<?php
declare(strict_types=1);

// F003-AK6: en nøgle oprettes på /konto (CSRF), vises én gang, gemmes kun som hash med prefix, højst 5 aktive. Den kan
// tilbagekaldes, og en tilbagekaldt nøgle virker ikke længere.

require_once __DIR__ . '/f003_lib.php';

use Whisper\DB;
use Whisper\Mcp\Tokens;

f003_setup();
[$c, $uid] = f003_login();

$page = $c->get('/konto');
F003Check::that('AK6', str_contains($page->body, 'Claude Desktop (MCP)') && str_contains($page->body, 'Dine nøgler (0 af 5)')
    && str_contains($page->body, 'Du har ingen nøgler endnu.'), 'sektionen vises, tom tilstand');

$r = $c->post('/konto/mcp-noegle', ['name' => 'Claude Desktop – laptop']);
preg_match('/id="mcp-noegle" type="text" readonly value="(wsp_[A-Za-z0-9_-]{43})"/', $r->body, $mm);
$token = $mm[1] ?? '';
F003Check::that('AK6', $r->status === 200 && $token !== '', 'oprettet: nøglen vises i et readonly-felt (200, ikke redirect)');
F003Check::that('AK6', str_contains($r->body, '✓ Nøglen «Claude Desktop – laptop» er oprettet')
    && str_contains($r->body, 'Kopiér nøglen nu. Den vises kun denne ene gang.'), 'ok-kortet');
F003Check::that('AK6', str_contains($r->body, 'mcp-remote@0.15.2') && str_contains($r->body, '&quot;--transport&quot;,')
    && str_contains($r->body, 'Authorization:${WHISPER_AUTH}') && str_contains($r->body, 'Bearer ' . $token)
    && str_contains($r->body, '%APPDATA%\\Claude\\claude_desktop_config.json')
    && str_contains($r->body, '~/Library/Application Support/Claude/claude_desktop_config.json'),
    'konfigurationsblok med låst mcp-remote, header via env og stierne på Windows og macOS');
F003Check::that('AK6', $r->header('Cache-Control') === 'no-store', 'svaret med nøglen caches ikke');
F003Check::that('AK6', !str_contains($r->body, '<script') && !str_contains($r->body, 'style="'), 'intet inline script eller style');

$row = DB::all('SELECT * FROM mcp_tokens WHERE user_id = ?', [$uid])[0] ?? [];
F003Check::that('AK6', ($row['token_hash'] ?? '') === hash('sha256', $token) && ($row['prefix'] ?? '') === substr($token, 0, 8),
    'gemt som SHA-256 og 8 tegns prefix');
$plainInDb = false;
foreach ($row as $v) {
    $plainInDb = $plainInDb || (is_string($v) && str_contains($v, substr($token, 8)));
}
F003Check::that('AK6', !$plainInDb, 'klarteksten findes ikke i databasen');
F003Check::that('AK6', strlen(base64_decode(strtr(substr($token, 4), '-_', '+/'))) === 32, '32 tilfældige bytes efter wsp_');

$again = $c->get('/konto');
F003Check::that('AK6', !str_contains($again->body, $token) && !str_contains($again->body, substr($token, 8)), 'nøglen vises ikke igen');
F003Check::that('AK6', str_contains($again->body, 'Dine nøgler (1 af 5)') && str_contains($again->body, substr($token, 0, 8) . '…')
    && str_contains($again->body, 'Aldrig'), 'listen viser navn, prefix og "Aldrig" brugt');

$m = new F003Mcp($token);
F003Check::that('AK6', $m->rpc('tools/list')->status === 200, 'den nye nøgle virker på /mcp');
F003Check::that('AK6', DB::scalar('SELECT last_used_at FROM mcp_tokens WHERE user_id = ?', [$uid]) !== null, 'last_used_at sat ved brug');

// Navn: tomt og for langt
$e = $c->post('/konto/mcp-noegle', ['name' => '   ']);
F003Check::that('AK6', $e->status === 422 && str_contains($e->body, 'Skriv et navn til nøglen.') && str_contains($e->body, 'aria-invalid="true"'),
    'tomt navn: fejl og aria-invalid');
