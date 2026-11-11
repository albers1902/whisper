<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F002-testene (ikke selv en test: run.php kører kun test_*.php).
 * Zendesk mockes med FakeTransport (ingen netværk: run.php peger alle proxies på en lukket port), motoren med
 * FakeEngine (CI-brugeren må ikke hente modeller). var/ er en midlertidig mappe pr. test. Hemmeligheder og tokens
 * genereres i testen (ingen literaler, der ligner nøgler).
 */

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/f001_lib.php';

use Whisper\App;
use Whisper\CallPages;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Paths;
use Whisper\Request;
use Whisper\Response;
use Whisper\Transcription\Engine;
use Whisper\Transcription\EngineError;
use Whisper\Zendesk\Client;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\HttpTransport;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

const F002_SUB = 'testsub';
const F002_BASE = 'https://testsub.zendesk.com';
const F002_SINCE = 1790000000;   // 2026-09-22 UTC
const F002_NOW = 1800000000;

final class F002Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F002-$ak $msg\n";
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

/**
 * Falsk HTTP: svar pr. metode + URL (præcis, eller præfiks med * til sidst). Flere svar til samme nøgle bruges i
 * rækkefølge, og det sidste gentages. Et svar kan være en ZendeskError (netværksfejl). Ved download skrives body i filen.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var array<string, list<HttpResult|ZendeskError>> */
    private array $routes = [];

    public function on(string $method, string $url, HttpResult|ZendeskError ...$responses): void
    {
        $this->routes[$method . ' ' . $url] = array_values($responses);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $key = null;
        if (isset($this->routes[$method . ' ' . $url])) {
            $key = $method . ' ' . $url;
        } else {
            foreach (array_keys($this->routes) as $k) {
                if (str_ends_with($k, '*') && str_starts_with($method . ' ' . $url, substr($k, 0, -1))) {
                    $key = $k;
                    break;
                }
            }
        }
        if ($key === null) {
            return new HttpResult(404, [], '{"error":"not found"}');
        }
        $r = count($this->routes[$key]) > 1 ? array_shift($this->routes[$key]) : $this->routes[$key][0];
        if ($r instanceof ZendeskError) {
            throw $r;
        }
        if ($saveTo !== null) {
            file_put_contents($saveTo, $r->body);
            return new HttpResult($r->status, $r->headers, '');
        }
        return $r;
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function to(string $prefix): array
    {
        return array_values(array_filter($this->requests, static fn(array $q): bool => str_starts_with($q['url'], $prefix)));
    }
}

final class FakeEngine implements Engine
{
    public int $calls = 0;
    /** @var array<mixed>|EngineError */
    public array|EngineError $result;

    public function __construct()
    {
        $this->result = ['text' => 'Hej og velkommen. Det handler om strøm og måler.', 'model' => 'turbo', 'language' => 'da',
            'segments' => [['start' => 0.0, 'end' => 4.2, 'text' => 'Hej og velkommen.'],
                ['start' => 65.5, 'end' => 70.0, 'text' => 'Det handler om strøm og måler.']]];
    }

    public function available(): bool
    {
        return true;
    }

    public function transcribe(string $audioFile): array
    {
        $this->calls++;
        if ($this->result instanceof EngineError) {
            throw $this->result;
        }
        return $this->result;
    }
}

function f002_setup(): string
{
    Flags::override(App::FLAG, true);
    Flags::override(CallPages::FLAG, true);
    DB::exec('DELETE FROM transcripts');
    DB::exec('DELETE FROM calls');
    DB::exec('DELETE FROM sync_state');
    $dir = sys_get_temp_dir() . '/whisper-f002-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    Paths::setVarDir($dir);
    return $dir;
}

/** @param list<string> $hosts */
function f002_cfg(array $hosts = [], string $scope = 'read'): ZendeskConfig
{
    return new ZendeskConfig(F002_SUB, 'whisper-test-client', 'cs-' . bin2hex(random_bytes(12)), $scope, F002_SINCE, $hosts);
}

/** @param list<int> $sleeps */
function f002_client(ZendeskConfig $cfg, FakeTransport $http, ?string $tokenFile = null, array &$sleeps = [], int $now = F002_NOW): Client
{
    return new Client($cfg, $http, $tokenFile, static fn(): int => $now, static function (int $s) use (&$sleeps): void {
        $sleeps[] = $s;
    });
}

/** Et nyt tilfældigt token pr. kald (ingen literal i koden). */
function f002_token_response(int $expiresIn = 3600): HttpResult
{
    return new HttpResult(200, [], (string) json_encode(['access_token' => 'at-' . bin2hex(random_bytes(16)),
        'token_type' => 'bearer', 'scope' => 'read', 'expires_in' => $expiresIn]));
}

/** @param array<mixed> $data */
function f002_json(array $data, int $status = 200): HttpResult
{
    return new HttpResult($status, ['content-type' => 'application/json'], (string) json_encode($data));
}

