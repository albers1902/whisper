<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Læser <hjemmemappe>/config/config.ini (ikke i git; hemmeligheder kun her, LIFECYCLE §8).
 * En manglende fil giver en tom config — DB::pdo() fejler så, og /health svarer "fail" i stedet for 500.
 */
final class Config
{
    private static string $baseDir = '';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public static function setBaseDir(string $baseDir): void
    {
        self::$baseDir = $baseDir;
        self::$data = null;
    }

    public static function baseDir(): string
    {
        return self::$baseDir !== '' ? self::$baseDir : dirname(__DIR__);
    }

    /** @return array<string, mixed> */
    public static function section(string $name): array
    {
        $section = self::all()[$name] ?? [];
        return is_array($section) ? $section : [];
    }

    public static function string(string $section, string $key, string $default = ''): string
    {
        $v = self::section($section)[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @return array<string, mixed> */
    private static function all(): array
    {
        if (self::$data === null) {
            $file = self::baseDir() . '/config/config.ini';
            $ini = is_readable($file) ? parse_ini_file($file, true, INI_SCANNER_RAW) : false;
            self::$data = is_array($ini) ? $ini : [];
        }
        return self::$data;
    }
}
