<?php
declare(strict_types=1);

// H010-AK2: engine_mode = batched giver --mode batched --batched-size N i argv (standard 4; 8 når batched_size = 8),
// både for ét opkald og for en batch (falsk python).

require_once __DIR__ . '/h010_lib.php';

use Whisper\Config;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$var = f002_setup();

$w = [];
$s = WhisperSettings::fromSection(['engine_mode' => 'batched', 'threads' => '3'], 6, h010_collect($w));
H010Check::that('AK2', $s->mode === 'batched' && $s->batchedSize === 4 && $w === []
    && $s->argv() === ['--threads', '3', '--beam', '1', '--vad', '1', '--mode', 'batched', '--batched-size', '4'],
    'batched uden batched_size: --batched-size 4: ' . implode(' ', $s->argv()));
$s = WhisperSettings::fromSection(['engine_mode' => 'BATCHED', 'batched_size' => '8', 'threads' => '3'], 6, h010_collect($w));
H010Check::that('AK2', $s->argv() === ['--threads', '3', '--beam', '1', '--vad', '1', '--mode', 'batched', '--batched-size', '8']
    && $w === [], 'batched_size = 8: --mode batched --batched-size 8');
H010Check::that('AK2', WhisperSettings::fromSection(['engine_mode' => 'batched', 'batched_size' => '32'], 6)->batchedSize === 32
    && WhisperSettings::fromSection(['engine_mode' => 'batched', 'batched_size' => '1'], 6)->batchedSize === 1, 'grænserne 1 og 32');

if (DIRECTORY_SEPARATOR === '/') {
    $tmp = $var . '/cfg';
    mkdir($tmp . '/config', 0700, true);
    file_put_contents($tmp . '/config/config.ini', "[whisper]\nmodel = turbo\nengine_mode = batched\nbatched_size = 8\n");
    $base = Config::baseDir();
    Config::setBaseDir($tmp);
    $settings = ProcessEngine::fromConfig()->settings();
    Config::setBaseDir($base);
    $pe = new ProcessEngine('turbo', null, $settings);
    $argvFile = $var . '/argv.txt';
    h005_fake_python($var, $argvFile);
    $audio = f002_audio(f002_insert(['started_at' => '2026-10-07 08:00:00']));
    $res = $pe->transcribe($audio, 10);
    $argv = h005_argv($argvFile);
    H010Check::that('AK2', h005_opt($argv, '--mode') === 'batched' && h005_opt($argv, '--batched-size') === '8'
        && h005_opt($argv, '--vad') === '1', 'ét opkald: motoren får --mode batched --batched-size 8: ' . implode(' ', $argv));
    H010Check::that('AK2', ($res['text'] ?? '') === 'ok' && end($argv) === $audio, 'svaret læses, lydfilen er sidst');

    @unlink($argvFile);
    $pe->transcribeBatch([$audio], 60, static function (): void {
    });
    $argv = h005_argv($argvFile);
    $mode = array_search('--mode', $argv, true);
    $sep = array_search('--', $argv, true);
    H010Check::that('AK2', is_int($mode) && is_int($sep) && $mode < $sep && h005_opt($argv, '--batched-size') === '8'
        && h005_opt($argv, '--batch-max') === '10', 'batch: --mode batched står før "--" og filerne: ' . implode(' ', $argv));
}

H010Check::done();
