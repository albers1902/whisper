<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use Closure;
use Whisper\Paths;

/**
 * Zendesk-API med OAuth client_credentials (API-tokens er fjernet af Zendesk).
 *
 * - Token: POST https://<sub>.zendesk.com/oauth/tokens (JSON: developer.zendesk.com grant_type_tokens kræver JSON), kun read-scopes, ingen refresh token. Det caches i
 *   var/zendesk-token.json (0600) med udløbstid og hentes igen 60 s før udløb, eller én gang ved 401.
 * - 429: venter Retry-After (højst 60 s, højst 3 gange); ellers RateLimited, så sync stopper pænt.
 * - Alle kald går kun til https://<sub>.zendesk.com. En optagelse må desuden hentes fra eller redirectes til værterne i
 *   recording_hosts, men Authorization sendes ALDRIG til andre end Zendesk-værten (developer.zendesk.com:
 *   "Working with url properties"). Redirects følges her, ikke af curl, så hver vært tjekkes.
 */
final class Client
{
    public const TOKEN_LIFETIME = 3600;
    /** Request-formatet til /oauth/tokens (proben udskriver det). */
    public const TOKEN_REQUEST_FORMAT = 'JSON';
    private const RENEW_MARGIN = 60;
    private const MAX_RETRY_WAIT = 60;
    private const MAX_429 = 3;
    private const MAX_REDIRECTS = 5;

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(int): void */
    private Closure $sleep;
    private ?string $token = null;
    private int $tokenExpires = 0;

