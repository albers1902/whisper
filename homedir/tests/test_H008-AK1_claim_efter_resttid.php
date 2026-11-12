<?php
declare(strict_types=1);

// H008-AK1: en batch claimer kun opkald, så Σ varighed × rtf_estimate holder sig inden for min(BATCH_LIMIT, resttid).
// 10 × 5 min, rtf 1,5: resttid 1200 → 2 (450 + 450 ≤ 1200 < 1350), resttid 3000 → 6. Køens rækkefølge holdes: det
// første opkald, der ikke passer, afslutter claim, også selv om et senere ville passe.

require_once __DIR__ . '/h008_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

$clock = new stdClass();
$clock->now = F002_NOW;
$quiet = static function (string $l): void {
};

foreach ([1200 => 2, 3000 => 6] as $remaining => $want) {
    f002_setup();
    $calls = h008_calls(array_fill(0, 10, 300));
    $engine = new H008Engine($clock);
    $t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet, null, null, 1.5);
    $n = $t->runBatch($remaining);
    H008Check::that('AK1', $n === $want && count($engine->calls[0] ?? []) === $want,
        "resttid $remaining: $want opkald claimet og sendt til motoren ($n)");
    H008Check::that('AK1', h008_count('faerdig') === $want && h008_count('ventende') === 10 - $want,
        "resttid $remaining: resten er ventende");
    H008Check::that('AK1', (int) DB::scalar("SELECT COALESCE(SUM(transcript_attempts), 0) FROM calls WHERE transcript_status = 'ventende'") === 0,
        "resttid $remaining: de ikke claimede har intet forsøg brugt");
    $first = array_slice(array_column($calls, 'file'), 0, $want);
    H008Check::that('AK1', ($engine->calls[0] ?? []) === $first, "resttid $remaining: de første $want i køens rækkefølge");
}

// Standard rtf er 1,5 (uden parameter)
f002_setup();
h008_calls(array_fill(0, 10, 300));
$engine = new H008Engine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet);
H008Check::that('AK1', $t->runBatch(1200) === 2, 'standard rtf_estimate = 1,5');

// Intet spring: 300, 300, 600, 30 med resttid 1200 → 2. Det tredje (900) passer ikke; det fjerde (45) tages ikke forbi det
f002_setup();
$calls = h008_calls([300, 300, 600, 30]);
$engine = new H008Engine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet, null, null, 1.5);
H008Check::that('AK1', $t->runBatch(1200) === 2 && ($engine->calls[0] ?? []) === [$calls[0]['file'], $calls[1]['file']],
    'det første opkald, der ikke passer, afslutter claim (ingen spring)');

// Resttiden over BATCH_LIMIT tæller som BATCH_LIMIT
f002_setup();
h008_calls(array_fill(0, 10, 300));
$engine = new H008Engine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet, null, null, 1.5);
H008Check::that('AK1', $t->runBatch(9000) === 6, 'resttid 9000 begrænses af BATCH_LIMIT (3000): 6');

H008Check::done();
