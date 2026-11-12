<?php
declare(strict_types=1);

// F007-AK2 (ejerens krav 2026-11-12: "der er flere 100.000 opkald"): antal pr. fane, statusfiltre, kø-kortet, uddrag og
// paginering er hurtige ved ~256.000 rækker i calls. Tjekker med EXPLAIN, at listens forespørgsler bruger F007's
// indekser (migration 0010), at antal tælles med loft (Calls::COUNT_CAP), og måler tiden. Planer og tider skrives ud.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\UiV2;

f007_setup();
$t0 = microtime(true);
// 1.000 rækker, fordoblet 8 gange = 256.000. Fordelingen ligner DEV: flest Adversus uden succes ("ikke valgt").
$rows = [];
$params = [];
for ($i = 0; $i < 1000; $i++) {
    $adv = $i % 5 !== 0;
    $success = $adv && $i % 20 === 1;
    $status = !$adv ? ($i % 3 === 0 ? 'ventende' : 'faerdig') : ($success ? 'faerdig' : ($i % 50 === 2 ? 'ventende' : 'ikke_valgt'));
    $audio = $status === 'ikke_valgt' ? 'venter' : 'hentet';
    $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
    array_push($params, $adv ? 'adversus' : 'zendesk', 's' . $i, 'inbound', 'Agent ' . ($i % 40), gmdate('Y-m-d H:i:s', 1790000000 + $i * 31536),
        30 + $i % 600, '', $audio, $status, $success ? 1 : 0, $adv ? 7000 + $i : null);
}
DB::exec('INSERT INTO calls (`source`, external_id, direction, agent_name, started_at, duration_sec, recording_url, audio_status, transcript_status, '
    . 'is_success, lead_id, created_at, updated_at) VALUES ' . implode(',', $rows), $params);
for ($k = 1; $k <= 8; $k++) {
    DB::exec('INSERT INTO calls (`source`, external_id, direction, agent_name, started_at, duration_sec, recording_url, audio_status, transcript_status, '
        . 'is_success, lead_id, created_at, updated_at) SELECT `source`, CONCAT(external_id, ?), direction, agent_name, '
        . 'DATE_SUB(started_at, INTERVAL ? MINUTE), duration_sec, recording_url, audio_status, transcript_status, is_success, lead_id, created_at, updated_at FROM calls',
        ['-' . $k, $k * 7]);
}
DB::exec('ANALYZE TABLE calls');
$total = (int) DB::scalar('SELECT COUNT(*) FROM calls');
printf("seed: %d rækker på %.1f s\n", $total, microtime(true) - $t0);
F007Check::that('AK2', $total === 256000, "seed: $total rækker");

/** @return array{0: float, 1: mixed} sekunder og resultat */
$time = static function (callable $fn): array {
    $t = microtime(true);
    $r = $fn();
    return [microtime(true) - $t, $r];
};
/** EXPLAIN af en WHERE fra Calls (via search-SQL'en) */
$explain = static function (string $sql, array $p): string {
    $plan = DB::all('EXPLAIN ' . $sql, $p);
    return implode(' | ', array_map(static fn(array $r): string => ($r['table'] ?? '') . ':' . ($r['type'] ?? '') . ' key=' . ($r['key'] ?? 'NULL')
        . ' rows=' . ($r['rows'] ?? '?') . ' ' . ($r['Extra'] ?? ''), $plan));
};
$where = static function (array $f): array {
    $m = new ReflectionMethod(Calls::class, 'where');
    return $m->invoke(null, $f);
};

