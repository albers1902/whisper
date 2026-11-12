<?php
declare(strict_types=1);

// F006-AK3: hele flowet register → authorize (login og godkendelse) → token → /mcp tools/list virker med PKCE S256.
// Forkert verifier, genbrugt kode, udløbet kode og forkert redirect_uri afvises. Ugyldig klient, redirect_uri og
// resource giver en side og aldrig en redirect (open redirect); manglende eller "plain" PKCE afvises (downgrade).

require_once __DIR__ . '/f006_lib.php';

use Whisper\App;
use Whisper\DB;
use Whisper\OAuth\OAuth;

f006_setup();
DB::exec('DELETE FROM login_attempts');
DB::exec('DELETE FROM users');
f001_user('bruger@f002.test', 'user');
$client = f006_register();

// Ikke logget ind: anmodningen gemmes, login viser konteksten, og efter login kommer godkendelsessiden
$c = new F001Client();
[$verifier, $challenge] = f006_pkce();
$q = f006_query($client, $challenge);
$r = f006_authorize($c, $q);
F006Check::that('AK3', $r->status === 303 && $r->header('Location') === '/login', 'anonym: redirect til /login (ingen next=)');
$login = $c->get('/login');
F006Check::that('AK3', str_contains($login->body, 'Log ind for at forbinde Claude til Whisper'), 'login viser konteksten');
$r = $c->post('/login', ['email' => 'bruger@f002.test', 'password' => F001_PW]);
F006Check::that('AK3', $r->status === 303 && $r->header('Location') === '/oauth/authorize', 'efter login: tilbage til godkendelsen');
$page = f006_authorize($c, []);
F006Check::that('AK3', $page->status === 200 && str_contains($page->body, 'Giv adgang til Whisper'), 'godkendelsessiden vises');
F006Check::that('AK3', str_contains($page->body, '<code class="host">claude.ai</code>'), 'redirect-værten vises');
F006Check::that('AK3', str_contains($page->body, '«Claude»') && str_contains($page->body, 'Navnet oplyses af appen selv.'),
    'klientnavnet i «» som selvopgivet');
F006Check::that('AK3', !str_contains($page->body, 'autofocus'), 'ingen autofocus på Godkend');

$r = f006_decide($c, f006_rid($page), 'godkend');
$loc = f006_location($r);
F006Check::that('AK3', $r->status === 303 && str_starts_with((string) $r->header('Location'), F006_CALLBACK . '?'), 'Godkend: redirect til callback');
F006Check::that('AK3', ($loc['state'] ?? '') === $q['state'] && ($loc['iss'] ?? '') === F006_SITE && preg_match('/^wac_/', $loc['code'] ?? '') === 1,
    'code, uændret state og iss');
$code = $loc['code'] ?? '';

// Forkert verifier, derefter genbrug: koden er brugt ved første forsøg (engangs), og genbrug tilbagekalder kæden
$bad = f006_token($client, $code, f006_pkce()[0]);
F006Check::that('AK3', $bad->status === 400 && (f006_json($bad)['error'] ?? '') === 'invalid_grant', 'forkert verifier: invalid_grant');

// Rigtig flow
$flow = f006_code($c, $client);
$t = f006_token($client, $flow['code'], $flow['verifier']);
$tj = f006_json($t);
F006Check::that('AK3', $t->status === 200 && ($tj['token_type'] ?? '') === 'Bearer' && ($tj['expires_in'] ?? 0) === 3600
    && preg_match('/^wat_/', (string) ($tj['access_token'] ?? '')) === 1 && preg_match('/^wrt_/', (string) ($tj['refresh_token'] ?? '')) === 1,
    'token: access (1 time) og refresh');
F006Check::that('AK3', $t->header('Cache-Control') === 'no-store', 'token-svaret: no-store');
$access = (string) ($tj['access_token'] ?? '');
F006Check::that('AK3', f006_mcp_ok($access), '/mcp tools/list med access token: 200');
$hashes = DB::all('SELECT token_hash FROM oauth_tokens');
F006Check::that('AK3', $hashes !== [] && !in_array($access, array_column($hashes, 'token_hash'), true)
    && in_array(hash('sha256', $access), array_column($hashes, 'token_hash'), true), 'kun hashen er gemt');

