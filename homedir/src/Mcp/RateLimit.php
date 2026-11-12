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
    /** Tæl, læs, ryd op — én fast tekst pr. tabel (ingen tabelnavne sat ind i SQL). */
    private const SQL_MCP = [
        'INSERT INTO mcp_rate (token_id, window_start, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1',
        'SELECT `count` FROM mcp_rate WHERE token_id = ? AND window_start = ?',
        'DELETE FROM mcp_rate WHERE window_start < ?',
    ];
    private const SQL_OAUTH = [
        'INSERT INTO oauth_rate (grant_id, window_start, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1',
        'SELECT `count` FROM oauth_rate WHERE grant_id = ? AND window_start = ?',
        'DELETE FROM oauth_rate WHERE window_start < ?',
    ];

    /**
     * 0: tilladt. Ellers antal sekunder til næste vindue (til Retry-After). $oauth: $tokenId er en OAuth-bevilling
     * (F006, tabellen oauth_rate) i stedet for en F003-nøgle.
     */
    public static function hit(int $tokenId, int $now, bool $oauth = false): int
    {
        $sql = $oauth ? self::SQL_OAUTH : self::SQL_MCP;
        $window = $now - $now % self::WINDOW;
        DB::exec($sql[0], [$tokenId, $window]);
        $count = (int) DB::scalar($sql[1], [$tokenId, $window]);
        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            DB::exec($sql[2], [$window - self::KEEP_SECONDS]);
        }
        return $count > self::LIMIT ? max(1, $window + self::WINDOW - $now) : 0;
    }
}
