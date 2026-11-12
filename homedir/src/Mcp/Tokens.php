<?php
declare(strict_types=1);

namespace Whisper\Mcp;

use Throwable;
use Whisper\DB;
use Whisper\UserError;
use Whisper\Users;

/**
 * Personlige MCP-nøgler (F003). En nøgle er `wsp_` + 32 tilfældige bytes i base64url (256 bit), så en SHA-256-hash
 * er nok: ingen bcrypt, og opslaget sker på hashen, aldrig på klarteksten. Klarteksten findes kun i svaret på
 * oprettelsen; databasen har hash og de første 8 tegn til visning.
 */
final class Tokens
{
    public const MAX_ACTIVE = 5;
    public const PREFIX = 'wsp_';
    private const PATTERN = '/^wsp_[A-Za-z0-9_-]{43}$/';
    private const TOUCH_SECONDS = 60;
    /** Fejltekster til navnefeltet (design F005 §3.6); View markerer feltet aria-invalid ved netop disse. */
    public const NAME_EMPTY = 'Skriv et navn til nøglen.';
    public const NAME_LONG = 'Navnet må højst være 80 tegn.';
    public const FULL = 'Du har 5 aktive nøgler, som er det højeste antal. Tilbagekald en nøgle for at oprette en ny.';

    public static function generate(): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Navnet renset, eller en UserError med en dansk tekst. */
    public static function validName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if (!mb_check_encoding($name, 'UTF-8') || $name === '') {
            throw new UserError(self::NAME_EMPTY);
        }
        if (mb_strlen($name, 'UTF-8') > 80) {
            throw new UserError(self::NAME_LONG);
        }
        return $name;
    }

    /**
     * Opretter en nøgle og returnerer klarteksten (vises én gang). Brugerens række låses (FOR UPDATE), så to samtidige
     * oprettelser ikke kan give flere end MAX_ACTIVE aktive nøgler.
     */
    public static function create(int $userId, string $name, int $now): string
    {
        $name = self::validName($name);
        $token = self::generate();
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if (DB::all('SELECT id FROM users WHERE id = ? AND active = 1 FOR UPDATE', [$userId]) === []) {
                throw new UserError('Brugeren findes ikke.');
            }
            $active = (int) DB::scalar('SELECT COUNT(*) FROM mcp_tokens WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
            if ($active >= self::MAX_ACTIVE) {
                throw new UserError(self::FULL);
            }
            DB::exec('INSERT INTO mcp_tokens (user_id, name, prefix, token_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, $name, substr($token, 0, 8), self::hash($token), Users::ts($now)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $token;
    }

    /**
     * Tilbagekalder en af brugerens EGNE aktive nøgler og returnerer dens navn. null: findes ikke, er en andens eller
     * allerede tilbagekaldt.
     */
    public static function revoke(int $userId, int $tokenId, int $now): ?string
    {
        $name = DB::scalar('SELECT name FROM mcp_tokens WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$tokenId, $userId]);
        if (!is_string($name)) {
            return null;
        }
        $n = DB::exec('UPDATE mcp_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [Users::ts($now), $tokenId, $userId]);
        return $n === 1 ? $name : null;
    }

    /** Tilbagekalder alle brugerens aktive nøgler (skift eller nulstilling af adgangskode) og returnerer antallet. */
    public static function revokeAll(int $userId, int $now): int
    {
        return DB::exec('UPDATE mcp_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Users::ts($now), $userId]);
    }

    /** @return list<array<string, mixed>> brugerens aktive nøgler (uden hash), nyeste først */
    public static function active(int $userId): array
    {
        return DB::all('SELECT id, name, prefix, created_at, last_used_at FROM mcp_tokens WHERE user_id = ? AND revoked_at IS NULL '
            . 'ORDER BY created_at DESC, id DESC', [$userId]);
    }

    /**
     * Brugeren bag en `Authorization: Bearer …`-header, eller null. Rolle og aktiv-status læses fra databasen ved
     * hver request (som F001's session). En bruger, der skal skifte adgangskode (fx efter en nulstilling), afvises
     * også, indtil det er gjort.
     *
     * @return array{token_id: int, user: array<string, mixed>}|null
     */
    public static function authenticate(string $header): ?array
    {
        if (preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/i', $header, $m) !== 1 || preg_match(self::PATTERN, $m[1]) !== 1) {
            return null;   // forkert form: intet databaseopslag
        }
        $rows = DB::all('SELECT t.id AS mcp_token_id, u.* FROM mcp_tokens t JOIN users u ON u.id = t.user_id '
            . 'WHERE t.token_hash = ? AND t.revoked_at IS NULL AND u.active = 1 AND u.must_change_password = 0',
            [self::hash($m[1])]);
        if ($rows === []) {
            return null;
        }
        $user = $rows[0];
        $id = (int) $user['mcp_token_id'];
        unset($user['mcp_token_id'], $user['password_hash']);
        return ['token_id' => $id, 'user' => $user];
    }

    /** last_used_at højst én gang i minuttet, så hver request ikke skriver. */
    public static function touch(int $tokenId, int $now): void
    {
        DB::exec('UPDATE mcp_tokens SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [Users::ts($now), $tokenId, Users::ts($now - self::TOUCH_SECONDS)]);
    }
}
