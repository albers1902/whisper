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

/**
 * Et lille Adversus i hukommelsen til v2-testene (F004a-AK6…AK10): svarer på /organization, /calls, /recordings,
 * /sessions, /campaigns, /leads og /calls/{id}/recording og overholder filtrene status, lastUpdatedTime (mod
 * lastModifiedTime) og leadId, sideinddeling og includeMeta. Hvert filter kan slås fra (ignoreres) eller afvises (HTTP).
 */
final class F004aApi implements \Whisper\Zendesk\HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    public array $requests = [];
    /** @var list<array<string, mixed>> */
    public array $leads = [];
    /** @var list<array<string, mixed>> */
    public array $calls = [];
    /** @var list<array<string, mixed>> */
    public array $recordings = [];
    /** @var array<int, true> opkald, hvor /calls/{id}/recording svarer 307 */
    public array $hasRecording = [];
    public bool $statusFilter = true;
    public bool $timeFilter = true;
    public bool $leadFilter = true;
    /** HTTP-status, når /calls får et leadId-filter (0 = svar normalt) */
    public int $leadFilterReject = 0;
    /** Hvert andet kald får 429 med Retry-After 1 */
    public bool $flaky = false;
    /** 307'ens mål (optagelsesværten) */
    public string $location = 'https://media.example-cdn.test/rec.mp3?X-Signature=abc';

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        if ($this->flaky && count($this->requests) % 2 === 1) {
            return new HttpResult(429, ['retry-after' => '1'], '');
        }
        if (!str_starts_with($url, F004A_API . '/')) {
            if ($url === $this->location) {   // optagelsesværten svarer med en redirect til sig selv (løkke)
                return new HttpResult(307, ['location' => $this->location], '');
            }
            return new HttpResult(404, [], '');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $f = is_string($q['filters'] ?? null) ? (array) json_decode($q['filters'], true) : [];
        if (preg_match('#^/v1/calls/(\d+)/recording$#', $path, $m) === 1) {
            return isset($this->hasRecording[(int) $m[1]]) ? new HttpResult(307, ['location' => $this->location], '')
                : new HttpResult(404, [], '{"code":404,"message":"Recording not found"}');
        }
        switch ($path) {
            case '/v1/organization':
                return f004a_json(['id' => 1, 'name' => 'TrygEnergi Test A/S']);
            case '/v1/leads':
                $items = array_values(array_filter($this->leads, function (array $l) use ($f): bool {
                    if ($this->statusFilter && isset($f['status']['$eq']) && $l['status'] !== $f['status']['$eq']) {
                        return false;
                    }
                    return !($this->timeFilter && isset($f['lastUpdatedTime']['$gt']) && $l['lastModifiedTime'] <= $f['lastUpdatedTime']['$gt']);
                }));
                return $this->page('leads', $items, $q);
            case '/v1/calls':
                if (isset($f['leadId']) && $this->leadFilterReject > 0) {
                    return f004a_json(['code' => $this->leadFilterReject, 'message' => 'Invalid filter property leadId'], $this->leadFilterReject);
                }
                $items = array_values(array_filter($this->calls, fn(array $c): bool
                    => !($this->leadFilter && isset($f['leadId']['$eq']) && $c['leadId'] !== $f['leadId']['$eq'])));
                return $this->page('calls', $items, $q);
            case '/v1/recordings':
                return $this->page('recordings', $this->recordings, $q);
            case '/v1/sessions':
            case '/v1/campaigns':
                return $this->page(substr($path, 4), [], $q);
        }
        return new HttpResult(404, [], '');
    }

    /**
     * @param list<array<string, mixed>> $all
     * @param array<mixed> $q
     */
    private function page(string $key, array $all, array $q): HttpResult
    {
        $size = (int) ($q['pageSize'] ?? 100);
        $p = (int) ($q['page'] ?? 1);
        $out = [$key => array_slice($all, ($p - 1) * $size, $size)];
        if (($q['includeMeta'] ?? '') === 'true') {
            $out['meta'] = ['pagination' => ['page' => $p, 'pageSize' => $size, 'total' => count($all),
                'pageCount' => (int) ceil(count($all) / max(1, $size))]];
        }
        return f004a_json($out);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>}> */
    public function to(string $prefix): array
    {
        return array_values(array_filter($this->requests, static fn(array $r): bool => str_starts_with($r['url'], $prefix)));
    }
}

