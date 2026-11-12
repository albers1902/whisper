<?php
declare(strict_types=1);

// H011-AK7: diarization kører kun, når transcription-køen er tom, tager nyeste opkald først og stopper inden for ca.
// 5 s, når der kommer et opkald i køen; det igangværende opkald bruger intet forsøg.

require_once __DIR__ . '/h011_lib.php';

use Whisper\Paths;
use Whisper\Transcription\DiarizationSettings;
use Whisper\Transcription\ProcessDiarizer;

$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 12.0, 'speaker' => 1]];

// Køen er ikke tom: intet motorkald
f002_setup();
$old = h011_done_call(h011_segments(), '2026-10-01 10:00:00');
$new = h011_done_call(h011_segments(), '2026-10-09 10:00:00');
$waiting = h011_new_call();
$engine = new FakeDiarizer();
$log = [];
H011Check::that('AK7', h011_diarizer($engine, $log)->runOnce(3000) === 0 && $engine->calls === [], 'et ventende opkald i køen: intet motorkald');
\Whisper\DB::exec("UPDATE calls SET transcript_status = 'transcriberer' WHERE id = ?", [$waiting]);
H011Check::that('AK7', h011_diarizer($engine, $log)->runOnce(3000) === 0 && $engine->calls === [], 'et opkald under transcription: intet motorkald');
\Whisper\DB::exec("UPDATE calls SET transcript_status = 'fejlet' WHERE id = ?", [$waiting]);

// Tom kø: nyeste først
$engine->answers = [$old['file'] => $iv, $new['file'] => $iv];
H011Check::that('AK7', h011_diarizer($engine, $log)->runOnce(3000) === 2 && $engine->calls === [[$new['file'], $old['file']]],
    'tom kø: nyeste opkald først');

// Et opkald kommer midt i batchen: motoren stoppes, det igangværende opkald bruger intet forsøg
f002_setup();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$engine = new FakeDiarizer();
$engine->answers = [$a['file'] => $iv, $b['file'] => $iv, $c['file'] => $iv];
$engine->busyAt = $b['file'];
$log = [];
$d = h011_diarizer($engine, $log);
H011Check::that('AK7', $d->runOnce(3000) === 0 && $d->stopped, 'stoppet: runOnce giver 0, og kørslen slutter');
H011Check::that('AK7', h011_row($a['id'])['status'] === 'faerdig', 'opkaldet før stoppet har talere');
H011Check::that('AK7', h011_row($b['id'])['status'] === null && h011_row($b['id'])['attempts'] === 0
    && h011_row($c['id'])['status'] === null && h011_row($c['id'])['attempts'] === 0, 'det igangværende og de næste: intet forsøg brugt');
H011Check::that('AK7', in_array('diarize: stoppet — nye opkald i transcription-køen', $log, true), 'loglinjen om stoppet');

// Hele vejen gennem ProcessDiarizer: en motor, der hænger, stoppes inden for ca. 5 s, når køen får et opkald (kun Unix)
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $x = h011_done_call(h011_segments());
    $argvFile = $var . '/argv.txt';
    $py = Paths::dir('whisper/venv/bin') . '/python';
    file_put_contents($py, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '" . $argvFile . "'\nexec sleep 60\n");
    chmod($py, 0700);
    Paths::dir('whisper/diarization');
    file_put_contents(DiarizationSettings::marker(), 'x');
    $engine = new ProcessDiarizer(3);
    H011Check::that('AK7', $engine->available(), 'ProcessDiarizer: klar med markør, modelmappe og python');
    $calls = 0;
    $t0 = microtime(true);
    $stopped = $engine->diarize([$x['file']], 600, static function (): void {
    }, static function () use (&$calls): bool {
        return ++$calls >= 2;   // køen får et opkald ved andet tjek
    });
    $took = microtime(true) - $t0;
    H011Check::that('AK7', $stopped && $took < 2 * ProcessDiarizer::STOP_CHECK + 3, sprintf('motoren stoppet efter %.1f s', $took));
    $argv = is_file($argvFile) ? explode("\n", trim((string) file_get_contents($argvFile))) : [];
    H011Check::that('AK7', array_slice($argv, -6) === [ '--models', DiarizationSettings::modelDir(), '--threads', '3', '--', $x['file']],
        'argv: --models <var/whisper/diarization> --threads 3 -- <fil>: ' . implode(' ', $argv));
}

H011Check::done();
