<?php
declare(strict_types=1);

// F007-AK3: Zendesk-fanen viser kun Zendesk-opkald og har ingen succes-vælger. Adversus-fanen viser kun Adversus-opkald
// med succes-vælgeren ("Succes og valgte" / "Alle opkald"). Kortenes felter følger kilden: Zendesk har Ticket, Adversus
// Kampagne, Lead og Succes-lead. Med F004 slukket er der ingen faner, og listen viser kun Zendesk.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Adversus\Feature;
use Whisper\Flags;

f007_setup();
$c = f002_login();
$z = f002_insert(['started_at' => '2026-11-12 09:00:00', 'ticket_id' => 4711, 'agent_name' => 'Zara Zendesk']);
$ok = f004_insert(6001, true, 'ventende', '2026-11-12 10:00:00');
$no = f004_insert(6002, false, 'ikke_valgt', '2026-11-12 11:00:00');

$zr = f007_get($c, '/');
F007Check::that('AK3', f007_ids($zr) === [$z], 'Zendesk-fanen: kun Zendesk-opkaldet');
F007Check::that('AK3', !str_contains($zr->body, 'Succes og valgte') && !str_contains($zr->body, 'aria-label="Vis"'), 'Zendesk: ingen succes-vælger');
F007Check::that('AK3', str_contains($zr->body, 'Ticket #4711') && !str_contains($zr->body, 'Kampagne #') && !str_contains($zr->body, 'Lead #'),
    'Zendesk-kort: Ticket, ingen Kampagne/Lead');
F007Check::that('AK3', !str_contains($zr->body, '<th scope="col">Kilde</th>') && !str_contains($zr->body, '<table class="calls"'), 'ingen tabel og ingen Kilde-kolonne');

$ar = f007_get($c, '/', ['kilde' => 'adversus']);
F007Check::that('AK3', f007_ids($ar) === [$ok], 'Adversus "Succes og valgte": kun succes-opkaldet');
F007Check::that('AK3', str_contains($ar->body, '<div class="pill-group" role="group" aria-label="Vis">')
    && str_contains($ar->body, 'aria-current="true">Succes og valgte'), 'Adversus: succes-vælgeren, standard "Succes og valgte"');
F007Check::that('AK3', str_contains($ar->body, 'Kampagne #31') && str_contains($ar->body, 'Lead #' . (7000 + 6001))
    && str_contains($ar->body, 'Succes-lead') && !str_contains($ar->body, 'Ticket #'), 'Adversus-kort: Kampagne, Lead, Succes-lead, ingen Ticket');
$all = f007_get($c, '/', ['kilde' => 'adversus', 'vis' => 'alle']);
F007Check::that('AK3', f007_ids($all) === [$no, $ok] && str_contains($all->body, 'aria-current="true">Alle opkald'), 'Adversus "Alle opkald": begge');
F007Check::that('AK3', str_contains($all->body, '>Ikke valgt</span>'), '"Ikke valgt" vises under Alle opkald');

$d = f007_get($c, '/opkald/' . $ok, ['kilde' => 'adversus']);
F007Check::that('AK3', str_contains($d->body, '<dt>Kampagne</dt>') && str_contains($d->body, '<dt>Lead</dt>') && !str_contains($d->body, '<dt>Ticket</dt>'),
    'Adversus-detalje: Kampagne og Lead, ingen Ticket');
$dz = f007_get($c, '/opkald/' . $z);
F007Check::that('AK3', str_contains($dz->body, '<dt>Ticket</dt>') && !str_contains($dz->body, '<dt>Lead</dt>'), 'Zendesk-detalje: Ticket');

Flags::override(Feature::FLAG, false);
$nf = f007_get($c, '/', ['kilde' => 'adversus']);
F007Check::that('AK3', !str_contains($nf->body, 'aria-label="Kilde"') && f007_ids($nf) === [$z] && str_contains($nf->body, 'class="sidebar"'),
    'F004 slukket: ny ramme, ingen faner, kun Zendesk');
F007Check::done();
