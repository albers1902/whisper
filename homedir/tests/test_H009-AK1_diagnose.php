<?php
declare(strict_types=1);

// H009-AK1: diagnosen bruger de korrigerede filtre (væg-tid i filter_tz) og højst 40 kald, skriver intet og finder
// opkaldet til en optagelse i et vindue med 400 opkald (alle sider). raw() afviser et telefonnummer som ren
// cifferstreng (security S1), en exception giver kun "✘ uventet fejl (<klasse>)" og exit 1 (S2), og logs/deploying
// giver en meddelelse og exit 1 (S4).

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h009_lib.php';

use Whisper\Adversus\Client;
use Whisper\Adversus\Diagnose;
use Whisper\DB;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\HttpTransport;

f004_setup();
$S = f004_since();
$now = $S + 18 * 3600;

/** Et opkald hvert 3. s i de sidste 3 timer (400 pr. 20 min); hvert 25. er optaget, 60 s langt, cdr.timestamp = slut. */
$api = static function () use ($now): FakeAdversus {
    $http = new FakeAdversus();
    for ($i = 0; $i < 3600; $i++) {
        $start = $now - 3 * 3600 + $i * 3;
        f004_call($http, 5000 + $i, 900 + $i, $start, 60);
        if ($i % 25 === 0) {
            f004_recording($http, 5000 + $i, $start + 60, $start + 150);
        }
    }
    return $http;
};

/**
 * @param list<array<string, mixed>> $pending
 * @return array{out: string, code: int, requests: int}
 */
$run = static function (FakeAdversus $http, array $pending = [], ?string $tz = null) use ($now): array {
    $cfg = f004_cfg([F004_AUDIO_HOST], null, $tz);
    $lines = [];
    $client = new Client($cfg, $http, static function (int $s): void {
    }, Diagnose::MAX_REQUESTS);
    $code = Diagnose::run($client, $cfg, $pending, $now, static function (string $l) use (&$lines): void {
        $lines[] = $l;
    });
    return ['out' => implode("\n", $lines), 'code' => $code, 'requests' => count($http->requests)];
};

$snapshot = static fn(): string => (string) json_encode([DB::all('SELECT * FROM sync_state ORDER BY `source`'),
    DB::all('SELECT * FROM adversus_pending ORDER BY cdr_id'), DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus'")]);
DB::exec('DELETE FROM adversus_pending');
DB::exec('INSERT INTO adversus_pending (cdr_id, cdr_at, tries, first_seen) VALUES (6000, ?, 2, ?)',
    [gmdate('Y-m-d H:i:s', $now - 3 * 3600 + 1000 * 3 + 60), gmdate('Y-m-d H:i:s', $now - 3600)]);
$pending = DB::all('SELECT cdr_id, cdr_at, tries, first_seen FROM adversus_pending');
$before = $snapshot();

// 1. Den målte adfærd (fake'ens standard) med de korrigerede filtre
$http = $api();
$r = $run($http, $pending);
echo $r['out'], "\n";
H009Check::that('AK1', $r['requests'] <= 40, 'højst 40 kald (fik ' . $r['requests'] . ')');
H009Check::that('AK1', $r['code'] === 0 && str_ends_with($r['out'], 'DIAGNOSE: FÆRDIG'), 'slutter med DIAGNOSE: FÆRDIG, exit 0');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION recordings-filter: alle '), 'upload-tiderne ligger i vinduet (korrigeret filter)');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION opkald fundet: 3 af 3 optagelser'), 'alle tre optagelsers opkald findes');
H009Check::that('AK1', str_contains($r['out'], 'fundet på side 3'), 'opkaldet ligger på side 3 af et vindue med 400 opkald (alle sider søges)');
H009Check::that('AK1', (bool) preg_match('/KONKLUSION format Z: accepteret, ([0-9]+) af \1 inden for vinduet/', $r['out']),
    'formatprøven: Z accepteres og rammer vinduet');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION cdr.timestamp − start: +60 s'), 'cdr.timestamp − start i konklusionen');
