<?php
declare(strict_types=1);

// F006-AK6: tilbagekald på Konto, ny adgangskode og fjernet (deaktiveret) bruger gør tokens ugyldige med det samme.
// Konto viser kun "Forbind Claude" med forbundne apps (DECISIONS #48); en andens bevilling kan ikke tilbagekaldes.

require_once __DIR__ . '/f006_lib.php';

use Whisper\App;
use Whisper\DB;
use Whisper\Users;

f006_setup();

// Konto: forbundne apps og tilbagekald
$s = f006_connected();
$c = $s['c'];
$konto = $c->get('/konto')->body;
F006Check::that('AK6', str_contains($konto, 'Forbind Claude') && str_contains($konto, 'Forbundne apps (1)') && str_contains($konto, 'Claude'),
    'Konto viser Forbind Claude og én forbundet app');
F006Check::that('AK6', !str_contains($konto, 'mcp-remote') && !str_contains($konto, 'Opret nøgle') && !str_contains($konto, '/konto/mcp-noegle'),
    'ingen nøgler og ingen mcp-remote med OAuth tændt');
F006Check::that('AK6', str_contains($konto, 'Customize → Connectors') && str_contains($konto, 'Add custom connector'), 'Claude\'s menunavne');
// v2 (QA F6a): en ny forbindelse til samme callback erstatter den gamle i stedet for at stå ved siden af
$again = f006_code($c, $s['client']);
$t2 = f006_json(f006_token($s['client'], $again['code'], $again['verifier']));
F006Check::that('AK6', str_contains($c->get('/konto')->body, 'Forbundne apps (1)'), 'ny forbindelse: stadig én række');
F006Check::that('AK6', !f006_mcp_ok($s['access']) && f006_mcp_ok((string) ($t2['access_token'] ?? '')), 'den gamle bevilling er tilbagekaldt');
$s['access'] = (string) ($t2['access_token'] ?? '');
$s['refresh'] = (string) ($t2['refresh_token'] ?? '');
$grant = (int) DB::scalar('SELECT id FROM oauth_grants WHERE user_id = ? AND revoked_at IS NULL', [$s['uid']]);

// En anden bruger kan ikke tilbagekalde den
f001_user('anden@f006.test', 'user');
$other = new F001Client('10.0.0.2');
$other->login('anden@f006.test', F001_PW);
$r = $other->post('/konto/app/' . $grant . '/tilbagekald');
F006Check::that('AK6', $r->status === 303 && f006_mcp_ok($s['access']), 'en andens bevilling: intet tilbagekaldt');
F006Check::that('AK6', str_contains($other->get('/konto')->body, App::APP_ALREADY_REVOKED), 'samme svar som allerede tilbagekaldt');

$r = $c->post('/konto/app/' . $grant . '/tilbagekald');
F006Check::that('AK6', $r->status === 303 && $r->header('Location') === '/konto', 'tilbagekald: redirect til /konto');
F006Check::that('AK6', !f006_mcp_ok($s['access']), 'tilbagekaldt: access token afvist med det samme');
F006Check::that('AK6', f006_refresh($s['client'], $s['refresh'])->status === 400, 'tilbagekaldt: refresh afvist');
$page = $c->get('/konto')->body;
F006Check::that('AK6', str_contains($page, 'er tilbagekaldt. Appen kan ikke længere læse dine transcripts') && str_contains($page, 'Forbundne apps (0)'),
    'kvittering, og listen er tom');
$r = $c->post('/konto/app/' . $grant . '/tilbagekald');
F006Check::that('AK6', str_contains($c->get('/konto')->body, App::APP_ALREADY_REVOKED), 'dobbeltklik: allerede tilbagekaldt');
F006Check::that('AK6', $c->post('/konto/app/' . $grant . '/tilbagekald', [], false)->status === 403, 'tilbagekald uden CSRF: 403');

// Ny adgangskode (eget skift)
$s = f006_connected();
$c = $s['c'];
$c->post('/konto', ['current' => F001_PW, 'new' => 'en-helt-ny-adgangskode', 'confirm' => 'en-helt-ny-adgangskode']);
F006Check::that('AK6', !f006_mcp_ok($s['access']), 'skift af adgangskode: access token afvist');
F006Check::that('AK6', f006_refresh($s['client'], $s['refresh'])->status === 400, 'skift af adgangskode: refresh afvist');
F006Check::that('AK6', str_contains($c->get('/konto')->body, App::APP_ALREADY_REVOKED) === false
    && str_contains($c->get('/konto')->body, 'Forbundne apps (0)'), 'ingen forbundne apps bagefter');

// v2 (UX 2): med flaget tændt nævner beskeden kun forbundne apps, og kun når nogen faktisk blev tilbagekaldt
[$c3, $uid3] = f003_login();
f003_token($uid3);   // kun en gammel F003-nøgle, som er usynlig med flaget tændt
$c3->post('/konto', ['current' => F001_PW, 'new' => 'en-helt-ny-adgangskode', 'confirm' => 'en-helt-ny-adgangskode']);
$page = $c3->get('/konto')->body;
F006Check::that('AK6', str_contains($page, 'Adgangskoden er skiftet.') && !str_contains($page, App::APPS_REVOKED_SELF)
    && !str_contains($page, App::KEYS_REVOKED_SELF), 'kun en gammel nøgle: ingen tekst om tilbagekaldte apps');

// Nulstilling af en administrator
$s = f006_connected();
f001_user('admin@f006.test', 'admin');
$admin = new F001Client('10.0.0.3');
$admin->login('admin@f006.test', F001_PW);
$reset = $admin->post('/brugere/' . $s['uid'], ['action' => 'reset', 'bekraeftet' => '1']);
F006Check::that('AK6', !f006_mcp_ok($s['access']), 'nulstilling: access token afvist');
F006Check::that('AK6', str_contains($reset->body, App::APPS_REVOKED_RESET), 'nulstilling: teksten nævner forbundne apps');

// Fjernet (deaktiveret) bruger: med det samme, og også efter genaktivering
$s = f006_connected();
f001_user('chef@f006.test', 'admin');
$admin = new F001Client('10.0.0.4');
$admin->login('chef@f006.test', F001_PW);
F006Check::that('AK6', str_contains($admin->get('/brugere/' . $s['uid'] . '/deaktiver')->body, 'forbundne apps holder op med at virke'),
    'bekræftelsessiden nævner forbundne apps');
$admin->post('/brugere/' . $s['uid'], ['action' => 'deactivate', 'bekraeftet' => '1']);
F006Check::that('AK6', !f006_mcp_ok($s['access']), 'deaktiveret: access token afvist med det samme');
F006Check::that('AK6', f006_refresh($s['client'], $s['refresh'])->status === 400, 'deaktiveret: refresh afvist');
Users::setActive($s['uid'], true, 0, F003_NOW);
F006Check::that('AK6', !f006_mcp_ok($s['access']), 'genaktiveret: de gamle tokens virker stadig ikke');

F006Check::done();
