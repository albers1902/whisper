<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Whisper\DB;
use Whisper\Paths;
use Whisper\Zendesk\Sync as ZendeskSync;
use Whisper\Zendesk\ZendeskError;

/**
 * Henter Adversus-opkald med optagelse (cron/adversus-sync.php hvert 10. minut, F004; algoritmen er beskrevet i
 * fullstack-ai/F004_v0.md §3–4 og F004_v2.md). Én kørsel:
 *
 * a) Succes-leads: keyset på lastUpdatedTime (filtrerbar og sorterbar) → adversus_success_leads.
 * b) Agentnavne: /users højst én gang i døgnet (én gang i timen, hvis et opkald har en ukendt agent) → adversus_users.
 * c) Ventende optagelser (adversus_pending), hvis opkald ikke blev fundet i /calls: søges igen nærmest-først (DESC) i
 *    RETRY_RUNS kørsler; derefter forkastes de med en synlig advarsel (sync_state 'adversus-warn').
 * d) Optagelser i lukkede vinduer [R, B) på /recordings.timestamp (upload-tid; kan IKKE sorteres, så side-numre bruges kun
 *    inden for et vindue, der ligger mindst lag i fortiden). Opkaldenes metadata slås op i /calls med start-filter omkring
 *    cdr.timestamp og kobles på id = cdr.id. Kun opkald med optagelse gemmes. R flyttes først, når hele vinduet er
 *    behandlet: gemt, eller lagt i adversus_pending, så intet tabes lydløst (QA F-1).
 * e) Succes begge veje: én JOIN-UPDATE markerer opkald på succes-leads og flytter "ikke valgt" til kø.
 * f) Lyd for opkald i kø via /calls/{id}/recording (redirect kun til recording_hosts), succes og Zendesk før manuelle
 *    valg, og ingen nye downloads efter DOWNLOAD_DEADLINE (security S1, S2). Den signerede URL gemmes aldrig.
 *
 * Tidsfiltre skrives med AdversusConfig::filterTime (væg-tid i filter_tz): Adversus læser filtrets cifre som lokal tid
 * og ignorerer "Z", men svarer i UTC (H009). iso() bruges kun til log.
 * Adversus har ingen $gte: alle nedre grænser er "$gt t − 1 s". Overlappet på ét sekund fjernes af UNIQUE (source,
 * external_id) og lead_id-nøglen, så databasen hverken får huller eller dubletter.
 * Højst MAX_REQUESTS kald pr. kørsel (Client tæller hvert HTTP-kald); fase c–d stopper med RESERVE kald tilbage til lyd.
 * Log-linjer og last_error nævner kun id'er, tal og HTTP-status — aldrig URL'er, telefonnumre, navne, Adversus' fritekst
 * eller hemmeligheder (security S5).
 */
final class Sync
{
    public const SOURCE = 'adversus';
    public const CURSOR_REC = 'adversus-rec';
    public const CURSOR_LEADS = 'adversus-leads';
    public const CURSOR_USERS = 'adversus-users';
    public const WARN = 'adversus-warn';
    /** Markør for H009's engangs-genopretning (findes den, er den sket) */
    public const RECOVERY = 'h009-adversus';
    public const MAX_REQUESTS = 100;
    public const RESERVE = 30;
    /** Standard for [adversus] lag_minutes (se AdversusConfig) */
    public const LAG = AdversusConfig::LAG_DEFAULT;
    public const WINDOW_MAX = 3600;
    public const WINDOW_MIN = 60;
    /** Har et vindue flere optagelser end dette (over 3 sider), halveres det, før resten hentes */
    public const WINDOW_SPLIT = 300;
    public const MARGIN = 300;
    /** Det brede opslag for umatchede går så langt tilbage fra cdr.timestamp (lange opkald, cdr.timestamp = sluttid) */
    public const MARGIN_WIDE = 7200;
    /** Ventende optagelser søges så langt tilbage (opkald over 2 t) */
    public const RETRY_BACK = 43200;
    public const WIDE_PAGES = 10;
    /** Kørsler, en optagelse uden opkald prøves igen, før den forkastes (6 × 10 min ≈ 1 t) */
    public const RETRY_RUNS = 6;
    /** Ventelisten bruger højst så mange kald pr. kørsel, så nye vinduer altid har budget */
    public const RETRY_REQUESTS = 30;
    /** Ventelisten vokser ikke over dette; flere umatchede forkastes med advarsel */
    public const PENDING_MAX = 2000;
    public const PAGE = 100;
    public const LEAD_REQUESTS = 5;
    public const USER_PAGES = 3;
    public const USER_REFRESH = 86400;
    public const USER_RETRY = 3600;
    public const MAX_AUDIO_ATTEMPTS = 5;
    public const MAX_DOWNLOADS = 25;
    /** Ingen nye downloads efter så mange sekunder af kørslen (cron hvert 10. min) */
    public const DOWNLOAD_DEADLINE = 480;
    public const RATE_LIMIT_PREFIX = 'rate limit';
    public const WARN_PREFIX = 'advarsel';
    /** En advarsel vises så længe på siden */
    public const WARN_TTL = 86400;
    public const UNKNOWN_AGENT = 'Adversus-bruger ';

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;

