<?php
declare(strict_types=1);

namespace Whisper\Mcp;

use Whisper\DB;

/**
 * Højst LIMIT requests pr. nøgle pr. minut (F003). Requesten tælles FØRST (atomisk INSERT … ON DUPLICATE KEY UPDATE)
 * og tjekkes bagefter, så parallelle requests ikke kan smutte forbi tjekket (LEARNINGS F001). En tabel frem for en fil:
 * tællingen er atomisk på tværs af FPM-processer uden låsefiler.
 */
final class RateLimit
{
    public const LIMIT = 60;
    private const WINDOW = 60;
    private const KEEP_SECONDS = 3600;
    private const PURGE_ONE_IN = 50;

    /** 0: tilladt. Ellers antal sekunder til næste vindue (til Retry-After). */
    public static function hit(int $tokenId, int $now): int
    {
        $window = $now - $now % self::WINDOW;
        DB::exec('INSERT INTO mcp_rate (token_id, window_start, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1',
            [$tokenId, $window]);
        $count = (int) DB::scalar('SELECT `count` FROM mcp_rate WHERE token_id = ? AND window_start = ?', [$tokenId, $window]);
        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            DB::exec('DELETE FROM mcp_rate WHERE window_start < ?', [$window - self::KEEP_SECONDS]);
        }
        return $count > self::LIMIT ? max(1, $window + self::WINDOW - $now) : 0;
    }
}
