<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Feature flags fra <hjemmemappe>/config/features.ini (LIFECYCLE §5) for miljøet i config.ini [app] env.
 * deploy.sh giver ikke miljøet videre til appen, så det står i hvert miljøs config.ini. Mangler det eller er det
 * ukendt, er alle flag slukket, så en server uden env aldrig viser ufærdig adfærd.
 */
final class Flags
{
    /** @var array<string, bool> kun til tests (et testmiljø har env = test, hvor flagene er off) */
    private static array $overrides = [];

    public static function enabled(string $flag): bool
    {
        if (array_key_exists($flag, self::$overrides)) {
            return self::$overrides[$flag];
        }
        if (self::testHook($flag, PHP_SAPI)) {
            return true;
        }
        return self::enabledIn($flag, self::env());
    }

    /**
     * Kun under testløberen (run.php sætter WHISPER_TEST) og kun i CLI: et cron-script, som en test starter i en ny
     * proces, kan få flag tændt via WHISPER_TEST_FLAGS (F002-AK2). Et miljø i PHP-FPM kan aldrig tænde et flag
     * (DECISIONS #17). $sapi er en parameter, så testen kan vise, at andre SAPI'er ignorerer hooken.
     */
    public static function testHook(string $flag, string $sapi): bool
    {
        return $sapi === 'cli' && getenv('WHISPER_TEST') === '1'
            && in_array($flag, explode(',', (string) getenv('WHISPER_TEST_FLAGS')), true);
    }

    public static function enabledIn(string $flag, string $env): bool
    {
        if (!in_array($env, ['dev', 'test', 'stable'], true)) {
            return false;
        }
        $file = Config::baseDir() . '/config/features.ini';
        $ini = is_readable($file) ? parse_ini_file($file, true, INI_SCANNER_RAW) : false;
        if (!is_array($ini) || !isset($ini[$flag]) || !is_array($ini[$flag])) {
            return false;
        }
        $v = $ini[$flag][$env] ?? '';
        return is_string($v) && strtolower(trim($v)) === 'on';
    }

    /** dev, test, stable eller '' (ukendt). chatio skriver development/production, så de accepteres også. */
    public static function env(): string
    {
        return self::normalizeEnv(Config::string('app', 'env'));
    }

    public static function normalizeEnv(string $env): string
    {
        $e = strtolower(trim($env));
        $e = ['development' => 'dev', 'production' => 'stable'][$e] ?? $e;
        return in_array($e, ['dev', 'test', 'stable'], true) ? $e : '';
    }

    public static function override(string $flag, ?bool $on): void
    {
        if ($on === null) {
            unset(self::$overrides[$flag]);
        } else {
            self::$overrides[$flag] = $on;
        }
    }
}
