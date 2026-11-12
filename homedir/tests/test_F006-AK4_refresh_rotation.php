<?php
declare(strict_types=1);

// F006-AK4: refresh roterer (nyt access og nyt refresh token ved hver brug), og genbrug af et gammelt refresh token
// tilbagekalder hele kæden. Udløbne access tokens afvises på /mcp; refresh med forkert klient afvises.

require_once __DIR__ . '/f006_lib.php';

f006_setup();
$s = f006_connected();
F006Check::that('AK4', f006_mcp_ok($s['access']), 'kontrol: access token virker');

$r1 = f006_refresh($s['client'], $s['refresh']);
$j1 = f006_json($r1);
$access2 = (string) ($j1['access_token'] ?? '');
$refresh2 = (string) ($j1['refresh_token'] ?? '');
F006Check::that('AK4', $r1->status === 200 && $access2 !== '' && $refresh2 !== '' && $refresh2 !== $s['refresh'] && $access2 !== $s['access'],
    'refresh: nyt access og nyt refresh token');
F006Check::that('AK4', f006_mcp_ok($access2), 'det nye access token virker');

$other = f006_register();
$r = f006_refresh($other, $refresh2);
F006Check::that('AK4', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_grant', 'refresh med en anden klient: invalid_grant');
F006Check::that('AK4', f006_mcp_ok($access2), 'en anden klients forsøg rører ikke kæden');

$r = f006_refresh($s['client'], \Whisper\OAuth\OAuth::random('wrt_'));
F006Check::that('AK4', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_grant', 'ukendt refresh token giver invalid_grant');

// Genbrug af det gamle (allerede roterede) refresh token
$reuse = f006_refresh($s['client'], $s['refresh']);
F006Check::that('AK4', $reuse->status === 400 && (f006_json($reuse)['error'] ?? '') === 'invalid_grant', 'genbrug af gammelt refresh token giver invalid_grant');
F006Check::that('AK4', !f006_mcp_ok($access2), 'genbrug: kædens access token er tilbagekaldt');
$r = f006_refresh($s['client'], $refresh2);
F006Check::that('AK4', $r->status === 400, 'genbrug: også kædens nyeste refresh token er dødt');

// Udløb: access efter 1 time
$t = f006_connected();
$m = new F003Mcp($t['access']);
$m->now = F003_NOW + 3601;
F006Check::that('AK4', $m->rpc('tools/list')->status === 401, 'access token efter 1 time: 401');
$r = f006_refresh($t['client'], $t['refresh'], F003_NOW + 3601);
F006Check::that('AK4', $r->status === 200, 'refresh efter 1 time: nyt par');
$late = f006_connected();
$r = f006_refresh($late['client'], $late['refresh'], F003_NOW + 31 * 86400);
F006Check::that('AK4', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_grant', 'refresh efter 31 dage: invalid_grant');

F006Check::done();

