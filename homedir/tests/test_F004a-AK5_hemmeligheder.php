<?php
declare(strict_types=1);

// F004a-AK5: brugernavn, adgangskode og tokens findes aldrig i output, også ved fejl (mocks). Og 429 håndteres med
// Retry-After (højst 60 s, ellers stopper proben pænt).

require_once __DIR__ . '/f004a_lib.php';

use Whisper\Adversus\Client;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\ZendeskError;

f004a_setup();
$s = f004a_section(['media.example-cdn.test']);
$basic = base64_encode($s['username'] . ':' . $s['password']);
$leaks = static function (string $out) use ($s, $basic): bool {
    return str_contains($out, $s['username']) || str_contains($out, $s['password']) || str_contains($out, $basic)
        || stripos($out, 'Basic ' . substr($basic, 0, 6)) !== false;
};
$echo = 'user=' . $s['username'] . ' password=' . $s['password'] . ' token=' . bin2hex(random_bytes(20))
    . ' header Basic ' . $basic . ' mail ' . $s['username'] . '@trygenergi.test url https://api.adversus.io/v1/x?pass=' . $s['password'];

$scenarios = [];
// 1. Alle endpoints svarer 500 med hemmelighederne i beskeden
$h = new FakeTransport();
$h->on('GET', F004A_API . '/organization', f004a_json(['id' => 1, 'name' => 'x']));
$h->on('GET', F004A_API . '/*', f004a_json(['code' => 500, 'message' => $echo], 500));
$scenarios['500 overalt'] = $h;
// 2. Auth afvist med hemmelighederne i beskeden (også i stort/småt)
$h = new FakeTransport();
$h->on('GET', F004A_API . '/organization', f004a_json(['code' => 401, 'message' => strtoupper($s['username']) . ' ' . $echo], 401));
$scenarios['401'] = $h;
// 3. Netværksfejl fra transporten
$h = new FakeTransport();
$h->on('GET', F004A_API . '/organization', new ZendeskError('netværksfejl (curl 6)'));
$scenarios['netværk'] = $h;
// 4. Optagelsen fejler hos CDN'en
$h = new FakeTransport();
f004a_defaults($h);
$h->on('GET', F004A_API . '/calls?*', f004a_page('calls', [f004a_call(9)], 1, 1, 1));
$h->on('GET', F004A_API . '/calls/9/recording', new HttpResult(307, ['location' => 'https://media.example-cdn.test/a?sig=' . $basic], ''));
$h->on('GET', 'https://media.example-cdn.test/*', f004a_json(['code' => 403, 'message' => $echo], 403));
$scenarios['optagelse 403'] = $h;

foreach ($scenarios as $name => $http) {
    $args = $name === 'optagelse 403' ? ['--call=9'] : [];
    $r = f004a_run($args, $s, $http);
    echo "--- $name\n", $r['out'], "\n";
    F004aCheck::that('AK5', !$leaks($r['out']), "ingen hemmeligheder i output: $name");
    F004aCheck::that('AK5', !str_contains($r['out'], 'sig=') && !str_contains($r['out'], '?pass'), "ingen URL med parametre: $name");
}

// errorText direkte: alle kendte former fjernes
$t = Client::errorText(400, (string) json_encode(['code' => 400, 'message' => $echo]), [$s['username'], $s['password'], $basic]);
F004aCheck::that('AK5', !$leaks($t) && str_starts_with($t, 'HTTP 400: code 400 - ') && strlen($t) <= 200, "errorText renset: $t");

// 429: Retry-After 2 → venter 2 s og fortsætter
$sleeps = [];
$http = new FakeTransport();
f004a_defaults($http);
$http->on('GET', F004A_API . '/organization', new HttpResult(429, ['retry-after' => '2'], ''), f004a_json(['id' => 1, 'name' => 'x']));
$r = f004a_run([], $s, $http, $sleeps);
F004aCheck::that('AK5', $sleeps === [2] && str_contains($r['out'], 'Auth: HTTP 200'), '429 med Retry-After 2: venter og fortsætter');

// 429 med Retry-After over 60 s → stopper pænt uden at vente
$sleeps = [];
$http = new FakeTransport();
$http->on('GET', F004A_API . '/organization', new HttpResult(429, ['retry-after' => '3600'], ''));
$r = f004a_run([], $s, $http, $sleeps);
F004aCheck::that('AK5', $sleeps === [] && $r['code'] === 1 && str_contains($r['out'], 'rate limit (HTTP 429)')
    && str_ends_with($r['out'], 'PROBE: FÆRDIG'), '429 over 60 s: stopper pænt');

// 429 hele tiden (uden Retry-After) midt i proben → højst 3 forsøg, 60 s ventetid, stopper pænt
$sleeps = [];
$http = new FakeTransport();
f004a_defaults($http);
$http->on('GET', F004A_API . '/calls?*', new HttpResult(429, [], ''));
$r = f004a_run([], $s, $http, $sleeps);
F004aCheck::that('AK5', $sleeps === [60, 60] && count($http->to(F004A_API . '/calls?')) === 3 && $r['code'] === 1
    && str_contains($r['out'], 'rate limit'), '429 uden Retry-After: 3 forsøg, så stop');

F004aCheck::done();
