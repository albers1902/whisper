<?php
declare(strict_types=1);

// F004a-AK3: optagelsen hentes med manuelt fulgte redirects, værten vises, filen slettes bagefter, og en ikke-godkendt
// vært afvises.

require_once __DIR__ . '/f004a_lib.php';

use Whisper\Zendesk\HttpResult;

$var = f004a_setup();
$cdn = 'media.example-cdn.test';
$signed = 'https://' . $cdn . '/recordings/2026/10/abc.mp3?X-Signature=' . bin2hex(random_bytes(16));

/** @return FakeTransport et Adversus, hvor opkald 4242 har en optagelse bag en 307 */
$make = static function () use ($signed): FakeTransport {
    $http = new FakeTransport();
    f004a_defaults($http);
    $http->on('GET', F004A_API . '/calls?*', f004a_page('calls', [f004a_call(4242)], 1, 1, 1));
    $http->on('GET', F004A_API . '/recordings?*', f004a_page('recordings', [
        ['cdr' => ['id' => 4242, 'timestamp' => '2026-10-05T10:02:00Z'], 'recording' => ['name' => 'n', 'download' => $signed],
            'clips' => [], 'timestamp' => '2026-10-05T10:03:00Z']], 1, 1, 1));
    $http->on('GET', F004A_API . '/calls/4242/recording', new HttpResult(307, ['location' => $signed], ''));
    $http->on('GET', $signed, new HttpResult(200, ['content-type' => 'audio/mpeg'], 'ID3' . str_repeat('x', 1021)));
    return $http;
};
$tmpFiles = static fn(): array => glob($var . '/tmp/*') ?: [];

// Godkendt vært: hentes, kæden og størrelsen vises, filen slettes
$s = f004a_section([$cdn]);
$http = $make();
$r = f004a_run([], $s, $http);
echo $r['out'], "\n";
F004aCheck::that('AK3', str_contains($r['out'], 'Optagelse: hentet · Content-Type: audio/mpeg · 1024 bytes'), 'optagelsen hentet med Content-Type og størrelse');
F004aCheck::that('AK3', str_contains($r['out'], '1. api.adversus.io → HTTP 307') && str_contains($r['out'], "2. $cdn → HTTP 200"), 'statuskæden med værter');
F004aCheck::that('AK3', $tmpFiles() === [] && str_contains($r['out'], 'Filen er slettet igen: ja'), 'filen er slettet bagefter');
$toCdn = $http->to('https://' . $cdn);
F004aCheck::that('AK3', count($toCdn) === 1 && !isset($toCdn[0]['headers']['Authorization']), 'Authorization sendes ikke til optagelsesværten');
$toApi = $http->to(F004A_API . '/calls/4242/recording');
F004aCheck::that('AK3', count($toApi) === 1 && str_starts_with($toApi[0]['headers']['Authorization'] ?? '', 'Basic '), 'Basic auth kun til api.adversus.io');
F004aCheck::that('AK3', !str_contains($r['out'], 'X-Signature') && !str_contains($r['out'], '/recordings/2026'), 'den signerede URL vises ikke');

// Ikke-godkendt vært: intet kald dertil, værten vises som "ikke godkendt"
$http = $make();
$r = f004a_run([], f004a_section(), $http);
F004aCheck::that('AK3', $http->to('https://' . $cdn) === [], 'intet kald til en ikke-godkendt vært');
F004aCheck::that('AK3', str_contains($r['out'], 'Optagelse: optagelsen ligger på en vært, der ikke er godkendt')
    && str_contains($r['out'], "2. $cdn (ikke godkendt) → ikke hentet") && str_contains($r['out'], 'recording_hosts'), 'værten afvist og vist');
F004aCheck::that('AK3', $tmpFiles() === [], 'ingen fil tilbage efter afvisning');

// Redirect til http:// eller med brugerinfo afvises
foreach (['http://' . $cdn . '/a.mp3', 'https://user:pw@' . $cdn . '/a.mp3', 'https://' . $cdn . ':8443/a.mp3'] as $bad) {
    $http = $make();
    $http->on('GET', F004A_API . '/calls/4242/recording', new HttpResult(302, ['location' => $bad], ''));
    $r = f004a_run(['--call=4242'], f004a_section([$cdn]), $http);
    F004aCheck::that('AK3', count($http->requests) === 3 && str_contains($r['out'], '(ugyldig eller ikke https)'), 'afvist redirect: ' . parse_url($bad, PHP_URL_SCHEME));
}

// Uendelige redirects stopper
$http = $make();
$http->on('GET', F004A_API . '/calls/4242/recording', new HttpResult(307, ['location' => '/v1/calls/4242/recording'], ''));
$r = f004a_run(['--call=4242'], f004a_section(), $http);
F004aCheck::that('AK3', str_contains($r['out'], 'for mange redirects') && $r['code'] === 1, 'for mange redirects stopper');

// 404: ingen optagelse
$http = $make();
$http->on('GET', F004A_API . '/calls/4242/recording', new HttpResult(404, [], '{"code":404,"message":"Recording not found"}'));
$r = f004a_run(['--call=4242'], f004a_section(), $http);
F004aCheck::that('AK3', str_contains($r['out'], 'ingen optagelse til opkaldet (HTTP 404)'), '404 vises som ingen optagelse');

F004aCheck::done();
