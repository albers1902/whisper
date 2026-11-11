<?php
declare(strict_types=1);

// F005-AK6: alle tilstande i UX §3.4's tabel giver den beskrevne tekst; tekniske fejl står i <details>, og
// admin-only tekster vises kun for admin.

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;

f005_setup();
$user = f002_login();

$mk = static function (string $audio, string $t, array $extra = []): int {
    $id = f002_insert(['transcript_status' => $t] + $extra);
    if ($audio === 'hentet') {
        f002_audio($id);
    } elseif ($audio === 'mangler') {
        unlink(f002_audio($id));
    } else {
        DB::exec('UPDATE calls SET audio_status = ? WHERE id = ?', [$audio, $id]);
    }
    return $id;
};
$page = static fn(F001Client $c, int $id): string => f002_get($c, '/opkald/' . $id)->body;

$id = $mk('hentet', 'ventende');
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, '<audio id="afspiller"') && str_contains($b, 'Transcriptet er på vej. Opkald transcriberes ét ad gangen. Genindlæs siden om lidt.'),
    'hentet + ventende: afspiller og "på vej"');

$id = $mk('hentet', 'transcriberer');
F005Check::that('AK6', str_contains($page($user, $id), 'Transcriptet laves lige nu. Genindlæs siden om et par minutter.'), 'hentet + transcriberer');

$id = $mk('hentet', 'fejlet');
DB::exec("UPDATE calls SET transcript_error = 'motoren fejlede <exit 1>' WHERE id = ?", [$id]);
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, 'Transcriptet kunne ikke laves.')
    && str_contains($b, '<details><summary>Teknisk besked</summary><p>motoren fejlede &lt;exit 1&gt;</p></details>'), 'fejlet: fejltekst i <details>, escaped');
F005Check::that('AK6', !str_contains($b, 'En administrator kan sende opkaldet tilbage i køen.'), 'bruger ser ikke admin-teksten');
$failedId = $id;

$id = $mk('mangler', 'faerdig');
f002_transcript($id, 'Hej.');
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, 'Lydfilen mangler på serveren. Kontakt en administrator.') && str_contains($b, '<span class="ts">'),
    'fil mangler + færdig: fejl og tidsstempler som tekst');

$id = $mk('venter', 'ventende');
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, 'Lyden er ikke hentet fra Zendesk endnu. Det sker normalt inden for 10 minutter.')
    && str_contains($b, 'Transcriptet laves, når lyden er hentet.') && !str_contains($b, '<audio'), 'venter: begge tekster, ingen afspiller');

$id = $mk('fejlet', 'ventende', ['ticket_id' => 48213]);
DB::exec("UPDATE calls SET audio_attempts = 5, audio_error = 'HTTP 404 <fra Zendesk>' WHERE id = ?", [$id]);
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, 'Lyden kunne ikke hentes fra Zendesk (5 forsøg).')
    && str_contains($b, '<details><summary>Teknisk besked</summary><p>HTTP 404 &lt;fra Zendesk&gt;</p></details>')
    && str_contains($b, 'Der kan ikke laves et transcript uden lyd.'), 'lyd fejlet: forsøg, <details> og neutral transcript-tekst');

$id = $mk('hentet', 'faerdig');
f002_transcript($id, '   ', [['start' => 0.0, 'end' => 1.0, 'text' => ' ']]);
F005Check::that('AK6', str_contains($page($user, $id), 'Der blev ikke fundet tale i optagelsen.'), 'færdig, tom tekst: "ingen tale"');

$id = $mk('hentet', 'faerdig');
f002_transcript($id, "Første afsnit.\n\nAndet afsnit.", [['start' => 0.0, 'end' => 1.0, 'text' => 'x']]);
DB::exec("UPDATE transcripts SET segments_json = '[]' WHERE call_id = ?", [$id]);
$b = $page($user, $id);
F005Check::that('AK6', str_contains($b, '<div class="transcript-plain" id="transcript"><p>Første afsnit.</p><p>Andet afsnit.</p></div>'),
    'uden segmenter: den samlede tekst i afsnit');

// Admin ser den ekstra tekst
$admin = f005_admin();
F005Check::that('AK6', str_contains($page($admin, $failedId), 'En administrator kan sende opkaldet tilbage i køen.'), 'admin ser køteksten');
F005Check::done();
