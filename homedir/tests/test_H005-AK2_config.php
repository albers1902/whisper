<?php
declare(strict_types=1);

// H005-AK2: [whisper] threads, beam_size og vad i config.ini overholdes; ugyldige værdier giver standarden og én
// advarsel pr. nøgle. Antal kerner findes uden shell (/proc/cpuinfo, ellers /sys/devices/system/cpu/online).

require_once __DIR__ . '/h005_lib.php';

use Whisper\Config;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$collect = static function (array &$into): Closure {
    return static function (string $w) use (&$into): void {
        $into[] = $w;
    };
};

// Gyldige værdier overholdes
$w = [];
$s = WhisperSettings::fromSection(['threads' => '3', 'beam_size' => '5', 'vad' => 'off'], 6, $collect($w));
H005Check::that('AK2', $s->threads === 3 && $s->beam === 5 && $s->vad === false && $w === [], 'threads 3, beam 5, vad off overholdes');
$s = WhisperSettings::fromSection(['threads' => ' 32 ', 'beam_size' => '10', 'vad' => 'ON'], 6, $collect($w));
H005Check::that('AK2', $s->threads === 32 && $s->beam === 10 && $s->vad === true && $w === [], 'grænserne 32 og 10, ON med store bogstaver');
$s = WhisperSettings::fromSection(['threads' => '4', 'beam_size' => '5', 'vad' => 'off'], 6, $collect($w));
H005Check::that('AK2', $s->argv() === ['--threads', '4', '--beam', '5', '--vad', '0'] && $s->label() === 'threads 4, beam 5, vad off',
    'den gamle adfærd kan sættes i config (rollback uden deploy)');
$s = WhisperSettings::fromSection(['threads' => '', 'beam_size' => '', 'vad' => ''], 6, $collect($w));
H005Check::that('AK2', $s->threads === 5 && $s->beam === 1 && $s->vad === true && $w === [], 'tomme nøgler = standarden uden advarsel');

// Ugyldige værdier: standarden og én advarsel pr. nøgle
$w = [];
$s = WhisperSettings::fromSection(['threads' => '0', 'beam_size' => '99', 'vad' => 'måske'], 6, $collect($w));
H005Check::that('AK2', $s->threads === 5 && $s->beam === 1 && $s->vad === true, 'threads = 0, beam_size = 99, vad = måske → standarderne');
H005Check::that('AK2', count($w) === 3, 'præcis tre advarsler: ' . implode(' | ', $w));
H005Check::that('AK2', count(array_filter($w, static fn(string $l): bool => str_contains($l, '[whisper] threads'))) === 1
    && count(array_filter($w, static fn(string $l): bool => str_contains($l, '[whisper] beam_size'))) === 1
    && count(array_filter($w, static fn(string $l): bool => str_contains($l, '[whisper] vad'))) === 1, 'én advarsel pr. nøgle');
H005Check::that('AK2', !str_contains(implode('', $w), 'måske') && !str_contains(implode('', $w), '99'),
    'advarslen gentager ikke den ugyldige værdi');
foreach (['33', '-1', '4; rm -rf /', '1e2', '2.5', '０', 'abc'] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['threads' => $bad, 'beam_size' => $bad], 6, $collect($w));
    H005Check::that('AK2', $s->threads === 5 && $s->beam === 1 && count($w) === 2, 'ugyldig værdi "' . $bad . '" → standarden og advarsel');
}

// Konstruktøren afviser alt uden for intervallerne (værdierne bliver argv-elementer)
foreach ([[0, 1], [33, 1], [1, 0], [1, 11]] as [$t, $b]) {
    $ok = false;
    try {
        new WhisperSettings($t, $b, true);
    } catch (EngineError) {
        $ok = true;
    }
    H005Check::that('AK2', $ok, "WhisperSettings($t, $b) afvises");
}

// Antal kerner uden shell
$tmp = sys_get_temp_dir() . '/whisper-h005-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
file_put_contents($tmp . '/cpuinfo', "processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\n\nprocessor\t: 2\n\nprocessor\t: 3\n\nprocessor\t: 4\n\nprocessor\t: 5\n");
file_put_contents($tmp . '/online', "0-3,6,8-9\n");
H005Check::that('AK2', WhisperSettings::cores($tmp . '/cpuinfo', $tmp . '/online') === 6, '/proc/cpuinfo med 6 processorer → 6');
H005Check::that('AK2', WhisperSettings::cores($tmp . '/mangler', $tmp . '/online') === 7, 'online "0-3,6,8-9" → 7');
H005Check::that('AK2', WhisperSettings::cores($tmp . '/mangler', $tmp . '/mangler2') === null, 'ingen af dem → ukendt');
$src = (string) file_get_contents(__DIR__ . '/../src/Transcription/WhisperSettings.php');
H005Check::that('AK2', !preg_match('/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/', $src), 'kernerne findes uden proces');

// Hele vejen gennem config.ini (fromConfig)
mkdir($tmp . '/config', 0700, true);
file_put_contents($tmp . '/config/config.ini', "[whisper]\nmodel = turbo\nthreads = 2\nbeam_size = 99\nvad = off\n");
Config::setBaseDir($tmp);
$w = [];
$s = ProcessEngine::fromConfig($collect($w))->settings();
H005Check::that('AK2', $s->threads === 2 && $s->beam === 1 && $s->vad === false && count($w) === 1 && str_contains($w[0], 'beam_size'),
    'fromConfig: threads 2 og vad off overholdes, beam_size 99 → 1 med én advarsel');

H005Check::done();
