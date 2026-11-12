<?php
declare(strict_types=1);

namespace Whisper\Mcp;

use Closure;
use stdClass;
use Throwable;
use Whisper\AccessLog;
use Whisper\App;
use Whisper\CallPages;
use Whisper\Flags;
use Whisper\OAuth\Grants;
use Whisper\OAuth\Issuer;
use Whisper\OAuth\OAuth;
use Whisper\Request;
use Whisper\Response;

/**
 * Remote MCP-server på POST /mcp (F003): Streamable HTTP efter MCP 2025-11-25, stateless og uden SSE — hver request
 * står alene og svarer med ét JSON-objekt, så den passer til PHP-FPM. Ingen session, ingen cookie og ingen CSRF:
 * adgang kun med en personlig bearer-nøgle (Tokens). index.php sender /mcp hertil FØR App, så App's
 * session-, login- og CSRF-kæde er uændret for alle andre ruter.
 *
 * Rækkefølge: flag → metode → Origin → nøgle → rate limit → protokolversion → body → JSON-RPC.
 *
 * Med F006_oauth tændt (DECISIONS #47/#48) gælder KUN OAuth access tokens: F003-nøgler afvises som manglende, 401
 * peger på protected resource metadata, og Claude's egne origins (claude.ai, claude.com) accepteres.
 */
final class Server
{
    public const FLAG = 'F003_mcp';
    public const PATH = '/mcp';
    public const MAX_BODY = 65536;
    public const LATEST = '2025-11-25';
    /** Legacy-revisionerne med initialize. 2026-07-28 (uden initialize) får 400, så en dual-era klient falder tilbage. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** @var Closure(): int */
    private Closure $clock;

    /** @param (Closure(): int)|null $clock tests styrer tiden (rate limit) */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function isPath(string $path): bool
    {
        return rtrim($path, '/') === self::PATH;
    }

    /** /mcp findes kun, når både F003 og F002 (dataene) er tændt. F001 tjekkes allerede i index.php og App. */
    public static function enabled(): bool
    {
        return Flags::enabled(App::FLAG) && Flags::enabled(CallPages::FLAG) && Flags::enabled(self::FLAG);
    }

    /**
     * Sitets egen oprindelse, https:// + Host. Apache's navnebaserede vhost har allerede bundet Host til sitet, så
     * en anden værts navn (DNS rebinding) når aldrig hertil. Ugyldig Host: ''.
     */
    public static function siteOrigin(Request $req): string
    {
        $host = strtolower($req->header('host'));
        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?$/', $host) === 1 ? 'https://' . $host : '';
    }

    /**
     * Hele kæden i én catch: en intern fejl (fx databasen nede under authenticate) giver JSON-RPC -32603 og aldrig
     * index.php's HTML-fejlside. Kun klasse og sted logges, for en PDO-besked kan indeholde SQL med værdier.
     */
    public function handle(Request $req): Response
    {
        try {
            return $this->dispatch($req);
        } catch (Throwable $e) {
            error_log('whisper mcp: ' . get_class($e) . ' i ' . $e->getFile() . ':' . $e->getLine());
            return self::rpcError(500, self::requestId($req->body), -32603, 'Intern fejl.');
        }
    }

    /** Sitets egen origin, og med OAuth tændt også Claude's (DNS rebinding er stadig udelukket: alt kræver bearer-token). */
    private static function originOk(string $site, string $origin, bool $oauth): bool
    {
        return hash_equals($site, $origin) || ($oauth && in_array($origin, ['https://claude.ai', 'https://claude.com'], true));
    }

    /**
     * Brugeren bag Authorization-headeren: F003-nøgle med OAuth slukket; kun et OAuth access token til netop denne
     * ressource med OAuth tændt (en F003-nøgle behandles da som ingen nøgle).
     *
     * @return array{rate_id: int, oauth: bool, user: array<string, mixed>}|null
     */
    private function authenticate(string $header, string $site, bool $oauth, int $now): ?array
    {
        if (!$oauth) {
            $a = Tokens::authenticate($header);
            return $a === null ? null : ['rate_id' => $a['token_id'], 'oauth' => false, 'user' => $a['user']];
        }
        $a = $site !== '' ? Issuer::authenticate($header, OAuth::resource($site), $now) : null;
        return $a === null ? null : ['rate_id' => $a['grant_id'], 'oauth' => true, 'user' => $a['user']];
    }

