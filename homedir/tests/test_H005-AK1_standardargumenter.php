<?php
declare(strict_types=1);

// H005-AK1: uden nye nøgler i config kaldes motoren med --threads <kerner−1>, --beam 1 og --vad 1 som argv-elementer
// (falsk python, som i F002-AK4). Fejlen: motoren blev kaldt uden dem, så faster-whisper brugte 4 tråde, beam 5 og
// ingen VAD. Siden H007 er standarden workers = 2, så standard threads er kernerne delt mellem to (H007-AK3);
// kerner − 1 gælder ved workers = 1.

require_once __DIR__ . '/h005_lib.php';

use Whisper\Config;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$var = f002_setup();
H005Check::that('AK1', Config::section('whisper') === [], 'testens config har ingen [whisper]-nøgler');

$cores = WhisperSettings::cores();
$want = WhisperSettings::defaultThreads($cores, WhisperSettings::DEFAULT_WORKERS);
H005Check::that('AK1', $cores === null ? $want === 4 : $want === max(1, min(32, intdiv($cores, 2))),
    'standard threads = kerner / 2 (workers 2), mindst 1 (kerner: ' . var_export($cores, true) . ', threads: ' . $want . ')');
H005Check::that('AK1', WhisperSettings::defaultThreads(6) === 5 && WhisperSettings::defaultThreads(1) === 1
    && WhisperSettings::defaultThreads(64) === 32 && WhisperSettings::defaultThreads(null) === 4,
    'threads: 6 kerner → 5, 1 kerne → 1, 64 kerner → 32, ukendt → 4');

$warnings = [];
$engine = ProcessEngine::fromConfig(static function (string $w) use (&$warnings): void {
    $warnings[] = $w;
});
$s = $engine->settings();
H005Check::that('AK1', $s->threads === $want && $s->beam === 1 && $s->vad === true && $warnings === [],
    'fromConfig uden nøgler: threads ' . $s->threads . ', beam ' . $s->beam . ', vad ' . ($s->vad ? 'on' : 'off') . ', ingen advarsel');
H005Check::that('AK1', $s->argv() === ['--threads', (string) $want, '--beam', '1', '--vad', '1'], 'argv-elementerne');
H005Check::that('AK1', $engine->label() === 'threads ' . $want . ', beam 1, vad on, mode sequential', 'label til loglinjen (mode fra H010)');

if (DIRECTORY_SEPARATOR === '/') {
    $argvFile = $var . '/argv.txt';
    h005_fake_python($var, $argvFile);
    $audio = f002_audio(f002_insert(['started_at' => '2026-10-07 08:00:00']));
    $pe = ProcessEngine::fromConfig();
    H005Check::that('AK1', $pe->available(), 'falsk motor er klar');
    $res = $pe->transcribe($audio, 10);
    $argv = h005_argv($argvFile);
    H005Check::that('AK1', ($res['text'] ?? '') === 'ok', 'motorens JSON læses');
    H005Check::that('AK1', h005_opt($argv, '--threads') === (string) $want, 'motoren får --threads ' . $want . ': ' . implode(' ', $argv));
    H005Check::that('AK1', h005_opt($argv, '--beam') === '1', 'motoren får --beam 1');
    H005Check::that('AK1', h005_opt($argv, '--vad') === '1', 'motoren får --vad 1');
    H005Check::that('AK1', h005_opt($argv, '--model') === 'turbo' && end($argv) === $audio,
        'model og lydfil er uændrede, lydfilen er sidst');
    H005Check::that('AK1', ($argv[0] ?? '') === Config::baseDir() . '/cron/transcribe.py', 'første argument er scriptet');
}

$src = (string) file_get_contents(__DIR__ . '/../src/Transcription/ProcessEngine.php');
H005Check::that('AK1', str_contains($src, 'proc_open($argv') && !preg_match('/\b(shell_exec|exec|system|passthru|popen)\s*\(/', $src)
    && str_contains($src, "'/usr/bin/nice', '-n', '19'") && str_contains($src, "'/usr/bin/ionice', '-c3'"),
    'stadig proc_open med argv-array, nice og ionice uændrede');

H005Check::done();
