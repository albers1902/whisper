<?php
declare(strict_types=1);

// H006-AK3: timeout midt i batchen: opkald med resultat er gemt, det igangværende tæller ét forsøg, og resten er
// ventende med uændrede forsøg.

require_once __DIR__ . '/h006_lib.php';

use Whisper\DB;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;
use Whisper\Transcription\WhisperSettings;

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;

$calls = h006_three_calls();
// C har allerede brugt ét forsøg før (fejlet tidligere, backoff udløbet): det skal være uændret bagefter
DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_attempts = 1, transcript_retry_at = '2026-01-01 00:00:00' WHERE id = ?",
    [$calls[2]['id']]);
$engine = new FakeBatchEngine($clock);
$engine->stopAt = $calls[1]['file'];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
});
H006Check::that('AK3', $t->runBatch() === 3, 'batchen tog 3 opkald');
$a = h006_state($calls[0]['id']);
$b = h006_state($calls[1]['id']);
$c = h006_state($calls[2]['id']);
H006Check::that('AK3', $a['status'] === 'faerdig' && $a['transcript'] && $a['attempts'] === 1, 'A (med resultat) er gemt');
H006Check::that('AK3', $b['status'] === 'fejlet' && $b['attempts'] === 1 && $b['retry'] !== null
    && str_starts_with((string) $b['error'], 'motoren blev stoppet efter tidsgrænsen'), 'B (i gang) er fejlet med ét forsøg: ' . (string) $b['error']);
H006Check::that('AK3', $c['status'] === 'ventende' && $c['attempts'] === 1 && !$c['transcript'], 'C er ventende med uændrede forsøg (1)');
H006Check::that('AK3', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'transcriberer'") === 0,
    'intet opkald står tilbage i transcriberer');
H006Check::that('AK3', count(array_filter($log, static fn(string $l): bool => str_starts_with($l, 'transcribe: batch: 3 opkald, model indlæst på '))) === 1,
    'batchlinjen skrives også efter en timeout');

// Næste kørsel tager C igen
$engine->stopAt = null;
H006Check::that('AK3', $t->runBatch() === 1 && h006_state($calls[2]['id'])['status'] === 'faerdig', 'C tages i næste batch');

// Hele vejen gennem ProcessEngine: python hænger på B og stoppes ved tidsgrænsen (2 s i testen)
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $argvFile = $var . '/argv.txt';
    h006_fake_python($var, $argvFile);
    $calls = h006_three_calls();
    file_put_contents($calls[1]['file'] . '.haeng', 'x');
    $pe = new ProcessEngine('turbo', 2, new WhisperSettings(2, 1, true, 10));
    $t = new Transcriber($pe, null, static function (string $l): void {
    });
    $t0 = microtime(true);
    H006Check::that('AK3', $t->runBatch() === 3, 'ProcessEngine: batchen tog 3 opkald');
    $took = microtime(true) - $t0;
    H006Check::that('AK3', $took < 20, sprintf('ProcessEngine: hængende motor stoppet ved tidsgrænsen (%.1f s)', $took));
    $a = h006_state($calls[0]['id']);
    $b = h006_state($calls[1]['id']);
    $c = h006_state($calls[2]['id']);
    H006Check::that('AK3', $a['status'] === 'faerdig' && $a['transcript'], 'ProcessEngine: A (skrevet før stop) er gemt');
    // H008: grænsen (2 s) stoppede B, før det havde kørt sit eget budget (max(5 min, 4 × varighed)), så det var
    // kørslens tid, der slap op: B sættes tilbage uden brugt forsøg (egen grænse: H008-AK4)
    H006Check::that('AK3', $b['status'] === 'ventende' && $b['attempts'] === 0,
        'ProcessEngine: B (stoppet før sit eget budget) er ventende med 0 forsøg');
    H006Check::that('AK3', $c['status'] === 'ventende' && $c['attempts'] === 0, 'ProcessEngine: C er ventende med 0 forsøg');
}

H006Check::done();