$e2 = $c->post('/konto/mcp-noegle', ['name' => str_repeat('x', 81)]);
F003Check::that('AK6', $e2->status === 422 && str_contains($e2->body, 'Navnet må højst være 80 tegn.'), 'over 80 tegn: fejl');
F003Check::that('AK6', !preg_match('/wsp_[A-Za-z0-9_-]{43}/', $e->body . $e2->body), 'ingen nøgle i fejlsvar');

// CSRF
$noCsrf = $c->post('/konto/mcp-noegle', ['name' => 'Uden CSRF'], false);
F003Check::that('AK6', $noCsrf->status === 403 && (int) DB::scalar('SELECT COUNT(*) FROM mcp_tokens WHERE user_id = ?', [$uid]) === 1,
    'uden CSRF-token: 403 og ingen nøgle');

// Højst 5 aktive
for ($i = 2; $i <= 5; $i++) {
    $c->post('/konto/mcp-noegle', ['name' => 'Nøgle ' . $i]);
}
$full = $c->get('/konto');
F003Check::that('AK6', str_contains($full->body, 'Dine nøgler (5 af 5)') && str_contains($full->body, Tokens::FULL)
    && !str_contains($full->body, 'action="/konto/mcp-noegle"'), '5 aktive: formularen er erstattet af forklaringen');
$six = $c->post('/konto/mcp-noegle', ['name' => 'Nummer seks']);
F003Check::that('AK6', $six->status === 422 && (int) DB::scalar('SELECT COUNT(*) FROM mcp_tokens WHERE user_id = ? AND revoked_at IS NULL', [$uid]) === 5,
    'en sjette afvises');

// Tilbagekald
$tid = (int) DB::scalar('SELECT id FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
$rv = $c->post('/konto/mcp-noegle/' . $tid . '/tilbagekald');
F003Check::that('AK6', $rv->status === 303 && $rv->header('Location') === '/konto', 'tilbagekald: redirect til /konto');
$after = $c->get('/konto');
F003Check::that('AK6', str_contains($after->body, 'Nøglen «Claude Desktop – laptop» er tilbagekaldt. Claude Desktop med den nøgle har ikke længere adgang.'),
    'flash med nøglens navn');
F003Check::that('AK6', (new F003Mcp($token))->rpc('tools/list')->status === 401, 'en tilbagekaldt nøgle virker ikke længere');
// H004: dobbelt tilbagekald giver redirect med samme flash som en andens nøgle (ikke længere en 404-fejlside)
$twice = $c->post('/konto/mcp-noegle/' . $tid . '/tilbagekald');
F003Check::that('AK6', $twice->status === 303 && $twice->header('Location') === '/konto'
    && str_contains($c->get('/konto')->body, 'Nøglen er allerede tilbagekaldt.'), 'tilbagekald to gange: redirect og flash');
F003Check::that('AK6', $c->post('/konto/mcp-noegle', ['name' => 'Ny efter tilbagekald'])->status === 200, 'plads til en ny efter tilbagekald');

// En andens nøgle kan ikke tilbagekaldes
$other = f001_user('anden@f003.test');
Tokens::create($other, 'Anden', F003_NOW);
$otherId = (int) DB::scalar('SELECT id FROM mcp_tokens WHERE user_id = ?', [$other]);
F003Check::that('AK6', $c->post('/konto/mcp-noegle/' . $otherId . '/tilbagekald')->status === 303
    && DB::scalar('SELECT revoked_at FROM mcp_tokens WHERE id = ?', [$otherId]) === null, 'en andens nøgle: redirect og uændret');
$noCsrfRevoke = $c->post('/konto/mcp-noegle/' . $otherId . '/tilbagekald', [], false);
F003Check::that('AK6', $noCsrfRevoke->status === 403, 'tilbagekald uden CSRF: 403');
F003Check::that('AK6', $c->get('/konto/mcp-noegle')->status === 405, 'GET på opret-ruten: 405');

// Anonym
$anon = new F001Client('10.0.0.2');
F003Check::that('AK6', $anon->post('/konto/mcp-noegle', ['name' => 'x'])->status === 303, 'ikke logget ind: redirect til login');

// Sletning af brugeren fjerner nøglerne (cascade)
DB::exec('DELETE FROM users WHERE id = ?', [$other]);
F003Check::that('AK6', (int) DB::scalar('SELECT COUNT(*) FROM mcp_tokens WHERE user_id = ?', [$other]) === 0, 'slettet bruger: nøgler slettet');

F003Check::done();