$again = f006_token($client, $flow['code'], $flow['verifier']);
F006Check::that('AK3', $again->status === 400 && (f006_json($again)['error'] ?? '') === 'invalid_grant', 'genbrugt kode: invalid_grant');
F006Check::that('AK3', !f006_mcp_ok($access), 'genbrugt kode: det udstedte access token er tilbagekaldt');

// Udløbet kode
$flow = f006_code($c, $client);
$late = f006_token($client, $flow['code'], $flow['verifier'], [], F003_NOW + 61);
F006Check::that('AK3', $late->status === 400 && (f006_json($late)['error'] ?? '') === 'invalid_grant', 'kode efter 61 s: invalid_grant');

// Forkert redirect_uri ved ombytning, og en anden klient
$flow = f006_code($c, $client);
$r = f006_token($client, $flow['code'], $flow['verifier'], ['redirect_uri' => 'https://claude.ai/api/mcp/other']);
F006Check::that('AK3', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_grant', 'token med anden redirect_uri: invalid_grant');
$flow = f006_code($c, $client);
$other = f006_register();
$r = f006_token($other, $flow['code'], $flow['verifier']);
F006Check::that('AK3', $r->status === 400, 'kode indløst af en anden klient: afvist');
$r = f006_token('wci_' . str_repeat('A', 22), $flow['code'], $flow['verifier']);
F006Check::that('AK3', $r->status === 401 && (f006_json($r)['error'] ?? '') === 'invalid_client', 'ukendt client_id: invalid_client');

// Ugyldig anmodning: side, ingen redirect, og klientens værdier gengives ikke (E1)
[, $ch] = f006_pkce();
$pages = [
    'ukendt klient'          => [f006_query('wci_' . str_repeat('B', 22), $ch), 'unknown_client'],
    'ikke-registreret redirect' => [f006_query($client, $ch, ['redirect_uri' => 'https://claude.ai/evil']), 'invalid_redirect_uri'],
    'fremmed redirect'       => [f006_query($client, $ch, ['redirect_uri' => 'https://evil.example/cb']), 'invalid_redirect_uri'],
    'forkert resource'       => [f006_query($client, $ch, ['resource' => 'https://andet.example/mcp']), 'invalid_resource'],
];
foreach ($pages as $case => [$query, $codeName]) {
    $r = f006_authorize($c, $query);
    F006Check::that('AK3', $r->status === 400 && $r->header('Location') === null && str_contains($r->body, 'Forbindelsen kan ikke oprettes')
        && str_contains($r->body, $codeName) && !str_contains($r->body, 'evil.example'), "$case: side 400 uden redirect ($codeName)");
}

// v2 (QA F5): mangler resource, menes /mcp — den eneste ressource
$noRes = f006_authorize($c, f006_query($client, $ch, ['resource' => '']));
F006Check::that('AK3', $noRes->status === 200 && str_contains($noRes->body, 'Giv adgang til Whisper'), 'uden resource: godkendelsessiden vises');
[$v2, $ch2] = f006_pkce();
$loc = f006_location(f006_decide($c, f006_rid(f006_authorize($c, f006_query($client, $ch2, ['resource' => '']))), 'godkend'));
$t = f006_token($client, $loc['code'] ?? '', $v2, ['resource' => '']);
F006Check::that('AK3', $t->status === 200 && f006_mcp_ok((string) (f006_json($t)['access_token'] ?? '')), 'uden resource på authorize og token: virker');

// PKCE-downgrade og forkert response_type: redirect med fejl til den beviste redirect_uri
$redirects = [
    'uden code_challenge'    => [f006_query($client, $ch, ['code_challenge' => '']), 'invalid_request'],
    'plain'                  => [f006_query($client, $ch, ['code_challenge_method' => 'plain']), 'invalid_request'],
    'manglende metode'       => [f006_query($client, $ch, ['code_challenge_method' => '']), 'invalid_request'],
    'response_type=token'    => [f006_query($client, $ch, ['response_type' => 'token']), 'unsupported_response_type'],
];
foreach ($redirects as $case => [$query, $err]) {
    $r = f006_authorize($c, $query);
    $loc = f006_location($r);
    F006Check::that('AK3', $r->status === 303 && str_starts_with((string) $r->header('Location'), F006_CALLBACK . '?')
        && ($loc['error'] ?? '') === $err && ($loc['state'] ?? '') === $query['state'] && !isset($loc['code']), "$case: redirect med error=$err");
}

// En godkendt kode findes kun efter POST med CSRF; GET udsteder intet
$before = (int) DB::scalar('SELECT COUNT(*) FROM oauth_codes');
f006_authorize($c, f006_query($client, $ch));
F006Check::that('AK3', (int) DB::scalar('SELECT COUNT(*) FROM oauth_codes') === $before, 'GET af godkendelsessiden udsteder ingen kode');
$page = f006_authorize($c, f006_query($client, $ch));
$r = f006_decide($c, f006_rid($page), 'godkend', false);
F006Check::that('AK3', $r->status === 400 && str_contains($r->body, 'Forbindelsen er udløbet') && $r->header('Location') === null,
    'Godkend uden CSRF: udløbet-siden, ingen redirect');
$page = f006_authorize($c, f006_query($client, $ch));
$rid = f006_rid($page);
f006_decide($c, $rid, 'godkend');
$r = f006_decide($c, $rid, 'godkend');
F006Check::that('AK3', $r->status === 400 && str_contains($r->body, 'Forbindelsen er udløbet'), 'dobbeltklik på Godkend: udløbet (engangs)');

// v2 (QA F4): "Ikke dig? Log ud" beholder den ventende anmodning; efter ny login fortsætter den
$page = f006_authorize($c, f006_query($client, $ch));
F006Check::that('AK3', str_contains($page->body, 'action="/logout"'), 'godkendelsessiden har Log ud');
$r = $c->post('/logout');
F006Check::that('AK3', $r->status === 303 && $r->header('Location') === '/login', 'Log ud: til login');
F006Check::that('AK3', str_contains($c->get('/login')->body, 'Log ind for at forbinde Claude til Whisper'), 'login efter Log ud viser konteksten');
$r = $c->post('/login', ['email' => 'bruger@f002.test', 'password' => F001_PW]);
F006Check::that('AK3', $r->header('Location') === '/oauth/authorize' && f006_authorize($c, [])->status === 200, 'efter ny login: tilbage til godkendelsen');

// v2 (QA F7): udløbet-teksten nævner et nyere vindue
F006Check::that('AK3', str_contains(App::OAUTH_EXPIRED_TEXT, 'en nyere forbindelse er startet i et andet vindue'), 'E4 nævner et nyere vindue');

// v2 (security S5): grov rate limit pr. IP på token og authorize
$last = null;
for ($i = 0; $i <= OAuth::IP_PER_MINUTE; $i++) {
    $last = f006_ep('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => $client], '',
        ['content-type' => 'application/x-www-form-urlencoded'], F003_NOW + 120, '192.0.2.77');
}
F006Check::that('AK3', $last !== null && $last->status === 429 && $last->header('Retry-After') !== null, 'token: over ' . OAuth::IP_PER_MINUTE . ' i minuttet fra én IP giver 429');
$other = new F001Client('192.0.2.78');
$c->now = F003_NOW + 180;
$other->now = F003_NOW + 180;
for ($i = 0; $i <= OAuth::IP_PER_MINUTE; $i++) {
    $last = $other->app->handle(new Whisper\Request('GET', '/oauth/authorize', [], $other->ip, f006_query($client, $ch), ['host' => F003_HOST]),
        $other->session);
}
F006Check::that('AK3', $last !== null && $last->status === 429, 'authorize: over ' . OAuth::IP_PER_MINUTE . ' i minuttet fra én IP giver 429');

// Klientens state kommer ikke i loggen; ingen koder eller tokens i adgangsloggen
$log = (string) @file_get_contents(\Whisper\AccessLog::file());
F006Check::that('AK3', str_contains($log, 'oauth_godkend') && str_contains($log, 'oauth_token') && !str_contains($log, 'wac_')
    && !str_contains($log, 'wat_') && !str_contains($log, 'wrt_') && !str_contains($log, $q['state']), 'adgangsloggen uden koder, tokens og state');

F006Check::done();
