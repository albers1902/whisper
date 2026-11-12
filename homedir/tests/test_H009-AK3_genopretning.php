<?php
declare(strict_types=1);

// H009-AK3: første kørsel efter udrulningen sætter optagelses-cursoren tilbage til sync_since, tømmer ventelisten og
// skriver markøren; de forkastede og ventende optagelser hentes igen. Næste kørsel gør det ikke igen. Opkald, der
// allerede er gemt, gemmes ikke igen.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h009_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

f004_setup();
DB::exec('DELETE FROM sync_state WHERE `source` = ?', [Sync::RECOVERY]);   // som på DEV før H009
DB::exec('DELETE FROM adversus_pending');
$S = f004_since();
$now = $S + 20 * 3600;
$http = new FakeAdversus();
for ($k = 1; $k <= 5; $k++) {
    f004_recorded($http, 7000 + $k, 800 + $k, $S + 2 * 3600 + $k * 600, 90);
}
f004_insert(7001, false, 'ikke_valgt');   // allerede gemt før genopretningen
// Tilstanden efter F004 uden H009: cursoren er langt fremme, to ventende og én, der aldrig findes
DB::exec('INSERT INTO sync_state (`source`, cursor_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value)',
    [Sync::CURSOR_REC, (string) json_encode(['t' => $now - 3600, 'w' => 3600])]);
foreach ([7002, 7003, 99999] as $id) {
    DB::exec('INSERT INTO adversus_pending (cdr_id, cdr_at, tries, first_seen) VALUES (?, ?, 5, ?)',
        [$id, gmdate('Y-m-d H:i:s', $S + 3 * 3600), gmdate('Y-m-d H:i:s', $now - 7200)]);
}

$log = [];
['sync' => $sync] = f004_sync(f004_cfg(), $http, $now, $log);
$r = $sync->run();
echo implode("\n", $log), "\n", Sync::logLine($r), "\n";
$line = 'adversus-sync: genopretning (H009): optagelses-cursoren er sat tilbage til ' . Sync::iso($S)
    . ', 3 ventende fjernet — optagelser fra sync_since hentes igen';
H009Check::that('AK3', in_array($line, $log, true), 'loglinjen for genopretningen');
H009Check::that('AK3', DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::RECOVERY]) === 'done', 'markøren er skrevet');
$ids = array_map('intval', array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus' ORDER BY external_id"), 'external_id'));
H009Check::that('AK3', $ids === [7001, 7002, 7003, 7004, 7005], 'alle fem optagelser fra sync_since er hentet igen (fik ' . implode(',', $ids) . ')');
H009Check::that('AK3', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id = '7001'") === 1,
    'det allerede gemte opkald er ikke gemt igen');
H009Check::that('AK3', (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0, 'ventelisten er tømt (også den, der aldrig findes)');
H009Check::that('AK3', $r['error'] === null && $r['requests'] <= Sync::MAX_REQUESTS, 'kørslen er ok inden for loftet');
$cursor = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_REC]);
$rec = json_decode((string) $cursor, true);
H009Check::that('AK3', is_array($rec) && $rec['t'] === $now - Sync::LAG, 'cursoren er nået frem til nu − lag igen');

// Næste kørsel: ingen ny genopretning
$log2 = [];
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600, $log2);
$r2 = $sync2->run();
H009Check::that('AK3', array_filter($log2, static fn(string $l): bool => str_contains($l, 'genopretning')) === [], 'anden kørsel genopretter ikke');
$rec2 = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_REC]), true);
H009Check::that('AK3', is_array($rec2) && $rec2['t'] >= $rec['t'] && $r2['new'] === 0, 'cursoren går ikke tilbage, intet nyt gemt');
H009Check::that('AK3', $sync2->recover() === false, 'recover() gør intet, når markøren findes');

H009Check::done();
