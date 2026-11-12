<?php
declare(strict_types=1);

// H011-AK4: med flaget slukket eller [diarization] enabled = off kører diarization ikke, og transcripts er uændrede.
// Transcription kalder aldrig diarization (transcribe.py og ProcessEngine kender den ikke), så dens uddata er som før.

require_once __DIR__ . '/h011_lib.php';

use Whisper\Transcription\DiarizationSettings;
use Whisper\Transcription\Transcriber;

// Config og flag
$warn = [];
$w = static function (string $l) use (&$warn): void {
    $warn[] = $l;
};
H011Check::that('AK4', DiarizationSettings::fromSection([], true)->enabled, 'flag on, intet i config: slået til');
H011Check::that('AK4', !DiarizationSettings::fromSection([], false)->enabled, 'flag off: slået fra');
H011Check::that('AK4', !DiarizationSettings::fromSection(['enabled' => 'off'], true)->enabled, 'enabled = off: slået fra trods flaget');
H011Check::that('AK4', !DiarizationSettings::fromSection(['enabled' => 'on'], false)->enabled, 'enabled = on tænder ikke et slukket flag');
$s = DiarizationSettings::fromSection(['enabled' => 'måske', 'threads' => '99'], true, 3, $w);
H011Check::that('AK4', $s->enabled && $s->threads === 3 && count($warn) === 2, 'ugyldige værdier: standarden og én advarsel pr. nøgle');
H011Check::that('AK4', DiarizationSettings::fromSection(['threads' => '2'], true, 3)->threads === 2, 'threads fra config');

$features = (string) file_get_contents(dirname(__DIR__) . '/config/features.ini');
H011Check::that('AK4', preg_match('/\[H011_diarization\]\n; ejer: H011[^\n]*\ndev = on\ntest = off\nstable = off/', $features) === 1,
    'flaget H011_diarization: dev on, test og stable off');

// Transcription kender ikke diarization
$py = (string) file_get_contents(dirname(__DIR__) . '/cron/transcribe.py');
$pe = (string) file_get_contents(dirname(__DIR__) . '/src/Transcription/ProcessEngine.php');
H011Check::that('AK4', !str_contains($py, 'diariz') && !str_contains($pe, 'diariz') && !str_contains($pe, 'Diariz'),
    'transcribe.py og ProcessEngine sender ingen diarization-argumenter');

// Med diarization slået fra: cron/diarize.php gør intet (egen proces, testens var/, kun F001/F002 tændt)
$var = f002_setup();
$call = h011_done_call(h011_segments());
$before = h011_row($call['id']);
$run = static function (string $flags) use ($var): array {
    putenv('WHISPER_VAR_DIR=' . $var);
    putenv('WHISPER_TEST_FLAGS=' . $flags);
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/cron/diarize.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
    $code = is_resource($p) ? proc_close($p) : -1;
    putenv('WHISPER_TEST_FLAGS=');
    return [$code, $out];
};
[$code, $out] = $run('F001_brugere,F002_zendesk');
H011Check::that('AK4', $code === 0 && trim($out) === '', 'flaget slukket: diarize.php afslutter uden output (exit ' . $code . ')');
H011Check::that('AK4', !is_file($var . '/locks/diarize.lock'), 'flaget slukket: ingen lås taget');
H011Check::that('AK4', h011_row($call['id']) === $before, 'flaget slukket: transcriptet er uændret');
[$code, $out] = $run('F001_brugere,F002_zendesk,H011_diarization');
H011Check::that('AK4', $code === 0 && h011_row($call['id']) === $before, 'flaget tændt, men ikke sat op: intet ændret (exit ' . $code . ')');

// Diarizer med en motor, der ikke er klar: ingen kald
$engine = new FakeDiarizer();
$engine->ready = false;
$log = [];
H011Check::that('AK4', h011_diarizer($engine, $log)->runOnce(3000) === 0 && $engine->calls === [] && h011_row($call['id']) === $before,
    'motor ikke klar: intet kald, intet ændret');

// Transcription: uddata som før, og en ny transcription nulstiller diarization-bogføringen
\Whisper\DB::exec("UPDATE transcripts SET diar_status = 'faerdig', diar_attempts = 2 WHERE call_id = ?", [$call['id']]);
\Whisper\DB::exec("UPDATE calls SET transcript_status = 'ventende', transcript_attempts = 0 WHERE id = ?", [$call['id']]);
$t = new Transcriber(new FakeEngine(), null, static function (string $l): void {
});
$t->runOnce();
$row = h011_row($call['id']);
H011Check::that('AK4', $row['segments'] === (new FakeEngine())->result['segments'] && !str_contains($row['json'], 'speaker'),
    'transcription gemmer segmenterne som før, uden taler');
H011Check::that('AK4', $row['status'] === null && $row['attempts'] === 0, 'en ny transcription nulstiller diar_status og diar_attempts');

H011Check::done();
