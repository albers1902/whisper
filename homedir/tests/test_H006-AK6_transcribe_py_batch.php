<?php
declare(strict_types=1);

// H006-AK6: transcribe.py validerer --batch-max (1–50) og antallet af filer, og afviser relative stier og stier med
// "..". Testen læser scriptet og kører det, hvis /usr/bin/python3 findes (valideringen sker før faster_whisper
// importeres, så pakken kræves ikke). ProcessEngine sender heller aldrig sådanne stier.

require_once __DIR__ . '/h006_lib.php';

use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\WhisperSettings;

$script = dirname(__DIR__) . '/cron/transcribe.py';
$src = (string) file_get_contents($script);

H006Check::that('AK6', str_contains($src, 'p.add_argument("--batch-max", type=int_range(1, 50)'), '--batch-max valideres 1–50');
H006Check::that('AK6', str_contains($src, 'len(a.audio) > a.batch_max'), 'højst --batch-max filer');
H006Check::that('AK6', str_contains($src, 'v.startswith("/") and ".." not in v.split("/")'), 'kun absolutte stier uden ".."');
H006Check::that('AK6', strpos($src, 'p.error("lydstier skal være absolutte') < strpos($src, 'from faster_whisper import'),
    'stierne valideres før faster_whisper importeres');
H006Check::that('AK6', str_contains($src, 'WhisperModel(') && substr_count($src, 'WhisperModel(') === 1
    && strpos($src, 'WhisperModel(') < strpos($src, 'for f in a.audio:'), 'modellen indlæses én gang, før løkken over filerne');
H006Check::that('AK6', str_contains($src, 'flush=True') && str_contains($src, '"load_seconds"') && str_contains($src, '"elapsed"'),
    'JSON Lines flushes pr. fil, elapsed pr. fil og load_seconds til sidst');
H006Check::that('AK6', str_contains($src, 'except Exception') && str_contains($src, '"error": type(e).__name__'),
    'en fejl på én fil giver en error-linje (kun fejltypen) og fortsætter');

$python = '/usr/bin/python3';
if (DIRECTORY_SEPARATOR === '/' && is_executable($python)) {
    $run = static function (array $args) use ($python, $script): array {
        $p = proc_open(array_merge([$python, '-I', $script, '--model', 'turbo', '--models', sys_get_temp_dir()], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [-1, ''];
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    $bad = [
        '--batch-max 0' => ['--batch-max', '0', '--', '/a/1.mp3'],
        '--batch-max 51' => ['--batch-max', '51', '--', '/a/1.mp3'],
        '--batch-max x' => ['--batch-max', 'x', '--', '/a/1.mp3'],
        'flere filer end --batch-max' => ['--batch-max', '2', '--', '/a/1.mp3', '/a/2.mp3', '/a/3.mp3'],
        'relativ sti' => ['--batch-max', '2', '--', 'a/1.mp3'],
        'sti med ..' => ['--batch-max', '2', '--', '/a/../etc/passwd'],
        'samme fil to gange' => ['--batch-max', '2', '--', '/a/1.mp3', '/a/1.mp3'],
        'flere filer uden --batch-max' => ['/a/1.mp3', '/a/2.mp3'],
        'ingen filer' => ['--batch-max', '2', '--'],
    ];
    foreach ($bad as $name => $args) {
        [$code, $out] = $run($args);
        H006Check::that('AK6', $code === 2, 'python afviser ' . $name . ' (exit ' . $code . ')');
    }
    // En gyldig batch kommer forbi valideringen (og fejler først ved import af faster_whisper, som ikke findes her)
    [$code, $out] = $run(['--batch-max', '2', '--', '/a/1.mp3', '/a/2.mp3']);
    H006Check::that('AK6', $code !== 2, 'gyldig batch afvises ikke af argparse (exit ' . $code . ')');
} else {
    echo "info: /usr/bin/python3 findes ikke her — kun scriptets kilde er tjekket\n";
}

// ProcessEngine sender aldrig en ugyldig batch ud (tjekket før proc_open, så motoren behøver ikke findes)
foreach (['/var/rec/1.mp3' => true, 'rec/1.mp3' => false, '/var/../etc/passwd' => false, '/var/rec/..' => false,
    "/var/rec/1.mp3\n--model" => false, '' => false] as $path => $ok) {
    H006Check::that('AK6', ProcessEngine::safePath((string) $path) === $ok, 'safePath(' . json_encode((string) $path) . ') = ' . var_export($ok, true));
}
$pe = new ProcessEngine('turbo', null, new WhisperSettings(2, 1, true, 2));
$noop = static function (string $f, array|EngineError $r, float $e): void {
};
foreach (['tom liste' => [], 'flere end batch' => ['/a/1.mp3', '/a/2.mp3', '/a/3.mp3'], 'relativ' => ['a/1.mp3'],
    'med ..' => ['/a/../b.mp3'], 'dublet' => ['/a/1.mp3', '/a/1.mp3']] as $name => $files) {
    $msg = '';
    try {
        $pe->transcribeBatch($files, 60, $noop);
    } catch (EngineError $e) {
        $msg = $e->getMessage();
    }
    H006Check::that('AK6', in_array($msg, ['ugyldig batch', 'ugyldig lydsti'], true), 'ProcessEngine afviser ' . $name . ': ' . $msg);
}

H006Check::done();
