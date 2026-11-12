<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H010-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på H005's falske python
 * og F002's database/var-hjælpere.
 */

require_once __DIR__ . '/h005_lib.php';

final class H010Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H010-$ak $msg\n";
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

/**
 * Advarsler samles i $into.
 *
 * @param list<string> $into
 * @return Closure(string): void
 */
function h010_collect(array &$into): Closure
{
    return static function (string $w) use (&$into): void {
        $into[] = $w;
    };
}
