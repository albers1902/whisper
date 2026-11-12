<?php
declare(strict_types=1);

// F003-AK4: get_transcript giver tekst med tidsstempler og metadata, afkortes over loftet (200 000 tegn) med
// markering, og et ukendt id giver isError uden detaljer.

require_once __DIR__ . '/f003_lib.php';

use Whisper\Mcp\Tools;

f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

$id = f002_insert(['started_at' => '2026-10-05 08:03:00', 'direction' => 'outbound', 'customer_phone' => '+45 88 77 66 55',
    'agent_name' => 'Søren Ærø', 'ticket_id' => 4242, 'duration_sec' => 3725, 'audio_path' => 'recordings/2026/10/77.mp3']);
f002_transcript($id, 'Hej. Vi taler om måleren.', [
    ['start' => 0.4, 'end' => 2.0, 'text' => 'Hej.'],
    ['start' => 75.0, 'end' => 80.0, 'text' => 'Vi taler om måleren.'],
    ['start' => 3725.0, 'end' => 3726.0, 'text' => 'Farvel.'],
]);

[$err, $text, $structured] = f003_result($m->tool('get_transcript', ['call_id' => $id]));
F003Check::that('AK4', !$err, 'kendt opkald: ingen fejl');
F003Check::that('AK4', str_contains($text, "[00:00] Hej.\n[01:15] Vi taler om måleren.\n[1:02:05] Farvel."), 'tekst med tidsstempler');
foreach (['Opkald:   ' . $id, 'Tid:      05.10.2026 10:03 (dansk tid)', 'Retning:  Udgående', 'Nummer:   +45 88 77 66 55',
    'Agent:    Søren Ærø', 'Varighed: 1:02:05', 'Ticket:   4242', 'Link:     https://' . F003_HOST . '/opkald/' . $id] as $h) {
    F003Check::that('AK4', str_contains($text, $h), "metadata: $h");
}
F003Check::that('AK4', !str_contains($text, 'AFKORTET'), 'kort transcript er ikke markeret afkortet');
F003Check::that('AK4', !str_contains($text, 'recordings/') && $structured === null, 'ingen lydsti; ingen structuredContent');

// Uden segmenter: teksten bruges
$plain = f002_insert(['started_at' => '2026-10-06 08:00:00']);
f002_transcript($plain, 'Kun ren tekst her.', [['start' => 'x', 'text' => 1]]);
[, $pt] = f003_result($m->tool('get_transcript', ['call_id' => $plain]));
F003Check::that('AK4', str_contains($pt, "\n\n" . \Whisper\Mcp\Tools::FRAME_START . "\nKun ren tekst her.\n"), 'ugyldige segmenter: den rene tekst');

// Over loftet: segmenter og ren tekst
$long = f002_insert(['started_at' => '2026-10-07 08:00:00']);
$segs = [];
for ($i = 0; $i < 3000; $i++) {
    $segs[] = ['start' => (float) $i, 'end' => $i + 0.5, 'text' => str_repeat('æ', 99)];
}
f002_transcript($long, str_repeat('ø', 300000), $segs);
[$le, $lt] = f003_result($m->tool('get_transcript', ['call_id' => $long]));
$body = substr($lt, (int) strpos($lt, "\n\n") + 2);
$body = substr($body, 0, (int) strpos($body, "\n[… AFKORTET"));
F003Check::that('AK4', !$le && str_contains($lt, '[… AFKORTET: transcriptet er længere end 200.000 tegn'), 'afkortning markeres');
F003Check::that('AK4', mb_strlen($body, 'UTF-8') <= Tools::MAX_CHARS + 1, 'teksten er højst 200 000 tegn');
F003Check::that('AK4', mb_check_encoding($lt, 'UTF-8'), 'afkortet midt i et tegn giver stadig gyldig UTF-8');

$raw = f002_insert(['started_at' => '2026-10-08 08:00:00']);
f002_transcript($raw, str_repeat('å', 250000), [['start' => 'x', 'text' => 1]]);
[, $rt] = f003_result($m->tool('get_transcript', ['call_id' => $raw]));
F003Check::that('AK4', str_contains($rt, 'AFKORTET') && str_contains($rt, str_repeat('å', Tools::MAX_CHARS))
    && !str_contains($rt, str_repeat('å', Tools::MAX_CHARS + 1)), 'ren tekst over loftet: præcis 200 000 tegn og markering');

// Ukendt, ikke færdigt, ugyldigt
$generic = 'Opkaldet findes ikke, eller det har endnu ikke et færdigt transcript.';
[$ue, $ut] = f003_result($m->tool('get_transcript', ['call_id' => 999999999]));
F003Check::that('AK4', $ue && $ut === $generic, 'ukendt id: isError uden detaljer');
$waiting = f002_insert(['started_at' => '2026-10-09 08:00:00']);
[$we, $wt] = f003_result($m->tool('get_transcript', ['call_id' => $waiting]));
F003Check::that('AK4', $we && $wt === $generic, 'ikke færdigt: samme svar som ukendt');
F003Check::that('AK4', f003_result($m->tool('get_transcript', []))[0], 'call_id mangler: isError');
F003Check::that('AK4', f003_result($m->tool('get_transcript', ['call_id' => '12']))[0], 'call_id som tekst: isError');
F003Check::that('AK4', f003_result($m->tool('get_transcript', ['call_id' => -1]))[0], 'negativt call_id: isError');
F003Check::that('AK4', f003_result($m->tool('get_transcript', ['call_id' => $id, 'x' => 1]))[0], 'ukendt argument: isError');

F003Check::done();