H009Check::that('AK1', (bool) preg_match('/5\. ventende: cdr\.id 6000 .* fundet på side/', $r['out']), 'den ventende række findes');
H009Check::that('AK1', (bool) preg_match('/cdr\.timestamp "[0-9TZ:-]+" · recordings\.timestamp "[0-9TZ:-]+" · start "[0-9TZ:-]+"/', $r['out']),
    'rå tidsstempler side om side');
H009Check::that('AK1', !str_contains($r['out'], '+45') && !str_contains($r['out'], '12 34 56') && !str_contains($r['out'], 'http')
    && !str_contains($r['out'], 'X-Amz') && !str_contains($r['out'], 'rec-'), 'ingen telefonnumre, URL\'er eller filnavne');
H009Check::that('AK1', $snapshot() === $before, 'intet skrevet i databasen');

// 2. Den gamle fortolkning (filtre i UTC) mod samme API: diagnosen viser, at intet passer
$r = $run($api(), $pending, 'UTC');
H009Check::that('AK1', str_contains($r['out'], 'upload-tider uden for vinduet') && str_contains($r['out'], 'KONKLUSION opkald fundet: 0 af 3'),
    'filter_tz = UTC: upload-tider uden for vinduet og 0 opkald fundet');

// 3. Kun rigtige tidsstempler og tal vises råt (S1)
H009Check::that('AK1', Diagnose::raw('4512345678') === '(uventet format, 10 tegn)' && Diagnose::raw('+45 12 34 56 78') === '(uventet format, 15 tegn)'
    && Diagnose::raw('2026-11-12T14:53:59Z') === '"2026-11-12T14:53:59Z"' && Diagnose::raw('2026-11-12 10:00:00.123+01:00') === '"2026-11-12 10:00:00.123+01:00"'
    && Diagnose::number('95') === '(uventet format, 2 tegn)' && Diagnose::number(95) === '95 s', 'telefonnumre og cifferstrenge vises ikke');

// 4. Uventede fejl (S2): én linje uden stakspor
$section = ['username' => 'apiuser' . bin2hex(random_bytes(4)), 'password' => 'pw-' . bin2hex(random_bytes(12)), 'since' => '2026-10-01'];
$out = [];
$say = static function (string $l) use (&$out): void {
    $out[] = $l;
};
$home = sys_get_temp_dir() . '/h009-' . bin2hex(random_bytes(4));
mkdir($home . '/logs', 0700, true);
$http = $api();
$code = Diagnose::cli($home, $section, $http, static function (): array {
    throw new RuntimeException('SQLSTATE[HY000] adgang nægtet for bruger whisper@localhost');
}, $now, $say);
H009Check::that('AK1', $code === 1 && $out === ['✘ uventet fejl (RuntimeException)'] && count($http->requests) === 0,
    'en fejl ved ventelisten giver kun "✘ uventet fejl (RuntimeException)" og exit 1');
$out = [];
$boom = new class implements HttpTransport {
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult
    {
        throw new LogicException('Authorization: Basic hemmeligt');
    }
};
$code = Diagnose::cli($home, $section, $boom, static fn(): array => [], $now, $say);
$all = implode("\n", $out);
H009Check::that('AK1', $code === 1 && str_contains($all, '✘ uventet fejl (LogicException)') && str_ends_with($all, 'DIAGNOSE: FÆRDIG')
    && !str_contains($all, 'Basic') && !str_contains($all, 'Stack'), 'en fejl under kørslen: én linje, ingen hemmeligheder, exit 1');

// 5. Deploy kører (S4): meddelelse, exit 1, ingen kald
touch($home . '/logs/deploying');
$out = [];
$http = $api();
$code = Diagnose::cli($home, $section, $http, static fn(): array => [], $now, $say);
H009Check::that('AK1', $code === 1 && $out === ['✘ deploy kører — prøv igen om lidt'] && count($http->requests) === 0, 'deploying: meddelelse og exit 1');
unlink($home . '/logs/deploying');
rmdir($home . '/logs');
rmdir($home);

// 6. 429 på alt: pænt stop
$http = $api();
$http->fail429At = 1;
$r = $run($http, $pending);
H009Check::that('AK1', $r['code'] === 0 && str_ends_with($r['out'], 'DIAGNOSE: FÆRDIG') && $r['requests'] <= 3, '429: DIAGNOSE: FÆRDIG og straks stop');

H009Check::done();
