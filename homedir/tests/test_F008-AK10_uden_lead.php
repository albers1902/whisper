<?php
declare(strict_types=1);

// F008-AK10: opkald uden lead-id forsvinder ikke: kortet "Opkald uden lead" står først på side 1 (med antal, ikke i
// leadtallet), og /lead/uden viser dem som F007's opkaldskort med fanens filtre. Står der kun opkald uden lead, vises
// kortet over den tomme tilstand.

require_once __DIR__ . '/f008_lib.php';

f008_setup();
$c = f002_login();
$o1 = f008_call(['lead_id' => null, 'started_at' => '2026-11-03 08:00:00', 'agent_name' => 'Sara']);
$o2 = f008_call(['lead_id' => null, 'started_at' => '2026-11-04 08:00:00', 'agent_name' => 'Mads']);

$only = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
F008Check::that('AK10', str_contains($only->body, '<li class="call-card lead-card lead-orphan">') && str_contains($only->body, 'href="/lead/uden?vis=alle&amp;kilde=adversus"')
    && str_contains($only->body, '2 opkald uden lead') && str_contains($only->body, 'Ingen Adversus-leads endnu'), 'kun opkald uden lead: kortet over den tomme tilstand');

f008_call(['lead_id' => 1301, 'started_at' => '2026-11-05 08:00:00']);
$r = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
F008Check::that('AK10', f008_leads($r) === [1301] && str_contains($r->body, 'Viser 1–1 af 1 leads · 2 opkald uden lead'), 'kortet tæller ikke med i leadtallet');
F008Check::that('AK10', strpos($r->body, 'lead-orphan') < strpos($r->body, 'href="/lead/1301'), '"Uden lead" står først');
F008Check::that('AK10', str_contains($r->body, 'Opkald, som Adversus ikke har knyttet til et lead'), 'forklaring på kortet');
$f = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle', 'agent' => 'Mads']);
F008Check::that('AK10', str_contains($f->body, '1 opkald uden lead'), 'filtrene gælder også "Uden lead"');

$u = f007_get($c, '/lead/uden', ['kilde' => 'adversus', 'vis' => 'alle']);
F008Check::that('AK10', $u->status === 200 && str_contains($u->body, '<h1>Opkald uden lead</h1>') && f007_ids($u) === [$o2, $o1], '/lead/uden: opkaldene, nyeste først');
F008Check::that('AK10', str_contains($u->body, 'href="/opkald/' . $o2 . '?vis=alle&amp;kilde=adversus"') && str_contains($u->body, '<p class="back"><a href="/?vis=alle&amp;kilde=adversus">'),
    'kortene linker til /opkald med konteksten; tilbage til listen');
$um = f007_get($c, '/lead/uden', ['kilde' => 'adversus', 'vis' => 'alle', 'agent' => 'Sara']);
F008Check::that('AK10', f007_ids($um) === [$o1], '/lead/uden: med filtre');
F008Check::that('AK10', f005_no_inline($u), 'ingen inline style/script');
F008Check::done();
