<?php
declare(strict_types=1);

// H002-AK1: et 400-svar {"error":"invalid_client","error_description":"Client authentication failed"} på /oauth/tokens
// giver en fejltekst med Zendesks fejlkode og beskrivelse — i proben, i loggen og i sync_state.last_error.
// Uden rettelsen stod der kun "Zendesk afviste OAuth-klienten (HTTP 400)".

require_once __DIR__ . '/h002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\Client;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Probe;
use Whisper\Zendesk\Sync;

$cfg = f002_cfg();
$invalid = f002_json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 400);

// Klientens fejltekst
$msg = h002_token_error($cfg, $invalid);
H002Check::that('AK1', $msg === 'Zendesk afviste OAuth-klienten (HTTP 400: invalid_client - Client authentication failed)',
    "fejlteksten har fejlkode og beskrivelse (fik: $msg)");

// Proben: linjen for en scope-variant (samme kode som cron/zendesk-probe.php)
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', $invalid);
$line = Probe::scopeLine($cfg, $http, 'read', F002_SINCE);
H002Check::that('AK1', $line === 'scope "read": token afvist (' . $msg . ')', "probens linje viser fejlkoden (fik: $line)");
$req = $http->to(F002_BASE . '/oauth/tokens')[0] ?? null;
H002Check::that('AK1', $req !== null && ($req['headers']['Content-Type'] ?? '') === 'application/json'
    && is_array(json_decode((string) $req['body'], true)), 'request-formatet er JSON, som proben siger');
H002Check::that('AK1', Client::TOKEN_REQUEST_FORMAT === 'JSON', 'proben udskriver request-format JSON');
H002Check::that('AK1', str_contains((string) file_get_contents(__DIR__ . '/../cron/zendesk-probe.php'), 'Probe::scopeLine('),
    'cron/zendesk-probe.php bruger Probe::scopeLine');

// invalid_scope (den fejl, Zendesks dokumentation nævner) vises på samme måde
$msg2 = h002_token_error($cfg, f002_json(['error' => 'invalid_scope', 'error_description' => 'The requested scope is invalid'], 400));
H002Check::that('AK1', str_contains($msg2, 'HTTP 400: invalid_scope - The requested scope is invalid'), 'invalid_scope vises');

// Loggen og sync_state: Sync stopper med samme tekst
f002_setup();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', $invalid);
$logs = [];
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static function (string $l) use (&$logs): void {
    $logs[] = $l;
}))->run();
H002Check::that('AK1', $r['error'] === $msg, 'Sync stopper med fejlkoden');
H002Check::that('AK1', in_array('zendesk-sync: stoppet: ' . $msg, $logs, true), 'loggen viser invalid_client og beskrivelsen');
H002Check::that('AK1', DB::scalar('SELECT last_error FROM sync_state') === $msg, 'sync_state.last_error viser fejlkoden');

// Et gyldigt svar virker som før
$ok = h002_token_error($cfg, f002_token_response());
H002Check::that('AK1', $ok === '', 'et 200-svar med access_token giver intet fejl');
$empty = h002_token_error($cfg, new HttpResult(200, [], '{}'));
H002Check::that('AK1', $empty === 'Zendesk afviste OAuth-klienten (HTTP 200)', '200 uden access_token er stadig en fejl');

H002Check::done();
