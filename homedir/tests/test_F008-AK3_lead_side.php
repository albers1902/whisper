<?php
declare(strict_types=1);

// F008-AK3: /lead/{id} viser alle leadets opkald i tidsrækkefølge (ældste først) med afspiller og samtale pr. besvaret
// opkald (unikke id'er), ubesvarede kompakt på én linje (3+ i træk samlet), hoved med status, kampagne, kunde, antal og
// taletid. Over 100 opkald: pager. Ukendt lead: 404.

require_once __DIR__ . '/f008_lib.php';

use Whisper\DB;

f008_setup();
$c = f002_login();
f008_lead(701, 'success');
$a = f008_call(['lead_id' => 701, 'is_success' => 1, 'started_at' => '2026-11-03 08:12:00', 'duration_sec' => 181, 'transcript_status' => 'faerdig']);
f008_done($a, 'Hej', [['start' => 1.0, 'end' => 2.0, 'text' => 'Hej det er Sara', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 4.0, 'end' => 5.0, 'text' => 'Ja hej', 'speaker' => 1, 'role' => 'kunde']]);
$m1 = f008_call(['lead_id' => 701, 'is_success' => 1, 'started_at' => '2026-11-03 10:40:00', 'disposition' => 'busy']);
$nv = f008_call(['lead_id' => 701, 'is_success' => 1, 'started_at' => '2026-11-03 12:00:00', 'transcript_status' => 'ikke_valgt']);
$g = [];
foreach (['2026-11-04 07:02:00', '2026-11-04 09:00:00', '2026-11-05 15:30:00'] as $t) {
    $g[] = f008_call(['lead_id' => 701, 'is_success' => 1, 'started_at' => $t, 'disposition' => 'no-answer']);
}
$b2 = f008_call(['lead_id' => 701, 'is_success' => 1, 'started_at' => '2026-11-06 11:09:00', 'agent_name' => 'Mads', 'duration_sec' => 580,
    'transcript_status' => 'faerdig']);
f008_done($b2, 'Vi sender faktura');

$r = f007_get($c, '/lead/701', ['kilde' => 'adversus', 'vis' => 'alle']);
$b = $r->body;
F008Check::that('AK3', $r->status === 200 && str_contains($b, '<h1>Lead #701</h1>') && str_contains($b, '<title>Lead #701 · Adversus · Whisper</title>'), 'side og titel');
F008Check::that('AK3', str_contains($b, 'Succes-lead') && str_contains($b, 'Kampagne #31') && str_contains($b, '7 opkald · 3 besvarede')
    && str_contains($b, 'Taletid 14:41') && str_contains($b, 'Agenter: Sara, Mads'), 'hoved: status, kampagne, antal, taletid, agenter');
preg_match_all('/id="opkald-([0-9]+)"/', $b, $mm);
F008Check::that('AK3', array_map('intval', $mm[1]) === [$a, $m1, $nv, $b2], 'tidsrækkefølge, ældste først (gruppe uden anker)');
F008Check::that('AK3', str_contains($b, '<h3 id="oc-' . $a . '-h">Opkald 1 af 7 · 3. nov. 2026 kl. 09:12</h3>') && str_contains($b, 'Opkald 7 af 7 · 6. nov. 2026 kl. 12:09'),
    'H3 pr. besvaret opkald med nummer af alle');
F008Check::that('AK3', str_contains($b, '<audio id="afspiller-' . $a . '" controls') && str_contains($b, '<audio id="afspiller-' . $b2 . '" controls')
    && str_contains($b, 'aria-label="Optagelse, opkald 1"') && !str_contains($b, 'id="afspiller"'), 'én afspiller pr. besvaret opkald med unikt id');
F008Check::that('AK3', str_contains($b, 'id="transcript-' . $a . '"') && str_contains($b, 'id="t-' . $a . '-1"') && str_contains($b, 'class="transcript chat playable"'),
    'samtale som bobler med unikke ankre');
F008Check::that('AK3', str_contains($b, 'class="lead-item lt-item lead-miss" id="opkald-' . $m1 . '"') && str_contains($b, 'Ikke besvaret (optaget)'),
    'ubesvaret kompakt på én linje');
F008Check::that('AK3', str_contains($b, '<details class="lead-miss-group"><summary>3 ubesvarede opkald · 4. nov. 08:02 – 5. nov. 16:30</summary>')
    && str_contains($b, 'Ikke besvaret (intet svar)') && str_contains($b, '<span class="sr-only">Opkald 4 af 7: </span>'), '3 i træk samles i en gruppe');
F008Check::that('AK3', str_contains($b, 'name="retur" value="lead"') && str_contains($b, 'id="vaelg-hjaelp-' . $nv . '"'), '"Transcribér" pr. sektion med retur');
F008Check::that('AK3', substr_count($b, 'Succes-lead') === 1, 'succes-tagget kun i hovedet');
F008Check::that('AK3', str_contains($b, '<h2 id="forloeb-h">Forløb</h2>') && str_contains($b, '<ol class="lead-timeline">'), 'tidslinje under H2 Forløb');
F008Check::that('AK3', f005_no_inline($r), 'ingen inline style/script (CSP)');

// Ukendt lead: 404; lead uden opkald (kun i adversus_leads): tomt forløb
$nf = f007_get($c, '/lead/999999');
F008Check::that('AK3', $nf->status === 404 && str_contains($nf->body, 'Leadet findes ikke.'), 'ukendt lead: 404');
f008_lead(702, 'unqualified');
$e = f007_get($c, '/lead/702');
F008Check::that('AK3', $e->status === 200 && str_contains($e->body, 'Ingen opkald på dette lead endnu.') && str_contains($e->body, 'Ukvalificeret')
    && !str_contains($e->body, 'Download .txt'), 'lead uden opkald: tom tilstand, ingen download');

// Over 100 opkald: 100 pr. side og pager (lside), "Leadet har N opkald"
for ($i = 0; $i < 105; $i++) {
    f008_call(['lead_id' => 703, 'started_at' => gmdate('Y-m-d H:i:s', 1793000000 + $i * 60), 'agent_name' => 'Bo']);
}
$p1 = f007_get($c, '/lead/703', ['kilde' => 'adversus']);
$p2 = f007_get($c, '/lead/703', ['kilde' => 'adversus', 'lside' => '2']);
F008Check::that('AK3', substr_count($p1->body, 'class="lead-call lt-call"') === 100 && str_contains($p1->body, 'Leadet har 105 opkald. Siden viser opkald 1–100.')
    && str_contains($p1->body, 'href="/lead/703?kilde=adversus&amp;lside=2"'), 'side 1: 100 opkald og pager');
F008Check::that('AK3', substr_count($p2->body, 'class="lead-call lt-call"') === 5 && str_contains($p2->body, 'Opkald 101 af 105'), 'side 2: resten');
DB::exec('DELETE FROM calls');
F008Check::done();
