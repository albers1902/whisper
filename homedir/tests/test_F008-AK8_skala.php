<?php
declare(strict_types=1);

// F008-AK8 (ejerens krav: flere hundrede tusinde opkald og 100.000 leads; mønstret fra F007-AK12): lead-listen, antallet
// af leads, "Uden lead", kortenes tal og lead-siden er hurtige ved ca. 300.000 Adversus-opkald på ca. 100.000 leads — både
// med mange træf (A) og få (B). EXPLAIN-planerne skal bruge et indeks og læse få rækker ved få træf. Desuden: indekset
// calls_source_lead gør ikke transcription-workerens statusskift langsommere. Planer og tider skrives ud.

require_once __DIR__ . '/f008_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\Leads;

f008_setup();
$t0 = microtime(true);
// 1.172 rækker på 391 leads (3 opkald pr. lead), fordoblet 8 gange = 300.032 opkald og 100.096 leads. Hvert 20. lead er
// succes (færdige transcripts), resten "ikke valgt"; hvert 3. opkald er ubesvaret; nogle få er valgt manuelt; hvert 50.
// opkald har intet lead.
$rows = [];
$params = [];
for ($i = 0; $i < 1172; $i++) {
    $lead = intdiv($i, 3) + 1;
    $orphan = $i % 50 === 49;
    $success = !$orphan && $lead % 20 === 1;
    $missed = $i % 3 === 1;
    $status = $success && !$missed ? 'faerdig' : (!$missed && $i % 97 === 0 ? 'ventende' : 'ikke_valgt');
    $audio = $status === 'ikke_valgt' ? 'venter' : 'hentet';
    $rows[] = "('adversus', ?, ?, ?, 'outbound', ?, ?, ?, ?, '', ?, ?, 31, '+45 12 34 56 78', NOW(), NOW())";
    array_push($params, 'l' . $i, $orphan ? null : $lead, $success ? 1 : 0, $missed ? 'no-answer' : null, 'Agent ' . ($i % 40),
        gmdate('Y-m-d H:i:s', 1790000000 + $lead * 25000 + ($i % 3) * 3600), $missed ? 0 : 30 + $i % 600, $audio, $status);
}
$cols = '`source`, external_id, lead_id, is_success, direction, disposition, agent_name, started_at, duration_sec, recording_url, audio_status, '
    . 'transcript_status, campaign_id, customer_phone, created_at, updated_at';
DB::exec('INSERT INTO calls (' . $cols . ') VALUES ' . implode(',', $rows), $params);
for ($k = 1; $k <= 8; $k++) {
    DB::exec('INSERT INTO calls (' . $cols . ') SELECT `source`, CONCAT(external_id, ?), lead_id + ?, is_success, direction, disposition, agent_name, '
        . 'DATE_SUB(started_at, INTERVAL ? MINUTE), duration_sec, recording_url, audio_status, transcript_status, campaign_id, customer_phone, '
        . 'created_at, updated_at FROM calls', ['-' . $k, 400 * 2 ** ($k - 1), $k * 7]);
}
DB::exec("INSERT INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at) SELECT lead_id, IF(MAX(is_success) = 1, 'success', 'notInterested'), "
    . 'NOW(), NOW() FROM calls WHERE lead_id IS NOT NULL GROUP BY lead_id');
DB::exec('ANALYZE TABLE calls');
$total = (int) DB::scalar('SELECT COUNT(*) FROM calls');
$leads = (int) DB::scalar('SELECT COUNT(*) FROM adversus_leads');
printf("seed: %d opkald, %d leads på %.1f s\n", $total, $leads, microtime(true) - $t0);
F008Check::that('AK8', $total === 300032 && $leads > 99000, "seed: $total opkald, $leads leads");

