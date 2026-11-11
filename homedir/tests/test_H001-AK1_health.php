<?php
declare(strict_types=1);

// H001-AK1: /health svarer med alle felter fra LIFECYCLE §8, beviser db_read/db_write mod den rigtige (test)database,
// efterlader intet efter skriveprøven og viser aldrig hemmeligheder. Køres: php homedir/tests/run.php

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Config;
use Whisper\DB;
use Whisper\Health;

$fails = 0;
$check = static function (bool $cond, string $msg) use (&$fails): void {
    echo ($cond ? 'ok:   ' : 'FAIL: ') . "H001-AK1 $msg\n";
    if (!$cond) {
        $fails++;
    }
};

$fields = ['sha', 'status', 'db_read', 'db_write', 'backup_last_ok', 'restore_last_ok', 'restore_rto_min', 'last_incident'];

// Midlertidig hjemmemappe med frameworkets beviser
$base = sys_get_temp_dir() . '/whisper_health_' . bin2hex(random_bytes(4));
mkdir($base . '/config', 0700, true);
mkdir($base . '/logs', 0700, true);
$sha = str_repeat('a1b2c3d4e5', 4);
file_put_contents($base . '/config/deployed_sha.php', "<?php return '$sha';\n");
file_put_contents($base . '/logs/backup_last_ok', "2026-11-10\n");
file_put_contents($base . '/logs/restore_last_ok', "2026-11-09 7\n");
$secretText = 'HEMMELIG-db-navn-og-sti /home/whisper/config/config.ini pass=xyz';
file_put_contents($base . '/logs/incidents.log',
    "2026-11-01T10:00:00+01:00|stable|gammel\n2026-11-08T03:04:05+01:00|stable|$secretText\n");

// 1) Med testdatabasen: alle felter, ok/ok, beviserne læst
$r = Health::report($base, static fn(): PDO => DB::pdo());
foreach ($fields as $f) {
    $check(array_key_exists($f, $r), "felt $f findes");
}
$check(count($r) === count($fields), 'ingen ekstra felter (' . implode(',', array_keys($r)) . ')');
$check($r['sha'] === $sha, 'sha fra config/deployed_sha.php');
$check($r['db_read'] === 'ok', 'db_read = ok');
$check($r['db_write'] === 'ok', 'db_write = ok');
$check($r['status'] === 'ok', 'status = ok');
$check($r['backup_last_ok'] === '2026-11-10', 'backup_last_ok');
$check($r['restore_last_ok'] === '2026-11-09', 'restore_last_ok');
$check($r['restore_rto_min'] === 7, 'restore_rto_min');
$check($r['last_incident'] === '2026-11-08T03:04:05+01:00', 'last_incident = kun tidsstemplet');
$json = (string) json_encode($r, JSON_UNESCAPED_SLASHES);
$check(!str_contains($json, 'HEMMELIG') && !str_contains($json, 'config.ini') && !str_contains($json, 'pass'),
    'hændelsens tekst vises ikke');
foreach (['name', 'user', 'pass', 'host'] as $k) {
    $v = Config::string('database', $k);
    $check($v === '' || strlen($v) < 4 || !str_contains($json, $v), "database.$k vises ikke");
}
$check(preg_match('/"db_read": *"ok"/', $json) === 1 && preg_match('/"db_write": *"ok"/', $json) === 1,
    'deploy.sh kan læse db_read/db_write (grep "db_read": *"ok")');

// 2) Skriveprøven efterlader intet
$check((int) DB::scalar('SELECT COUNT(*) FROM health_probe') === 0, 'skriveprøven er rullet tilbage');

// 3) Nede database: degraded, ingen fejltekst, intet 500
$down = Health::report($base, static function (): PDO {
    throw new RuntimeException('SQLSTATE[HY000] HEMMELIG forbindelse');
});
$check($down['db_read'] === 'fail' && $down['db_write'] === 'fail' && $down['status'] === 'degraded',
    'nede database giver fail/fail/degraded');
$check(!str_contains((string) json_encode($down), 'HEMMELIG'), 'fejlteksten vises ikke');

// 4) Ingen beviser endnu (ny server): null, sha unknown
$empty = sys_get_temp_dir() . '/whisper_health_empty_' . bin2hex(random_bytes(4));
mkdir($empty, 0700, true);
$e = Health::report($empty, static fn(): PDO => DB::pdo());
$check($e['sha'] === 'unknown' && $e['backup_last_ok'] === null && $e['restore_last_ok'] === null
    && $e['restore_rto_min'] === null && $e['last_incident'] === null, 'tom hjemmemappe: unknown og null');

// Oprydning
foreach (['config/deployed_sha.php', 'logs/backup_last_ok', 'logs/restore_last_ok', 'logs/incidents.log'] as $f) {
    @unlink("$base/$f");
}
@rmdir("$base/config");
@rmdir("$base/logs");
@rmdir($base);
@rmdir($empty);

echo $fails === 0 ? "H001-AK1: alle tjek bestået\n" : "H001-AK1: $fails tjek fejlede\n";
exit($fails === 0 ? 0 : 1);
