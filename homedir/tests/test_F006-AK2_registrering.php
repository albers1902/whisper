<?php
declare(strict_types=1);

// F006-AK2: dynamisk registrering accepterer en https-redirect på allowlisten og afviser andre værter, http (også
// loopback uden for DEV) og manglende felter. Kun public clients; navnet renses; loftet pr. IP.

require_once __DIR__ . '/f006_lib.php';

use Whisper\DB;
use Whisper\OAuth\Clients;

f006_setup();

$ok = f006_register_raw(['client_name' => 'Claude', 'redirect_uris' => [F006_CALLBACK]]);
$j = f006_json($ok);
F006Check::that('AK2', $ok->status === 201, 'claude.ai-callback: 201');
F006Check::that('AK2', preg_match('/^wci_[A-Za-z0-9_-]{22}$/', (string) ($j['client_id'] ?? '')) === 1, 'client_id udstedt');
F006Check::that('AK2', ($j['token_endpoint_auth_method'] ?? null) === 'none' && !array_key_exists('client_secret', $j), 'public client uden hemmelighed');
F006Check::that('AK2', ($j['redirect_uris'] ?? null) === [F006_CALLBACK], 'redirect_uris gentaget');
F006Check::that('AK2', f006_register_raw(['redirect_uris' => ['https://claude.com/api/mcp/auth_callback']])->status === 201,
    'claude.com-callback: 201');

$bad = [
    'anden vært'               => ['redirect_uris' => ['https://evil.example/api/mcp/auth_callback']],
    'underdomæne-snyd'         => ['redirect_uris' => ['https://claude.ai.evil.example/cb']],
    'http'                     => ['redirect_uris' => ['http://claude.ai/api/mcp/auth_callback']],
    'loopback uden for DEV'    => ['redirect_uris' => ['http://localhost:3118/callback']],
    'port'                     => ['redirect_uris' => ['https://claude.ai:8443/cb']],
    'userinfo'                 => ['redirect_uris' => ['https://x@claude.ai/cb']],
    'fragment'                 => ['redirect_uris' => ['https://claude.ai/cb#x']],
    'manglende redirect_uris'  => ['client_name' => 'Claude'],
    'tom liste'                => ['redirect_uris' => []],
    'ikke en liste'            => ['redirect_uris' => F006_CALLBACK],
    'en af flere er ulovlig'   => ['redirect_uris' => [F006_CALLBACK, 'https://evil.example/cb']],
];
foreach ($bad as $case => $meta) {
    $r = f006_register_raw($meta);
    F006Check::that('AK2', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_redirect_uri', "$case: 400 invalid_redirect_uri");
}
$r = f006_register_raw(['redirect_uris' => [F006_CALLBACK], 'token_endpoint_auth_method' => 'client_secret_basic']);
F006Check::that('AK2', $r->status === 400 && (f006_json($r)['error'] ?? '') === 'invalid_client_metadata', 'confidential client: afvist');
$r = f006_register_raw(['redirect_uris' => [F006_CALLBACK], 'grant_types' => ['client_credentials']]);
F006Check::that('AK2', $r->status === 400, 'client_credentials: afvist');
$r = f006_ep('POST', '/oauth/register', [], 'ikke json', ['content-type' => 'application/json']);
F006Check::that('AK2', $r->status === 400, 'ugyldig JSON: 400');
$r = f006_ep('POST', '/oauth/register', [], '{"redirect_uris":["' . F006_CALLBACK . '"]}', ['content-type' => 'text/plain']);
F006Check::that('AK2', $r->status === 400, 'forkert Content-Type: 400');

// Navnet renses for kontrol- og bidi-tegn (design D8)
$id = f006_register(F006_CALLBACK, "Cla\u{202E}ude\x07  Desktop");
$name = (string) DB::scalar('SELECT client_name FROM oauth_clients WHERE client_id = ?', [$id]);
F006Check::that('AK2', $name === 'Cla ude Desktop', 'bidi- og kontroltegn fjernet, whitespace samlet');

// Loft pr. IP
DB::exec('DELETE FROM oauth_clients');
$status = 0;
for ($i = 0; $i <= Clients::PER_IP_HOUR; $i++) {
    $status = f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '203.0.113.7')->status;
}
F006Check::that('AK2', $status === 429, 'over ' . Clients::PER_IP_HOUR . ' registreringer i timen fra én IP: 429');
F006Check::that('AK2', f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '203.0.113.8')->status === 201, 'anden IP: stadig 201');

F006Check::done();
