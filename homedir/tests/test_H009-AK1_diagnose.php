<?php
declare(strict_types=1);

// H009-AK1: diagnosen bruger højst 40 kald, skriver intet i databasen og udskriver rå tidsstempler og resultatet af
// hvert opslag (id-filter, forskudte start-vinduer, timestamp-filter) uden telefonnumre, navne eller URL'er. Den slutter
// med DIAGNOSE: FÆRDIG, også ved 429.

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Client;
use Whisper\Adversus\Diagnose;
use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 18 * 3600;
$cfg = f004_cfg();

/** 1000 opkald i de sidste 3 timer, hvert femte med optagelse; cdr.timestamp = sluttid (opkaldet varer 400 s). */
$api = static function () use ($now): FakeAdversus {
    $http = new FakeAdversus();
    for ($i = 0; $i < 1000; $i++) {
        $start = $now - 3 * 3600 + $i * 10;
        if ($i % 5 === 0) {
            f004_call($http, 5000 + $i, 900 + $i, $start, 400);
            f004_recording($http, 5000 + $i, $start + 400, $start + 430);
        } else {
            f004_call($http, 5000 + $i, 900 + $i, $start, 30);
        }
    }
    return $http;
};

/**
 * @param list<array<string, mixed>> $pending
 * @return array{out: string, code: int, requests: int}
 */
$run = static function (FakeAdversus $http, array $pending = []) use ($cfg, $now): array {
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
DB::exec("INSERT INTO adversus_pending (cdr_id, cdr_at, tries, first_seen) VALUES (5100, ?, 2, ?)",
    [gmdate('Y-m-d H:i:s', $now - 3 * 3600 + 1000 + 400), gmdate('Y-m-d H:i:s', $now - 3600)]);
$pending = DB::all('SELECT cdr_id, cdr_at, tries, first_seen FROM adversus_pending');
$before = $snapshot();

// 1. Som hypotese (a): id-filter afvist (400 som leadId i F004a), og Adversus læser filtertid en time forskudt
$http = $api();
$http->rejectFilters = ['/v1/calls' => ['id']];
$http->filterShift = -3600;
$r = $run($http, $pending);
echo $r['out'], "\n";
H009Check::that('AK1', $r['requests'] <= 40, 'højst 40 kald (fik ' . $r['requests'] . ')');
H009Check::that('AK1', $r['code'] === 0 && str_ends_with($r['out'], 'DIAGNOSE: FÆRDIG'), 'slutter med DIAGNOSE: FÆRDIG, exit 0');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION id-filter: $eq afvist (HTTP 400') && str_contains($r['out'], 'interval afvist'),
    'id-filteret vises som afvist med HTTP-status');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION start-forskydning: +1 t fandt opkaldet'), 'den forskydning, der rammer, er +1 t');
H009Check::that('AK1', (bool) preg_match('/±0 t: ikke fundet/', $r['out']), 'uden forskydning findes opkaldet ikke (som sync i dag)');
H009Check::that('AK1', (bool) preg_match('/cdr\.timestamp "[0-9TZ:-]+" · recordings\.timestamp "[0-9TZ:-]+" · start "[0-9TZ:-]+"/', $r['out']),
    'rå tidsstempler side om side');
H009Check::that('AK1', str_contains($r['out'], 'cdr.timestamp − start = +400 s'), 'forskellen cdr.timestamp − start i sekunder');
H009Check::that('AK1', str_contains($r['out'], '5. ventende 1: cdr.id 5100'), 'ventende rækker indgår');
H009Check::that('AK1', !str_contains($r['out'], '+45') && !str_contains($r['out'], '12 34 56') && !str_contains($r['out'], 'http')
    && !str_contains($r['out'], 'X-Amz') && !str_contains($r['out'], 'rec-'), 'ingen telefonnumre, URL\'er eller filnavne');
H009Check::that('AK1', str_contains($r['out'], 'felter: id, leadId'), 'feltnavnene på opkaldet vises');
H009Check::that('AK1', (bool) preg_match('/KONKLUSION recordings-filter: [0-9]+ af [0-9]+ upload-tider uden for vinduet/', $r['out']),
    'en forskudt fortolkning af /recordings-filtret ses på upload-tiderne');
H009Check::that('AK1', $snapshot() === $before, 'intet skrevet i databasen');

// 2. id-filter virker (fake'en kender id), ingen forskydning
$http = $api();
$r = $run($http, $pending);
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION id-filter: $eq virker · interval virker'), 'id-filter: virker');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION start-forskydning: ±0 t fandt opkaldet'), 'uden forskydning: ±0 t');
H009Check::that('AK1', str_contains($r['out'], '· id $eq: virker'), 'ventende slås op på id, når det virker');
H009Check::that('AK1', str_contains($r['out'], 'KONKLUSION recordings-filter: som sync forventer'), 'upload-tider inden for vinduet');
H009Check::that('AK1', $r['requests'] <= 40, 'højst 40 kald (fik ' . $r['requests'] . ')');

// 3. Uventede værdier vises ikke råt
H009Check::that('AK1', Diagnose::raw('Kunde Jensen +45 12345678') === '(uventet format, 25 tegn)' && Diagnose::raw(17) === 'tal 17'
    && Diagnose::raw('2026-11-12 10:00:00') === '"2026-11-12 10:00:00"', 'kun tidsstempler og tal vises råt');

// 4. 429 på alt: pænt stop
$http = $api();
$http->fail429At = 1;
$r = $run($http, $pending);
H009Check::that('AK1', $r['code'] === 0 && str_ends_with($r['out'], 'DIAGNOSE: FÆRDIG') && str_contains($r['out'], 'KONKLUSION id-filter: ikke afgjort'),
    '429: DIAGNOSE: FÆRDIG og "ikke afgjort"');
H009Check::that('AK1', $r['requests'] <= 3, '429: stopper straks (fik ' . $r['requests'] . ')');

H009Check::done();

final class H009Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H009-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}
