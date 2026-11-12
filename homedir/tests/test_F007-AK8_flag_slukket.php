<?php
declare(strict_types=1);

// F007-AK8: med F007_kilder slukket er alle sider som før F007. (1) Renderfunktionerne giver byte for byte det samme som
// main @ 3c0be86 (snapshot i fixtures/f007_ak8.json, lavet før F007-koden). (2) Hele vejen gennem App med database:
// ingen v2-assets, ingen fonte, ingen kilde/status i links, samme rækker. (3) MCP er uændret, også med flaget tændt.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\Flags;
use Whisper\Mcp\Tools;
use Whisper\UiV2;

// 1. Snapshot (uden database)
$want = json_decode((string) file_get_contents(__DIR__ . '/fixtures/f007_ak8.json'), true);
$got = f007_snapshot_pages(['F007_kilder' => false]);
$diff = [];
foreach (is_array($want) ? $want : [] as $name => $html) {
    if (($got[$name] ?? null) !== $html) {
        $diff[] = $name;
    }
}
F007Check::that('AK8', is_array($want) && count($want) === 36 && $diff === [], 'snapshot: 36 sider/værdier byte for byte som før F007'
    . ($diff !== [] ? ' (forskel: ' . implode(', ', $diff) . ')' : ''));

// 2. Gennem App
f007_setup();
Flags::override(UiV2::FLAG, false);
$c = f002_login();
$z = f002_insert(['started_at' => '2026-11-12 09:00:00']);
f002_audio($z);
f002_transcript($z, 'Hej fra Zendesk');
$a = f004_insert(8001, true, 'ventende', '2026-11-12 10:00:00');
foreach (['/' => [], '/?q=hej' => ['q' => 'hej', 'kilde' => 'adversus', 'status' => 'koe'], '/opkald/' . $z => ['kilde' => 'adversus'], '/konto' => []] as $label => $q) {
    $path = explode('?', $label)[0];
    $r = f002_get($c, $path, $q);
    $b = $r->body;
    F007Check::that('AK8', $r->status === 200 && str_contains($b, '/assets/app.css?v=') && !str_contains($b, 'app-v2') && !str_contains($b, '/assets/fonts/')
        && !str_contains($b, 'class="sidebar"') && !str_contains($b, 'kilde=adversus') && !str_contains($b, 'status=koe'), "$label: F005-siden, ingen v2, ingen kilde/status");
}
$list = f002_get($c, '/', ['kilde' => 'adversus']);
F007Check::that('AK8', str_contains($list->body, '/opkald/' . $z . '"') && str_contains($list->body, '/opkald/' . $a . '"')
    && str_contains($list->body, '<th scope="col">Kilde</th>'), 'listen som F004: begge kilder i én tabel, kilde= ignoreres');
F007Check::that('AK8', array_keys(Calls::filter(['kilde' => 'adversus', 'status' => 'koe'])) === ['fra', 'til', 'retning', 'agent', 'q', 'side', 'vis']
    && Calls::uiFilter(['kilde' => 'adversus']) === Calls::filter(['kilde' => 'adversus']), 'filter og uiFilter: samme nøgler som før');

// 3. MCP er uændret med flaget tændt: filter() giver intet kildefilter
$before = Calls::range(Calls::filter([]), 0, 50);
Flags::override(UiV2::FLAG, true);
$after = Calls::range(Calls::filter([]), 0, 50);
$res = Tools::call('search_calls', ['query' => 'hej'], 'https://whisper.test', time());
F007Check::that('AK8', $before === $after && count($after['rows']) === 2, 'MCP-udsnit: samme rækker med og uden flaget (begge kilder)');
F007Check::that('AK8', str_contains((string) json_encode($res, JSON_UNESCAPED_UNICODE), (string) $z), 'MCP search_calls virker med flaget tændt');
F007Check::that('AK8', !str_contains((string) json_encode(Tools::definitions()), 'kilde'), 'MCP-værktøjerne har ingen ny parameter');
F007Check::done();
