<?php
declare(strict_types=1);

namespace Whisper;

use PDOException;
use Throwable;
use Whisper\Mcp\Tokens;
use Whisper\OAuth\Grants;
use Whisper\OAuth\OAuth;

/**
 * Tabellen users (migration 0002). Al validering, der kan nå brugeren, kaster UserError med en dansk besked.
 * Adgangskoder findes kun som password_hash; engangskoder returneres til visning én gang og gemmes aldrig.
 */
final class Users
{
    public const ROLES = ['admin', 'user'];

    /** E-mail gemmes trimmet og med små bogstaver; kolonnen er desuden unik uden forskel på store og små (unicode_ci). */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $rows = DB::all('SELECT * FROM users WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        $rows = DB::all('SELECT * FROM users WHERE email = ?', [self::normalizeEmail($email)]);
        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return DB::all('SELECT id, email, name, role, active, must_change_password, last_login_at FROM users ORDER BY name, email');
    }

    /**
     * Opretter en bruger og returnerer id. Kaster UserError ved ugyldige felter eller en e-mail, der findes.
     */
    public static function create(string $email, string $name, string $role, string $password, bool $mustChange, int $now): int
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UserError('Skriv en gyldig e-mailadresse.');
        }
        $name = self::validName($name);
        self::validRole($role);
        $problem = Password::problem($password);
        if ($problem !== null) {
            throw new UserError($problem);
        }
        if (self::findByEmail($email) !== null) {
            throw new UserError('Der findes allerede en bruger med den e-mail.');
        }
        $ts = self::ts($now);
        try {
            DB::exec(
                'INSERT INTO users (email, name, role, password_hash, must_change_password, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                [$email, $name, $role, Password::hash($password), $mustChange ? 1 : 0, $ts, $ts]
            );
        } catch (PDOException $e) {
            // To samtidige oprettelser med samme e-mail: det unikke indeks afgør det
            if ((string) $e->getCode() === '23000') {
                throw new UserError('Der findes allerede en bruger med den e-mail.');
            }
            throw $e;
        }
        return (int) DB::pdo()->lastInsertId();
    }

    /** Ændrer navn og rolle. Den sidste aktive administrator kan ikke nedgraderes. */
    public static function update(int $id, string $name, string $role, int $now): void
    {
        $name = self::validName($name);
        self::validRole($role);
        self::locked($id, static function (array $user, int $activeAdmins) use ($id, $name, $role, $now): void {
            if ($user['role'] === 'admin' && $role !== 'admin' && (int) $user['active'] === 1 && $activeAdmins <= 1) {
                throw new UserError('Den sidste aktive administrator kan ikke nedgraderes.');
            }
            DB::exec('UPDATE users SET name = ?, role = ?, updated_at = ? WHERE id = ?', [$name, $role, self::ts($now), $id]);
        });
    }

    /** Deaktiverer eller genaktiverer. En admin kan ikke deaktivere sig selv eller den sidste aktive admin. */
    public static function setActive(int $id, bool $active, int $actorId, int $now): void
    {
        if (!$active && $id === $actorId) {
            throw new UserError('Du kan ikke deaktivere dig selv.');
        }
        self::locked($id, static function (array $user, int $activeAdmins) use ($id, $active, $now): void {
            if (!$active && $user['role'] === 'admin' && (int) $user['active'] === 1 && $activeAdmins <= 1) {
                throw new UserError('Den sidste aktive administrator kan ikke deaktiveres.');
            }
            DB::exec('UPDATE users SET active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, self::ts($now), $id]);
            if (!$active) {
                // F006: en fjernet bruger mister sine forbundne apps for altid, også efter en genaktivering
                Grants::revokeAll($id, $now);
            }
        });
    }

    /**
     * Ny engangskode (vises én gang af kalderen). Ændret hash logger også brugerens åbne sessioner ud (App), og
     * setPassword tilbagekalder MCP-nøglerne.
     *
     * @return array{password: string, revoked: int}
     */
    public static function resetPassword(int $id, int $now): array
    {
        $password = Password::generate();
        $revoked = self::setPassword($id, $password, true, $now);
        return ['password' => $password, 'revoked' => $revoked];
    }

    /**
     * Ny adgangskode og tilbagekald af alle brugerens aktive MCP-nøgler i samme transaktion (DECISIONS #24): en nøgle
     * må ikke overleve, at adgangskoden er skiftet, fordi kontoen kan have været kompromitteret. Returnerer antallet af
     * tilbagekaldte nøgler.
     */
    public static function setPassword(int $id, string $password, bool $mustChange, int $now): int
    {
        $problem = Password::problem($password);
        if ($problem !== null) {
            throw new UserError($problem);
        }
        $hash = Password::hash($password);
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec(
                'UPDATE users SET password_hash = ?, must_change_password = ?, updated_at = ? WHERE id = ?',
                [$hash, $mustChange ? 1 : 0, self::ts($now), $id]
            );
            // F006: også forbundne apps (OAuth-bevillinger) — uanset flaget, så en tændt OAuth aldrig arver en gammel kæde.
            // Antallet, der returneres (til beskeden), er det, brugeren kan se: apps med OAuth tændt, ellers nøgler.
            $keys = Tokens::revokeAll($id, $now);
            $apps = Grants::revokeAll($id, $now);
            $revoked = OAuth::enabled() ? $apps : $keys;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $revoked;
    }

    /** Ved login: sidste login og evt. en ny hash, når PASSWORD_DEFAULT eller dens cost er ændret. */
    public static function touchLogin(int $id, string $password, string $hash, int $now): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password_hash = ?, last_login_at = ? WHERE id = ?', [Password::hash($password), self::ts($now), $id]);
        } else {
            DB::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [self::ts($now), $id]);
        }
    }

    /**
     * Et fingeraftryk af hashen gemmes i sessionen. Ændres adgangskoden (nulstilling eller skift), passer det ikke
     * længere, og alle andre åbne sessioner for brugeren afvises ved næste request.
     *
     * @param array<string, mixed> $user
     */
    public static function fingerprint(array $user): string
    {
        return substr(hash('sha256', (string) ($user['password_hash'] ?? '')), 0, 32);
    }

    public static function ts(int $now): string
    {
        return date('Y-m-d H:i:s', $now);
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 1 || $len > 120) {
            throw new UserError('Navnet skal være mellem 1 og 120 tegn.');
        }
        return $name;
    }

    private static function validRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new UserError('Ukendt rolle.');
        }
    }

    /**
     * Kører $fn i en transaktion med låse på brugeren og alle aktive administratorer (SELECT … FOR UPDATE), så to
     * admins, der samtidig deaktiverer eller nedgraderer hinanden, ikke kan efterlade nul aktive administratorer:
     * den anden venter på den første og ser derefter det opdaterede antal.
     *
     * @param callable(array<string, mixed>, int): void $fn
     */
    private static function locked(int $id, callable $fn): void
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $admins = DB::all("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id FOR UPDATE");
            $rows = DB::all('SELECT * FROM users WHERE id = ? FOR UPDATE', [$id]);
            if ($rows === []) {
                throw new UserError('Brugeren findes ikke.');
            }
            $fn($rows[0], count($admins));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