    /** @var array{recordings: int, new: int, success: int, queued: int, unmatched: int, skipped: int, discarded: int} */
    private array $stats = ['recordings' => 0, 'new' => 0, 'success' => 0, 'queued' => 0, 'unmatched' => 0, 'skipped' => 0, 'discarded' => 0];
    /** @var list<int> opkald med ukendt retning i denne kørsel */
    private array $skippedIds = [];
    /** @var list<int> optagelser forkastet i denne kørsel */
    private array $discardedIds = [];
    private int $reserve = 0;
    /** Fasens øvre grænse for Client::$requests (kun ventelisten), null = ingen */
    private ?int $ceiling = null;
    private int $startedAt = 0;
    /** @var list<int> optagelser, der ikke kom på ventelisten, fordi den var fuld */
    private array $overflowIds = [];

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     */
    public function __construct(
        private readonly Client $client,
        private readonly AdversusConfig $cfg,
        ?Closure $clock = null,
        ?Closure $log = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
    }

    /**
     * Hele kørslen. Fejl står i sync_state.last_error (rate limit med præfikset "rate limit", som siden viser som
     * information). Lyd hentes også, når en tidligere fase stoppede.
     *
     * @return array{recordings: int, new: int, success: int, queued: int, unmatched: int, skipped: int, discarded: int, requests: int, error: ?string}
     */
    public function run(): array
    {
        $this->startedAt = $this->ts();
        DB::exec('INSERT INTO sync_state (`source`, last_run_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)',
            [self::SOURCE, $this->now()]);
        $error = null;
        foreach ([fn() => $this->recover(), fn() => $this->leads(), fn() => $this->users(), fn() => $this->retryPending(), fn() => $this->recordings()] as $phase) {
            try {
                $phase();
            } catch (Throwable $e) {
                $error = $this->describe($e);
                break;
            }
        }
        try {
            $this->markSuccess();
        } catch (Throwable $e) {
            $error ??= $this->describe($e);
        }
        try {
            $this->downloadPending();
        } catch (Throwable $e) {
            $error ??= $this->describe($e);
        }
        if ($error === null) {
            DB::exec('UPDATE sync_state SET last_ok_at = ?, last_error = NULL WHERE `source` = ?', [$this->now(), self::SOURCE]);
        } else {
            DB::exec('UPDATE sync_state SET last_error = ? WHERE `source` = ?', [mb_substr($error, 0, 250), self::SOURCE]);
            ($this->log)('adversus-sync: stoppet: ' . $error);
        }
        $this->warn();
        return $this->stats + ['requests' => $this->client->requests, 'error' => $error];
    }

    /**
     * "adversus-sync: N optagelser, M nye opkald, S succes, K kø, U umatchede · kald X/100" (+ forkastede og ukendt retning)
     *
     * @param array<string, mixed> $r  run()'s resultat
     */
    public static function logLine(array $r): string
    {
        $extra = '';
        if ((int) ($r['discarded'] ?? 0) > 0) {
            $extra .= ', ' . (int) $r['discarded'] . ' forkastet';
        }
        if ((int) ($r['skipped'] ?? 0) > 0) {
            $extra .= ', ' . (int) $r['skipped'] . ' ukendt retning';
        }
        return sprintf('adversus-sync: %d optagelser, %d nye opkald, %d succes, %d kø, %d umatchede%s · kald %d/%d',
            (int) ($r['recordings'] ?? 0), (int) ($r['new'] ?? 0), (int) ($r['success'] ?? 0), (int) ($r['queued'] ?? 0),
            (int) ($r['unmatched'] ?? 0), $extra, (int) ($r['requests'] ?? 0), self::MAX_REQUESTS);
    }