/** @return array<string, mixed> et lead som /leads leverer det, med persondata i masterData/resultData */
function f004a_lead(int $id, string $status, string $modified = '2026-10-15T10:00:00Z'): array
{
    return ['id' => $id, 'campaignId' => 11, 'contactAttempts' => 2, 'lastModifiedTime' => $modified, 'updated' => $modified,
        'nextContactTime' => null, 'importedTime' => '2026-01-02T03:04:05Z', 'lastContactedBy' => 7, 'status' => $status,
        'active' => false, 'externalId' => 77, 'importId' => 3,
        'masterData' => [['id' => 1, 'value' => 'Jens Hansen'], ['id' => 2, 'value' => '+4520304050']],
        'resultData' => [['id' => 9, 'value' => 'Aftale om skift til groen stroem']]];
}

/** @return array<string, mixed> et opkald på et lead */
function f004a_lead_call(int $id, int $leadId, int $conversation): array
{
    $c = f004a_call($id, 'outbound', $conversation > 0 ? 'answered' : 'no-answer', $conversation + 10, $conversation);
    $c['leadId'] = $leadId;
    return $c;
}

/** @return array<string, mixed> en optagelse for et opkald (som /recordings leverer den) */
function f004a_recording(int $callId): array
{
    return ['cdr' => ['id' => $callId, 'timestamp' => '2026-10-15T10:02:00Z'],
        'recording' => ['name' => 'rec-' . $callId . '.mp3', 'download' => 'https://media.example-cdn.test/rec-' . $callId . '.mp3?sig=x'],
        'clips' => [], 'timestamp' => '2026-10-15T10:03:00Z'];
}

/**
 * Standard-Adversus til AK7/AK9: 30 succes-leads siden since (5001…5030), 10 ældre succes-leads, 20 andre. De første fem
 * succes-leads siden since har kendte opkald:
 *   5001: 3 opkald (120 s i /recordings, 45 s med 307, 0 s uden) → 2 optaget
 *   5002: 1 opkald (300 s, 307)                                    → 1
 *   5003: 2 opkald (0 s, 0 s, ingen)                               → 0
 *   5004: ingen opkald                                             → 0
 *   5005: 1 opkald (60 s, 307)                                     → 1
 * I alt 4 optagede opkald på 5 leads = 0,80 pr. lead → overslag 30 × 0,80 = 24.
 */
function f004a_api(): F004aApi
{
    $a = new F004aApi();
    for ($i = 1; $i <= 10; $i++) {
        $a->leads[] = f004a_lead(4000 + $i, 'success', '2026-09-01T08:00:00Z');
    }
    for ($i = 1; $i <= 30; $i++) {
        $a->leads[] = f004a_lead(5000 + $i, 'success');
    }
    for ($i = 1; $i <= 20; $i++) {
        $a->leads[] = f004a_lead(6000 + $i, $i % 2 === 0 ? 'notInterested' : 'automaticRedial');
    }
    $a->calls = [f004a_lead_call(101, 5001, 120), f004a_lead_call(102, 5001, 0), f004a_lead_call(103, 5001, 45),
        f004a_lead_call(201, 5002, 300), f004a_lead_call(301, 5003, 0), f004a_lead_call(302, 5003, 0),
        f004a_lead_call(501, 5005, 60), f004a_lead_call(901, 6001, 30)];
    $a->recordings = [f004a_recording(101)];
    $a->hasRecording = [101 => true, 103 => true, 201 => true, 501 => true];
    return $a;
}

/**
 * Kører proben mod F004aApi.
 *
 * @param array<string, mixed> $section
 * @param list<string> $args
 * @return array{code: int, out: string}
 */
function f004a_run_api(F004aApi $api, array $section, array $args = []): array
{
    $lines = [];
    $code = Probe::run($args, $section, $api, static function (string $l) use (&$lines): void {
        $lines[] = $l;
    }, static function (int $s): void {
    });
    return ['code' => $code, 'out' => implode("\n", $lines)];
}
