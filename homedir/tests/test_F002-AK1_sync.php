<?php
declare(strict_types=1);

// F002-AK1: sync med et mocket Zendesk-svar gemmer opkaldene med alle felter; en ny kørsel med samme data giver ingen
// dubletter, og cursoren gemmes og bruges. Opkald uden optagelse gemmes ikke.

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\Sync;

f002_setup();
$cfg = f002_cfg();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());

$T1 = F002_SINCE + 5 * 86400;
$urlA = F002_BASE . '/api/v2/channels/voice/calls/CA1/twilio/recording';
$urlB = F002_BASE . '/api/v2/channels/voice/calls/CA2/twilio/recording';
$calls = [
    f002_call(101, 11, 'inbound', 30, 7, '2026-10-05T10:00:00Z', 120),
    f002_call(102, 12, 'outbound', 45, 8, '2026-10-05T11:00:00Z', 300),
    f002_call(103, 13, 'inbound', 0, 7, '2026-10-05T12:00:00Z', 0),     // ubesvaret: ingen optagelse
    f002_call(104, 14, 'inbound', 20, 7, '2026-10-05T13:00:00Z', 60),   // kommentaren matcher ikke (forkert tid)
];
$http->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => $calls, 'count' => 4, 'end_time' => $T1, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1, 'next_page' => 'x']));
// Ticket 11: kommentaren har call_id (direkte match), plus et andet opkalds voice-kommentar
$http->on('GET', f002_comments_url(11), f002_json(['comments' => [
    ['id' => 1, 'type' => 'Comment', 'body' => 'x'],
    f002_voice(999, F002_BASE . '/api/v2/andet', '+4511111111', '+4570000000', '2026-10-05T10:00:05Z', 120),
    f002_voice(101, $urlA, '+45 20 30 40 50', '+4570000000', '2026-10-05T10:00:02Z', 120),
], 'meta' => ['has_more' => false]]));
// Ticket 12: ingen call_id — fallback på tid (±2 min) og varighed (±5 s)
$http->on('GET', f002_comments_url(12), f002_json(['comments' => [
    f002_voice(null, $urlB, '+4570000000', '+4588776655', '2026-10-05T11:01:10Z', 302),
], 'meta' => ['has_more' => false]]));
$http->on('GET', f002_comments_url(14), f002_json(['comments' => [
    f002_voice(null, F002_BASE . '/api/v2/x', '+4511', '+4570', '2026-10-05T15:00:00Z', 60),
], 'meta' => ['has_more' => false]]));
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['id' => 7, 'name' => 'Anne Agent']]));
$http->on('GET', F002_BASE . '/api/v2/users/8.json', f002_json(['user' => ['id' => 8, 'name' => 'Bo Æblesen']]));

$log = [];
$sync = new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static function (string $l) use (&$log): void {
    $log[] = $l;
});
$n = $sync->fetchCalls();
F002Check::that('AK1', $n === 2, "to opkald med optagelse gemt (fik $n)");
$rows = DB::all('SELECT * FROM calls ORDER BY external_id');
F002Check::that('AK1', count($rows) === 2, 'kun opkald med optagelse og match står i databasen');
$a = $rows[0];
F002Check::that('AK1', $a['source'] === 'zendesk' && $a['external_id'] === '101', 'call-id gemt');
F002Check::that('AK1', (int) $a['ticket_id'] === 11, 'ticket-id gemt');
F002Check::that('AK1', $a['direction'] === 'inbound', 'retning gemt');
F002Check::that('AK1', $a['customer_phone'] === '+45 20 30 40 50', 'indgående: kundens nummer er "from"');
F002Check::that('AK1', $a['agent_name'] === 'Anne Agent', 'agentens navn hentet');
F002Check::that('AK1', $a['started_at'] === '2026-10-05 10:00:02', 'start-tid i UTC fra kommentaren');
F002Check::that('AK1', (int) $a['duration_sec'] === 120, 'varighed gemt');
F002Check::that('AK1', $a['recording_url'] === $urlA, 'optagelsens URL fra kommentaren med samme call_id');
F002Check::that('AK1', $a['audio_status'] === 'venter' && $a['transcript_status'] === 'ventende', 'status: lyd venter, transcription ventende');
$b = $rows[1];
F002Check::that('AK1', $b['recording_url'] === $urlB && $b['direction'] === 'outbound', 'fallback-match på tid og varighed');
F002Check::that('AK1', $b['customer_phone'] === '+4588776655', 'udgående: kundens nummer er "to"');
F002Check::that('AK1', $b['agent_name'] === 'Bo Æblesen', 'UTF-8-navn gemt');
$cursor = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', ['zendesk']);
F002Check::that('AK1', $cursor === (string) $T1, "cursoren er end_time ($cursor)");
F002Check::that('AK1', count($http->to(f002_export_url($T1))) === 1, 'næste side hentes med start_time = end_time');

