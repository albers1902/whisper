<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use Closure;
use Whisper\Paths;

/**
 * Zendesk-API med OAuth client_credentials (API-tokens er fjernet af Zendesk).
 *
 * - Token: POST https://<sub>.zendesk.com/oauth/tokens (JSON), kun read-scopes, ingen refresh token. Det caches i
 *   var/zendesk-token.json (0600) med udløbstid og hentes igen 60 s før udløb, eller én gang ved 401.
 * - 429: venter Retry-After (højst 60 s, højst 3 gange); ellers RateLimited, så sync stopper pænt.
 * - Alle kald går kun til https://<sub>.zendesk.com. En optagelse må desuden hentes fra eller redirectes til værterne i
 *   recording_hosts, men Authorization sendes ALDRIG til andre end Zendesk-værten (developer.zendesk.com:
 *   "Working with url properties"). Redirects følges her, ikke af curl, så hver vært tjekkes.
 */
final class Client
{
    public const TOKEN_LIFETIME = 3600;
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
            throw new ZendeskError('Zendesk afviste OAuth-klienten (HTTP ' . $res->status . ')');
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

    /** Skifter klient-id eller scope, gælder et cachet token ikke længere. Hash, så id'et ikke står i filen. */
    private function clientKey(): string
    {
        return hash('sha256', $this->cfg->host() . "\n" . $this->cfg->clientId . "\n" . $this->cfg->scope);
    }

    /** Værten for en https-URL på port 443 uden brugerinfo, ellers ''. */
    public static function hostOf(string $url): string
    {
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
