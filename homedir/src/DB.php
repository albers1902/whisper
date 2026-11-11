<?php
declare(strict_types=1);

namespace Whisper;

use PDO;
use RuntimeException;

/** Én PDO-forbindelse pr. proces, altid med parametre (aldrig værdier sat ind i SQL-strengen). */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $name = Config::string('database', 'name');
            if ($name === '') {
                throw new RuntimeException('database er ikke konfigureret i config.ini');
            }
            $host = Config::string('database', 'host', 'localhost');
            $port = (int) Config::string('database', 'port', '3306');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port > 0 ? $port : 3306, $name);
            self::$pdo = new PDO($dsn, Config::string('database', 'user'), Config::string('database', 'pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,   // en hængende database må ikke holde /health til PHP's timeout
            ]);
        }
        return self::$pdo;
    }

    /**
     * Ny forbindelse, hvis den gamle er lukket af serveren (wait_timeout) — fx efter en transcription, der har kørt i
     * timer (F002).
     */
    public static function ensureAlive(): void
    {
        if (self::$pdo === null) {
            return;
        }
        try {
            @self::$pdo->query('SELECT 1');   // en lukket forbindelse giver også en PHP-advarsel
        } catch (\Throwable) {
            self::$pdo = null;
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /** @param list<mixed> $params */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param list<mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
