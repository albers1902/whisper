<?php
declare(strict_types=1);

/**
 * Migrations-runner. Kører homedir/migrations/NNNN_*.sql i nummerorden, én gang hver (sporet i _migrations med
 * sha256), og stopper ved første fejl. En kørt fil, der er ændret, stopper alt: migrationer er immutable (LIFECYCLE §6).
 *
 * Frameworkets deploy.sh (coworker.ini [deploy] migrate) kører den FØR koden skiftes, fra hjemmemappen og med den
 * NYE commits migrate.php, src/ og migrationer (ved første udrulning findes ~/cron/migrate.php og ~/src/ endnu ikke):
 *   /usr/bin/php repo/homedir/cron/migrate.php --home=. --dir=repo/homedir/migrations
 * --home = hjemmemappen (config og database). Exit ≠ 0 ved enhver fejl, også manglende config — ellers skifter
 * deploy koden over en database, der ikke passer.
 *
 *   --status   vis kørte og ventende, kør intet
 *   --dry-run  vis hvad der ville køre, kør intet
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "migrate.php må kun køres fra CLI.\n");
    exit(2);
}

$args = array_slice($argv, 1);
$home = dirname(__DIR__);
$dirArg = null;
foreach ($args as $a) {
    if (strncmp($a, '--home=', 7) === 0) {
        $h = realpath(substr($a, 7));
        if ($h === false || !is_dir($h)) {
            fwrite(STDERR, 'migrate.php: --home findes ikke: ' . substr($a, 7) . "\n");
            exit(1);
        }
        $home = $h;
    } elseif (strncmp($a, '--dir=', 6) === 0) {
        $dirArg = substr($a, 6);
    }
}
$status = in_array('--status', $args, true);
$dryRun = in_array('--dry-run', $args, true);

if (!is_readable($home . '/config/config.ini')) {
    fwrite(STDERR, "migrate.php: {$home}/config/config.ini mangler eller kan ikke læses.\n");
    exit(1);
}

define('BASE_DIR', $home);
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\DB;

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'migrate.php: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
});

$migrationsDir = realpath($dirArg ?? (__DIR__ . '/../migrations'));
if ($migrationsDir === false || !is_dir($migrationsDir)) {
    fwrite(STDERR, "migrate.php: migrations-mappen findes ikke.\n");
    exit(1);
}

$pdo = DB::pdo();

// To samtidige kørsler (fx deploy + manuel) må aldrig køre samme migration to gange. Låsen hører til forbindelsen
// og slippes, når processen slutter.
if ((int) DB::scalar("SELECT GET_LOCK('whisper_migrate', 0)") !== 1) {
    fwrite(STDERR, "migrate.php: en anden kørsel er i gang (GET_LOCK whisper_migrate) — stopper.\n");
    exit(1);
}

$hasTable = (int) DB::scalar(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '_migrations'"
) === 1;
if (!$hasTable && !$status && !$dryRun) {   // --status/--dry-run skriver intet, heller ikke tabellen
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS _migrations (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename     VARCHAR(255) NOT NULL UNIQUE,
            checksum     CHAR(64) NOT NULL,
            executed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_ms INT UNSIGNED
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $hasTable = true;
}

$applied = [];
if ($hasTable) {
    foreach (DB::all('SELECT filename, checksum FROM _migrations') as $row) {
        $applied[(string) $row['filename']] = (string) $row['checksum'];
    }
}

$files = glob($migrationsDir . '/[0-9]*.sql') ?: [];
sort($files, SORT_STRING);

// En kørt migration uden fil er en advarsel, ikke en fejl: skemaet findes, men historikken er ufuldstændig
$present = array_flip(array_map('basename', $files));
foreach (array_keys($applied) as $name) {
    if (!isset($present[$name])) {
        fwrite(STDERR, "ADVARSEL: {$name} er kørt (står i _migrations), men filen findes ikke i {$migrationsDir}.\n");
    }
}

$pending = [];
$changed = [];
foreach ($files as $path) {
    $name = basename($path);
    $checksum = hash('sha256', (string) file_get_contents($path));
    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            $changed[] = $name;
        }
        continue;
    }
    $pending[] = ['name' => $name, 'path' => $path, 'checksum' => $checksum];
}

if ($changed !== []) {
    fwrite(STDERR, "FEJL: kørte migrationer er ændret efter kørsel (immutable — lav en ny migration):\n");
    foreach ($changed as $n) {
        fwrite(STDERR, "  - {$n}\n");
    }
    exit(1);
}

if ($status || $dryRun) {
    echo 'Kørte migrationer: ' . count($applied) . "\n";
    echo $pending === [] ? "Ingen ventende migrationer.\n" : 'Ventende (' . count($pending) . "):\n";
    foreach ($pending as $m) {
        echo "  - {$m['name']}\n";
    }
    exit(0);
}

// Flersætningsfiler: exec() melder kun fejl fra første sætning. query() + nextRowset() under ERRMODE_EXCEPTION
// kaster for hver sætning. Emulerede prepares, så hele filen sendes som tekst (server-side prepare tager kun én sætning).
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

$ran = 0;
foreach ($pending as $m) {
    echo "-> {$m['name']} ... ";
    $start = microtime(true);
    try {
        // DDL auto-committer i MariaDB, så en transaktion ville ikke rulle noget tilbage: stop ved første fejl
        $st = $pdo->query((string) file_get_contents($m['path']));
        if ($st !== false) {
            do {
                // hver sætnings resultat hentes; en fejl i en senere sætning kaster her
            } while ($st->nextRowset());
            $st->closeCursor();
        }
    } catch (\Throwable $e) {
        echo "FEJL\n";
        fwrite(STDERR, "Migration {$m['name']} fejlede: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Stoppede efter {$ran} migration(er).\n");
        exit(1);
    }
    $ms = (int) round((microtime(true) - $start) * 1000);
    DB::exec('INSERT INTO _migrations (filename, checksum, execution_ms) VALUES (?, ?, ?)',
        [$m['name'], $m['checksum'], $ms]);
    echo "OK ({$ms} ms)\n";
    $ran++;
}

echo $ran === 0 ? "Ingen ventende migrationer.\n" : "Færdig — {$ran} migration(er) kørt.\n";
exit(0);
