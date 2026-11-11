<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Login-begrænsning: 5 fejlede forsøg for samme e-mail ELLER samme IP (IPv6: samme /64) inden for 15 min låser i
 * 15 min regnet fra det femte forsøg. Tider skrives og sammenlignes med PHP's ur, så databasens tidszone er ligegyldig.
 *
 * Mod parallelle requests: begin() skriver forsøget som fejl FØR den dyre bcrypt-kontrol og tæller kun forsøg med
 * lavere id. Forsøg, der er i gang, tæller derfor med, og en byge af samtidige requests får højst 5 igennem.
 * Et afvist forsøg under låsen slettes igen, så låsen ophører til tiden og ikke kan forlænges i det uendelige.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;
    private const KEEP_DAYS = 30;
    private const PURGE_ONE_IN = 50;

    /** Id på det registrerede forsøg (som fejl), eller null hvis e-mail eller IP er låst. */
    public static function begin(string $email, string $ip, int $now): ?int
    {
        $id = self::record($email, $ip, false, $now);
        if (self::isLocked($email, $ip, $now, $id)) {
            DB::exec('DELETE FROM login_attempts WHERE id = ?', [$id]);
            return null;
        }
        return $id;
    }

    public static function succeed(int $id): void
    {
        DB::exec('UPDATE login_attempts SET success = 1 WHERE id = ?', [$id]);
    }

    /** @param int $beforeId tæl kun forsøg registreret før dette id (begin() tæller ikke sig selv) */
    public static function isLocked(string $email, string $ip, int $now, int $beforeId = PHP_INT_MAX): bool
    {
        $since = Users::ts($now - 2 * self::WINDOW_SECONDS);
        $byEmail = DB::all(
            'SELECT created_at FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= ? AND id < ? ORDER BY created_at, id',
            [self::clip($email, 190), $since, $beforeId]
        );
        if (self::lockedBy($byEmail, $now)) {
            return true;
        }
        $ip = self::normalizeIp($ip);
        if ($ip === '') {
            return false;
        }
        $byIp = DB::all(
            'SELECT created_at FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ? AND id < ? ORDER BY created_at, id',
            [$ip, $since, $beforeId]
        );
        return self::lockedBy($byIp, $now);
    }

    public static function record(string $email, string $ip, bool $success, int $now): int
    {
        DB::exec(
            'INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?, ?, ?, ?)',
            [self::clip($email, 190), self::normalizeIp($ip), $success ? 1 : 0, Users::ts($now)]
        );
        return (int) DB::pdo()->lastInsertId();
    }

    /**
     * IPv6 grupperes på /64 (én kunde har typisk et helt /64), så en angriber ikke får en ny "IP" pr. request.
     * IPv4 og IPv4-mappede IPv6-adresser gemmes som IPv4.
     */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return self::clip($ip, 45);
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return self::clip($ip, 45);
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Persondata (e-mail, IP) gemmes højst 30 dage (impact F001). */
    public static function purge(int $now): void
    {
        DB::exec('DELETE FROM login_attempts WHERE created_at < ?', [Users::ts($now - self::KEEP_DAYS * 86400)]);
    }

    /** Oprydning ved ca. hvert 50. login-forsøg i stedet for hvert (indeks på created_at, migration 0002). */
    public static function maybePurge(int $now): void
    {
        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            self::purge($now);
        }
    }

    /**
     * Låst, hvis 5 fejl ligger inden for 15 min, og den femte af dem er under 15 min gammel.
     *
     * @param list<array<string, mixed>> $rows sorteret ældst først
     */
    private static function lockedBy(array $rows, int $now): bool
    {
        $t = [];
        foreach ($rows as $r) {
            $ts = strtotime((string) $r['created_at']);
            if ($ts !== false) {
                $t[] = $ts;
            }
        }
        $last = count($t) - self::MAX_FAILURES;
        for ($i = 0; $i <= $last; $i++) {
            $fifth = $t[$i + self::MAX_FAILURES - 1];
            if ($fifth - $t[$i] <= self::WINDOW_SECONDS && $now - $fifth < self::WINDOW_SECONDS) {
                return true;
            }
        }
        return false;
    }

    /** Kolonnerne er VARCHAR(190)/(45); et langt input må ikke give en databasefejl i stedet for et afslag. */
    private static function clip(string $s, int $max): string
    {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
}
