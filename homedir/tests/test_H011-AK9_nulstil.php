<?php
declare(strict_types=1);

// H011-AK9: `php cron/diarize.php --reset <opkalds-id|fejlet|alle>` nulstiller diar_status, diar_attempts og
// diarized_at, også for færdige, så talerne beregnes igen (fx efter ændrede agent_phrases).

require_once __DIR__ . '/h011_lib.php';

use Whisper\DB;
use Whisper\Transcription\Diarizer;

f002_setup();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
DB::exec("UPDATE transcripts SET diar_status = 'faerdig', diar_attempts = 0, diarized_at = NOW() WHERE call_id IN (?, ?)", [$a['id'], $b['id']]);
DB::exec("UPDATE transcripts SET diar_status = 'fejlet', diar_attempts = 3, diarized_at = NOW() WHERE call_id = ?", [$c['id']]);
$state = static fn(int $id): array => DB::all('SELECT diar_status, diar_attempts, diarized_at FROM transcripts WHERE call_id = ?', [$id])[0];
$clean = static fn(int $id): bool => $state($id)['diar_status'] === null && (int) $state($id)['diar_attempts'] === 0 && $state($id)['diarized_at'] === null;

$run = static function (array $args): array {
    $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/cron/diarize.php', '--reset'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
    $code = is_resource($p) ? proc_close($p) : -1;
    return [$code, $out];
};

[$code, $out] = $run(['fejlet']);
H011Check::that('AK9', $code === 0 && str_contains($out, 'diarize: 1 transcript(s) nulstillet') && $clean($c['id'])
    && $state($a['id'])['diar_status'] === 'faerdig', '--reset fejlet: kun det opgivne nulstilles (' . trim($out) . ')');
[$code, $out] = $run([(string) $a['id']]);
H011Check::that('AK9', $code === 0 && $clean($a['id']) && $state($b['id'])['diar_status'] === 'faerdig', '--reset <id>: også et færdigt opkald');
[$code, $out] = $run(['alle']);
H011Check::that('AK9', $code === 0 && $clean($a['id']) && $clean($b['id']) && $clean($c['id']), '--reset alle');
foreach ([[], ['0'], ['x'], ['1;DROP'], ['-1']] as $bad) {
    [$code] = $run($bad);
    H011Check::that('AK9', $code === 2, '--reset ' . implode(' ', $bad) . ' afvises (exit ' . $code . ')');
}

// Efter nulstilling beregnes talerne igen med de nye markører
$engine = new FakeDiarizer();
$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 12.0, 'speaker' => 1]];
$engine->answers = [$a['file'] => $iv, $b['file'] => $iv, $c['file'] => $iv];
$log = [];
$n = h011_diarizer($engine, $log)->runOnce(3000);
H011Check::that('AK9', $n === 3 && h011_row($a['id'])['status'] === 'faerdig' && h011_row($c['id'])['status'] === 'faerdig',
    'nulstillede opkald får talere igen');
H011Check::that('AK9', Diarizer::reset((string) $b['id']) === 1, 'Diarizer::reset giver antal nulstillede');

H011Check::done();
