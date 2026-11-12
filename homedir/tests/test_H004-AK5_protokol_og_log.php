<?php
declare(strict_types=1);

// H004-AK5: et ukendt værktøj logges som mcp_ukendt antal=0 uden klientens navn; {} giver "Ugyldig JSON-RPC-besked",
// en liste "Batches understøttes ikke"; et tomt uddrag er null (QA F003 F-5, F-6, F-8).

require_once __DIR__ . '/h004_lib.php';

$var = f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

$t = f003_json($m->rpc('tools/call', ['name' => 'slet_alt_xyz', 'arguments' => new stdClass()]));
$log = (string) @file_get_contents($var . '/adgang.log');
H004Check::that('AK5', ($t['error']['code'] ?? 0) === -32602, 'ukendt værktøj: stadig -32602');
H004Check::that('AK5', preg_match('/ bruger=' . $uid . ' mcp_ukendt antal=0$/m', $log) === 1, 'ukendt værktøj logges som mcp_ukendt antal=0');
H004Check::that('AK5', !str_contains($log, 'slet') && !str_contains($log, 'xyz'), 'klientens værktøjsnavn står ikke i loggen');

foreach (['{}' => 'Ugyldig JSON-RPC-besked.', '  {}' => 'Ugyldig JSON-RPC-besked.', '{"0":1}' => 'Ugyldig JSON-RPC-besked.',
    '[]' => 'Batches understøttes ikke', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]' => 'Batches understøttes ikke'] as $body => $want) {
    $d = f003_json($m->raw('POST', $body, ['mcp-protocol-version' => '2025-11-25']));
    H004Check::that('AK5', ($d['error']['code'] ?? 0) === -32600 && str_starts_with((string) ($d['error']['message'] ?? ''), $want),
        "$body: -32600 \"$want\"");
}

$empty = f003_call(['started_at' => '2026-10-06 09:00:00'], '');
$full = f003_call(['started_at' => '2026-10-05 09:00:00'], 'Der er noget tekst her.');
[$err, , $s] = f003_result($m->tool('search_calls', []));
$byId = [];
foreach ($s['calls'] ?? [] as $row) {
    $byId[(int) $row['call_id']] = $row;
}
H004Check::that('AK5', !$err && array_key_exists('excerpt', $byId[$empty] ?? []) && $byId[$empty]['excerpt'] === null,
    'tom tekst: excerpt er null, ikke ""');
H004Check::that('AK5', is_string($byId[$full]['excerpt'] ?? null) && str_contains((string) $byId[$full]['excerpt'], 'tekst her'),
    'en tekst: excerpt er stadig en streng');

H004Check::done();
