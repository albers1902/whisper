<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Whisper\Session;

/**
 * Validering af en autorisationsanmodning og den ventende anmodning i sessionen (F006).
 *
 * Rækkefølgen er sikkerhedskritisk (open redirect): først klienten og en redirect_uri, der er EKSAKT en af de
 * registrerede og stadig på allowlisten. Fejler det, vises en side og redirectes aldrig. Først derefter må fejl sendes
 * til redirect_uri. Den validerede anmodning ligger i sessionen under SESSION_KEY — aldrig i en next=-parameter.
 */
final class Authorize
{
    public const SESSION_KEY = 'oauth_req';
    /** Så længe må en ventende anmodning ligge (login, evt. tvunget skift af adgangskode). */
    public const PENDING_SECONDS = 1800;
    private const STATE_MAX = 1000;

    /**
     * @param array<mixed> $q  query-strengen
     * @return array{kind: 'page', code: string}|array{kind: 'redirect', url: string}|array{kind: 'ok', req: array<string, mixed>}
     */
    public static function validate(array $q, string $site, int $now): array
    {
        $s = static fn(string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';
        $client = Clients::find($s('client_id'));
        if ($client === null) {
            return ['kind' => 'page', 'code' => 'unknown_client'];
        }
        $redirect = $s('redirect_uri');
        if (!OAuth::redirectMatches($redirect, $client['redirect_uris']) || !OAuth::redirectAllowed($redirect)) {
            return ['kind' => 'page', 'code' => 'invalid_redirect_uri'];
        }
        // Der er kun én ressource: mangler parameteren, menes /mcp (QA F5). Kun en forkert værdi afvises.
        if ($s('resource') !== '' && OAuth::canonicalResource($s('resource')) !== OAuth::resource($site)) {
            return ['kind' => 'page', 'code' => 'invalid_resource'];
        }
        // Herfra er redirect_uri bevist: fejl går tilbage til klienten med state og iss (RFC 9207)
        $state = $s('state');
        $back = static function (string $error) use ($redirect, $state, $site): array {
            $p = ['error' => $error];
            if ($state !== '' && strlen($state) <= self::STATE_MAX) {
                $p['state'] = $state;
            }
            return ['kind' => 'redirect', 'url' => OAuth::withQuery($redirect, $p + ['iss' => $site])];
        };
        if (strlen($state) > self::STATE_MAX) {
            return $back('invalid_request');
        }
        if ($s('response_type') !== 'code') {
            return $back('unsupported_response_type');
        }
        // Kun S256: en manglende metode betyder "plain" (RFC 7636 §4.3) og afvises (PKCE-downgrade)
        if ($s('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $s('code_challenge')) !== 1) {
            return $back('invalid_request');
        }
        return ['kind' => 'ok', 'req' => [
            'client_pk'    => $client['id'],
            'client_name'  => $client['name'],
            'redirect_uri' => $redirect,
            'challenge'    => $s('code_challenge'),
            'state'        => $state,
            'resource'     => OAuth::resource($site),
            'rid'          => bin2hex(random_bytes(16)),
            't'            => $now,
        ]];
    }

    /**
     * Den ventende anmodning, hvis den har den forventede form og ikke er for gammel; ellers null.
     *
     * @return array{client_pk: int, client_name: string, redirect_uri: string, challenge: string, state: string,
     *     resource: string, rid: string, t: int}|null
     */
    public static function pending(Session $s, int $now): ?array
    {
        $p = $s->get(self::SESSION_KEY);
        if (!is_array($p) || !is_int($p['client_pk'] ?? null) || !is_int($p['t'] ?? null)) {
            return null;
        }
        foreach (['client_name', 'redirect_uri', 'challenge', 'state', 'resource', 'rid'] as $k) {
            if (!is_string($p[$k] ?? null)) {
                return null;
            }
        }
        if ($now - $p['t'] > self::PENDING_SECONDS || $now < $p['t']) {
            return null;
        }
        return ['client_pk' => $p['client_pk'], 'client_name' => $p['client_name'], 'redirect_uri' => $p['redirect_uri'],
            'challenge' => $p['challenge'], 'state' => $p['state'], 'resource' => $p['resource'], 'rid' => $p['rid'], 't' => $p['t']];
    }

    /**
     * Svaret til klienten efter Godkend eller Afvis.
     *
     * @param array{redirect_uri: string, state: string} $req
     * @param array<string, string> $params  code eller error
     */
    public static function back(array $req, array $params, string $site): string
    {
        if ($req['state'] !== '') {
            $params['state'] = $req['state'];
        }
        return OAuth::withQuery($req['redirect_uri'], $params + ['iss' => $site]);
    }

    /** Teksten over login (design §1): "Claude" kun når værten er en af Claude's; aldrig klientens selvopgivne navn. */
    public static function loginContext(Session $s, int $now): ?string
    {
        $p = self::pending($s, $now);
        if ($p === null) {
            return null;
        }
        return OAuth::isClaudeHost(OAuth::host($p['redirect_uri']))
            ? 'Log ind for at forbinde Claude til Whisper. Bagefter ser du, hvad Claude får adgang til, før du godkender.'
            : 'Log ind for at forbinde en app til Whisper. Bagefter ser du, hvad appen får adgang til, før du godkender.';
    }
}
