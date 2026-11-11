<?php
declare(strict_types=1);

// F003-AK1: initialize med en gyldig nøgle svarer med protocolVersion, capabilities.tools og serverInfo; tools/list
// viser de fire værktøjer med inputSchema. Notifications får 202 uden body, og ping virker.

require_once __DIR__ . '/f003_lib.php';

use Whisper\App;

f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

$r = $m->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'test', 'version' => '1']], ['mcp-protocol-version' => '']);
$j = f003_json($r);
F003Check::that('AK1', $r->status === 200 && str_starts_with((string) $r->header('Content-Type'), 'application/json'), 'initialize: 200 JSON');
F003Check::that('AK1', ($j['jsonrpc'] ?? '') === '2.0' && ($j['id'] ?? null) === 1, 'JSON-RPC 2.0 med samme id');
F003Check::that('AK1', ($j['result']['protocolVersion'] ?? '') === '2025-11-25', 'protocolVersion 2025-11-25');
F003Check::that('AK1', str_contains($r->body, '"capabilities":{"tools":{}}'), 'capabilities.tools er et objekt');
F003Check::that('AK1', ($j['result']['serverInfo']['name'] ?? '') === 'whisper' && ($j['result']['serverInfo']['version'] ?? '') === App::VERSION,
    'serverInfo name=whisper og version=App::VERSION');
F003Check::that('AK1', $r->header('Mcp-Session-Id') === null && $r->header('Set-Cookie') === null, 'ingen session-id og ingen cookie');
F003Check::that('AK1', $r->header('Cache-Control') === 'no-store', 'no-store');

$old = f003_json($m->rpc('initialize', ['protocolVersion' => '2025-06-18']));
F003Check::that('AK1', ($old['result']['protocolVersion'] ?? '') === '2025-06-18', 'en understøttet ældre version gives tilbage');
$unknown = f003_json($m->rpc('initialize', ['protocolVersion' => '2099-01-01']));
F003Check::that('AK1', ($unknown['result']['protocolVersion'] ?? '') === '2025-11-25', 'ukendt version: serverens nyeste');

$n = $m->rpc('notifications/initialized', null, [], null);
F003Check::that('AK1', $n->status === 202 && $n->body === '', 'notifications/initialized: 202 uden body');

$p = f003_json($m->rpc('ping', null, [], 'abc'));
F003Check::that('AK1', ($p['id'] ?? null) === 'abc' && isset($p['result']) && !isset($p['error']), 'ping svarer med tomt resultat og streng-id');

$l = f003_json($m->rpc('tools/list'));
$tools = $l['result']['tools'] ?? [];
$names = array_map(static fn(array $t): string => (string) $t['name'], is_array($tools) ? $tools : []);
sort($names);
F003Check::that('AK1', $names === ['call_stats', 'get_transcript', 'list_recent_calls', 'search_calls'], 'de fire værktøjer');
$schemasOk = true;
foreach ($tools as $t) {
    $s = $t['inputSchema'] ?? null;
    $schemasOk = $schemasOk && is_array($s) && ($s['type'] ?? '') === 'object' && ($s['additionalProperties'] ?? null) === false
        && is_string($t['description'] ?? null) && ($t['annotations']['readOnlyHint'] ?? false) === true;
}
F003Check::that('AK1', $schemasOk, 'hvert værktøj har inputSchema (object, ingen ekstra argumenter), beskrivelse og readOnlyHint');

F003Check::done();