    /** id fra en request, der kan læses som et JSON-RPC-objekt; ellers null. */
    private static function requestId(string $body): int|string|null
    {
        $msg = strlen($body) <= self::MAX_BODY ? json_decode($body, true, 64) : null;
        $id = is_array($msg) && !array_is_list($msg) ? ($msg['id'] ?? null) : null;
        return is_string($id) || is_int($id) ? $id : null;
    }

    private function dispatch(Request $req): Response
    {
        if (!self::enabled()) {
            return App::notFound();
        }
        if ($req->method !== 'POST') {
            // Ingen SSE-strøm (GET) og ingen sessioner at afslutte (DELETE): MCP 2025-11-25 tillader 405 for begge
            return self::plain(405, "Metoden er ikke tilladt. Brug POST.\n", ['Allow' => 'POST']);
        }
        $origin = $req->header('origin');
        $site = self::siteOrigin($req);
        $oauth = OAuth::enabled();
        if ($origin !== '' && ($site === '' || !self::originOk($site, strtolower(rtrim($origin, '/')), $oauth))) {
            // MCP: en ugyldig Origin SKAL give 403 (DNS rebinding). Klienter uden Origin (mcp-remote) er i orden.
            return self::rpcError(403, null, -32600, 'Forbudt oprindelse.');
        }

        $now = ($this->clock)();
        // F006: ressourcen og metadata-adressen følger [app] public_url, når den er sat (security S4)
        $public = $oauth ? OAuth::site($req) : $site;
        $auth = $this->authenticate($req->header('authorization'), $public, $oauth, $now);
        if ($auth === null) {
            // Ingen data og ingen forskel på manglende, forkert, tilbagekaldt nøgle eller deaktiveret bruger
            if ($oauth && $public !== '') {
                // RFC 9728 §5.1: herfra finder Claude metadata og starter login (claude.com/docs/connectors/building/authentication)
                return self::plain(401, "Forbind Whisper som connector i Claude, og log ind.\n", ['WWW-Authenticate' => 'Bearer realm="whisper", '
                    . 'resource_metadata="' . $public . OAuth::PRM_PATH . self::PATH . '"']);
            }
            return self::plain(401, "Mangler eller ugyldig nøgle. Opret en ny under Konto i Whisper.\n",
                ['WWW-Authenticate' => 'Bearer realm="whisper"']);
        }
        $retry = RateLimit::hit($auth['rate_id'], $now, $auth['oauth']);
        if ($retry > 0) {
            return self::plain(429, 'For mange forespørgsler: højst ' . RateLimit::LIMIT . " i minuttet pr. nøgle. Prøv igen om lidt.\n",
                ['Retry-After' => (string) $retry]);
        }
        if ($auth['oauth']) {
            Grants::touch($auth['rate_id'], $now);
        } else {
            Tokens::touch($auth['rate_id'], $now);
        }

        $version = trim($req->header('mcp-protocol-version'));
        if ($version !== '' && !in_array($version, self::VERSIONS, true)) {
            // Bevidst IKKE en JSON-RPC-fejl: en klient, der kan både ny og gammel MCP, læser en 400 uden "moderne"
            // fejlkode som "gammel server" og skifter til initialize (MCP 2026-07-28, Backward Compatibility)
            return self::plain(400, 'Protokolversionen understøttes ikke. Understøttede: ' . implode(', ', self::VERSIONS) . ".\n");
        }
        if (strlen($req->body) > self::MAX_BODY) {
            return self::plain(413, "Forespørgslen er for stor.\n");
        }
        $type = strtolower(trim(explode(';', $req->header('content-type'))[0]));
        if ($type !== 'application/json') {
            return self::plain(415, "Content-Type skal være application/json.\n");
        }

        $msg = json_decode($req->body, true, 64);
        if (!is_array($msg)) {
            return self::rpcError(400, null, -32700, 'Ugyldig JSON.');
        }
        if (array_is_list($msg)) {
            // json_decode(..., true) gør {} (og {"0": …}) til en liste: kun en body, der ER en JSON-liste, er en batch
            return self::rpcError(400, null, -32600, str_starts_with(ltrim($req->body), '[')
                ? 'Batches understøttes ikke: send én JSON-RPC-besked pr. forespørgsel.' : 'Ugyldig JSON-RPC-besked.');
        }
        if (($msg['jsonrpc'] ?? null) !== '2.0') {
            return self::rpcError(400, null, -32600, 'Ugyldig JSON-RPC-besked.');
        }
        if (!array_key_exists('method', $msg)) {
            // Et svar fra klienten (vi sender aldrig requests til den): accepteres og ignoreres
            return new Response(202, '', self::headers([]));
        }
        if (!array_key_exists('id', $msg)) {
            return new Response(202, '', self::headers([]));   // notification, fx notifications/initialized
        }
        $id = $msg['id'];
        if (!is_string($id) && !is_int($id)) {
            return self::rpcError(400, null, -32600, 'Ugyldigt id.');
        }
        $method = $msg['method'];
        $params = $msg['params'] ?? [];
        if (!is_string($method) || !is_array($params)) {
            return self::rpcError(400, $id, -32600, 'Ugyldig JSON-RPC-besked.');
        }

        return match ($method) {
            'initialize' => self::result($id, self::initialize($params)),
            'ping'       => self::result($id, new stdClass()),
            'tools/list' => self::result($id, ['tools' => Tools::definitions()]),
            'tools/call' => $this->toolsCall($id, $params, $auth['user'], $site, $now),
            default      => self::rpcError(200, $id, -32601, 'Metoden findes ikke.'),
        };
    }

