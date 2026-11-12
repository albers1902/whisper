<?php
declare(strict_types=1);

// F006-AK8: med flaget slukket findes OAuth-endpointene ikke (404, App's almindelige side), og /mcp opfører sig som
// i dag. OAuth kræver også F003 og F005: uden dem er F006 slukket, selv om dets eget flag er tændt.

require_once __DIR__ . '/f006_lib.php';

use Whisper\Flags;
use Whisper\Mcp\Server;
use Whisper\OAuth\Endpoints;
use Whisper\OAuth\OAuth;
use Whisper\Request;
use Whisper\Ui;

f006_setup(false);
[$c, $uid] = f003_login();
$anon = new F001Client('10.0.0.5');

F006Check::that('AK8', !OAuth::enabled(), 'flaget slukket: OAuth::enabled() er false');
$paths = ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server',
    '/oauth/register', '/oauth/token', '/oauth/authorize'];
foreach ($paths as $p) {
    F006Check::that('AK8', Endpoints::isPath($p) || $p === '/oauth/authorize', "$p genkendes af index.php (kun brugt, når OAuth er tændt)");
    F006Check::that('AK8', $anon->get($p)->status === 404 && $anon->post($p, [], false)->status === 404, "slukket, anonym: $p giver 404");
    F006Check::that('AK8', $c->get($p)->status === 404, "slukket, logget ind: $p giver 404");
}
$r = $c->app->handle(new Request('GET', '/oauth/authorize', [], $c->ip, ['client_id' => 'x'], ['host' => F003_HOST]), $c->session);
F006Check::that('AK8', $r->status === 404, 'slukket: /oauth/authorize med parametre giver 404');
F006Check::that('AK8', $c->post('/konto/app/1/tilbagekald')->status === 404, 'slukket: tilbagekald af app giver 404');

$key = f003_token($uid);
F006Check::that('AK8', f006_mcp_ok($key), 'slukket: F003-nøglen virker på /mcp');
$r = (new F003Mcp(null))->rpc('tools/list');
F006Check::that('AK8', $r->status === 401 && $r->header('WWW-Authenticate') === 'Bearer realm="whisper"', 'slukket: 401 uden resource_metadata');
$r = (new F003Mcp($key))->rpc('tools/list', null, ['origin' => 'https://claude.ai']);
F006Check::that('AK8', $r->status === 403, 'slukket: Origin https://claude.ai giver 403 som i F003');
F006Check::that('AK8', !str_contains($anon->get('/login')->body, 'forbinde Claude'), 'slukket: login uden kontekstlinje');

// Afhængigheder: F006 on, men F005 eller F003 slukket
Flags::override(OAuth::FLAG, true);
F006Check::that('AK8', OAuth::enabled(), 'kontrol: alle flag tændt');
Flags::override(Ui::FLAG, false);
F006Check::that('AK8', !OAuth::enabled() && $anon->get('/oauth/authorize')->status === 404, 'F005_ui slukket: OAuth slukket');
Flags::override(Ui::FLAG, true);
Flags::override(Server::FLAG, false);
F006Check::that('AK8', !OAuth::enabled(), 'F003_mcp slukket: OAuth slukket');

F006Check::done();
