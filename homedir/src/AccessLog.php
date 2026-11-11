<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Sporbarhed for udlevering af persondata (security S4): én linje pr. hentning af lyd, transcript (.txt) og zip i
 * logs/adgang.log — tid, bruger-id, handling og opkalds-id (zip: antal og en hash af filtret). Ingen navne,
 * telefonnumre, fritekst eller stier. En fejl i loggen stopper aldrig svaret.
 */
final class AccessLog
{
    private static ?string $file = null;

    /** Kun til tests. */
    public static function setFile(?string $file): void
    {
        self::$file = $file;
    }

    public static function file(): string
    {
        return self::$file ?? Config::baseDir() . '/logs/adgang.log';
    }

    /** @param array<string, int|string> $fields kun id'er, tal og hashes */
    public static function write(int $userId, string $action, array $fields): void
    {
        // _ er tilladt i handlingen (F003: mcp_search_calls); mellemrum og alt andet fjernes stadig
        $line = gmdate('Y-m-d\TH:i:s\Z') . ' bruger=' . $userId . ' ' . preg_replace('/[^a-z_]/', '', $action);
        foreach ($fields as $k => $v) {
            $line .= ' ' . preg_replace('/[^a-z_]/', '', $k) . '=' . preg_replace('/[^0-9a-f]/', '', strtolower((string) $v));
        }
        $old = umask(077);
        try {
            @file_put_contents(self::file(), $line . "\n", FILE_APPEND | LOCK_EX);
        } finally {
            umask($old);
        }
    }
}
