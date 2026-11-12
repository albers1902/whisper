<?php
declare(strict_types=1);

// F004-AK4: kaldloftet på 100 og 429 stopper pænt. Cursoren står ved sidst gemte data, og næste kørsel fortsætter uden
// huller og uden dubletter. Datamængden kræver flere kørsler (store vinduer halveres), og der er optagelser præcis på
// en vinduesgrænse og flere i samme sekund.

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 7 * 3600;
$http = new FakeAdversus();
$want = [];
for ($i = 0; $i < 3000; $i++) {
    $id = 100000 + $i;
    f004_recorded($http, $id, 50000 + $i, $S + $i * 7, 60);
    $want[$id] = true;
    if ($i % 3 === 0) {
        f004_call($http, 900000 + $i, 50000 + $i, $S + $i * 7 + 3);   // opkald uden optagelse imellem
    }
    if ($i % 150 === 0) {
        f004_lead($http, 50000 + $i, 'success', $S + 60);
    }
}
// Fem optagelser uploadet i præcis samme sekund på vinduesgrænsen S + 1 t
for ($k = 0; $k < 5; $k++) {
    $id = 200000 + $k;
    f004_call($http, $id, 60000 + $k, $S + 3000 + $k, 60);
    $http->recordings[] = ['cdr' => ['id' => $id, 'timestamp' => f004_iso($S + 3000 + $k)], 'timestamp' => f004_iso($S + 3600),
        'recording' => ['name' => 'x', 'download' => null], 'clips' => []];
    $want[$id] = true;
}

/** Alle optagelser med upload før cursoren er gemt (cursoren går aldrig forbi data, der ikke er gemt). */
$invariant = static function () use ($http): bool {
    $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['adversus-rec']), true);
    $t = is_array($rec) ? (int) $rec['t'] : 0;
    $saved = array_flip(array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id'));
    foreach ($http->recordings as $r) {
        if (strtotime($r['timestamp']) < $t && !isset($saved[(string) $r['cdr']['id']])) {
            return false;
        }
    }
    return true;
};

// 1. Kørsel med 429 midt i: stopper pænt, rate limit står som information, cursoren er ikke gået forbi
$http->fail429At = 12;
['sync' => $s1] = f004_sync(f004_cfg(), $http, $now);
$r1 = $s1->run();
F004Check::that('AK4', $r1['error'] !== null && str_starts_with($r1['error'], Sync::RATE_LIMIT_PREFIX), '429 stopper kørslen pænt (rate limit)');
F004Check::that('AK4', $invariant(), 'efter 429: cursoren står ved sidst gemte data');
$http->fail429At = null;

// 2. Kørsler med kaldloftet 100, til alt er hentet
$runs = 0;
$maxReq = 0;
$okInv = true;
$caughtUp = false;
for ($run = 1; $run <= 30; $run++) {
    $before = count($http->requests);
    ['sync' => $s] = f004_sync(f004_cfg(), $http, $now + $run);
    $r = $s->run();
    $runs++;
    $maxReq = max($maxReq, count($http->requests) - $before);
    $okInv = $okInv && $invariant();
    $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['adversus-rec']), true);
    $pending = (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND transcript_status <> 'ikke_valgt' AND audio_status <> 'hentet'");
    if (is_array($rec) && $rec['t'] >= $now + $run - Sync::LAG && $pending === 0) {
        $caughtUp = true;
        break;
    }
}
echo "kørsler: $runs, største kørsel: $maxReq kald\n";
F004Check::that('AK4', $runs > 1, 'datamængden kræver flere kørsler (loftet nås)');
F004Check::that('AK4', $maxReq <= 100, 'ingen kørsel bruger over 100 kald (største: ' . $maxReq . ')');
F004Check::that('AK4', $okInv, 'efter hver kørsel står cursoren ved sidst gemte data');
F004Check::that('AK4', $caughtUp, 'hentningen indhenter efterslæbet');

$ids = array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id');
$missing = array_diff(array_map('strval', array_keys($want)), $ids);
F004Check::that('AK4', $missing === [], 'ingen huller: alle optagede opkald er gemt (' . count($missing) . ' mangler)');
F004Check::that('AK4', count($ids) === count(array_unique($ids)) && count($ids) === count($want), 'ingen dubletter og intet ekstra');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id >= '900000'") === 0,
    'opkald uden optagelse er aldrig gemt');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id LIKE '2000%'") === 5,
    'optagelser på vinduesgrænsen og i samme sekund er med');

// Filtrene bruger kun $gt/$lt (ingen $gte)
$all = implode("\n", array_column($http->requests, 'url'));
F004Check::that('AK4', !str_contains(urldecode($all), '$gte'), 'ingen $gte i filtrene');
F004Check::done();
