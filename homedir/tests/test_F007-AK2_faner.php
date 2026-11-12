<?php
declare(strict_types=1);

// F007-AK2: opkaldslisten har fanerne Zendesk og Adversus med antal. Fanen står i URL'en (kilde=adversus; standarden
// Zendesk skrives aldrig) og bevares i pager, filtre, zip og "Tilbage" fra detaljen — og i "Transcribér"-redirecten.
// Antallet på den inaktive fane er det, et klik giver. Over loftet (Calls::COUNT_CAP) står "10.000+".

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\DB;

f007_setup();
$c = f002_login();
$z = [];
for ($i = 0; $i < 3; $i++) {
    $z[] = f002_insert(['started_at' => '2026-11-12 0' . ($i + 1) . ':00:00', 'agent_name' => 'Zara']);
}
$a = [];
for ($i = 0; $i < 55; $i++) {
    $a[] = f004_insert(5000 + $i, true, 'faerdig', sprintf('2026-11-1%d %02d:%02d:00', $i % 2, 10 + intdiv($i, 60), $i % 60));
}
foreach ($a as $id) {
    DB::exec('INSERT INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [$id, 'Hej fra Adversus', '[]', 'turbo', 'da']);
}
f002_audio($z[0]);
f002_transcript($z[0], 'Hej fra Zendesk');

$zr = f007_get($c, '/');
F007Check::that('AK2', str_contains($zr->body, '<nav class="src-tabs" aria-label="Kilde">')
    && preg_match('#<a href="/" aria-current="page">Zendesk<span class="tab-count">3<span class="sr-only"> opkald</span></span></a>#', $zr->body) === 1
    && preg_match('#<a href="/\?kilde=adversus">Adversus<span class="tab-count">55<#', $zr->body) === 1, 'standard: Zendesk aktiv (3), Adversus 55');
F007Check::that('AK2', str_contains($zr->body, '<title>Opkald · Zendesk · Whisper</title>'), 'title: Opkald · Zendesk');
F007Check::that('AK2', !in_array(true, array_map(static fn(string $h): bool => str_contains($h, 'kilde=zendesk'), f007_hrefs($zr)), true),
    'kilde=zendesk står aldrig i et link');

$ar = f007_get($c, '/', ['kilde' => 'adversus', 'q' => 'hej']);
F007Check::that('AK2', str_contains($ar->body, '<title>Opkald · Adversus · Whisper</title>') && str_contains($ar->body, 'aria-current="page">Adversus'),
    'kilde=adversus: Adversus-fanen er aktiv');
F007Check::that('AK2', str_contains($ar->body, '<input type="hidden" name="kilde" value="adversus">'), 'søgeformularen bærer fanen');
$hrefs = f007_hrefs($ar);
$pager = array_values(array_filter($hrefs, static fn(string $h): bool => str_contains($h, 'side=2')));
F007Check::that('AK2', $pager !== [] && str_contains($pager[0], 'kilde=adversus') && str_contains($pager[0], 'q=hej'), 'pageren bærer fane og søgning');
$zip = array_values(array_filter($hrefs, static fn(string $h): bool => str_starts_with($h, '/opkald/download')));
F007Check::that('AK2', $zip !== [] && str_contains($zip[0], 'kilde=adversus'), 'zip-linket bærer fanen');
$zipIds = Calls::zipIds(Calls::uiFilter(['kilde' => 'adversus']));
F007Check::that('AK2', count($zipIds) === 55 && !in_array($z[0], $zipIds, true), 'zip på Adversus-fanen: kun Adversus');
$zipZ = Calls::zipIds(Calls::uiFilter([]));
F007Check::that('AK2', $zipZ === [$z[0]], 'zip på Zendesk-fanen: kun Zendesk');
$tabZ = array_values(array_filter($hrefs, static fn(string $h): bool => $h === '/?q=hej'));
F007Check::that('AK2', $tabZ !== [], 'Zendesk-fanens link bærer søgningen, ikke fanen');
$card = array_values(array_filter($hrefs, static fn(string $h): bool => str_starts_with($h, '/opkald/' . $a[54])));
F007Check::that('AK2', $card !== [] && str_contains($card[0], 'kilde=adversus'), 'kortets link bærer fanen');

// Detaljen: tilbage til opkaldets egen fane (også uden ctx)
$d = f007_get($c, '/opkald/' . $a[0], ['kilde' => 'adversus', 'q' => 'hej', 'side' => '2']);
F007Check::that('AK2', str_contains($d->body, '<p class="back"><a href="/?q=hej&amp;kilde=adversus&amp;side=2">'), 'detalje: tilbage med fane, søgning og side');
$d2 = f007_get($c, '/opkald/' . $a[0]);
F007Check::that('AK2', str_contains($d2->body, '<p class="back"><a href="/?kilde=adversus">'), 'detalje uden ctx: tilbage til Adversus (opkaldets kilde)');
$d3 = f007_get($c, '/opkald/' . $z[0], ['kilde' => 'adversus', 'vis' => 'alle']);
F007Check::that('AK2', str_contains($d3->body, '<p class="back"><a href="/">'), 'Zendesk-opkald: tilbage til Zendesk uden vis');

// Gamle /?vis=alle åbner Adversus med "Alle opkald"
$old = f007_get($c, '/', ['vis' => 'alle']);
F007Check::that('AK2', str_contains($old->body, 'aria-current="page">Adversus') && str_contains($old->body, 'aria-current="true">Alle opkald'),
    '/?vis=alle åbner Adversus, "Alle opkald"');
// Ugyldig kilde: Zendesk uden fejl
$bad = f007_get($c, '/', ['kilde' => 'x<script>']);
F007Check::that('AK2', $bad->status === 200 && str_contains($bad->body, 'aria-current="page">Zendesk') && !str_contains($bad->body, 'x<script>'),
    'ugyldig kilde giver Zendesk');

// Transcribér-redirecten bevarer fanen (ctx renses gennem uiFilter)
$sel = f004_insert(5999, false, 'ikke_valgt', '2026-11-12 12:00:00');
$c->get('/');
$p = $c->post('/opkald/' . $sel . '/transcriber', ['ctx' => 'kilde=adversus&vis=alle&q=hej&evil=1']);
F007Check::that('AK2', $p->status === 302 && ($p->headers['Location'] ?? '') === '/opkald/' . $sel . '?q=hej&vis=alle&kilde=adversus',
    'Transcribér: redirect med fane og visning, intet andet (' . ($p->headers['Location'] ?? '') . ')');

// Loftet: over COUNT_CAP står "10.000+", og siderne stopper ved loftet
F007Check::that('AK2', Calls::count(Calls::uiFilter(['kilde' => 'adversus'])) === 55, 'count() under loftet er præcist');
F007Check::that('AK2', \Whisper\UiCallsV2::countText(Calls::COUNT_CAP + 1) === '10.000+' && \Whisper\UiCallsV2::countText(1234) === '1.234',
    'antal over loftet vises som 10.000+');
$deep = f007_get($c, '/', ['kilde' => 'adversus', 'side' => '99999']);
F007Check::that('AK2', $deep->status === 200 && str_contains($deep->body, 'Side 2 af 2'), 'side ud over listen klemmes til sidste side');
F007Check::done();
