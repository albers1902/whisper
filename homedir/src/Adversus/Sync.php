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
 * Henter Adversus inkrementelt til en lokal database (cron/adversus-sync.php hvert 10. minut). Den lokale database er
 * sandheden for CDR'er og leads; hver fase har sin cursor i sync_state og upsert'er på Adversus' id, så en afbrudt
 * kørsel fortsætter, hvor den slap, uden huller og uden dubletter (H013). Én kørsel:
 *
 * a) Leads: /leads med lastUpdatedTime efter cursoren (dokumenteret filter og sortering) → adversus_leads (id, status).
 * b) Agentnavne: /users højst én gang i døgnet (én gang i timen, hvis et opkald har en ukendt agent).
 * c) CDR'er: /calls sorteret på start efter cursoren (keyset) op til nu − lag → ALLE opkald i calls. Opkald uden succes
 *    får 'ikke_valgt'; opkald uden samtale (busy, no-answer, …) kommer aldrig i lydkøen af sig selv.
 * d) Kontrol: hver afsluttet time sammenlignes, når den er CHECK_SETTLE gammel, med Adversus' total (1 kald). Mangler
 *    der opkald (fx et langt opkald, der først blev logget efter cursoren), hentes timen igen med samme upsert.
 * e) Succes: et lead, der bliver success, markerer sine opkald og sætter de besvarede i kø (fjernes aldrig igen).
 * f) Lyd kun for opkald i kø (succes eller manuelt valgt) via /calls/{id}/recording; redirect kun til recording_hosts,
 *    og ingen nye downloads efter DOWNLOAD_DEADLINE (security S1, S2). Den signerede URL gemmes aldrig.
 *
 * /calls har intet dokumenteret "ændret siden"-filter (swagger: campaignId, userId, start, timestamp — timestamp findes
 * ikke i call-entry), derfor cursor på start + kontrollen i d). Tidsfiltre skrives med AdversusConfig::filterTime
 * (væg-tid i filter_tz): Adversus læser cifrene som lokal tid og svarer i UTC (H009). Ingen $gte: nedre grænser er
 * "$gt t − 1 s"; overlappet fjernes af UNIQUE (source, external_id) og lead_id-nøglen.
 * Højst MAX_REQUESTS kald pr. kørsel; c–d stopper med RESERVE kald tilbage til lyd.
 * Log-linjer og last_error nævner kun id'er, tal og HTTP-status — aldrig URL'er, telefonnumre, navne, Adversus' fritekst
 * eller hemmeligheder (security S5).
 */
final class Sync
{
    public const SOURCE = 'adversus';
    public const CURSOR_CDR = 'adversus-cdr';
    public const CURSOR_CHECK = 'adversus-cdr-check';
    public const CURSOR_LEADS = 'adversus-leads-all';
    public const CURSOR_USERS = 'adversus-users';
    public const WARN = 'adversus-warn';
    /** Markør for H013's engangs-oprydning (findes den, er den sket) */
    public const MIGRATED = 'h013-adversus';
    public const MAX_REQUESTS = 100;
    public const RESERVE = 30;
    /** Standard for [adversus] lag_minutes (se AdversusConfig) */
    public const LAG = AdversusConfig::LAG_DEFAULT;
    public const PAGE = 100;
    /** Lead-sider pr. kørsel (backfill fortsætter i næste kørsel) */
    public const LEAD_REQUESTS = 20;
    /** Kontrollens enhed og hvor gammel en time skal være, før den kontrolleres (lange opkald er logget til den tid) */
    public const CHECK_SPAN = 3600;
    public const CHECK_SETTLE = 10800;
    /** Dispositioner uden samtale: ingen lyd at hente, så de kommer ikke i lydkøen af sig selv */
    public const NO_CONVERSATION = ['busy', 'no-answer', 'failed', 'congestion'];
    public const USER_PAGES = 3;
    public const USER_REFRESH = 86400;
    public const USER_RETRY = 3600;
    public const MAX_AUDIO_ATTEMPTS = 5;
    public const MAX_DOWNLOADS = 25;
    /** Ingen nye downloads efter så mange sekunder af kørslen (cron hvert 10. min) */
    public const DOWNLOAD_DEADLINE = 480;
    /** 404 fra /calls/{id}/recording: prøves igen efter så lang tid, til opkaldet er NO_AUDIO_FINAL gammelt */
    public const NO_AUDIO_RETRY = 1800;
    public const NO_AUDIO_FINAL = 21600;
    public const NO_AUDIO_YET = 'ingen optagelse endnu (HTTP 404)';
    public const NO_AUDIO = 'ingen optagelse hos Adversus (HTTP 404)';
    public const RATE_LIMIT_PREFIX = 'rate limit';
    public const WARN_PREFIX = 'advarsel';
    /** En advarsel vises så længe på siden */
    public const WARN_TTL = 86400;
    public const UNKNOWN_AGENT = 'Adversus-bruger ';

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;

