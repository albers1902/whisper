<?php
declare(strict_types=1);

// H013-AK4: første kørsel efter udrulningen tømmer ventelisten fra det gamle optagelses-flow og fjerner den forældede
// advarsel (den gule boks), skriver markøren og én loglinje; næste kørsel gør det ikke igen. En ny advarsel (ukendt
// retning) har de nyeste id'er først, så afkortningen til 250 tegn aldrig skjuler det nye.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h013_lib.php';

use Whisper\Adversus\Sync;
use Whisper\Calls;
use Whisper\DB;

f004_setup();
DB::exec('DELETE FROM sync_state WHERE `source` = ?', [Sync::MIGRATED]);   // som på DEV før H013
$S = f004_since();
$now = $S + 20 * 3600;
$old = 'advarsel: 314 optagelser forkastet, fordi ventelisten er fuld (2000) (id 1497432196, 1497432806, …) — ikke gemt | '
    . str_repeat('x', 200);
DB::exec('INSERT INTO sync_state (`source`, last_run_at, last_error) VALUES (?, ?, ?)', [Sync::WARN, gmdate('Y-m-d H:i:s', $now - 600), mb_substr($old, 0, 250)]);
DB::exec('INSERT INTO sync_state (`source`, cursor_value) VALUES (?, ?)', ['adversus-rec', '{"t":1,"w":3600}']);
for ($k = 0; $k < 3; $k++) {
    DB::exec('INSERT INTO adversus_pending (cdr_id, cdr_at, tries, first_seen) VALUES (?, NULL, 0, ?)', [900 + $k, gmdate('Y-m-d H:i:s', $now - 7200)]);
}
H013Check::that('AK4', Calls::adversusWarning($now) !== null, '(før: den gamle advarsel vises)');

$http = new FakeAdversus();
$log = [];
['sync' => $sync] = f004_sync(f004_cfg(), $http, $now, $log);
$sync->run();
$line = 'adversus-sync: H013: ventelisten (3 optagelser) og den gamle advarsel er ryddet — CDR\'er hentes fra ' . Sync::iso($S);
H013Check::that('AK4', in_array($line, $log, true), 'én loglinje for oprydningen (' . implode(' / ', $log) . ')');
H013Check::that('AK4', (int) DB::scalar('SELECT COUNT(*) FROM adversus_pending') === 0, 'ventelisten er tom');
H013Check::that('AK4', Calls::adversusWarning($now) === null, 'den forældede advarsel er væk');
H013Check::that('AK4', DB::scalar("SELECT COUNT(*) FROM sync_state WHERE `source` = 'adversus-rec'") == 0, 'den gamle optagelses-cursor er fjernet');
H013Check::that('AK4', DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::MIGRATED]) === 'done', 'markøren er skrevet');

$log2 = [];
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600, $log2);
$sync2->run();
H013Check::that('AK4', array_filter($log2, static fn(string $l): bool => str_contains($l, 'H013:')) === [] && $sync2->migrate() === false,
    'anden kørsel rydder ikke igen');

// Ukendt retning: nyeste først; afkortningen fjerner de ældste (7001 ligger på cursoren og tælles igen ved overlappet)
f004_call($http, 7001, 1, $now - 1800, 60, 'internal');
['sync' => $s3] = f004_sync(f004_cfg(), $http, $now + 1200);
$s3->run();
for ($k = 0; $k < 12; $k++) {
    f004_call($http, 7100 + $k, 1, $now + 600 + $k, 60, 'internal');
}
['sync' => $s4] = f004_sync(f004_cfg(), $http, $now + 2400);
$s4->run();
$w = (string) Calls::adversusWarning($now + 2400);
H013Check::that('AK4', str_starts_with($w, 'advarsel: ' . gmdate('Y-m-d H:i:s', $now + 2400) . ' 13 opkald med ukendt retning (id 7001, 7100'),
    'den nyeste advarsel står først (' . $w . ')');
H013Check::that('AK4', mb_strlen($w) <= 250 && !str_contains($w, 'optagelser forkastet') && !str_contains($w, 'ventelisten'),
    'højst 250 tegn, og intet om ventelisten');

// Oprydningen retter de kopierede succes-leads til en anden success_status (QA L6; SQL i 0009 kender ikke config)
f004_setup();
DB::exec('DELETE FROM sync_state WHERE `source` = ?', [Sync::MIGRATED]);
DB::exec('INSERT INTO adversus_success_leads (lead_id, last_updated_at, seen_at) VALUES (61, NULL, ?)', [gmdate('Y-m-d H:i:s', $now)]);
DB::exec("INSERT INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at) VALUES (61, 'success', NULL, ?), (62, 'success', NULL, ?)",
    [gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now)]);
$won = new \Whisper\Adversus\AdversusConfig('apiuser' . bin2hex(random_bytes(4)), 'pw-' . bin2hex(random_bytes(12)), $S, [F004_AUDIO_HOST], 'won');
$client = new \Whisper\Adversus\Client($won, new FakeAdversus(), static function (int $s): void {
}, Sync::MAX_REQUESTS);
(new Sync($client, $won, static fn(): int => $now, static function (string $l): void {
}))->run();
H013Check::that('AK4', DB::scalar('SELECT `status` FROM adversus_leads WHERE lead_id = 61') === 'won'
    && DB::scalar('SELECT `status` FROM adversus_leads WHERE lead_id = 62') === 'success',
    'kopierede succes-leads får den konfigurerede success_status; andre røres ikke');
H013Check::done();