    /**
     * @param array<mixed> $params
     * @return array<string, mixed>
     */
    private static function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;
        return [
            'protocolVersion' => is_string($asked) && in_array($asked, self::VERSIONS, true) ? $asked : self::LATEST,
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'whisper', 'title' => 'Whisper', 'version' => App::VERSION],
            'instructions'    => 'Whisper har opkald fra Zendesk Talk med transcripts på dansk. Brug search_calls til at finde '
                . 'opkald, get_transcript til at læse ét, list_recent_calls til de nyeste og call_stats til antal. Datoer er '
                . 'ÅÅÅÅ-MM-DD i dansk tid. Alt er kun til læsning. ' . Tools::RULE,
        ];
    }

    /**
     * @param array<mixed> $params
     * @param array<string, mixed> $user
     */
    private function toolsCall(int|string $id, array $params, array $user, string $site, int $now): Response
    {
        $name = $params['name'] ?? null;
        $uid = (int) $user['id'];
        if (!is_string($name) || !in_array($name, Tools::names(), true)) {
            // Revisionssporet får forsøget med, men aldrig klientens navn (det er frit input)
            AccessLog::write($uid, 'mcp_ukendt', ['antal' => 0]);
            return self::rpcError(200, $id, -32602, 'Ukendt værktøj.');
        }
        $args = $params['arguments'] ?? [];
        $action = 'mcp_' . $name;
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            AccessLog::write($uid, $action, ['antal' => 0]);
            return self::result($id, self::toolError('Argumenterne skal være et objekt.'));
        }
        try {
            $r = Tools::call($name, $args, $site, $now);
        } catch (ToolError $e) {
            $fields = $name === 'get_transcript' && is_int($args['call_id'] ?? null) ? ['opkald' => $args['call_id']] : [];
            AccessLog::write($uid, $action, $fields + ['antal' => 0]);
            return self::result($id, self::toolError($e->getMessage()));
        } catch (Throwable $e) {
            // Kun klasse og sted: en PDO-besked kan indeholde SQL med værdier
            error_log('whisper mcp: ' . get_class($e) . ' i ' . $e->getFile() . ':' . $e->getLine());
            AccessLog::write($uid, $action, ['antal' => 0]);
            return self::result($id, self::toolError('Der opstod en fejl i Whisper. Prøv igen om lidt.'));
        }
        // Kun bruger, værktøj, opkalds-id og antal — aldrig argumenter eller tekst (AK10)
        AccessLog::write($uid, $action, ($r['call'] > 0 ? ['opkald' => $r['call']] : []) + ['antal' => $r['count']]);
        $result = ['content' => [['type' => 'text', 'text' => $r['text']]], 'isError' => false];
        if ($r['structured'] !== null) {
            $result['structuredContent'] = $r['structured'];
        }
        return self::result($id, $result);
    }

    /** @return array<string, mixed> */
    private static function toolError(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }

    /** @param array<string, mixed>|stdClass $result */
    private static function result(int|string $id, array|stdClass $result): Response
    {
        return self::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private static function rpcError(int $status, int|string|null $id, int $code, string $message): Response
    {
        return self::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new Response($status, $body, self::headers(['Content-Type' => 'application/json; charset=utf-8']));
    }

    /** @param array<string, string> $extra */
    private static function plain(int $status, string $text, array $extra = []): Response
    {
        return new Response($status, $text, self::headers($extra + ['Content-Type' => 'text/plain; charset=utf-8']));
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private static function headers(array $extra): array
    {
        return $extra + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
