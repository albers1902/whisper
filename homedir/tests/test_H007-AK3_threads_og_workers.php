<?php
declare(strict_types=1);

// H007-AK3: standard-threads er 3 ved 6 kerner og workers 2, og 5 ved workers 1. En eksplicit threads vinder. En
// ugyldig workers (0, 9, "to") giver 2 og én advarsel.

require_once __DIR__ . '/h007_lib.php';

use Whisper\Config;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$collect = static function (array &$into): Closure {
    return static function (string $w) use (&$into): void {
        $into[] = $w;
    };
};

$w = [];
$s = WhisperSettings::fromSection([], 6, $collect($w));
H007Check::that('AK3', $s->workers === 2 && $s->threads === 3 && $w === [], 'uden nøgler, 6 kerner: workers 2, threads 3, ingen advarsel');
$s = WhisperSettings::fromSection(['workers' => '1'], 6, $collect($w));
H007Check::that('AK3', $s->workers === 1 && $s->threads === 5 && $w === [], 'workers 1, 6 kerner: threads 5 (som H006)');
$s = WhisperSettings::fromSection(['workers' => '2', 'threads' => '4'], 6, $collect($w));
H007Check::that('AK3', $s->workers === 2 && $s->threads === 4 && $w === [], 'eksplicit threads 4 vinder over standarden');
$s = WhisperSettings::fromSection(['workers' => ' 4 '], 6, $collect($w));
H007Check::that('AK3', $s->workers === 4 && $s->threads === 1 && $w === [], 'workers 4 (grænsen), 6 kerner: threads 1');
H007Check::that('AK3', WhisperSettings::defaultThreads(6, 2) === 3 && WhisperSettings::defaultThreads(6, 1) === 5
    && WhisperSettings::defaultThreads(1, 2) === 1 && WhisperSettings::defaultThreads(7, 2) === 3
    && WhisperSettings::defaultThreads(null, 2) === 4 && WhisperSettings::defaultThreads(128, 2) === 32,
    'defaultThreads: 6/2 → 3, 6/1 → 5, 1/2 → 1, 7/2 → 3, ukendt → 4, højst 32');

foreach (['0', '9', 'to', '5', '-1', '2.5', ''] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['workers' => $bad], 6, $collect($w));
    if ($bad === '') {
        H007Check::that('AK3', $s->workers === 2 && $w === [], 'tom workers = standarden uden advarsel');
        continue;
    }
    H007Check::that('AK3', $s->workers === 2 && $s->threads === 3 && count($w) === 1
        && str_contains($w[0], '[whisper] workers skal være 1–4 — bruger 2') && !str_contains($w[0], '"'),
        'ugyldig workers "' . $bad . '" → 2 og én advarsel: ' . implode(' | ', $w));
}

foreach ([0, 5] as $bad) {
    $ok = false;
    try {
        new WhisperSettings(3, 1, true, 10, $bad);
    } catch (EngineError) {
        $ok = true;
    }
    H007Check::that('AK3', $ok, "WhisperSettings med workers $bad afvises");
}

// Hele vejen gennem config.ini: motoren får de delte tråde, og slot 2 har sin egen fejlfil
$var = f002_setup();
$tmp = sys_get_temp_dir() . '/whisper-h007-' . bin2hex(random_bytes(6));
mkdir($tmp . '/config', 0700, true);
file_put_contents($tmp . '/config/config.ini', "[whisper]\nmodel = turbo\nworkers = to\n");
$base = Config::baseDir();
Config::setBaseDir($tmp);
$w = [];
$s = WhisperSettings::fromSection(Config::section('whisper'), 6, $collect($w));
$pe = ProcessEngine::fromSettings($s, 2);
H007Check::that('AK3', $pe->settings()->workers === 2 && $pe->settings()->threads === 3 && count($w) === 1,
    'config.ini workers = to: 2, threads 3, én advarsel');
H007Check::that('AK3', $pe->settings()->argv() === ['--threads', '3', '--beam', '1', '--vad', '1'], 'motorens argv: --threads 3');
H007Check::that('AK3', $pe->errorFile() === $var . '/whisper/sidste-fejl-2.log'
    && ProcessEngine::fromSettings($s, 1)->errorFile() === $var . '/whisper/sidste-fejl.log',
    'slot 1 skriver stderr i sidste-fejl.log, slot 2 i sidste-fejl-2.log');
Config::setBaseDir($base);

H007Check::done();
