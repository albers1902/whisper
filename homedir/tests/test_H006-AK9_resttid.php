<?php
declare(strict_types=1);

// H006-AK9: batchens grænse er min(grænse, resttid af kørslens ramme), men mindst nok til ét kort opkald
// (max(5 min, 4 × dets varighed)). Et langt opkald starter kun, hvis der er mere end 10 min tilbage; ellers venter
// det uden brugt forsøg til næste kørsel (security LOW-3).

require_once __DIR__ . '/h006_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

// Grænsen
H006Check::that('AK9', Transcriber::batchLimit([125, 125, 125], 5000) === 3000, 'fuld ramme: højst 50 min');
H006Check::that('AK9', Transcriber::batchLimit([125, 125, 125], 900) === 900, '15 min tilbage: 15 min');
H006Check::that('AK9', Transcriber::batchLimit([125, 125, 125], 100) === 500, 'næsten ingen tid: mindst 4 × første opkald (500 s)');
H006Check::that('AK9', Transcriber::batchLimit([30, 600], 10) === 300, 'mindst 5 min');
H006Check::that('AK9', Transcriber::batchLimit([600], -50) === 2400, 'mindst 4 × 600 s, også med negativ resttid');

// Gennem runBatch
f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;
h006_three_calls();
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $x): void {
});
H006Check::that('AK9', $t->runBatch(900) === 3 && ($engine->limits[0] ?? 0) === 900, 'runBatch(900): batchen får 900 s');

// Et langt opkald med 10 min eller mindre tilbage venter
f002_setup();
$l = f002_insert(['started_at' => '2026-10-01 08:00:00', 'duration_sec' => 2400, 'transcript_attempts' => 1]);
f002_audio($l);
$engine = new FakeBatchEngine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, static function (string $x): void {
});
H006Check::that('AK9', $t->runBatch(600) === 0, '600 s tilbage: det lange opkald startes ikke (0)');
$s = h006_state($l);
H006Check::that('AK9', $engine->singleCalls === 0 && $engine->calls === [], 'motoren kaldes ikke');
H006Check::that('AK9', $s['status'] === 'ventende' && $s['attempts'] === 1, 'opkaldet er ventende med uændrede forsøg (1)');
H006Check::that('AK9', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'transcriberer'") === 0, 'intet i transcriberer');
H006Check::that('AK9', $t->runBatch(601) === 1 && $engine->singleCalls === 1 && h006_state($l)['status'] === 'faerdig',
    '601 s tilbage: det lange opkald kører alene');

H006Check::done();
