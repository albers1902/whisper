<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Throwable;
use Whisper\DB;
use Whisper\Users;

/**
 * Engangskoder, access og refresh tokens. Alt er opaque og tilfældigt (256 bit), og kun SHA-256 gemmes, som for
 * F003-nøglerne. Indløsning af en kode og rotation af et refresh token sker med ét atomisk
 * `UPDATE … WHERE used_at IS NULL`: taber man det kapløb, eller er den allerede brugt, er det genbrug, og hele
 * bevillingen tilbagekaldes.
 */
final class Issuer
{
    private const ACCESS = '/^wat_[A-Za-z0-9_-]{43}$/';

    /** Ny kode til en godkendt bevilling. Returnerer klarteksten (går kun i redirecten). */
    public static function issueCode(int $grantId, string $redirectUri, string $challenge, int $now): string
    {
        $code = OAuth::random('wac_');
        DB::exec('INSERT INTO oauth_codes (code_hash, grant_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?)',
            [OAuth::hash($code), $grantId, $redirectUri, $challenge, Users::ts($now + OAuth::CODE_SECONDS)]);
        return $code;
    }

    /**
     * Kode → tokens. Fejl er RFC 6749 §5.2-koder.
     *
     * @param array{id: int, client_id: string} $client
     * @return array{ok: true, tokens: array<string, mixed>, grant: int, user: int}|array{ok: false, error: string, grant: int}
     */
    public static function exchange(array $client, string $code, string $redirectUri, string $verifier, string $resource, int $now): array
    {
        if (preg_match('/^wac_[A-Za-z0-9_-]{43}$/', $code) !== 1) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];
        }
        $hash = OAuth::hash($code);
        $rows = DB::all('SELECT c.grant_id, c.redirect_uri, c.code_challenge, c.expires_at, c.used_at, g.client_pk, g.user_id, g.resource, '
            . 'g.revoked_at FROM oauth_codes c JOIN oauth_grants g ON g.id = c.grant_id WHERE c.code_hash = ?', [$hash]);
        if ($rows === []) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];
        }
        $row = $rows[0];
        $grant = (int) $row['grant_id'];
        $claimed = $row['used_at'] === null
            && DB::exec('UPDATE oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL', [Users::ts($now), $hash]) === 1;
        if (!$claimed) {
            // Genbrugt kode: en angriber eller en dobbelt indløsning. Alt udstedt fra koden dør (AK3).
            Grants::revoke($grant, $now);
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => -$grant];
        }
        $valid = (int) $row['client_pk'] === $client['id']
            && strtotime((string) $row['expires_at']) >= $now
            && $row['revoked_at'] === null
            && hash_equals((string) $row['redirect_uri'], $redirectUri)
            && OAuth::pkceOk($verifier, (string) $row['code_challenge'])
            && ($resource === '' || hash_equals((string) $row['resource'], OAuth::canonicalResource($resource)))
            && self::userOk((int) $row['user_id']);
        if (!$valid) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => $grant];
        }
        return ['ok' => true, 'tokens' => self::issuePair($grant, $now), 'grant' => $grant, 'user' => (int) $row['user_id']];
    }

    /**
     * Refresh token → nyt par (rotation). Et allerede brugt refresh token tilbagekalder bevillingen (AK4).
     *
     * @param array{id: int, client_id: string} $client
     * @return array{ok: true, tokens: array<string, mixed>, grant: int, user: int}|array{ok: false, error: string, grant: int}
     */
    public static function refresh(array $client, string $token, string $resource, int $now): array
    {
        if (preg_match('/^wrt_[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];
        }
        $hash = OAuth::hash($token);
        $rows = DB::all("SELECT t.id, t.grant_id, t.expires_at, t.used_at, g.client_pk, g.user_id, g.resource, g.revoked_at FROM oauth_tokens t "
            . "JOIN oauth_grants g ON g.id = t.grant_id WHERE t.token_hash = ? AND t.kind = 'refresh'", [$hash]);
        if ($rows === [] || (int) $rows[0]['client_pk'] !== $client['id']) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];
        }
        $row = $rows[0];
        $grant = (int) $row['grant_id'];
        $claimed = $row['used_at'] === null
            && DB::exec('UPDATE oauth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [Users::ts($now), (int) $row['id']]) === 1;
        if (!$claimed) {
            Grants::revoke($grant, $now);
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => -$grant];
        }
        $valid = $row['revoked_at'] === null
            && strtotime((string) $row['expires_at']) >= $now
            && ($resource === '' || hash_equals((string) $row['resource'], OAuth::canonicalResource($resource)))
            && self::userOk((int) $row['user_id']);
        if (!$valid) {
            return ['ok' => false, 'error' => 'invalid_grant', 'grant' => $grant];
        }
        return ['ok' => true, 'tokens' => self::issuePair($grant, $now), 'grant' => $grant, 'user' => (int) $row['user_id']];
    }

    /** @return array<string, mixed> token-svaret (RFC 6749 §5.1) */
    private static function issuePair(int $grantId, int $now): array
    {
        $access = OAuth::random('wat_');
        $refresh = OAuth::random('wrt_');
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO oauth_tokens (grant_id, kind, token_hash, expires_at) VALUES (?, ?, ?, ?)';
            DB::exec($sql, [$grantId, 'access', OAuth::hash($access), Users::ts($now + OAuth::ACCESS_SECONDS)]);
            DB::exec($sql, [$grantId, 'refresh', OAuth::hash($refresh), Users::ts($now + OAuth::REFRESH_SECONDS)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => OAuth::ACCESS_SECONDS, 'refresh_token' => $refresh];
    }

    private static function userOk(int $userId): bool
    {
        return DB::scalar('SELECT id FROM users WHERE id = ? AND active = 1 AND must_change_password = 0', [$userId]) !== false;
    }

    /**
     * Brugeren bag et OAuth access token på /mcp, eller null. Token skal være udstedt til netop denne ressource
     * (audience, RFC 8707), ikke udløbet, bevillingen ikke tilbagekaldt, og brugeren aktiv uden tvunget skift.
     *
     * @return array{grant_id: int, client_pk: int, user: array<string, mixed>}|null
     */
    public static function authenticate(string $header, string $resource, int $now): ?array
    {
        if (preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/i', $header, $m) !== 1 || preg_match(self::ACCESS, $m[1]) !== 1) {
            return null;
        }
        $rows = DB::all("SELECT g.id AS oauth_grant_id, g.client_pk AS oauth_client_pk, g.resource AS oauth_resource, u.* FROM oauth_tokens t "
            . "JOIN oauth_grants g ON g.id = t.grant_id JOIN users u ON u.id = g.user_id WHERE t.token_hash = ? AND t.kind = 'access' "
            . 'AND t.expires_at > ? AND g.revoked_at IS NULL AND u.active = 1 AND u.must_change_password = 0',
            [OAuth::hash($m[1]), Users::ts($now)]);
        if ($rows === [] || !hash_equals((string) $rows[0]['oauth_resource'], $resource)) {
            return null;
        }
        $user = $rows[0];
        $grant = (int) $user['oauth_grant_id'];
        $client = (int) $user['oauth_client_pk'];
        unset($user['oauth_grant_id'], $user['oauth_client_pk'], $user['oauth_resource'], $user['password_hash']);
        return ['grant_id' => $grant, 'client_pk' => $client, 'user' => $user];
    }
}
