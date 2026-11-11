<?php
declare(strict_types=1);

// F003-AK2: uden nøgle, med en forkert nøgle, med en tilbagekaldt nøgle eller med en nøgle fra en deaktiveret bruger
// svares 401 med WWW-Authenticate: Bearer og uden data.

require_once __DIR__ . '/f003_lib.php';

use Whisper\DB;
use Whisper\Mcp\Tokens;
use Whisper\Response;

f003_setup();
[, $uid] = f003_login();
f003_call([], 'Hemmelig samtale om strøm.');
$good = f003_token($uid);

$is401 = static function (Response $r): bool {
    return $r->status === 401 && str_starts_with((string) $r->header('WWW-Authenticate'), 'Bearer')
        && !str_contains($r->body, 'result') && !str_contains($r->body, 'Hemmelig') && !str_contains($r->body, 'search_calls');
};
$list = static fn(F003Mcp $m): Response => $m->rpc('tools/list');

F003Check::that('AK2', $list(new F003Mcp($good))->status === 200, 'kontrol: den gyldige nøgle virker');
F003Check::that('AK2', $is401($list(new F003Mcp(null))), 'uden Authorization-header: 401');
F003Check::that('AK2', $is401((new F003Mcp(null))->raw('POST', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['authorization' => 'Basic eDp5'])),
    'Basic i stedet for Bearer: 401');
F003Check::that('AK2', $is401($list(new F003Mcp(Tokens::generate()))), 'en tilfældig nøgle i rigtigt format: 401');
F003Check::that('AK2', $is401($list(new F003Mcp('noget-helt-andet'))), 'forkert format: 401');
F003Check::that('AK2', $is401($list(new F003Mcp(substr($good, 0, -1)))), 'afkortet nøgle: 401');
F003Check::that('AK2', $is401((new F003Mcp(null))->rpc('tools/list', null, ['authorization' => 'Bearer ' . strtoupper($good)])),
    'nøglen med ændrede store/små bogstaver: 401');

// Tilbagekaldt
$revoked = f003_token($uid, 'Gammel');
Tokens::revoke($uid, (int) DB::scalar('SELECT id FROM mcp_tokens WHERE token_hash = ?', [Tokens::hash($revoked)]), F003_NOW);
F003Check::that('AK2', $is401($list(new F003Mcp($revoked))), 'tilbagekaldt nøgle: 401');

// Skal skifte adgangskode (fx efter en nulstilling)
DB::exec('UPDATE users SET must_change_password = 1 WHERE id = ?', [$uid]);
F003Check::that('AK2', $is401($list(new F003Mcp($good))), 'bruger, der skal skifte adgangskode: 401');
DB::exec('UPDATE users SET must_change_password = 0 WHERE id = ?', [$uid]);

// Deaktiveret bruger: læses fra databasen ved hver request
DB::exec('UPDATE users SET active = 0 WHERE id = ?', [$uid]);
F003Check::that('AK2', $is401($list(new F003Mcp($good))), 'deaktiveret bruger: 401 med det samme');
F003Check::that('AK2', $is401((new F003Mcp($good))->rpc('tools/call', ['name' => 'list_recent_calls', 'arguments' => new stdClass()])),
    'deaktiveret bruger: heller ingen værktøjskald');
DB::exec('UPDATE users SET active = 1 WHERE id = ?', [$uid]);
F003Check::that('AK2', $list(new F003Mcp($good))->status === 200, 'genaktiveret: nøglen virker igen');

$body = $list(new F003Mcp(null))->body;
F003Check::that('AK2', !str_contains($body, $good), '401-svaret gentager ikke nøglen');

F003Check::done();
