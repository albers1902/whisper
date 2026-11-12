<?php
declare(strict_types=1);

// H013-AK1: alle CDR'er fra /calls gemmes i calls, også ubesvarede og opkald uden succes, med disposition. Hentningen er
// inkrementel: cursoren gemmes efter hver side, en backfill over flere dage går dag for dag inden for kaldloftet, kan
// genoptages og giver ingen dubletter. Der hentes ingen optagelsesliste, og intet lægges på en venteliste.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h013_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

f004_setup();
$S = f004_since();
$http = new FakeAdversus();
f004_call($http, 1, 11, $S + 600, 90);                                    // succes, besvaret
f004_call($http, 2, 11, $S + 700, 0, 'outbound', 77, 'no-answer');       // succes, ikke besvaret
f004_call($http, 3, 12, $S + 800, 60);                                    // ikke succes
f004_call($http, 4, 12, $S + 900, 0, 'outbound', 77, 'busy');            // ikke succes, optaget
f004_lead($http, 11, 'success', $S + 60);
f004_lead($http, 12, 'notInterested', $S + 60);
['sync' => $sync] = f004_sync(f004_cfg(), $http, $S + 3 * 3600);
$r = $sync->run();
$rows = [];
foreach (DB::all("SELECT * FROM calls WHERE `source` = 'adversus' ORDER BY external_id") as $row) {
    $rows[(int) $row['external_id']] = $row;
}
H013Check::that('AK1', array_keys($rows) === [1, 2, 3, 4], 'alle fire CDR\'er er gemt, også ubesvarede og uden succes');
H013Check::that('AK1', $rows[2]['disposition'] === 'no-answer' && $rows[4]['disposition'] === 'busy' && $rows[1]['disposition'] === 'answered',
    'dispositionen gemmes');
H013Check::that('AK1', (int) $rows[1]['is_success'] === 1 && (int) $rows[2]['is_success'] === 1 && (int) $rows[3]['is_success'] === 0,
    'succes afgøres af leadets status');
H013Check::that('AK1', $http->to('/v1/recordings') === [] && (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0,
    'ingen optagelsesliste og ingen venteliste');
H013Check::that('AK1', $r['error'] === null && $r['cdr'] === 4 && $r['new'] === 4, 'kørslen er ok: 4 cdr, 4 nye');

// Backfill over tre dage med et lavt kaldloft: dag for dag, genoptages efter hver kørsel, ingen dubletter
f004_setup();
$http = new FakeAdversus();
$n = 0;
for ($d = 0; $d < 3; $d++) {
    for ($i = 0; $i < 400; $i++) {
        f004_call($http, 50000 + $n, 900, $S + $d * 86400 + 8 * 3600 + $i * 60, 60);
        $n++;
    }
}
$now = $S + 3 * 86400 + 3600;
$log = [];
$days = [];
$maxReq = 0;
for ($k = 0; $k < 20; $k++) {
    ['sync' => $s] = f004_sync(f004_cfg(), $http, $now + $k * 600, $log, 36);
    $rk = $s->run();
    $maxReq = max($maxReq, $rk['requests']);
    $days[] = $rk['day'];
    echo Sync::logLine($rk), "\n";
    if ((int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'") >= $n) {
        break;
    }
}
$saved = (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'");
$distinct = (int) DB::scalar("SELECT COUNT(DISTINCT external_id) FROM calls WHERE `source` = 'adversus'");
H013Check::that('AK1', $saved === $n && $distinct === $n, 'alle ' . $n . ' CDR\'er er gemt én gang (fik ' . $saved . ')');
H013Check::that('AK1', count($days) >= 3 && $maxReq <= 36, 'backfill over flere kørsler inden for loftet (' . count($days) . ' kørsler, max ' . $maxReq . ' kald)');
$sorted = $days;
sort($sorted);
H013Check::that('AK1', $days === $sorted && count(array_unique($days)) >= 3, 'loglinjens dag går frem dag for dag (' . implode(', ', array_unique($days)) . ')');
$cur = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_CDR]), true);
H013Check::that('AK1', is_array($cur) && isset($cur['t'], $cur['p'], $cur['h']), 'cursoren står i sync_state (t, p, h)');

// En ny kørsel uden nye opkald: én side fra cursoren (kontrollen tæller kun totaler med pageSize=1), intet nyt
$pages = static fn(): int => count(array_filter($http->to('/v1/calls?'), static fn(string $u): bool => str_contains($u, 'pageSize=100')));
$before = $pages();
['sync' => $s] = f004_sync(f004_cfg(), $http, $now + 86400);
$rr = $s->run();
H013Check::that('AK1', $rr['new'] === 0 && $pages() - $before === 1 && $rr['rescanned'] === 0,
    'ingen nye: én side fra cursoren, ingen timer hentet igen (' . ($pages() - $before) . ' sider)');
H013Check::done();
