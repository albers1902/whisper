<?php
declare(strict_types=1);

// H006-AK4: loggen har linjen pr. opkald med transcriptionstiden for filen (uden indlæsning) og en batchlinje med
// indlæsningstiden:
//   transcribe: opkald 120: færdig (lyd 2:05, tid 1:05, RTF 0,52, threads 5, beam 1, vad on)
//   transcribe: batch: 3 opkald, model indlæst på 0:12, i alt 3:27

require_once __DIR__ . '/h006_lib.php';

use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;

$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock, 12.4, 65);
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
});
$t->runBatch();
$want = [];
foreach ($calls as $c) {
    $want[] = 'transcribe: opkald ' . $c['id'] . ': færdig (lyd 2:05, tid 1:05, RTF 0,52, threads 5, beam 1, vad on)';
}
// i alt = indlæsning (12 s) + 3 × 65 s = 207 s
$want[] = 'transcribe: batch: 3 opkald, model indlæst på 0:12, i alt 3:27';
H006Check::that('AK4', $log === $want, "loglinjerne:\n  " . implode("\n  ", $log));
H006Check::that('AK4', !str_contains(implode("\n", $log), 'tid 1:17'), 'tid pr. opkald er uden indlæsningen');

// Ukendt indlæsningstid (motoren stoppet før sidste linje): "-"
f002_setup();
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$engine->stopAt = $calls[0]['file'];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
});
$t->runBatch();
H006Check::that('AK4', preg_match('/^transcribe: batch: 3 opkald, model indlæst på -, i alt [0-9]+:[0-9]{2}$/', (string) end($log)) === 1,
    'ukendt indlæsning: ' . (string) end($log));

// Hele vejen: ProcessEngine læser elapsed og load_seconds fra python
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    h006_fake_python($var, $var . '/argv.txt');
    $calls = h006_three_calls();
    $pe = ProcessEngine::fromConfig();
    $log = [];
    $t = new Transcriber($pe, null, static function (string $l) use (&$log): void {
        $log[] = $l;
    });
    $t->runBatch();
    H006Check::that('AK4', ($log[0] ?? '') === 'transcribe: opkald ' . $calls[0]['id'] . ': færdig (lyd 2:05, tid 1:05, RTF 0,52, '
        . $pe->label() . ')', 'ProcessEngine: tid = pythons elapsed for filen: ' . ($log[0] ?? ''));
    H006Check::that('AK4', preg_match('/^transcribe: batch: 3 opkald, model indlæst på 0:12, i alt [0-9]+:[0-9]{2}$/', (string) end($log)) === 1,
        'ProcessEngine: batchlinjen med load_seconds: ' . (string) end($log));
}

H006Check::done();
