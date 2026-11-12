<?php
declare(strict_types=1);

// F007-AK6: detaljen viser samtalen som chatbobler, når der er talere (Agent til venstre, Kunde til højre, Taler N i
// neutrale farver), ellers én kolonne. Hver boble har en tidsstempel-knap (data-start), og app-v2.js spoler afspilleren
// ved klik på boblen. Afspillerkortet har play, spolelinje, tider, hastigheder (0,75×–2×) og "Download lyd".

require_once __DIR__ . '/f007_lib.php';

use Whisper\Flags;
use Whisper\Transcription\DiarizationSettings;

f007_setup();
$c = f002_login();
$id = f002_insert(['started_at' => '2026-11-12 09:00:00', 'duration_sec' => 181]);
f002_audio($id);
f002_transcript($id, 'x', [
    ['start' => 0.4, 'end' => 2.0, 'text' => 'Hej, du taler med Mette.', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 2.5, 'end' => 4.0, 'text' => 'Det er om regningen <script>.', 'speaker' => 1, 'role' => 'kunde'],
    ['start' => 4.5, 'end' => 6.0, 'text' => 'Og flytningen.', 'speaker' => 1, 'role' => 'kunde'],
]);
$n = f002_insert(['started_at' => '2026-11-12 08:00:00']);
f002_audio($n);
f002_transcript($n, 'x', [['start' => 0.0, 'end' => 1.0, 'text' => 'Hej.', 'speaker' => 0], ['start' => 1.5, 'end' => 3.0, 'text' => 'Goddag.', 'speaker' => 1]]);
$plain = f002_insert(['started_at' => '2026-11-12 07:00:00']);
f002_audio($plain);
f002_transcript($plain, 'x', [['start' => 0.4, 'end' => 2.0, 'text' => 'Hej.'], ['start' => 75.0, 'end' => 80.0, 'text' => 'Farvel.']]);

$r = f007_get($c, '/opkald/' . $id);
$b = $r->body;
F007Check::that('AK6', str_contains($b, '<ol class="transcript chat playable" id="transcript">'), 'chatbobler med afspillelig lyd');
F007Check::that('AK6', str_contains($b, '<li id="t-0" class="b b-agent b-l" data-start="0.40" data-who="Agent"><div class="b-head"><span class="who">Agent</span>'
    . '<button type="button" class="ts" data-start="0.40" disabled aria-label="Afspil fra 00:00">00:00</button>'), 'Agent til venstre med tidsstempel-knap');
F007Check::that('AK6', str_contains($b, 'class="b b-kunde b-r" data-start="2.50" data-who="Kunde"><div class="b-head"><span class="who">Kunde</span>'),
    'Kunde til højre');
F007Check::that('AK6', str_contains($b, 'class="b b-kunde b-r b-cont" data-start="4.50" data-who="Kunde"><div class="b-head"><span class="who sr-only">Kunde</span>'),
    'samme taler igen: etiketten er sr-only');
F007Check::that('AK6', str_contains($b, 'Det er om regningen &lt;script&gt;.') && !str_contains($b, 'regningen <script>'), 'teksten er escaped');
F007Check::that('AK6', str_contains($b, '<section class="player-v2" aria-label="Optagelse"><audio id="afspiller" controls preload="metadata" src="/opkald/'
    . $id . '/lyd"></audio>') && str_contains($b, 'class="pl-play" aria-label="Afspil"')
    && str_contains($b, 'aria-label="Spol" aria-valuetext="0:00 af 3:01"') && str_contains($b, 'data-rate="0.75" aria-pressed="false">0,75×')
    && str_contains($b, 'data-rate="2" aria-pressed="false">2×') && str_contains($b, '/lyd" download>'), 'afspillerkort: play, spol, 5 hastigheder, Download lyd');
F007Check::that('AK6', strpos($b, 'class="d-player"') < strpos($b, 'class="d-conv"'), 'afspilleren står før samtalen i DOM (fokusrækkefølge)');
F007Check::that('AK6', str_contains($b, 'href="/opkald/' . $id . '/transcript">') && str_contains($b, 'data-copy="#transcript"'), 'Download .txt og Kopiér');

$rn = f007_get($c, '/opkald/' . $n);
F007Check::that('AK6', str_contains($rn->body, 'class="b b-n b-l"') && str_contains($rn->body, 'class="b b-n b-r"')
    && str_contains($rn->body, '<span class="who">Taler 1</span>') && !str_contains($rn->body, 'b-agent'), 'Taler 1/2: neutrale bobler på hver side');
$rp = f007_get($c, '/opkald/' . $plain);
F007Check::that('AK6', str_contains($rp->body, '<ol class="transcript playable" id="transcript"><li id="t-0" data-start="0.40"><button type="button" class="ts"')
    && !str_contains($rp->body, 'class="b '), 'uden talere: én kolonne');
Flags::override(DiarizationSettings::FLAG, false);
$off = f007_get($c, '/opkald/' . $id);
F007Check::that('AK6', !str_contains($off->body, 'transcript chat') && str_contains($off->body, 'Hej, du taler med Mette.'), 'H011 slukket: én kolonne');

$js = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.js');
F007Check::that('AK6', str_contains($js, "ev.target.closest('#transcript.chat li[data-start]')") && str_contains($js, 'audio.currentTime = sec')
    && str_contains($js, "String(window.getSelection())"), 'JS: klik på en boble spoler (ikke ved markeret tekst)');
F007Check::that('AK6', str_contains($js, "who ? who + ': '"), 'JS: Kopiér giver .txt-formatet "[00:12] Agent: tekst"');
F007Check::done();
