<?php
declare(strict_types=1);

// F007-AK4: statuslinje og advarsler for en kilde vises kun på dens egen fane; den anden fane får en markør. Køen står
// i sidebarens kø-kort (fælles for begge kilder), ikke på listen. Advarslen fra før H009-genopretningen vises ikke.

require_once __DIR__ . '/f007_lib.php';

use Whisper\Adversus\Sync as AdversusSync;
use Whisper\DB;
use Whisper\Paths;
use Whisper\UiV2;

f007_setup();
$c = f005_admin();
$q1 = f002_insert(['started_at' => '2026-11-12 09:00:00']);
f002_audio($q1);   // hentet + ventende: i kø
$q2 = f002_insert(['started_at' => '2026-11-12 09:30:00']);   // venter på lyd
f004_insert(7001, true, 'ventende', '2026-11-12 10:00:00');
$now = gmdate('Y-m-d H:i:s');
DB::exec("INSERT INTO sync_state (`source`, last_run_at, last_ok_at, last_error) VALUES ('zendesk', ?, ?, NULL)", [$now, $now]);
DB::exec("INSERT INTO sync_state (`source`, last_run_at, last_ok_at, last_error) VALUES ('adversus', ?, ?, 'HTTP 500')", [$now, $now]);
DB::exec("INSERT INTO sync_state (`source`, last_run_at, last_error) VALUES (?, ?, 'adversus-advarsel: 3 optagelser (id 1, 2, 3)')",
    [AdversusSync::WARN, $now]);

$zr = f007_get($c, '/');
F007Check::that('AK4', str_contains($zr->body, 'Hentet fra Zendesk') && !str_contains($zr->body, 'fra Adversus'), 'Zendesk-fanen: kun Zendesk-linjen');
F007Check::that('AK4', !str_contains($zr->body, 'Seneste hentning fra Adversus stoppede') && !str_contains($zr->body, 'Nogle opkald kunne ikke hentes'),
    'Zendesk-fanen: ingen Adversus-beskeder');
F007Check::that('AK4', str_contains($zr->body, '<span class="tab-flag tab-flag-error">') && str_contains($zr->body, ', hentningen er stoppet'),
    'Zendesk-fanen: markør på Adversus-fanen');
F007Check::that('AK4', !str_contains($zr->body, 'venter på transcription') && !str_contains($zr->body, 'class="sync-queue"'), 'listen har ingen kø-linje');
F007Check::that('AK4', str_contains($zr->body, '<p class="q-count"><span class="q-num">1</span> i kø</p>') && str_contains($zr->body, '2 venter på lyd'),
    'kø-kortet: 1 i kø, 2 venter på lyd (Zendesk + Adversus)');

$ar = f007_get($c, '/', ['kilde' => 'adversus']);
// Testens config.ini har ingen [adversus]: linjen siger "ikke sat op" (grå prik), og sync-fejlen står stadig for admin
F007Check::that('AK4', str_contains($ar->body, '<span class="dot dot-none" aria-hidden="true"></span>Adversus er ikke sat op')
    && !str_contains($ar->body, 'fra Zendesk'), 'Adversus-fanen: kun Adversus-linjen');
F007Check::that('AK4', str_contains($ar->body, 'Seneste hentning fra Adversus stoppede.') && str_contains($ar->body, 'Nogle opkald kunne ikke hentes.'),
    'Adversus-fanen: fejl og advarsel (admin)');

f001_user('bruger@f007.test', 'user');
$u = new F001Client('10.0.0.7');
$u->login('bruger@f007.test', F001_PW);
$ur = f007_get($u, '/', ['kilde' => 'adversus']);
F007Check::that('AK4', !str_contains($ur->body, 'Nogle opkald kunne ikke hentes') && !str_contains($ur->body, 'Teknisk besked'),
    'bruger: ingen advarsel og ingen teknisk besked');

// Cachemappen kan ikke oprettes (en fil står i vejen): direkte tælling, aldrig "Ingen i kø" (QA F-2)
@unlink(Paths::varDir() . '/cache/f007-queue.json');
@rmdir(Paths::varDir() . '/cache');
file_put_contents(Paths::varDir() . '/cache', 'ikke en mappe');
$nc = f007_get($c, '/');
F007Check::that('AK4', str_contains($nc->body, '<p class="q-count"><span class="q-num">1</span> i kø</p>') && !str_contains($nc->body, 'Ingen i kø'),
    'uskrivbar cache: kortet tæller direkte (1 i kø)');
unlink(Paths::varDir() . '/cache');
// Ugyldig cache (urimeligt tal): tælles igen (security S3)
UiV2::reset();
Paths::writePrivate(Paths::dir('cache') . '/f007-queue.json', '{"last_ok_at":null,"last_error":null,"waiting":99999999999,"audio_waiting":-4}');
UiV2::reset(false);
F007Check::that('AK4', UiV2::queue()['status']['waiting'] === 1, 'cache med urimelige tal ignoreres');

// H009: advarslen fra før genopretningen er forældet
DB::exec('UPDATE sync_state SET last_run_at = ? WHERE `source` = ?', [gmdate('Y-m-d H:i:s', time() + 60), AdversusSync::RECOVERY]);
$after = f007_get($c, '/', ['kilde' => 'adversus']);
F007Check::that('AK4', !str_contains($after->body, 'Nogle opkald kunne ikke hentes') && !str_contains($after->body, 'tab-flag-warn'),
    'advarsel fra før H009-genopretningen vises ikke');
F007Check::that('AK4', AdversusSync::beforeRecovery($now) && !AdversusSync::beforeRecovery(gmdate('Y-m-d H:i:s', time() + 3600)),
    'beforeRecovery: kun advarsler fra før genopretningen');
F007Check::done();
