<?php
declare(strict_types=1);

/**
 * Opretter den første administrator (F001). Køres af ejeren som sitets bruger fra hjemmemappen:
 *
 *   php ~/repo/homedir/cron/opret-admin.php --home=. --email=<e-mail> --name=<navn>
 *
 * Adgangskoden læses fra stdin (uden ekko i en terminal) — aldrig som argument, så den ikke ses i ps eller i
 * shell-historikken. Exit 0 = oprettet; ≠ 0 ved enhver fejl (e-mail findes, for kort kode, manglende config).
 * Ingen bootstrap-side på nettet: kun CLI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$home = null;
$email = '';
$name = '';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--home=', 7) === 0) {
        $home = realpath(substr($a, 7));
    } elseif (strncmp($a, '--email=', 8) === 0) {
        $email = substr($a, 8);
    } elseif (strncmp($a, '--name=', 7) === 0) {
        $name = substr($a, 7);
    } else {
        fwrite(STDERR, "opret-admin.php: ukendt argument: {$a}\n");
        exit(2);
    }
}
if ($home === null || $home === false || !is_dir($home) || $email === '' || trim($name) === '') {
    fwrite(STDERR, "brug: php opret-admin.php --home=<hjemmemappe> --email=<e-mail> --name=<navn>  (adgangskoden på stdin)\n");
    exit(2);
}
if (!is_readable($home . '/config/config.ini')) {
    fwrite(STDERR, "opret-admin.php: {$home}/config/config.ini mangler eller kan ikke læses.\n");
    exit(1);
}

define('BASE_DIR', $home);
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\SecretInput;
use Whisper\Users;
use Whisper\UserError;

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'opret-admin.php: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
});

$tty = stream_isatty(STDIN);
if ($tty) {
    // Dør processen (Ctrl-C, fejl) mens ekkoet er slukket, tændes det igen, så terminalen ikke står "død"
    register_shutdown_function(static function (): void {
        SecretInput::systemStty('echo');
    });
}
$prompt = static function (string $s): void {
    fwrite(STDERR, $s);
};

try {
    $password = SecretInput::read(STDIN, $tty, [SecretInput::class, 'systemStty'], $prompt, 'Adgangskode (mindst 12 tegn): ');
    if ($tty && SecretInput::read(STDIN, true, [SecretInput::class, 'systemStty'], $prompt, 'Gentag adgangskoden: ') !== $password) {
        throw new UserError('de to adgangskoder er ikke ens.');
    }
    $id = Users::create($email, $name, 'admin', $password, false, time());
} catch (UserError $e) {
    fwrite(STDERR, 'opret-admin.php: ' . $e->getMessage() . "\n");
    exit(1);
}
echo 'Administrator oprettet: ' . Users::normalizeEmail($email) . " (id {$id})\n";
exit(0);
