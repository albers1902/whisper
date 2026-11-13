<?php
declare(strict_types=1);

// F008-AK6: /opkald/{id} består og linker til leadet ("Se hele leadet (n opkald)" og lead-nummeret i metadata). "Tilbage"
// fra lead-siden fører til Adversus-fanen med samme filtre og side. "Transcribér" på lead-siden fører tilbage til
// /lead/{eget lead}#opkald-{id}; målet bygges af serveren (aldrig en URL fra klienten, ingen åben redirect).

require_once __DIR__ . '/f008_lib.php';

use Whisper\DB;

f008_setup();
$c = f002_login();
$a = f008_call(['lead_id' => 1001, 'started_at' => '2026-11-03 08:00:00', 'transcript_status' => 'ikke_valgt']);
f008_call(['lead_id' => 1001, 'started_at' => '2026-11-04 08:00:00']);
$orphan = f008_call(['lead_id' => null, 'started_at' => '2026-11-05 08:00:00']);
$zd = f002_insert(['started_at' => '2026-11-05 08:00:00']);

$ctx = ['kilde' => 'adversus', 'vis' => 'alle', 'agent' => 'Sara', 'side' => '2'];
$d = f007_get($c, '/opkald/' . $a, $ctx);
F008Check::that('AK6', $d->status === 200 && str_contains($d->body, '<div class="actions head-actions"><a class="button secondary" href="/lead/1001?agent=Sara&amp;vis=alle&amp;kilde=adversus&amp;side=2#opkald-' . $a . '">')
    && str_contains($d->body, 'Se hele leadet (2 opkald)</a>'), '/opkald/{id}: "Se hele leadet" først i handlingerne, med kontekst');
F008Check::that('AK6', str_contains($d->body, '<dd><a href="/lead/1001?agent=Sara&amp;vis=alle&amp;kilde=adversus&amp;side=2#opkald-' . $a . '">#1001</a></dd>'),
    'lead-nummeret i metadata er et link');
F008Check::that('AK6', !str_contains(f007_get($c, '/opkald/' . $orphan)->body, 'Se hele leadet') && !str_contains(f007_get($c, '/opkald/' . $zd)->body, 'Se hele leadet'),
    'uden lead og Zendesk: intet lead-link');

$l = f007_get($c, '/lead/1001', $ctx);
F008Check::that('AK6', str_contains($l->body, '<p class="back"><a href="/?agent=Sara&amp;vis=alle&amp;kilde=adversus&amp;side=2">'), 'Tilbage: samme fane, filtre og side');
F008Check::that('AK6', str_contains($l->body, 'href="/opkald/' . $a . '?agent=Sara&amp;vis=alle&amp;kilde=adversus&amp;side=2">Åbn opkaldet'), '"Åbn opkaldet" bærer konteksten');
$bare = f007_get($c, '/lead/1001');
F008Check::that('AK6', str_contains($bare->body, '<p class="back"><a href="/?kilde=adversus">'), 'uden kontekst (fx fra MCP): til Adversus-fanen');

// "Transcribér" fra lead-siden: tilbage til eget lead og sektion, med renset kontekst
$r = $c->post('/opkald/' . $a . '/transcriber', ['ctx' => 'vis=alle&agent=Sara&side=2&kilde=adversus&evil=1', 'retur' => 'lead']);
F008Check::that('AK6', $r->status === 303 && ($r->headers['Location'] ?? '') === '/lead/1001?agent=Sara&vis=alle&kilde=adversus&side=2#opkald-' . $a,
    'PRG til /lead/{eget lead}#opkald-{id}: ' . ($r->headers['Location'] ?? ''));
$f = f007_get($c, '/lead/1001');
F008Check::that('AK6', str_contains($f->body, 'Opkaldet er sat i kø'), 'kvitteringen står på lead-siden');
// Retur kan ikke pege andre steder hen: et opkald uden lead går til /opkald, og ctx kan ikke smugle en URL ind
$r2 = $c->post('/opkald/' . $orphan . '/transcriber', ['ctx' => 'kilde=adversus', 'retur' => 'lead']);
F008Check::that('AK6', ($r2->headers['Location'] ?? '') === '/opkald/' . $orphan . '?kilde=adversus', 'uden lead: tilbage til /opkald');
DB::exec("UPDATE calls SET transcript_status = 'ikke_valgt', manual_at = NULL WHERE id = ?", [$a]);
$r3 = $c->post('/opkald/' . $a . '/transcriber', ['ctx' => 'q=//evil.example/x&kilde=adversus', 'retur' => '//evil.example']);
$loc = $r3->headers['Location'] ?? '';
F008Check::that('AK6', str_starts_with($loc, '/opkald/' . $a . '?') && !str_contains($loc, '//evil'), 'ukendt retur-værdi: som før (/opkald), aldrig en fremmed vært: ' . $loc);
DB::exec("UPDATE calls SET transcript_status = 'ikke_valgt', manual_at = NULL WHERE id = ?", [$a]);
$r4 = $c->post('/opkald/' . $a . '/transcriber', ['ctx' => 'q=%2F%2Fevil.example&kilde=adversus', 'retur' => 'lead']);
$loc4 = $r4->headers['Location'] ?? '';
F008Check::that('AK6', str_starts_with($loc4, '/lead/1001?') && str_ends_with($loc4, '#opkald-' . $a) && !str_contains($loc4, '//evil'), 'retur=lead: kun egen lead-sti: ' . $loc4);
F008Check::done();
