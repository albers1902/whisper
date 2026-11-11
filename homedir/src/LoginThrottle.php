<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Login-begrænsning: 5 fejlede forsøg for samme e-mail ELLER samme IP inden for 15 min låser i 15 min regnet fra
 * det femte forsøg. Afviste forsøg under låsen tælles ikke, så låsen ophører til tiden og ikke kan forlænges i det
 * uendelige. Tider skrives og sammenlignes med PHP's ur, så databasens tidszone er ligegyldig.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;
    private const KEEP_DAYS = 30;

    public static function isLocked(string $email, string $ip, int $now): bool
    {
        $since = Users::ts($now - 2 * self::WINDOW_SECONDS);
        $byEmail = DB::all(
            'SELECT created_at FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= ? ORDER BY created_at, id',
            [self::clip($email, 190), $since]
        );
        if (self::lockedBy($byEmail, $now)) {
            return true;
        }
        if ($ip === '') {
            return false;
        }
        $byIp = DB::all(
            'SELECT created_at FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ? ORDER BY created_at, id',
            [self::clip($ip, 45), $since]
        );
        return self::lockedBy($byIp, $now);
    }

    public static function record(string $email, string $ip, bool $success, int $now): void
    {
        DB::exec(
            'INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?, ?, ?, ?)',
            [self::clip($email, 190), self::clip($ip, 45), $success ? 1 : 0, Users::ts($now)]
        );
    }

    /** Persondata (e-mail, IP) gemmes højst 30 dage (impact F001). */
    public static function purge(int $now): void
    {
        DB::exec('DELETE FROM login_attempts WHERE created_at < ?', [Users::ts($now - self::KEEP_DAYS * 86400)]);
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
