<?php
declare(strict_types=1);

// H001-AK2: migrate.php (coworker.ini [deploy] migrate) giver exit ≠ 0 uden config, så deploy.sh aldrig skifter kode
// over en database, der ikke er migreret; med testdatabasen er alle migrationer kørt, og en genkørsel er en no-op.
// Hver migration har frameworkets headers og en down-fil, når den siger reversibel: ja (LIFECYCLE §6).

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\DB;

$fails = 0;
$check = static function (bool $cond, string $msg) use (&$fails): void {
    echo ($cond ? 'ok:   ' : 'FAIL: ') . "H001-AK2 $msg\n";
    if (!$cond) {
        $fails++;
    }
};

$home = dirname(__DIR__);
$migrate = $home . '/cron/migrate.php';
$migDir = $home . '/migrations';

/** @return array{0: int, 1: string} */
$run = static function (array $args) use ($migrate): array {
    $cmd = array_merge([PHP_BINARY, $migrate], $args);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return [-1, 'kunne ikke starte'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
};

// 1) Hjemmemappe uden config: exit ≠ 0
$noCfg = sys_get_temp_dir() . '/whisper_mig_' . bin2hex(random_bytes(4));
mkdir($noCfg, 0700, true);
[$code, $out] = $run(['--home=' . $noCfg, '--dir=' . $migDir]);
$check($code !== 0, "uden config.ini: exit ≠ 0 (fik $code)");
$check(str_contains($out, 'config.ini'), 'fejlbeskeden nævner config.ini');
@rmdir($noCfg);

// 2) Ukendt --home: exit ≠ 0
[$code] = $run(['--home=' . $noCfg . '_findes_ikke']);
$check($code !== 0, "ukendt --home: exit ≠ 0 (fik $code)");

// 3) Med testdatabasen (CI har allerede kørt migrationerne med mysql): migrate.php kører dem, der mangler i
//    _migrations, og en genkørsel er en no-op. Migrationerne er idempotente, så første kørsel er sikker.
[$code, $out] = $run(['--home=' . $home, '--dir=' . $migDir]);
$check($code === 0, "første kørsel mod testdatabasen: exit 0 (fik $code: " . trim($out) . ')');
[$code, $out] = $run(['--home=' . $home, '--dir=' . $migDir]);
$check($code === 0 && str_contains($out, 'Ingen ventende'), 'genkørsel: intet at køre');
$files = glob($migDir . '/[0-9]*.sql') ?: [];
$rows = (int) DB::scalar('SELECT COUNT(*) FROM _migrations');
$check($rows === count($files), "_migrations har én række pr. migration ($rows/" . count($files) . ')');
$check((int) DB::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'health_probe'") === 1,
    'health_probe findes');

// 3b) En allerede kørt migration, der er ændret (sha256-mismatch): exit ≠ 0, og intet køres. Ændringen sker i en
//     kopi i en midlertidig mappe — repoets migrationer røres aldrig.
$tmpMig = sys_get_temp_dir() . '/whisper_mig_changed_' . bin2hex(random_bytes(4));
mkdir($tmpMig, 0700, true);
foreach ($files as $f) {
    copy($f, $tmpMig . '/' . basename($f));
}
$first = $files !== [] ? basename($files[0]) : '';
$check($first !== '' && file_put_contents($tmpMig . '/' . $first, "\n-- ændret efter kørsel\n", FILE_APPEND) !== false,
    "kopien af $first er ændret");
$before = (int) DB::scalar('SELECT COUNT(*) FROM _migrations');
[$code, $out] = $run(['--home=' . $home, '--dir=' . $tmpMig]);
$check($code !== 0, "ændret kørt migration: exit ≠ 0 (fik $code)");
$check(str_contains($out, $first), 'fejlbeskeden nævner den ændrede fil');
$check((int) DB::scalar('SELECT COUNT(*) FROM _migrations') === $before, '_migrations er uændret efter afvisningen');
$check(hash_file('sha256', $migDir . '/' . $first) === (string) DB::scalar('SELECT checksum FROM _migrations WHERE filename = ?', [$first]),
    "repoets $first er uændret (checksum som kørt)");
foreach (glob($tmpMig . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpMig);

// 3c) Fejl i en SENERE sætning i en flersætningsfil: exit ≠ 0 og ingen række i _migrations for filen (QA fund 1).
//     Kopi i en midlertidig mappe — repoets migrationer røres aldrig.
$tmpMulti = sys_get_temp_dir() . '/whisper_mig_multi_' . bin2hex(random_bytes(4));
mkdir($tmpMulti, 0700, true);
foreach ($files as $f) {
    copy($f, $tmpMulti . '/' . basename($f));
}
$multi = '9990_v0.0.0_h001_multi_fejl.sql';
file_put_contents($tmpMulti . '/' . $multi,
    "CREATE TABLE IF NOT EXISTS h001_multi_ok (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n"
    . "INSERT INTO h001_tabel_findes_ikke (id) VALUES (1);\n");
[$code, $out] = $run(['--home=' . $home, '--dir=' . $tmpMulti]);
$check($code !== 0, "fejl i anden sætning: exit ≠ 0 (fik $code)");
$check(str_contains($out, $multi), 'fejlbeskeden nævner flersætningsfilen');
$check((int) DB::scalar('SELECT COUNT(*) FROM _migrations WHERE filename = ?', [$multi]) === 0,
    'flersætningsfilen er IKKE registreret i _migrations');
DB::exec('DROP TABLE IF EXISTS h001_multi_ok');

// 3d) --status skriver intet og advarer om en kørt migration, hvis fil mangler (QA fund 3)
$tmpEmpty = sys_get_temp_dir() . '/whisper_mig_empty_' . bin2hex(random_bytes(4));
mkdir($tmpEmpty, 0700, true);
$before = (int) DB::scalar('SELECT COUNT(*) FROM _migrations');
[$code, $out] = $run(['--home=' . $home, '--dir=' . $tmpEmpty, '--status']);
$check($code === 0, "--status: exit 0 (fik $code)");
$check($first !== '' && str_contains($out, 'ADVARSEL') && str_contains($out, $first),
    "--status advarer om kørt $first uden fil");
$check((int) DB::scalar('SELECT COUNT(*) FROM _migrations') === $before, '--status skriver intet i _migrations');
@rmdir($tmpEmpty);

// 3e) To samtidige kørsler: holder en anden forbindelse låsen, afvises kørslen med exit ≠ 0 (QA fund 3)
$check((int) DB::scalar("SELECT GET_LOCK('whisper_migrate', 0)") === 1, 'testen tager låsen whisper_migrate');
[$code, $out] = $run(['--home=' . $home, '--dir=' . $migDir]);
$check($code !== 0 && str_contains($out, 'whisper_migrate'), "migrate.php afvises, mens låsen holdes (exit $code)");
DB::scalar("SELECT RELEASE_LOCK('whisper_migrate')");
[$code] = $run(['--home=' . $home, '--dir=' . $migDir]);
$check($code === 0, "efter låsen er sluppet: exit 0 (fik $code)");

foreach (glob($tmpMulti . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpMulti);

// 4) Headers og down-filer
$check($files !== [], 'der findes migrationer');
foreach ($files as $f) {
    $b = basename($f);
    $head = implode("\n", array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], 0, 3));
    $check(preg_match('/^-- reversibel: (ja|nej)\n-- destruktiv: (ja|nej)\n-- idempotent: (ja|nej)$/', $head) === 1,
        "$b starter med de tre headers");
    $check(preg_match('/^\d{4}_v\d+\.\d+\.\d+_[a-z0-9_]+\.sql$/', $b) === 1, "$b følger NNNN_vX.Y.Z_beskrivelse.sql");
    if (str_starts_with($head, '-- reversibel: ja')) {
        $check(is_file($migDir . '/down/' . $b), "$b har down-fil");
    }
}

echo $fails === 0 ? "H001-AK2: alle tjek bestået\n" : "H001-AK2: $fails tjek fejlede\n";
exit($fails === 0 ? 0 : 1);
