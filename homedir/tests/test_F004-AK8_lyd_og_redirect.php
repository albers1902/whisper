<?php
declare(strict_types=1);

// F004-AK8: lyd hentes via /calls/{id}/recording, og redirect følges kun til en godkendt vært. En ugodkendt vært
// afvises, og der gemmes ingen signeret URL. Authorization sendes aldrig til lydværten.

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Sync;
use Whisper\Calls;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 3 * 3600;
$http = new FakeAdversus();
f004_recorded($http, 8001, 901, $S + 600, 60);
f004_lead($http, 901, 'success', $S + 60);

// 1. Godkendt vært
$log = [];
['sync' => $sync] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $now, $log);
$sync->run();
$a = DB::all("SELECT * FROM calls WHERE external_id = '8001'")[0];
F004Check::that('AK8', $a['audio_status'] === 'hentet' && Calls::audioFile((string) $a['audio_path']) !== null, 'lyden er hentet');
F004Check::that('AK8', preg_match('#^recordings/[0-9]{4}/[0-9]{2}/' . (int) $a['id'] . '\.mp3$#', (string) $a['audio_path']) === 1,
    'lyden ligger som var/recordings/ÅÅÅÅ/MM/<id>.mp3');
$api = array_values(array_filter($http->requests, static fn(array $r): bool => str_contains($r['url'], '/v1/calls/8001/recording')));
$s3 = array_values(array_filter($http->requests, static fn(array $r): bool => str_contains($r['url'], F004_AUDIO_HOST)));
F004Check::that('AK8', count($api) === 1 && isset($api[0]['headers']['Authorization']), 'første hop til api.adversus.io med login');
F004Check::that('AK8', count($s3) === 1 && !isset($s3[0]['headers']['Authorization']), 'lydværten får ingen Authorization');
F004Check::that('AK8', !str_contains(f004_dump(), 'X-Amz') && !str_contains(f004_dump(), F004_AUDIO_HOST), 'ingen signeret URL i databasen');
F004Check::that('AK8', !str_contains(implode("\n", $log), 'X-Amz'), 'ingen signeret URL i loggen');

// 2. Ugodkendt vært
DB::exec("UPDATE calls SET audio_status = 'venter', audio_path = NULL, audio_attempts = 0 WHERE external_id = '8001'");
$http->redirectHost = 'evil.example.com';
$log = [];
['sync' => $sync2] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $now + 600, $log);
$sync2->run();
$a = DB::all("SELECT * FROM calls WHERE external_id = '8001'")[0];
F004Check::that('AK8', $a['audio_status'] === 'fejlet' && $a['audio_path'] === null, 'ugodkendt vært: lyden afvises');
F004Check::that('AK8', str_contains((string) $a['audio_error'], 'ikke er godkendt'), 'fejlteksten siger "ikke godkendt"');
F004Check::that('AK8', array_filter($http->requests, static fn(array $r): bool => str_contains($r['url'], 'evil.example.com')) === [],
    'den ugodkendte vært er aldrig kaldt');
F004Check::that('AK8', !str_contains(f004_dump(), 'evil.example.com') && !str_contains(f004_dump(), 'X-Amz'), 'ingen URL gemt');
F004Check::that('AK8', !str_contains(implode("\n", $log), 'evil') && !str_contains(implode("\n", $log), 'X-Amz'), 'ingen URL i loggen');

// 3. Opkald uden optagelse (404, H013): lige efter opkaldet prøves igen efter 30 min uden at bruge et forsøg; når
// opkaldet er 6 t gammelt, er det endeligt (alle forsøg brugt, "Lyd mangler") og spørges ikke mere
DB::exec("UPDATE calls SET audio_status = 'venter', audio_attempts = 0, audio_error = NULL WHERE external_id = '8001'");
$http->redirectHost = F004_AUDIO_HOST;
$http->noRecording = [8001];
$asked = static fn(): int => count($http->to('/v1/calls/8001/recording'));
$n0 = $asked();
['sync' => $s] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $now + 1200);
$s->run();
$a = DB::all("SELECT * FROM calls WHERE external_id = '8001'")[0];
F004Check::that('AK8', $asked() === $n0 + 1 && $a['audio_status'] === 'venter' && (int) $a['audio_attempts'] === 0
    && $a['audio_error'] === Sync::NO_AUDIO_YET, '404 lige efter opkaldet: venter igen uden forsøg');
