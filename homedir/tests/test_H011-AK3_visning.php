<?php
declare(strict_types=1);

// H011-AK3: UI (nyt og gammelt), .txt, zip og MCP viser rollen ("Agent:"/"Kunde:", ellers "Taler 1:"); UI'et kun ved
// skift. Transcripts uden taler vises præcis som før.

require_once __DIR__ . '/h011_lib.php';
require_once __DIR__ . '/f003_lib.php';

use Whisper\Calls;
use Whisper\Flags;
use Whisper\Ui;

f003_setup();
[$c, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

// Et transcript med talere, som diarization har gemt det
$id = f002_insert(['started_at' => '2026-10-05 08:03:00', 'ticket_id' => 4242]);
f002_audio($id);
f002_transcript($id, 'Hej, du taler med Mette. Hej, det er om regningen. Den er sendt. Godt.', [
    ['start' => 0.4, 'end' => 2.0, 'text' => 'Hej, du taler med Mette.', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 2.5, 'end' => 4.0, 'text' => 'Hej, det er om regningen.', 'speaker' => 1, 'role' => 'kunde'],
    ['start' => 4.5, 'end' => 6.0, 'text' => 'Den er sendt.', 'speaker' => 0, 'role' => 'agent'],
    ['start' => 6.5, 'end' => 8.0, 'text' => 'Godt.', 'speaker' => 0, 'role' => 'agent'],
]);
// Uden roller (ingen agentmarkør): Taler 1/2
$plain = f002_insert(['started_at' => '2026-10-05 09:00:00', 'ticket_id' => 4243]);
f002_audio($plain);
f002_transcript($plain, 'Hej. Goddag.', [
    ['start' => 0.0, 'end' => 1.0, 'text' => 'Hej.', 'speaker' => 0],
    ['start' => 1.5, 'end' => 3.0, 'text' => 'Goddag.', 'speaker' => 1],
]);
// Et gammelt transcript uden taler
$old = f002_insert(['started_at' => '2026-10-05 10:00:00', 'ticket_id' => 4244]);
f002_audio($old);
f002_transcript($old, 'Hej. Farvel.', [['start' => 0.4, 'end' => 2.0, 'text' => 'Hej.'], ['start' => 75.0, 'end' => 80.0, 'text' => 'Farvel.']]);

// .txt
$t = f002_get($c, '/opkald/' . $id . '/transcript');
H011Check::that('AK3', str_contains($t->body, "\n\n[00:00] Agent: Hej, du taler med Mette.\n[00:02] Kunde: Hej, det er om regningen.\n"
    . "[00:04] Agent: Den er sendt.\n[00:06] Agent: Godt.\n"), '.txt: [mm:ss] Agent: … på hver linje');
$tp = f002_get($c, '/opkald/' . $plain . '/transcript');
H011Check::that('AK3', str_contains($tp->body, "\n\n[00:00] Taler 1: Hej.\n[00:01] Taler 2: Goddag.\n"), '.txt uden roller: Taler 1/Taler 2');
$to = f002_get($c, '/opkald/' . $old . '/transcript');
H011Check::that('AK3', str_contains($to->body, "\n\n[00:00] Hej.\n[01:15] Farvel.\n") && !str_contains($to->body, 'Taler'),
    '.txt uden taler: som før');

// Zip
$z = f002_get($c, '/opkald/download');
$files = $z->status === 200 ? f002_unzip($z->fileContents()) : [];
$all = implode("\n", array_column($files, 'data'));
H011Check::that('AK3', str_contains($all, '[00:02] Kunde: Hej, det er om regningen.') && str_contains($all, "[01:15] Farvel.\n"),
    'zip: med taler, og uden taler som før (' . count($files) . ' filer)');

// MCP
[, $text] = f003_result($m->tool('get_transcript', ['call_id' => $id]));
H011Check::that('AK3', str_contains($text, "[00:00] Agent: Hej, du taler med Mette.\n[00:02] Kunde: Hej, det er om regningen.\n"),
    'MCP: Agent:/Kunde: i teksten');
[, $oldText] = f003_result($m->tool('get_transcript', ['call_id' => $old]));
H011Check::that('AK3', str_contains($oldText, "[00:00] Hej.\n[01:15] Farvel.") && !str_contains($oldText, 'Taler'), 'MCP uden taler: som før');

// Nyt UI (F005): rollen kun ved skift
Flags::override(Ui::FLAG, true);
$r = f002_get($c, '/opkald/' . $id);
H011Check::that('AK3', substr_count($r->body, '<strong class="speaker">') === 3, 'nyt UI: tre skift (Agent, Kunde, Agent), ikke fire');
H011Check::that('AK3', str_contains($r->body, '<p><strong class="speaker">Agent:</strong> Hej, du taler med Mette.</p>')
    && str_contains($r->body, '<p><strong class="speaker">Kunde:</strong> Hej, det er om regningen.</p>')
    && str_contains($r->body, '<p>Godt.</p>'), 'nyt UI: rollen foran teksten, ikke ved samme taler igen');
$ro = f002_get($c, '/opkald/' . $old);
H011Check::that('AK3', !str_contains($ro->body, 'class="speaker"') && str_contains($ro->body, '<p>Hej.</p>'), 'nyt UI uden taler: som før');

// Gammelt UI
Flags::override(Ui::FLAG, false);
$r = f002_get($c, '/opkald/' . $id);
H011Check::that('AK3', str_contains($r->body, '[00:00]</span> <strong class="speaker">Agent:</strong> Hej, du taler med Mette.</p>')
    && str_contains($r->body, '[00:06]</span> Godt.</p>'), 'gammelt UI: rollen ved skift');
$ro = f002_get($c, '/opkald/' . $old);
H011Check::that('AK3', str_contains($ro->body, '[00:00]</span> Hej.') && !str_contains($ro->body, 'class="speaker"'), 'gammelt UI uden taler: som før');

// Etiketten escapes og kommer aldrig fra fri tekst: ugyldige nøgler ignoreres
$bad = Calls::segments((string) json_encode([['start' => 0, 'end' => 1, 'text' => 'x', 'speaker' => '<b>', 'role' => '<script>'],
    ['start' => 1, 'end' => 2, 'text' => 'y', 'speaker' => 1, 'role' => '<script>']]));
H011Check::that('AK3', !isset($bad[0]['speaker']) && !isset($bad[0]['role']) && $bad[1]['speaker'] === 1 && !isset($bad[1]['role']),
    'ugyldig speaker/role i segments_json vises ikke');

H011Check::done();