/** @return array<string, mixed> et opkald, som Talk Incremental Export leverer det */
function f002_call(int $id, int $ticket, string $direction = 'inbound', int $recTime = 30, int $agent = 7,
    string $created = '2026-10-05T10:00:00Z', int $duration = 120): array
{
    return ['id' => $id, 'ticket_id' => $ticket, 'direction' => $direction, 'recording_time' => $recTime, 'agent_id' => $agent,
        'created_at' => $created, 'updated_at' => $created, 'duration' => $duration, 'phone_number' => '+4570000000'];
}

/** @return array<string, mixed> en VoiceComment */
function f002_voice(?int $callId, string $url, string $from, string $to, string $started, int $duration): array
{
    $data = ['from' => $from, 'to' => $to, 'recording_url' => $url, 'started_at' => $started, 'call_duration' => $duration];
    if ($callId !== null) {
        $data['call_id'] = $callId;
    }
    return ['id' => random_int(1, 999999), 'type' => 'VoiceComment', 'data' => $data, 'public' => false];
}

function f002_export_url(int $start): string
{
    return F002_BASE . '/api/v2/channels/voice/stats/incremental/calls.json?start_time=' . $start;
}

function f002_comments_url(int $ticket): string
{
    return F002_BASE . '/api/v2/tickets/' . $ticket . '/comments.json?page%5Bsize%5D=100';
}

/**
 * Indsætter et opkald direkte (til UI- og transcription-testene).
 *
 * @param array<string, mixed> $o
 */
function f002_insert(array $o = []): int
{
    static $n = 0;
    $n++;
    $row = $o + ['external_id' => (string) (900000 + $n + random_int(0, 99999) * 10), 'ticket_id' => 5000 + $n, 'direction' => 'inbound',
        'customer_phone' => '+45 1234 5678', 'agent_name' => 'Agent A', 'started_at' => '2026-10-05 10:00:00', 'duration_sec' => 125,
        'recording_url' => F002_BASE . '/api/v2/recordings/' . $n . '.mp3', 'audio_path' => null, 'audio_status' => 'venter',
        'transcript_status' => 'ventende', 'transcript_attempts' => 0, 'audio_attempts' => 0, 'claimed_at' => null];
    DB::exec('INSERT INTO calls (`source`, external_id, ticket_id, direction, customer_phone, agent_name, started_at, duration_sec, '
        . 'recording_url, audio_path, audio_status, audio_attempts, transcript_status, transcript_attempts, claimed_at, created_at, updated_at) '
        . "VALUES ('zendesk', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [$row['external_id'], $row['ticket_id'], $row['direction'], $row['customer_phone'], $row['agent_name'], $row['started_at'],
            $row['duration_sec'], $row['recording_url'], $row['audio_path'], $row['audio_status'], $row['audio_attempts'],
            $row['transcript_status'], $row['transcript_attempts'], $row['claimed_at']]);
    return (int) DB::scalar('SELECT LAST_INSERT_ID()');
}

/** Lægger en lydfil i var/recordings og markerer opkaldet som hentet. Returnerer den fulde sti. */
function f002_audio(int $id, string $bytes = '', string $ext = 'mp3'): string
{
    $rel = 'recordings/2026/10/' . $id . '.' . $ext;
    $file = Paths::dir('recordings/2026/10') . '/' . $id . '.' . $ext;
    file_put_contents($file, $bytes !== '' ? $bytes : 'ID3' . str_repeat("\x01\x02\x03\x04", 256));
    chmod($file, 0600);
    DB::exec("UPDATE calls SET audio_status = 'hentet', audio_path = ? WHERE id = ?", [$rel, $id]);
    return $file;
}

/** @param list<array{start: float, end: float, text: string}> $segments */
function f002_transcript(int $id, string $text, array $segments = []): void
{
    $segments = $segments !== [] ? $segments : [['start' => 0.0, 'end' => 3.0, 'text' => $text]];
    DB::exec('INSERT INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [$id, $text, (string) json_encode($segments), 'turbo', 'da']);
    DB::exec("UPDATE calls SET transcript_status = 'faerdig' WHERE id = ?", [$id]);
}

/** En indlogget bruger (F001) til siderne. */
function f002_login(): F001Client
{
    DB::exec('DELETE FROM login_attempts');
    DB::exec('DELETE FROM users');
    f001_user('bruger@f002.test', 'user');
    $c = new F001Client();
    $c->login('bruger@f002.test', F001_PW);
    return $c;
}

/** @param array<mixed> $query  @param array<string, string> $headers */
function f002_get(F001Client $c, string $path, array $query = [], array $headers = []): Response
{
    return $c->app->handle(new Request('GET', $path, [], $c->ip, $query, $headers), $c->session);
}

/**
 * Filerne i en zip (læst uden ZipArchive): navn => indhold, og om CRC32 passer.
 *
 * @return array<string, array{data: string, crc_ok: bool}>
 */
function f002_unzip(string $zip): array
{
    $out = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $h = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($zip, $pos + 4, 26));
        if ($h === false) {
            break;
        }
        $name = substr($zip, $pos + 30, $h['nlen']);
        $data = substr($zip, $pos + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
        $out[$name] = ['data' => $data, 'crc_ok' => (int) hexdec(hash('crc32b', $data)) === $h['crc']];
        $pos += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
    }
    return $out;
}
