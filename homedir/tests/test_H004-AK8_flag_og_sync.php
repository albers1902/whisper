<?php
declare(strict_types=1);

// H004-AK8: WHISPER_TEST_FLAGS virker kun i CLI (DECISIONS #17). En fejl ved agent-opslaget gemmer opkaldet med tomt
// agentnavn, og lookback retter navnet (QA F002 N-2). Et opkald, der springes over, logges højst én gang pr. døgn (N-3).

require_once __DIR__ . '/h004_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Sync;

// 1. Flag-hooken
putenv('WHISPER_TEST_FLAGS=H004_findes_ikke');
H004Check::that('AK8', Flags::testHook('H004_findes_ikke', 'cli'), 'CLI med WHISPER_TEST=1: hooken virker (F002-AK2 bruger den)');
foreach (['fpm-fcgi', 'apache2handler', 'cgi-fcgi', 'cli-server'] as $sapi) {
    H004Check::that('AK8', !Flags::testHook('H004_findes_ikke', $sapi), "$sapi: WHISPER_TEST_FLAGS har ingen effekt");
}
putenv('WHISPER_TEST_FLAGS');

// 2. Agent-opslaget fejler med 500: opkaldet gemmes alligevel
f002_setup();
$cfg = f002_cfg();
$T1 = F002_SINCE + 3600;
$comments = f002_json(['comments' => [f002_voice(501, F002_BASE . '/api/v2/rec/1', '+4512345678', '+4570000000', '2026-10-05T10:00:00Z', 120)],
    'meta' => ['has_more' => false]]);
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [f002_call(501, 51)], 'count' => 1, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(51), $comments);
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['error' => 'x'], 500));
$http->on('GET', F002_BASE . '/api/v2/rec/*', new HttpResult(500, [], ''));
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->fetchCalls();
$row = DB::all("SELECT agent_name FROM calls WHERE external_id = '501'");
H004Check::that('AK8', $r === 1 && $row === [['agent_name' => '']], 'agent-opslag med 500: opkaldet er gemt med tomt agentnavn');

$http2 = new FakeTransport();
$http2->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http2->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [f002_call(501, 51)], 'count' => 1, 'end_time' => $T1, 'next_page' => 'x']));
$http2->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$http2->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'Anne Agent']]));
(new Sync(f002_client($cfg, $http2), $cfg, static fn(): int => F002_NOW + 600, static fn(string $l) => null))->fetchCalls();
H004Check::that('AK8', DB::scalar("SELECT agent_name FROM calls WHERE external_id = '501'") === 'Anne Agent', 'næste lookback retter navnet');

// 3. Et opkald, der springes over pga. HTTP-fejl, logges højst én gang pr. døgn
f002_setup();
$http3 = new FakeTransport();
$http3->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http3->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [f002_call(601, 61)], 'count' => 1, 'end_time' => $T1, 'next_page' => 'x']));
$http3->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$http3->on('GET', f002_comments_url(61), f002_json(['error' => 'Forbidden'], 403));
$http3->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$logs = [];
$run = static function (int $now) use ($cfg, $http3, &$logs): void {
    $sleeps = [];
    (new Sync(f002_client($cfg, $http3, null, $sleeps, $now), $cfg, static fn(): int => $now, static function (string $l) use (&$logs): void {
        $logs[] = $l;
    }))->fetchCalls();
};
$line = 'zendesk-sync: opkald 601: springes over (HTTP 403 fra Zendesk)';
$run(F002_NOW);
$run(F002_NOW + 600);
$run(F002_NOW + 1200);
H004Check::that('AK8', count(array_keys($logs, $line, true)) === 1, 'tre kørsler inden for et døgn: én logline (fik ' . count($logs) . ')');
$run(F002_NOW + 86400 + 60);
H004Check::that('AK8', count(array_keys($logs, $line, true)) === 2, 'efter et døgn: logges igen');
H004Check::that('AK8', (int) DB::scalar('SELECT COUNT(*) FROM calls') === 0, 'opkaldet er stadig sprunget over (intet gemt)');

H004Check::done();
