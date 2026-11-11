<?php
declare(strict_types=1);

// F003-AK9: med [F003_mcp] slukket svarer /mcp 404, og nøgle-sektionen på /konto vises ikke. Det samme, når F002's
// flag er slukket (dataene findes ikke). Flaget tjekkes før nøgle og database.

require_once __DIR__ . '/f003_lib.php';

use Whisper\CallPages;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Mcp\Server;

f003_setup();
[$c, $uid] = f003_login();
$token = f003_token($uid);
$m = new F003Mcp($token);
F003Check::that('AK9', $m->rpc('tools/list')->status === 200, 'kontrol: flag on virker');

Flags::override(Server::FLAG, false);
$r = $m->rpc('tools/list');
F003Check::that('AK9', $r->status === 404 && !str_contains($r->body, 'tools'), 'F003 off: /mcp 404');
F003Check::that('AK9', (new F003Mcp(null))->rpc('tools/list')->status === 404, 'F003 off: 404 også uden nøgle (ikke 401)');
F003Check::that('AK9', $m->raw('GET', '')->status === 404, 'F003 off: GET 404 (ikke 405)');
$before = (int) DB::scalar('SELECT COALESCE(SUM(`count`), 0) FROM mcp_rate');
$m->rpc('ping');
F003Check::that('AK9', (int) DB::scalar('SELECT COALESCE(SUM(`count`), 0) FROM mcp_rate') === $before, 'F003 off: intet skrives i rate-tabellen');
$k = $c->get('/konto');
F003Check::that('AK9', $k->status === 200 && !str_contains($k->body, 'Claude Desktop (MCP)') && !str_contains($k->body, 'mcp-noegle'),
    'F003 off: ingen nøgle-sektion på /konto');
F003Check::that('AK9', $c->post('/konto/mcp-noegle', ['name' => 'x'])->status === 404, 'F003 off: opret-ruten findes ikke');
F003Check::that('AK9', $c->get('/mcp')->status === 404, 'App kender ikke /mcp: 404');

Flags::override(Server::FLAG, true);
Flags::override(CallPages::FLAG, false);
F003Check::that('AK9', $m->rpc('tools/list')->status === 404, 'F002 off: /mcp 404');
F003Check::that('AK9', !str_contains($c->get('/konto')->body, 'Claude Desktop (MCP)'), 'F002 off: ingen nøgle-sektion');

Flags::override(CallPages::FLAG, true);
F003Check::that('AK9', str_contains($c->get('/konto')->body, 'Claude Desktop (MCP)'), 'begge on: sektionen vises');

// Flagets standardværdier i features.ini: kun dev
F003Check::that('AK9', Flags::enabledIn(Server::FLAG, 'dev') && !Flags::enabledIn(Server::FLAG, 'test') && !Flags::enabledIn(Server::FLAG, 'stable'),
    'features.ini: dev=on, test=off, stable=off');

F003Check::done();
