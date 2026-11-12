<?php
declare(strict_types=1);

// F007-AK11: "Find i samtalen" står over samtalen (kun med JS: data-js-only), markerer alle træf i boblerne eller linjerne
// med <mark> som DOM-noder (aldrig innerHTML med transcript-tekst), viser "n af m" og hopper mellem træf med Enter/pile.
// Ren klient-side: ingen ny server-søgning. Uden færdigt transcript er feltet der ikke.

require_once __DIR__ . '/f007_lib.php';

f007_setup();
$c = f002_login();
$id = f002_insert(['started_at' => '2026-11-12 09:00:00']);
f002_audio($id);
f002_transcript($id, 'x', [['start' => 0.0, 'end' => 1.0, 'text' => 'Faktura og faktura.', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 1.0, 'end' => 2.0, 'text' => 'Fakturaen er betalt.', 'speaker' => 1, 'role' => 'kunde']]);
$wait = f002_insert(['started_at' => '2026-11-12 08:00:00']);

$r = f007_get($c, '/opkald/' . $id);
F007Check::that('AK11', str_contains($r->body, '<div class="conv-head"><h2 id="transcript-h">Samtale</h2><div class="find" data-js-only hidden>'
    . '<label for="find-q" class="sr-only">Find i samtalen</label>'), 'feltet står i samtalens hoved, kun med JS');
F007Check::that('AK11', str_contains($r->body, '<input type="search" id="find-q" placeholder="Find i samtalen" autocomplete="off" aria-describedby="find-status">'
    . '<span class="find-status" id="find-status" aria-live="polite"></span>'), 'søgefelt og "n af m"-status (aria-live)');
F007Check::that('AK11', substr_count($r->body, '<p class="say">') === 2, 'teksten, der søges i, står i .say');
$w = f007_get($c, '/opkald/' . $wait);
F007Check::that('AK11', !str_contains($w->body, 'find-q'), 'uden transcript: intet felt');

$js = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.js');
F007Check::that('AK11', str_contains($js, "d.createElement('mark')") && str_contains($js, ".splitText(") && str_contains($js, "m.className = 'find-hit'")
    && !str_contains($js, 'innerHTML') && !str_contains($js, 'insertAdjacentHTML'), 'JS: træf som <mark>-noder via splitText, ingen HTML-strenge');
F007Check::that('AK11', str_contains($js, "(active + 1) + ' af ' + hits.length") && str_contains($js, "'Ingen træf'"), 'JS: "n af m" og "Ingen træf"');
F007Check::that('AK11', str_contains($js, "ev.key === 'Enter' || ev.key === 'ArrowDown'") && str_contains($js, "ev.key === 'ArrowUp'")
    && str_contains($js, 'ev.shiftKey ? active - 1 : active + 1') && str_contains($js, "ev.key === 'Escape'"), 'JS: Enter/pil ned, Skift+Enter/pil op, Esc rydder');
F007Check::that('AK11', str_contains($js, "closest('.say')") && !str_contains($js, 'fetch(') && !str_contains($js, 'XMLHttpRequest'),
    'JS: søger kun i samtalens tekst og spørger ikke serveren');
F007Check::done();