['sync' => $s] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $now + 1800);
$s->run();
F004Check::that('AK8', $asked() === $n0 + 1, 'ikke spurgt igen inden for 30 min');
$late = $S + 600 + 60 + Sync::NO_AUDIO_FINAL + 60;
['sync' => $s] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $late);
$r404 = $s->run();
$a = DB::all("SELECT * FROM calls WHERE external_id = '8001'")[0];
F004Check::that('AK8', (int) $a['audio_attempts'] === 5 && $a['audio_status'] === 'fejlet' && $a['audio_error'] === Sync::NO_AUDIO
    && $r404['no_audio'] === 1, '404 efter 6 t: endeligt uden optagelse');
for ($i = 1; $i <= 3; $i++) {
    ['sync' => $s] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $late + $i * 3600);
    $s->run();
}
F004Check::that('AK8', $asked() === $n0 + 2, 'derefter spørges der ikke mere (' . ($asked() - $n0) . ' opslag i alt)');

// 4. Deadline (security S2): ingen nye downloads efter 8 min af kørslen. Hver hentning "tager" 300 s på testens ur.
f004_setup();
$http = new FakeAdversus();
$clock = $now + 7200;
$http->onAudio = static function () use (&$clock): void {
    $clock += 300;
};
foreach ([8101, 8102, 8103] as $k => $ext) {
    $id = f004_insert($ext, true, 'ventende', '2026-11-12 0' . ($k + 1) . ':00:00');
}
$cfg = f004_cfg([F004_AUDIO_HOST]);
$client = new \Whisper\Adversus\Client($cfg, $http, static function (int $s): void {
}, 100);
$sync = new \Whisper\Adversus\Sync($client, $cfg, static function () use (&$clock): int {
    return $clock;
}, static function (string $l): void {
});
$res = $sync->run();
F004Check::that('AK8', $res['queued'] === 2 && Sync::DOWNLOAD_DEADLINE === 480, 'efter 8 min startes ingen nye downloads (2 af 3 hentet)');
F004Check::that('AK8', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND audio_status = 'venter'") === 1, 'den tredje venter til næste kørsel');

// 5. Fejltekster (security S5): kun HTTP-status og code fra Adversus i last_error og loggen, ikke deres fritekst
f004_setup();
$http = new FakeAdversus();
$http->usersForbidden = true;
$log = [];
['sync' => $s5] = f004_sync(f004_cfg(), $http, $now, $log);
$s5->run();
$joined = implode("\n", $log);
F004Check::that('AK8', str_contains($joined, 'agentnavne ikke hentet: HTTP 403: code 403') && !str_contains($joined, 'Jensen'), 'loggen: kun status og code');
DB::exec("DELETE FROM sync_state WHERE `source` = 'adversus-users'");
$http->leadsError = true;
$log = [];
['sync' => $s6] = f004_sync(f004_cfg(), $http, $now + 600, $log);
$r6 = $s6->run();
$err = (string) DB::scalar("SELECT last_error FROM sync_state WHERE `source` = 'adversus'");
F004Check::that('AK8', $err === 'HTTP 500: code 500' && $r6['error'] === 'HTTP 500: code 500', 'last_error: kun status og code (fik ' . $err . ')');
F004Check::that('AK8', !str_contains(implode("\n", $log), 'Jensen'), 'loggen har ikke Adversus\' fritekst');
F004Check::done();
