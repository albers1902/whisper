<?php
declare(strict_types=1);

// H011-AK1: med en falsk diarization-motor, der returnerer intervaller, får transcriptets segmenter taleren med størst
// tidsoverlap; uden overlap den nærmeste. Talerne nummereres efter, hvem der taler først, og gemmes i segments_json.

require_once __DIR__ . '/h011_lib.php';

use Whisper\Transcription\Diarization;

$seg = static fn(float $s, float $e, string $t = 'x'): array => ['start' => $s, 'end' => $e, 'text' => $t];

// Størst overlap: segment 0–10 overlapper taler 0 i 3 s og taler 1 i 7 s
$r = Diarization::apply([$seg(0, 10)], [['start' => 0.0, 'end' => 3.0, 'speaker' => 0], ['start' => 3.0, 'end' => 10.0, 'speaker' => 1]], []);
H011Check::that('AK1', $r['segments'][0]['speaker'] === 0 && $r['speakers'] === 1,
    'størst overlap vinder, og den eneste taler i transcriptet er taler 0');

// Summen af flere intervaller for samme taler tæller
$r = Diarization::apply([$seg(0, 10)], [['start' => 0.0, 'end' => 3.0, 'speaker' => 1], ['start' => 5.0, 'end' => 7.5, 'speaker' => 1],
    ['start' => 3.0, 'end' => 5.0, 'speaker' => 0], ['start' => 7.5, 'end' => 10.0, 'speaker' => 0]], []);
H011Check::that('AK1', $r['segments'][0]['speaker'] === 0, 'overlap summeres pr. taler (5,5 s mod 4,5 s)');

// Nummerering efter første optræden: motorens taler 1 taler først og bliver 0
$segments = [$seg(0, 4), $seg(4.5, 9), $seg(9.5, 12)];
$intervals = [['start' => 0.0, 'end' => 4.2, 'speaker' => 1], ['start' => 4.3, 'end' => 9.2, 'speaker' => 0],
    ['start' => 9.4, 'end' => 12.0, 'speaker' => 1]];
$r = Diarization::apply($segments, $intervals, []);
H011Check::that('AK1', array_column($r['segments'], 'speaker') === [0, 1, 0] && $r['speakers'] === 2,
    'talerne nummereres efter første optræden: ' . json_encode(array_column($r['segments'], 'speaker')));

// Intet overlap: det nærmeste interval
$r = Diarization::apply([$seg(20, 21)], [['start' => 0.0, 'end' => 5.0, 'speaker' => 0], ['start' => 22.0, 'end' => 30.0, 'speaker' => 1]], []);
H011Check::that('AK1', $r['segments'][0]['speaker'] === 0 && $r['speakers'] === 1, 'uden overlap: det nærmeste interval (1 s væk)');

// Ingen intervaller (ingen tale fundet): ingen taler, segmenterne er uændrede
$r = Diarization::apply($segments, [], []);
H011Check::that('AK1', $r['segments'] === $segments && $r['speakers'] === 0, 'ingen intervaller: segmenterne er uændrede');

// Gamle taler-nøgler erstattes
$r = Diarization::apply([$seg(0, 4) + ['speaker' => 5, 'role' => 'agent']], [['start' => 0.0, 'end' => 4.0, 'speaker' => 1]], []);
H011Check::that('AK1', $r['segments'][0] === ['start' => 0.0, 'end' => 4.0, 'text' => 'x', 'speaker' => 0], 'gamle speaker/role erstattes');

// Ugyldige intervaller afvises
foreach ([null, 'x', [['start' => 0, 'end' => 1]], [['start' => 'a', 'end' => 1, 'speaker' => 0]], [['start' => 0, 'end' => 1, 'speaker' => 99]],
    [['start' => 0, 'end' => 1, 'speaker' => '1']]] as $bad) {
    $ok = false;
    try {
        Diarization::intervals($bad);
    } catch (\Whisper\Transcription\EngineError) {
        $ok = true;
    }
    H011Check::that('AK1', $ok, 'ugyldige intervaller afvises: ' . json_encode($bad));
}

// Hele vejen: Diarizer med falsk motor gemmer talerne i segments_json
f002_setup();
$call = h011_done_call([$seg(0, 4, 'Hej.'), $seg(4.5, 9, 'Goddag.'), $seg(9.5, 12, 'Farvel.')]);
$engine = new FakeDiarizer();
$engine->answers[$call['file']] = $intervals;
$log = [];
$n = h011_diarizer($engine, $log)->runOnce(3000);
$row = h011_row($call['id']);
H011Check::that('AK1', $n === 1 && $engine->calls === [[$call['file']]], 'ét opkald i ét motorkald');
H011Check::that('AK1', array_column($row['segments'], 'speaker') === [0, 1, 0], 'segments_json har talerne: ' . $row['json']);
H011Check::that('AK1', array_column($row['segments'], 'text') === ['Hej.', 'Goddag.', 'Farvel.'], 'teksten er uændret');
H011Check::that('AK1', $row['status'] === 'faerdig' && $row['attempts'] === 0, 'diar_status = faerdig');
H011Check::that('AK1', (string) \Whisper\DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$call['id']]) === 'faerdig',
    'opkaldet er stadig færdigt');
H011Check::that('AK1', in_array('diarize: opkald ' . $call['id'] . ': 2 talere, ingen agent (lyd 2:05, diar 0:02)', $log, true),
    'loglinje med talere og diar-tid: ' . implode(' | ', $log));
H011Check::that('AK1', h011_diarizer($engine, $log)->runOnce(3000) === 0 && count($engine->calls) === 1, 'et færdigt opkald tages ikke igen');

H011Check::done();
