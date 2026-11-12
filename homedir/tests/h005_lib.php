<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H005-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's hjælpere
 * (database, falsk motor, var/ i en midlertidig mappe).
 */

require_once __DIR__ . '/f002_lib.php';

final class H005Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H005-$ak $msg\n";
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
 * Lægger en falsk "python" i var/whisper/venv/bin, der skriver sine argumenter (én pr. linje) i $argvFile og svarer
 * med gyldig motor-JSON, og markerer turbo som klar. Kun på Unix (sh).
 */
function h005_fake_python(string $var, string $argvFile): void
{
    $py = \Whisper\Paths::dir('whisper/venv/bin') . '/python';
    file_put_contents($py, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '" . $argvFile . "'\n"
        . "echo '{\"text\":\"ok\",\"segments\":[{\"start\":0,\"end\":1,\"text\":\"ok\"}],\"duration\":1,\"model\":\"turbo\",\"language\":\"da\"}'\n");
    chmod($py, 0700);
    file_put_contents($var . '/whisper/ready-turbo', 'x');
}

/** @return list<string> */
function h005_argv(string $argvFile): array
{
    $raw = is_file($argvFile) ? (string) file_get_contents($argvFile) : '';
    return $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
}

/** Værdien efter et flag i argv, eller null. @param list<string> $argv */
function h005_opt(array $argv, string $flag): ?string
{
    $i = array_search($flag, $argv, true);
    return is_int($i) && isset($argv[$i + 1]) ? $argv[$i + 1] : null;
}
