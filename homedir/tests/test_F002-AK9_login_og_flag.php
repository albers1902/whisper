<?php
declare(strict_types=1);

// F002-AK9: anonyme requests til alle F002-ruter omdirigeres til /login. Med [F002_zendesk] slukket viser / F001's
// pladsholder, og F002-ruterne svarer 404.

require_once __DIR__ . '/f002_lib.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\CallPages;
use Whisper\Flags;
use Whisper\Request;
use Whisper\Session;

f002_setup();
$id = f002_insert();
f002_audio($id);
f002_transcript($id, 'Hemmelig samtale.');
$routes = ['/', '/opkald/' . $id, '/opkald/' . $id . '/lyd', '/opkald/' . $id . '/transcript', '/opkald/download'];

$app = new App();
foreach ($routes as $p) {
    $s = new Session(new ArraySessionDriver());
    $r = $app->handle(new Request('GET', $p, [], '10.0.0.9', ['fra' => '2026-10-01']), $s);
    F002Check::that('AK9', $r->status === 303 && $r->header('Location') === '/login', "anonym GET $p → /login");
    F002Check::that('AK9', !str_contains($r->body, 'Hemmelig') && $r->file === null, "anonym GET $p: intet indhold");
    $h = $app->handle(new Request('HEAD', $p, [], '10.0.0.9'), new Session(new ArraySessionDriver()));
    F002Check::that('AK9', $h->status === 303, "anonym HEAD $p → /login");
}

$c = f002_login();
F002Check::that('AK9', str_contains(f002_get($c, '/')->body, '<table class="calls">'), 'flag on: / er opkaldslisten');

Flags::override(CallPages::FLAG, false);
$home = f002_get($c, '/');
F002Check::that('AK9', $home->status === 200 && str_contains($home->body, 'Opkald kommer her (F002)'), 'flag off: / viser F001-pladsholderen');
foreach (array_slice($routes, 1) as $p) {
    $r = f002_get($c, $p);
    F002Check::that('AK9', $r->status === 404 && $r->file === null && !str_contains($r->body, 'Hemmelig'), "flag off, logget ind: $p → 404");
    $a = $app->handle(new Request('GET', $p, [], '10.0.0.9'), new Session(new ArraySessionDriver()));
    F002Check::that('AK9', $a->status === 404, "flag off, anonym: $p → 404");
}

// Cron-scripts med flaget slukket i miljøet: exit 0 før lås, database og netværk (security S1)
$cronVar = sys_get_temp_dir() . '/whisper-f002-cron-' . bin2hex(random_bytes(4));
mkdir($cronVar, 0700);
putenv('WHISPER_VAR_DIR=' . $cronVar);
putenv('WHISPER_TEST_FLAGS=');
$stale = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => 1, 'claimed_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
f002_audio($stale);
\Whisper\DB::exec('DELETE FROM sync_state');
$cron = static function (string $script): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../cron/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
    return [is_resource($p) ? proc_close($p) : -1, $out];
};
foreach (['zendesk-sync.php', 'transcribe.php'] as $script) {
    [$code, $out] = $cron($script);
    F002Check::that('AK9', $code === 0 && trim($out) === '', "flag off: $script afslutter med exit 0 uden output");
}
F002Check::that('AK9', \Whisper\DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$stale]) === 'transcriberer',
    'flag off: transcribe.php har ikke rørt databasen');
F002Check::that('AK9', (int) \Whisper\DB::scalar('SELECT COUNT(*) FROM sync_state') === 0, 'flag off: zendesk-sync.php har ikke skrevet i databasen');
F002Check::that('AK9', !is_dir($cronVar . '/locks') && !is_dir($cronVar . '/heartbeat'), 'flag off: hverken lås eller hjertebanken');
// Kontrol: med flaget tændt i scriptets proces rører transcribe.php det hængende opkald (så det er flaget, der stoppede det)
putenv('WHISPER_TEST_FLAGS=F001_brugere,F002_zendesk');
[$code] = $cron('transcribe.php');
F002Check::that('AK9', $code === 0 && \Whisper\DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$stale]) === 'ventende',
    'kontrol: flag on, transcribe.php sætter det hængende opkald tilbage');
putenv('WHISPER_TEST_FLAGS=');

// features.ini: dev on, test off, stable off; testmiljøet (env = test) har flaget slukket
Flags::override(CallPages::FLAG, null);
F002Check::that('AK9', Flags::enabledIn(CallPages::FLAG, 'dev') === true, 'features.ini: dev = on');
F002Check::that('AK9', Flags::enabledIn(CallPages::FLAG, 'test') === false, 'features.ini: test = off');
F002Check::that('AK9', Flags::enabledIn(CallPages::FLAG, 'stable') === false, 'features.ini: stable = off');
F002Check::that('AK9', Flags::enabled(CallPages::FLAG) === false, 'slukket i testmiljøet');
F002Check::that('AK9', f002_get($c, '/opkald/' . $id)->status === 404, 'uden tvang: 404 i testmiljøet');
$ini = (string) file_get_contents(__DIR__ . '/../config/features.ini');
F002Check::that('AK9', preg_match('/\[F002_zendesk\]\n; ejer: F002 · oprettet: 2026-11-11 · fjernes senest: 2027-05\n/u', $ini) === 1,
    'flag-sektionens første linje');

// F001 slukket: alt er 404, også F002
Flags::override(App::FLAG, false);
Flags::override(CallPages::FLAG, true);
F002Check::that('AK9', f002_get($c, '/opkald/' . $id)->status === 404, 'F001 slukket: F002 svarer også 404');

F002Check::done();
