<?php
declare(strict_types=1);

// F003-AK3: search_calls med fritekst og filtre giver samme opkald som F002's liste, højst 50 rækker, og ugyldige
// datoer eller ukendte argumenter giver isError. Ingen lydstier eller recording-URL'er i svaret.

require_once __DIR__ . '/f003_lib.php';

use Whisper\Calls;

f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

// 60 opkald med "faktura", fordelt på dage, retninger og agenter, plus nogle uden
$days = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'];
for ($i = 0; $i < 60; $i++) {
    f003_call(['started_at' => $days[$i % 6] . sprintf(' %02d:%02d:00', 8 + $i % 9, $i % 60), 'direction' => $i % 3 === 0 ? 'outbound' : 'inbound',
        'agent_name' => $i % 2 === 0 ? 'Agent A' : 'Søren Ærø', 'audio_path' => 'recordings/2026/10/' . (900 + $i) . '.mp3'],
        'Kunden spørger om sin faktura nummer ' . $i . ' og om strømmen.');
}
for ($i = 0; $i < 5; $i++) {
    f003_call(['started_at' => '2026-10-03 12:0' . $i . ':00'], 'Noget helt andet om vejret.');
}

/** Opkalds-id'erne i F002's liste (samme filter), side 1. @param array<string, string> $q @return list<int> */
$ui = static function (array $q): array {
    return array_map(static fn(array $r): int => (int) $r['id'], Calls::search(Calls::filter($q))['rows']);
};
$ids = static fn(?array $s): array => array_map(static fn(array $c): int => (int) $c['call_id'], is_array($s['calls'] ?? null) ? $s['calls'] : []);

[$err, , $s] = f003_result($m->tool('search_calls', ['query' => 'faktura']));
F003Check::that('AK3', !$err && ($s['total'] ?? 0) === 60, 'fritekst: 60 opkald i alt');
F003Check::that('AK3', count($s['calls'] ?? []) === 20 && ($s['has_more'] ?? false) === true, 'standard 20 rækker og has_more');
F003Check::that('AK3', $ids($s) === array_slice($ui(['q' => 'faktura']), 0, 20), 'samme opkald og rækkefølge som F002-listen');

[, , $s50] = f003_result($m->tool('search_calls', ['query' => 'faktura', 'limit' => 50]));
F003Check::that('AK3', $ids($s50) === $ui(['q' => 'faktura']), 'limit 50 = hele F002-side 1');
[, , $s2] = f003_result($m->tool('search_calls', ['query' => 'faktura', 'limit' => 50, 'page' => 2]));
F003Check::that('AK3', $ids($s2) === $ui(['q' => 'faktura', 'side' => '2']) && count($ids($s2)) === 10, 'side 2 = F002-side 2');
[, , $s9] = f003_result($m->tool('search_calls', ['query' => 'faktura', 'page' => 99999]));
F003Check::that('AK3', ($s9['calls'] ?? null) === [] && ($s9['total'] ?? 0) === 60, 'side ud over listen: tom liste, ingen fejl');

$q = ['q' => 'faktura', 'fra' => '2026-10-02', 'til' => '2026-10-04', 'retning' => 'inbound', 'agent' => 'Søren Ærø'];
[$e2, , $f] = f003_result($m->tool('search_calls', ['query' => 'faktura', 'from' => '2026-10-02', 'to' => '2026-10-04',
    'direction' => 'inbound', 'agent' => 'Søren Ærø', 'limit' => 50]));
F003Check::that('AK3', !$e2 && $ids($f) === $ui($q) && $ids($f) !== [], 'periode, retning og agent: samme som F002');
$allOk = true;
foreach ($f['calls'] ?? [] as $c) {
    $allOk = $allOk && $c['direction'] === 'inbound' && $c['agent'] === 'Søren Ærø' && $c['date'] >= '2026-10-02' && $c['date'] <= '2026-10-04';
}
F003Check::that('AK3', $allOk, 'alle rækker opfylder filtrene');

[, $text, $one] = f003_result($m->tool('search_calls', ['query' => 'faktura', 'limit' => 1]));
$c = $one['calls'][0] ?? [];
F003Check::that('AK3', array_keys($c) === ['call_id', 'started_at', 'date', 'time', 'direction', 'duration_sec', 'agent', 'customer_phone',
    'ticket_id', 'transcript_status', 'url', 'excerpt'], 'kun felterne fra hvidlisten');
F003Check::that('AK3', ($c['url'] ?? '') === 'https://' . F003_HOST . '/opkald/' . ($c['call_id'] ?? 0), 'link til /opkald/{id} i Whisper');
F003Check::that('AK3', str_contains((string) ($c['excerpt'] ?? ''), 'faktura'), 'uddraget viser søgeordet');
F003Check::that('AK3', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', (string) ($c['started_at'] ?? '')) === 1, 'tid i dansk tid (ISO)');
F003Check::that('AK3', !str_contains($text, 'recordings/') && !str_contains($text, 'recording_url') && !str_contains($text, '.mp3'),
    'ingen lydstier eller recording-URL');
F003Check::that('AK3', json_decode($text, true) === $one, 'tekstindholdet er samme JSON som structuredContent');

[, , $none] = f003_result($m->tool('search_calls', ['query' => 'xyzzyikkeher']));
F003Check::that('AK3', ($none['total'] ?? -1) === 0 && ($none['calls'] ?? null) === [], 'intet fundet: tom liste, ikke fejl');

$bad = [
    [['from' => '2026-13-45'], 'Ugyldig dato'],
    [['to' => '05-10-2026'], 'Ugyldig dato'],
    [['from' => '2026-10-05', 'to' => '2026-10-01'], 'ligger efter'],
    [['limit' => 51], 'heltal mellem 1 og 50'],
    [['limit' => 0], 'heltal mellem 1 og 50'],
    [['limit' => '20'], 'heltal'],
    [['direction' => 'sideways'], 'inbound'],
    [['query' => ['a']], 'tekst'],
    [['query' => str_repeat('a', 201)], '200 tegn'],
    [['foo' => 'bar'], 'Ukendt argument: "foo"'],
];
foreach ($bad as [$args, $expect]) {
    [$isErr, $msg] = f003_result($m->tool('search_calls', $args));
    F003Check::that('AK3', $isErr && str_contains($msg, $expect), 'isError for ' . json_encode($args) . ": $msg");
}
$listArgs = f003_json($m->rpc('tools/call', ['name' => 'search_calls', 'arguments' => ['a', 'b']]));
F003Check::that('AK3', f003_result($listArgs)[0] === true, 'argumenter som liste: isError');

F003Check::done();
