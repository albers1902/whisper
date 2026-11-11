<?php
declare(strict_types=1);

// F005-AK10: søgeuddrag i listen (ved q) har højst 160 tegn omkring første fund, fremhævet og escaped korrekt.
// Uddraget skæres i SQL (Calls::snippets), så hele transcriptet aldrig hentes til listen.

require_once __DIR__ . '/f005_lib.php';

use Whisper\Calls;
use Whisper\Ui;

f005_setup();
$c = f002_login();

$long = f002_insert(['started_at' => '2026-10-05 10:00:00']);
f002_transcript($long, str_repeat('indledning og snak om vejret ', 400) . 'her nævner kunden sin <b>faktura</b> & flytning '
    . str_repeat('og så videre med mere tekst ', 400));
$short = f002_insert(['started_at' => '2026-10-04 10:00:00']);
f002_transcript($short, 'Faktura & <script>alert(1)</script> til kunden.');
$other = f002_insert(['started_at' => '2026-10-03 10:00:00']);
f002_transcript($other, 'Intet relevant her.');

$r = f002_get($c, '/', ['q' => 'faktura']);
preg_match_all('#<td colspan="7" class="snippet"><span class="sr-only">Uddrag: </span><span class="snippet-text">(.*?)</span></td>#u', $r->body, $m);
F005Check::that('AK10', count($m[1]) === 2, 'to rækker matcher og har et uddrag (fik ' . count($m[1]) . ')');
$first = $m[1][0] ?? '';
$plain = f005_plain($first);
F005Check::that('AK10', mb_strlen($plain) <= Ui::SNIPPET_MAX, 'højst 160 tegn (fik ' . mb_strlen($plain) . ')');
F005Check::that('AK10', str_contains($first, '&lt;b&gt;<mark>faktura</mark>&lt;/b&gt; &amp; flytning'), 'fundet er fremhævet og escaped');
F005Check::that('AK10', str_starts_with($plain, '…') && str_ends_with($plain, '…'), 'afkortet i begge ender med …');
$second = $m[1][1] ?? '';
F005Check::that('AK10', $second === '<mark>Faktura</mark> &amp; &lt;script&gt;alert(1)&lt;/script&gt; til kunden.', 'kort transcript: helt, escaped');
F005Check::that('AK10', !str_contains($r->body, '<script>alert'), 'ingen rå markup fra transcripts');
F005Check::that('AK10', !str_contains(f002_get($c, '/')->body, 'class="snippet"'), 'uden q: ingen uddrag');

// SQL skærer vinduet: aldrig hele teksten
$s = Calls::snippets([$long, $short, $other], 'faktura');
F005Check::that('AK10', mb_strlen($s[$long]['text']) <= 480 && $s[$long]['cut_start'] && $s[$long]['cut_end'], 'vinduet fra SQL er højst 480 tegn');
F005Check::that('AK10', str_contains($s[$long]['text'], 'faktura'), 'vinduet indeholder fundet');
F005Check::that('AK10', Calls::snippets([], 'faktura') === [] && Calls::snippets([$long], '"+-') === [], 'ingen id\'er eller ingen ord: intet opslag');

// Enhed: grænser
$u = Ui::snippet(str_repeat('ord ', 200) . 'målerfejl ' . str_repeat('ord ', 200), false, false, Ui::terms('måler'));
F005Check::that('AK10', mb_strlen(f005_plain($u)) <= 160 && str_contains($u, '<mark>målerfejl</mark>'), 'præfiks fremhæves, 160-loft holder');
F005Check::that('AK10', Ui::snippet('', false, false, Ui::terms('x')) === '', 'tom tekst: intet uddrag');
F005Check::done();
