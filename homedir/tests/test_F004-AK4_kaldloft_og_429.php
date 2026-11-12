<?php
declare(strict_types=1);

// F004-AK4: kaldloftet på 100 og 429 stopper pænt. Cursoren står ved sidst gemte data, og næste kørsel fortsætter uden
// huller og uden dubletter. Datamængden kræver flere kørsler (store vinduer halveres), og der er optagelser præcis på
// en vinduesgrænse og flere i samme sekund. Lange opkald (> 5 min og > 2 t, cdr.timestamp = sluttid) findes via det
// brede opslag eller ventelisten. Et opkald, der først dukker op i /calls senere, gemmes ved en senere kørsel; et, der
// aldrig dukker op, eller som har ukendt retning, forkastes synligt (advarsel), ikke lydløst (QA F-1).

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Sync;
use Whisper\Calls;
use Whisper\DB;

f004_setup();
DB::exec('DELETE FROM adversus_pending');
$S = f004_since();
$now = $S + 10 * 3600;
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
    f004_recording($http, $id, $S + 3000 + $k, $S + 3600);
    $want[$id] = true;
}
// Langt opkald (400 s > 5 min), cdr.timestamp = sluttid: uden for ±5 min, fundet af det brede opslag
f004_call($http, 300001, 61001, $S + 5000, 400);
f004_recording($http, 300001, $S + 5400, $S + 5430);
$want[300001] = true;
// Opkald på 2,5 t (> 2 t), cdr.timestamp = sluttid: uden for det brede opslag, fundet via ventelisten
f004_call($http, 300002, 61002, $S + 21100, 9000);
f004_recording($http, 300002, $S + 30100, $S + 30130);
$want[300002] = true;

/** Alle optagelser med upload før cursoren er gemt eller på ventelisten (intet tabt bag cursoren). */
$invariant = static function () use ($http): bool {
    $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['adversus-rec']), true);
    $t = is_array($rec) ? (int) $rec['t'] : 0;
    $saved = array_flip(array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id'));
    $pending = array_flip(array_map('strval', array_column(DB::all('SELECT cdr_id FROM adversus_pending'), 'cdr_id')));
    foreach ($http->recordings as $r) {
        $id = (string) $r['cdr']['id'];
        if (strtotime($r['timestamp']) < $t && !isset($saved[$id]) && !isset($pending[$id])) {
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

// 2. Kørsler med kaldloftet 100, til alt er hentet og ventelisten er tom
$runs = 0;
$maxReq = 0;
$okInv = true;
$caughtUp = false;
$sawPending = false;
for ($run = 1; $run <= 30; $run++) {
    $before = count($http->requests);
    ['sync' => $s] = f004_sync(f004_cfg(), $http, $now + $run);
    $s->run();
    $runs++;
    $maxReq = max($maxReq, count($http->requests) - $before);
    $okInv = $okInv && $invariant();
    $pendingN = (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending');
    $sawPending = $sawPending || $pendingN > 0;
    $rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['adversus-rec']), true);
    $audio = (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND transcript_status <> 'ikke_valgt' AND audio_status <> 'hentet'");
    if (is_array($rec) && $rec['t'] >= $now + $run - Sync::LAG && $audio === 0 && $pendingN === 0) {
        $caughtUp = true;
        break;
    }
}
echo "kørsler: $runs, største kørsel: $maxReq kald\n";
F004Check::that('AK4', $runs > 1, 'datamængden kræver flere kørsler (loftet nås)');
F004Check::that('AK4', $maxReq <= 100, 'ingen kørsel bruger over 100 kald (største: ' . $maxReq . ')');
F004Check::that('AK4', $okInv, 'efter hver kørsel er alt bag cursoren gemt eller på ventelisten');
F004Check::that('AK4', $sawPending, 'opkaldet på 2,5 t kom på ventelisten undervejs');
F004Check::that('AK4', $caughtUp, 'hentningen indhenter efterslæbet, og ventelisten tømmes');

$ids = array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus'"), 'external_id');
$missing = array_diff(array_map('strval', array_keys($want)), $ids);
F004Check::that('AK4', $missing === [], 'ingen huller: alle optagede opkald er gemt (' . count($missing) . ' mangler: ' . implode(',', array_slice($missing, 0, 5)) . ')');
F004Check::that('AK4', count($ids) === count(array_unique($ids)) && count($ids) === count($want), 'ingen dubletter og intet ekstra');
F004Check::that('AK4', in_array('300001', $ids, true) && in_array('300002', $ids, true), 'lange opkald (> 5 min og > 2 t) er gemt');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id >= '900000'") === 0,
    'opkald uden optagelse er aldrig gemt');
F004Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id LIKE '2000%'") === 5,
    'optagelser på vinduesgrænsen og i samme sekund er med');
F004Check::that('AK4', Calls::adversusWarning($now + $runs) === null, 'ingen advarsel, når alt er fundet');

// Filtrene bruger kun $gt/$lt (ingen $gte); det brede opslag søger nyeste først
$all = urldecode(implode("\n", array_column($http->requests, 'url')));
F004Check::that('AK4', !str_contains($all, '$gte'), 'ingen $gte i filtrene');
F004Check::that('AK4', str_contains($all, 'sortDirection=DESC'), 'det brede opslag søger nærmeste opkald først (DESC)');

// 3. Sent indekseret, aldrig fundet og ukendt retning (ny, lille datamængde)
f004_setup();
DB::exec('DELETE FROM adversus_pending');
$http = new FakeAdversus();
$T = $S + 3600;
f004_recording($http, 400001, $T, $T + 90);                    // opkaldet kommer først i /calls efter første kørsel
f004_recording($http, 400002, $T + 100, $T + 190);             // opkaldet findes aldrig
f004_recorded($http, 400003, 81003, $T + 200, 60, 'internal'); // ukendt retning
$t0 = $T + 3 * 3600;
['sync' => $a] = f004_sync(f004_cfg(), $http, $t0);
$ra = $a->run();
F004Check::that('AK4', $ra['unmatched'] === 2 && (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 2, 'umatchede kommer på ventelisten');
F004Check::that('AK4', $ra['skipped'] === 1 && str_contains((string) Calls::adversusWarning($t0), 'ukendt retning (id 400003)'),
    'ukendt retning står som synlig advarsel (sync_state)');
f004_call($http, 400001, 81001, $T - 30, 120);
for ($k = 1; $k <= Sync::RETRY_RUNS + 1; $k++) {
    ['sync' => $b] = f004_sync(f004_cfg(), $http, $t0 + $k * 600);
    $rb = $b->run();
    if ($k === 1) {
        F004Check::that('AK4', DB::scalar("SELECT id FROM calls WHERE external_id = '400001'") !== false, 'sent indekseret opkald er gemt ved næste kørsel');
    }
}
F004Check::that('AK4', (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0, 'ventelisten er tom efter ' . Sync::RETRY_RUNS . ' kørsler');
$warn = (string) Calls::adversusWarning($t0 + (Sync::RETRY_RUNS + 1) * 600);
F004Check::that('AK4', str_contains($warn, '1 optagelser uden opkald') && str_contains($warn, '400002'), 'den aldrig fundne er forkastet med synlig advarsel');
F004Check::that('AK4', Calls::adversusWarning($t0 + 3 * 86400) === null, 'advarslen forsvinder efter et døgn');
F004Check::that('AK4', str_contains(Sync::logLine(['discarded' => 1, 'skipped' => 1]), '1 forkastet, 1 ukendt retning'), 'loglinjen tæller forkastede');
F004Check::done();
