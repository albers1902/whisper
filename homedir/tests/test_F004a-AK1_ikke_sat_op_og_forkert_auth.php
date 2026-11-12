<?php
declare(strict_types=1);

// F004a-AK1: uden [adversus] siger proben "Adversus er ikke sat op" og afslutter med exit 0. Med forkert auth vises
// HTTP-status og en renset fejlkode uden hemmeligheder.

require_once __DIR__ . '/f004a_lib.php';

use Whisper\Zendesk\HttpResult;

f004a_setup();

// Uden sektion: intet netværk, exit 0
$http = new FakeTransport();
$r = f004a_run([], [], $http);
F004aCheck::that('AK1', $r['code'] === 0, 'exit 0 uden [adversus] (fik ' . $r['code'] . ')');
F004aCheck::that('AK1', str_contains($r['out'], 'Adversus er ikke sat op'), 'siger "Adversus er ikke sat op"');
F004aCheck::that('AK1', $http->requests === [], 'ingen HTTP-kald uden sektion');

// Ufuldstændig sektion: fejl uden værdier
$s = f004a_section();
$r = f004a_run([], ['username' => $s['username'], 'since' => '2026-10-01'], new FakeTransport());
F004aCheck::that('AK1', $r['code'] === 1 && str_contains($r['out'], 'username eller password mangler'), 'manglende password afvises');
F004aCheck::that('AK1', !str_contains($r['out'], $s['username']), 'brugernavnet står ikke i fejlen');
$r = f004a_run([], ['username' => 'a', 'password' => 'b', 'since' => '1. oktober'], new FakeTransport());
F004aCheck::that('AK1', $r['code'] === 1 && str_contains($r['out'], 'since'), 'ugyldig since afvises');
$r = f004a_run([], ['username' => 'a', 'password' => 'b', 'since' => '2026-10-01', 'recording_hosts' => 'http://x/y'], new FakeTransport());
F004aCheck::that('AK1', $r['code'] === 1 && str_contains($r['out'], 'recording_hosts'), 'ugyldig recording_hosts afvises');

// Forkert auth: Adversus svarer 401 med {code, message}; beskeden gentager brugernavnet og en header
$s = f004a_section();
$http = new FakeTransport();
$http->on('GET', F004A_API . '/organization', f004a_json(['code' => 401,
    'message' => 'Invalid credentials for user ' . $s['username'] . ' (Authorization: Basic '
        . base64_encode($s['username'] . ':' . $s['password']) . ')'], 401));
$r = f004a_run([], $s, $http);
F004aCheck::that('AK1', $r['code'] === 1, 'exit 1 ved forkert auth');
F004aCheck::that('AK1', str_contains($r['out'], 'HTTP 401: code 401 - Invalid credentials'), 'HTTP-status og fejlkode vises: ' . $r['out']);
F004aCheck::that('AK1', !str_contains($r['out'], $s['username']) && !str_contains($r['out'], $s['password'])
    && !str_contains($r['out'], base64_encode($s['username'] . ':' . $s['password'])), 'ingen hemmeligheder i fejlen');
F004aCheck::that('AK1', !str_contains($r['out'], 'PROBE: FÆRDIG') && count($http->requests) === 1, 'stopper efter auth-fejlen');
F004aCheck::that('AK1', ($http->requests[0]['headers']['Authorization'] ?? '') === 'Basic ' . base64_encode($s['username'] . ':' . $s['password']),
    'HTTP Basic auth sendes til api.adversus.io');

// 403 med en HTML-side: kun status
$http = new FakeTransport();
$http->on('GET', F004A_API . '/organization', new HttpResult(403, ['content-type' => 'text/html'], '<html>Forbidden ' . $s['username'] . '</html>'));
$r = f004a_run([], $s, $http);
F004aCheck::that('AK1', str_contains($r['out'], '✘ Auth: HTTP 403 ') && !str_contains($r['out'], $s['username']), 'HTML-fejl: kun HTTP-status');

F004aCheck::done();
