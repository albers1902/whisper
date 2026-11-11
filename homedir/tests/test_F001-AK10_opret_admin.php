<?php
declare(strict_types=1);

// F001-AK10: opret-admin.php opretter en aktiv admin med adgangskode fra stdin, afviser en e-mail, der findes,
// afslutter med kode ≠ 0 ved fejl og kan kun køres fra CLI.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\Users;

f001_setup();
$home = dirname(__DIR__);
$script = $home . '/cron/opret-admin.php';

/**
 * @param list<string> $args
 * @return array{0: int, 1: string}
 */
function f001_run(string $script, array $args, string $stdin): array
{
    $p = proc_open(array_merge([PHP_BINARY, $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return [-1, 'kunne ikke starte'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

$pw = 'ejerens-lange-kode-42';
[$code, $out] = f001_run($script, ["--home=$home", '--email=Ejer@Example.com', '--name=Ejeren'], $pw . "\n");
$row = Users::findByEmail('ejer@example.com');
F001Check::that('AK10', $code === 0, "oprettet: exit 0 (fik $code: " . trim($out) . ')');
F001Check::that('AK10', $row !== null && $row['role'] === 'admin' && (int) $row['active'] === 1 && (int) $row['must_change_password'] === 0,
    'aktiv admin uden tvunget skift');
F001Check::that('AK10', $row !== null && password_verify($pw, (string) $row['password_hash']), 'adgangskoden fra stdin virker');
F001Check::that('AK10', !str_contains($out, $pw), 'adgangskoden skrives ikke ud');
F001Check::that('AK10', (new F001Client())->login('ejer@example.com', $pw)->header('Location') === '/', 'admin kan logge ind i web-appen');

[$code, $out] = f001_run($script, ["--home=$home", '--email=ejer@example.com', '--name=Igen'], $pw . "\n");
F001Check::that('AK10', $code !== 0 && str_contains($out, 'findes allerede'), "e-mailen findes: exit ≠ 0 (fik $code)");
F001Check::that('AK10', (int) DB::scalar('SELECT COUNT(*) FROM users') === 1, 'ingen dublet');

[$code] = f001_run($script, ["--home=$home", '--email=kort@example.com', '--name=Kort'], "for-kort\n");
F001Check::that('AK10', $code !== 0 && Users::findByEmail('kort@example.com') === null, "for kort adgangskode: exit ≠ 0 (fik $code)");
[$code] = f001_run($script, ["--home=$home", '--email=tom@example.com', '--name=Tom'], '');
F001Check::that('AK10', $code !== 0 && Users::findByEmail('tom@example.com') === null, 'tom stdin: exit ≠ 0');
[$code] = f001_run($script, ["--home=$home", '--name=Uden'], $pw . "\n");
F001Check::that('AK10', $code !== 0, 'uden --email: exit ≠ 0');
[$code] = f001_run($script, ["--home=$home", '--email=x@example.com', '--name=X', "--password=$pw"], $pw . "\n");
F001Check::that('AK10', $code !== 0 && Users::findByEmail('x@example.com') === null, 'adgangskode som argument afvises (ukendt argument)');
$noCfg = sys_get_temp_dir() . '/whisper_admin_' . bin2hex(random_bytes(4));
mkdir($noCfg, 0700, true);
[$code, $out] = f001_run($script, ["--home=$noCfg", '--email=y@example.com', '--name=Y'], $pw . "\n");
F001Check::that('AK10', $code !== 0 && str_contains($out, 'config.ini'), 'uden config.ini: exit ≠ 0');
@rmdir($noCfg);

// Terminal-input (QA F-1): uden virkende stty afvises indtastningen, så koden aldrig vises med ekko
$mem = static function (string $s) {
    $h = fopen('php://memory', 'w+');
    fwrite($h, $s);
    rewind($h);
    return $h;
};
$calls = [];
$noStty = static function (string $mode) use (&$calls): bool {
    $calls[] = $mode;
    return false;
};
$silent = static function (string $s): void {
};
$err = '';
try {
    \Whisper\SecretInput::read($mem("hemmelig-kode-123\n"), true, $noStty, $silent, 'Adgangskode: ');
} catch (\Whisper\UserError $e) {
    $err = $e->getMessage();
}
F001Check::that('AK10', str_contains($err, 'Pipe adgangskoden ind via stdin'), 'tty uden stty: afvist med besked om at pipe via stdin');
F001Check::that('AK10', $calls === ['-echo'], 'tty uden stty: intet læst, intet prompt vist');
$calls = [];
$okStty = static function (string $mode) use (&$calls): bool {
    $calls[] = $mode;
    return true;
};
$got = \Whisper\SecretInput::read($mem("hemmelig-kode-123\n"), true, $okStty, $silent, 'Adgangskode: ');
F001Check::that('AK10', $got === 'hemmelig-kode-123' && $calls === ['-echo', 'echo'], 'tty med stty: ekko slås fra og til igen');
$calls = [];
$got = \Whisper\SecretInput::read($mem("fra-pipe-kode-123\r\n"), false, $okStty, $silent, 'x');
F001Check::that('AK10', $got === 'fra-pipe-kode-123' && $calls === [], 'pipe: læses uden stty');
F001Check::that('AK10', \Whisper\SecretInput::systemStty('; rm -rf /') === false, 'systemStty tager kun faste argumenter');
F001Check::that('AK10', str_contains($src = (string) file_get_contents($script), 'register_shutdown_function'), 'ekkoet gendannes i et shutdown-hook');

// Kun CLI: guarden står først i filen, og filen ligger uden for webroden
$src = (string) file_get_contents($script);
$guard = strpos($src, "if (PHP_SAPI !== 'cli')");
F001Check::that('AK10', $guard !== false && $guard < (int) strpos($src, 'require_once'), 'CLI-guard før al anden kode');
F001Check::that('AK10', !str_starts_with((string) realpath($script), (string) realpath($home . '/public_html')), 'scriptet ligger uden for public_html');

F001Check::done();
