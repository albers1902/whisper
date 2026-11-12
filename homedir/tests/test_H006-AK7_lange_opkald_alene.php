<?php
declare(strict_types=1);

// H006-AK7: et langt opkald (over 10 min lyd, eller ukendt længde) batches ikke, men kører alene med den gamle grænse
// max(2 t, 4 × varighed). Fejlen (security LOW-1): med RTF 1,47 på DEV kunne et opkald over ca. 33 min aldrig blive
// færdigt inden for batchens loft på 50 min og endte som fejlet efter tre forsøg.

require_once __DIR__ . '/h006_lib.php';

use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

H006Check::that('AK7', !Transcriber::isLong(125) && !Transcriber::isLong(600), '125 s og 600 s er korte');
H006Check::that('AK7', Transcriber::isLong(601) && Transcriber::isLong(2400) && Transcriber::isLong(0), '601 s, 40 min og ukendt (0) er lange');
H006Check::that('AK7', ProcessEngine::timeoutFor(2400) === 9600, '40 min lyd: grænsen max(2 t, 160 min) = 160 min');

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;
$a = f002_insert(['started_at' => '2026-10-01 08:00:00', 'duration_sec' => 125]);
$fa = f002_audio($a);
$l = f002_insert(['started_at' => '2026-10-01 09:00:00', 'duration_sec' => 2400]);
f002_audio($l);
$c = f002_insert(['started_at' => '2026-10-01 10:00:00', 'duration_sec' => 125]);
$fc = f002_audio($c);
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $x): void {
});

H006Check::that('AK7', $t->runBatch() === 2, 'første batch tager de 2 korte');
H006Check::that('AK7', $engine->calls === [[$fa, $fc]], 'batchen har A og C, ikke det lange opkald');
$s = h006_state($l);
H006Check::that('AK7', $s['status'] === 'ventende' && $s['attempts'] === 0, 'det lange opkald er urørt (ventende, 0 forsøg)');
H006Check::that('AK7', $t->runBatch() === 1, 'næste kald tager det lange opkald');
H006Check::that('AK7', $engine->singleCalls === 1 && $engine->lastDuration === 2400 && count($engine->calls) === 1,
    'det lange opkald sendes alene med transcribe() og sin varighed (grænse max(2 t, 4 × 2400 s))');
H006Check::that('AK7', h006_state($l)['status'] === 'faerdig', 'det lange opkald er færdigt');

// Ukendt længde kører også alene
f002_setup();
$u = f002_insert(['started_at' => '2026-10-02 08:00:00', 'duration_sec' => 0]);
f002_audio($u);
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $x): void {
});
H006Check::that('AK7', $t->runBatch() === 1 && $engine->singleCalls === 1 && $engine->calls === [], 'ukendt længde: alene');

// Hele vejen: ProcessEngine starter python uden --batch-max for det lange opkald
if (DIRECTORY_SEPARATOR === '/') {
    $var = f002_setup();
    $argvFile = $var . '/argv.txt';
    h006_fake_python($var, $argvFile);
    $l = f002_insert(['started_at' => '2026-10-03 08:00:00', 'duration_sec' => 2400]);
    $fl = f002_audio($l);
    $t = new Transcriber(ProcessEngine::fromConfig(), null, static function (string $x): void {
    });
    H006Check::that('AK7', $t->runBatch() === 1, 'ProcessEngine: det lange opkald tages');
    $first = h006_invocations($argvFile)[0] ?? [];
    H006Check::that('AK7', !in_array('--batch-max', $first, true) && end($first) === $fl, 'ProcessEngine: det gamle argv med én fil');
    H006Check::that('AK7', h006_state($l)['status'] === 'faerdig', 'ProcessEngine: færdigt');
}

H006Check::done();
