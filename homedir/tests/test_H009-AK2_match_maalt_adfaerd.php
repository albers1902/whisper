<?php
declare(strict_types=1);

// H009-AK2: med Adversus' målte adfærd (filtercifre er dansk væg-tid, svar er UTC med Z) henter sync alle opkald ved
// spidsbelastning — ca. 1200 opkald i timen, heraf lange opkald — inden for loftet på 100 kald pr. kørsel. Fra H013
// gemmes alle CDR'er direkte fra /calls (ingen matchning mod optagelser), og started_at er opkaldets start i UTC.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h009_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

$S = f004_since();
$T0 = $S + 10 * 3600;
$hours = 2;
$now = $T0 + ($hours + 1) * 3600;

f004_setup();
$http = new FakeAdversus();
$ids = [];
$long = [];
for ($i = 0; $i < $hours * 1200; $i++) {
    $id = 1498000000 + $i;
    $dur = $i % 40 === 0 ? 600 + $i % 900 : 30 + $i % 120;
    f004_call($http, $id, 700000 + $i, $T0 + $i * 3, $dur);
    $ids[] = $id;
    if ($dur > 300) {
        $long[] = $id;
    }
}
$runs = 0;
$max = 0;
for ($k = 0; $k < 20; $k++) {
    $runs++;
    ['sync' => $sync] = f004_sync(f004_cfg(), $http, $now);
    $r = $sync->run();
    $max = max($max, $r['requests']);
    if ((int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'") >= count($ids)) {
        break;
    }
}
$got = array_map('intval', array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id'));
echo 'opkald: ', count($ids), ' (lange: ', count($long), ') · gemt: ', count($got), ' · kørsler: ', $runs, ' · max kald: ', $max, "\n";
H009Check::that('AK2', array_diff($ids, $got) === [], 'alle opkald er gemt (mangler ' . count(array_diff($ids, $got)) . ')');
H009Check::that('AK2', array_diff($long, $got) === [] && count($long) >= 50, 'også opkald over 5 min');
H009Check::that('AK2', count($got) === count($ids), 'ingen dubletter (' . count($got) . ')');
H009Check::that('AK2', $max <= Sync::MAX_REQUESTS, 'højst ' . Sync::MAX_REQUESTS . ' kald pr. kørsel (max ' . $max . ')');
H009Check::that('AK2', $runs <= 2, 'efterslæbet (13 t, 2400 opkald) er indhentet på højst 2 kørsler (' . $runs . ')');
H009Check::that('AK2', $http->to('/v1/recordings') === [], 'ingen optagelsesliste og ingen matchning');
$first = DB::all("SELECT started_at FROM calls WHERE `source` = 'adversus' AND external_id = ?", [(string) $ids[0]]);
H009Check::that('AK2', ($first[0]['started_at'] ?? null) === gmdate('Y-m-d H:i:s', $T0), 'started_at er opkaldets start i UTC');

H009Check::done();
