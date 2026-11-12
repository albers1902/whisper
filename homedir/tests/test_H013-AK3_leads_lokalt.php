<?php
declare(strict_types=1);

// H013-AK3: leads gemmes lokalt med status (alle statusser, ikke kun success) via /leads med lastUpdatedTime efter
// cursoren. Et statusskift fanges af næste kørsel. Kun id, status og tid gemmes; status renses for andet end en
// enum-værdi. Succes-leads fra før H013 (adversus_success_leads) er kopieret ind af migrationen.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h013_lib.php';

use Whisper\Adversus\Sync;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 3 * 3600;
$http = new FakeAdversus();
f004_lead($http, 41, 'success', $S + 60);
f004_lead($http, 42, 'notInterested', $S + 120);
f004_lead($http, 43, 'new', $S + 180);
f004_lead($http, 44, 'Kunde <Jensen> ringer igen', $S + 240);   // ikke en enum-værdi
f004_lead($http, 45, 'success', $S - 60);                          // før since
['sync' => $sync] = f004_sync(f004_cfg(), $http, $now);
$r = $sync->run();
$status = static function (): array {
    $out = [];
    foreach (DB::all('SELECT lead_id, `status` FROM adversus_leads ORDER BY lead_id') as $l) {
        $out[(int) $l['lead_id']] = $l['status'];
    }
    return $out;
};
$st = $status();
H013Check::that('AK3', array_keys($st) === [41, 42, 43, 44], 'alle leads efter since er gemt, uanset status (fik ' . implode(',', array_keys($st)) . ')');
H013Check::that('AK3', $st[41] === 'success' && $st[42] === 'notInterested' && $st[43] === 'new', 'status gemmes');
H013Check::that('AK3', $st[44] === 'KundeJensenringerigen' && !str_contains((string) json_encode($st), ' '), 'fritekst i status renses (kun bogstaver/tal)');
H013Check::that('AK3', array_keys(DB::all('SELECT * FROM adversus_leads LIMIT 1')[0]) === ['lead_id', 'status', 'last_updated_at', 'synced_at'],
    'kun id, status og tider gemmes om leadet');
H013Check::that('AK3', $r['leads'] === 4 && (int) DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [Sync::CURSOR_LEADS]) === $S + 240,
    'lead-cursoren står ved sidste lastUpdatedTime');
$filters = urldecode(implode("\n", $http->to('/v1/leads')));
H013Check::that('AK3', str_contains($filters, 'lastUpdatedTime') && !str_contains($filters, '"status"'), 'filteret er lastUpdatedTime, ikke status');

// Statusskift fanges inkrementelt; kun de ændrede leads (og overlappet på 1 s) hentes
f004_lead($http, 42, 'success', $now + 100);
f004_lead($http, 41, 'notInterested', $now + 100);
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600);
$r2 = $sync2->run();
$st = $status();
H013Check::that('AK3', $st[42] === 'success' && $st[41] === 'notInterested' && $r2['leads'] === 3,
    'statusskift fanges ved næste kørsel (de 2 ændrede + 1 s overlap på cursoren; fik ' . $r2['leads'] . ')');

// Migrationen kopierede kendte succes-leads (her: prøvet direkte med samme SQL på en tom tabel)
DB::exec('DELETE FROM adversus_leads');
DB::exec('INSERT INTO adversus_success_leads (lead_id, last_updated_at, seen_at) VALUES (46, NULL, ?)', [gmdate('Y-m-d H:i:s', $now)]);
DB::exec("INSERT IGNORE INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at) SELECT lead_id, 'success', last_updated_at, seen_at FROM adversus_success_leads");
H013Check::that('AK3', $status() === [46 => 'success'], 'succes-leads fra før H013 kopieres som success');
H013Check::done();
