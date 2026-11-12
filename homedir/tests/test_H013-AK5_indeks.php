<?php
declare(strict_types=1);

// H013-AK5: skalering til flere hundrede tusinde opkald: migration 0009 giver indeks til listen "Alle opkald"
// (source + started_at), lydkøen (source, transcript_status, audio_status) og lead-status, og lydkøens forespørgsel kan
// bruge sit indeks. Med 5000 ikke-valgte opkald henter en kørsel ingen lyd og laver ingen kald efter lyd.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h013_lib.php';

use Whisper\DB;

f004_setup();
$keys = static function (string $table): array {
    return array_values(array_unique(array_column(DB::all('SHOW INDEX FROM ' . $table), 'Key_name')));
};
H013Check::that('AK5', in_array('calls_source_started', $keys('calls'), true) && in_array('calls_audio_queue', $keys('calls'), true),
    'calls har calls_source_started og calls_audio_queue');
H013Check::that('AK5', in_array('adversus_leads_status', $keys('adversus_leads'), true), 'adversus_leads har indeks på status');
$cols = array_column(DB::all('SHOW COLUMNS FROM calls'), 'Field');
H013Check::that('AK5', in_array('disposition', $cols, true), 'calls har disposition');

// 5000 ikke-valgte Adversus-opkald (som CDR'er uden succes)
for ($chunk = 0; $chunk < 5000; $chunk += 500) {
    $vals = [];
    $params = [];
    for ($i = $chunk; $i < $chunk + 500; $i++) {
        $vals[] = "('adversus', ?, 9, 0, 'outbound', 'answered', '', '', ?, 60, '', 'ikke_valgt', NOW(), NOW())";
        array_push($params, (string) (600000 + $i), gmdate('Y-m-d H:i:s', f004_since() + $i));
    }
    DB::exec('INSERT INTO calls (`source`, external_id, lead_id, is_success, direction, disposition, customer_phone, agent_name, started_at, '
        . 'duration_sec, recording_url, transcript_status, created_at, updated_at) VALUES ' . implode(', ', $vals), $params);
}
DB::all('ANALYZE TABLE calls');
$plan = DB::all("EXPLAIN SELECT id FROM calls WHERE `source` = 'adversus' AND transcript_status IN ('ventende', 'transcriberer', 'faerdig', 'fejlet') "
    . "AND (audio_status = 'venter' OR (audio_status = 'fejlet' AND audio_attempts < 5)) ORDER BY is_success DESC, started_at DESC, id DESC LIMIT 25");
$possible = (string) ($plan[0]['possible_keys'] ?? '');
echo 'EXPLAIN: key=', (string) ($plan[0]['key'] ?? ''), ' rows=', (string) ($plan[0]['rows'] ?? ''), ' possible=', $possible, "\n";
H013Check::that('AK5', str_contains($possible, 'calls_audio_queue'), 'lydkøens forespørgsel kan bruge calls_audio_queue');
H013Check::that('AK5', (int) ($plan[0]['rows'] ?? 99999) < 5000, 'lydkøen læser ikke alle ikke-valgte rækker (rows ' . (string) ($plan[0]['rows'] ?? '?') . ')');

$http = new FakeAdversus();
['sync' => $sync] = f004_sync(f004_cfg(), $http, f004_since() + 3 * 3600);
$r = $sync->run();
H013Check::that('AK5', $r['queued'] === 0 && array_filter($http->requests, static fn(array $q): bool => str_contains($q['url'], '/recording')) === [],
    'ingen lyd hentet til ikke-valgte opkald');
H013Check::done();
