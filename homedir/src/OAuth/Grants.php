<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Whisper\DB;
use Whisper\Users;

/**
 * En bevilling er én godkendelse (bruger × klient) og hele kæden af koder og tokens fra den. Tilbagekald sætter
 * revoked_at på bevillingen, så alle dens tokens er ugyldige i samme UPDATE — også ved genbrug af en kode eller et
 * roteret refresh token (OAuth 2.1 §4.1.3, §4.3.1).
 */
final class Grants
{
    public static function create(int $clientPk, int $userId, string $resource, int $now): int
    {
        DB::exec('INSERT INTO oauth_grants (client_pk, user_id, resource, created_at) VALUES (?, ?, ?, ?)',
            [$clientPk, $userId, $resource, Users::ts($now)]);
        return (int) DB::pdo()->lastInsertId();
    }

    public static function revoke(int $grantId, int $now): int
    {
        return DB::exec('UPDATE oauth_grants SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Users::ts($now), $grantId]);
    }

    /**
     * Tilbagekalder alle brugerens aktive bevillinger (ny adgangskode, deaktivering) og returnerer antallet, der havde
     * et levende token (dem, brugeren kunne se under Forbundne apps).
     */
    public static function revokeAll(int $userId, int $now): int
    {
        $ts = Users::ts($now);
        $live = (int) DB::scalar('SELECT COUNT(*) FROM oauth_grants g WHERE g.user_id = ? AND g.revoked_at IS NULL AND EXISTS '
            . '(SELECT 1 FROM oauth_tokens t WHERE t.grant_id = g.id AND t.used_at IS NULL AND t.expires_at > ?)', [$userId, $ts]);
        DB::exec('UPDATE oauth_grants SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$ts, $userId]);
        return $live;
    }

    /**
     * Brugerens forbundne apps: bevillinger, der hverken er tilbagekaldt eller udløbet (har et levende token), nyeste
     * først.
     *
     * @return list<array<string, mixed>> id, client_name, created_at, last_used_at
     */
    public static function active(int $userId, int $now): array
    {
        return DB::all('SELECT g.id, c.client_name, g.created_at, g.last_used_at FROM oauth_grants g JOIN oauth_clients c ON c.id = g.client_pk '
            . 'WHERE g.user_id = ? AND g.revoked_at IS NULL AND EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.grant_id = g.id '
            . 'AND t.used_at IS NULL AND t.expires_at > ?) ORDER BY g.created_at DESC, g.id DESC', [$userId, Users::ts($now)]);
    }

    /**
     * Tilbagekalder en af brugerens EGNE aktive bevillinger og returnerer klientnavnet. null: findes ikke, er en andens
     * eller allerede tilbagekaldt.
     */
    public static function revokeOwn(int $userId, int $grantId, int $now): ?string
    {
        $name = DB::scalar('SELECT c.client_name FROM oauth_grants g JOIN oauth_clients c ON c.id = g.client_pk '
            . 'WHERE g.id = ? AND g.user_id = ? AND g.revoked_at IS NULL', [$grantId, $userId]);
        if (!is_string($name)) {
            return null;
        }
        $n = DB::exec('UPDATE oauth_grants SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [Users::ts($now), $grantId, $userId]);
        return $n === 1 ? $name : null;
    }

    public static function touch(int $grantId, int $now): void
    {
        DB::exec('UPDATE oauth_grants SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [Users::ts($now), $grantId, Users::ts($now - 60)]);
    }
}
