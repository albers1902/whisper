<?php
declare(strict_types=1);

// H004-AK2: når en admin nulstiller en adgangskode, og når en bruger selv skifter den, tilbagekaldes alle brugerens
// aktive MCP-nøgler (DECISIONS #24), og de giver derefter 401. Andre brugeres nøgler røres ikke.

require_once __DIR__ . '/h004_lib.php';

use Whisper\App;
use Whisper\DB;

f003_setup();
[$c, $uid] = f003_login();
$other = f001_user('anden@h004.test');
$otherToken = f003_token($other, 'Anden');

// 1. Brugeren skifter selv adgangskode på /konto
$t1 = f003_token($uid, 'Laptop');
$t2 = f003_token($uid, 'Stationær');
H004Check::that('AK2', (new F003Mcp($t1))->rpc('tools/list')->status === 200, 'før skiftet: nøglen virker');
$new = 'nyt-langt-kodeord-h004';
$r = $c->post('/konto', ['current' => F001_PW, 'new' => $new, 'confirm' => $new]);
H004Check::that('AK2', $r->status === 303, 'skift af adgangskode: redirect');
H004Check::that('AK2', (int) DB::scalar('SELECT COUNT(*) FROM mcp_tokens WHERE user_id = ? AND revoked_at IS NULL', [$uid]) === 0,
    'skift: alle brugerens nøgler er tilbagekaldt');
H004Check::that('AK2', (new F003Mcp($t1))->rpc('tools/list')->status === 401 && (new F003Mcp($t2))->rpc('tools/list')->status === 401,
    'skift: nøglerne giver 401');
$page = $c->get('/konto')->body;
H004Check::that('AK2', str_contains($page, 'Adgangskoden er skiftet.') && str_contains($page, App::KEYS_REVOKED_SELF),
    'skift: flash-teksten nævner nøglerne');
H004Check::that('AK2', (new F003Mcp($otherToken))->rpc('tools/list')->status === 200, 'en anden brugers nøgle virker stadig');

// Uden aktive nøgler nævner flash-teksten dem ikke
$newer = 'endnu-et-kodeord-h004';
$c->post('/konto', ['current' => $new, 'confirm' => $newer, 'new' => $newer]);
H004Check::that('AK2', !str_contains($c->get('/konto')->body, App::KEYS_REVOKED_SELF), 'uden nøgler: ingen tekst om nøgler');

// 2. En admin nulstiller brugerens adgangskode
$t3 = f003_token($uid, 'Efter skift');
f001_user('admin@h004.test', 'admin');
$admin = new F001Client('10.0.0.2');
$admin->login('admin@h004.test', F001_PW);
$reset = $admin->post('/brugere/' . $uid, ['action' => 'reset', 'bekraeftet' => '1']);
H004Check::that('AK2', $reset->status === 200 && str_contains($reset->body, App::KEYS_REVOKED_RESET),
    'nulstilling: siden med engangskoden nævner, at nøglerne er tilbagekaldt');
H004Check::that('AK2', DB::scalar('SELECT revoked_at FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $t3)]) !== null
    && (new F003Mcp($t3))->rpc('tools/list')->status === 401, 'nulstilling: nøglen er tilbagekaldt og giver 401');
H004Check::that('AK2', (new F003Mcp($otherToken))->rpc('tools/list')->status === 200, 'nulstilling: en anden brugers nøgle virker stadig');

H004Check::done();
