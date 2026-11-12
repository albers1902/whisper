<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Closure;
use Whisper\Zendesk\Client as ZendeskClient;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\HttpTransport;
use Whisper\Zendesk\ZendeskError;

/**
 * Adversus' API v1 (api.adversus.io/swagger/apinew.json): HTTP Basic auth med en API-bruger, kun https://api.adversus.io/v1.
 *
 * - Transporten er F002's (kun https, ingen automatiske redirects, certifikattjek, loft på 500 MB); dens fejltekster er
 *   faste og uden URL, så de kan sendes videre som AdversusError.
 * - 429: Adversus dokumenterer 60 kald/min og 1000/time, men ikke Retry-After. Vi venter Retry-After (eller 60 s uden
 *   headeren) højst 3 gange; over 60 s eller fortsat 429 = RateLimited.
 * - Optagelser: /calls/{id}/recording svarer 307 til en tidsbegrænset URL. Redirects følges her, så hver vært tjekkes,
 *   og Authorization sendes ALDRIG til andre værter end api.adversus.io. Andre værter skal stå i recording_hosts.
 */
final class Client
{
    public const HOST = 'api.adversus.io';
    public const BASE = 'https://api.adversus.io/v1';
    private const MAX_RETRY_WAIT = 60;
    private const MAX_429 = 3;
    private const MAX_REDIRECTS = 5;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var list<array{host: string, status: int}> statuskæden for sidste download (kun værter, ingen URL'er) */
    public array $lastChain = [];
    /** Antal HTTP-kald (proben viser det mod Adversus' rate limit) */
    public int $requests = 0;

    /** @param (Closure(int): void)|null $sleep */
    public function __construct(
        private readonly AdversusConfig $cfg,
        private readonly HttpTransport $http,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
    }

    /**
     * GET af et API-endpoint ("/calls" + query). Returnerer det afkodede JSON (objekt eller liste).
     *
     * @param array<string, string|int> $query
     * @return array<mixed>
     * @throws AdversusError
     */
    public function getJson(string $path, array $query = []): array
    {
        $res = $this->get($path, $query);
        if ($res->status < 200 || $res->status > 299) {
            throw new AdversusError(self::errorText($res->status, $res->body, $this->secrets()), $res->status);
        }
        $data = json_decode($res->body, true);
        if (!is_array($data)) {
            throw new AdversusError('ugyldigt JSON-svar fra Adversus (HTTP ' . $res->status . ')', $res->status);
        }
        return $data;
    }

    /**
     * @param array<string, string|int> $query
     * @throws AdversusError
     */
    public function get(string $path, array $query = []): HttpResult
    {
        if (preg_match('#^(/[a-z0-9-]+)+$#', $path) !== 1) {
            throw new AdversusError('ugyldig API-sti');
        }
        $url = self::BASE . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        return $this->send(fn(): HttpResult => $this->http->request('GET', $url, $this->authHeaders()));
    }

    /**
     * Henter optagelsen for et opkald (/calls/{id}/recording → 307 → lydfilen) til $dest. Returnerer Content-Type.
     *
     * @throws AdversusError
     */
    public function downloadRecording(int $callId, string $dest): string
    {
        $this->lastChain = [];
        $current = self::BASE . '/calls/' . $callId . '/recording';
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = self::hostOf($current);
            $own = $host === self::HOST;
            if ($host === '' || (!$own && !in_array($host, $this->cfg->recordingHosts, true))) {
                $this->lastChain[] = ['host' => $host === '' ? '(ugyldig eller ikke https)' : $host . ' (ikke godkendt)', 'status' => 0];
                throw new AdversusError('optagelsen ligger på en vært, der ikke er godkendt');
            }
            $target = $current;
            $headers = $own ? $this->authHeaders() : [];
            $res = $this->send(fn(): HttpResult => $this->http->request('GET', $target, $headers, null, $dest));
            $this->lastChain[] = ['host' => $host, 'status' => $res->status];
            if (in_array($res->status, [301, 302, 303, 307, 308], true)) {
                $current = self::resolve($current, $res->header('location'));
                continue;
            }
            if ($res->status === 404 && $hop === 0) {
                throw new AdversusError('ingen optagelse til opkaldet (HTTP 404)', 404);
            }
            if ($res->status !== 200) {
                throw new AdversusError('optagelsen kunne ikke hentes (HTTP ' . $res->status . ')', $res->status);
            }
            return $res->header('content-type');
        }
        throw new AdversusError('for mange redirects ved hentning af optagelsen');
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->cfg->username . ':' . $this->cfg->password),
            'Accept'        => 'application/json',
        ];
    }

    /** @return list<string> værdier, der aldrig må stå i en fejltekst */
    private function secrets(): array
    {
        return [$this->cfg->username, $this->cfg->password, base64_encode($this->cfg->username . ':' . $this->cfg->password)];
    }

    /** @param Closure(): HttpResult $call */
    private function send(Closure $call): HttpResult
    {
        for ($try = 1; ; $try++) {
            $this->requests++;
            try {
                $res = $call();
            } catch (ZendeskError $e) {
                // Transportens tekster er faste ("netværksfejl (curl 6)", loft på 500 MB) og uden URL
                throw new AdversusError($e->getMessage(), $e->getCode());
            }
            if ($res->status !== 429) {
                return $res;
            }
            if ($try >= self::MAX_429) {
                break;
            }
            $ra = trim($res->header('retry-after'));
            $wait = ctype_digit($ra) ? (int) $ra : self::MAX_RETRY_WAIT;
            if ($wait > self::MAX_RETRY_WAIT) {
                break;
            }
            ($this->sleep)(max(1, $wait));
        }
        throw new RateLimited('Adversus rate limit (HTTP 429) — proben stopper; prøv igen om et par minutter', 429);
    }

    /**
     * "HTTP 401: code 401 - <besked>" ud fra Adversus' fejlformat {code, message}. Beskeden er fri tekst fra Adversus og
     * renses: de kendte hemmeligheder, e-mailadresser, navngivne felter, Basic/Bearer-værdier og alt token-lignende fjernes,
     * kun printbar ASCII, højst 200 tegn. Er svaret ikke JSON (fx en HTML-side), vises kun HTTP-status.
     *
     * @param list<string> $secrets
     */
    public static function errorText(int $status, string $body, array $secrets): string
    {
        $head = 'HTTP ' . $status;
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $head;
        }
        $parts = [];
        if (is_int($data['code'] ?? null)) {
            $parts[] = 'code ' . $data['code'];
        }
        if (is_string($data['message'] ?? null) && trim($data['message']) !== '') {
            $parts[] = $data['message'];
        }
        if ($parts === []) {
            return $head;
        }
        $text = implode(' - ', $parts);
        // Hemmelighederne først (råt), så rensningen af tegn ikke kan skille dem ad. Under 4 tegn ville ødelægge almindelige ord.
        $known = array_values(array_filter($secrets, static fn(string $v): bool => strlen($v) >= 4));
        $text = str_ireplace($known, '[fjernet]', $text);
        $text = (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text);
        $text = str_ireplace($known, '[fjernet]', $text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', '[fjernet]', $text);
        $text = (string) preg_replace(
            '/\b(user(name)?|password|pass|secret|token|access_token|authorization)(["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i',
            '$1$3[fjernet]', $text);
        $text = (string) preg_replace('/\b(bearer|basic)\s+[^\s"\',;]+/i', '$1 [fjernet]', $text);
        $text = (string) preg_replace('#https?://\S+#i', '[url fjernet]', $text);
        // Token-lignende ord: 16+ tegn med et ciffer, eller 32+ tegn (base64/hex)
        $text = (string) preg_replace('/(?=[A-Za-z0-9_\-+\/=.]*\d)[A-Za-z0-9_\-+\/=.]{16,}|[A-Za-z0-9_\-+\/=.]{32,}/', '[fjernet]', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        $text = $head . ': ' . $text;
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }

    /** Værten for en https-URL på port 443 uden brugerinfo, ellers '' (samme regel som F002). */
    public static function hostOf(string $url): string
    {
        return ZendeskClient::hostOf($url);
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
