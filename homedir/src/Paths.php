<?php
declare(strict_types=1);

namespace Whisper;

use RuntimeException;

/**
 * Projektets runtime-mappe <hjemmemappe>/var (SERVERKONTRAKT §2: projektets, i open_basedir, røres aldrig af deploy).
 * Optagelser, token-cache, låse og hjertebanken ligger her med 0700/0600. Tests peger den på en midlertidig mappe.
 */
final class Paths
{
    private static ?string $varDir = null;

    public static function setVarDir(?string $dir): void
    {
        self::$varDir = $dir;
    }

    public static function varDir(): string
    {
        if (self::$varDir === null && getenv('WHISPER_TEST') === '1' && (string) getenv('WHISPER_VAR_DIR') !== '') {
            // Kun under testløberen (run.php sætter WHISPER_TEST): cron-scripts startet af en test skriver i testens mappe
            return (string) getenv('WHISPER_VAR_DIR');
        }
        return self::$varDir ?? Config::baseDir() . '/var';
    }

    /** var/<rel> som mappe med 0700 (oprettes ved behov). */
    public static function dir(string $rel): string
    {
        $dir = self::varDir() . ($rel === '' ? '' : '/' . $rel);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('kan ikke oprette en mappe under var/');
        }
        return $dir;
    }

    /** Skriver en fil atomisk med 0600 (midlertidig fil i samme mappe + rename), så en læser aldrig ser en halv fil. */
    public static function writePrivate(string $file, string $content): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(077);
        try {
            if (file_put_contents($tmp, $content) !== strlen($content)) {
                @unlink($tmp);
                throw new RuntimeException('kunne ikke skrive en fil under var/');
            }
        } finally {
            umask($old);
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('kunne ikke skrive en fil under var/');
        }
    }

    /** Hjertebanken for et cron-job (SERVERKONTRAKT §5): var/heartbeat/<job>. */
    public static function heartbeat(string $job): void
    {
        @touch(self::dir('heartbeat') . '/' . $job);
    }
}
