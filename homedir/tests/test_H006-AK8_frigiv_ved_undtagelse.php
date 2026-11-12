<?php
declare(strict_types=1);

// H006-AK8: en undtagelse uden for motorkaldet midt i en batch (her: finish() kaster, fordi loggen fejler, efter at
// det igangværende opkald er markeret fejlet) efterlader intet opkald i "transcriberer" (security LOW-2). Før
// rettelsen stod resten af batchen der, til stale-reglen ryddede det efter 2 timer.

require_once __DIR__ . '/h006_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$engine->stopAt = $calls[1]['file'];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    if (str_contains($l, ': fejlet: ')) {
        throw new RuntimeException('loggen kan ikke skrives');
    }
    $log[] = $l;
});

$thrown = false;
$n = 0;
try {
    $n = $t->runBatch();
} catch (Throwable) {
    $thrown = true;
}
H006Check::that('AK8', !$thrown && $n === 0, 'runBatch kaster ikke videre og melder 0, så kørslen stopper til næste minut (H007, DECISIONS #34)');
H006Check::that('AK8', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'transcriberer'") === 0,
    'intet opkald står tilbage i transcriberer');
$a = h006_state($calls[0]['id']);
$b = h006_state($calls[1]['id']);
$c = h006_state($calls[2]['id']);
H006Check::that('AK8', $a['status'] === 'faerdig', 'A (med resultat) er gemt');
H006Check::that('AK8', $b['status'] === 'fejlet' && $b['attempts'] === 1, 'B (i gang) er fejlet med ét forsøg');
H006Check::that('AK8', $c['status'] === 'ventende' && $c['attempts'] === 0, 'C er frigivet: ventende med forsøget givet tilbage');
H006Check::that('AK8', in_array('transcribe: batch afbrudt: Intern fejl ved transcription', $log, true), 'afbrydelsen logges kort');

// En fejl i loglinjen for et gemt opkald gør det ikke til fejlet
f002_setup();
$calls = h006_three_calls();
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l): void {
    if (str_contains($l, ': færdig ')) {
        throw new RuntimeException('loggen kan ikke skrives');
    }
});
$t->runBatch();
$a = h006_state($calls[0]['id']);
H006Check::that('AK8', $a['status'] === 'faerdig' && $a['transcript'], 'A er stadig færdig, selv om dens loglinje fejlede');
H006Check::that('AK8', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'transcriberer'") === 0,
    'intet i transcriberer efter fejl i loglinjen');

H006Check::done();
