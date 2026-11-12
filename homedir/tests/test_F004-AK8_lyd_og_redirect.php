<?php
declare(strict_types=1);

// F004-AK8: lyd hentes via /calls/{id}/recording, og redirect følges kun til en godkendt vært. En ugodkendt vært
// afvises, og der gemmes ingen signeret URL. Authorization sendes aldrig til lydværten.

require_once __DIR__ . '/f004_lib.php';

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

// 3. Opkald uden optagelse (404): fejlet med kort tekst, højst 5 forsøg
DB::exec("UPDATE calls SET audio_status = 'venter', audio_attempts = 0 WHERE external_id = '8001'");
$http->redirectHost = F004_AUDIO_HOST;
$http->noRecording = [8001];
for ($i = 0; $i < 7; $i++) {
    ['sync' => $s] = f004_sync(f004_cfg([F004_AUDIO_HOST]), $http, $now + 1200 + $i * 600);
    $s->run();
}
$a = DB::all("SELECT * FROM calls WHERE external_id = '8001'")[0];
F004Check::that('AK8', (int) $a['audio_attempts'] === 5 && $a['audio_status'] === 'fejlet', 'højst 5 forsøg');
F004Check::done();