    /** @var array{cdr: int, new: int, leads: int, success: int, queued: int, no_audio: int, failed: int, checked: int, rescanned: int, skipped: int} */
    private array $stats = ['cdr' => 0, 'new' => 0, 'leads' => 0, 'success' => 0, 'queued' => 0, 'no_audio' => 0, 'failed' => 0,
        'checked' => 0, 'rescanned' => 0, 'skipped' => 0];
    /** @var list<int> opkald med ukendt retning i denne kørsel */
    private array $skippedIds = [];
    private int $reserve = 0;
    private int $startedAt = 0;
    /** @var array<mixed>|null agentnavne (user_id → navn), læst én gang pr. kørsel */
    private ?array $userNames = null;

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
     * @return array{cdr: int, new: int, leads: int, success: int, queued: int, no_audio: int, failed: int, checked: int, rescanned: int, skipped: int, requests: int, error: ?string, day: string}
     */
    public function run(): array
    {
        $this->startedAt = $this->ts();
        DB::exec('INSERT INTO sync_state (`source`, last_run_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)',
            [self::SOURCE, $this->now()]);
        $error = null;
        foreach ([fn() => $this->migrate(), fn() => $this->leads(), fn() => $this->users(), fn() => $this->cdrs(), fn() => $this->check()] as $phase) {
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
        $day = (new DateTimeImmutable('@' . $this->cdrCursor()[2]))->setTimezone($this->cfg->filterTz)->format('Y-m-d');
        return $this->stats + ['requests' => $this->client->requests, 'error' => $error, 'day' => $day];
    }

    /**
     * Én linje pr. kørsel med tal, fx
     * "adversus-sync: 812 cdr (40 nye, dag 2026-11-12), 95 leads, 3 succes, lyd 2 hentet/1 uden optagelse/0 fejlet, kontrol 1 t (0 igen) · kald 14/100"
     *
     * @param array<string, mixed> $r  run()'s resultat
     */
    public static function logLine(array $r): string
    {
        $extra = (int) ($r['skipped'] ?? 0) > 0 ? ', ' . (int) $r['skipped'] . ' ukendt retning' : '';
        return sprintf('adversus-sync: %d cdr (%d nye, dag %s), %d leads, %d succes, lyd %d hentet/%d uden optagelse/%d fejlet, '
            . 'kontrol %d t (%d igen)%s · kald %d/%d',
            (int) ($r['cdr'] ?? 0), (int) ($r['new'] ?? 0), is_string($r['day'] ?? null) ? $r['day'] : '?', (int) ($r['leads'] ?? 0),
            (int) ($r['success'] ?? 0), (int) ($r['queued'] ?? 0), (int) ($r['no_audio'] ?? 0), (int) ($r['failed'] ?? 0),
            (int) ($r['checked'] ?? 0), (int) ($r['rescanned'] ?? 0), $extra, (int) ($r['requests'] ?? 0), self::MAX_REQUESTS);
    }

    /**
     * Opkald med ukendt retning (gemmes ikke) skrives som advarsel i sync_state 'adversus-warn' (last_error), som siden
     * viser i WARN_TTL. Nyeste først, så afkortningen til 250 tegn fjerner de ældste — aldrig de nye (H013).
     */
    private function warn(): void
    {
        if ($this->skippedIds === []) {
            return;
        }
        $part = count($this->skippedIds) . ' opkald med ukendt retning (id ' . self::idList($this->skippedIds) . ') — ikke gemt';
        ($this->log)('adversus-sync: ' . self::WARN_PREFIX . ': ' . $part);
        $text = self::WARN_PREFIX . ': ' . $this->now() . ' ' . $part;
        $prev = DB::all('SELECT last_run_at, last_error FROM sync_state WHERE `source` = ?', [self::WARN])[0] ?? null;
        if ($prev !== null && is_string($prev['last_error']) && is_string($prev['last_run_at'])
            && $this->ts() - (int) strtotime($prev['last_run_at'] . ' UTC') <= self::WARN_TTL) {
            $text .= ' | ' . (string) preg_replace('/^' . self::WARN_PREFIX . ': /', '', $prev['last_error']);
        }
        if (mb_strlen($text) > 250) {
            $text = mb_substr($text, 0, 249) . '…';   // de ældste står i loggen
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

    // ---------------------------------------------------------------- oprydning (H013)

    /**
     * Én gang: ventelisten fra det gamle optagelses-flow og advarslen om den er afledte og gælder ikke mere (CDR'erne
     * hentes forfra fra sync_since). Tabellen tømmes, men bliver stående til en contract med eget ID.
     *
     * @return bool om oprydningen kørte nu
     */
    public function migrate(): bool
    {
        if (DB::scalar('SELECT 1 FROM sync_state WHERE `source` = ?', [self::MIGRATED]) !== false) {
            return false;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $pending = DB::exec('DELETE FROM adversus_pending');
            DB::exec('DELETE FROM sync_state WHERE `source` IN (?, ?)', [self::WARN, 'adversus-rec']);
            DB::exec('INSERT INTO sync_state (`source`, cursor_value, last_run_at) VALUES (?, ?, ?)', [self::MIGRATED, 'done', $this->now()]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ($this->log)('adversus-sync: H013: ventelisten (' . $pending . ' optagelser) og den gamle advarsel er ryddet — CDR\'er hentes fra '
            . self::iso($this->cfg->syncSince));
        return true;
    }

    // ---------------------------------------------------------------- a) leads

    /** @return int antal leads hentet i denne kørsel */
    public function leads(): int
    {
        $c = max($this->cursorInt(self::CURSOR_LEADS) ?? $this->cfg->syncSince, $this->cfg->syncSince);
        $page = 1;
        $n = 0;
        for ($i = 0; $i < self::LEAD_REQUESTS; $i++) {
            $data = $this->client->getJson('/leads', [
                'filters'       => self::filter(['lastUpdatedTime' => ['$gt' => $this->cfg->filterTime($c - 1)]]),
                'sortProperty'  => 'lastUpdatedTime',
                'sortDirection' => 'ASC',
                'pageSize'      => self::PAGE,
                'page'          => $page,
            ]);
            $items = self::items($data, 'leads');
            $max = $c;
            foreach ($items as $lead) {
                $id = self::posInt($lead['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                // Kun status (en enum-værdi) gemmes om leadet; alt andet er persondata og fritekst
                $status = is_string($lead['status'] ?? null) ? substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $lead['status']), 0, 32) : '';
                $t = self::epoch($lead['lastUpdatedTime'] ?? null);
                DB::exec('INSERT INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at) VALUES (?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), last_updated_at = VALUES(last_updated_at), synced_at = VALUES(synced_at)',
                    [$id, $status === '' ? 'unknown' : $status, $t === null ? null : gmdate('Y-m-d H:i:s', $t), $this->now()]);
                $n++;
                if ($t !== null) {
                    $max = max($max, $t);
                }
            }
            $this->stats['leads'] = $n;
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
        return $n;
    }

    // ---------------------------------------------------------------- b) agentnavne

    public function users(): void
    {
        $last = DB::scalar('SELECT last_run_at FROM sync_state WHERE `source` = ?', [self::CURSOR_USERS]);
        $lastTs = is_string($last) ? (int) strtotime($last . ' UTC') : 0;
        $age = $this->ts() - $lastTs;
        // Ukendte agenter har navnet "Adversus-bruger <id>" (indeks calls_agent), så ingen scanning af alle opkald
        $unknown = DB::scalar("SELECT 1 FROM calls WHERE agent_name LIKE ? AND `source` = 'adversus' LIMIT 1", [self::UNKNOWN_AGENT . '%']) !== false;
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
        $this->userNames = null;
        DB::exec("UPDATE calls c JOIN adversus_users u ON c.agent_ext_id = CAST(u.user_id AS CHAR) SET c.agent_name = u.name "
            . "WHERE c.agent_name LIKE ? AND c.`source` = 'adversus'", [self::UNKNOWN_AGENT . '%']);
        DB::exec('UPDATE sync_state SET last_ok_at = ? WHERE `source` = ?', [$this->now(), self::CURSOR_USERS]);
    }

    // ---------------------------------------------------------------- c) CDR'er

    /**
     * Alle opkald med start efter cursoren og før nu − lag, sorteret på start (keyset som leads). Cursoren gemmes efter
     * hver side, så backfill og afbrudte kørsler fortsætter, hvor de slap.
     */
    public function cdrs(): void
    {
        $this->reserve = self::RESERVE;
        [$t, $p, $h] = $this->cdrCursor();
        $hi = $this->ts() - $this->cfg->lag;
        try {
            while ($t < $hi) {
                $this->need(1);
                [$items] = $this->callsPage($t, $hi, $p, self::PAGE);
                $max = $this->saveCalls($items);
                if (count($items) < self::PAGE) {
                    // Alt med start før $hi er set: kontrollen må gå hertil, også når der ingen opkald var (nat)
                    $this->saveCdrCursor(max($t, $max ?? $t), 1, $hi);
                    break;
                }
                if ($max !== null && $max > $t) {
                    [$t, $p] = [$max, 1];
                } else {
                    $p++;   // en fuld side i samme sekund
                }
                $this->saveCdrCursor($t, $p, max($h, $t));
            }
        } catch (OutOfBudget) {
            // resten hentes ved næste kørsel; cursoren står ved sidst gemte side
        } finally {
            $this->reserve = 0;
        }
    }

    // ---------------------------------------------------------------- d) kontrol pr. time

    /**
     * Timer, cursoren er forbi, og som er CHECK_SETTLE gamle: Adversus' total (1 kald med pageSize 1) mod antal gemte.
     * Mangler der nogen, hentes timen igen én gang (samme upsert, ingen dubletter); derefter går kontrollen videre.
     */
    public function check(): void
    {
        $this->reserve = self::RESERVE;
        [$b, $p] = $this->checkCursor();
        $limit = min($this->ts() - self::CHECK_SETTLE, $this->cdrCursor()[2]);
        try {
            while ($b + self::CHECK_SPAN <= $limit) {
                $e = $b + self::CHECK_SPAN;
                if ($p === 0) {
                    $this->need(1);
                    [, $total] = $this->callsPage($b, $e, 1, 1);
                    $local = (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND started_at >= ? AND started_at < ?",
                        [gmdate('Y-m-d H:i:s', $b), gmdate('Y-m-d H:i:s', $e)]);
                    $this->stats['checked']++;
                    if ($total === null || $total <= $local) {
                        [$b, $p] = [$e, 0];
                        $this->saveCheckCursor($b, $p);
                        continue;
                    }
                    ($this->log)('adversus-sync: kontrol ' . self::iso($b) . ': Adversus har ' . $total . ' opkald, gemt ' . $local
                        . ' — timen hentes igen');
                    $this->stats['rescanned']++;
                    $p = 1;
                    $this->saveCheckCursor($b, $p);
                }
                while (true) {
                    $this->need(1);
                    [$items] = $this->callsPage($b, $e, $p, self::PAGE);
                    $this->saveCalls($items);
                    if (count($items) < self::PAGE) {
                        break;
                    }
                    $p++;
                    $this->saveCheckCursor($b, $p);
                }
                [$b, $p] = [$e, 0];
                $this->saveCheckCursor($b, $p);
            }
        } catch (OutOfBudget) {
            // fortsætter ved næste kørsel fra samme time og side
        } finally {
            $this->reserve = 0;
        }
    }

    /**
     * Én side /calls med start i [lo, hi) (Adversus har ingen $gte: $gt lo − 1 s).
     *
     * @return array{0: list<array<mixed>>, 1: ?int} opkaldene og meta.pagination.total
     */
    private function callsPage(int $lo, int $hi, int $page, int $size): array
    {
        $data = $this->client->getJson('/calls', [
            'filters'       => self::filter(['start' => ['$gt' => $this->cfg->filterTime($lo - 1), '$lt' => $this->cfg->filterTime($hi)]]),
            'sortProperty'  => 'start',
            'sortDirection' => 'ASC',
            'page'          => $page,
            'pageSize'      => $size,
            'includeMeta'   => 'true',
        ]);
        $total = $data['meta']['pagination']['total'] ?? null;
        return [self::items($data, 'calls'), is_int($total) ? $total : null];
    }

    /**
     * Gemmer en side opkald. Succes slås op for hele siden i én forespørgsel.
     *
     * @param list<array<mixed>> $items
     * @return ?int seneste start (epoch) på siden
     */
    private function saveCalls(array $items): ?int
    {
        $leads = [];
        foreach ($items as $call) {
            $lead = self::posInt($call['leadId'] ?? null);
            if ($lead !== null) {
                $leads[$lead] = true;
            }
        }
        $success = [];
        if ($leads !== []) {
            $rows = DB::all('SELECT lead_id FROM adversus_leads WHERE `status` = ? AND lead_id IN ('
                . implode(',', array_fill(0, count($leads), '?')) . ')', array_merge([$this->cfg->successStatus], array_keys($leads)));
            foreach ($rows as $r) {
                $success[(int) $r['lead_id']] = true;
            }
        }
        $max = null;
        foreach ($items as $call) {
            $id = self::posInt($call['id'] ?? null);
            $start = self::epoch($call['start'] ?? null);
            if ($start !== null) {
                $max = max($max ?? $start, $start);
            }
            if ($id !== null) {
                $this->saveCall($id, $call, $start, isset($success[self::posInt($call['leadId'] ?? null) ?? 0]));
            }
        }
        return $max;
    }

    /** @param array<mixed> $call */
    private function saveCall(int $id, array $call, ?int $start, bool $success): void
    {
        if ($start === null || $start < $this->cfg->syncSince) {
            return;   // intet før sync_since
        }
        $origin = $call['origin'] ?? null;
        if (!in_array($origin, ['inbound', 'outbound'], true)) {
            // Swagger kender kun inbound/outbound; noget andet tælles i advarslen i stedet for at gætte
            $this->stats['skipped']++;
            if (!in_array($id, $this->skippedIds, true)) {
                $this->skippedIds[] = $id;
            }
            return;
        }
        $lead = self::posInt($call['leadId'] ?? null);
        $campaign = self::posInt($call['campaignId'] ?? null);
        $user = self::posInt($call['userId'] ?? null);
        $duration = is_numeric($call['duration'] ?? null) ? max(0, (int) $call['duration']) : 0;
        $disp = is_string($call['disposition'] ?? null) && preg_match('/^[a-z-]{1,16}$/', $call['disposition']) === 1 ? $call['disposition'] : null;
        $talk = $disp === null || !in_array($disp, self::NO_CONVERSATION, true);
        // Zendesk-opkald gemmer nummeret (Zendesk\Sync::saveCall), så det må Adversus også (spec); samme rensning
        $phone = mb_substr((string) preg_replace('/[^0-9+ ]/', '', is_string($call['number'] ?? null) ? $call['number'] : ''), 0, 40);
        $agent = '';
        if ($user !== null) {
            $this->userNames ??= array_column(DB::all('SELECT user_id, name FROM adversus_users'), 'name', 'user_id');
            $name = $this->userNames[$user] ?? null;
            $agent = is_string($name) && $name !== '' ? $name : self::UNKNOWN_AGENT . $user;
        }
        $now = $this->now();
        // Nyt opkald: succes og status afgøres her. Kendt opkald: kun metadata; succes og status ejes af markSuccess og knappen
        $n = DB::exec('INSERT INTO calls (`source`, external_id, ticket_id, lead_id, campaign_id, is_success, direction, disposition, '
            . 'customer_phone, agent_name, agent_ext_id, started_at, duration_sec, recording_url, transcript_status, created_at, updated_at) '
            . "VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?) "
            . 'ON DUPLICATE KEY UPDATE lead_id = VALUES(lead_id), campaign_id = VALUES(campaign_id), direction = VALUES(direction), '
            . 'disposition = VALUES(disposition), customer_phone = VALUES(customer_phone), agent_name = VALUES(agent_name), '
            . 'agent_ext_id = VALUES(agent_ext_id), started_at = VALUES(started_at), duration_sec = VALUES(duration_sec), updated_at = VALUES(updated_at)',
            [self::SOURCE, (string) $id, $lead, $campaign, $success ? 1 : 0, $origin, $disp, $phone, $agent,
                $user === null ? null : (string) $user, gmdate('Y-m-d H:i:s', $start), $duration,
                $success && $talk ? 'ventende' : 'ikke_valgt', $now, $now]);
        $this->stats['cdr']++;
        if ($n === 1) {
            $this->stats['new']++;
            if ($success) {
                $this->stats['success']++;
            }
        }
    }

    // ---------------------------------------------------------------- e) succes

    /** Opkald, hvis lead er blevet success, efter de blev gemt. Markeringen fjernes aldrig. */
    public function markSuccess(): int
    {
        $n = DB::exec("UPDATE calls c JOIN adversus_leads l ON l.lead_id = c.lead_id SET c.is_success = 1, "
            . "c.transcript_status = IF(c.transcript_status = 'ikke_valgt' AND (c.disposition IS NULL OR c.disposition NOT IN ("
            . implode(',', array_fill(0, count(self::NO_CONVERSATION), '?')) . ")), 'ventende', c.transcript_status), c.updated_at = ? "
            . "WHERE l.`status` = ? AND c.`source` = 'adversus' AND c.is_success = 0",
            array_merge(self::NO_CONVERSATION, [$this->now(), $this->cfg->successStatus]));
        $this->stats['success'] += $n;
        return $n;
    }

    // ---------------------------------------------------------------- f) lyd

    /** @return array{ok: int, failed: int} */
    public function downloadPending(): array
    {
        // Kun opkald i kø (succes eller manuelt valgt; IN-listen bruger indekset calls_audio_queue). Succes før manuelt
        // valgte (security S1), derefter nyeste først som transcription-køen (H012). Et opkald, der lige har svaret 404,
        // venter NO_AUDIO_RETRY, før det prøves igen.
        $rows = DB::all("SELECT id, external_id, started_at, duration_sec FROM calls WHERE `source` = ? "
            . "AND transcript_status IN ('ventende', 'transcriberer', 'faerdig', 'fejlet') "
            . "AND (audio_status = 'venter' OR (audio_status = 'fejlet' AND audio_attempts < ?)) "
            . 'AND NOT (audio_error <=> ? AND audio_reset_at > ?) '
            . 'ORDER BY is_success DESC, started_at DESC, id DESC LIMIT ' . self::MAX_DOWNLOADS,
            [self::SOURCE, self::MAX_AUDIO_ATTEMPTS, self::NO_AUDIO_YET, gmdate('Y-m-d H:i:s', $this->ts() - self::NO_AUDIO_RETRY)]);
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
                if ($e instanceof AdversusError && $e->getCode() === 404) {
                    $this->noRecording($id, (string) $r['started_at'], (int) $r['duration_sec']);
                    continue;
                }
                $msg = $e instanceof AdversusError || $e instanceof ZendeskError ? self::safe($e->getMessage()) : 'intern fejl ved hentning';
                DB::exec("UPDATE calls SET audio_status = 'fejlet', audio_attempts = audio_attempts + 1, audio_error = ?, "
                    . 'updated_at = ? WHERE id = ?', [mb_substr($msg, 0, 250), $this->now(), $id]);
                ($this->log)('adversus-sync: opkald ' . $id . ': lyd ikke hentet: ' . $msg);
                $failed++;
                $this->stats['failed']++;
            }
        }
        return ['ok' => $ok, 'failed' => $failed];
    }

    /**
     * 404: opkaldet har ingen optagelse — endnu. Lige efter opkaldet kan den mangle (upload kommer efter slut), så det
     * prøves igen efter NO_AUDIO_RETRY uden at bruge et forsøg. Er opkaldet NO_AUDIO_FINAL gammelt, er det endeligt:
     * alle forsøg brugt (tæller ikke som manuelt valg i gang), og siden viser "Lyd mangler".
     */
    private function noRecording(int $id, string $startedAt, int $duration): void
    {
        $end = (int) strtotime($startedAt . ' UTC') + $duration;
        if ($this->ts() - $end < self::NO_AUDIO_FINAL) {
            // audio_reset_at er tidspunktet for 404'en (updated_at flyttes af hver metadata-upsert)
            DB::exec("UPDATE calls SET audio_status = 'venter', audio_error = ?, audio_reset_at = ?, updated_at = ? WHERE id = ?",
                [self::NO_AUDIO_YET, $this->now(), $this->now(), $id]);
            return;
        }
        DB::exec("UPDATE calls SET audio_status = 'fejlet', audio_attempts = ?, audio_error = ?, audio_reset_at = NULL, updated_at = ? WHERE id = ?",
            [self::MAX_AUDIO_ATTEMPTS, self::NO_AUDIO, $this->now(), $id]);
        $this->stats['no_audio']++;
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
        if ($this->client->remaining() - $n < $this->reserve) {
            throw new OutOfBudget();
        }
    }

    /** @return array{0: int, 1: int, 2: int} seneste start, side og grænsen, alt før er set (h) */
    private function cdrCursor(): array
    {
        $d = $this->cursorJson(self::CURSOR_CDR);
        $t = is_int($d['t'] ?? null) ? $d['t'] : $this->cfg->syncSince;
        $p = is_int($d['p'] ?? null) && $d['p'] > 0 ? $d['p'] : 1;
        $h = is_int($d['h'] ?? null) ? $d['h'] : $t;
        return $t < $this->cfg->syncSince ? [$this->cfg->syncSince, 1, $this->cfg->syncSince] : [$t, $p, max($h, $t)];
    }

    private function saveCdrCursor(int $t, int $p, int $h): void
    {
        $this->saveCursor(self::CURSOR_CDR, (string) json_encode(['t' => $t, 'p' => $p, 'h' => $h]));
    }

    /** @return array{0: int, 1: int} næste time, der skal kontrolleres, og siden (0 = ikke talt endnu) */
    private function checkCursor(): array
    {
        $d = $this->cursorJson(self::CURSOR_CHECK);
        $b = is_int($d['b'] ?? null) ? $d['b'] : $this->cfg->syncSince;
        $p = is_int($d['p'] ?? null) && $d['p'] >= 0 ? $d['p'] : 0;
        return $b < $this->cfg->syncSince ? [$this->cfg->syncSince, 0] : [$b, $p];
    }

    private function saveCheckCursor(int $b, int $p): void
    {
        $this->saveCursor(self::CURSOR_CHECK, (string) json_encode(['b' => $b, 'p' => $p]));
    }

    /** @return array<mixed> */
    private function cursorJson(string $name): array
    {
        $raw = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [$name]);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
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
