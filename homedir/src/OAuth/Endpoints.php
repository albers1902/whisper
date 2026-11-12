<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Throwable;
use Whisper\AccessLog;
use Whisper\Mcp\Server;
use Whisper\Request;
use Whisper\Response;

/**
 * De maskin-endpoints, Claude's connector kalder uden browser og uden session (F006): protected resource metadata
 * (RFC 9728), authorization server metadata (RFC 8414), dynamisk registrering (RFC 7591) og token-endpointet.
 * index.php sender hertil før App, når OAuth::enabled(); ellers findes stierne ikke (App's 404).
 */
final class Endpoints
{
    public const REGISTER = '/oauth/register';
    public const TOKEN = '/oauth/token';
    public const AUTHORIZE = '/oauth/authorize';
    public const MAX_BODY = 8192;
    private const PURGE_ONE_IN = 50;

    /** Er stien et af endpointene her (ikke /oauth/authorize, der kræver session og går gennem App)? */
    public static function isPath(string $path): bool
    {
        return in_array(rtrim($path, '/'), [OAuth::PRM_PATH, OAuth::PRM_PATH . Server::PATH, OAuth::AS_PATH, self::REGISTER, self::TOKEN], true);
    }

    public function __construct(private ?\Closure $clock = null)
    {
    }

    public function handle(Request $req): Response
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        try {
            return $this->dispatch($req, $now);
        } catch (Throwable $e) {
            // Kun klasse og sted: en PDO-besked kan indeholde SQL med værdier
            error_log('whisper oauth: ' . get_class($e) . ' i ' . $e->getFile() . ':' . $e->getLine());
            return self::json(500, ['error' => 'server_error']);
        }
    }

    private function dispatch(Request $req, int $now): Response
    {
        $path = rtrim($req->path, '/');
        $site = Server::siteOrigin($req);
        if ($site === '') {
            return self::json(400, ['error' => 'invalid_request', 'error_description' => 'Ugyldig Host.']);
        }
        $method = $req->method === 'HEAD' ? 'GET' : $req->method;
        if ($path === OAuth::PRM_PATH || $path === OAuth::PRM_PATH . Server::PATH || $path === OAuth::AS_PATH) {
            if ($method !== 'GET') {
                return self::json(405, ['error' => 'invalid_request'], ['Allow' => 'GET, HEAD']);
            }
            // Offentlige dokumenter: må læses af browserbaserede klienter (fx MCP Inspector)
            return self::json(200, $path === OAuth::AS_PATH ? self::asMetadata($site) : self::prMetadata($site),
                ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=3600']);
        }
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'invalid_request'], ['Allow' => 'POST']);
        }
        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            Clients::purge($now);
        }
        return $path === self::REGISTER ? $this->register($req, $now) : $this->token($req, $site, $now);
    }

    /** @return array<string, mixed> */
    public static function prMetadata(string $site): array
    {
        return [
            'resource'                 => OAuth::resource($site),
            'authorization_servers'    => [$site],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Whisper',
        ];
    }

    /** @return array<string, mixed> */
    public static function asMetadata(string $site): array
    {
        // Ingen client_id_metadata_document_supported (CIMD kræver, at serveren henter fremmede URL'er) og ingen
        // scopes_supported: der er kun læseadgang
        return [
            'issuer'                                         => $site,
            'authorization_endpoint'                         => $site . self::AUTHORIZE,
            'token_endpoint'                                 => $site . self::TOKEN,
            'registration_endpoint'                          => $site . self::REGISTER,
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'token_endpoint_auth_methods_supported'          => ['none'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    private function register(Request $req, int $now): Response
    {
        $type = strtolower(trim(explode(';', $req->header('content-type'))[0]));
        if ($type !== 'application/json') {
            return self::json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Content-Type skal være application/json.']);
        }
        if (strlen($req->body) > self::MAX_BODY) {
            return self::json(413, ['error' => 'invalid_client_metadata', 'error_description' => 'For stor.']);
        }
        $meta = json_decode($req->body, true, 16);
        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            return self::json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Ugyldig JSON.']);
        }
        $v = Clients::validate($meta);
        if ($v['ok'] === false) {
            return self::json(400, ['error' => $v['error'], 'error_description' => $v['description']]);
        }
        if (Clients::limited($req->ip, $now)) {
            return self::json(429, ['error' => 'invalid_request', 'error_description' => 'For mange registreringer. Prøv igen senere.'],
                ['Retry-After' => '3600']);
        }
        $c = Clients::create($v['name'], $v['redirect_uris'], $req->ip, $now);
        AccessLog::write(0, 'oauth_registrer', ['klient' => $c['id']]);
        return self::json(201, [
            'client_id'                  => $c['client_id'],
            'client_id_issued_at'        => $now,
            'client_name'                => $v['name'],
            'redirect_uris'              => $v['redirect_uris'],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    private function token(Request $req, string $site, int $now): Response
    {
        $grantType = $req->str('grant_type');
        if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
            return self::json(400, ['error' => $grantType === '' ? 'invalid_request' : 'unsupported_grant_type']);
        }
        $client = Clients::find($req->str('client_id'));
        if ($client === null) {
            return self::json(401, ['error' => 'invalid_client'], ['WWW-Authenticate' => 'Basic realm="whisper"']);
        }
        $resource = $req->str('resource');
        if ($resource !== '' && OAuth::canonicalResource($resource) !== OAuth::resource($site)) {
            return self::json(400, ['error' => 'invalid_target']);
        }
        $r = $grantType === 'authorization_code'
            ? Issuer::exchange($client, $req->str('code'), $req->str('redirect_uri'), $req->str('code_verifier'), $resource, $now)
            : Issuer::refresh($client, $req->str('refresh_token'), $resource, $now);
        if ($r['ok'] === false) {
            if ($r['grant'] < 0) {
                // Genbrug: kæden er tilbagekaldt. Bruger-id kendes ikke uden et ekstra opslag; bevillingen er nok til sporet
                AccessLog::write(0, 'oauth_genbrug', ['bevilling' => -$r['grant']]);
            }
            return self::json(400, ['error' => $r['error']]);
        }
        Clients::touch($client['id'], $now);
        AccessLog::write($r['user'], $grantType === 'authorization_code' ? 'oauth_token' : 'oauth_refresh', ['bevilling' => $r['grant']]);
        return self::json(200, $r['tokens']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $extra
     */
    private static function json(int $status, array $data, array $extra = []): Response
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new Response($status, $body, $extra + ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }
}
