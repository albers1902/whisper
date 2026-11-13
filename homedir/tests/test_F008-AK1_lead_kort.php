<?php
declare(strict_types=1);

// F008-AK1: Adversus-fanen viser ét kort pr. lead med lead-nummer og status, kampagne, kundens nummer, antal opkald (heraf
// besvarede), taletid, første og seneste opkald, agenter og samlet transcription-status, sorteret efter seneste aktivitet.
// Fanens antal er antal leads.

require_once __DIR__ . '/f008_lib.php';

use Whisper\DB;
use Whisper\Leads;

f008_setup();
$c = f002_login();

// Lead 501: succes, 4 opkald (2 besvarede, begge færdige), to agenter
f008_lead(501, 'success');
$a1 = f008_call(['lead_id' => 501, 'is_success' => 1, 'started_at' => '2026-11-03 08:12:00', 'agent_name' => 'Sara', 'duration_sec' => 181,
    'transcript_status' => 'faerdig']);
f008_done($a1, 'Hej det er Sara');
f008_call(['lead_id' => 501, 'is_success' => 1, 'started_at' => '2026-11-03 10:40:00', 'disposition' => 'busy', 'duration_sec' => 0]);
f008_call(['lead_id' => 501, 'is_success' => 1, 'started_at' => '2026-11-04 07:02:00', 'disposition' => 'no-answer', 'duration_sec' => 0]);
$a4 = f008_call(['lead_id' => 501, 'is_success' => 1, 'started_at' => '2026-11-06 11:09:00', 'agent_name' => 'Mads', 'duration_sec' => 580,
    'transcript_status' => 'faerdig', 'customer_phone' => '+45 99 88 77 66']);
f008_done($a4, 'Vi sender faktura');
// Lead 502: ikke interesseret, ét opkald, seneste aktivitet (øverst)
f008_lead(502, 'notInterested');
f008_call(['lead_id' => 502, 'started_at' => '2026-11-07 09:00:00', 'agent_name' => 'Jonas', 'campaign_id' => 44]);
// Lead 503: ukendt status vises rå; et opkald i kø
f008_lead(503, 'somethingNew');
$q = f008_call(['lead_id' => 503, 'started_at' => '2026-11-05 09:00:00', 'transcript_status' => 'ventende']);
f002_audio($q);

$r = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
$b = $r->body;
F008Check::that('AK1', $r->status === 200 && f008_leads($r) === [502, 501, 503], 'ét kort pr. lead, senest aktive først: ' . implode(',', f008_leads($r)));
F008Check::that('AK1', str_contains($b, '<ol class="call-list lead-list"') && substr_count($b, 'class="call-card lead-card"') === 3, 'lead-kort i listen');
F008Check::that('AK1', str_contains($b, 'Viser 1–3 af 3 leads'), 'opsummering i leads');
F008Check::that('AK1', str_contains($b, '>Lead #501<span class="sr-only">, Succes-lead, 4 opkald'), 'titel og sr-hale');
F008Check::that('AK1', str_contains($b, 'Succes-lead</span>') && str_contains($b, '<span class="tag">Ikke interesseret</span>')
    && str_contains($b, '<span class="tag">somethingNew</span>'), 'status: succes, oversat og rå');
F008Check::that('AK1', str_contains($b, 'Kampagne #31') && str_contains($b, 'Kampagne #44'), 'kampagne');
F008Check::that('AK1', str_contains($b, '+45 99 88 77 66'), 'kundens nummer fra seneste opkald');
F008Check::that('AK1', str_contains($b, '4 opkald, 2 besvarede') && str_contains($b, '12:41<span class="sr-only"> taletid'), 'antal og taletid (kun besvarede)');
F008Check::that('AK1', str_contains($b, 'Sara og Mads'), 'agenter i rækkefølge efter første opkald');
F008Check::that('AK1', (bool) preg_match('#<time datetime="2026-11-03T08:12:00Z"[^>]*>[^<]+</time><span aria-hidden="true"> – </span><span class="sr-only"> til </span><time datetime="2026-11-06T11:09:00Z"#', $b),
    'første – seneste opkald');
F008Check::that('AK1', str_contains($b, '<span class="badge s-faerdig">2 af 2 transcriberet</span>'), 'samlet status: alle færdige (grøn)');
F008Check::that('AK1', str_contains($b, '<span class="badge s-ikke-valgt">0 af 1 transcriberet</span><span class="badge s-ventende">I kø</span>'),
    'delvis: hul pille + aktivitet I kø');
F008Check::that('AK1', str_contains($b, '<span class="badge s-ikke-valgt">0 af 1 transcriberet</span>'), 'lead uden transcript');
F008Check::that('AK1', str_contains($b, '<span class="sr-only"> leads</span>'), 'fanens antal er leads');
F008Check::that('AK1', str_contains($b, '>Alle leads</a>') && str_contains($b, '>Succes og valgte</a>'), 'succes-vælgeren siger leads');
F008Check::that('AK1', f005_no_inline($r), 'ingen inline style/script (CSP)');

// Kun ubesvarede opkald: "Ikke besvaret"
f008_call(['lead_id' => 504, 'started_at' => '2026-11-01 09:00:00', 'disposition' => 'busy']);
$r2 = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
F008Check::that('AK1', str_contains($r2->body, '<span class="badge s-ikke-valgt">Ikke besvaret</span>'), 'kun ubesvarede: Ikke besvaret');
// Flere end 3 agenter: avatarstak med +N og "og N mere"
foreach (['Bo', 'Eva', 'Ida', 'Kim', 'Lis'] as $i => $name) {
    f008_call(['lead_id' => 505, 'started_at' => '2026-11-08 0' . $i . ':00:00', 'agent_name' => $name]);
}
$r3 = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
F008Check::that('AK1', str_contains($r3->body, 'Bo, Eva og 3 mere') && str_contains($r3->body, '<span class="avatar av-0 av-more" aria-hidden="true">+2</span>'),
    'mange agenter: "og 3 mere" og +2');
F008Check::that('AK1', Leads::count(['fra' => '', 'til' => '', 'retning' => '', 'agent' => '', 'q' => '', 'side' => 1, 'vis' => 'alle']) === 5, 'antal leads');
DB::exec('DELETE FROM calls');
F008Check::done();
