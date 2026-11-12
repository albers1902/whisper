<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Whisper\Config;
use Whisper\Flags;
use Whisper\Mcp\Server;
use Whisper\Ui;

/**
 * Fælles regler for OAuth-serveren (F006): flag, issuer og ressource, redirect-allowlisten og rensning af klientnavne.
 * Whisper er både authorization server og resource server på samme vært (MCP Authorization 2025-11-25): issuer er
 * `https://<vært>`, og den eneste ressource er `https://<vært>/mcp`.
 */
final class OAuth
{
    public const FLAG = 'F006_oauth';
    /** Claude's callback-værter (claude.com/docs/connectors/building/authentication). Kan overskrives i config.ini [oauth]. */
    public const DEFAULT_HOSTS = 'claude.ai, claude.com';
    public const CODE_SECONDS = 60;
    public const ACCESS_SECONDS = 3600;
    public const REFRESH_SECONDS = 30 * 86400;
    public const PRM_PATH = '/.well-known/oauth-protected-resource';
    public const AS_PATH = '/.well-known/oauth-authorization-server';

    /** OAuth kræver /mcp (F001, F002, F003) og det nye UI (F005), fordi godkendelsessiden kun findes dér. */
    public static function enabled(): bool
    {
        return Server::enabled() && Flags::enabled(Ui::FLAG) && Flags::enabled(self::FLAG);
    }

    /** @return list<string> tilladte redirect-værter, små bogstaver */
    public static function redirectHosts(): array
    {
        $raw = Config::string('oauth', 'redirect_hosts', self::DEFAULT_HOSTS);
        $hosts = [];
        foreach (explode(',', strtolower($raw)) as $h) {
            $h = trim($h);
            if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $h) === 1) {
                $hosts[] = $h;
            }
        }
        return $hosts;
    }

    public static function resource(string $site): string
    {
        return $site . Server::PATH;
    }

    /**
     * RFC 8707-ressourcen i kanonisk form (lille scheme og host, ingen afsluttende /, ingen query eller fragment), eller
     * '' når den ikke kan være vores. Claude sender den kanoniske form; store bogstaver i host tolereres (MCP-spec).
     */
    public static function canonicalResource(string $given): string
    {
        if ($given === '' || strlen($given) > 255 || preg_match('/[\x00-\x20\x7F\\\\]/', $given) === 1) {
            return '';
        }
        $p = parse_url($given);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['pass'])
            || isset($p['query']) || isset($p['fragment'])) {
            return '';
        }
        $path = rtrim($p['path'] ?? '', '/');
        return 'https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
    }

    /** Tilfældigt token med prefix: 32 bytes (256 bit) i base64url, 43 tegn. */
    public static function random(string $prefix, int $bytes = 32): string
    {
        return $prefix . self::b64url(random_bytes($bytes));
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** PKCE S256 (RFC 7636 §4.6): base64url(sha256(verifier)) sammenlignet i konstant tid. */
    public static function pkceOk(string $verifier, string $challenge): bool
    {
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            return false;
        }
        return hash_equals($challenge, self::b64url(hash('sha256', $verifier, true)));
    }

    public static function isLoopback(string $uri): bool
    {
        $p = parse_url($uri);
        return is_array($p) && ($p['scheme'] ?? '') === 'http' && in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true);
    }

    /**
     * Må en redirect_uri registreres og bruges? https med en vært på allowlisten, uden port, userinfo og fragment.
     * Kun på DEV (env = dev): http-loopback på localhost/127.0.0.1 med vilkårlig port (Claude Code, RFC 8252).
     */
    public static function redirectAllowed(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 500 || preg_match('/[^\x21-\x7E]|\\\\/', $uri) === 1 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $p = parse_url($uri);
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || !isset($p['host'], $p['scheme'])) {
            return false;
        }
        if ($p['scheme'] === 'https') {
            return !isset($p['port']) && in_array($p['host'], self::redirectHosts(), true);
        }
        return $p['scheme'] === 'http' && Flags::env() === 'dev' && in_array($p['host'], ['localhost', '127.0.0.1'], true);
    }

    /**
     * Er den viste redirect_uri en af klientens registrerede? Eksakt sammenligning; kun loopback matches uden port
     * (RFC 8252 §7.3, Claude Code bruger en tilfældig port).
     *
     * @param list<string> $registered
     */
    public static function redirectMatches(string $uri, array $registered): bool
    {
        foreach ($registered as $r) {
            if (hash_equals($r, $uri)) {
                return true;
            }
            if (self::isLoopback($r) && self::isLoopback($uri) && self::withoutPort($r) === self::withoutPort($uri)) {
                return true;
            }
        }
        return false;
    }

    private static function withoutPort(string $uri): string
    {
        return (string) preg_replace('#^(http://(?:localhost|127\.0\.0\.1)):[0-9]+#', '$1', $uri);
    }

    /** scheme://host[:port] til CSP form-action. */
    public static function origin(string $uri): string
    {
        $p = parse_url($uri);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** @param array<string, string> $params */
    public static function withQuery(string $uri, array $params): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Klientnavnet renset (design D8): ingen kontrol- eller formattegn (bidi), whitespace samlet, højst 100 tegn. */
    public static function cleanName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return mb_substr($name, 0, 100, 'UTF-8');
    }

    /** Til visning: renset og afkortet til 60 tegn med "…". */
    public static function displayName(string $name): string
    {
        $name = self::cleanName($name);
        return mb_strlen($name, 'UTF-8') > 60 ? rtrim(mb_substr($name, 0, 59, 'UTF-8')) . '…' : $name;
    }

    /** Er værten en af Claude's (bestemmer "Claude" eller "en app" i login-teksten). */
    public static function isClaudeHost(string $host): bool
    {
        return in_array($host, ['claude.ai', 'claude.com'], true);
    }

    public static function host(string $uri): string
    {
        $h = parse_url($uri, PHP_URL_HOST);
        return is_string($h) ? strtolower($h) : '';
    }
}
