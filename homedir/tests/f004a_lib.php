<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F004a-testene (ikke selv en test: run.php kører kun test_*.php). Adversus mockes med F002's
 * FakeTransport (ingen netværk). Brugernavn og adgangskode genereres i testen (ingen literaler, der ligner nøgler).
 * Testene bruger ikke databasen.
 */

require_once __DIR__ . '/f002_lib.php';

use Whisper\Adversus\Probe;
use Whisper\Paths;
use Whisper\Zendesk\HttpResult;

const F004A_API = 'https://api.adversus.io/v1';

final class F004aCheck
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F004a-$ak $msg\n";
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

/** En midlertidig var/-mappe pr. test (optagelsen lægges i var/tmp). */
function f004a_setup(): string
{
    $dir = sys_get_temp_dir() . '/whisper-f004a-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    Paths::setVarDir($dir);
    return $dir;
}

/**
 * [adversus] som config.ini giver den (strenge).
 *
 * @param list<string> $hosts
 * @return array<string, string>
 */
function f004a_section(array $hosts = []): array
{
    return ['username' => 'apiuser' . bin2hex(random_bytes(4)), 'password' => 'pw-' . bin2hex(random_bytes(12)),
        'since' => '2026-10-01', 'recording_hosts' => implode(',', $hosts)];
}

/**
 * Kører proben og samler output.
 *
 * @param list<string> $args
 * @param array<string, mixed> $section
 * @param list<int> $sleeps
 * @return array{code: int, out: string}
 */
function f004a_run(array $args, array $section, FakeTransport $http, array &$sleeps = []): array
{
    $lines = [];
    $code = Probe::run($args, $section, $http, static function (string $l) use (&$lines): void {
        $lines[] = $l;
    }, static function (int $s) use (&$sleeps): void {
        $sleeps[] = $s;
    });
    return ['code' => $code, 'out' => implode("\n", $lines)];
}

/** @param array<mixed> $data */
function f004a_json(array $data, int $status = 200): HttpResult
{
    return new HttpResult($status, ['content-type' => 'application/json'], (string) json_encode($data));
}

/** @return array<string, mixed> et opkald som /calls leverer det (call-entry), med persondata i felterne */
function f004a_call(int $id, string $origin = 'outbound', string $disp = 'answered', int $duration = 95, int $conversation = 80): array
{
    return ['id' => $id, 'campaignId' => 11, 'leadId' => 900000 + $id, 'contactId' => 5, 'userId' => 7, 'disposition' => $disp,
        'byeCause' => 'normal', 'start' => '2026-10-05T10:00:00Z', 'answer' => '2026-10-05T10:00:05Z',
        'connect' => '2026-10-05T10:00:08Z', 'end' => '2026-10-05T10:01:35Z', 'number' => '+4520304050',
        'extension' => 'Jens Hansen', 'duration' => $duration, 'queued' => 3, 'billable' => 90, 'dialing' => 5,
        'conversation' => $conversation, 'sip' => '200', 'origin' => $origin, 'anonymous' => 'false'];
}

/** @param list<array<mixed>> $items */
function f004a_page(string $key, array $items, int $page, int $pageCount, int $total): HttpResult
{
    return f004a_json(['meta' => ['pagination' => ['page' => $page, 'pageCount' => $pageCount, 'pageSize' => 100, 'total' => $total,
        'nextUrl' => 'https://api.adversus.io/v1/' . $key . '?page=' . ($page + 1)]], $key => $items]);
}

/** Standardsvar: organization, tomme lister for alt det, testen ikke selv sætter. */
function f004a_defaults(FakeTransport $http): void
{
    $http->on('GET', F004A_API . '/organization', f004a_json(['id' => 1337, 'name' => 'TrygEnergi Test A/S']));
    foreach (['sessions', 'campaigns', 'leads', 'recordings'] as $k) {
        $http->on('GET', F004A_API . '/' . $k . '?*', f004a_page($k, [], 1, 0, 0));
    }
}