    /** @var list<array{host: string, status: int}> statuskæden for sidste download (til proben; ingen URL'er) */
    public array $lastChain = [];

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(int): void)|null $sleep
     */
    public function __construct(
        private readonly ZendeskConfig $cfg,
        private readonly HttpTransport $http,
        private readonly ?string $tokenFile = null,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
    }

    public static function tokenFile(): string
    {
        return Paths::dir('') . '/zendesk-token.json';
    }

    /**
     * GET af et API-endpoint på Zendesk-værten (sti "/api/v2/…" eller en fuld URL fra Zendesk, fx next_page).
     *
     * @return array<string, mixed>
     * @throws ZendeskError
     */
    public function getJson(string $pathOrUrl): array
    {
        $url = str_starts_with($pathOrUrl, '/') ? $this->cfg->baseUrl() . $pathOrUrl : $pathOrUrl;
        if (self::hostOf($url) !== $this->cfg->host()) {
            throw new ZendeskError('API-kald til en anden vært end Zendesk afvist');
        }
        $res = $this->authed(fn(string $tok): HttpResult => $this->http->request('GET', $url,
            ['Authorization' => 'Bearer ' . $tok, 'Accept' => 'application/json']));
        if ($res->status < 200 || $res->status > 299) {
            throw new ZendeskError('HTTP ' . $res->status . ' fra Zendesk', $res->status);
        }
        $data = json_decode($res->body, true);
        if (!is_array($data)) {
            throw new ZendeskError('ugyldigt JSON-svar fra Zendesk');
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Henter en optagelse til $dest. Returnerer Content-Type.
     *
     * @throws ZendeskError
     */
    public function download(string $url, string $dest): string
    {
        $this->lastChain = [];
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = self::hostOf($current);
            $own = $host === $this->cfg->host();
            if ($host === '' || (!$own && !in_array($host, $this->cfg->recordingHosts, true))) {
                $this->lastChain[] = ['host' => $host === '' ? '(ugyldig)' : $host, 'status' => 0];
                throw new ZendeskError('optagelsen ligger på en vært, der ikke er godkendt');
            }
            $target = $current;
            $res = $own
                ? $this->authed(fn(string $tok): HttpResult => $this->http->request('GET', $target, ['Authorization' => 'Bearer ' . $tok], null, $dest))
                : $this->retry429(fn(): HttpResult => $this->http->request('GET', $target, [], null, $dest));
            $this->lastChain[] = ['host' => $host, 'status' => $res->status];
            if (in_array($res->status, [301, 302, 303, 307, 308], true)) {
                $current = self::resolve($current, $res->header('location'));
                continue;
            }
            if ($res->status !== 200) {
                throw new ZendeskError('optagelsen kunne ikke hentes (HTTP ' . $res->status . ')');
            }
            return $res->header('content-type');
        }
        throw new ZendeskError('for mange redirects ved hentning af optagelsen');
    }

    /**
     * Et kald med Bearer-token: fornyes én gang ved 401, og 429 håndteres.
     *
     * @param Closure(string): HttpResult $call
     */
    private function authed(Closure $call): HttpResult
    {
        $res = $this->retry429(fn(): HttpResult => $call($this->accessToken(false)));
        if ($res->status === 401) {
            $res = $this->retry429(fn(): HttpResult => $call($this->accessToken(true)));
        }
        return $res;
    }

    /** @param Closure(): HttpResult $call */
    private function retry429(Closure $call): HttpResult
    {
        for ($i = 1; $i < self::MAX_429; $i++) {
            $res = $call();
            if ($res->status !== 429) {
                return $res;
            }
            $ra = trim($res->header('retry-after'));
            $wait = ctype_digit($ra) ? (int) $ra : self::MAX_RETRY_WAIT;
            if ($wait > self::MAX_RETRY_WAIT) {
                break;
            }
            ($this->sleep)(max(1, $wait));
        }
        $res = $i < self::MAX_429 ? null : $call();   // sidste forsøg efter den sidste ventetid
        if ($res !== null && $res->status !== 429) {
            return $res;
        }
        throw new RateLimited('Zendesk rate limit (429) — fortsætter ved næste kørsel');
    }

    /** @throws ZendeskError */
    public function accessToken(bool $force): string
    {
        $now = ($this->clock)();
        if (!$force && $this->token !== null && $this->tokenExpires - self::RENEW_MARGIN > $now) {
            return $this->token;
        }
        if (!$force && $this->tokenFile !== null && is_readable($this->tokenFile)) {
            $c = json_decode((string) file_get_contents($this->tokenFile), true);
            if (is_array($c) && is_string($c['access_token'] ?? null) && is_int($c['expires_at'] ?? null)
                && ($c['client'] ?? null) === $this->clientKey() && $c['expires_at'] - self::RENEW_MARGIN > $now) {
                $this->token = $c['access_token'];
                $this->tokenExpires = $c['expires_at'];
                return $this->token;
            }
        }
        $body = (string) json_encode([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg->clientId,
            'client_secret' => $this->cfg->clientSecret,
            'scope'         => $this->cfg->scope,
            'expires_in'    => self::TOKEN_LIFETIME,
        ]);
        $res = $this->retry429(fn(): HttpResult => $this->http->request('POST', $this->cfg->baseUrl() . '/oauth/tokens',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body));
        $data = json_decode($res->body, true);
        $tok = is_array($data) ? ($data['access_token'] ?? null) : null;
        if ($res->status !== 200 || !is_string($tok) || $tok === '') {
            throw new ZendeskError('Zendesk afviste OAuth-klienten ('
                . self::oauthErrorText($res->status, $res->body, [$this->cfg->clientId, $this->cfg->clientSecret]) . ')');
        }
        $life = is_array($data) && is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 1800;
        $this->token = $tok;
        $this->tokenExpires = $now + $life;
        if ($this->tokenFile !== null) {
            Paths::writePrivate($this->tokenFile, (string) json_encode([
                'access_token' => $tok, 'expires_at' => $this->tokenExpires, 'client' => $this->clientKey(),
            ]));
        }
        return $tok;
    }

    /**
     * "HTTP 400: invalid_client - <beskrivelse>" ud fra Zendesks OAuth-fejlsvar (RFC 6749 §5.2: error, error_description).
     * Teksten ender i proben, loggen, sync_state.last_error og på siden, så den renses (ZendeskError-reglen): de kendte
     * hemmeligheder og alt token-lignende fjernes, kun printbar ASCII, højst 200 tegn. Er svaret ikke JSON (fx en
     * HTML-side), vises kun HTTP-status.
     *
     * @param list<string> $secrets client_id, client_secret
     */
    public static function oauthErrorText(int $status, string $body, array $secrets): string
    {
        $head = 'HTTP ' . $status;
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $head;
        }
        $parts = [];
        foreach (['error', 'error_description'] as $k) {
            if (is_string($data[$k] ?? null) && trim($data[$k]) !== '') {
                $parts[] = $data[$k];
            }
        }
        if ($parts === []) {
            return $head;
        }
        $text = implode(' - ', $parts);
        // Hemmelighederne først (råt), så rensningen af tegn ikke kan skille dem ad. Under 4 tegn er ikke en hemmelighed,
        // og en så kort værdi ville ødelægge almindelige ord.
        $known = array_values(array_filter($secrets, static fn(string $v): bool => strlen($v) >= 4));
        $text = str_replace($known, '[fjernet]', $text);
        $text = (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text);
        $text = str_replace($known, '[fjernet]', $text);
        // Navngivne felter med værdi (client_secret=…, "access_token": "…", Bearer …)
        $text = (string) preg_replace(
            '/\b(client_id|client_secret|secret|access_token|refresh_token|token|password|authorization)(["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i',
            '$1$2[fjernet]', $text);
        $text = (string) preg_replace('/\b(bearer|basic)\s+[^\s"\',;]+/i', '$1 [fjernet]', $text);
        // Token-lignende ord: 16+ tegn med et ciffer, eller 32+ tegn (base64/hex/JWT). Fejlkoder som
        // unsupported_grant_type (22 tegn, ingen cifre) bevares.
        $text = (string) preg_replace('/(?=[A-Za-z0-9_\-+\/=.]*\d)[A-Za-z0-9_\-+\/=.]{16,}|[A-Za-z0-9_\-+\/=.]{32,}/', '[fjernet]', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        $text = $head . ': ' . $text;
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }

    /** Skifter klient-id eller scope, gælder et cachet token ikke længere. Hash, så id'et ikke står i filen. */
    private function clientKey(): string
    {
        return hash('sha256', $this->cfg->host() . "\n" . $this->cfg->clientId . "\n" . $this->cfg->scope);
    }

    /** Værten for en https-URL på port 443 uden brugerinfo, ellers ''. */
    public static function hostOf(string $url): string
    {
        // Kontroltegn, mellemrum og backslash afvises før parse_url: curl og parse_url kunne ellers læse værten forskelligt
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return '';
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || !isset($p['host'])
            || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            return '';
        }
        return strtolower($p['host']);
    }

    private static function resolve(string $base, string $location): string
    {
        if ($location === '') {
            return '';
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return 'https://' . self::hostOf($base) . $location;
        }
        return $location;   // absolut (eller ugyldig — så afvises den af hostOf i næste runde)
    }
}
