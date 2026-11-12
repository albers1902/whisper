<?php
declare(strict_types=1);

// H008-AK4: stopper motoren, fordi opkaldet selv har brugt sin grænse, bruger det ét forsøg som i dag:
// - et langt opkald alene (max(2 t, 4 × varighed));
// - et kort opkald i en batch, der har kørt sit eget budget max(5 min, 4 × varighed);
// - batchens første fil altid (gulvet er dens budget), så et hængende opkald aldrig genforsøges for evigt.

require_once __DIR__ . '/h008_lib.php';

use Whisper\Transcription\Transcriber;

$clock = new stdClass();
$clock->now = F002_NOW;
$log = [];
$collect = static function (string $l) use (&$log): void {
    $log[] = $l;
};

// Langt opkald alene
f002_setup();
$calls = h008_calls([2400]);
$engine = new H008Engine($clock);
$engine->singleTimeout = true;
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $collect);
H008Check::that('AK4', $t->runBatch(3000) === 1, 'det lange opkald kører alene');
$s = h006_state($calls[0]['id']);
H008Check::that('AK4', $s['status'] === 'fejlet' && $s['attempts'] === 1 && str_starts_with((string) $s['error'], 'motoren blev stoppet efter tidsgrænsen'),
    'langt opkald: fejlet med ét forsøg (' . (string) $s['error'] . ')');

// Kort opkald nr. 2 i batchen har kørt sit budget (grænse 3000 s, B starter ved 77 s)
f002_setup();
$calls = h008_calls([125, 125, 125]);
$engine = new H008Engine($clock);
$engine->hangOn = $calls[1]['file'];
$log = [];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $collect);
H008Check::that('AK4', $t->runBatch(3000) === 3, 'batchen tog 3 opkald');
$b = h006_state($calls[1]['id']);
$c = h006_state($calls[2]['id']);
H008Check::that('AK4', $b['status'] === 'fejlet' && $b['attempts'] === 1 && $b['retry'] !== null, 'B (kørt over sit budget) er fejlet med ét forsøg');
H008Check::that('AK4', $c['status'] === 'ventende' && $c['attempts'] === 0, 'C (ikke startet) er ventende uden forsøg');
H008Check::that('AK4', h008_lines($log, 'sat tilbage') === 0 && h008_lines($log, ': fejlet: motoren blev stoppet efter tidsgrænsen') === 1,
    'loglinjen siger "fejlet", ikke "sat tilbage"');

// Batchens første fil hænger: altid ét forsøg, også med kort resttid (grænsen er gulvet = dens budget)
f002_setup();
$calls = h008_calls([125, 125]);
$engine = new H008Engine($clock);
$engine->hangOn = $calls[0]['file'];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $collect);
$t->runBatch(100);
$a = h006_state($calls[0]['id']);
H008Check::that('AK4', $a['status'] === 'fejlet' && $a['attempts'] === 1, 'første fil hænger: fejlet med ét forsøg');

// Et hængende opkald bag andre: sat tilbage, men forrest i næste batch bruger det et forsøg, og efter 3 er det opgivet
f002_setup();
$calls = h008_calls([125, 125, 125]);
$engine = new H008Engine($clock);
$engine->hangOn = $calls[1]['file'];
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $collect, null, null, 0.3);
$t->runBatch(400);
H008Check::that('AK4', h006_state($calls[1]['id'])['attempts'] === 0, 'bag A: sat tilbage uden forsøg');
for ($i = 0; $i < 6; $i++) {
    $clock->now += Transcriber::RETRY_DELAY + 1;
    $t->runBatch(400);
}
$b = h006_state($calls[1]['id']);
H008Check::that('AK4', $b['status'] === 'fejlet' && $b['attempts'] === Transcriber::MAX_ATTEMPTS,
    'det hængende opkald er opgivet efter ' . Transcriber::MAX_ATTEMPTS . ' forsøg (' . $b['status'] . ', ' . $b['attempts'] . ')');
H008Check::that('AK4', h006_state($calls[2]['id'])['status'] === 'faerdig', 'køen bag det kom videre');

H008Check::done();
