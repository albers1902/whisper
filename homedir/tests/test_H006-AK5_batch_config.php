<?php
declare(strict_types=1);

// H006-AK5: [whisper] batch = 1 giver ét opkald pr. motorkald (den gamle vej, rollback via config). Ugyldig batch
// giver standarden (10) og én advarsel, samme mønster som H005.

require_once __DIR__ . '/h006_lib.php';

use Whisper\Config;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;
use Whisper\Transcription\WhisperSettings;

$collect = static function (array &$into): Closure {
    return static function (string $w) use (&$into): void {
        $into[] = $w;
    };
};

// Config
$w = [];
H006Check::that('AK5', WhisperSettings::fromSection([], 6, $collect($w))->batch === 10 && $w === [], 'uden nøgle: batch 10, ingen advarsel');
H006Check::that('AK5', WhisperSettings::fromSection(['batch' => '1'], 6, $collect($w))->batch === 1 && $w === [], 'batch = 1');
H006Check::that('AK5', WhisperSettings::fromSection(['batch' => ' 50 '], 6, $collect($w))->batch === 50 && $w === [], 'batch = 50 (grænsen)');
foreach (['0', '51', '-1', 'x', '2.5', '10; rm -rf /', '１'] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['batch' => $bad], 6, $collect($w));
    H006Check::that('AK5', $s->batch === 10 && count($w) === 1 && str_contains($w[0], '[whisper] batch skal være 1–50 — bruger 10')
        && ($bad === '0' || !str_contains($w[0], $bad)), 'ugyldig batch "' . $bad . '" → 10 og én advarsel');
}
$w = [];
WhisperSettings::fromSection(['threads' => '0', 'batch' => '0'], 6, $collect($w));
H006Check::that('AK5', count($w) === 2, 'én advarsel pr. nøgle (threads og batch)');
foreach ([0, 51] as $bad) {
    $ok = false;
    try {
        new WhisperSettings(2, 1, true, $bad);
    } catch (EngineError) {
        $ok = true;
    }
    H006Check::that('AK5', $ok, "WhisperSettings(batch $bad) afvises");
}

// batch = 1: den gamle vej (transcribe() pr. opkald), ingen batchlinje
f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$engine->size = 1;
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
});
H006Check::that('AK5', $t->runBatch() === 1 && $t->runBatch() === 1 && $t->runBatch() === 1 && $t->runBatch() === 0,
    'batch = 1: ét opkald pr. runBatch');
H006Check::that('AK5', $engine->singleCalls === 3 && $engine->calls === [], 'motoren kaldes med transcribe() tre gange, aldrig batch');
H006Check::that('AK5', count(array_filter($log, static fn(string $l): bool => str_contains($l, 'batch:'))) === 0, 'ingen batchlinje');

// En motor uden batch (F002's FakeEngine) bruger også den gamle vej
f002_setup();
h006_three_calls();
$fe = new FakeEngine();
$t = new Transcriber($fe, static fn(): int => F002_NOW);
ob_start();
$n = $t->runBatch();
ob_end_clean();
H006Check::that('AK5', $n === 1 && $fe->calls === 1, 'Engine uden batch: ét opkald pr. kald');

if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $argvFile = $var . '/argv.txt';
    h006_fake_python($var, $argvFile);
    $calls = h006_three_calls();
    $pe = new ProcessEngine('turbo', null, new WhisperSettings(2, 1, true, 1));
    $t = new Transcriber($pe, null, static function (string $l): void {
    });
    $runs = 0;
    while ($runs < 10 && $t->runBatch() > 0) {
        $runs++;
    }
    $inv = h006_invocations($argvFile);
    $first = $inv[0] ?? [];
    H006Check::that('AK5', $runs === 3 && count($inv) === 3, 'ProcessEngine med batch = 1: python startes 3 gange (' . count($inv) . ')');
    H006Check::that('AK5', !in_array('--batch-max', $first, true) && !in_array('--', $first, true)
        && end($first) === $calls[0]['file'], 'det gamle argv: ingen --batch-max, lydfilen sidst');
    H006Check::that('AK5', h006_state($calls[2]['id'])['status'] === 'faerdig', 'alle tre færdige');
}

// fromConfig læser [whisper] batch
$tmp = sys_get_temp_dir() . '/whisper-h006-' . bin2hex(random_bytes(6));
mkdir($tmp . '/config', 0700, true);
file_put_contents($tmp . '/config/config.ini', "[whisper]\nmodel = turbo\nbatch = 1\n");
Config::setBaseDir($tmp);
H006Check::that('AK5', ProcessEngine::fromConfig()->batchSize() === 1, 'fromConfig: batch = 1 fra config.ini');
file_put_contents($tmp . '/config/config.ini', "[whisper]\nmodel = turbo\nbatch = 99\n");
Config::setBaseDir($tmp);
$w = [];
H006Check::that('AK5', ProcessEngine::fromConfig($collect($w))->batchSize() === 10 && count($w) === 1, 'fromConfig: batch = 99 → 10 og én advarsel');

H006Check::done();
