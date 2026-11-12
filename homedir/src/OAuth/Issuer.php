<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Throwable;
use Whisper\DB;
use Whisper\Users;

/**
 * Engangskoder, access og refresh tokens. Alt er opaque og tilfældigt (256 bit), og kun SHA-256 gemmes, som for
 * F003-nøglerne.
 *
 * Indløsning af en kode og rotation af et refresh token sker i ÉN transaktion med rækken låst (SELECT … FOR UPDATE):
 * claim og det nye par committes sammen, så en fejl midt i aldrig brænder et token uden at udstede et nyt (security S3),
 * og to samtidige kald står i kø i stedet for at ræse. Genbrug af en kode tilbagekalder hele bevillingen. Genbrug af et
 * refresh token gør det også, undtagen inden for GRACE_SECONDS fra rotationen med samme klient (QA F2): Claude, der
 * refresher to gange samtidigt eller prøver igen efter et tabt svar, får så et nyt par i stedet for en død forbindelse.
 */
final class Issuer
{
    private const ACCESS = '/^wat_[A-Za-z0-9_-]{43}$/';
    public const GRACE_SECONDS = 30;

    /** Ny kode til en godkendt bevilling. Returnerer klarteksten (går kun i redirecten). */
    public static function issueCode(int $grantId, string $redirectUri, string $challenge, int $now): string
    {
        $code = OAuth::random('wac_');
        DB::exec('INSERT INTO oauth_codes (code_hash, grant_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?)',
            [OAuth::hash($code), $grantId, $redirectUri, $challenge, Users::ts($now + OAuth::CODE_SECONDS)]);
        return $code;
    }

    /**
     * Kode → tokens. Fejl er RFC 6749 §5.2-koder. grant < 0: genbrug, bevillingen er tilbagekaldt.
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
        /** @var array{ok: true, tokens: array<string, mixed>, grant: int, user: int}|array{ok: false, error: string, grant: int} $result */
        $result = self::atomic(static function () use ($client, $hash, $redirectUri, $verifier, $resource, $now): array {
            $rows = DB::all('SELECT c.grant_id, c.redirect_uri, c.code_challenge, c.expires_at, c.used_at, g.client_pk, g.user_id, g.resource, '
                . 'g.revoked_at, g.created_at AS grant_created FROM oauth_codes c JOIN oauth_grants g ON g.id = c.grant_id '
                . 'WHERE c.code_hash = ? FOR UPDATE', [$hash]);
            if ($rows === []) {
                return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];
            }
            $row = $rows[0];
            $grant = (int) $row['grant_id'];
            if ($row['used_at'] !== null) {
                // Genbrugt kode: en angriber eller en dobbelt indløsning. Alt udstedt fra koden dør (AK3).
                Grants::revoke($grant, $now);
                return ['ok' => false, 'error' => 'invalid_grant', 'grant' => -$grant];
            }
            DB::exec('UPDATE oauth_codes SET used_at = ? WHERE code_hash = ?', [Users::ts($now), $hash]);
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
        });
        return $result;
    }

    /**
     * Refresh token → nyt par (rotation). Genbrug efter GRACE_SECONDS tilbagekalder bevillingen (AK4); bevillingen
     * lever højst OAuth::GRANT_MAX_SECONDS, uanset rotation.
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
        /** @var array{ok: true, tokens: array<string, mixed>, grant: int, user: int}|array{ok: false, error: string, grant: int} $result */
        $result = self::atomic(static function () use ($client, $hash, $resource, $now): array {
            $rows = DB::all("SELECT t.id, t.grant_id, t.expires_at, t.used_at, g.client_pk, g.user_id, g.resource, g.revoked_at, "
                . "g.created_at AS grant_created FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id "
                . "WHERE t.token_hash = ? AND t.kind = 'refresh' FOR UPDATE", [$hash]);
            if ($rows === [] || (int) $rows[0]['client_pk'] !== $client['id']) {
                return ['ok' => false, 'error' => 'invalid_grant', 'grant' => 0];   // en anden klient rører ikke kæden
            }
            $row = $rows[0];
            $grant = (int) $row['grant_id'];
            if ($row['used_at'] !== null) {
                $since = $now - (int) strtotime((string) $row['used_at']);
                if ($since < 0 || $since > self::GRACE_SECONDS) {
                    Grants::revoke($grant, $now);
                    return ['ok' => false, 'error' => 'invalid_grant', 'grant' => -$grant];
                }
                // Inden for vinduet: et kapløb eller et retry fra samme klient. Nyt par, kæden lever videre.
            } else {
                DB::exec('UPDATE oauth_tokens SET used_at = ? WHERE id = ?', [Users::ts($now), (int) $row['id']]);
            }
            $valid = $row['revoked_at'] === null
                && strtotime((string) $row['expires_at']) >= $now
                && $now - (int) strtotime((string) $row['grant_created']) <= OAuth::GRANT_MAX_SECONDS
                && ($resource === '' || hash_equals((string) $row['resource'], OAuth::canonicalResource($resource)))
                && self::userOk((int) $row['user_id']);
            if (!$valid) {
                return ['ok' => false, 'error' => 'invalid_grant', 'grant' => $grant];
            }
            return ['ok' => true, 'tokens' => self::issuePair($grant, $now), 'grant' => $grant, 'user' => (int) $row['user_id']];
        });
        return $result;
    }

    /**
     * Kører $fn i én transaktion og committer også, når $fn returnerer en fejl (en tilbagekaldelse ved genbrug og et
     * brugt token skal gemmes). Kun en undtagelse ruller tilbage, så intet brændes uden et nyt par.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function atomic(callable $fn): mixed
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> token-svaret (RFC 6749 §5.1). Kaldes inde i atomic(). */
    private static function issuePair(int $grantId, int $now): array
    {
        $access = OAuth::random('wat_');
        $refresh = OAuth::random('wrt_');
        $sql = 'INSERT INTO oauth_tokens (grant_id, kind, token_hash, expires_at) VALUES (?, ?, ?, ?)';
        DB::exec($sql, [$grantId, 'access', OAuth::hash($access), Users::ts($now + OAuth::ACCESS_SECONDS)]);
        DB::exec($sql, [$grantId, 'refresh', OAuth::hash($refresh), Users::ts($now + OAuth::REFRESH_SECONDS)]);
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
