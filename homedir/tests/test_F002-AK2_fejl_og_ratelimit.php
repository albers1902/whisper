<?php
declare(strict_types=1);

// F002-AK2: sync håndterer 429 med Retry-After uden datatab, stopper ved netværksfejl uden halve rækker og gør intet,
// når [zendesk] mangler. Kun read-scopes accepteres (et tomt scope ville give skriveadgang).

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Sync;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

$var = f002_setup();
$cfg = f002_cfg();
$T1 = F002_SINCE + 3600;
$url = static fn(int $n): string => F002_BASE . '/api/v2/rec/' . $n;
$comments = static fn(int $call, int $n): HttpResult => f002_json(['comments' => [
    f002_voice($call, $url($n), '+4512345678', '+4570000000', '2026-10-05T10:00:00Z', 120)], 'meta' => ['has_more' => false]]);

// 1. 429 med kort Retry-After: der ventes, og intet går tabt
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', f002_export_url(F002_SINCE),
    new HttpResult(429, ['retry-after' => '7'], ''),
    f002_json(['calls' => [f002_call(201, 21)], 'count' => 1, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(21), $comments(201, 1));
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$sleeps = [];
$r = (new Sync(f002_client($cfg, $http, null, $sleeps), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();
F002Check::that('AK2', $sleeps === [7], 'ventede Retry-After (7 s) og prøvede igen');
F002Check::that('AK2', $r['error'] === null && (int) DB::scalar('SELECT COUNT(*) FROM calls') === 1, 'opkaldet er gemt efter 429');
F002Check::that('AK2', DB::scalar('SELECT cursor_value FROM sync_state') === (string) $T1, 'cursoren er flyttet');

// 2. 429 med lang Retry-After: kørslen stopper pænt, cursor og gemte rækker bevares
$T2 = $T1 + 3600;
$http->on('GET', f002_export_url($T1),
    f002_json(['calls' => [f002_call(202, 22)], 'count' => 1, 'end_time' => $T2, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(22), $comments(202, 2));
$http->on('GET', f002_export_url($T2), new HttpResult(429, ['retry-after' => '600'], ''));
$sleeps = [];
$r = (new Sync(f002_client($cfg, $http, null, $sleeps), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();
F002Check::that('AK2', $r['error'] !== null && str_contains($r['error'], '429'), 'lang Retry-After: stoppet med rate limit-fejl');
F002Check::that('AK2', $sleeps === [], 'ventede ikke 600 s');
F002Check::that('AK2', (int) DB::scalar('SELECT COUNT(*) FROM calls') === 2, 'siden før 429 er gemt (intet datatab)');
F002Check::that('AK2', DB::scalar('SELECT cursor_value FROM sync_state') === (string) $T2, 'cursoren står efter den gemte side');
F002Check::that('AK2', str_contains((string) DB::scalar('SELECT last_error FROM sync_state'), '429'), 'fejlen står i sync_state');

// 3. Netværksfejl midt i en side: ingen halve rækker, og cursoren flyttes ikke
f002_setup();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [f002_call(301, 31), f002_call(302, 32)], 'count' => 2,
    'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(31), $comments(301, 3));
$http->on('GET', f002_comments_url(32), new ZendeskError('netværksfejl (curl 7)'));
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();
F002Check::that('AK2', $r['error'] === 'netværksfejl (curl 7)', 'netværksfejl stopper kørslen');
F002Check::that('AK2', DB::scalar('SELECT cursor_value FROM sync_state') === null, 'cursoren er ikke flyttet');
$rows = DB::all('SELECT * FROM calls');
F002Check::that('AK2', count($rows) === 1 && $rows[0]['external_id'] === '301', 'kun det komplette opkald er gemt');
F002Check::that('AK2', $rows !== [] && $rows[0]['recording_url'] !== '' && $rows[0]['agent_name'] === 'A'
    && $rows[0]['customer_phone'] !== '', 'rækken har alle felter (ingen halv række)');
// Næste kørsel tager samme side igen uden dubletter
$http->on('GET', f002_comments_url(32), $comments(302, 4));
$http->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();
F002Check::that('AK2', $r['error'] === null && (int) DB::scalar('SELECT COUNT(*) FROM calls') === 2, 'næste kørsel gør siden færdig uden dubletter');

F002Check::that('AK2', (int) DB::scalar("SELECT audio_attempts FROM calls WHERE external_id = '301'") >= 1,
    'optagelser hentes også i en kørsel, hvor opkaldshentningen stoppede');

// 3b. HTTP-fejl på ét ticket (403/500) springer kun det opkald over; siden fortsætter (QA F-1)
f002_setup();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [f002_call(401, 41), f002_call(402, 42), f002_call(403, 43)],
    'count' => 3, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(41), f002_json(['error' => 'Forbidden'], 403));
$http->on('GET', f002_comments_url(42), $comments(402, 5));
$http->on('GET', f002_comments_url(43), f002_json(['error' => 'x'], 500));
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$logs = [];
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static function (string $l) use (&$logs): void {
    $logs[] = $l;
}))->run();
F002Check::that('AK2', $r['error'] === null, 'et ticket med 403 stopper ikke kørslen');
F002Check::that('AK2', DB::all('SELECT external_id FROM calls') === [['external_id' => '402']], 'de øvrige opkald på siden er gemt');
F002Check::that('AK2', DB::scalar('SELECT cursor_value FROM sync_state') === (string) $T1, 'cursoren flyttes forbi siden');
F002Check::that('AK2', in_array('zendesk-sync: opkald 401: springes over (HTTP 403 fra Zendesk)', $logs, true)
    && in_array('zendesk-sync: opkald 403: springes over (HTTP 500 fra Zendesk)', $logs, true), 'de oversprungne logges med opkalds-id');

// 4. [zendesk] mangler: cron-scriptet gør intet og logger én linje
F002Check::that('AK2', ZendeskConfig::fromSection([]) === null, 'tom sektion = ikke sat op');
f002_setup();
putenv('WHISPER_VAR_DIR=' . $var);
putenv('WHISPER_TEST_FLAGS=F001_brugere,F002_zendesk');   // flaget tændt i scriptets proces (testmiljøet har env = test)
$cmd = [PHP_BINARY, __DIR__ . '/../cron/zendesk-sync.php'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
$code = is_resource($p) ? proc_close($p) : -1;
F002Check::that('AK2', $code === 0, "cron/zendesk-sync.php uden [zendesk]: exit 0 (fik $code)");
F002Check::that('AK2', substr_count(trim($out), "\n") === 0 && str_contains($out, '[zendesk] mangler'), 'præcis én linje i loggen');
F002Check::that('AK2', (int) DB::scalar('SELECT COUNT(*) FROM calls') === 0, 'intet hentet');

// 5. Scope: kun read; tomt scope og write afvises
$base = ['subdomain' => 'testsub', 'client_id' => 'id', 'client_secret' => 'x' . bin2hex(random_bytes(4)), 'since' => '2026-10-01'];
F002Check::that('AK2', ZendeskConfig::fromSection($base)?->scope === 'read', 'uden scope-linje: standard read');
foreach (['', '   ', 'write', 'read write', 'tickets:write', 'tickets:read impersonate', 'read tickets:write'] as $bad) {
    $ok = false;
    try {
        ZendeskConfig::fromSection($base + ['scope' => $bad]);
    } catch (ZendeskError) {
        $ok = true;
    }
    F002Check::that('AK2', $ok, "scope \"$bad\" afvises");
}
F002Check::that('AK2', ZendeskConfig::fromSection($base + ['scope' => 'tickets:read users:read'])?->scope === 'tickets:read users:read',
    'smalle read-scopes accepteres');
foreach ([['subdomain' => 'a.evil.com'], ['since' => 'i går'], ['client_secret' => '']] as $bad) {
    $ok = false;
    try {
        ZendeskConfig::fromSection($bad + $base);
    } catch (ZendeskError) {
        $ok = true;
    }
    F002Check::that('AK2', $ok, 'ugyldig sektion afvises: ' . (string) array_key_first($bad));
}

F002Check::done();
