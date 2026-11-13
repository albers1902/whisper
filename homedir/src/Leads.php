<?php
declare(strict_types=1);

namespace Whisper;

use PDOException;
use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\AdversusError;
use Whisper\Adversus\Feature;
use Whisper\Adversus\Sync as AdversusSync;
use Whisper\Transcription\QueueRules;

/**
 * Adversus pr. lead (F008, product-ai/F008_v1.md): listen af leads på Adversus-fanen, et leads opkald og dets downloads.
 * Et lead er med i listen, hvis mindst ét af dets opkald matcher fanens filtre (Calls::where), og listen sorteres efter det
 * seneste matchende opkald. "Færdige" er dog leadets samlede status: mindst ét besvaret opkald, og alle besvarede er
 * færdige. Kortets tal (antal, taletid, første/seneste, status) gælder altid hele leadet.
 *
 * Skala (AK8, som F007-AK12): antallet tælles højst til Calls::COUNT_CAP + 1. Op til loftet grupperes træffene pr. lead i
 * SQL; over loftet går listen i tidsindekset (nyeste først) og samler de første forskellige leads, så hverken 100.000
 * grupper sorteres eller hele tabellen læses for side 1.
 *
 * @phpstan-type Filter array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string, kilde?: string, status?: string}
 * @phpstan-type Summary array{lead_id: int, calls: int, answered: int, talk: int, first_at: string, last_at: string, success: bool,
 *     done: int, audio: int, activity: ?string, campaign: int, phone: string, agents: list<string>, status: ?string}
 */
final class Leads
{
    public const FLAG = 'F008_leads';
    /** Højst så mange opkald pr. lead-side (Team Lead, design §10.1). Download dækker hele leadet. */
    public const LEAD_PAGE = 100;
    /** Over loftet: rækker pr. opslag i tidsindekset, og højst så mange rækker i alt pr. side. */
    public const WALK_BATCH = 2000;
    public const WALK_MAX = 400000;
    /** Lead-zip og -txt (Team Lead): højst så mange opkald og så meget lyd; resten nævnes i LÆS-MIG.txt. */
    public const ZIP_MAX_CALLS = 500;
    public const ZIP_MAX_BYTES = 1073741824;
    /** Aktiviteten på et lead, højeste først (UI/UX §2.3). */
    public const ACTIVITY = ['transcriberer', 'koe', 'fejlet', 'lyd', 'venter'];

    /** Kræver det nye UI (F007) og Adversus (F004). Slukket: Adversus-fanen er som F007, og /lead/... findes ikke (AK9). */
    public static function on(): bool
    {
        return UiV2::on() && Feature::on() && Flags::enabled(self::FLAG);
    }

    /** Et besvaret opkald: der blev talt (H013: disposition er tom eller ikke en af Sync::NO_CONVERSATION). */
    public static function answeredSql(string $alias = 'c'): string
    {
        return '(' . $alias . '.disposition IS NULL OR ' . $alias . ".disposition NOT IN ('" . implode("', '", AdversusSync::NO_CONVERSATION) . "'))";
    }

    /**
     * Fanens WHERE (Calls::where med kilden Adversus) plus lead-delen. "I kø" er pr. opkald (mindst ét i kø); "Færdige" er
     * pr. lead. $withLead = false giver opkaldene uden lead med samme filtre ("Færdige" pr. opkald som i F007).
     *
     * @param Filter $f
     * @return array{0: string, 1: list<mixed>}
     */
    public static function where(array $f, bool $withLead = true): array
    {
        $status = $f['status'] ?? '';
        $f['kilde'] = 'adversus';
        $f['status'] = $status === Calls::STATUS_QUEUE ? $status : '';
        [$w, $p] = Calls::where($f);
        $w .= $withLead ? ' AND c.lead_id IS NOT NULL' : ' AND c.lead_id IS NULL';
        if ($status === Calls::STATUS_DONE) {
            $w .= " AND c.transcript_status = 'faerdig'";
            if ($withLead) {
                // Ét færdigt, besvaret opkald findes; intet besvaret opkald på leadet mangler (opslag i calls_source_lead)
                $w .= ' AND ' . self::answeredSql('c') . " AND NOT EXISTS (SELECT 1 FROM calls c2 WHERE c2.`source` = 'adversus' "
                    . 'AND c2.lead_id = c.lead_id AND ' . self::answeredSql('c2') . " AND c2.transcript_status <> 'faerdig')";
            }
        }
        return [$w, $p];
    }

