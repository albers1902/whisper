<?php
declare(strict_types=1);

// F003-AK8: en fremmed Origin afvises (403). GET og DELETE på /mcp giver 405, og en ukendt metode giver -32601.
// Desuden: protokolversion, body-loft, Content-Type, ugyldig JSON, batches og ukendte værktøjer.

require_once __DIR__ . '/f003_lib.php';

use Whisper\Mcp\Server;

f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

$evil = $m->rpc('tools/list', null, ['origin' => 'https://evil.example']);
F003Check::that('AK8', $evil->status === 403 && !str_contains($evil->body, 'search_calls'), 'fremmed Origin: 403 uden data');
F003Check::that('AK8', $m->rpc('tools/list', null, ['origin' => 'null'])->status === 403, 'Origin "null": 403');
F003Check::that('AK8', $m->rpc('tools/list', null, ['origin' => 'http://' . F003_HOST])->status === 403, 'egen vært over http: 403');
F003Check::that('AK8', $m->rpc('tools/list', null, ['origin' => 'https://' . F003_HOST . '.evil.example'])->status === 403, 'præfiks-trick: 403');
F003Check::that('AK8', $m->rpc('tools/list', null, ['origin' => 'https://' . F003_HOST])->status === 200, 'sitets egen Origin: tilladt');
F003Check::that('AK8', $m->rpc('tools/list')->status === 200, 'uden Origin (mcp-remote): tilladt');
F003Check::that('AK8', $m->rpc('tools/list', null, ['origin' => 'https://' . F003_HOST, 'host' => 'bad host'])->status === 403,
    'ugyldig Host: ingen Origin godkendes');

foreach (['GET', 'DELETE', 'PUT'] as $verb) {
    $r = $m->raw($verb, '');
    F003Check::that('AK8', $r->status === 405 && $r->header('Allow') === 'POST', "$verb: 405 med Allow: POST");
}
F003Check::that('AK8', (new F003Mcp(null))->raw('GET', '')->status === 405, 'GET uden nøgle: også 405 (ingen SSE-strøm)');

$u = f003_json($m->rpc('resources/list'));
F003Check::that('AK8', ($u['error']['code'] ?? 0) === -32601 && ($u['id'] ?? null) === 1, 'ukendt metode: -32601');
$t = f003_json($m->rpc('tools/call', ['name' => 'delete_everything', 'arguments' => new stdClass()]));
F003Check::that('AK8', ($t['error']['code'] ?? 0) === -32602, 'ukendt værktøj: -32602');

// Protokolversion: 2026-07-28 (uden initialize) får 400 uden "moderne" JSON-RPC-fejl, så klienten falder tilbage
$v = $m->rpc('tools/list', null, ['mcp-protocol-version' => '2026-07-28']);
F003Check::that('AK8', $v->status === 400 && json_decode($v->body, true) === null && str_contains($v->body, '2025-11-25'),
    'MCP-Protocol-Version 2026-07-28: 400 med tekst, ikke JSON-RPC');
foreach (Server::VERSIONS as $ok) {
    F003Check::that('AK8', $m->rpc('tools/list', null, ['mcp-protocol-version' => $ok])->status === 200, "version $ok accepteres");
}
F003Check::that('AK8', $m->rpc('tools/list', null, ['mcp-protocol-version' => ''])->status === 200, 'uden version-header: accepteres (2025-03-26)');

// Body
F003Check::that('AK8', $m->raw('POST', str_repeat(' ', Server::MAX_BODY + 1))->status === 413, 'for stor body: 413');
F003Check::that('AK8', $m->raw('POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['content-type' => 'text/plain'])->status === 415,
    'forkert Content-Type: 415');
F003Check::that('AK8', $m->raw('POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['content-type' => 'application/json; charset=utf-8'])->status === 200,
    'application/json med charset: tilladt');
$bad = $m->raw('POST', '{"jsonrpc": "2.0", ');
F003Check::that('AK8', $bad->status === 400 && (f003_json($bad)['error']['code'] ?? 0) === -32700, 'ugyldig JSON: -32700');
$batch = $m->raw('POST', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
F003Check::that('AK8', (f003_json($batch)['error']['code'] ?? 0) === -32600, 'batch: -32600');
F003Check::that('AK8', (f003_json($m->raw('POST', '{"id":1,"method":"ping"}'))['error']['code'] ?? 0) === -32600, 'uden jsonrpc: -32600');
F003Check::that('AK8', (f003_json($m->raw('POST', '{"jsonrpc":"2.0","id":{"a":1},"method":"ping"}'))['error']['code'] ?? 0) === -32600,
    'id som objekt: -32600');
F003Check::that('AK8', $m->raw('POST', '{"jsonrpc":"2.0","id":7,"result":{}}')->status === 202, 'et svar fra klienten: 202');
F003Check::that('AK8', $m->rpc('notifications/cancelled', ['requestId' => 1], [], null)->status === 202, 'notification: 202');

$sess = $m->rpc('ping', null, ['mcp-session-id' => 'abc']);
F003Check::that('AK8', $sess->status === 200 && $sess->header('Mcp-Session-Id') === null, 'Mcp-Session-Id ignoreres, ingen udstedes');

F003Check::done();
