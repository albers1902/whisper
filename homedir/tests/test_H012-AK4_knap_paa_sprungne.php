<?php
declare(strict_types=1);

// H012-AK4: "Transcribér dette opkald" virker på opkald, der er sprunget over efter begge regler (for kort, for gammelt),
// for begge kilder, og loftet på 50 manuelle opkald gælder. Et valgt opkald tages af køen trods reglerne, efter de
// automatiske. Zendesk-opkald, der ikke er sprunget over, har stadig ingen knap (404).

require_once __DIR__ . '/h012_lib.php';

use Whisper\Adversus\Feature;
use Whisper\CallPages;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Transcription\QueueRules;

f004_setup();
QueueRules::override(new QueueRules());
$c = f002_login();
$t = time();
$day = 86400;
$manualAt = static fn(int $id): ?string => ($v = DB::scalar('SELECT manual_at FROM calls WHERE id = ?', [$id])) === null ? null : (string) $v;
$status = static fn(int $id): string => (string) DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$id]);

// Zendesk, for kort
$zShort = h012_zendesk($t, 3600, 10);
$page = f002_get($c, '/opkald/' . $zShort)->body;
H012Check::that('AK4', str_contains($page, 'action="/opkald/' . $zShort . '/transcriber"') && str_contains($page, 'kortere end 15 sekunder')
    && str_contains($page, '<span class="badge s-ikke-valgt">For kort</span>'), 'Zendesk, for kort: knap, årsag og badge');
$r = $c->post('/opkald/' . $zShort . '/transcriber', ['ctx' => 'vis=alle']);
H012Check::that('AK4', $r->status === 303 && str_starts_with($r->headers['Location'] ?? '', '/opkald/' . $zShort . '?'), 'Zendesk: 303 til detaljen');
H012Check::that('AK4', $manualAt($zShort) !== null && $status($zShort) === 'ventende', 'Zendesk: manual_at sat, status uændret ventende');
$after = f002_get($c, '/opkald/' . $zShort)->body;
H012Check::that('AK4', str_contains($after, 'Opkaldet er sat i kø. Transcriptet laves, når opkaldene foran det er færdige.')
    && !str_contains($after, '/transcriber"') && str_contains($after, '<span class="badge s-ventende">Venter</span>'),
    'kvittering, knappen er væk, badget er "Venter"');
$first = $manualAt($zShort);
$c->post('/opkald/' . $zShort . '/transcriber');
H012Check::that('AK4', $manualAt($zShort) === $first && str_contains(f002_get($c, '/opkald/' . $zShort)->body, 'Opkaldet er allerede i kø.'),
    'anden gang: ingen ændring, "allerede i kø"');

// Adversus (succes), ældre end 30 dage
$aOld = h012_adversus(5401, true, $t, 40 * $day);
$page = f002_get($c, '/opkald/' . $aOld)->body;
H012Check::that('AK4', str_contains($page, 'action="/opkald/' . $aOld . '/transcriber"') && str_contains($page, 'ældre end 30 dage')
    && str_contains($page, 'Ikke automatisk'), 'Adversus, for gammelt: knap, årsag og badge');
$r = $c->post('/opkald/' . $aOld . '/transcriber');
H012Check::that('AK4', $r->status === 303 && $manualAt($aOld) !== null && $status($aOld) === 'ventende', 'Adversus: valgt');

// Zendesk, der ikke er sprunget over: ingen knap, 404 som før (F004)
$zNormal = h012_zendesk($t, 7200, 125);
H012Check::that('AK4', !str_contains(f002_get($c, '/opkald/' . $zNormal)->body, '/transcriber"'), 'almindeligt Zendesk-opkald: ingen knap');
H012Check::that('AK4', $c->post('/opkald/' . $zNormal . '/transcriber')->status === 404 && $manualAt($zNormal) === null, 'almindeligt Zendesk-opkald: 404');

// Uden CSRF: 403, intet ændres
$zOld = h012_zendesk($t, 50 * $day, 125);
H012Check::that('AK4', $c->post('/opkald/' . $zOld . '/transcriber', [], false)->status === 403 && $manualAt($zOld) === null, 'uden CSRF: 403');

// Loftet: valg med knappen (manual_at) og Adversus uden succes tæller sammen; ved 50 ændres intet
for ($k = 0; $k < CallPages::MANUAL_MAX; $k++) {
    f004_insert(48000 + $k, false, 'ventende');
}
H012Check::that('AK4', CallPages::manualInProgress() === CallPages::MANUAL_MAX + 2, 'loftet tæller begge slags valg (52)');
$r = $c->post('/opkald/' . $zOld . '/transcriber');
H012Check::that('AK4', $r->status === 303 && $manualAt($zOld) === null
    && str_contains(f002_get($c, '/opkald/' . $zOld)->body, 'Der er allerede mange opkald i kø. Prøv igen senere.'), 'ved loftet: intet ændres');
DB::exec("DELETE FROM calls WHERE `source` = 'adversus' AND external_id LIKE '480%'");
$c->post('/opkald/' . $zOld . '/transcriber');
H012Check::that('AK4', $manualAt($zOld) !== null, 'under loftet igen: valgt');

// Med F004 slukket (F005 tændt): Zendesk-knappen virker, Adversus-opkald giver 404 som før (F004-AK9)
Flags::override(Feature::FLAG, false);
$zShort2 = h012_zendesk($t, 120, 7);
$aShort = h012_adversus(5402, true, $t, 120, 5);
H012Check::that('AK4', str_contains(f002_get($c, '/opkald/' . $zShort2)->body, 'action="/opkald/' . $zShort2 . '/transcriber"'), 'F004 slukket: Zendesk-knappen vises');
$c->post('/opkald/' . $zShort2 . '/transcriber');
H012Check::that('AK4', $manualAt($zShort2) !== null, 'F004 slukket: Zendesk-opkaldet vælges');
H012Check::that('AK4', $c->post('/opkald/' . $aShort . '/transcriber')->status === 404 && $manualAt($aShort) === null, 'F004 slukket: Adversus 404');
Flags::override(Feature::FLAG, true);

// Køen tager de valgte trods reglerne, efter det automatiske
$order = h012_order(new QueueRules(), $t);
H012Check::that('AK4', ($order[0] ?? 0) === $zNormal && in_array($zShort, $order, true) && in_array($aOld, $order, true)
    && in_array($zOld, $order, true) && in_array($zShort2, $order, true) && !in_array($aShort, $order, true),
    'claim: det automatiske først, derefter de valgte; det ikke valgte korte tages ikke (fik ' . implode(',', $order) . ')');

H012Check::done();
