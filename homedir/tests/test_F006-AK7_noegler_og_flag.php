<?php
declare(strict_types=1);

// F006-AK7 (ændret af DECISIONS #48): med F006 tændt afvises F003-nøgler (wsp_) på /mcp med samme 401 som uden token,
// og nøgle-handlingerne på Konto findes ikke (404). Med flaget slukket virker F003 helt uændret (rollback).

require_once __DIR__ . '/f006_lib.php';

use Whisper\Flags;
use Whisper\OAuth\OAuth;

f006_setup();
[$c, $uid] = f003_login();
$key = f003_token($uid);

$r = (new F003Mcp($key))->rpc('tools/list');
$none = (new F003Mcp(null))->rpc('tools/list');
F006Check::that('AK7', $r->status === 401 && !str_contains($r->body, 'search_calls'), 'tændt: wsp_-nøgle giver 401');
F006Check::that('AK7', $r->header('WWW-Authenticate') === $none->header('WWW-Authenticate') && $r->body === $none->body,
    'tændt: samme 401 som uden token');
F006Check::that('AK7', $c->post('/konto/mcp-noegle', ['name' => 'Ny'])->status === 404, 'tændt: POST /konto/mcp-noegle giver 404');
F006Check::that('AK7', $c->post('/konto/mcp-noegle/1/tilbagekald')->status === 404, 'tændt: tilbagekald af nøgle giver 404');

// Et OAuth access token virker kun med flaget tændt
$s = f006_connected();
$key = f003_token($s['uid']);
F006Check::that('AK7', f006_mcp_ok($s['access']), 'tændt: OAuth access token virker');

Flags::override(OAuth::FLAG, false);
F006Check::that('AK7', f006_mcp_ok($key), 'slukket: F003-nøglen virker som før');
F006Check::that('AK7', !f006_mcp_ok($s['access']), 'slukket: OAuth access token afvises');
$r = (new F003Mcp(null))->rpc('tools/list');
F006Check::that('AK7', $r->header('WWW-Authenticate') === 'Bearer realm="whisper"', 'slukket: 401 som i F003');
$konto = $s['c']->get('/konto')->body;
F006Check::that('AK7', str_contains($konto, 'Claude Desktop (MCP)') && str_contains($konto, 'Opret nøgle') && !str_contains($konto, 'Forbundne apps') && !str_contains($konto, 'connector-url'),
    'slukket: Konto som i F003');
$r = $s['c']->post('/konto/mcp-noegle', ['name' => 'Bærbar']);
F006Check::that('AK7', $r->status === 200 && str_contains($r->body, 'er oprettet'), 'slukket: nøgler kan oprettes');

F006Check::done();