    /**
     * Forkastede optagelser og opkald med ukendt retning skrives som advarsel i sync_state 'adversus-warn' (last_error),
     * som siden viser i WARN_TTL — ikke kun i loggen (QA F-1).
     */
    private function warn(): void
    {
        $parts = [];
        if ($this->discardedIds !== []) {
            $parts[] = count($this->discardedIds) . ' optagelser uden opkald i /calls efter ' . self::RETRY_RUNS . ' kørsler (id '
                . self::idList($this->discardedIds) . ')';
        }
        if ($this->overflowIds !== []) {
            $parts[] = count($this->overflowIds) . ' optagelser forkastet, fordi ventelisten er fuld (' . self::PENDING_MAX . ') (id '
                . self::idList($this->overflowIds) . ')';
        }
        if ($this->skippedIds !== []) {
            $parts[] = count($this->skippedIds) . ' opkald med ukendt retning (id ' . self::idList($this->skippedIds) . ')';
        }
        if ($parts === []) {
            return;
        }
        $text = self::WARN_PREFIX . ': ' . implode('; ', $parts) . ' — ikke gemt';
        ($this->log)('adversus-sync: ' . $text);
        // En advarsel, der ikke er udløbet, forlænges med de nye id'er i stedet for at blive overskrevet (QA N-3)
        $prev = DB::all('SELECT last_run_at, last_error FROM sync_state WHERE `source` = ?', [self::WARN])[0] ?? null;
        if ($prev !== null && is_string($prev['last_error']) && is_string($prev['last_run_at'])
            && $this->ts() - (int) strtotime($prev['last_run_at'] . ' UTC') <= self::WARN_TTL
            && !(\Whisper\UiV2::on() && self::beforeRecovery($prev['last_run_at']))) {   // F007: ikke de gamle id'er fra før H009
            $text = $prev['last_error'] . ' | ' . $this->now() . ' ' . implode('; ', $parts);
        }
        if (mb_strlen($text) > 250) {
            $text = mb_substr($text, 0, 249) . '…';   // resten står i loggen
        }
        DB::exec('INSERT INTO sync_state (`source`, last_run_at, last_error) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), last_error = VALUES(last_error)',
            [self::WARN, $this->now(), $text]);
    }

