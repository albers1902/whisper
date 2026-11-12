<?php
declare(strict_types=1);

// H010-AK1: uden engine_mode (eller med engine_mode = sequential) kaldes motoren præcis som før H010: intet --mode og
// intet --batched-size i argv, og motorens JSON læses uændret (falsk python, som H005-AK1).

require_once __DIR__ . '/h010_lib.php';

use Whisper\Config;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$var = f002_setup();
H010Check::that('AK1', array_diff_key(Config::section('whisper'), ['min_seconds' => 1, 'max_age_days' => 1]) === [],
    'testens config har ingen motor-nøgler i [whisper] (kun kø-reglerne fra H012, slået fra)');

foreach ([[], ['engine_mode' => 'sequential'], ['engine_mode' => ' Sequential ', 'batched_size' => '8']] as $section) {
    $w = [];
    $s = WhisperSettings::fromSection($section + ['threads' => '3', 'workers' => '2'], 6, h010_collect($w));
    H010Check::that('AK1', $s->mode === WhisperSettings::MODE_SEQUENTIAL && $w === []
        && $s->argv() === ['--threads', '3', '--beam', '1', '--vad', '1'],
        'sequential (' . json_encode($section) . '): argv som før H010: ' . implode(' ', $s->argv()));
}
H010Check::that('AK1', WhisperSettings::fromSection(['vad' => 'off', 'threads' => '3'], 6)->argv() === ['--threads', '3', '--beam', '1', '--vad', '0'],
    'vad off er stadig muligt i sequential');

if (DIRECTORY_SEPARATOR === '/') {
    $argvFile = $var . '/argv.txt';
    h005_fake_python($var, $argvFile);
    $audio = f002_audio(f002_insert(['started_at' => '2026-10-07 08:00:00']));
    $res = ProcessEngine::fromConfig()->transcribe($audio, 10);
    $argv = h005_argv($argvFile);
    H010Check::that('AK1', !in_array('--mode', $argv, true) && !in_array('--batched-size', $argv, true),
        'motoren får intet --mode/--batched-size: ' . implode(' ', $argv));
    H010Check::that('AK1', $res === ['text' => 'ok', 'segments' => [['start' => 0, 'end' => 1, 'text' => 'ok']], 'duration' => 1,
        'model' => 'turbo', 'language' => 'da'], 'motorens JSON er uændret');
    H010Check::that('AK1', end($argv) === $audio, 'lydfilen er stadig sidst');
}

// transcribe.py: sequential-grenen kalder faster-whisper som før H010
$src = (string) file_get_contents(dirname(__DIR__) . '/cron/transcribe.py');
H010Check::that('AK1', str_contains($src, 'model.transcribe(audio, language="da", beam_size=a.beam, vad_filter=a.vad == "1")'),
    'sequential: samme transcribe-kald som før');
H010Check::that('AK1', str_contains($src, 'p.add_argument("--mode", choices=["sequential", "batched"], default="sequential")'),
    '--mode er som standard sequential');

H010Check::done();
