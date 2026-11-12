<?php
declare(strict_types=1);

// F006-AK9 (automatisk del; ejerens smoke på DEV med den rigtige Claude er den anden del): kæden, som Claude's
// connector går den (claude.com/docs/connectors/building/authentication): 401 → resource_metadata → PRM →
// authorization_servers[0] → AS-metadata → registrering → authorize (login, tvunget skift af adgangskode,
// godkendelse) → token → /mcp initialize med Origin https://claude.ai og en ældre protokolversion → tools/call.

require_once __DIR__ . '/f006_lib.php';

use Whisper\DB;

f006_setup();
DB::exec('DELETE FROM login_attempts');
DB::exec('DELETE FROM users');
f001_user('ny@f006.test', 'user', true);   // skal skifte adgangskode ved første login
f003_call([], 'Kunden nævner flytning til Aarhus.');

$path = static fn(string $url): string => (string) parse_url($url, PHP_URL_PATH);

// 1. 401 peger på metadata
$r = (new F003Mcp(null))->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'claude-ai', 'version' => '1']], ['origin' => 'https://claude.ai']);
$prm = preg_match('/resource_metadata="([^"]+)"/', (string) $r->header('WWW-Authenticate'), $m) === 1 ? $m[1] : '';
F006Check::that('AK9', $r->status === 401 && $prm !== '', '401 med resource_metadata');

// 2. PRM og AS-metadata fra den første authorization server
$prmJson = f006_json(f006_ep('GET', $path($prm)));
F006Check::that('AK9', ($prmJson['resource'] ?? '') === F006_RESOURCE, 'PRM: resource er præcis URL\'en, brugeren indtaster');
$issuer = (string) ($prmJson['authorization_servers'][0] ?? '');
$as = f006_json(f006_ep('GET', $path($issuer) . '/.well-known/oauth-authorization-server'));
F006Check::that('AK9', ($as['issuer'] ?? '') === $issuer && in_array('S256', $as['code_challenge_methods_supported'] ?? [], true),
    'AS-metadata: issuer og S256');

// 3. Registrering (DCR, som "Register automatically")
$reg = f006_ep('POST', $path((string) $as['registration_endpoint']), [], (string) json_encode(['client_name' => 'Claude',
    'redirect_uris' => [F006_CALLBACK], 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none']), ['content-type' => 'application/json']);
$client = (string) (f006_json($reg)['client_id'] ?? '');
F006Check::that('AK9', $reg->status === 201 && $client !== '', 'registreret');

// 4. Authorize i browseren: login, tvunget skift, godkendelse
$c = new F001Client();
[$verifier, $challenge] = f006_pkce();
$q = f006_query($client, $challenge);
f006_authorize($c, $q);
$c->get('/login');
$r = $c->post('/login', ['email' => 'ny@f006.test', 'password' => F001_PW]);
F006Check::that('AK9', $r->header('Location') === '/konto', 'tvunget skift: først til /konto');
F006Check::that('AK9', str_contains($c->get('/konto')->body, 'fortsætter du med at forbinde Claude'), 'Konto siger, at forbindelsen fortsætter');
$r = $c->post('/konto', ['current' => F001_PW, 'new' => 'en-helt-ny-adgangskode', 'confirm' => 'en-helt-ny-adgangskode']);
F006Check::that('AK9', $r->status === 303 && $r->header('Location') === '/oauth/authorize', 'efter skiftet: videre til godkendelsen');
$page = f006_authorize($c, []);
F006Check::that('AK9', str_contains($page->body, 'Adgangskoden er skiftet.'), 'godkendelsessiden bekræfter skiftet (v2, QA F6b)');
F006Check::that('AK9', !str_contains($c->get('/konto')->body, 'Adgangskoden er skiftet.'), 'beskeden er forbrugt og dukker ikke op senere');
$loc =f006_location(f006_decide($c, f006_rid($page), 'godkend'));
F006Check::that('AK9', ($loc['state'] ?? '') === $q['state'] && ($loc['iss'] ?? '') === $issuer, 'callback med state og iss = issuer');

// 5. Token (form-urlencoded) og /mcp som Claude
$t = f006_json(f006_token($client, $loc['code'] ?? '', $verifier));
$m = new F003Mcp((string) ($t['access_token'] ?? ''));
$init = f003_json($m->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'claude-ai', 'version' => '1']], ['origin' => 'https://claude.ai', 'mcp-protocol-version' => '2025-06-18']));
F006Check::that('AK9', ($init['result']['protocolVersion'] ?? '') === '2025-06-18', 'initialize forhandler 2025-06-18');
$r = $m->rpc('tools/list', null, ['origin' => 'https://claude.com']);
F006Check::that('AK9', $r->status === 200 && str_contains($r->body, 'search_calls'), 'tools/list med Origin https://claude.com');
F006Check::that('AK9', $m->rpc('tools/list', null, ['origin' => 'https://evil.example'])->status === 403, 'fremmed Origin: stadig 403');
[$isError, , $found] = f003_result($m->tool('search_calls', ['query' => 'flytning']));
F006Check::that('AK9', !$isError && ($found['total'] ?? 0) === 1, 'search_calls finder opkaldet');

F006Check::done();
