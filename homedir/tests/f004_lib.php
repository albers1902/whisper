<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F004-testene (ikke selv en test: run.php kører kun test_*.php). Adversus mockes med FakeAdversus,
 * en falsk transport, der anvender filtrene ($gt, $lt, $eq), sorteringen og sideinddelingen på fixtures, som Adversus
 * gør — så cursorer og vinduer testes mod data, ikke mod faste URL'er. Ingen netværk (run.php lukker alle proxies).
 * Brugernavn og adgangskode genereres i testen.
 */

require_once __DIR__ . '/f005_lib.php';

use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\Client;
use Whisper\Adversus\Feature;
use Whisper\Adversus\Sync;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\HttpTransport;

const F004_AUDIO_HOST = 's3.gra.io.cloud.ovh.net';
const F004_SIG = 'X-Amz-Signature=f004signaturetestvalue0123456789';

final class F004Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F004-$ak $msg\n";
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

/** 2026-11-12 00:00 Europe/Copenhagen (= 2026-11-11 23:00 UTC) */
function f004_since(): int
{
    return (int) AdversusConfig::localMidnight(AdversusConfig::SYNC_SINCE_DEFAULT);
}

function f004_iso(int $t): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $t);
}

function f004_setup(): string
{
    $dir = f005_setup();
    Flags::override(Feature::FLAG, true);
    DB::exec('DELETE FROM adversus_success_leads');
    DB::exec('DELETE FROM adversus_users');
    return $dir;
}

/** @param list<string> $hosts */
function f004_cfg(array $hosts = [F004_AUDIO_HOST], ?int $syncSince = null): AdversusConfig
{
    return new AdversusConfig('apiuser' . bin2hex(random_bytes(4)), 'pw-' . bin2hex(random_bytes(12)), f004_since(), $hosts, 'success',
        $syncSince);
}

/**
 * @param list<string> $log
 * @return array{sync: Sync, client: Client}
 */
function f004_sync(AdversusConfig $cfg, FakeAdversus $http, int $now, array &$log = [], int $max = Sync::MAX_REQUESTS): array
{
    $client = new Client($cfg, $http, static function (int $s): void {
    }, $max);
    $sync = new Sync($client, $cfg, static fn(): int => $now, static function (string $l) use (&$log): void {
        $log[] = $l;
    });
    return ['sync' => $sync, 'client' => $client];
}

/**
 * Et opkald med optagelse: lægger både /calls-posten og /recordings-posten (upload $uploadDelay s efter opkaldets slut).
 *
 * @return array<string, mixed> opkaldet
 */
function f004_recorded(FakeAdversus $http, int $id, int $lead, int $start, int $duration = 120, string $origin = 'outbound',
    int $uploadDelay = 30, int $user = 77): array
{
    $call = f004_call($http, $id, $lead, $start, $duration, $origin, $user);
    $http->recordings[] = ['cdr' => ['id' => $id, 'timestamp' => f004_iso($start)], 'timestamp' => f004_iso($start + $duration + $uploadDelay),
        'recording' => ['name' => 'rec-' . $id . '.mp3', 'download' => 'https://' . F004_AUDIO_HOST . '/rec/' . $id . '.mp3?' . F004_SIG],
        'clips' => []];
    return $call;
}

/** Kun /recordings-posten (opkaldet lægges evt. senere med f004_call): cdr.timestamp og upload-tid frit. */
function f004_recording(FakeAdversus $http, int $id, int $cdrTs, int $uploadTs): void
{
    $http->recordings[] = ['cdr' => ['id' => $id, 'timestamp' => f004_iso($cdrTs)], 'timestamp' => f004_iso($uploadTs),
        'recording' => ['name' => 'rec-' . $id . '.mp3', 'download' => 'https://' . F004_AUDIO_HOST . '/rec/' . $id . '.mp3?' . F004_SIG],
        'clips' => []];
}

