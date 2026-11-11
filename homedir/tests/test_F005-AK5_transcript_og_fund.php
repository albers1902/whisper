<?php
declare(strict_types=1);

// F005-AK5: detaljen viser segmenter i <ol class="transcript"> med button.ts[data-start] (disabled uden JS), eller
// span.ts uden afspillelig lyd. Søgeord fremhæves med <mark>, det første med id="fund-1", og "N fund for «q»" vises.
// Fremhævningen escaper korrekt (<script> og &amp; i transcriptet).

require_once __DIR__ . '/f005_lib.php';

use Whisper\Ui;

f005_setup();
$c = f002_login();

$id = f002_insert(['started_at' => '2026-10-05 08:03:00']);
f002_audio($id);
f002_transcript($id, 'samlet', [
    ['start' => 7.4, 'end' => 9.0, 'text' => 'Ja hej, jeg ringer om min faktura.'],
    ['start' => 75.0, 'end' => 80.0, 'text' => 'Fakturaen <script>alert(1)</script> &amp; faktura-nr. 7'],
    ['start' => 3725.0, 'end' => 3726.0, 'text' => 'Farvel.'],
]);

$r = f002_get($c, '/opkald/' . $id, ['q' => 'faktura']);
$b = $r->body;
F005Check::that('AK5', $r->status === 200 && str_contains($b, '<ol class="transcript" id="transcript">'), 'segmenter i <ol class="transcript">');
F005Check::that('AK5', str_contains($b, '<li id="t-7"><button type="button" class="ts" data-start="7.40" disabled aria-label="Afspil fra 00:07">00:07</button>'),
    'button.ts[data-start] disabled med aria-label og uden kantede parenteser');
F005Check::that('AK5', str_contains($b, '>1:02:05</button>'), 'over en time: t:mm:ss');
F005Check::that('AK5', str_contains($b, 'jeg ringer om min <mark id="fund-1">faktura</mark>.'), 'første fund har id="fund-1"');
F005Check::that('AK5', substr_count($b, 'id="fund-1"') === 1, 'kun ét id="fund-1"');
F005Check::that('AK5', str_contains($b, '<mark>Fakturaen</mark> &lt;script&gt;alert(1)&lt;/script&gt; &amp;amp; <mark>faktura</mark>-nr. 7'),
    'escaping: <script> og &amp; bliver tekst, kun ord-tokens får <mark>');
F005Check::that('AK5', !str_contains($b, '<script>'), 'ingen rå <script> fra transcriptet');
F005Check::that('AK5', str_contains($b, '3 fund for «faktura» · <a href="#fund-1">Gå til første fund</a>'), '"3 fund for «faktura»" og link');
F005Check::that('AK5', str_contains($b, '<audio id="afspiller" controls preload="metadata" src="/opkald/' . $id . '/lyd"></audio>'), 'afspiller');
F005Check::that('AK5', str_contains($b, 'data-copy="#transcript" hidden') && str_contains($b, 'Download transcript (.txt)'),
    'Kopiér tekst (hidden) og Download transcript');

$none = f002_get($c, '/opkald/' . $id, ['q' => 'måler<b>'])->body;
F005Check::that('AK5', str_contains($none, 'Ingen fund for «måler&lt;b&gt;» i dette transcript.') && !str_contains($none, '<mark'),
    'ingen træf: tekst med escaped q og intet <mark>');
F005Check::that('AK5', !str_contains(f002_get($c, '/opkald/' . $id)->body, 'fund for'), 'uden q: ingen fund-linje');

// Uden afspillelig lyd: span.ts
$g = f002_insert(['started_at' => '2026-10-06 09:00:00']);
unlink(f002_audio($g));
f002_transcript($g, 'x', [['start' => 3.0, 'end' => 4.0, 'text' => 'Hej.']]);
$gb = f002_get($c, '/opkald/' . $g)->body;
F005Check::that('AK5', str_contains($gb, '<span class="ts">00:03</span>') && !str_contains($gb, 'class="ts" data-start'),
    'lydfil mangler: tidsstempler som span.ts');

// Enhed: tokenisering før escaping
$n = 0;
F005Check::that('AK5', Ui::highlight('a&b <i>ab</i>', Ui::terms('ab'), $n) === 'a&amp;b &lt;i&gt;<mark>ab</mark>&lt;/i&gt;' && $n === 1,
    'highlight: entities og tags escapes, ordet "i" fremhæves ikke');
$n = 0;
F005Check::that('AK5', Ui::highlight('amp lt gt', Ui::terms('amp'), $n) === '<mark>amp</mark> lt gt', 'søgning på "amp" rammer kun teksten, aldrig en entity');
$n = 0;
F005Check::that('AK5', Ui::highlight("ugyldig \xff faktura", Ui::terms('faktura'), $n) === 'ugyldig ' . "\u{FFFD}" . ' faktura',
    'ugyldig UTF-8: escapes med erstatningstegn, ingen fremhævning og ingen fejl');
F005Check::done();