    /** @param list<int> $ids */
    private static function idList(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, 10)) . (count($ids) > 10 ? ', …' : '');
    }

    private function describe(Throwable $e): string
    {
        if ($e instanceof RateLimited) {
            return $this->client->remaining() === 0
                ? self::RATE_LIMIT_PREFIX . ': kaldsgrænsen for kørslen er nået — hentningen fortsætter ved næste kørsel'
                : self::RATE_LIMIT_PREFIX . ' (429) — Adversus bad os vente; hentningen fortsætter ved næste kørsel';
        }
        return $e instanceof AdversusError ? self::safe($e->getMessage()) : 'intern fejl (' . (new \ReflectionClass($e))->getShortName() . ')';
    }

    /**
     * Kun HTTP-status og Adversus' code fra et svar (security S5): "HTTP 403: code 403 - <fri tekst>" bliver "HTTP 403: code 403".
     * Vores egne faste tekster (uden "HTTP n:"-præfiks) bevares.
     */
    public static function safe(string $msg): string
    {
        if (preg_match('/^HTTP ([0-9]{3})(?::\s*code ([0-9]+))?/', $msg, $m) === 1) {
            return 'HTTP ' . $m[1] . (isset($m[2]) && $m[2] !== '' ? ': code ' . $m[2] : '');
        }
        return $msg;
    }

    // ---------------------------------------------------------------- genopretning (H009)

    /**
     * F007: er en advarsel skrevet før (eller samtidig med) H009-genopretningen? Så nævner den id'er, som genopretningen
     * henter igen, og den er forældet. Uden genopretning (markøren findes ikke) er intet forældet.
     */
    public static function beforeRecovery(string $warnAt): bool
    {
        $done = DB::scalar('SELECT last_run_at FROM sync_state WHERE `source` = ?', [self::RECOVERY]);
        return is_string($done) && strtotime($warnAt . ' UTC') <= strtotime($done . ' UTC');
    }

    /**
     * Én gang efter H009: indtil da lå alle tidsfiltre 1 t forkert (Adversus læser filtercifre som lokal væg-tid), så
     * næsten ingen optagelser blev matchet, og de blev forkastet eller ligger på ventelisten. Optagelses-cursoren sættes
     * tilbage til sync_since, og ventelisten tømmes (den er afledt: de samme optagelser findes igen i vinduerne). Gemte
     * opkald springes over i window(), så der kommer ingen dubletter. Markøren gør, at det kun sker én gang.
     *
     * @return bool om genopretningen kørte nu
     */
    public function recover(): bool
    {
        if (DB::scalar('SELECT 1 FROM sync_state WHERE `source` = ?', [self::RECOVERY]) !== false) {
            return false;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $pending = DB::exec('DELETE FROM adversus_pending');
            $this->saveRecCursor($this->cfg->syncSince, self::WINDOW_MAX);
            DB::exec('INSERT INTO sync_state (`source`, cursor_value, last_run_at) VALUES (?, ?, ?)',
                [self::RECOVERY, 'done', $this->now()]);
            if (\Whisper\UiV2::on()) {
                DB::exec('DELETE FROM sync_state WHERE `source` = ?', [self::WARN]);   // F007: advarslens id'er hentes igen nu
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ($this->log)('adversus-sync: genopretning (H009): optagelses-cursoren er sat tilbage til ' . self::iso($this->cfg->syncSince)
            . ', ' . $pending . ' ventende fjernet — optagelser fra sync_since hentes igen');
        return true;
    }

    // ---------------------------------------------------------------- a) succes-leads

    /** @return int antal nye succes-leads */
    public function leads(): int
    {
        $c = $this->cursorInt(self::CURSOR_LEADS) ?? $this->cfg->syncSince;
        $c = max($c, $this->cfg->syncSince);
        $page = 1;
        $new = 0;
        for ($i = 0; $i < self::LEAD_REQUESTS; $i++) {
            $data = $this->client->getJson('/leads', [
                'filters'       => self::filter(['status' => ['$eq' => $this->cfg->successStatus], 'lastUpdatedTime' => ['$gt' => $this->cfg->filterTime($c - 1)]]),
                'sortProperty'  => 'lastUpdatedTime',
                'sortDirection' => 'ASC',
                'pageSize'      => self::PAGE,
                'page'          => $page,
            ]);
            $items = self::items($data, 'leads');
            $max = $c;
            foreach ($items as $lead) {
                $id = self::posInt($lead['id'] ?? null);
                if ($id === null || ($lead['status'] ?? null) !== $this->cfg->successStatus) {
                    continue;   // filteret er Adversus'; et lead med en anden status tages aldrig som succes
                }
                $t = self::epoch($lead['lastUpdatedTime'] ?? null);
                $new += DB::exec('INSERT IGNORE INTO adversus_success_leads (lead_id, last_updated_at, seen_at) VALUES (?, ?, ?)',
                    [$id, $t === null ? null : gmdate('Y-m-d H:i:s', $t), $this->now()]);
                if ($t !== null) {
                    $max = max($max, $t);
                }
            }
            if (count($items) < self::PAGE) {
                $this->saveCursor(self::CURSOR_LEADS, (string) $max);
                break;
            }
            if ($max > $c) {
                $c = $max;   // keyset: næste side er "efter det sidste", ikke side 2, så ændrede leads ikke forskyder siderne
                $this->saveCursor(self::CURSOR_LEADS, (string) $c);
                $page = 1;
            } else {
                $page++;     // en fuld side i samme sekund: næste side af samme filter
            }
        }
        return $new;
    }

    // ---------------------------------------------------------------- b) agentnavne

    public function users(): void
    {
        $last = DB::scalar('SELECT last_run_at FROM sync_state WHERE `source` = ?', [self::CURSOR_USERS]);
        $lastTs = is_string($last) ? (int) strtotime($last . ' UTC') : 0;
        $age = $this->ts() - $lastTs;
        $unknown = (int) DB::scalar("SELECT COUNT(*) FROM calls c LEFT JOIN adversus_users u ON c.agent_ext_id = CAST(u.user_id AS CHAR) "
            . "WHERE c.`source` = 'adversus' AND c.agent_ext_id IS NOT NULL AND u.user_id IS NULL") > 0;
        if ($lastTs > 0 && $age < self::USER_REFRESH && !($unknown && $age >= self::USER_RETRY)) {
            return;
        }
        // Tidspunktet gemmes først, så et endpoint, der fejler, ikke kaldes hver kørsel
        DB::exec('INSERT INTO sync_state (`source`, last_run_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)',
            [self::CURSOR_USERS, $this->now()]);
        try {
            for ($p = 1; $p <= self::USER_PAGES; $p++) {
                $items = self::items($this->client->getJson('/users', ['page' => $p, 'pageSize' => self::PAGE]), 'users');
                foreach ($items as $u) {
                    $id = self::posInt($u['id'] ?? null);
                    $name = is_string($u['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $u['name'])) : '';
                    if ($id === null || $name === '') {
                        continue;
                    }
                    // Kun id og navn gemmes (Team Lead): ingen e-mail, telefon eller andre brugerfelter
                    DB::exec('INSERT INTO adversus_users (user_id, name, updated_at) VALUES (?, ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)',
                        [$id, mb_substr($name, 0, 120), $this->now()]);
                }
                if (count($items) < self::PAGE) {
                    break;
                }
            }
        } catch (AdversusError $e) {
            if ($e instanceof RateLimited || $e->getCode() < 400) {
                throw $e;
            }
            // Navnet er pynt: uden adgang til /users vises "Adversus-bruger <id>"
            ($this->log)('adversus-sync: agentnavne ikke hentet: ' . self::safe($e->getMessage()));
            return;
        }
        DB::exec("UPDATE calls c JOIN adversus_users u ON c.agent_ext_id = CAST(u.user_id AS CHAR) SET c.agent_name = u.name "
            . "WHERE c.`source` = 'adversus' AND c.agent_name <> u.name");
        DB::exec('UPDATE sync_state SET last_ok_at = ? WHERE `source` = ?', [$this->now(), self::CURSOR_USERS]);
    }

    // ---------------------------------------------------------------- c) ventende optagelser

    /**
     * Optagelser, hvis opkald ikke blev fundet: grupperet efter tid (højst MARGIN_WIDE fra gruppens første) og søgt fra
     * gruppens seneste cdr.timestamp og RETRY_BACK bagud, nyeste først (de nærmeste opkald før de fjerne). Hver optagelse
     * tæller ét forsøg pr. kørsel, hvor dens gruppe blev søgt helt; efter RETRY_RUNS forkastes den med en advarsel.
     */
    public function retryPending(): void
    {
        $this->reserve = self::RESERVE;
        // Eget loft (QA N-2, security N1): ventelisten må aldrig tage budgettet fra nye vinduer
        $this->ceiling = $this->client->requests + self::RETRY_REQUESTS;
        try {
            $rows = DB::all('SELECT cdr_id, cdr_at, tries FROM adversus_pending ORDER BY tries ASC, cdr_at IS NULL, cdr_at ASC, cdr_id ASC LIMIT 500');
            // Færrest forsøg først (så de samme grupper ikke tager loftet hver kørsel), inden for det i tidsorden.
            // Grupper dannes pr. antal forsøg; rækker uden cdr.timestamp er én gruppe pr. antal forsøg (QA N-2).
            /** @var array<string, array{first: ?int, last: ?int, want: array<int, ?int>}> $groups */
            $groups = [];
            $open = [];   // antal forsøg → nøglen på den åbne gruppe med tid
            foreach ($rows as $r) {
                $t = is_string($r['cdr_at']) ? (int) strtotime($r['cdr_at'] . ' UTC') : null;
                $tries = (int) $r['tries'];
                if ($t === null) {
                    $key = 'null-' . $tries;
                    $groups[$key] ??= ['first' => null, 'last' => null, 'want' => []];
                    $groups[$key]['want'][(int) $r['cdr_id']] = null;
                    continue;
                }
                $key = $open[$tries] ?? null;
                if ($key !== null && $t - (int) $groups[$key]['first'] <= self::MARGIN_WIDE) {
                    $groups[$key]['want'][(int) $r['cdr_id']] = $t;
                    $groups[$key]['last'] = $t;
                } else {
                    $key = 't-' . $tries . '-' . $t . '-' . (int) $r['cdr_id'];
                    $groups[$key] = ['first' => $t, 'last' => $t, 'want' => [(int) $r['cdr_id'] => $t]];
                    $open[$tries] = $key;
                }
            }
            foreach ($groups as $g) {
                $hi = ($g['last'] ?? $this->ts()) + self::MARGIN;
                $lo = ($g['first'] ?? $this->ts()) - self::RETRY_BACK;
                $missing = $this->lookup(max($lo, $this->cfg->syncSince - self::RETRY_BACK), $hi, $g['want'], self::WIDE_PAGES, 'DESC');
                $found = array_diff_key($g['want'], $missing);
                if ($found !== []) {
                    DB::exec('DELETE FROM adversus_pending WHERE cdr_id IN (' . implode(',', array_fill(0, count($found), '?')) . ')',
                        array_keys($found));
                }
                foreach (array_keys($missing) as $id) {
                    DB::exec('UPDATE adversus_pending SET tries = tries + 1 WHERE cdr_id = ?', [$id]);
                }
                $gone = DB::all('SELECT cdr_id FROM adversus_pending WHERE tries >= ? AND cdr_id IN ('
                    . implode(',', array_fill(0, count($g['want']), '?')) . ')', array_merge([self::RETRY_RUNS], array_keys($g['want'])));
                foreach ($gone as $r) {
                    DB::exec('DELETE FROM adversus_pending WHERE cdr_id = ?', [(int) $r['cdr_id']]);
                    $this->discardedIds[] = (int) $r['cdr_id'];
                    $this->stats['discarded']++;
                }
            }
        } catch (OutOfBudget) {
            // resten prøves ved næste kørsel; en gruppe, der ikke blev søgt helt, har ikke brugt et forsøg
        } finally {
            $this->reserve = 0;
            $this->ceiling = null;
        }
    }

    // ---------------------------------------------------------------- d) optagelser og opkald

    public function recordings(): void
    {
        $this->reserve = self::RESERVE;
        [$r, $w] = $this->recCursor();
        $first = true;   // intet vindue er færdigt i denne kørsel endnu
        while (true) {
            $b = min($r + $w, $this->ts() - $this->cfg->lag);
            if ($b <= $r) {
                break;
            }
            try {
                $n = $this->window($r, $b);
            } catch (OutOfBudget) {
                // Kunne vinduet ikke nås med et helt loft, halveres det, så det altid kan blive færdigt i én kørsel
                if ($first && $w > self::WINDOW_MIN) {
                    $this->saveRecCursor($r, max(self::WINDOW_MIN, intdiv($w, 2)));
                }
                break;
            }
            if ($n === null) {
                $w = max(self::WINDOW_MIN, intdiv($b - $r, 2));
                $this->saveRecCursor($r, $w);
                continue;
            }
            $this->stats['recordings'] += $n;
            $r = $b;
            if ($n < self::PAGE) {
                $w = min(self::WINDOW_MAX, $w * 2);
            }
            $this->saveRecCursor($r, $w);
            $first = false;
        }
        $this->reserve = 0;
    }

    /**
     * Ét lukket vindue [from, to). Returnerer antal optagelser, eller null hvis vinduet er for stort og skal halveres.
     * Optagelser uden fundet opkald lægges i adversus_pending, før cursoren flyttes.
     *
     * @throws OutOfBudget
     */
    private function window(int $from, int $to): ?int
    {
        $recs = [];   // cdr.id → cdr.timestamp (epoch) eller null
        for ($p = 1; ; $p++) {
            $this->need(1);
            $data = $this->client->getJson('/recordings', [
                'filters'     => self::filter(['timestamp' => ['$gt' => $this->cfg->filterTime($from - 1), '$lt' => $this->cfg->filterTime($to)]]),
                'page'        => $p,
                'pageSize'    => self::PAGE,
                'includeMeta' => 'true',
            ]);
            $meta = is_array($data['meta']['pagination'] ?? null) ? $data['meta']['pagination'] : [];
            if ($p === 1 && is_int($meta['total'] ?? null) && $meta['total'] > self::WINDOW_SPLIT && $to - $from > self::WINDOW_MIN) {
                return null;
            }
            $items = self::items($data, 'recordings');
            foreach ($items as $rec) {
                $cdr = is_array($rec['cdr'] ?? null) ? $rec['cdr'] : [];
                $id = self::posInt($cdr['id'] ?? null);
                if ($id !== null) {
                    $recs[$id] = self::epoch($cdr['timestamp'] ?? null);
                }
            }
            $pageCount = is_int($meta['pageCount'] ?? null) ? $meta['pageCount'] : null;
            if (count($items) < self::PAGE || ($pageCount !== null && $p >= $pageCount)) {
                break;
            }
        }
        if ($recs === []) {
            return 0;
        }
        // Allerede gemt (overlap, eller et vindue der hentes igen): ingen opslag nødvendigt
        $saved = DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus' AND external_id IN ("
            . implode(',', array_fill(0, count($recs), '?')) . ')', array_map('strval', array_keys($recs)));
        $want = $recs;
        foreach ($saved as $s) {
            unset($want[(int) $s['external_id']]);
        }
        if ($want !== []) {
            $known = array_values(array_filter($want, static fn(?int $t): bool => $t !== null));
            $lo = $known === [] ? $from - self::MARGIN_WIDE : min($known) - self::MARGIN;
            $hi = $known === [] ? $to : max($known) + self::MARGIN;
            $missing = $this->lookup($lo, $hi, $want, PHP_INT_MAX, 'ASC');
            if ($missing !== [] && $known !== []) {
                // cdr.timestamp kan være slut- eller uploadtid: én bredere søgning bagud, nærmeste opkald først
                $mk = array_values(array_filter($missing, static fn(?int $t): bool => $t !== null));
                $top = $mk === [] ? $lo : max($mk);
                $missing = $this->lookup($top - self::MARGIN_WIDE, $top + 1, $missing, self::WIDE_PAGES, 'DESC');
            }
            $room = self::PENDING_MAX - (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending');
            foreach ($missing as $id => $t) {
                if ($room <= 0) {
                    // Fuld venteliste (security N1): ingen tavs vækst — forkastet med advarsel
                    $this->overflowIds[] = $id;
                    $this->stats['discarded']++;
                    continue;
                }
                $room -= DB::exec('INSERT IGNORE INTO adversus_pending (cdr_id, cdr_at, tries, first_seen) VALUES (?, ?, 0, ?)',
                    [$id, $t === null ? null : gmdate('Y-m-d H:i:s', $t), $this->now()]);
            }
            if ($missing !== []) {
                $this->stats['unmatched'] += count($missing);
                ($this->log)('adversus-sync: ' . count($missing) . ' optagelser uden opkald i /calls endnu — prøves igen (id '
                    . self::idList(array_keys($missing)) . ')');
            }
        }
        return count($recs);
    }

    /**
     * Opkald med start i (lo − 1 s, hi) — gemmer dem, hvis id er i $want. Returnerer dem af $want, der ikke blev fundet.
     *
     * @param array<int, ?int> $want
     * @return array<int, ?int>
     * @throws OutOfBudget
     */
    private function lookup(int $lo, int $hi, array $want, int $maxPages, string $direction): array
    {
        for ($p = 1; $p <= $maxPages && $want !== []; $p++) {
            $this->need(1);
            $data = $this->client->getJson('/calls', [
                'filters'       => self::filter(['start' => ['$gt' => $this->cfg->filterTime($lo - 1), '$lt' => $this->cfg->filterTime($hi)]]),
                'sortProperty'  => 'start',
                'sortDirection' => $direction,
                'page'          => $p,
                'pageSize'      => self::PAGE,
                'includeMeta'   => 'true',
            ]);
            $items = self::items($data, 'calls');
            foreach ($items as $call) {
                $id = self::posInt($call['id'] ?? null);
                if ($id !== null && array_key_exists($id, $want)) {
                    $this->saveCall($id, $call);
                    unset($want[$id]);
                }
            }
            $meta = is_array($data['meta']['pagination'] ?? null) ? $data['meta']['pagination'] : [];
            $pageCount = is_int($meta['pageCount'] ?? null) ? $meta['pageCount'] : null;
            if (count($items) < self::PAGE || ($pageCount !== null && $p >= $pageCount)) {
                break;
            }
        }
        return $want;
    }

    /** @param array<mixed> $call */
    private function saveCall(int $id, array $call): void
    {
        $start = self::epoch($call['start'] ?? null);
        if ($start === null || $start < $this->cfg->syncSince) {
            return;   // AK5: intet før sync_since (en optagelse uploadet efter since kan høre til et ældre opkald)
        }
        $origin = $call['origin'] ?? null;
        if (!in_array($origin, ['inbound', 'outbound'], true)) {
            // Swagger kender kun inbound/outbound; noget andet tælles i advarslen (QA F-1) i stedet for at gætte
            $this->stats['skipped']++;
            $this->skippedIds[] = $id;
            return;
        }
        $lead = self::posInt($call['leadId'] ?? null);
        $campaign = self::posInt($call['campaignId'] ?? null);
        $user = self::posInt($call['userId'] ?? null);
        $duration = is_numeric($call['duration'] ?? null) ? max(0, (int) $call['duration']) : 0;
        // Zendesk-opkald gemmer nummeret (Zendesk\Sync::saveCall), så det må Adversus også (spec); samme rensning
        $phone = mb_substr((string) preg_replace('/[^0-9+ ]/', '', is_string($call['number'] ?? null) ? $call['number'] : ''), 0, 40);
        $agent = '';
        if ($user !== null) {
            $name = DB::scalar('SELECT name FROM adversus_users WHERE user_id = ?', [$user]);
            $agent = is_string($name) && $name !== '' ? $name : self::UNKNOWN_AGENT . $user;
        }
        $now = $this->now();
        $existing = DB::scalar('SELECT id FROM calls WHERE `source` = ? AND external_id = ?', [self::SOURCE, (string) $id]);
        if ($existing !== false && $existing !== null) {
            // Allerede gemt: kun metadata; succes og status ejes af markSuccess og knappen
            DB::exec('UPDATE calls SET lead_id = ?, campaign_id = ?, direction = ?, customer_phone = ?, agent_ext_id = ?, '
                . 'agent_name = ?, started_at = ?, duration_sec = ?, updated_at = ? WHERE id = ?',
                [$lead, $campaign, $origin, $phone, $user === null ? null : (string) $user, $agent, gmdate('Y-m-d H:i:s', $start),
                    $duration, $now, (int) $existing]);
            return;
        }
        $success = $lead !== null && DB::scalar('SELECT 1 FROM adversus_success_leads WHERE lead_id = ?', [$lead]) !== false;
        DB::exec('INSERT INTO calls (`source`, external_id, ticket_id, lead_id, campaign_id, is_success, direction, customer_phone, '
            . 'agent_name, agent_ext_id, started_at, duration_sec, recording_url, transcript_status, created_at, updated_at) '
            . "VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?) "
            . 'ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [self::SOURCE, (string) $id, $lead, $campaign, $success ? 1 : 0, $origin, $phone, $agent,
                $user === null ? null : (string) $user, gmdate('Y-m-d H:i:s', $start), $duration,
                $success ? 'ventende' : 'ikke_valgt', $now, $now]);
        $this->stats['new']++;
        if ($success) {
            $this->stats['success']++;
        }
    }

    // ---------------------------------------------------------------- e) succes begge veje

    /** Opkald, hvis lead er blevet success, efter de blev gemt. Markeringen fjernes aldrig. */
    public function markSuccess(): int
    {
        $n = DB::exec("UPDATE calls c JOIN adversus_success_leads l ON l.lead_id = c.lead_id SET c.is_success = 1, "
            . "c.transcript_status = IF(c.transcript_status = 'ikke_valgt', 'ventende', c.transcript_status), c.updated_at = ? "
            . "WHERE c.`source` = 'adversus' AND c.is_success = 0", [$this->now()]);
        $this->stats['success'] += $n;
        return $n;
    }

    // ---------------------------------------------------------------- f) lyd

    /** @return array{ok: int, failed: int} */
    public function downloadPending(): array
    {
        // Succes-opkald før manuelt valgte (security S1), derefter nyeste først, som transcription-køen (H012)
        $rows = DB::all("SELECT id, external_id, started_at FROM calls WHERE `source` = ? AND transcript_status <> 'ikke_valgt' "
            . "AND (audio_status = 'venter' OR (audio_status = 'fejlet' AND audio_attempts < ?)) "
            . 'ORDER BY is_success DESC, started_at DESC, id DESC LIMIT ' . self::MAX_DOWNLOADS, [self::SOURCE, self::MAX_AUDIO_ATTEMPTS]);
        $ok = 0;
        $failed = 0;
        foreach ($rows as $r) {
            if ($this->client->remaining() < 2) {
                break;   // 307 + lydværten: næste kørsel fortsætter
            }
            if ($this->ts() - $this->startedAt >= self::DOWNLOAD_DEADLINE) {
                break;   // security S2: en langsom lydvært må ikke holde låsen i timevis
            }
            $id = (int) $r['id'];
            try {
                $rel = $this->downloadOne($id, (int) $r['external_id'], (string) $r['started_at']);
                DB::exec("UPDATE calls SET audio_status = 'hentet', audio_path = ?, audio_attempts = audio_attempts + 1, "
                    . 'audio_error = NULL, updated_at = ? WHERE id = ?', [$rel, $this->now(), $id]);
                $ok++;
                $this->stats['queued']++;
            } catch (RateLimited $e) {
                throw $e;   // intet forsøg brugt
            } catch (Throwable $e) {
                $msg = $e instanceof AdversusError || $e instanceof ZendeskError ? self::safe($e->getMessage()) : 'intern fejl ved hentning';
                DB::exec("UPDATE calls SET audio_status = 'fejlet', audio_attempts = audio_attempts + 1, audio_error = ?, "
                    . 'updated_at = ? WHERE id = ?', [mb_substr($msg, 0, 250), $this->now(), $id]);
                ($this->log)('adversus-sync: opkald ' . $id . ': lyd ikke hentet: ' . $msg);
                $failed++;
            }
        }
        return ['ok' => $ok, 'failed' => $failed];
    }

    /** @return string stien relativt til var/ (fx recordings/2026/11/17.mp3) */
    private function downloadOne(int $id, int $adversusId, string $startedAt): string
    {
        $t = strtotime($startedAt . ' UTC') ?: $this->ts();
        $relDir = 'recordings/' . gmdate('Y', $t) . '/' . gmdate('m', $t);
        $dir = Paths::dir($relDir);
        $part = $dir . '/' . $id . '.part';
        $old = umask(077);
        try {
            $type = $this->client->downloadRecording($adversusId, $part);
            clearstatcache(true, $part);
            if (!is_file($part) || (int) filesize($part) === 0) {
                throw new AdversusError('optagelsen var tom');
            }
            $ext = ZendeskSync::extension($type, '', $part);
            chmod($part, 0600);
            if (!rename($part, $dir . '/' . $id . '.' . $ext)) {
                throw new AdversusError('optagelsen kunne ikke gemmes');
            }
            return $relDir . '/' . $id . '.' . $ext;
        } catch (Throwable $e) {
            @unlink($part);   // en afbrudt download efterlader aldrig en halv fil
            throw $e;
        } finally {
            umask($old);
        }
    }

    // ---------------------------------------------------------------- hjælpere

    /** @throws OutOfBudget hvis $n kald mere ville gå ind i reserven */
    private function need(int $n): void
    {
        if ($this->client->remaining() - $n < $this->reserve
            || ($this->ceiling !== null && $this->client->requests + $n > $this->ceiling)) {
            throw new OutOfBudget();
        }
    }

    /** @return array{0: int, 1: int} vinduets start og længde */
    private function recCursor(): array
    {
        $raw = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [self::CURSOR_REC]);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        $t = is_array($d) && is_int($d['t'] ?? null) ? $d['t'] : $this->cfg->syncSince;
        $w = is_array($d) && is_int($d['w'] ?? null) ? $d['w'] : self::WINDOW_MAX;
        return [max($t, $this->cfg->syncSince), max(self::WINDOW_MIN, min(self::WINDOW_MAX, $w))];
    }

    private function saveRecCursor(int $t, int $w): void
    {
        $this->saveCursor(self::CURSOR_REC, (string) json_encode(['t' => $t, 'w' => $w]));
    }

    private function cursorInt(string $name): ?int
    {
        $raw = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [$name]);
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : null;
    }

    private function saveCursor(string $name, string $value): void
    {
        DB::exec('INSERT INTO sync_state (`source`, cursor_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value)',
            [$name, $value]);
    }

    /** @param array<string, mixed> $f */
    private static function filter(array $f): string
    {
        return (string) json_encode($f);
    }

    public static function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /**
     * ISO 8601 (med eller uden brøkdele af sekunder) som epoch, rundet ned til hele sekunder; null ved en ugyldig værdi.
     * Uden offset i strengen er tiden UTC (ikke serverens standardzone).
     */
    public static function epoch(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '' || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $iso) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    private static function posInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        return is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v) === 1 ? (int) $v : null;
    }

    /**
     * Listen under $key (eller svaret selv, hvis det er en liste).
     *
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function items(array $data, string $key): array
    {
        $list = array_is_list($data) ? $data : (is_array($data[$key] ?? null) ? $data[$key] : []);
        return array_values(array_filter($list, 'is_array'));
    }

    private function ts(): int
    {
        return ($this->clock)();
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->ts());
    }
}
