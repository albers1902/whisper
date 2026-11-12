<?php
declare(strict_types=1);

// H008-AK3: stopper motoren, fordi kørslens resttid er brugt op, før det igangværende opkald har haft sit eget budget
// (max(5 min, 4 × varighed)), er de færdige opkald gemt, alle andre ventende med uændrede forsøg, og loglinjen siger
// "sat tilbage (kørslens tid opbrugt)" for hvert af dem — aldrig "fejlet".

require_once __DIR__ . '/h008_lib.php';

use Whisper\DB;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;
use Whisper\Transcription\WhisperSettings;

$clock = new stdClass();
$clock->now = F002_NOW;

f002_setup();
$calls = h008_calls([125, 125, 125]);
// C har brugt ét forsøg før: det skal være uændret bagefter
DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_attempts = 1, transcript_retry_at = '2026-01-01 00:00:00' WHERE id = ?",
    [$calls[2]['id']]);
$engine = new H008Engine($clock);
$engine->hangOn = $calls[1]['file'];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $l) use (&$log): void {
    $log[] = $l;
}, null, null, 0.3);
// Grænsen bliver gulvet 500 s; B starter ved 77 s og har kørt 423 s < 500 s, da motoren stoppes
H008Check::that('AK3', $t->runBatch(400) === 3 && ($engine->limits[0] ?? 0) === 500, 'batchen tog 3 opkald med grænsen 500 s');
$a = h006_state($calls[0]['id']);
$b = h006_state($calls[1]['id']);
$c = h006_state($calls[2]['id']);
H008Check::that('AK3', $a['status'] === 'faerdig' && $a['transcript'] && $a['attempts'] === 1, 'A (færdig) er gemt');
H008Check::that('AK3', $b['status'] === 'ventende' && $b['attempts'] === 0 && $b['retry'] === null, 'B (i gang) er ventende uden brugt forsøg');
H008Check::that('AK3', $c['status'] === 'ventende' && $c['attempts'] === 1, 'C er ventende med uændrede forsøg (1)');
H008Check::that('AK3', h008_count('transcriberer') === 0, 'intet står tilbage i transcriberer');
H008Check::that('AK3', in_array('transcribe: opkald ' . $calls[1]['id'] . ': sat tilbage (kørslens tid opbrugt)', $log, true)
    && in_array('transcribe: opkald ' . $calls[2]['id'] . ': sat tilbage (kørslens tid opbrugt)', $log, true),
    'loglinjen "sat tilbage (kørslens tid opbrugt)" for B og C');
H008Check::that('AK3', h008_lines($log, 'fejlet') === 0, 'ingen "fejlet"-linje: ' . implode(' | ', $log));

// Næste batch: B står forrest og tages
$engine->hangOn = null;
H008Check::that('AK3', $t->runBatch(3000) === 2 && ($engine->calls[1][0] ?? '') === $calls[1]['file'], 'B står forrest i næste batch');

// Hele vejen gennem ProcessEngine: python hænger på B, og grænsen (2 s i testen) er langt under B's budget
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    h006_fake_python($var, $var . '/argv.txt');
    $calls = h008_calls([125, 125, 125]);
    file_put_contents($calls[1]['file'] . '.haeng', 'x');
    $pe = new ProcessEngine('turbo', 2, new WhisperSettings(2, 1, true, 10));
    $log = [];
    $t = new Transcriber($pe, null, static function (string $l) use (&$log): void {
        $log[] = $l;
    });
    H008Check::that('AK3', $t->runBatch() === 3, 'ProcessEngine: batchen tog 3 opkald');
    $a = h006_state($calls[0]['id']);
    $b = h006_state($calls[1]['id']);
    $c = h006_state($calls[2]['id']);
    H008Check::that('AK3', $a['status'] === 'faerdig' && $a['transcript'], 'ProcessEngine: A er gemt');
    H008Check::that('AK3', $b['status'] === 'ventende' && $b['attempts'] === 0, 'ProcessEngine: B er ventende uden forsøg');
    H008Check::that('AK3', $c['status'] === 'ventende' && $c['attempts'] === 0, 'ProcessEngine: C er ventende uden forsøg');
    H008Check::that('AK3', h008_lines($log, ': sat tilbage (kørslens tid opbrugt)') === 2 && h008_lines($log, 'fejlet') === 0,
        'ProcessEngine: to "sat tilbage"-linjer, ingen "fejlet"');
}

H008Check::done();
