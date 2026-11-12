<?php
declare(strict_types=1);

// F007-AK7: "Ikke sat op" vises kun, når motoren faktisk ikke er sat op. Kø-kortets status læses fra det, motoren selv
// efterlader (var/whisper/ready-<model>, logs/transcribe.log, "transcriberer"), ikke fra venv'ets python, som web-PHP
// ikke nødvendigvis kan se. Mens whisper-setup.sh kører (markøren er væk, men loggen er frisk), står "Opsætning i gang".
// Kan status ikke læses, står "Ukendt status" — aldrig "Ikke sat op" på et gæt.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\Paths;
use Whisper\Transcription\EngineStatus as S;
use Whisper\UiV2;

$dir = f007_setup();
$now = time();
$log = $dir . '/transcribe.log';
$marker = Paths::dir('whisper') . '/ready-turbo';

// Ingen markør, ingen log: ikke sat op
F007Check::that('AK7', S::read(3, false, $now, $log)['state'] === S::MISSING, 'uden markør og uden aktivitet: ikke sat op');
// Ingen markør, frisk log (whisper-setup.sh kører og har fjernet markøren midlertidigt)
file_put_contents($log, "x\n");
touch($log, $now - 120);
F007Check::that('AK7', S::read(3, false, $now, $log)['state'] === S::SETUP, 'uden markør, men aktivitet for 2 min. siden: opsætning i gang');
F007Check::that('AK7', S::read(3, true, $now, $log)['state'] === S::SETUP, 'uden markør, men et opkald transcriberes: opsætning i gang');
touch($log, $now - 3600);
F007Check::that('AK7', S::read(3, false, $now, $log)['state'] === S::MISSING, 'uden markør og log over 10 min. gammel: ikke sat op');

// Markøren findes — venv'ets python findes IKKE (som web-PHP kan opleve det): aldrig "ikke sat op"
touch($marker);
F007Check::that('AK7', !is_file(Paths::varDir() . '/whisper/venv/bin/python') && S::read(0, false, $now, $log)['state'] === S::READY,
    'markør uden synlig python: klar (den falske besked er væk)');
touch($log, $now - 60);
F007Check::that('AK7', S::read(5, true, $now, $log)['state'] === S::BUSY, 'et opkald transcriberes og loggen er frisk: transcriberer nu');
touch($log, $now - 3 * 3600);
F007Check::that('AK7', S::read(5, true, $now, $log)['state'] === S::BUSY,
    'et langt opkald inden for motorens tidsgrænse: transcriberer nu, selv om loggen er 3 t gammel (QA R-5)');
F007Check::that('AK7', S::read(5, false, $now, $log)['state'] === S::STALLED, 'kø og log 3 t gammel, intet opkald i gang: ingen fremdrift (QA F-8)');

// transcribingNow: kun "transcriberer" inden for max(2 t, 4 × varighed) fra claimed_at
$long = f002_insert(['duration_sec' => 3600]);
DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now - 3 * 3600), $long]);
F007Check::that('AK7', Calls::transcribingNow($now), '1 t opkald claimet for 3 t siden (grænse 4 t): i gang (QA R-5)');
DB::exec('UPDATE calls SET claimed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $now - 5 * 3600), $long]);
F007Check::that('AK7', !Calls::transcribingNow($now), 'claimet for 5 t siden (over grænsen, dræbt proces): ikke i gang (QA F-8)');
$short = f002_insert(['duration_sec' => 60]);
DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now - 3600), $short]);
F007Check::that('AK7', Calls::transcribingNow($now), 'kort opkald claimet for 1 t siden (grænse 2 t): i gang');
DB::exec('DELETE FROM calls');
Paths::setVarDir($dir);
touch($log, $now - 600);
F007Check::that('AK7', S::read(5, false, $now, $log)['state'] === S::BUSY, 'kø og aktivitet for 10 min. siden: transcriberer nu');
touch($log, $now - 45 * 60);
$st = S::read(5, false, $now, $log);
F007Check::that('AK7', $st['state'] === S::STALLED && UiV2::engineLine($st, true, $now)[1] === 'Ingen fremdrift i 45 min.',
    'kø uden aktivitet i 45 min.: "Ingen fremdrift i 45 min."');
F007Check::that('AK7', UiV2::engineLine(['state' => S::STALLED, 'since' => $now - 7300], false, $now)[1] === 'Ingen fremdrift i 2 t.', '… i 2 t.');

// Ukendt: var/ kan ikke læses
Paths::setVarDir($dir . '/findes-ikke');
F007Check::that('AK7', S::read(5, false, $now, $log)['state'] === S::UNKNOWN, 'var/ kan ikke læses: ukendt');
F007Check::that('AK7', UiV2::engineLine(['state' => S::UNKNOWN, 'since' => null], true, $now)[1] === 'Ukendt status', 'teksten: Ukendt status');
Paths::setVarDir($dir);

// Teksterne pr. rolle
F007Check::that('AK7', UiV2::engineLine(['state' => S::MISSING, 'since' => null], true, $now)[1] === 'Ikke sat op. Kør cron/whisper-setup.sh.'
    && UiV2::engineLine(['state' => S::MISSING, 'since' => null], false, $now)[1] === 'Nye opkald bliver ikke transcriberet lige nu.',
    'ikke sat op: admin får kommandoen, bruger en forklaring');

// På siden: kort og ingen blå motorboks på listen
$c = f005_admin();
@unlink($marker);
$id = f002_insert();
f002_audio($id);
$r = f007_get($c, '/');
F007Check::that('AK7', str_contains($r->body, 'dot dot-error') && str_contains($r->body, 'Ikke sat op. Kør cron/whisper-setup.sh.')
    && str_contains($r->body, 'Transcription: ikke sat op') && !str_contains($r->body, 'Transcription er ikke sat op endnu'),
    'uden markør: rød linje i kortet og topbaren, ingen boks på listen');
touch($marker);
$r2 = f007_get($c, '/');
F007Check::that('AK7', !str_contains($r2->body, 'Ikke sat op') && !str_contains($r2->body, 'ikke sat op endnu'), 'med markør: ingen "ikke sat op" nogen steder');
F007Check::done();
