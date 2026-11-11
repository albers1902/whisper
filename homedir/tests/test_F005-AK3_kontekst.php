<?php
declare(strict_types=1);

// F005-AK3: links fra listen til detaljen bærer listens filtre og side, og tilbage-linket på detaljen fører til
// /?<samme query>. Uden filtre: "Alle opkald" til /. Et ukendt opkald giver 404 med "Til opkald" til samme liste.

require_once __DIR__ . '/f005_lib.php';

f005_setup();
$c = f002_login();
for ($i = 0; $i < 55; $i++) {
    f002_insert(['started_at' => gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + $i * 3600), 'agent_name' => 'Anne & Bo']);
}
$query = ['fra' => '2026-10-01', 'agent' => 'Anne & Bo', 'side' => '2'];
$r = f002_get($c, '/', $query);
preg_match_all('#<a class="row-link" href="(/opkald/([0-9]+)\?[^"]+)">#', $r->body, $m);
F005Check::that('AK3', count($m[1]) === 5, 'side 2 har 5 rækker med row-link (fik ' . count($m[1]) . ')');
$href = html_entity_decode($m[1][0] ?? '', ENT_QUOTES);
$ctx = 'fra=2026-10-01&agent=Anne%20%26%20Bo&side=2';
F005Check::that('AK3', str_ends_with($href, '?' . $ctx), 'detalje-linket bærer filtre og side: ' . $href);

$id = (int) ($m[2][0] ?? 0);
$d = f002_get($c, '/opkald/' . $id, $query);
F005Check::that('AK3', $d->status === 200 && str_contains($d->body, '<a href="/?' . htmlspecialchars($ctx, ENT_QUOTES) . '">')
    && str_contains($d->body, 'Tilbage til opkald</a>'), 'tilbage-linket: /?<samme query> og "Tilbage til opkald"');

$plain = f002_get($c, '/opkald/' . $id);
F005Check::that('AK3', str_contains($plain->body, '<a href="/">') && str_contains($plain->body, 'Alle opkald</a>'), 'uden filtre: "Alle opkald" til /');

$bad = f002_get($c, '/opkald/' . $id, ['agent' => 'x"><script>', 'side' => 'abc']);
F005Check::that('AK3', !str_contains($bad->body, '<script>') && str_contains($bad->body, 'agent=x%22%3E%3Cscript%3E'),
    'query-værdier renses og URL-kodes (ingen markup)');

$missing = f002_get($c, '/opkald/999999999', $query);
F005Check::that('AK3', $missing->status === 404 && str_contains($missing->body, 'Opkaldet findes ikke.')
    && str_contains($missing->body, 'href="/?' . htmlspecialchars($ctx, ENT_QUOTES) . '">Til opkald</a>'), '404 med "Til opkald" til samme liste');

// Pager og chips bevarer også de andre filtre
F005Check::that('AK3', str_contains($r->body, 'href="/?fra=2026-10-01&amp;agent=Anne%20%26%20Bo"'), 'pager "Forrige" bærer filtrene');
F005Check::done();