    /** @param Filter $f  antallet af leads, højst Calls::COUNT_CAP + 1 */
    public static function count(array $f): int
    {
        [$sql, $p] = self::countSql($f);
        return (int) DB::scalar($sql, $p);
    }

    /** @param Filter $f @return array{0: string, 1: list<mixed>} */
    public static function countSql(array $f): array
    {
        [$w, $p] = self::where($f);
        return ['SELECT COUNT(*) FROM (SELECT c.lead_id FROM calls c WHERE ' . $w . ' GROUP BY c.lead_id LIMIT ' . (Calls::COUNT_CAP + 1) . ') n', $p];
    }

    /**
     * Én side af lead-listen: lead-id'erne i rækkefølge med tidspunktet for deres seneste matchende opkald.
     *
     * @param Filter $f
     * @return array{leads: array<int, string>, total: int, side: int}  total = COUNT_CAP + 1 betyder "flere end"
     */
    public static function search(array $f): array
    {
        $total = self::count($f);
        $side = max(1, min($f['side'], (int) ceil(min($total, Calls::COUNT_CAP) / Calls::PAGE_SIZE)));
        if ($total === 0) {
            return ['leads' => [], 'total' => 0, 'side' => $side];
        }
        if ($total <= Calls::COUNT_CAP) {
            [$sql, $p] = self::listSql($f, $side);
            $leads = [];
            foreach (DB::all($sql, $p) as $r) {
                $leads[(int) $r['lead_id']] = (string) $r['last_at'];
            }
            return ['leads' => $leads, 'total' => $total, 'side' => $side];
        }
        return ['leads' => self::walk($f, $side), 'total' => $total, 'side' => $side];
    }

    /** @param Filter $f  op til loftet: grupperet i SQL (højst COUNT_CAP grupper) @return array{0: string, 1: list<mixed>} */
    public static function listSql(array $f, int $side): array
    {
        [$w, $p] = self::where($f);
        return ['SELECT c.lead_id, MAX(c.started_at) AS last_at FROM calls c WHERE ' . $w . ' GROUP BY c.lead_id ORDER BY last_at DESC, c.lead_id DESC '
            . 'LIMIT ' . Calls::PAGE_SIZE . ' OFFSET ' . (($side - 1) * Calls::PAGE_SIZE), $p];
    }

    /**
     * Over loftet: træffene nyeste først i tidsindekset (keyset efter started_at, id). Det første opkald, der ses for et
     * lead, er dets seneste matchende. FORCE, som i F007 (Calls::listSql), så optimizeren ikke sorterer alle træf.
     *
     * @param Filter $f
     * @param array{0: string, 1: int}|null $after
     * @return array{0: string, 1: list<mixed>}
     */
    public static function walkSql(array $f, ?array $after, bool $force = true): array
    {
        [$w, $p] = self::where($f);
        if ($after !== null) {
            $w .= ' AND c.started_at <= ? AND (c.started_at < ? OR c.id < ?)';
            array_push($p, $after[0], $after[0], $after[1]);
        }
        return ['SELECT c.id, c.lead_id, c.started_at FROM calls c' . ($force ? ' FORCE INDEX (calls_source_started)' : '') . ' WHERE ' . $w
            . ' ORDER BY c.started_at DESC, c.id DESC LIMIT ' . self::WALK_BATCH, $p];
    }

    /** @param Filter $f @return array<int, string> */
    private static function walk(array $f, int $side): array
    {
        $need = $side * Calls::PAGE_SIZE;
        $seen = [];
        $after = null;
        $read = 0;
        $force = true;
        while (count($seen) < $need && $read < self::WALK_MAX) {
            [$sql, $p] = self::walkSql($f, $after, $force);
            try {
                $rows = DB::all($sql, $p);
            } catch (PDOException $e) {
                // 1176 = indekset findes ikke (down/0010 med flaget tændt): samme opslag uden FORCE, aldrig en fejlside
                if ($force && (int) ($e->errorInfo[1] ?? 0) === 1176) {
                    $force = false;
                    continue;
                }
                throw $e;
            }
            foreach ($rows as $r) {
                $seen[(int) $r['lead_id']] ??= (string) $r['started_at'];
                if (count($seen) >= $need) {
                    break;
                }
            }
            if (count($rows) < self::WALK_BATCH) {
                break;
            }
            $last = $rows[count($rows) - 1];
            $after = [(string) $last['started_at'], (int) $last['id']];
            $read += count($rows);
        }
        return array_slice($seen, ($side - 1) * Calls::PAGE_SIZE, Calls::PAGE_SIZE, true);
    }

