<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Læser en adgangskode fra stdin til CLI (cron/opret-admin.php). Fra en pipe læses én linje. I en terminal slås
 * ekkoet fra med stty; kan det ikke lade sig gøre (exec spærret i disable_functions, stty mangler), afvises
 * indtastningen, så adgangskoden aldrig står synligt på skærmen.
 */
final class SecretInput
{
    public const NO_ECHO_MESSAGE = 'Kan ikke slå ekko fra i terminalen. Pipe adgangskoden ind via stdin i stedet, fx: '
        . 'read -rs P; printf \'%s\n\' "$P" | php opret-admin.php --home=. --email=... --name=...; unset P';

    /**
     * @param resource                $in     stdin
     * @param callable(string): bool  $stty   kører "stty <mode>" og returnerer, om det lykkedes
     * @param callable(string): void  $prompt skriver en tekst til brugeren (stderr)
     */
    public static function read($in, bool $isTty, callable $stty, callable $prompt, string $text): string
    {
        if (!$isTty) {
            $line = fgets($in);
            return $line === false ? '' : rtrim($line, "\r\n");
        }
        if (!$stty('-echo')) {
            throw new UserError(self::NO_ECHO_MESSAGE);
        }
        try {
            $prompt($text);
            $line = fgets($in);
        } finally {
            $stty('echo');
            $prompt("\n");
        }
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /** Den rigtige stty: kun når exec findes, og kun med faste argumenter (intet brugerinput i kommandoen). */
    public static function systemStty(string $mode): bool
    {
        if (!in_array($mode, ['-echo', 'echo'], true) || !function_exists('exec')) {
            return false;
        }
        $out = [];
        $rc = 1;
        $r = @exec('stty ' . $mode . ' 2>/dev/null', $out, $rc);
        return $r !== false && $rc === 0;
    }
}
