<?php
declare(strict_types=1);

namespace Whisper;

final class Password
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 72;

    /** Uden 0/O, 1/l/I, så engangskoden kan læses højt eller skrives af. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** Fejlbesked på dansk, eller null hvis adgangskoden er gyldig. Kravet er kun længden (spec F001). */
    public static function problem(string $password): ?string
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            return 'Adgangskoden skal være mindst ' . self::MIN_LENGTH . ' tegn.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            // bcrypt bruger kun de første 72 byte; resten ville stille blive ignoreret
            return 'Adgangskoden må højst være 72 byte (72 tegn, men æ, ø og å tæller som 2).';
        }
        if (str_contains($password, "\0")) {
            return 'Adgangskoden indeholder et ugyldigt tegn.';   // bcrypt afviser NUL-tegn
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** Tilfældig engangskode fra random_bytes med rejection sampling, så alle tegn er lige sandsynlige. */
    public static function generate(int $length = 16): string
    {
        $n = strlen(self::ALPHABET);
        $limit = 256 - (256 % $n);
        $out = '';
        while (strlen($out) < $length) {
            foreach (str_split(random_bytes(32)) as $byte) {
                $b = ord($byte);
                if ($b < $limit) {
                    $out .= self::ALPHABET[$b % $n];
                    if (strlen($out) === $length) {
                        break;
                    }
                }
            }
        }
        return $out;
    }
}
