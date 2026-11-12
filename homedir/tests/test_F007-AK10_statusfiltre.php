<?php
declare(strict_types=1);

// F007-AK10: statusfiltrene Alle / Færdige / I kø virker på begge faner og står i URL'en (status=faerdig|koe; "Alle" står
// aldrig). "I kø" er præcis de opkald, kø-tælleren tæller (H012-reglerne). Filteret bevares i pager, zip, faner og detalje.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\Transcription\QueueRules;

f007_setup();
$c = f002_login();
$done = f002_insert(['started_at' => '2026-11-12 09:00:00']);
f002_audio($done);
f002_transcript($done, 'Færdigt opkald');
$queued = f002_insert(['started_at' => '2026-11-12 08:00:00']);
f002_audio($queued);   // hentet + ventende: i kø
$busy = f002_insert(['started_at' => '2026-11-12 07:00:00']);
f002_audio($busy);
DB::exec("UPDATE calls SET transcript_status = 'transcriberer' WHERE id = ?", [$busy]);
$noAudio = f002_insert(['started_at' => '2026-11-12 06:00:00']);   // venter på lyd: ikke i kø
$failed = f002_insert(['started_at' => '2026-11-12 05:00:00']);
f002_audio($failed);
DB::exec("UPDATE calls SET transcript_status = 'fejlet' WHERE id = ?", [$failed]);
$aDone = f004_insert(9101, true, 'faerdig', '2026-11-12 10:00:00');
DB::exec("UPDATE calls SET audio_status = 'hentet' WHERE id = ?", [$aDone]);
$aQueue = f004_insert(9102, true, 'ventende', '2026-11-12 11:00:00');
DB::exec("UPDATE calls SET audio_status = 'hentet' WHERE id = ?", [$aQueue]);

$all = f007_get($c, '/');
F007Check::that('AK10', f007_ids($all) === [$done, $queued, $busy, $noAudio, $failed], 'Alle: alle Zendesk-opkald (også fejlet og uden lyd)');
F007Check::that('AK10', str_contains($all->body, '<div class="pill-group" role="group" aria-label="Status"><a class="fpill" href="/" aria-current="true">Alle</a>'
    . '<a class="fpill" href="/?status=faerdig">Færdige</a><a class="fpill" href="/?status=koe">I kø</a></div>'), 'filtrene som links; Alle står ikke i URL\'en');
$f = f007_get($c, '/', ['status' => 'faerdig']);
F007Check::that('AK10', f007_ids($f) === [$done] && str_contains($f->body, 'aria-current="true">Færdige'), 'Færdige: kun det færdige');
$q = f007_get($c, '/', ['status' => 'koe']);
F007Check::that('AK10', f007_ids($q) === [$queued, $busy], 'I kø: hentet og venter eller transcriberes');
F007Check::that('AK10', Calls::count(Calls::uiFilter(['status' => 'koe'])) + Calls::count(Calls::uiFilter(['kilde' => 'adversus', 'status' => 'koe']))
    === Calls::status()['waiting'], 'I kø på begge faner = kø-tælleren');
$aq = f007_get($c, '/', ['kilde' => 'adversus', 'status' => 'koe']);
F007Check::that('AK10', f007_ids($aq) === [$aQueue], 'Adversus + I kø');
$af = f007_get($c, '/', ['kilde' => 'adversus', 'status' => 'faerdig']);
F007Check::that('AK10', f007_ids($af) === [$aDone], 'Adversus + Færdige');

// H012-reglerne: et gammelt ventende opkald er ikke i kø
QueueRules::override(new QueueRules(15, 30));
$old = f002_insert(['started_at' => gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
f002_audio($old);
$fresh = f002_insert(['started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
f002_audio($fresh);
$ids = f007_ids(f007_get($c, '/', ['status' => 'koe']));
F007Check::that('AK10', in_array($fresh, $ids, true) && !in_array($old, $ids, true), 'I kø følger H012: ældre end 30 dage er ikke i kø');
QueueRules::override(null);

// Bevares i links
$hrefs = f007_hrefs(f007_get($c, '/', ['kilde' => 'adversus', 'status' => 'faerdig', 'q' => 'x']));
$zip = array_values(array_filter($hrefs, static fn(string $h): bool => str_starts_with($h, '/opkald/download')));
F007Check::that('AK10', in_array('/?q=x&status=faerdig', $hrefs, true), 'Zendesk-fanens link bærer statusfiltret');
$fq = f007_get($c, '/', ['status' => 'faerdig']);
$fz = array_values(array_filter(f007_hrefs($fq), static fn(string $h): bool => str_starts_with($h, '/opkald/download')));
F007Check::that('AK10', $fz !== [] && str_contains($fz[0], 'status=faerdig'), 'zip bærer statusfiltret');
$card = array_values(array_filter(f007_hrefs($fq), static fn(string $h): bool => str_starts_with($h, '/opkald/' . $done)));
F007Check::that('AK10', $card !== [] && str_contains($card[0], 'status=faerdig'), 'kortets link bærer statusfiltret');
$d = f007_get($c, '/opkald/' . $done, ['status' => 'faerdig']);
F007Check::that('AK10', str_contains($d->body, '<p class="back"><a href="/?status=faerdig">'), 'Tilbage bærer statusfiltret');
F007Check::that('AK10', str_contains($fq->body, '<input type="hidden" name="status" value="faerdig">'), 'søgeformularen bærer statusfiltret');
$bad = f007_get($c, '/', ['status' => "x' OR 1=1"]);
F007Check::that('AK10', $bad->status === 200 && count(f007_ids($bad)) >= 5, 'ugyldig status ignoreres (Alle)');
F007Check::done();
