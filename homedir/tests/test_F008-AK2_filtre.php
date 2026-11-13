<?php
declare(strict_types=1);

// F008-AK2: periode, agent, retning, søgning, statusfiltrene og "Succes og valgte / Alle" virker på leads og står i URL'en.
// Et lead er med, hvis mindst ét opkald matcher, og sorteres efter det seneste matchende opkald. "Færdige" = mindst ét
// besvaret og alle besvarede færdige; "I kø" = mindst ét opkald i kø. Søgning viser det første matchende opkalds uddrag.

require_once __DIR__ . '/f008_lib.php';

f008_setup();
$c = f002_login();

// 601: succes, to færdige (begge besvarede), Sara + Mads
$s1 = f008_call(['lead_id' => 601, 'is_success' => 1, 'started_at' => '2026-11-02 10:00:00', 'agent_name' => 'Sara', 'transcript_status' => 'faerdig']);
f008_done($s1, 'Vi aftaler en faktura i dag');
$s2 = f008_call(['lead_id' => 601, 'is_success' => 1, 'started_at' => '2026-11-09 10:00:00', 'agent_name' => 'Mads', 'transcript_status' => 'faerdig',
    'direction' => 'inbound']);
f008_done($s2, 'Tak for fakturaen');
f008_call(['lead_id' => 601, 'is_success' => 1, 'started_at' => '2026-11-10 10:00:00', 'disposition' => 'busy', 'agent_name' => 'Eva']);   // ubesvaret tæller ikke
// 602: ikke succes, ét manuelt valgt opkald i kø og ét ikke valgt
$m1 = f008_call(['lead_id' => 602, 'started_at' => '2026-11-04 10:00:00', 'transcript_status' => 'ventende', 'agent_name' => 'Eva']);
f002_audio($m1);
f008_call(['lead_id' => 602, 'started_at' => '2026-11-11 10:00:00', 'agent_name' => 'Jonas']);
// 603: ikke succes og intet valgt (kun under "Alle leads")
f008_call(['lead_id' => 603, 'started_at' => '2026-11-08 10:00:00', 'agent_name' => 'Sara']);
// 604: succes, ét færdigt og ét besvaret, der ikke er færdigt (ikke "Færdige")
$p1 = f008_call(['lead_id' => 604, 'is_success' => 1, 'started_at' => '2026-11-05 10:00:00', 'transcript_status' => 'faerdig', 'agent_name' => 'Eva']);
f008_done($p1, 'Første del');
f008_call(['lead_id' => 604, 'is_success' => 1, 'started_at' => '2026-11-06 10:00:00', 'transcript_status' => 'fejlet', 'audio_status' => 'hentet', 'agent_name' => 'Eva']);

$ids = static fn(array $q): array => f008_leads(f007_get($c, '/', ['kilde' => 'adversus'] + $q));
F008Check::that('AK2', $ids([]) === [601, 604, 602], 'Succes og valgte: succes-leads + lead med valgt opkald, efter seneste matchende: ' . implode(',', $ids([])));
F008Check::that('AK2', $ids(['vis' => 'alle']) === [602, 601, 603, 604], 'Alle leads: alle med opkald');
F008Check::that('AK2', $ids(['vis' => 'alle', 'agent' => 'Sara']) === [603, 601], 'agent: leads med mindst ét opkald med agenten, efter dets tid');
F008Check::that('AK2', $ids(['vis' => 'alle', 'fra' => '2026-11-07', 'til' => '2026-11-09']) === [601, 603], 'periode');
F008Check::that('AK2', $ids(['vis' => 'alle', 'retning' => 'inbound']) === [601], 'retning');
F008Check::that('AK2', $ids(['vis' => 'alle', 'status' => 'faerdig']) === [601], 'Færdige: alle besvarede færdige');
F008Check::that('AK2', $ids(['vis' => 'alle', 'status' => 'koe']) === [602], 'I kø: mindst ét opkald i kø');
$r = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle', 'q' => 'faktura']);
F008Check::that('AK2', f008_leads($r) === [601], 'søgning: leads med et matchende transcript');
F008Check::that('AK2', str_contains($r->body, '<p class="cc-excerpt"><span class="sr-only">Uddrag: </span><strong class="who-n">2. nov. 11:00 — </strong>')
    && str_contains($r->body, '<mark>faktura</mark>'), 'uddrag fra det første matchende opkald med dets tid');
F008Check::that('AK2', str_contains($r->body, 'href="/lead/601?q=faktura&amp;vis=alle&amp;kilde=adversus"'), 'kortets link bærer filtrene (ctx)');
F008Check::that('AK2', str_contains($r->body, 'href="/?q=faktura&amp;vis=alle&amp;kilde=adversus&amp;status=faerdig"'), 'statuspillerne bevarer filtrene');
$empty = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle', 'agent' => 'Ingen']);
F008Check::that('AK2', str_contains($empty->body, 'Ingen leads matcher') && str_contains($empty->body, 'Nulstil filtre'), 'tom med filtre');
$koe = f007_get($c, '/', ['kilde' => 'adversus', 'status' => 'koe', 'agent' => '']);
F008Check::that('AK2', f008_leads($koe) === [602], 'I kø også under Succes og valgte');
F008Check::done();