$cases = [
    'Zendesk, alle'              => Calls::uiFilter([]),
    'Adversus, succes og valgte' => Calls::uiFilter(['kilde' => 'adversus']),
    'Adversus, alle opkald'      => Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle']),
    'Adversus, alle, Færdige'    => Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'faerdig']),
    'Zendesk, I kø'              => Calls::uiFilter(['status' => 'koe']),
    'Adversus, I kø'             => Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'koe']),
    'Zendesk, periode + agent'   => Calls::uiFilter(['fra' => '2026-11-01', 'til' => '2026-12-31', 'agent' => 'Agent 5']),
    'Adversus, side 200'         => Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'side' => '200']),
];
$okKeys = ['calls_source_started', 'calls_source_status', 'calls_agent', 'calls_source_agent', 'calls_source_success', 'calls_audio_queue'];
foreach ($cases as $name => $f) {
    [$w, $p] = $where($f);
    $planList = $explain('SELECT c.* FROM calls c WHERE ' . $w . ' ORDER BY c.started_at DESC, c.id DESC LIMIT 50', $p);
    $planCount = $explain('SELECT COUNT(*) FROM (SELECT 1 FROM calls c WHERE ' . $w . ' LIMIT ' . (Calls::COUNT_CAP + 1) . ') n', $p);
    [$sec, $res] = $time(static fn(): array => Calls::searchCapped($f));
    printf("%-28s %6.1f ms  antal=%s side=%d  liste: %s  ||  antal: %s\n", $name, $sec * 1000, $res['total'] > Calls::COUNT_CAP ? '10.000+' : $res['total'],
        $res['side'], $planList, $planCount);
    $used = false;
    foreach ($okKeys as $k) {
        $used = $used || str_contains($planList, 'key=' . $k);
    }
    F007Check::that('AK2', $used && !str_contains($planList, 'type=ALL') && !str_contains($planList, ':ALL '), "$name: listen bruger et indeks");
    F007Check::that('AK2', $sec < 1.0, sprintf('%s: liste + antal på %.0f ms (< 1000)', $name, $sec * 1000));
}
[$sec, $n] = $time(static fn(): int => Calls::count(Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle'])));
F007Check::that('AK2', $n === Calls::COUNT_CAP + 1 && $sec < 0.5, sprintf('den anden fanes antal har loft (%d) på %.0f ms', $n, $sec * 1000));
[$sec, $agents] = $time(static fn(): array => Calls::agents('adversus'));
$planAgents = $explain("SELECT DISTINCT c.agent_name FROM calls c WHERE c.agent_name <> '' AND " . Calls::sourceSql('alle') . " AND c.`source` = 'adversus' ORDER BY c.agent_name", []);
printf("agenter (Adversus) %6.1f ms: %s\n", $sec * 1000, $planAgents);
F007Check::that('AK2', count($agents) === 40 && $sec < 0.5 && str_contains($planAgents, 'calls_source_agent'), sprintf('agentlisten pr. fane: %.0f ms, indeks', $sec * 1000));

// Kø-kortet: højst én tælling pr. minut (cache), og selve tællingen måles
UiV2::reset();
[$secQ, $q] = $time(static fn(): array => UiV2::queue());
[$secQ2] = $time(static fn(): array => (static function (): array { UiV2::reset(); return UiV2::queue(); })());
printf("kø-kort: første %.1f ms (tæller, i kø=%d), næste request %.1f ms (cache)\n", $secQ * 1000, $q['status']['waiting'], $secQ2 * 1000);
F007Check::that('AK2', $secQ < 2.0, sprintf('kø-kortets tælling: %.0f ms', $secQ * 1000));

// Uddrag: én forespørgsel for en side, skåret i SQL
$ids = array_map(static fn(array $r): int => (int) $r['id'], Calls::searchCapped(Calls::uiFilter([]))['rows']);
foreach ($ids as $id) {
    DB::exec('INSERT IGNORE INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [$id, str_repeat('Lang tekst. ', 5000), (string) json_encode(array_fill(0, 2000, ['start' => 1.0, 'end' => 2.0, 'text' => 'Hej', 'speaker' => 0, 'role' => 'agent'])), 'turbo', 'da']);
}
[$sec, $x] = $time(static fn(): array => Calls::excerpts($ids));
printf("uddrag for %d opkald: %.1f ms\n", count($x), $sec * 1000);
F007Check::that('AK2', count($x) === 50 && $sec < 0.5, sprintf('uddrag for en side: %.0f ms', $sec * 1000));

DB::exec('DELETE FROM transcripts');
DB::exec('DELETE FROM calls');
F007Check::done();