/** @return array{0: float, 1: mixed} */
$time = static function (callable $fn): array {
    $t = microtime(true);
    $r = $fn();
    return [microtime(true) - $t, $r];
};
/** @return array{0: string, 1: int} planen som tekst og det største rows-estimat (afledte tabeller tælles ikke) */
$explain = static function (string $sql, array $p): array {
    $plan = DB::all('EXPLAIN ' . $sql, $p);
    $max = 0;
    $txt = [];
    foreach ($plan as $r) {
        if (!str_starts_with((string) ($r['table'] ?? ''), '<')) {
            $max = max($max, (int) ($r['rows'] ?? 0));
        }
        $txt[] = ($r['table'] ?? '') . ':' . ($r['type'] ?? '') . ' key=' . ($r['key'] ?? 'NULL') . ' rows=' . ($r['rows'] ?? '?')
            . (($r['Extra'] ?? '') !== '' ? ' (' . $r['Extra'] . ')' : '');
    }
    return [implode(' | ', $txt), $max];
};
$sum = [];
// Præcis den SQL, siden kører (Leads::countSql/listSql/walkSql)
$run = static function (string $name, array $q, ?int $maxRows, float $maxSec) use ($time, $explain, &$sum): void {
    $f = Calls::uiFilter(['kilde' => 'adversus'] + $q);
    [$sec, $res] = $time(static fn(): array => Leads::search($f));
    [$secS] = $time(static fn(): array => Leads::summaries(array_keys($res['leads'])));
    [$secO, $orph] = $time(static fn(): int => Leads::orphanCount($f));
    $walk = $res['total'] > Calls::COUNT_CAP;
    [$sql, $p] = $walk ? Leads::walkSql($f, null) : Leads::listSql($f, $res['side']);
    [$plan, $rows] = $explain($sql, $p);
    [$csql, $pc] = Leads::countSql($f);
    [$cplan, $crows] = $explain($csql, $pc);
    $n = $res['total'] > Calls::COUNT_CAP ? '10k+' : (string) $res['total'];
    $line = sprintf('%s %.0fms n=%s kort=%.0fms uden-lead=%.0fms(%d) %s L[%s] C[%s]', $name, $sec * 1000, $n, $secS * 1000, $secO * 1000, $orph,
        $walk ? 'gang' : 'gruppe', $plan, $cplan);
    echo $line . "\n";
    // Kort form til resuméet (DEV's PR-kommentar viser kun loggens sidste linjer, 300 tegn hver)
    $short = static fn(string $pl): string => trim((string) preg_replace(['/<derived[0-9]+>:[^|]*\| ?/', '/ \([^)]*\)/', '/ key=/', '/ rows=/'],
        ['', '', ' ', ' r'], $pl));
    $sum[] = sprintf('%s %.0f+%.0f+%.0fms n=%s %s L[%s] C[%s]', $name, $sec * 1000, $secS * 1000, $secO * 1000, $n, $walk ? 'gang' : 'grp',
        $short($plan), $short($cplan));
    F008Check::that('AK8', !str_contains($plan, 'c:ALL') && !str_contains($cplan, 'c:ALL'), "$name: liste og antal bruger indeks");
    if ($maxRows !== null) {
        F008Check::that('AK8', $rows <= $maxRows && $crows <= $maxRows, "$name: få rækker i planen (liste $rows, antal $crows ≤ $maxRows)");
    }
    F008Check::that('AK8', count($res['leads']) === min(Calls::PAGE_SIZE, max(0, min($res['total'], Calls::COUNT_CAP) - ($res['side'] - 1) * Calls::PAGE_SIZE)),
        "$name: siden har det rigtige antal leads (" . count($res['leads']) . ')');
    F008Check::that('AK8', $sec + $secS + $secO < $maxSec, sprintf('%s: %.0f ms i alt (< %d)', $name, ($sec + $secS + $secO) * 1000, $maxSec * 1000));
};

// A. Mange træf
$run('A Succes og valgte', [], null, 1.0);
$run('A Alle leads', ['vis' => 'alle'], null, 1.0);
$run('A Alle leads, side 200', ['vis' => 'alle', 'side' => '200'], null, 1.0);
$run('A Alle, Færdige', ['vis' => 'alle', 'status' => 'faerdig'], null, 1.0);
$run('A Alle, I kø', ['vis' => 'alle', 'status' => 'koe'], null, 1.0);
$run('A Alle, agent', ['vis' => 'alle', 'agent' => 'Agent 5'], null, 1.0);
$run('A Alle, periode', ['vis' => 'alle', 'fra' => '2026-09-01', 'til' => '2026-09-30'], null, 1.0);
$run('A Alle, agent + periode', ['vis' => 'alle', 'agent' => 'Agent 7', 'fra' => '2026-09-01', 'til' => '2026-12-31'], null, 1.0);
// Rækkefølgen: seneste aktivitet først (gang og gruppe giver samme rækkefølge)
$f = Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle']);
$page = Leads::search($f)['leads'];
$lasts = array_values($page);
$sorted = $lasts;
rsort($sorted);
F008Check::that('AK8', $lasts === $sorted, 'A: leads sorteret efter seneste opkald');
// Den anden fanes tal (Zendesk aktiv): antal leads med loft
[$sec, $n] = $time(static fn(): int => Leads::count(['kilde' => 'adversus', 'agent' => '', 'vis' => ''] + Calls::uiFilter([])));
printf("A anden fanes antal (leads) %.1f ms (%d)\n", $sec * 1000, $n);
F008Check::that('AK8', $sec < 1.0, sprintf('den anden fanes antal: %.0f ms', $sec * 1000));
// Lead-siden: et lead og dets opkald
$one = (int) array_key_first($page);
[$secL, $calls] = $time(static fn(): array => [Leads::exists($one), Leads::calls($one, 0, Leads::LEAD_PAGE), Leads::summaries([$one]), Leads::status($one)]);
[$lplan, $lrows] = $explain("SELECT c.* FROM calls c WHERE c.`source` = 'adversus' AND c.lead_id = ? ORDER BY c.started_at, c.id LIMIT 100", [$one]);
printf("A lead-side %.1f ms: %s\n", $secL * 1000, $lplan);
$sum[] = sprintf('lead-side %.0fms %s', $secL * 1000, (string) preg_replace('/ \([^)]*\)/', '', $lplan));
F008Check::that('AK8', str_contains($lplan, 'calls_source_lead') && $lrows < 100 && $secL < 0.2, sprintf('lead-siden: indeks, %d rækker, %.0f ms', $lrows, $secL * 1000));