/** @return array<string, mixed> et opkald uden optagelse */
function f004_call(FakeAdversus $http, int $id, int $lead, int $start, int $duration = 120, string $origin = 'outbound', int $user = 77): array
{
    $call = ['id' => $id, 'leadId' => $lead, 'campaignId' => 31, 'userId' => $user, 'contactId' => 9, 'number' => '+45 12 34 56 78',
        'origin' => $origin, 'disposition' => 'answered', 'start' => f004_iso($start), 'end' => f004_iso($start + $duration),
        'duration' => $duration, 'conversation' => max(0, $duration - 10)];
    $http->calls[] = $call;
    return $call;
}

function f004_lead(FakeAdversus $http, int $id, string $status, int $updated): void
{
    foreach ($http->leads as $i => $l) {
        if ($l['id'] === $id) {
            unset($http->leads[$i]);
        }
    }
    $http->leads[] = ['id' => $id, 'status' => $status, 'campaignId' => 31, 'lastUpdatedTime' => f004_iso($updated),
        'lastModifiedTime' => f004_iso($updated)];
    $http->leads = array_values($http->leads);
}

/** Et Adversus-opkald direkte i databasen (til UI-testene). */
function f004_insert(int $extId, bool $success, string $status, string $started = '2026-11-12 10:00:00'): int
{
    DB::exec('INSERT INTO calls (`source`, external_id, lead_id, campaign_id, is_success, direction, customer_phone, agent_name, agent_ext_id, '
        . "started_at, duration_sec, recording_url, transcript_status, created_at, updated_at) VALUES ('adversus', ?, ?, 31, ?, 'outbound', "
        . "'', 'Adversus-bruger 77', '77', ?, 95, '', ?, NOW(), NOW())",
        [(string) $extId, 7000 + $extId, $success ? 1 : 0, $started, $status]);
    return (int) DB::scalar('SELECT LAST_INSERT_ID()');
}

/** Alle tekstkolonner i calls for Adversus-rækker, samlet (til "ingen signeret URL"-tjek). */
function f004_dump(): string
{
    return (string) json_encode(DB::all("SELECT * FROM calls WHERE `source` = 'adversus'"));
}

/**
 * Falsk Adversus: anvender filters (JSON med $gt/$lt/$eq), sortProperty/sortDirection og page/pageSize på fixtures.
 * Tidspunkter sammenlignes som epoch. /calls/{id}/recording svarer 307 til $redirectHost; lydværten svarer audio/mpeg.
 */
