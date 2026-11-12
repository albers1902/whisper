<?php
declare(strict_types=1);

// F004-AK6: opkaldslisten viser som standard Zendesk og succes-opkald. "Alle opkald" viser også de øvrige. Filteret står
// i URL'en (vis=alle), bæres med i links, pager, chips og zip, og "Nulstil filtre" bevarer det. Kilde er kolonne nr. 2.

require_once __DIR__ . '/f004_lib.php';

f004_setup();
$c = f002_login();
$z = f002_insert(['agent_name' => 'Zara Zendesk', 'started_at' => '2026-11-12 09:00:00']);
$ok = f004_insert(4001, true, 'ventende', '2026-11-12 10:00:00');
$no = f004_insert(4002, false, 'ikke_valgt', '2026-11-12 11:00:00');

$std = f002_get($c, '/');
F004Check::that('AK6', $std->status === 200 && f005_no_inline($std), 'listen svarer 200 uden inline CSS/JS');
F004Check::that('AK6', str_contains($std->body, '/opkald/' . $z) && str_contains($std->body, '/opkald/' . $ok), 'standard: Zendesk og succes-opkald');
F004Check::that('AK6', !str_contains($std->body, '/opkald/' . $no . '"') && !str_contains($std->body, '/opkald/' . $no . '?'), 'standard: ikke "ikke valgt"-opkaldet');
F004Check::that('AK6', str_contains($std->body, '<th scope="col">Dato og tid</th><th scope="col">Kilde</th>'), 'Kilde er kolonne nr. 2');
F004Check::that('AK6', str_contains($std->body, '<span class="tag tag-source">Adversus</span>') && str_contains($std->body, '<span class="tag tag-source">Zendesk</span>'),
    'kilden vises som neutralt tag');
F004Check::that('AK6', !str_contains($std->body, 'tag-success'), 'standard: intet Succes-lead-mærke i listen (Team Lead 2)');
F004Check::that('AK6', str_contains($std->body, '<a href="/" aria-current="true">Succes-leads</a>') && str_contains($std->body, '<a href="/?vis=alle">Alle opkald</a>'),
    'segmentvælgeren: Succes-leads aktiv, standarden står ikke i URL\'en');
F004Check::that('AK6', !str_contains($std->body, 'name="vis"'), 'standard: intet skjult vis-felt');

$all = f002_get($c, '/', ['vis' => 'alle']);
F004Check::that('AK6', str_contains($all->body, '/opkald/' . $no . '?vis=alle'), '"Alle opkald" viser også det øvrige, og linket bærer vis=alle');
F004Check::that('AK6', str_contains($all->body, '<a href="/?vis=alle" aria-current="true">Alle opkald</a>'), '"Alle opkald" er aktiv');
F004Check::that('AK6', str_contains($all->body, 'tag-success') && str_contains($all->body, 'Succes-lead</span>'), '"Alle opkald": Succes-lead-mærket vises');
F004Check::that('AK6', str_contains($all->body, '<input type="hidden" name="vis" value="alle">'), 'filterformularen bevarer visningen');
F004Check::that('AK6', str_contains($all->body, 'badge s-ikke-valgt">Ikke valgt<'), 'badget "Ikke valgt"');
F004Check::that('AK6', str_contains($all->body, '/opkald/download?vis=alle'), 'zip følger visningen');

$q = f002_get($c, '/', ['vis' => 'alle', 'retning' => 'outbound']);
F004Check::that('AK6', str_contains($q->body, 'class="reset" href="/?vis=alle"'), '"Nulstil filtre" bevarer visningen');
F004Check::that('AK6', str_contains($q->body, 'href="/?vis=alle" aria-current="true"') === false
    && str_contains($q->body, 'href="/?retning=outbound&amp;vis=alle" aria-current="true"'), 'segmentvælgeren bevarer filtrene');

$bad = f002_get($c, '/', ['vis' => '<script>']);
F004Check::that('AK6', !str_contains($bad->body, '/opkald/' . $no . '"') && !str_contains($bad->body, '<script>'), 'ukendt vis = standard');

// Tom standardvisning: henvis til "Alle opkald"
\Whisper\DB::exec('DELETE FROM calls WHERE id IN (?, ?)', [$z, $ok]);
$empty = f002_get($c, '/');
F004Check::that('AK6', str_contains($empty->body, 'Vis alle opkald') && str_contains($empty->body, 'succes-leads fra Adversus'), 'tom standardvisning');
F004Check::done();
