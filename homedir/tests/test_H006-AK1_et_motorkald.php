<?php
declare(strict_types=1);

// H006-AK1: med 3 ventende opkald og batch = 10 kaldes motoren ÉN gang med alle 3 filer, og alle 3 gemmes som færdige.
// Fejlen: motoren (og dermed modellen) blev startet én gang pr. opkald.

require_once __DIR__ . '/h006_lib.php';

use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

$var = f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;

// Logikken med en falsk batch-motor i PHP
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l): void {
});
H006Check::that('AK1', $t->runBatch() === 3, 'runBatch tager 3 opkald');
H006Check::that('AK1', count($engine->calls) === 1 && $engine->singleCalls === 0, 'motoren kaldes én gang (' . count($engine->calls) . ')');
H006Check::that('AK1', ($engine->calls[0] ?? []) === array_column($calls, 'file'), 'med de 3 filer i køens rækkefølge');
H006Check::that('AK1', ($engine->limits[0] ?? 0) === Transcriber::BATCH_LIMIT, 'tidsgrænse: summen af max(2 t, 4 × varighed), højst 50 min');
foreach ($calls as $c) {
    $s = h006_state($c['id']);
    H006Check::that('AK1', $s['status'] === 'faerdig' && $s['transcript'] && $s['attempts'] === 1, 'opkald ' . $c['id'] . ' er færdigt med transcript');
}
H006Check::that('AK1', $t->runBatch() === 0 && count($engine->calls) === 1, 'tom kø: 0 og intet motorkald');

// Batch-størrelsen holdes: 3 ventende, batch = 2 → 2 + 1
f002_setup();
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$engine->size = 2;
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l): void {
});
H006Check::that('AK1', $t->runBatch() === 2 && $t->runBatch() === 1 && $t->runBatch() === 0, 'batch = 2: først 2, så 1, så tom');
H006Check::that('AK1', array_map('count', $engine->calls) === [2, 1], 'motorkald med 2 og 1 fil');

// Hele vejen gennem ProcessEngine med en falsk python, der logger sit argv
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $argvFile = $var . '/argv.txt';
    h006_fake_python($var, $argvFile);
    $calls = h006_three_calls();
    $pe = ProcessEngine::fromConfig();
    H006Check::that('AK1', $pe->batchSize() === 10, 'standard batch = 10');
    $t = new Transcriber($pe, null, static function (string $l): void {
    });
    H006Check::that('AK1', $t->runBatch() === 3, 'runBatch med ProcessEngine tager 3 opkald');
    $inv = h006_invocations($argvFile);
    H006Check::that('AK1', count($inv) === 1, 'python startes én gang (' . count($inv) . ')');
    $argv = $inv[0] ?? [];
    H006Check::that('AK1', h006_files($argv) === array_column($calls, 'file'), 'de 3 filer efter "--": ' . implode(' ', $argv));
    H006Check::that('AK1', h005_opt($argv, '--batch-max') === '10' && h005_opt($argv, '--model') === 'turbo'
        && h005_opt($argv, '--beam') === '1' && h005_opt($argv, '--vad') === '1', '--batch-max 10 og H005-flagene');
    foreach ($calls as $c) {
        $s = h006_state($c['id']);
        H006Check::that('AK1', $s['status'] === 'faerdig' && $s['transcript'], 'opkald ' . $c['id'] . ' er færdigt (ProcessEngine)');
    }
    H006Check::that('AK1', $t->runBatch() === 0 && count(h006_invocations($argvFile)) === 1, 'tom kø: python startes ikke');
}

H006Check::done();
