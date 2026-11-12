<?php
declare(strict_types=1);

// H004-AK3: en intern fejl i /mcp uden for værktøjerne (her en DB-fejl i Tokens::authenticate) giver 500 med JSON-RPC
// -32603 og id fra requesten, aldrig index.php's HTML-fejlside. Loggen har kun klasse og sted, ingen SQL eller besked.

require_once __DIR__ . '/h004_lib.php';

use Whisper\DB;

$var = f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));
$log = $var . '/php-fejl.log';
ini_set('error_log', $log);

// Tabellen forsvinder midlertidigt: SELECT i authenticate kaster en PDOException
DB::exec('RENAME TABLE mcp_tokens TO mcp_tokens_h004');
try {
    $r = $m->rpc('tools/list', null, [], 42);
    $r2 = $m->raw('POST', 'ikke json');
} finally {
    DB::exec('RENAME TABLE mcp_tokens_h004 TO mcp_tokens');
}

$d = f003_json($r);
H004Check::that('AK3', $r->status === 500, 'DB-fejl i authenticate: 500 (fik ' . $r->status . ')');
H004Check::that('AK3', str_starts_with((string) $r->header('Content-Type'), 'application/json') && stripos($r->body, '<html') === false
    && stripos($r->body, '<!doctype') === false, 'svaret er JSON, ikke HTML');
H004Check::that('AK3', ($d['jsonrpc'] ?? '') === '2.0' && ($d['error']['code'] ?? 0) === -32603 && ($d['error']['message'] ?? '') === 'Intern fejl.'
    && array_key_exists('id', $d) && $d['id'] === 42, 'JSON-RPC -32603 "Intern fejl." med requestens id');
$d2 = f003_json($r2);
H004Check::that('AK3', $r2->status === 500 && ($d2['error']['code'] ?? 0) === -32603 && array_key_exists('id', $d2) && $d2['id'] === null,
    'ulæselig body: id er null');
H004Check::that('AK3', !str_contains($r->body, 'mcp_tokens') && !str_contains($r->body, 'SELECT'), 'svaret afslører ingen SQL');

$logged = is_file($log) ? (string) file_get_contents($log) : '';
H004Check::that('AK3', str_contains($logged, 'whisper mcp: PDOException i ') && str_contains($logged, 'DB.php:'),
    'loggen har klasse og sted');
H004Check::that('AK3', !str_contains($logged, 'mcp_tokens') && !str_contains($logged, 'SELECT') && !str_contains($logged, 'SQLSTATE'),
    'loggen har hverken SQL, tabelnavn eller PDO-besked');
H004Check::that('AK3', (new F003Mcp(f003_token($uid, 'Igen')))->rpc('tools/list')->status === 200, 'efter fejlen virker /mcp igen');

H004Check::done();
