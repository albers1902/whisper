<?php
declare(strict_types=1);

// F006-AK2: dynamisk registrering accepterer en https-redirect på allowlisten (fulde URI'er, v2: security S2) og
// afviser andre værter og stier, http (også loopback uden for DEV) og manglende felter. Kun public clients; navnet
// renses; loftet pr. IP (IPv6 pr. /64); ubrugte klienter ryddes efter 24 timer, og det samlede loft fortrænger de
// ældste i stedet for at afvise (v2: QA F1, security S1).

require_once __DIR__ . '/f006_lib.php';

use Whisper\DB;
use Whisper\OAuth\Clients;
use Whisper\OAuth\OAuth;
use Whisper\Users;

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
    'anden sti på claude.ai'   => ['redirect_uris' => ['https://claude.ai/chat/x']],
    'callback med query'       => ['redirect_uris' => [F006_CALLBACK . '?x=1']],
    'callback med / til sidst' => ['redirect_uris' => [F006_CALLBACK . '/']],
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

// IPv6: hele /64 deler loftet
DB::exec('DELETE FROM oauth_clients');
for ($i = 1; $i <= Clients::PER_IP_HOUR; $i++) {
    f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '2001:db8:1:2::' . dechex($i));
}
F006Check::that('AK2', f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '2001:db8:1:2:ffff::1')->status === 429, 'IPv6: samme /64 deler loftet');
F006Check::that('AK2', f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '2001:db8:1:3::1')->status === 201, 'IPv6: et andet /64 er fri');

// Det samlede loft afviser aldrig: de ældste ubrugte klienter fortrænges, og en klient med bevilling røres ikke
DB::exec('DELETE FROM oauth_clients');
[$c] = f003_login();
$kept = f006_register();
f006_code($c, $kept);   // giver bevilling: må ikke fortrænges
$ins = DB::pdo()->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at, created_ip) VALUES (?, ?, ?, ?, ?)');
for ($i = 0; $i < Clients::MAX_UNUSED; $i++) {
    // Spredt over de sidste 23 timer (ingen er gamle nok til 24-timers-oprydningen), fra mange IP'er
    $ins->execute([OAuth::random('wci_', 16), 'Fyld', '["' . F006_CALLBACK . '"]', Users::ts(F003_NOW - 23 * 3600 + $i * 60),
        '198.51.100.' . ($i % 250)]);
}
$oldest = (string) DB::scalar('SELECT client_id FROM oauth_clients WHERE client_name = ? ORDER BY created_at, id LIMIT 1', ['Fyld']);
$r = f006_register_raw(['redirect_uris' => [F006_CALLBACK]], '192.0.2.50');
$unused = (int) DB::scalar('SELECT COUNT(*) FROM oauth_clients c WHERE NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_pk = c.id)');
F006Check::that('AK2', $r->status === 201, 'loftet på ' . Clients::MAX_UNUSED . ' ubrugte: registreringen lykkes stadig (ingen DoS)');
F006Check::that('AK2', $unused === Clients::MAX_UNUSED, 'antallet af ubrugte klienter holder sig på loftet');
F006Check::that('AK2', Clients::find($oldest) === null, 'den ældste ubrugte klient er fortrængt');
F006Check::that('AK2', Clients::find($kept) !== null, 'en klient med bevilling røres ikke');

// 24 timer: ubrugte klienter ryddes; en klient med bevilling bliver
Clients::purge(F003_NOW + 24 * 3600 + 60);
$left = (int) DB::scalar('SELECT COUNT(*) FROM oauth_clients');
F006Check::that('AK2', Clients::find($kept) !== null && $left === 1, 'efter 24 timer: kun klienten med bevilling er tilbage');

F006Check::done();
