<?php
declare(strict_types=1);

// F004a-AK2: med et mocket Adversus-svar viser proben feltnavne, antal og optællinger over højst 5 sider og aldrig
// feltværdier.

require_once __DIR__ . '/f004a_lib.php';

f004a_setup();
$s = f004a_section();
$http = new FakeTransport();
f004a_defaults($http);

// 7 sider à 100 opkald (pageCount 7): proben må kun læse 5. Hver side: 60 udgående besvarede, 40 indgående uden svar.
$pages = [];
$id = 1000;
for ($p = 1; $p <= 7; $p++) {
    $items = [];
    for ($i = 0; $i < 100; $i++) {
        $id++;
        $items[] = $i < 60 ? f004a_call($id) : f004a_call($id, 'inbound', 'no-answer', 0, 0);
    }
    $items[0]['disposition'] = 'Kunden bad om at blive ringet op';   // fri tekst i et enum-felt
    $pages[] = f004a_page('calls', $items, $p, 7, 700);
}
$http->on('GET', F004A_API . '/calls?*', ...$pages);
// Optagelser: 3, heraf én uden download-URL, med cdr.id for to skannede opkald og ét uden for
$http->on('GET', F004A_API . '/recordings?*', f004a_page('recordings', [
    ['cdr' => ['id' => 1001, 'timestamp' => '2026-10-05T10:02:00Z'], 'recording' => ['name' => 'rec-1001.mp3',
        'download' => 'https://media.example-cdn.test/rec-1001.mp3?signature=abc'], 'clips' => [['id' => 1, 'tagId' => 2, 'download' => 'https://x.test/c']],
        'timestamp' => '2026-10-05T10:03:00Z'],
    ['cdr' => ['id' => 1002, 'timestamp' => '2026-10-05T10:02:00Z'], 'recording' => ['name' => 'rec-1002.mp3', 'download' => null],
        'clips' => [], 'timestamp' => '2026-10-05T10:03:00Z'],
    ['cdr' => ['id' => 99999999, 'timestamp' => '2026-10-05T10:02:00Z'], 'recording' => ['name' => 'r', 'download' => 'https://media.example-cdn.test/r'],
        'clips' => [], 'timestamp' => '2026-10-05T10:03:00Z'],
], 1, 1, 3));
// /sessions som et rent array (spec'ens schema) med et nøgle-navn, der er en værdi
$http->on('GET', F004A_API . '/sessions?*', f004a_json([
    ['id' => 1, 'leadId' => 2, 'userId' => 3, 'campaignId' => 4, 'status' => 'success', 'sessionSeconds' => 40,
        'cdr' => ['destination' => '004520304050', 'durationSeconds' => 30, 'disposition' => 'answered'], '+4520304050' => 'x'],
    ['id' => 2, 'status' => 'Ring igen torsdag', 'cdr' => []],
]));
$http->on('GET', F004A_API . '/leads?*', f004a_page('leads', [['id' => 5, 'masterData' => [['label' => 'Navn', 'value' => 'Jens Hansen']]]], 1, 1, 1));
$http->on('GET', F004A_API . '/calls/1001/recording', new \Whisper\Zendesk\HttpResult(404, [], '{"code":404,"message":"not found"}'));

$r = f004a_run([], $s, $http);
$out = $r['out'];
echo $out, "\n";

F004aCheck::that('AK2', $r['code'] === 0 && str_ends_with($out, 'PROBE: FÆRDIG'), 'exit 0 og sidste linje PROBE: FÆRDIG');
F004aCheck::that('AK2', count($http->to(F004A_API . '/calls?')) === 5, 'højst 5 sider af /calls (fik ' . count($http->to(F004A_API . '/calls?')) . ')');
$q = $http->to(F004A_API . '/calls?')[0]['url'];
F004aCheck::that('AK2', str_contains($q, 'pageSize=100') && str_contains($q, 'includeMeta=true') && str_contains($q, 'page=1')
    && str_contains(rawurldecode($q), '"start":{"$gt":"2026-10-01T00:00:00Z"}'), 'sideinddeling og since-filter i forespørgslen');
F004aCheck::that('AK2', str_contains($out, '/calls: 500 opkald på 5 side(r) · duration > 0: 300 · conversation > 0: 300'), 'opkald talt over 5 sider');
F004aCheck::that('AK2', str_contains($out, 'origin: inbound 200, outbound 300'), 'fordelt på retning');
F004aCheck::that('AK2', str_contains($out, 'disposition: (andet) 5, answered 295, no-answer 200'), 'disposition kun som dokumenterede enum-værdier');
F004aCheck::that('AK2', str_contains($out, '/calls: felter: anonymous, answer, billable, byeCause, campaignId, connect, contactId'), 'feltnavnene vises');
F004aCheck::that('AK2', str_contains($out, 'meta.pagination: firstUrl') || str_contains($out, 'meta.pagination: nextUrl, page, pageCount, pageSize, total'),
    'meta.pagination-felterne vises');
F004aCheck::that('AK2', str_contains($out, 'pageCount 7 · total 700'), 'pageCount og total vises');
F004aCheck::that('AK2', str_contains($out, '/recordings: 3 optagelse(r) på 1 side(r) · med download-URL: 2 · uden (null): 1 · klip: 1'), 'optagelser talt');
F004aCheck::that('AK2', str_contains($out, 'download-URL\'ens vært: media.example-cdn.test (ikke godkendt) 2'), 'kun værten for download-URL\'en');
F004aCheck::that('AK2', str_contains($out, 'Opkald med optagelse: 2 af 500 skannede opkald'), 'opkald med optagelse');
F004aCheck::that('AK2', str_contains($out, '/sessions: HTTP 200 · svar-felter: (liste) · antal på side 1: 2'), '/sessions som liste');
F004aCheck::that('AK2', str_contains($out, '/sessions: status (første side): (andet) 1, success 1'), 'session-status kun som enum');
F004aCheck::that('AK2', str_contains($out, '(1 andre nøgler)'), 'en nøgle, der ligner en værdi, vises ikke');
F004aCheck::that('AK2', str_contains($out, '/leads: felter: id, masterData') && str_contains($out, '/campaigns: HTTP 200'), 'leads og kampagner: felter');

foreach (['+4520304050', '004520304050', 'Jens Hansen', 'Kunden bad', 'Ring igen', 'TrygEnergi Test', 'signature', 'rec-1001',
    'example-cdn.test/', 'x.test', '2026-10-05T10', 'normal', 'Navn'] as $v) {
    F004aCheck::that('AK2', !str_contains($out, $v), "ingen feltværdi i output: $v");
}

F004aCheck::done();
