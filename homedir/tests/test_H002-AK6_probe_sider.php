<?php
declare(strict_types=1);

// H002-AK6: proben skanner op til 5 sider af Talk-exporten (ikke kun den første) og tæller opkald med
// recording_time > 0, med ticket_id, begge, og dem Sync vil slå op.

require_once __DIR__ . '/h002_lib.php';

use Whisper\Zendesk\Probe;

$cfg = f002_cfg();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
// 7 sider à 2 opkald; optagelsen ligger først på side 3
$t = F002_SINCE;
for ($p = 1; $p <= 7; $p++) {
    $next = $t + 3600;
    $calls = [
        ['id' => $p * 10 + 1, 'ticket_id' => null, 'recording_time' => 0, 'duration' => 0, 'completion_status' => 'abandoned_in_ivr'],
        $p === 3 ? f002_call($p * 10 + 2, 100 + $p) : h002_answered_call($p * 10 + 2, 100 + $p, '2026-11-11T11:17:30Z'),
    ];
    $http->on('GET', f002_export_url($t), f002_json(['calls' => $calls, 'count' => 2, 'end_time' => $next, 'next_page' => 'x']));
    $t = $next;
}
$scan = Probe::scanExport(f002_client($cfg, $http), F002_SINCE);
H002Check::that('AK6', $scan['pages'] === 5, 'højst 5 sider (fik ' . $scan['pages'] . ')');
H002Check::that('AK6', count($http->to(F002_BASE . '/api/v2/channels/voice/stats/incremental/calls.json')) === 5, '5 kald til exporten');
H002Check::that('AK6', $scan['calls'] === 10, '10 opkald talt');
H002Check::that('AK6', $scan['recording'] === 1 && $scan['ticket'] === 5 && $scan['both'] === 1, 'recording_time > 0: 1, ticket_id: 5, begge: 1');
H002Check::that('AK6', $scan['candidates'] === 5, 'Sync slår 5 op (1 optaget + 4 besvarede)');
H002Check::that('AK6', is_array($scan['recorded']) && $scan['recorded']['id'] === 32, 'opkaldet med recording_time > 0 fundet på side 3');

// Stopper, når exporten er tom
$http2 = new FakeTransport();
$http2->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http2->on('GET', f002_export_url(F002_SINCE), f002_json(['calls' => [], 'count' => 0, 'end_time' => F002_SINCE, 'next_page' => 'x']));
$scan = Probe::scanExport(f002_client($cfg, $http2), F002_SINCE);
H002Check::that('AK6', $scan['pages'] === 1 && $scan['calls'] === 0, 'tom export: én side');

// Tallene, proben udskriver, er rene tal/enum-værdier
$n = Probe::numbers(h002_answered_call(1, 2, '2026-11-11T11:17:30Z') + ['completion_status' => 'completed']);
H002Check::that('AK6', $n === 'recording_time 0 · duration 74 · not_recording_time 0 · talk_time 70 · completion_status completed · direction inbound',
    "numbers(): $n");
$n = Probe::numbers(['completion_status' => '<script>', 'duration' => '+4512345678']);
H002Check::that('AK6', !str_contains($n, 'script') && !str_contains($n, '4512345678'), 'kun tal og enum-værdier udskrives');

H002Check::done();