    /**
     * Kortenes tal for højst én side leads, over HELE leadet: én GROUP BY, én for agenterne og én for lead-status.
     *
     * @param list<int> $leads
     * @return array<int, Summary>
     */
    public static function summaries(array $leads, ?int $now = null): array
    {
        $leads = array_slice(array_values(array_unique(array_map('intval', $leads))), 0, Calls::PAGE_SIZE);
        if ($leads === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($leads), '?'));
        $ans = self::answeredSql('c');
        [$rule, $rp] = QueueRules::fromConfig()->eligibleSql($now ?? time(), 'c');
        $active = $ans . " AND c.transcript_status <> 'ikke_valgt'";
        $rows = DB::all('SELECT c.lead_id, COUNT(*) AS n, SUM(' . $ans . ') AS answered, SUM(IF(' . $ans . ', c.duration_sec, 0)) AS talk, '
            . 'MIN(c.started_at) AS first_at, MAX(c.started_at) AS last_at, MAX(c.is_success) AS success, '
            . 'SUM(' . $ans . " AND c.transcript_status = 'faerdig') AS done, SUM(c.audio_status = 'hentet') AS audio, "
            . 'SUM(' . $ans . " AND c.transcript_status = 'transcriberer') AS a_transcriberer, "
            . 'SUM(' . $ans . " AND c.transcript_status = 'ventende' AND c.audio_status = 'hentet' AND " . $rule . ') AS a_koe, '
            . 'SUM(' . $ans . " AND c.transcript_status = 'fejlet') AS a_fejlet, "
            . 'SUM(' . $active . " AND c.audio_status = 'fejlet') AS a_lyd, "
            . 'SUM(' . $active . " AND c.audio_status = 'venter') AS a_venter, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(c.campaign_id, 0) ORDER BY c.started_at DESC, c.id DESC SEPARATOR '|'), '|', 1) AS campaign, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(c.customer_phone, '') ORDER BY c.started_at DESC, c.id DESC SEPARATOR '|'), '|', 1) AS phone "
            . "FROM calls c WHERE c.`source` = 'adversus' AND c.lead_id IN (" . $in . ') GROUP BY c.lead_id', array_merge($rp, $leads));
        $agents = [];
        foreach (DB::all("SELECT c.lead_id, c.agent_name, MIN(c.started_at) AS f FROM calls c WHERE c.`source` = 'adversus' AND c.lead_id IN ("
            . $in . ') GROUP BY c.lead_id, c.agent_name ORDER BY c.lead_id, f, c.agent_name', $leads) as $a) {
            $agents[(int) $a['lead_id']][] = trim((string) $a['agent_name']);   // '' = ukendt agent (vises af UiLeads)
        }
        $status = [];
        foreach (DB::all('SELECT lead_id, `status` FROM adversus_leads WHERE lead_id IN (' . $in . ')', $leads) as $s) {
            $status[(int) $s['lead_id']] = (string) $s['status'];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['lead_id'];
            $activity = null;
            foreach (self::ACTIVITY as $a) {
                if ((int) $r['a_' . $a] > 0) {
                    $activity = $a;
                    break;
                }
            }
            $out[$id] = ['lead_id' => $id, 'calls' => (int) $r['n'], 'answered' => (int) $r['answered'], 'talk' => (int) $r['talk'],
                'first_at' => (string) $r['first_at'], 'last_at' => (string) $r['last_at'], 'success' => (int) $r['success'] === 1,
                'done' => (int) $r['done'], 'audio' => (int) $r['audio'], 'activity' => $activity, 'campaign' => (int) $r['campaign'], 'phone' => (string) ($r['phone'] ?? ''),
                'agents' => array_values(array_unique($agents[$id] ?? [])), 'status' => $status[$id] ?? null];
        }
        return $out;
    }

    /**
     * Søgeuddrag på lead-kortet: det første (ældste) matchende opkald pr. lead.
     *
     * @param Filter $f
     * @param list<int> $leads
     * @return array<int, array{id: int, started_at: string}>
     */
    public static function firstMatches(array $f, array $leads): array
    {
        $leads = array_slice(array_values(array_unique(array_map('intval', $leads))), 0, Calls::PAGE_SIZE);
        if ($leads === []) {
            return [];
        }
        [$w, $p] = self::where($f);
        $rows = DB::all('SELECT c.lead_id, c.id, c.started_at FROM calls c WHERE ' . $w . ' AND c.lead_id IN ('
            . implode(',', array_fill(0, count($leads), '?')) . ') ORDER BY c.started_at, c.id', array_merge($p, $leads));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['lead_id']] ??= ['id' => (int) $r['id'], 'started_at' => (string) $r['started_at']];
        }
        return $out;
    }

    /** @param Filter $f  opkald uden lead i filtret, højst COUNT_CAP + 1 */
    public static function orphanCount(array $f): int
    {
        [$w, $p] = self::where($f, false);
        return (int) DB::scalar('SELECT COUNT(*) FROM (SELECT 1 FROM calls c WHERE ' . $w . ' LIMIT ' . (Calls::COUNT_CAP + 1) . ') n', $p);
    }

    /**
     * Opkaldene uden lead (AK10), nyeste først, én side som F007.
     *
     * @param Filter $f
     * @return array{rows: list<array<string, mixed>>, total: int, side: int}
     */
    public static function orphans(array $f): array
    {
        $total = self::orphanCount($f);
        $side = max(1, min($f['side'], (int) ceil(min($total, Calls::COUNT_CAP) / Calls::PAGE_SIZE)));
        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'side' => $side];
        }
        [$w, $p] = self::where($f, false);
        $rows = DB::all('SELECT c.* FROM calls c WHERE ' . $w . ' ORDER BY c.started_at DESC, c.id DESC LIMIT ' . Calls::PAGE_SIZE
            . ' OFFSET ' . (($side - 1) * Calls::PAGE_SIZE), $p);
        return ['rows' => $rows, 'total' => $total, 'side' => $side];
    }

    /** Antal opkald på leadet, eller null hvis leadet hverken har opkald eller en række i adversus_leads. */
    public static function exists(int $lead): ?int
    {
        $n = (int) DB::scalar("SELECT COUNT(*) FROM calls c WHERE c.`source` = 'adversus' AND c.lead_id = ?", [$lead]);
        if ($n > 0) {
            return $n;
        }
        return DB::scalar('SELECT 1 FROM adversus_leads WHERE lead_id = ?', [$lead]) !== false ? 0 : null;
    }

    /** Leadets status i adversus_leads (renset af Sync: [A-Za-z0-9_-]{1,32}), eller null. */
    public static function status(int $lead): ?string
    {
        $s = DB::scalar('SELECT `status` FROM adversus_leads WHERE lead_id = ?', [$lead]);
        return is_string($s) ? $s : null;
    }

    /**
     * Leadets opkald i tidsrækkefølge (ældste først), fra $offset (calls_source_lead).
     *
     * @return list<array<string, mixed>>
     */
    public static function calls(int $lead, int $offset, int $limit): array
    {
        return DB::all("SELECT c.* FROM calls c WHERE c.`source` = 'adversus' AND c.lead_id = ? ORDER BY c.started_at, c.id LIMIT "
            . max(1, $limit) . ' OFFSET ' . max(0, $offset), [$lead]);
    }

    /** [adversus] success_status (standard success); en ugyldig opsætning giver standarden. */
    public static function successStatus(): string
    {
        try {
            return AdversusConfig::fromSection(Config::section('adversus'))?->successStatus ?? 'success';
        } catch (AdversusError) {
            return 'success';
        }
    }

    /** Opkaldet er besvaret (samme regel som answeredSql). @param array<string, mixed> $call */
    public static function answered(array $call): bool
    {
        return !in_array($call['disposition'] ?? null, AdversusSync::NO_CONVERSATION, true);
    }
}
