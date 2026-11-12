<?php
declare(strict_types=1);

// H011-AK7: diarization kører kun, når transcription-køen er tom, tager nyeste opkald først og stopper inden for ca.
// 5 s, når der kommer et opkald i køen eller en udrulning starter; det igangværende opkald bruger intet forsøg, og
// loglinjen har den målte stoptid.

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
H011Check::that('AK7', $d->run(3000) === 0 && $d->stopped && count($engine->calls) === 1, 'stoppet: kørslen slutter efter én batch');
H011Check::that('AK7', h011_row($a['id'])['status'] === 'faerdig', 'opkaldet før stoppet har talere');
H011Check::that('AK7', h011_row($b['id'])['status'] === null && h011_row($b['id'])['attempts'] === 0
    && h011_row($c['id'])['status'] === null && h011_row($c['id'])['attempts'] === 0, 'det igangværende og de næste: intet forsøg brugt');
H011Check::that('AK7', in_array('diarize: stoppet — nye opkald i transcription-køen (motoren stoppet på 0,1 s, køen tjekkes hvert 5. s)', $log, true),
    'loglinjen om stoppet med målt stoptid: ' . implode(' | ', $log));

// En udrulning starter: også stop
f002_setup();
$x = h011_done_call(h011_segments());
$engine = new FakeDiarizer();
$engine->busyAt = '*';
$log = [];
$d = h011_diarizer($engine, $log, null, static fn(): bool => count($engine->calls) > 0);
H011Check::that('AK7', $d->runOnce(3000) === 0 && $d->stopped && h011_row($x['id'])['attempts'] === 0
    && str_starts_with((string) end($log), 'diarize: stoppet — udrulning'), 'udrulning: motoren stoppes, intet forsøg');
$engine = new FakeDiarizer();
H011Check::that('AK7', h011_diarizer($engine, $log, null, static fn(): bool => true)->run(3000) === 0 && $engine->calls === [],
    'udrulning i gang: kørslen starter ikke motoren');

// Hele vejen gennem ProcessDiarizer: en motor, der hænger, stoppes ved første køtjek (kun Unix). Fejlloggen bevares
// (append) og er 0600, også når den fandtes før
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $x = h011_done_call(h011_segments());
    $argvFile = $var . '/argv.txt';
    $py = Paths::dir('whisper/venv/bin') . '/python';
    file_put_contents($py, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '" . $argvFile . "'\necho 'fra motoren' >&2\nexec sleep 60\n");
    chmod($py, 0700);
    Paths::dir('whisper/diarization');
    file_put_contents(DiarizationSettings::marker(), 'x');
    file_put_contents(ProcessDiarizer::errorFile(), "gammel fejl\n");
    chmod(ProcessDiarizer::errorFile(), 0644);
    $engine = new ProcessDiarizer(3);
    H011Check::that('AK7', $engine->available(), 'ProcessDiarizer: klar med markør, modelmappe og python');
    $t0 = microtime(true);
    $took = $engine->diarize([$x['file']], 600, static function (): void {
    }, static fn(): bool => true);
    $all = microtime(true) - $t0;
    H011Check::that('AK7', $took !== null && $took < ProcessDiarizer::KILL_WAIT + 0.5 && $all < ProcessDiarizer::STOP_CHECK + 3,
        sprintf('motoren stoppet ved første køtjek: %.1f s i alt, %.1f s fra beslutning til død', $all, (float) $took));
    $argv = is_file($argvFile) ? explode("\n", trim((string) file_get_contents($argvFile))) : [];
    H011Check::that('AK7', array_slice($argv, -6) === ['--models', DiarizationSettings::modelDir(), '--threads', '3', '--', $x['file']],
        'argv: --models <var/whisper/diarization> --threads 3 -- <fil>: ' . implode(' ', $argv));
    clearstatcache();
    $err = (string) file_get_contents(ProcessDiarizer::errorFile());
    H011Check::that('AK7', str_starts_with($err, "gammel fejl\n") && str_contains($err, 'diarize.py startet med 1 fil(er)')
        && str_contains($err, 'fra motoren') && (fileperms(ProcessDiarizer::errorFile()) & 0777) === 0600,
        'fejlloggen: append, kørselslinje, motorens stderr, 0600');
}

H011Check::done();