// Ny kørsel: starter LOOKBACK (24 t) før cursoren, giver ingen dubletter og opdaterer ændrede felter. Opkald 104, hvis
// VoiceComment ikke fandtes sidst, kommer nu med (QA F-2)
$calls[0]['duration'] = 130;
$http->on('GET', f002_comments_url(14), f002_json(['comments' => [
    f002_voice(104, F002_BASE . '/api/v2/rec/104', '+4511', '+4570', '2026-10-05T13:00:00Z', 60)], 'meta' => ['has_more' => false]]));
$back = $T1 - Sync::LOOKBACK;
$http->on('GET', f002_export_url($back), f002_json(['calls' => [$calls[0], $calls[1], $calls[3]], 'count' => 3, 'end_time' => $T1 + 60, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1 + 60), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1 + 60, 'next_page' => 'x']));
$before = count($http->to(f002_export_url(F002_SINCE)));
$comments11 = count($http->to(f002_comments_url(11)));
$sync2 = new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null);
$sync2->fetchCalls();
F002Check::that('AK1', count($http->to(f002_export_url(F002_SINCE))) === $before && count($http->to(f002_export_url($back))) === 1,
    'anden kørsel starter 24 t før den gemte cursor, ikke ved since');
F002Check::that('AK1', (int) DB::scalar('SELECT COUNT(*) FROM calls') === 3, 'ingen dubletter; opkaldet, hvis optagelse kom senere, er gemt');
F002Check::that('AK1', DB::scalar("SELECT recording_url FROM calls WHERE external_id = '104'") === F002_BASE . '/api/v2/rec/104',
    'sent VoiceComment: opkaldet prøves igen i lookback-vinduet');
F002Check::that('AK1', count($http->to(f002_comments_url(11))) === $comments11, 'et allerede gemt opkald henter ikke kommentarer igen');
F002Check::that('AK1', (int) DB::scalar("SELECT duration_sec FROM calls WHERE external_id = '101'") === 130, 'et ændret opkald opdateres');
F002Check::that('AK1', DB::scalar('SELECT cursor_value FROM sync_state') === (string) ($T1 + 60), 'cursoren flyttet videre');

// Et allerede hentet opkalds lyd-URL overskrives ikke
DB::exec("UPDATE calls SET audio_status = 'hentet' WHERE external_id = '101'");
$http->on('GET', f002_comments_url(11), f002_json(['comments' => [
    f002_voice(101, F002_BASE . '/api/v2/ny-url', '+45 20 30 40 50', '+4570000000', '2026-10-05T10:00:02Z', 130)], 'meta' => ['has_more' => false]]));
$http->on('GET', f002_export_url($T1 + 60 - Sync::LOOKBACK), f002_json(['calls' => [$calls[0]], 'count' => 1, 'end_time' => $T1 + 120, 'next_page' => 'x']));
$http->on('GET', f002_export_url($T1 + 120), f002_json(['calls' => [], 'count' => 0, 'end_time' => $T1 + 120, 'next_page' => 'x']));
(new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->fetchCalls();
F002Check::that('AK1', DB::scalar("SELECT recording_url FROM calls WHERE external_id = '101'") === $urlA, 'hentet lyd: URL bevares');

// Cursoren flyttes pr. batch (25 opkald) inden for en side, kun fremad (QA F-8): en netværksfejl ved opkald nr. 27
// bevarer fremdriften for de første 25
DB::exec('DELETE FROM sync_state');
$many = [];
for ($i = 0; $i < 30; $i++) {
    $many[] = f002_call(5000 + $i, 9000 + $i, 'inbound', 30, 7, gmdate('Y-m-d\TH:i:s\Z', F002_SINCE + 100 + $i));
}
$http3 = new FakeTransport();
$http3->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http3->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$http3->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => $many, 'count' => 30, 'end_time' => F002_SINCE + 500, 'next_page' => 'x']));
$http3->on('GET', F002_BASE . '/api/v2/tickets/*', f002_json(['comments' => [], 'meta' => ['has_more' => false]]));
$http3->on('GET', f002_comments_url(9026), new \Whisper\Zendesk\ZendeskError('netværksfejl (curl 7)'));
$stopped = false;
try {
    (new Sync(f002_client($cfg, $http3), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->fetchCalls();
} catch (\Whisper\Zendesk\ZendeskError) {
    $stopped = true;
}
F002Check::that('AK1', $stopped && DB::scalar('SELECT cursor_value FROM sync_state') === (string) (F002_SINCE + 124),
    'cursoren står efter de 25 første opkald, da nr. 27 fejlede');

// Kørslen holder op, når end_time ikke flytter sig (next_page er aldrig null hos Zendesk)
$http2 = new FakeTransport();
$http2->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
DB::exec('DELETE FROM sync_state');
$http2->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [], 'count' => 5, 'end_time' => F002_SINCE, 'next_page' => 'x']));
(new Sync(f002_client($cfg, $http2), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->fetchCalls();
F002Check::that('AK1', count($http2->to(F002_BASE . '/api/v2/channels')) === 1, 'ingen uendelig løkke, når end_time står stille');

F002Check::done();
