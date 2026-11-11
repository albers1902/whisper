<?php
declare(strict_types=1);

// F002-AK6: detaljesiden viser transcript med tidsstempler, og .txt-download indeholder hoved-blok og tekst i UTF-8.

require_once __DIR__ . '/f002_lib.php';

use Whisper\Calls;
use Whisper\DB;

f002_setup();
$c = f002_login();

$id = f002_insert(['started_at' => '2026-10-05 08:03:00', 'direction' => 'outbound', 'customer_phone' => '+45 88 77 66 55',
    'agent_name' => 'Søren Ærø', 'ticket_id' => 4242, 'duration_sec' => 3725]);
f002_audio($id);
f002_transcript($id, 'Hej. Vi taler om måleren <script>.', [
    ['start' => 0.4, 'end' => 2.0, 'text' => 'Hej.'],
    ['start' => 75.0, 'end' => 80.0, 'text' => 'Vi taler om måleren <script>.'],
    ['start' => 3725.0, 'end' => 3726.0, 'text' => 'Farvel.'],
]);

$r = f002_get($c, '/opkald/' . $id);
F002Check::that('AK6', $r->status === 200, 'detaljesiden svarer 200');
F002Check::that('AK6', str_contains($r->body, '[00:00]</span> Hej.'), 'tidsstempel [00:00]');
F002Check::that('AK6', str_contains($r->body, '[01:15]</span> Vi taler om måleren &lt;script&gt;.'), 'tidsstempel [01:15] og escaped tekst');
F002Check::that('AK6', str_contains($r->body, '[1:02:05]</span> Farvel.'), 'over en time: [t:mm:ss]');
F002Check::that('AK6', !str_contains($r->body, '<script>'), 'ingen rå HTML fra transcriptet');
F002Check::that('AK6', str_contains($r->body, '<audio controls preload="metadata" src="/opkald/' . $id . '/lyd"></audio>'), 'lydafspiller');
F002Check::that('AK6', str_contains($r->body, 'href="/opkald/' . $id . '/transcript"') && str_contains($r->body, 'Download transcript (.txt)'),
    'knappen "Download transcript (.txt)"');
F002Check::that('AK6', str_contains($r->body, 'Søren Ærø') && str_contains($r->body, '+45 88 77 66 55') && str_contains($r->body, '1:02:05'),
    'metadata: agent, nummer, varighed');
F002Check::that('AK6', str_contains($r->body, '05.10.2026 10:03'), 'tid i dansk tid');

$t = f002_get($c, '/opkald/' . $id . '/transcript');
F002Check::that('AK6', $t->status === 200 && $t->header('Content-Type') === 'text/plain; charset=utf-8', 'text/plain i UTF-8');
F002Check::that('AK6', $t->header('Content-Disposition') === 'attachment; filename="2026-10-05_1003_ud_4242.txt"',
    'filnavn ÅÅÅÅ-MM-DD_HHMM_<retning>_<ticket>.txt');
F002Check::that('AK6', mb_check_encoding($t->body, 'UTF-8'), 'gyldig UTF-8');
foreach (['Opkald:   05.10.2026 10:03', 'Retning:  Udgående', 'Nummer:   +45 88 77 66 55', 'Agent:    Søren Ærø', 'Ticket:   4242'] as $h) {
    F002Check::that('AK6', str_contains($t->body, $h), "hoved-blok: $h");
}
F002Check::that('AK6', str_contains($t->body, "\n\n[00:00] Hej.\n[01:15] Vi taler om måleren <script>.\n[1:02:05] Farvel.\n"),
    'teksten med tidsstempler efter hoved-blokken');
F002Check::that('AK6', $t->header('Cache-Control') === 'no-store', 'ingen cache');

// Ikke færdigt / findes ikke / mangler lyd
$v = f002_insert(['started_at' => '2026-10-06 08:00:00']);
F002Check::that('AK6', f002_get($c, '/opkald/' . $v . '/transcript')->status === 404, 'transcript, der ikke er færdigt: 404');
$rv = f002_get($c, '/opkald/' . $v);
F002Check::that('AK6', str_contains($rv->body, 'Venter på transcription.') && str_contains($rv->body, 'Lyden er ikke hentet fra Zendesk endnu.'),
    'ventende opkald: tydelige statusser');
F002Check::that('AK6', !str_contains($rv->body, '<audio'), 'ingen afspiller uden lyd');
$g = f002_insert(['started_at' => '2026-10-06 09:00:00']);
unlink(f002_audio($g));
$rg = f002_get($c, '/opkald/' . $g);
F002Check::that('AK6', str_contains($rg->body, 'Lydfilen mangler på serveren.') && !str_contains($rg->body, '<audio'),
    'lydfilen slettet: siden siger det, og afspilleren vises ikke');
F002Check::that('AK6', f002_get($c, '/opkald/999999999')->status === 404, 'ukendt opkald: 404');
DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = 'motoren fejlede (exit 1)' WHERE id = ?", [$v]);
F002Check::that('AK6', str_contains(f002_get($c, '/opkald/' . $v)->body, 'Transcription fejlede: motoren fejlede (exit 1)'), 'fejltekst vises');

F002Check::that('AK6', Calls::stamp(59.9) === '00:59' && Calls::stamp(3600) === '1:00:00', 'tidsstempler');
F002Check::done();
