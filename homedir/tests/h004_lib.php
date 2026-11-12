<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H004-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F003's hjælpere
 * (database, MCP-klient, login), som igen bygger på F002 og F001.
 */

require_once __DIR__ . '/f003_lib.php';

final class H004Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H004-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}
