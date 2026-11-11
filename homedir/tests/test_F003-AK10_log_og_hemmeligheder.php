<?php
declare(strict_types=1);

// F003-AK10: hvert tools/call logges i adgang.log uden argumentværdier eller tekst. Nøgler optræder aldrig i logs,
// fejltekster eller HTML efter visningen. AccessLog's eksisterende handlinger (F002) er uændrede.

require_once __DIR__ . '/f003_lib.php';

use Whisper\AccessLog;

$dir = f003_setup();
[$c, $uid] = f003_login();
$log = $dir . '/adgang.log';

$id = f003_call(['customer_phone' => '+45 11 22 33 44', 'agent_name' => 'Agent Hemmelig'], 'Kunden hedder Kirsten og bor på Elmevej.');
$r = $c->post('/konto/mcp-noegle', ['name' => 'Logtest']);
preg_match('/value="(wsp_[A-Za-z0-9_-]{43})"/', $r->body, $mm);
$token = $mm[1] ?? '';
$m = new F003Mcp($token);

$m->tool('search_calls', ['query' => 'Elmevej', 'agent' => 'Agent Hemmelig']);
$m->tool('get_transcript', ['call_id' => $id]);
$m->tool('list_recent_calls', ['limit' => 5]);
$m->tool('call_stats', ['from' => '2026-10-01', 'to' => '2026-10-31']);
$m->tool('search_calls', ['from' => 'Kirsten-ikke-en-dato']);
$m->tool('get_transcript', ['call_id' => 999999999]);
$m->rpc('tools/list');

$lines = array_values(array_filter(explode("\n", (string) @file_get_contents($log))));
$has = static fn(string $re): bool => array_filter($lines, static fn(string $l): bool => preg_match($re, $l) === 1) !== [];
F003Check::that('AK10', count($lines) === 6, 'én linje pr. tools/call (tools/list logges ikke): ' . count($lines));
F003Check::that('AK10', $has('/^\S+Z bruger=' . $uid . ' mcp_search_calls antal=1$/'), 'search_calls: bruger, værktøj, antal');
F003Check::that('AK10', $has('/^\S+Z bruger=' . $uid . ' mcp_get_transcript opkald=' . $id . ' antal=1$/'), 'get_transcript: opkalds-id');
F003Check::that('AK10', $has('/ mcp_list_recent_calls antal=1$/') && $has('/ mcp_call_stats antal=1$/'), 'list_recent_calls og call_stats');
F003Check::that('AK10', $has('/ mcp_search_calls antal=0$/') && $has('/ mcp_get_transcript opkald=999999999 antal=0$/'), 'fejlede kald logges med antal=0');
$all = implode("\n", $lines);
foreach (['Elmevej', 'Kirsten', 'Hemmelig', '+45', '11 22', 'Logtest', 'wsp_', $token] as $secret) {
    F003Check::that('AK10', !str_contains($all, $secret), "loggen indeholder ikke \"$secret\"");
}

// Nøglen aldrig i fejltekster eller HTML efter visningen
$err = f003_result($m->tool('search_calls', ['nøgle' => $token]))[1];
F003Check::that('AK10', !str_contains($err, $token) && !str_contains($err, substr($token, 8)), 'fejltekst gentager ikke værdier');
$bad = (new F003Mcp(null))->rpc('ping', null, ['authorization' => 'Bearer ' . $token . 'x']);
F003Check::that('AK10', $bad->status === 401 && !str_contains($bad->body, $token), '401 gentager ikke den sendte nøgle');
F003Check::that('AK10', !str_contains($c->get('/konto')->body, substr($token, 8)), '/konto viser ikke nøglen igen');

// AccessLog: eksisterende handlinger uændrede; _ er nu tilladt, mellemrum og andet fjernes stadig
$t = $dir . '/accesslog-test.log';
AccessLog::setFile($t);
AccessLog::write(7, 'transcript', ['opkald' => 42]);
AccessLog::write(7, 'lyd', ['opkald' => 43]);
AccessLog::write(7, 'zip', ['antal' => 3, 'filter' => 'ab12']);
AccessLog::write(7, 'mcp search_calls', ['antal' => 1]);
AccessLog::write(7, "mcp_x\nbruger=1", ['antal' => '5 bruger=1']);
$l = explode("\n", trim((string) file_get_contents($t)));
$tail = array_map(static fn(string $x): string => (string) substr($x, (int) strpos($x, ' ') + 1), $l);
F003Check::that('AK10', $tail === ['bruger=7 transcript opkald=42', 'bruger=7 lyd opkald=43', 'bruger=7 zip antal=3 filter=ab12',
    'bruger=7 mcpsearch_calls antal=1', 'bruger=7 mcp_xbruger antal=5be1'], 'eksisterende handlinger uændrede; ingen log-injektion: ' . json_encode($tail));
F003Check::that('AK10', (fileperms($t) & 0777) === 0600 || DIRECTORY_SEPARATOR === '\\', 'loggen er 0600');

F003Check::done();