final class FakeAdversus implements HttpTransport
{
    /** @var list<array<string, mixed>> */
    public array $recordings = [];
    /** @var list<array<string, mixed>> */
    public array $calls = [];
    /** @var list<array<string, mixed>> */
    public array $leads = [];
    /** @var list<array<string, mixed>> */
    public array $users = [];
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];
    /** Fra og med dette kaldnummer (1-baseret) svares 429 med Retry-After 3600 (Client stopper straks) */
    public ?int $fail429At = null;
    public string $redirectHost = F004_AUDIO_HOST;
    public bool $usersForbidden = false;
    /** @var list<int> opkald, hvis /recording svarer 404 */
    public array $noRecording = [];
    /** /leads svarer 500 med fri tekst (kundenavn) i message */
    public bool $leadsError = false;
    /** Kaldes ved hver hentning fra lydværten (fx for at flytte testens ur) */
    public ?Closure $onAudio = null;
    /** @var array<string, list<string>> filter-egenskaber pr. sti, der svarer 400 som hos Adversus (fx ['/v1/calls' => ['leadId']]) */
    public array $rejectFilters = [];
    /** Sekunder, som et tidsstempel i et filter flyttes, før det sammenlignes (H009: Adversus tolker filtertid anderledes) */
    public int $filterShift = 0;

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        if ($this->fail429At !== null && count($this->requests) >= $this->fail429At) {
            return new HttpResult(429, ['retry-after' => '3600'], '{"code":429,"message":"Too many requests"}');
        }
        $u = parse_url($url);
        $host = is_array($u) ? (string) ($u['host'] ?? '') : '';
        $path = is_array($u) ? (string) ($u['path'] ?? '') : '';
        parse_str(is_array($u) ? (string) ($u['query'] ?? '') : '', $q);
        if ($host === F004_AUDIO_HOST || $host === 'evil.example.com') {
            if ($this->onAudio !== null) {
                ($this->onAudio)();
            }
            if ($saveTo !== null) {
                file_put_contents($saveTo, 'ID3' . str_repeat("\x01\x02\x03\x04", 64));
            }
            return new HttpResult(200, ['content-type' => 'audio/mpeg'], '');
        }
        if ($host !== 'api.adversus.io') {
            return new HttpResult(404, [], '');
        }
        if (preg_match('#^/v1/calls/([0-9]+)/recording$#', $path, $m) === 1) {
            if (in_array((int) $m[1], $this->noRecording, true)) {
                return new HttpResult(404, [], '{"code":404,"message":"call or recording does not exist"}');
            }
            return new HttpResult(307, ['location' => 'https://' . $this->redirectHost . '/rec/' . $m[1] . '.mp3?' . F004_SIG], '');
        }
        $lists = ['/v1/recordings' => ['recordings', $this->recordings], '/v1/calls' => ['calls', $this->calls],
            '/v1/leads' => ['leads', $this->leads], '/v1/users' => ['users', $this->users]];
        if (!isset($lists[$path])) {
            return new HttpResult(404, [], '');
        }
        if ($path === '/v1/users' && $this->usersForbidden) {
            return new HttpResult(403, [], '{"code":403,"message":"Forbidden for Kunde Jensen"}');
        }
        if ($path === '/v1/leads' && $this->leadsError) {
            return new HttpResult(500, [], '{"code":500,"message":"Lead for Kunde Jensen kunne ikke læses"}');
        }
        [$key, $items] = $lists[$path];
        $filters = is_string($q['filters'] ?? null) ? json_decode($q['filters'], true) : [];
        foreach (array_keys(is_array($filters) ? $filters : []) as $field) {
            if (in_array($field, $this->rejectFilters[$path] ?? [], true)) {
                return new HttpResult(400, ['content-type' => 'application/json'],
                    (string) json_encode(['code' => 400, 'message' => 'Invalid filter property: ' . $field]));
            }
        }
        $items = array_values(array_filter($items, fn(array $it): bool => $this->matches($it, is_array($filters) ? $filters : [])));
        if (is_string($q['sortProperty'] ?? null)) {
            $prop = $q['sortProperty'];
            $dir = ($q['sortDirection'] ?? 'ASC') === 'DESC' ? -1 : 1;
            usort($items, fn(array $a, array $b): int => $dir * ($this->num($a[$prop] ?? null) <=> $this->num($b[$prop] ?? null)));
        }
        $size = (int) ($q['pageSize'] ?? 50);
        $page = max(1, (int) ($q['page'] ?? 1));
        $total = count($items);
        $data = [$key => array_slice($items, ($page - 1) * $size, $size),
            'meta' => ['pagination' => ['page' => $page, 'pageSize' => $size, 'pageCount' => max(1, (int) ceil($total / $size)), 'total' => $total]]];
        return new HttpResult(200, ['content-type' => 'application/json'], (string) json_encode($data));
    }

    /**
     * @param array<string, mixed> $it
     * @param array<mixed> $filters
     */
    private function matches(array $it, array $filters): bool
    {
        foreach ($filters as $field => $ops) {
            $v = $it[$field] ?? null;
            foreach (is_array($ops) ? $ops : [] as $op => $want) {
                $isTime = is_string($want) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $want) === 1;
                $w = $isTime ? $this->num($want) + $this->filterShift : $this->num($want);
                $ok = match ($op) {
                    '$eq'  => $isTime ? $this->num($v) === $w : $v === $want,
                    '$gt'  => $this->num($v) > $w,
                    '$lt'  => $this->num($v) < $w,
                    default => false,
                };
                if (!$ok) {
                    return false;
                }
            }
        }
        return true;
    }

    private function num(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        static $memo = [];
        if (!is_string($v)) {
            return 0.0;
        }
        if (!isset($memo[$v])) {
            $t = strtotime($v);
            $memo[$v] = $t === false ? 0.0 : (float) $t;
        }
        return $memo[$v];
    }

    /** @return list<string> URL'erne til en sti (fx /v1/recordings) */
    public function to(string $path): array
    {
        return array_values(array_map(static fn(array $r): string => $r['url'],
            array_filter($this->requests, static fn(array $r): bool => str_contains($r['url'], 'api.adversus.io' . $path))));
    }
}
