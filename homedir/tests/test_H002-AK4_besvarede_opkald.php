<?php
declare(strict_types=1);

// H002-AK4: Sync slår VoiceComment op for et opkald med ticket_id, når recording_time > 0, ELLER når opkaldet er
// besvaret (duration > 0 og completion_status "completed" eller talk_time > 0). Hos ejeren har optagede opkald
// recording_time 0 — før rettelsen blev de aldrig slået op og aldrig gemt.

require_once __DIR__ . '/h002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\Sync;

// Reglen
$base = ['recording_time' => 0, 'duration' => 74, 'talk_time' => 0, 'completion_status' => 'abandoned_in_queue'];
H002Check::that('AK4', Sync::recordingHint(['recording_time' => 30] + $base) === Sync::HINT_RECORDED, 'recording_time > 0: slås op');
H002Check::that('AK4', Sync::recordingHint(['completion_status' => 'completed'] + $base) === Sync::HINT_ANSWERED,
    'recording_time 0, duration > 0 og completed: slås op');
H002Check::that('AK4', Sync::recordingHint(['talk_time' => 70] + $base) === Sync::HINT_ANSWERED,
    'recording_time 0, duration > 0 og talk_time > 0: slås op');
H002Check::that('AK4', Sync::recordingHint($base) === '', 'opgivet i kø uden samtale: slås ikke op');
H002Check::that('AK4', Sync::recordingHint(['duration' => 0, 'completion_status' => 'completed'] + $base) === '',
    'duration 0: slås ikke op');
H002Check::that('AK4', Sync::recordingHint([]) === '', 'ingen felter: slås ikke op');
H002Check::that('AK4', Sync::recordingHint(['duration' => '74', 'talk_time' => '70', 'recording_time' => null]) === Sync::HINT_ANSWERED,
    'tal som tekst accepteres');

// Hele vejen gennem Sync: optaget opkald med recording_time 0 bliver gemt
f002_setup();
$cfg = f002_cfg();
$updated = '2026-11-11T11:17:30Z';
$end = (int) strtotime($updated) + 60;
$answered = h002_answered_call(901, 3901, $updated);
$abandoned = ['completion_status' => 'abandoned_in_queue', 'talk_time' => 0] + h002_answered_call(902, 3902, $updated);
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [$answered, $abandoned], 'count' => 2, 'end_time' => $end, 'next_page' => 'x']));
$http->on('GET', f002_export_url($end), f002_json(['calls' => [], 'count' => 0, 'end_time' => $end, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(3901), f002_json(['comments' => [
    f002_voice(901, F002_BASE . '/api/v2/rec/901', '+4512345678', '+4570000000', '2026-11-11T11:16:00Z', 74)], 'meta' => ['has_more' => false]]));
$http->on('GET', f002_comments_url(3902), f002_json(['comments' => [], 'meta' => ['has_more' => false]]));
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$http->on('GET', F002_BASE . '/api/v2/rec/901', new \Whisper\Zendesk\HttpResult(200, ['content-type' => 'audio/mpeg'], 'ID3' . str_repeat('x', 100)));
$r = (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();
H002Check::that('AK4', $r['error'] === null, 'kørslen lykkes');
H002Check::that('AK4', DB::all('SELECT external_id, ticket_id FROM calls') === [['external_id' => '901', 'ticket_id' => 3901]],
    'det besvarede opkald med recording_time 0 er gemt');
H002Check::that('AK4', count($http->to(f002_comments_url(3901))) === 1, 'ticket 3901 slået op én gang');
H002Check::that('AK4', count($http->to(f002_comments_url(3902))) === 0, 'det opgivne opkald koster intet kommentarkald');

H002Check::done();
