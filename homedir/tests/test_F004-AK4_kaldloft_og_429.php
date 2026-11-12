<?php
declare(strict_types=1);

// F004-AK4: kaldloftet og 429 stopper pænt. Cursoren står ved sidst gemte data, og næste kørsel fortsætter uden huller
// og uden dubletter. Datamængden kræver flere kørsler, og der er flere opkald i samme sekund end en side (keyset).
// Fra H013 er det CDR'er (/calls), ikke optagelser: et opkald, der først dukker op i /calls efter cursoren, hentes af
// kontrollen pr. time, og et opkald med ukendt retning står som synlig advarsel, ikke lydløst (QA F-1).

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Sync;
use Whisper\Calls;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 8 * 3600;
$http = new FakeAdversus();
$want = [];
for ($i = 0; $i < 3000; $i++) {
    $id = 100000 + $i;
    f004_call($http, $id, 50000 + $i, $S + $i * 7, 60, 'outbound', 77, $i % 3 === 0 ? 'no-answer' : 'answered');
    $want[$id] = true;
    if ($i % 150 === 1) {   // succes-opkaldene er besvarede
        f004_lead($http, 50000 + $i, 'success', $S + 60);
    }
}
// 150 opkald i præcis samme sekund (mere end en side): keyset skal bladre inden for sekundet
for ($k = 0; $k < 150; $k++) {
    $id = 200000 + $k;
    f004_call($http, $id, 60000 + $k, $S + 3600, 60);
    $want[$id] = true;
}

/** Alle opkald med start før cursoren er gemt (intet tabt bag cursoren). */
$invariant = static function () use ($http): bool {
    $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_CDR]), true);
    $t = is_array($rec) ? (int) $rec['t'] : 0;
    $saved = array_flip(array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id'));
    foreach ($http->calls as $c) {
        if (strtotime($c['start']) < $t && !isset($saved[(string) $c['id']])) {
            return false;
        }
    }
    return true;
};

// 1. Kørsel med 429 midt i: stopper pænt, rate limit står som information, cursoren er ikke gået forbi
$http->fail429At = 8;
['sync' => $s1] = f004_sync(f004_cfg(), $http, $now);
$r1 = $s1->run();
F004Check::that('AK4', $r1['error'] !== null && str_starts_with($r1['error'], Sync::RATE_LIMIT_PREFIX), '429 stopper kørslen pænt (rate limit)');
F004Check::that('AK4', $invariant(), 'efter 429: cursoren står ved sidst gemte data');
$http->fail429At = null;

// 2. Kørsler med et lavt kaldloft (40), til alt er hentet og kontrolleret
$log = [];
$runs = 0;
$maxReq = 0;
$okInv = true;
$caughtUp = false;
for ($run = 1; $run <= 30; $run++) {
    $before = count($http->requests);
    ['sync' => $s] = f004_sync(f004_cfg(), $http, $now + $run, $log, 40);
    $s->run();
    $runs++;
    $maxReq = max($maxReq, count($http->requests) - $before);
    $okInv = $okInv && $invariant();
    $chk = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_CHECK]), true);
    $audio = (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND transcript_status <> 'ikke_valgt' AND audio_status <> 'hentet'");
    if (is_array($chk) && $chk['b'] + Sync::CHECK_SPAN > $now - Sync::CHECK_SETTLE && $audio === 0
        && (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'") >= count($want)) {
        $caughtUp = true;
        break;
    }
}
echo "kørsler: $runs, største kørsel: $maxReq kald\n";
F004Check::that('AK4', $runs > 1, 'datamængden kræver flere kørsler (loftet nås)');
F004Check::that('AK4', $maxReq <= 40, 'ingen kørsel bruger over loftet (største: ' . $maxReq . ')');
F004Check::that('AK4', $okInv, 'efter hver kørsel er alt bag cursoren gemt');
F004Check::that('AK4', $caughtUp, 'hentningen indhenter efterslæbet, og kontrollen er nået frem');

$ids = array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id');
$missing = array_diff(array_map('strval', array_keys($want)), $ids);
F004Check::that('AK4', $missing === [], 'ingen huller: alle CDR\'er er gemt (' . count($missing) . ' mangler: ' . implode(',', array_slice($missing, 0, 5)) . ')');
F004Check::that('AK4', count($ids) === count(array_unique($ids)) && count($ids) === count($want), 'ingen dubletter og intet ekstra');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND CAST(external_id AS UNSIGNED) BETWEEN 200000 AND 200149") === 150,
    '150 opkald i samme sekund er alle med');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND disposition = 'no-answer'") === 1000,
    'ubesvarede opkald er også gemt (med disposition)');
