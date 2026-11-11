<?php
declare(strict_types=1);

// F002-AK3: optagelsen hentes til var/recordings/... med 0600. En fejlet download markeres og prøves igen, højst 5 gange.
// Authorization sendes kun til Zendesk-værten, og en redirect til en ikke-godkendt vært følges ikke (SSRF).

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Paths;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Sync;

$var = f002_setup();
$cfg = f002_cfg(['media.rec-storage.test']);
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$mp3 = 'ID3' . random_bytes(2000);

// 1. Hentning via redirect til en godkendt lagervært
$ok = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/ok', 'started_at' => '2026-10-31 23:30:00']);
$http->on('GET', F002_BASE . '/api/v2/rec/ok', new HttpResult(302, ['location' => 'https://media.rec-storage.test/a/b.mp3?sig=s1'], ''));
$http->on('GET', 'https://media.rec-storage.test/a/b.mp3?sig=s1', new HttpResult(200, ['content-type' => 'audio/mpeg'], $mp3));
// 2. Altid 500
$bad = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/bad', 'started_at' => '2026-10-06 09:00:00']);
$http->on('GET', F002_BASE . '/api/v2/rec/bad', new HttpResult(500, [], 'fejl'));
// 3. Redirect til en fremmed vært
$evil = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/evil', 'started_at' => '2026-10-06 10:00:00']);
$http->on('GET', F002_BASE . '/api/v2/rec/evil', new HttpResult(302, ['location' => 'https://169.254.169.254/latest/meta-data'], ''));
// 4. En URL, der slet ikke er Zendesks
$foreign = f002_insert(['recording_url' => 'https://evil.test/x.mp3', 'started_at' => '2026-10-06 11:00:00']);

$sync = new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null);
$r = $sync->downloadPending();
F002Check::that('AK3', $r['ok'] === 1 && $r['failed'] === 3, "1 hentet, 3 fejlet (fik {$r['ok']}/{$r['failed']})");

$row = DB::all('SELECT * FROM calls WHERE id = ?', [$ok])[0];
$file = $var . '/recordings/2026/10/' . $ok . '.mp3';
F002Check::that('AK3', $row['audio_status'] === 'hentet' && $row['audio_path'] === 'recordings/2026/10/' . $ok . '.mp3',
    'status hentet og sti var/recordings/<år>/<måned>/<id>.mp3 (UTC-måned)');
F002Check::that('AK3', is_file($file) && file_get_contents($file) === $mp3, 'filen har optagelsens bytes');
F002Check::that('AK3', is_file($file) && (fileperms($file) & 0777) === 0600, 'filen er 0600');
F002Check::that('AK3', (fileperms($var . '/recordings') & 0777) === 0700, 'mappen er 0700');
F002Check::that('AK3', glob($var . '/recordings/*/*/*.part') === [], 'ingen .part-filer efterladt');

$auth = static fn(string $prefix): array => array_map(static fn(array $q): bool => isset($q['headers']['Authorization']), $http->to($prefix));
F002Check::that('AK3', $auth(F002_BASE . '/api/v2/rec/ok') === [true], 'Authorization sendes til Zendesk-værten');
F002Check::that('AK3', $auth('https://media.rec-storage.test/') === [false], 'Authorization sendes IKKE til lagerværten');
F002Check::that('AK3', $http->to('https://169.254.169.254') === [], 'redirect til en ikke-godkendt vært følges ikke');
F002Check::that('AK3', $http->to('https://evil.test') === [], 'en optagelse på en fremmed vært hentes ikke');
$e = DB::all('SELECT audio_status, audio_attempts, audio_error FROM calls WHERE id = ?', [$evil])[0];
F002Check::that('AK3', $e['audio_status'] === 'fejlet' && str_contains((string) $e['audio_error'], 'ikke er godkendt'), 'fremmed vært: fejlet med kort tekst');

// Fejlet download prøves igen ved næste kørsel, højst 5 gange i alt
for ($i = 0; $i < 6; $i++) {
    $sync->downloadPending();
}
$b = DB::all('SELECT audio_status, audio_attempts, audio_error FROM calls WHERE id = ?', [$bad])[0];
F002Check::that('AK3', (int) $b['audio_attempts'] === 5, 'højst 5 forsøg (fik ' . $b['audio_attempts'] . ')');
F002Check::that('AK3', count($http->to(F002_BASE . '/api/v2/rec/bad')) === 5, 'efter 5 forsøg spørges Zendesk ikke igen');
F002Check::that('AK3', $b['audio_status'] === 'fejlet' && $b['audio_error'] === 'optagelsen kunne ikke hentes (HTTP 500)', 'status og fejltekst ses på opkaldet');
F002Check::that('AK3', count($http->to('https://media.rec-storage.test/')) === 1, 'en hentet optagelse hentes ikke igen');

// Et senere forsøg, der lykkes, rydder fejlen
$retry = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/retry', 'started_at' => '2026-10-07 10:00:00']);
$http->on('GET', F002_BASE . '/api/v2/rec/retry', new HttpResult(503, [], ''), new HttpResult(200, ['content-type' => 'audio/wav'], 'RIFF' . random_bytes(100)));
$sync->downloadPending();
F002Check::that('AK3', DB::scalar('SELECT audio_status FROM calls WHERE id = ?', [$retry]) === 'fejlet', 'første forsøg fejlet');
$sync->downloadPending();
$rr = DB::all('SELECT * FROM calls WHERE id = ?', [$retry])[0];
F002Check::that('AK3', $rr['audio_status'] === 'hentet' && $rr['audio_error'] === null && str_ends_with((string) $rr['audio_path'], '.wav'),
    'andet forsøg: hentet som .wav, fejlen ryddet');

// En afbrudt download (fx fuld disk) efterlader ingen halv fil
$part = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/disk', 'started_at' => '2026-10-08 10:00:00']);
$http->on('GET', F002_BASE . '/api/v2/rec/disk', new \Whisper\Zendesk\ZendeskError('netværksfejl (curl 23)'));
$sync->downloadPending();
F002Check::that('AK3', glob($var . '/recordings/*/*/' . $part . '.*') === [], 'ingen fil efter en afbrudt download');
F002Check::that('AK3', DB::scalar('SELECT audio_status FROM calls WHERE id = ?', [$part]) === 'fejlet', 'markeret fejlet');

F002Check::that('AK3', Sync::extension('application/octet-stream', 'https://x/y', $file) === 'mp3', 'lydformat fra filens start (ID3)');
Paths::setVarDir(null);
F002Check::done();
