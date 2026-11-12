<?php
declare(strict_types=1);

// H008-AK2: det første opkald claimes altid, også når det alene er større end resttiden, ligesom i dag.

require_once __DIR__ . '/h008_lib.php';

use Whisper\Transcription\Transcriber;

$clock = new stdClass();
$clock->now = F002_NOW;
$quiet = static function (string $l): void {
};

f002_setup();
$calls = h008_calls([600, 30, 30]);
$engine = new H008Engine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet, null, null, 1.5);
H008Check::that('AK2', $t->runBatch(300) === 1, 'resttid 300, første opkald 600 s × 1,5 = 900: det claimes alene');
H008Check::that('AK2', ($engine->calls[0] ?? []) === [$calls[0]['file']], 'kun det første sendes til motoren');
H008Check::that('AK2', ($engine->limits[0] ?? 0) === 2400, 'grænsen er stadig gulvet 4 × 600 s');
H008Check::that('AK2', h006_state($calls[0]['id'])['status'] === 'faerdig', 'det første er transcriberet');
H008Check::that('AK2', h006_state($calls[1]['id'])['status'] === 'ventende' && h006_state($calls[1]['id'])['attempts'] === 0
    && h006_state($calls[2]['id'])['status'] === 'ventende', 'de næste er ventende uden forsøg');

// Også med resttid 0 eller negativ
f002_setup();
h008_calls([125, 125]);
$engine = new H008Engine($clock);
$t = new Transcriber($engine, static fn(): int => (int) $clock->now, $quiet, null, null, 5.0);
H008Check::that('AK2', $t->runBatch(-30) === 1, 'negativ resttid: det første claimes stadig');

H008Check::done();
