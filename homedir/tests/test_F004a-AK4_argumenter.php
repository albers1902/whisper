<?php
declare(strict_types=1);

// F004a-AK4: --call=<id> valideres (kun cifre — Adversus' opkalds-id er et heltal), og ukendte argumenter afvises.
// Scriptet selv svarer kun på CLI.

require_once __DIR__ . '/f004a_lib.php';

use Whisper\Zendesk\HttpResult;

f004a_setup();

foreach (['--call=abc', '--call=12a', '--call=0', '--call=-5', '--call=', '--call=1 OR 1', '--call=99999999999999999999', '--foo',
    'call=5', '--call=5 --call=6'] as $bad) {
    $http = new FakeTransport();
    $r = f004a_run([$bad], f004a_section(), $http);
    F004aCheck::that('AK4', $r['code'] === 2 && str_contains($r['out'], 'ukendt argument') && $http->requests === [],
        "afvist uden HTTP: '$bad'");
}
$http = new FakeTransport();
$r = f004a_run(['--call=5', '--call=6'], f004a_section(), $http);
F004aCheck::that('AK4', $r['code'] === 2 && $http->requests === [], 'to --call afvises');
$r = f004a_run(['--call=5', '--verbose'], f004a_section(), new FakeTransport());
F004aCheck::that('AK4', $r['code'] === 2, 'et ekstra ukendt argument afvises');

// Gyldigt id: opkaldets tal og optagelsens kæde for netop det id
$http = new FakeTransport();
f004a_defaults($http);
$http->on('GET', F004A_API . '/calls?*', f004a_page('calls', [f004a_call(77), f004a_call(4242, 'inbound')], 1, 1, 2));
$http->on('GET', F004A_API . '/calls/4242/recording', new HttpResult(404, [], '{"code":404,"message":"not found"}'));
$r = f004a_run(['--call=4242'], f004a_section(), $http);
echo $r['out'], "\n";
F004aCheck::that('AK4', str_contains($r['out'], 'Opkald 4242: duration 95 · conversation 80 · billable 90 · queued 3 · dialing 5 · origin inbound · disposition answered'),
    'opkaldets tal vises');
F004aCheck::that('AK4', count($http->to(F004A_API . '/calls/4242/recording')) === 1 && $http->to(F004A_API . '/recordings') === [],
    'kun det valgte opkalds optagelse hentes');
F004aCheck::that('AK4', str_ends_with($r['out'], 'PROBE: FÆRDIG'), 'sidste linje PROBE: FÆRDIG');

// Scriptet: CLI-only-vagten står før alt andet
$src = (string) file_get_contents(__DIR__ . '/../cron/adversus-probe.php');
$guard = strpos($src, "if (PHP_SAPI !== 'cli')");
F004aCheck::that('AK4', $guard !== false && $guard < (int) strpos($src, 'require_once'), 'cron/adversus-probe.php afviser andet end CLI først');

F004aCheck::done();
