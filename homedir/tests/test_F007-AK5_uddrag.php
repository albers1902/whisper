<?php
declare(strict_types=1);

// F007-AK5: kortet viser et uddrag på to linjer (line-clamp i CSS) af samtalens begyndelse; med talere som "Agent:"/
// "Kunde:" ved hvert skift, ellers de første ord. Uddraget skæres i SQL (Calls::excerpts), så hele transcripts aldrig når
// PHP, og en afkortet JSON repareres. Med søgning vinder søgeuddraget. Intet uddrag uden færdigt transcript.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\UiCallsV2;

f007_setup();
$c = f002_login();
$sp = f002_insert(['started_at' => '2026-11-12 09:00:00']);
f002_audio($sp);
$segs = [
    ['start' => 0.4, 'end' => 2.0, 'text' => 'Tryg Energi, du taler med Sara.', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 2.5, 'end' => 4.0, 'text' => 'Hej, det er om min faktura <b>.', 'speaker' => 1, 'role' => 'kunde'],
    ['start' => 4.5, 'end' => 6.0, 'text' => 'Og flytningen.', 'speaker' => 1, 'role' => 'kunde'],
];
for ($i = 0; $i < 300; $i++) {
    $segs[] = ['start' => 10.0 + $i, 'end' => 11.0 + $i, 'text' => 'Fyld ' . $i . ' "}, med tegn', 'speaker' => $i % 2, 'role' => $i % 2 === 0 ? 'agent' : 'kunde'];
}
f002_transcript($sp, str_repeat('Lang tekst. ', 2000), $segs);
$plain = f002_insert(['started_at' => '2026-11-12 08:00:00']);
f002_audio($plain);
f002_transcript($plain, 'Goddag, jeg ringer om ' . str_repeat('noget meget vigtigt ', 30), [['start' => 0.0, 'end' => 3.0, 'text' => 'Goddag']]);
$wait = f002_insert(['started_at' => '2026-11-12 07:00:00']);

$x = Calls::excerpts([$sp, $plain, $wait]);
F007Check::that('AK5', isset($x[$sp], $x[$plain]) && !isset($x[$wait]), 'excerpts: kun opkald med transcript');
F007Check::that('AK5', count($x[$sp]['segments']) >= 3 && count($x[$sp]['segments']) < 303, 'segmenterne er skåret i SQL og repareret ('
    . count($x[$sp]['segments']) . ' af 303)');
F007Check::that('AK5', mb_strlen($x[$plain]['text']) === Calls::EXCERPT_TEXT && $x[$plain]['cut'], 'teksten er skåret i SQL');
F007Check::that('AK5', Calls::closeJson('[{"a":1},{"a":2},{"a') === '[{"a":1},{"a":2}]' && Calls::closeJson('[{"a') === '[]',
    'closeJson lukker efter sidste hele objekt, ellers []');

$r = f007_get($c, '/');
F007Check::that('AK5', str_contains($r->body, '<p class="cc-excerpt"><span class="sr-only">Uddrag: </span><strong class="who-agent">Agent:</strong> '
    . 'Tryg Energi, du taler med Sara. <strong class="who-kunde">Kunde:</strong> Hej, det er om min faktura &lt;b&gt;. Og flytningen.'),
    'med talere: "Agent:" og "Kunde:" ved skift, ikke ved samme taler igen; teksten escaped');
F007Check::that('AK5', str_contains($r->body, '<span class="sr-only">Uddrag: </span>Goddag, jeg ringer om noget meget vigtigt'), 'uden talere: de første ord');
preg_match('#<li class="call-card">(?:(?!</li>).)*/opkald/' . $wait . '(?:(?!</li>).)*</li>#s', $r->body, $m);
F007Check::that('AK5', isset($m[0]) && !str_contains($m[0], 'cc-excerpt'), 'intet transcript: intet uddrag');
$plainText = f005_plain(UiCallsV2::excerpt($x[$plain]));
F007Check::that('AK5', mb_strlen($plainText) <= 201 && str_ends_with($plainText, '…'), 'højst ca. 200 tegn og "…" ved klip');

$s = f007_get($c, '/', ['q' => 'lang']);
F007Check::that('AK5', str_contains($s->body, '<mark>Lang</mark>') && !str_contains($s->body, 'who-agent'), 'med søgning: søgeuddraget vinder');

$css = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.css');
F007Check::that('AK5', preg_match('/\.cc-excerpt \{[^}]*-webkit-line-clamp: 2;[^}]*line-clamp: 2;/', $css) === 1, 'CSS: to linjer');
F007Check::done();