F004Check::that('AK4', Calls::adversusWarning($now + $runs) === null, 'ingen advarsel, når alt er hentet');
F004Check::that('AK4', (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0 && $http->to('/v1/recordings') === [],
    'ingen venteliste og ingen optagelsesliste');

$all = urldecode(implode("\n", array_column($http->requests, 'url')));
F004Check::that('AK4', !str_contains($all, '$gte'), 'ingen $gte i filtrene');

// 3. Sent logget opkald og ukendt retning (ny, lille datamængde)
f004_setup();
$http = new FakeAdversus();
$T = $S + 3600;
f004_call($http, 400001, 81001, $T, 60);
f004_call($http, 400003, 81003, $T + 200, 60, 'internal');   // ukendt retning
f004_call($http, 400004, 81004, $T + 1800, 60);
$t0 = $T + 2 * 3600;
['sync' => $a] = f004_sync(f004_cfg(), $http, $t0);
$ra = $a->run();
F004Check::that('AK4', $ra['skipped'] === 1 && str_contains((string) Calls::adversusWarning($t0), 'ukendt retning (id 400003)'),
    'ukendt retning står som synlig advarsel (sync_state)');
// Et langt opkald startede før cursoren, men dukker først op i /calls nu: den nylige kontrol (hver kørsel) henter det
f004_call($http, 400002, 81002, $T + 100, 5400);
$log = [];
['sync' => $b] = f004_sync(f004_cfg(), $http, $t0 + 600, $log);
$rb = $b->run();
F004Check::that('AK4', DB::scalar("SELECT id FROM calls WHERE external_id = '400002'") !== false && $rb['rescanned'] >= 1,
    'den nylige kontrol henter det sent loggede opkald ved næste kørsel');
F004Check::that('AK4', (bool) array_filter($log, static fn(string $l): bool => str_contains($l, 'nylig kontrol ' . Sync::iso($T) . ': Adversus har 4 opkald, gemt 2')),
    'kontrollen logger tallene (' . implode(' / ', $log) . ')');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'") === 3, 'ingen dubletter efter kontrollen');

// Et opkald, der først logges efter både den nylige kontrol og timens kontrol, findes af dagskontrollen (ikke tabt stille)
['sync' => $c] = f004_sync(f004_cfg(), $http, $T + 3600 + Sync::CHECK_SETTLE + 60);
$c->run();
f004_call($http, 400005, 81005, $T + 300, 60);
$found = false;
$dayLog = [];
for ($k = 0; $k < 6 && !$found; $k++) {
    ['sync' => $d] = f004_sync(f004_cfg(), $http, $S + 2 * 86400 + 600 + $k * 600, $dayLog);
    $d->run();
    $found = DB::scalar("SELECT id FROM calls WHERE external_id = '400005'") !== false;
}
F004Check::that('AK4', $found, 'dagskontrollen henter et opkald, der blev logget efter timens kontrol');
F004Check::that('AK4', (bool) array_filter($dayLog, static fn(string $l): bool => str_contains($l, 'dagskontrol ' . Sync::iso($S) . ':')),
    'dagskontrollen logger det (' . implode(' / ', array_slice($dayLog, 0, 4)) . ')');
F004Check::that('AK4', Calls::adversusWarning($t0 + 3 * 86400) === null, 'advarslen forsvinder efter et døgn');
F004Check::that('AK4', str_contains(Sync::logLine(['skipped' => 1]), '1 ukendt retning'), 'loglinjen tæller ukendt retning');
F004Check::done();
