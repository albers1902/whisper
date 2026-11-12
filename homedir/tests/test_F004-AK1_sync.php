<?php
declare(strict_types=1);

// F004-AK1: med fake-transport (fixtures fra /calls og /leads) gemmer sync opkaldene med korrekt kilde, lead og varighed,
// og flytter cursorerne frem. En ny kørsel giver ingen dubletter. Højst 100 kald. Fra H013 gemmes ALLE CDR'er (også
// dem uden optagelse), og /recordings bruges ikke.

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 6 * 3600;
$http = new FakeAdversus();
$http->users[] = ['id' => 77, 'name' => 'Anne Adversus', 'email' => 'anne@example.test', 'phone' => '+4511111111'];
f004_recorded($http, 1001, 501, $S + 3600, 95, 'outbound');
f004_recorded($http, 1002, 502, $S + 2 * 3600, 240, 'inbound');
f004_recorded($http, 1003, 503, $S + 4 * 3600 + 17, 61, 'outbound');
f004_call($http, 1004, 501, $S + 3600 + 300);     // uden optagelse
f004_call($http, 1005, 504, $S + 3 * 3600);       // uden optagelse
f004_lead($http, 501, 'success', $S + 1800);

$log = [];
['sync' => $sync] = f004_sync(f004_cfg(), $http, $now, $log);
$r = $sync->run();
echo implode("\n", $log), "\n", Sync::logLine($r), "\n";

$rows = DB::all("SELECT * FROM calls WHERE `source` = 'adversus' ORDER BY external_id");
F004Check::that('AK1', array_column($rows, 'external_id') === ['1001', '1002', '1003', '1004', '1005'], 'alle fem CDR-poster er gemt (H013)');
F004Check::that('AK1', $http->to('/v1/recordings') === [], 'optagelseslisten bruges ikke (H013)');
$a = $rows[0];
F004Check::that('AK1', $a['source'] === 'adversus' && (int) $a['lead_id'] === 501 && (int) $a['campaign_id'] === 31, 'kilde, lead og kampagne');
F004Check::that('AK1', (int) $a['duration_sec'] === 95 && $a['direction'] === 'outbound', 'varighed og retning (origin)');
F004Check::that('AK1', $a['started_at'] === gmdate('Y-m-d H:i:s', $S + 3600), 'start i UTC');
F004Check::that('AK1', $rows[1]['direction'] === 'inbound' && (int) $rows[1]['duration_sec'] === 240, 'andet opkald: indgående, 240 s');
F004Check::that('AK1', $a['recording_url'] === '' && $a['ticket_id'] === null, 'ingen optagelses-URL og ingen ticket');
F004Check::that('AK1', $a['customer_phone'] === '+45 12 34 56 78', 'nummeret gemt og renset som for Zendesk');
F004Check::that('AK1', $a['agent_name'] === 'Anne Adversus' && $a['agent_ext_id'] === '77', 'agentnavn fra /users');
$users = DB::all('SELECT * FROM adversus_users');
F004Check::that('AK1', count($users) === 1 && array_keys($users[0]) === ['user_id', 'name', 'updated_at'], 'kun id og navn gemmes om brugeren');

$rec = json_decode((string) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_CDR]), true);
F004Check::that('AK1', is_array($rec) && $rec['t'] === $S + 4 * 3600 + 17, 'CDR-cursoren står ved seneste start');
$leadCursor = (int) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_LEADS]);
F004Check::that('AK1', $leadCursor === $S + 1800, 'lead-cursoren står ved sidste lastUpdatedTime');
F004Check::that('AK1', DB::scalar("SELECT last_ok_at FROM sync_state WHERE `source` = 'adversus'") !== null && $r['error'] === null,
    'kørslen er ok (last_ok_at sat)');
F004Check::that('AK1', $r['requests'] <= 100 && Sync::MAX_REQUESTS === 100, 'højst 100 kald (fik ' . $r['requests'] . ')');
F004Check::that('AK1', $r['cdr'] === 5 && $r['new'] === 5, 'loglinjens tal: 5 cdr, 5 nye');
F004Check::that('AK1', str_starts_with(Sync::logLine($r),
    'adversus-sync: 5 cdr (5 nye, dag 2026-11-12), 1 leads, 2 succes, lyd 2 hentet/0 uden optagelse/0 fejlet, kontrol 3 t (0 igen) · kald '),
    'loglinjen har tal for hver fase (fik ' . Sync::logLine($r) . ')');

// Ny kørsel senere: intet nyt, ingen dubletter
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 1200);
$r2 = $sync2->run();
F004Check::that('AK1', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'") === 5 && $r2['new'] === 0, 'ingen dubletter ved ny kørsel');

// Ingen hemmeligheder eller signerede URL'er i loggen
$all = implode("\n", $log) . Sync::logLine($r);
F004Check::that('AK1', !str_contains($all, 'X-Amz') && !str_contains($all, 'Anne') && !str_contains($all, '+45'), 'loggen har kun id\'er og tal');
F004Check::done();
