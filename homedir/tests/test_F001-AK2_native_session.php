<?php
declare(strict_types=1);

// F001-AK2 (QA F-2): PHP's rigtige sessioner via NativeSessionDriver mod en midlertidig hjemmemappe — cookie-flag
// (Secure, HttpOnly, SameSite=Lax), var/sessions oprettet med 0700, nyt id ved regenerate og ingen fil efter destroy.
// Output bufres, så session_start() ikke ser "headers already sent" i CLI.

ob_start();

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\App;
use Whisper\NativeSessionDriver;
use Whisper\Session;

$results = [];
$check = static function (bool $cond, string $msg) use (&$results): void {
    $results[] = [$cond, $msg];
};

$base = sys_get_temp_dir() . '/whisper_sess_' . bin2hex(random_bytes(4));
mkdir($base, 0700, true);
$dir = $base . '/var/sessions';

$driver = NativeSessionDriver::start($base);
$check(session_status() === PHP_SESSION_ACTIVE, 'sessionen er startet');
$check(is_dir($dir), 'var/sessions er oprettet');
$check((fileperms($dir) & 0777) === 0700 || PHP_OS_FAMILY === 'Windows', 'var/sessions har rettighederne 0700 (fik ' . decoct(fileperms($dir) & 0777) . ')');
$check(realpath((string) ini_get('session.save_path')) === realpath($dir), 'session.save_path er var/sessions');

$p = session_get_cookie_params();
$check($p['secure'] === true, 'cookie: Secure');
$check($p['httponly'] === true, 'cookie: HttpOnly');
$check($p['samesite'] === 'Lax', 'cookie: SameSite=Lax');
$check($p['lifetime'] === 0 && $p['path'] === '/', 'cookie: sessionscookie på /');
$check(session_name() === 'whisper_session', 'cookie-navn whisper_session');
$check(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1', 'strict mode og kun cookies');
$check((int) ini_get('session.gc_maxlifetime') === App::IDLE_SECONDS, 'gc_maxlifetime = 8 timer');
$check(ini_get('session.gc_probability') === '1' && ini_get('session.gc_divisor') === '100', 'GC 1/100');

$session = new Session($driver, $driver->load());
$session->set('user_id', 42);
$id1 = $session->id();
$session->regenerate();
$id2 = $session->id();
$check($id1 !== '' && $id2 !== '' && $id1 !== $id2, 'regenerate giver et nyt session-id');
$driver->save($session->all());
$check(is_file($dir . '/sess_' . $id2), 'sessionsfilen ligger i var/sessions');
$check(!is_file($dir . '/sess_' . $id1), 'den gamle sessionsfil er slettet ved regenerate');
$check(str_contains((string) file_get_contents($dir . '/sess_' . $id2), 'user_id|i:42'), 'data er gemt');

// Ny request med samme id: data læses igen, og destroy fjerner filen
session_id($id2);
$driver = NativeSessionDriver::start($base);
$check($driver->load() === ['user_id' => 42], 'sessionen læses tilbage');
$driver->destroy();
$check(!is_file($dir . '/sess_' . $id2), 'destroy sletter sessionsfilen');

foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);
@rmdir($base . '/var');
@rmdir($base);

ob_end_clean();
$fails = 0;
foreach ($results as [$ok, $msg]) {
    echo ($ok ? 'ok:   ' : 'FAIL: ') . "F001-AK2 $msg\n";
    $fails += $ok ? 0 : 1;
}
exit($fails === 0 ? 0 : 1);
