<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F006-testene (ikke selv en test: run.php kører kun test_*.php).
 * OAuth-serveren køres direkte: Endpoints (metadata, register, token) og App (/oauth/authorize med session) får en
 * Request og giver en Response; /mcp køres med F003Mcp. Alle flag, F006 kræver, tvinges on. Koder og tokens skabes i
 * testen (ingen literaler, der ligner hemmeligheder).
 */

require_once __DIR__ . '/f003_lib.php';

use Whisper\Csrf;
use Whisper\DB;
use Whisper\Flags;
use Whisper\OAuth\Endpoints;
use Whisper\OAuth\OAuth;
use Whisper\Request;
use Whisper\Response;
use Whisper\Ui;

const F006_SITE = 'https://' . F003_HOST;
const F006_RESOURCE = F006_SITE . '/mcp';
const F006_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

final class F006Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F006-$ak $msg\n";
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

/** Ren database og alle flag, OAuth kræver. Returnerer var-mappen (adgangsloggen ligger der). */
function f006_setup(bool $oauth = true): string
{
    $dir = f003_setup();
    Flags::override(Ui::FLAG, true);
    Flags::override(OAuth::FLAG, $oauth);
    DB::exec('DELETE FROM oauth_rate');
    DB::exec('DELETE FROM oauth_ip_rate');
    DB::exec('DELETE FROM oauth_tokens');
    DB::exec('DELETE FROM oauth_codes');
    DB::exec('DELETE FROM oauth_grants');
    DB::exec('DELETE FROM oauth_clients');
    return $dir;
}

/**
 * Et af maskin-endpointene.
 *
 * @param array<mixed> $post
 * @param array<string, string> $headers
 */
function f006_ep(string $method, string $path, array $post = [], string $body = '', array $headers = [], int $now = F003_NOW,
    string $ip = '160.79.104.10'): Response
{
    return (new Endpoints(static fn(): int => $now))->handle(new Request($method, $path, $post, $ip, [], $headers + ['host' => F003_HOST], $body));
}

/** @return array<string, mixed> */
function f006_json(Response $r): array
{
    $d = json_decode($r->body, true);
    return is_array($d) ? $d : [];
}

/**
 * Dynamisk registrering som Claude. Returnerer svaret.
 *
 * @param array<string, mixed> $meta
 */
function f006_register_raw(array $meta, string $ip = '160.79.104.10'): Response
{
    return f006_ep('POST', '/oauth/register', [], (string) json_encode($meta), ['content-type' => 'application/json'], F003_NOW, $ip);
}

function f006_register(string $redirect = F006_CALLBACK, string $name = 'Claude'): string
{
    $j = f006_json(f006_register_raw(['client_name' => $name, 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]));
    return is_string($j['client_id'] ?? null) ? $j['client_id'] : '';
}

/** @return array{0: string, 1: string} verifier og S256-challenge */
function f006_pkce(): array
{
    $verifier = OAuth::b64url(random_bytes(48));
    return [$verifier, OAuth::b64url(hash('sha256', $verifier, true))];
}

/**
 * @param array<string, string> $over  overskriv eller fjern (værdi '') parametre
 * @return array<string, string>
 */
function f006_query(string $clientId, string $challenge, array $over = []): array
{
    $q = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => F006_CALLBACK, 'code_challenge' => $challenge,
        'code_challenge_method' => 'S256', 'state' => 'st-' . bin2hex(random_bytes(6)), 'resource' => F006_RESOURCE];
    foreach ($over as $k => $v) {
        if ($v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return $q;
}

/** @param array<mixed> $query */
function f006_authorize(F001Client $c, array $query): Response
{
    return $c->app->handle(new Request('GET', '/oauth/authorize', [], $c->ip, $query, ['host' => F003_HOST]), $c->session);
}

/** @param array<string, string> $data */
function f006_decide(F001Client $c, string $rid, string $decision, bool $csrf = true): Response
{
    $data = ['rid' => $rid, 'beslutning' => $decision];
    if ($csrf) {
        $data[Csrf::FIELD] = Csrf::token($c->session);
    }
    return $c->app->handle(new Request('POST', '/oauth/authorize', $data, $c->ip, [], ['host' => F003_HOST]), $c->session);
}

function f006_rid(Response $r): string
{
    return preg_match('/name="rid" value="([0-9a-f]{32})"/', $r->body, $m) === 1 ? $m[1] : '';
}

/** @return array<string, string> query-parametrene i en redirect */
function f006_location(Response $r): array
{
    $q = parse_url((string) $r->header('Location'), PHP_URL_QUERY);
    parse_str(is_string($q) ? $q : '', $out);
    $res = [];
    foreach ($out as $k => $v) {
        if (is_string($v)) {
            $res[(string) $k] = $v;
        }
    }
    return $res;
}

/**
 * Hele vejen til en kode for en indlogget klient: authorize → godkendelse → redirect.
 *
 * @return array{code: string, verifier: string, state: string, response: Response}
 */
function f006_code(F001Client $c, string $clientId): array
{
    [$verifier, $challenge] = f006_pkce();
    $q = f006_query($clientId, $challenge);
    $page = f006_authorize($c, $q);
    $r = f006_decide($c, f006_rid($page), 'godkend');
    $loc = f006_location($r);
    return ['code' => $loc['code'] ?? '', 'verifier' => $verifier, 'state' => $q['state'], 'response' => $r];
}

/**
 * @param array<string, string> $over
 */
function f006_token(string $clientId, string $code, string $verifier, array $over = [], int $now = F003_NOW): Response
{
    $post = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => F006_CALLBACK, 'client_id' => $clientId,
        'code_verifier' => $verifier, 'resource' => F006_RESOURCE];
    return f006_ep('POST', '/oauth/token', array_merge($post, $over), '', ['content-type' => 'application/x-www-form-urlencoded'], $now);
}

function f006_refresh(string $clientId, string $refresh, int $now = F003_NOW): Response
{
    return f006_ep('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId,
        'resource' => F006_RESOURCE], '', ['content-type' => 'application/x-www-form-urlencoded'], $now);
}

/**
 * Indlogget bruger, registreret klient og et udstedt tokenpar.
 *
 * @return array{c: F001Client, uid: int, client: string, access: string, refresh: string}
 */
function f006_connected(): array
{
    [$c, $uid] = f003_login();
    $client = f006_register();
    $flow = f006_code($c, $client);
    $t = f006_json(f006_token($client, $flow['code'], $flow['verifier']));
    return ['c' => $c, 'uid' => $uid, 'client' => $client, 'access' => (string) ($t['access_token'] ?? ''),
        'refresh' => (string) ($t['refresh_token'] ?? '')];
}

/** tools/list med et token: 200 og værktøjerne? */
function f006_mcp_ok(string $token): bool
{
    $r = (new F003Mcp($token))->rpc('tools/list');
    return $r->status === 200 && str_contains($r->body, 'search_calls');
}
