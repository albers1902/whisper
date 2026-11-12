<?php
declare(strict_types=1);

// H011-AK8: talerne gemmes kun, hvis transcriptet er uændret siden valget (samme created_at). Er opkaldet transcriberet
// igen imens, kasseres resultatet, det nye transcript røres ikke, og det får talere ved en senere kørsel.

require_once __DIR__ . '/h011_lib.php';

use Whisper\DB;

f002_setup();
$call = h011_done_call(h011_segments());
DB::exec('UPDATE transcripts SET created_at = ? WHERE call_id = ?', ['2026-10-05 10:00:00', $call['id']]);
$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 12.0, 'speaker' => 1]];
$newSegments = [['start' => 0.0, 'end' => 12.0, 'text' => 'Et helt nyt transcript.']];

$engine = new FakeDiarizer();
$engine->answers = [$call['file'] => $iv];
// Mens diarization kører, transcriberes opkaldet igen (Transcriber::finish skriver nyt transcript og nulstiller bogføringen)
$engine->before = static function (string $file) use ($call, $newSegments): void {
    DB::exec('UPDATE transcripts SET segments_json = ?, created_at = ?, diar_status = NULL, diar_attempts = 0 WHERE call_id = ?',
        [(string) json_encode($newSegments), '2026-10-05 11:00:00', $call['id']]);
};
$log = [];
h011_diarizer($engine, $log)->runOnce(3000);
$row = h011_row($call['id']);
H011Check::that('AK8', $row['segments'] === $newSegments && $row['status'] === null && $row['attempts'] === 0,
    'det nye transcript er urørt, uden talere og uden brugt forsøg: ' . $row['json']);
H011Check::that('AK8', in_array('diarize: opkald ' . $call['id'] . ': transcriptet er lavet igen imens — tages forfra senere', $log, true),
    'loglinjen siger, at resultatet er kasseret');

// Næste kørsel tager det nye transcript
$engine->before = null;
h011_diarizer($engine, $log)->runOnce(3000);
$row = h011_row($call['id']);
H011Check::that('AK8', $row['status'] === 'faerdig' && array_column($row['segments'], 'speaker') === [0]
    && $row['segments'][0]['text'] === 'Et helt nyt transcript.', 'næste kørsel lægger talere på det nye transcript');

H011Check::done();
