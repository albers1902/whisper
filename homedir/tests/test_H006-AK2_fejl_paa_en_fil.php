<?php
declare(strict_types=1);

// H006-AK2: en error-linje for én fil markerer kun det opkald som fejlet (backoff som i dag); de andre gemmes.

require_once __DIR__ . '/h006_lib.php';

use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;

$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$engine->fail = [$calls[1]['file']];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
});
H006Check::that('AK2', $t->runBatch() === 3, 'batchen tager 3 opkald');
$a = h006_state($calls[0]['id']);
$b = h006_state($calls[1]['id']);
$c = h006_state($calls[2]['id']);
H006Check::that('AK2', $a['status'] === 'faerdig' && $a['transcript'], 'A (før fejlen) er færdig');
H006Check::that('AK2', $c['status'] === 'faerdig' && $c['transcript'], 'C (efter fejlen) er færdig: batchen fortsatte');
H006Check::that('AK2', $b['status'] === 'fejlet' && !$b['transcript'] && $b['attempts'] === 1, 'B er fejlet med ét forsøg brugt');
H006Check::that('AK2', $b['error'] === 'motoren kunne ikke transcribere lydfilen', 'kort, fast fejltekst: ' . (string) $b['error']);
H006Check::that('AK2', $b['retry'] !== null && strtotime($b['retry'] . ' UTC') > (int) $clock->now - 300,
    'B får backoff (transcript_retry_at): ' . (string) $b['retry']);
H006Check::that('AK2', in_array('transcribe: opkald ' . $calls[1]['id'] . ': fejlet: motoren kunne ikke transcribere lydfilen', $log, true),
    'fejllinjen for B som i dag');

// Hele vejen gennem ProcessEngine: python skriver en error-linje for B og fortsætter
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $argvFile = $var . '/argv.txt';
    h006_fake_python($var, $argvFile);
    $calls = h006_three_calls();
    file_put_contents($calls[1]['file'] . '.fejl', 'x');
    $t = new Transcriber(ProcessEngine::fromConfig(), null, static function (string $l): void {
    });
    H006Check::that('AK2', $t->runBatch() === 3, 'ProcessEngine: batchen tager 3 opkald');
    $a = h006_state($calls[0]['id']);
    $b = h006_state($calls[1]['id']);
    $c = h006_state($calls[2]['id']);
    H006Check::that('AK2', $a['status'] === 'faerdig' && $c['status'] === 'faerdig', 'ProcessEngine: A og C er færdige');
    H006Check::that('AK2', $b['status'] === 'fejlet' && $b['attempts'] === 1 && $b['retry'] !== null
        && $b['error'] === 'motoren kunne ikke transcribere lydfilen', 'ProcessEngine: kun B er fejlet, med backoff');
    H006Check::that('AK2', count(h006_invocations($argvFile)) === 1, 'ProcessEngine: ét motorkald');
}

H006Check::done();