// B. Få træf: næsten alt "ikke valgt" uden succes
$t1 = microtime(true);
DB::exec("UPDATE calls SET transcript_status = 'ikke_valgt', is_success = 0, audio_status = 'venter'");
DB::exec("UPDATE calls SET transcript_status = 'ventende', audio_status = 'hentet' WHERE disposition IS NULL AND lead_id IS NOT NULL ORDER BY id LIMIT 5");
DB::exec("UPDATE calls SET transcript_status = 'faerdig', audio_status = 'hentet', is_success = 1 WHERE lead_id IN (SELECT lead_id FROM "
    . '(SELECT DISTINCT lead_id FROM calls WHERE lead_id IS NOT NULL ORDER BY lead_id DESC LIMIT 5) x) AND disposition IS NULL');
DB::exec("UPDATE calls SET agent_name = 'Sjælden Agent' WHERE lead_id IS NOT NULL ORDER BY id LIMIT 3");
DB::exec('ANALYZE TABLE calls');
printf("B: omfordelt på %.1f s\n", microtime(true) - $t1);
$run('B Succes og valgte (10)', [], 1000, 1.0);
$run('B Alle, I kø (5)', ['vis' => 'alle', 'status' => 'koe'], 1000, 1.0);
$run('B Alle, Færdige (5)', ['vis' => 'alle', 'status' => 'faerdig'], 1000, 1.0);
$run('B Alle, agent med 3', ['vis' => 'alle', 'agent' => 'Sjælden Agent'], 1000, 1.0);
$run('B Alle leads (uændret mange)', ['vis' => 'alle'], null, 1.0);
F008Check::that('AK8', count(Leads::search(Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'faerdig']))['leads']) === 5,
    'B: de rigtige leads kommer frem');

// Transcription må ikke blive langsommere: workerens statusskift med og uden calls_source_lead
$ids = array_map(static fn(array $r): int => (int) $r['id'], DB::all('SELECT id FROM calls ORDER BY id LIMIT 2000'));
$bench = static function (array $ids): float {
    $t = microtime(true);
    foreach ($ids as $id) {
        DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
        DB::exec("UPDATE calls SET transcript_status = 'faerdig', claimed_at = NULL, updated_at = NOW() WHERE id = ?", [$id]);
    }
    return microtime(true) - $t;
};
$with = $bench($ids);
DB::exec('ALTER TABLE calls DROP INDEX calls_source_lead');
$without = $bench($ids);
DB::exec('ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_lead (`source`, lead_id, started_at, id)');
$with2 = $bench($ids);
printf("worker-statusskift (4.000 UPDATE): med indeks %.0f ms / %.0f ms, uden %.0f ms\n", $with * 1000, $with2 * 1000, $without * 1000);
$sum[] = sprintf('worker 4000 UPDATE: med %.0f/%.0f ms, uden %.0f ms', $with * 1000, $with2 * 1000, $without * 1000);
F008Check::that('AK8', min($with, $with2) <= $without * 1.5 + 0.05, 'statusskift er ikke langsommere med indekset');

DB::exec('DELETE FROM adversus_leads');
DB::exec('DELETE FROM calls');
// Resuméet pakket i linjer på højst 290 tegn
$lines = [];
$cur = '';
foreach ($sum as $item) {
    if ($cur !== '' && strlen($cur) + strlen($item) + 3 > 290) {
        $lines[] = $cur;
        $cur = '';
    }
    $cur .= ($cur !== '' ? ' ## ' : '') . $item;
}
$lines[] = $cur;
echo "== RESUME\n" . implode("\n", $lines) . "\n";
F008Check::done();
