<?php
declare(strict_types=1);

// H002-AK5: den bredere regel giver ikke flere kommentarkald i lookback-vinduet. Et besvaret opkald med
// recording_time 0 slås kun op, når det er nyt (updated_at efter cursoren ved kørslens start). Et opkald med
// recording_time > 0 prøves igen i lookback-vinduet som før (F002 QA F-2).

require_once __DIR__ . '/h002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\Sync;

f002_setup();
$cfg = f002_cfg();
$updated = '2026-11-11T11:17:30Z';
$end = (int) strtotime($updated) + 60;
$answered = h002_answered_call(911, 3911, $updated);
$recorded = ['recording_time' => 30] + h002_answered_call(912, 3912, $updated);
$noVoice = f002_json(['comments' => [], 'meta' => ['has_more' => false]]);

$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$page = f002_json(['calls' => [$answered, $recorded], 'count' => 2, 'end_time' => $end, 'next_page' => 'x']);
$http->on('GET', f002_export_url(F002_SINCE), $page);
$http->on('GET', f002_export_url($end - Sync::LOOKBACK), $page);   // kørsel 2 starter LOOKBACK før cursoren
$http->on('GET', f002_export_url($end), f002_json(['calls' => [], 'count' => 0, 'end_time' => $end, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(3911), $noVoice);
$http->on('GET', f002_comments_url(3912), $noVoice);
$http->on('GET', F002_BASE . '/api/v2/users/7.json', f002_json(['user' => ['name' => 'A']]));
$run = static fn() => (new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null))->run();

// Kørsel 1: begge er nye og slås op; ingen VoiceComment endnu
$r = $run();
H002Check::that('AK5', $r['error'] === null && (int) DB::scalar('SELECT COUNT(*) FROM calls') === 0, 'kørsel 1: intet gemt (ingen optagelse endnu)');
H002Check::that('AK5', count($http->to(f002_comments_url(3911))) === 1 && count($http->to(f002_comments_url(3912))) === 1,
    'kørsel 1: begge slået op én gang');
H002Check::that('AK5', DB::scalar('SELECT cursor_value FROM sync_state') === (string) $end, 'cursoren står efter siden');

// Kørsel 2: samme opkald i lookback-vinduet — kun recording_time > 0 slås op igen
$r = $run();
H002Check::that('AK5', $r['error'] === null, 'kørsel 2 lykkes');
H002Check::that('AK5', count($http->to(f002_export_url($end - Sync::LOOKBACK))) === 1, 'kørsel 2 læste lookback-vinduet');
H002Check::that('AK5', count($http->to(f002_comments_url(3911))) === 1, 'besvaret opkald (recording_time 0): intet nyt kommentarkald');
H002Check::that('AK5', count($http->to(f002_comments_url(3912))) === 2, 'recording_time > 0: prøves igen som før');

// Opdaterer Zendesk opkaldet (nyt updated_at), er det nyt igen og slås op én gang til
$later = '2026-11-11T11:30:00Z';
$end2 = (int) strtotime($later) + 60;
$http->on('GET', f002_export_url($end - Sync::LOOKBACK), f002_json(['calls' => [h002_answered_call(911, 3911, $later)], 'count' => 1,
    'end_time' => $end2, 'next_page' => 'x']));
$http->on('GET', f002_export_url($end2), f002_json(['calls' => [], 'count' => 0, 'end_time' => $end2, 'next_page' => 'x']));
$http->on('GET', f002_comments_url(3911), f002_json(['comments' => [
    f002_voice(911, F002_BASE . '/api/v2/rec/911', '+4512345678', '+4570000000', '2026-11-11T11:16:00Z', 74)], 'meta' => ['has_more' => false]]));
$r = $run();
H002Check::that('AK5', count($http->to(f002_comments_url(3911))) === 2, 'opdateret opkald slås op igen');
H002Check::that('AK5', DB::all('SELECT external_id FROM calls') === [['external_id' => '911']], 'og gemmes, når optagelsen findes');

H002Check::done();
