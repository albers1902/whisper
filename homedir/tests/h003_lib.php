<?php
declare(strict_types=1);

// H003: fælles for testene af låsefilen og whisper-setup.sh. Python kan ikke køres i CI, så testene læser filerne.

namespace Whisper\Tests;

final class H003Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H003-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(string $ak): never
    {
        echo self::$fails === 0 ? "H003-$ak: alle tjek bestået\n" : "H003-$ak: " . self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

function h003_lock_path(): string
{
    return dirname(__DIR__) . '/cron/whisper-requirements.txt';
}

function h003_setup_path(): string
{
    return dirname(__DIR__) . '/cron/whisper-setup.sh';
}

/** @return list<string> låsefilens krav-linjer (uden kommentarer og tomme linjer) */
function h003_lock_lines(): array
{
    $raw = is_file(h003_lock_path()) ? (string) file_get_contents(h003_lock_path()) : '';
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') {
            $out[] = $l;
        }
    }
    return $out;
}

/** @return array<string, string> normaliseret pakkenavn => version */
function h003_lock_versions(): array
{
    $v = [];
    foreach (h003_lock_lines() as $l) {
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]*)==(\S+)/', $l, $m) === 1) {
            $v[strtolower((string) preg_replace('/[._-]+/', '-', $m[1]))] = $m[2];
        }
    }
    return $v;
}
