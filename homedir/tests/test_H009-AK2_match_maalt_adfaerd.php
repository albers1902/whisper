<?php
declare(strict_types=1);

// H009-AK2: med Adversus' målte adfærd (filtercifre er dansk væg-tid, svar er UTC med Z) matcher sync alle
// optagelser ved spidsbelastning — ca. 290 optagelser og 1200 opkald i timen, ca. 8 % af de optagede over 5 min med
// cdr.timestamp = sluttid — inden for loftet på 100 kald pr. kørsel. Den gamle fortolkning (filter_tz = UTC, som F004
// sendte filtrene) matcher kun en lille del i samme fixture.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h009_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

$S = f004_since();
$T0 = $S + 10 * 3600;
$hours = 2;
$now = $T0 + ($hours + 1) * 3600;

/** @return array{http: FakeAdversus, recorded: list<int>, long: list<int>} */
$fixture = static function () use ($T0, $hours): array {
    $http = new FakeAdversus();
    $recorded = [];
    $long = [];
    for ($i = 0; $i < $hours * 1200; $i++) {
        $id = 1498000000 + $i;
        $start = $T0 + $i * 3;
        if ($i % 4 === 0 && $i % 100 !== 0) {
            $dur = $i % 40 === 0 ? 600 + $i % 900 : 60 + $i % 120;
            f004_call($http, $id, 700000 + $i, $start, $dur);
            f004_recording($http, $id, $start + $dur, $start + $dur + 90);   // cdr.timestamp = slut, upload 90 s efter
            $recorded[] = $id;
            if ($dur > 300) {
                $long[] = $id;
            }
        } else {
            f004_call($http, $id, 700000 + $i, $start, 30);
        }
    }
    return ['http' => $http, 'recorded' => $recorded, 'long' => $long];
};

/** @return array{runs: int, max: int, discarded: int} */
$runAll = static function (FakeAdversus $http, ?string $tz, int $maxRuns) use ($now): array {
    $max = 0;
    $discarded = 0;
    $runs = 0;
    for ($k = 0; $k < $maxRuns; $k++) {
        $runs++;
        ['sync' => $sync] = f004_sync(f004_cfg([F004_AUDIO_HOST], null, $tz), $http, $now);
        $r = $sync->run();
        $max = max($max, $r['requests']);
        $discarded += $r['discarded'];
        $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['adversus-rec']), true);
        if (is_array($rec) && $rec['t'] >= $now - Sync::LAG && (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0) {
            break;
        }
    }
    return ['runs' => $runs, 'max' => $max, 'discarded' => $discarded];
};
$saved = static fn(): array => array_map('intval', array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id'));

// 1. Fixet: filtre i væg-tid (standard Europe/Copenhagen)
f004_setup();
DB::exec('DELETE FROM adversus_pending');
['http' => $http, 'recorded' => $recorded, 'long' => $long] = $fixture();
$res = $runAll($http, null, 20);
$got = $saved();
$missing = array_diff($recorded, $got);
echo 'optagelser: ', count($recorded), ' (lange: ', count($long), ') · gemt: ', count($got), ' · kørsler: ', $res['runs'], ' · max kald: ', $res['max'], "\n";
H009Check::that('AK2', count($recorded) >= 500 && count($long) >= 40, 'fixture: ca. 290 optagelser i timen, heraf ca. 8 % lange');
H009Check::that('AK2', $missing === [], 'alle optagelser er matchet med deres opkald (mangler ' . count($missing) . ')');
H009Check::that('AK2', array_diff($long, $got) === [], 'også opkald over 5 min med cdr.timestamp = sluttid');
H009Check::that('AK2', count($got) === count($recorded), 'kun optagede opkald er gemt (' . count($got) . ')');
H009Check::that('AK2', $res['max'] <= Sync::MAX_REQUESTS, 'højst ' . Sync::MAX_REQUESTS . ' kald pr. kørsel (max ' . $res['max'] . ')');
H009Check::that('AK2', $res['discarded'] === 0 && (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0, 'intet forkastet, tom venteliste');
H009Check::that('AK2', $res['runs'] <= 12, 'efterslæbet (12 t) er indhentet på højst 12 kørsler (' . $res['runs'] . ')');
$first = DB::all("SELECT started_at FROM calls WHERE `source` = 'adversus' AND external_id = ?", [(string) $recorded[0]]);
H009Check::that('AK2', ($first[0]['started_at'] ?? null) === gmdate('Y-m-d H:i:s', $T0 + 3), 'started_at er opkaldets start i UTC');

// 2. Den gamle fortolkning i samme fixture
f004_setup();
DB::exec('DELETE FROM adversus_pending');
['http' => $http] = $fixture();
$runAll($http, 'UTC', $res['runs']);
$old = count(array_intersect($recorded, $saved()));
echo 'gammel fortolkning (filter_tz = UTC): ', $old, ' af ', count($recorded), " matchet\n";
H009Check::that('AK2', $old * 2 < count($recorded), 'filtre i UTC (som før H009) matcher under halvdelen (' . $old . ' af ' . count($recorded) . ')');

H009Check::done();
