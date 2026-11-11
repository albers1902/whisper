<?php
declare(strict_types=1);

namespace Whisper;

use PDO;
use Throwable;

/**
 * Svaret bag /health (LIFECYCLE §8), som deploy.sh og monitor.sh læser.
 *
 * Felterne er frameworkets kontrakt: sha, status, db_read, db_write, backup_last_ok, restore_last_ok,
 * restore_rto_min, last_incident. Svaret er offentligt, så det indeholder aldrig fejltekster, stier,
 * databasenavne eller hændelsestekster — kun "ok"/"fail", datoer og et tal.
 */
final class Health
{
    /**
     * @param callable():PDO $pdo  giver forbindelsen (kaldes inde i try, så en nede database bliver "fail")
     * @return array{sha: string, status: string, db_read: string, db_write: string, backup_last_ok: ?string,
     *               restore_last_ok: ?string, restore_rto_min: ?int, last_incident: ?string}
     */
    public static function report(string $baseDir, callable $pdo): array
    {
        $dbRead = 'fail';
        $dbWrite = 'fail';
        try {
            $conn = $pdo();
            $stmt = $conn->query('SELECT 1');
            $dbRead = ($stmt !== false && (int) $stmt->fetchColumn() === 1) ? 'ok' : 'fail';
            $dbWrite = self::probeWrite($conn) ? 'ok' : 'fail';
        } catch (Throwable $e) {
            // Fejlen må aldrig stå i det offentlige svar; serverens fejllog får den
            error_log('health: db-tjek fejlede: ' . $e->getMessage());
        }

        $restore = self::firstLine($baseDir . '/logs/restore_last_ok');
        $restoreParts = $restore === null ? [] : (preg_split('/\s+/', $restore) ?: []);

        return [
            'sha'             => self::deployedSha($baseDir),
            'status'          => ($dbRead === 'ok' && $dbWrite === 'ok') ? 'ok' : 'degraded',
            'db_read'         => $dbRead,
            'db_write'        => $dbWrite,
            'backup_last_ok'  => self::dateOf(self::firstLine($baseDir . '/logs/backup_last_ok')),
            'restore_last_ok' => self::dateOf($restoreParts[0] ?? null),
            'restore_rto_min' => isset($restoreParts[1]) && ctype_digit($restoreParts[1]) ? (int) $restoreParts[1] : null,
            'last_incident'   => self::lastIncident($baseDir . '/logs/incidents.log'),
        ];
    }

    /**
     * Skriver i health_probe (migration 0001) og ruller tilbage: fejler, hvis databasen er read-only eller fuld,
     * men efterlader intet.
     */
    private static function probeWrite(PDO $conn): bool
    {
        // /health er offentlig og tager rækkelåsen på id 1: samtidige kald skal fejle hurtigt, ikke køe i 50 s
        $conn->exec('SET SESSION innodb_lock_wait_timeout = 3');
        $conn->beginTransaction();
        try {
            $conn->exec('INSERT INTO health_probe (id, beat_at) VALUES (1, NOW(6))
                         ON DUPLICATE KEY UPDATE beat_at = VALUES(beat_at)');
            return true;
        } finally {
            $conn->rollBack();
        }
    }

    /**
     * deploy.sh skriver config/deployed_sha.php: <?php return '<sha>'; — filen læses som tekst, ikke med include,
     * så /health aldrig kører kode fra config/.
     */
    private static function deployedSha(string $baseDir): string
    {
        $f = $baseDir . '/config/deployed_sha.php';
        $txt = is_file($f) && is_readable($f) ? (string) file_get_contents($f, false, null, 0, 256) : '';
        return preg_match("/return\\s+'([0-9a-f]{7,40})'/", $txt, $m) === 1 ? $m[1] : 'unknown';
    }

    private static function firstLine(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        $line = fgets($fh);
        fclose($fh);
        $line = $line === false ? '' : trim($line);
        return $line === '' ? null : $line;
    }

    private static function dateOf(?string $s): ?string
    {
        return ($s !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) ? $s : null;
    }

    /** Kun tidsstemplet fra sidste linje ("<ISO-tid>|<mode>|<tekst>") — teksten kan nævne interne navne. */
    private static function lastIncident(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        fseek($fh, max(0, $size - 4096));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $tail)), static fn(string $l): bool => $l !== ''));
        if ($lines === []) {
            return null;
        }
        $ts = explode('|', $lines[count($lines) - 1], 2)[0];
        return preg_match('/^\d{4}-\d{2}-\d{2}(T[0-9:+\-.Z]+)?$/', $ts) === 1 ? $ts : null;
    }
}
