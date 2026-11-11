<?php
declare(strict_types=1);

// F001-AK5: en admin opretter en bruger; engangskoden virker én gang, brugeren tvinges til at skifte adgangskode
// før alt andet, og e-mailen er unik uden forskel på store og små bogstaver.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\Users;

f001_setup();
f001_user('admin@example.com', 'admin');
$a = new F001Client();
$a->login('admin@example.com', F001_PW);

F001Check::that('AK5', $a->get('/brugere')->status === 200, 'admin: GET /brugere → 200');
F001Check::that('AK5', $a->get('/brugere/ny')->status === 200, 'admin: GET /brugere/ny → 200');

$r = $a->post('/brugere/ny', ['email' => '  Dorte@Example.com ', 'name' => 'Dorte <b>D</b>', 'role' => 'user']);
$otp = f001_otp($r);
F001Check::that('AK5', $r->status === 200 && strlen($otp) === 16, "oprettet, engangskode på 16 tegn vist (fik {$r->status}, " . strlen($otp) . ' tegn)');
F001Check::that('AK5', $r->header('Cache-Control') === 'no-store', 'siden med engangskoden caches ikke');
$row = Users::findByEmail('dorte@example.com');
F001Check::that('AK5', $row !== null && $row['email'] === 'dorte@example.com', 'e-mail gemt trimmet og med små bogstaver');
F001Check::that('AK5', $row !== null && (int) $row['must_change_password'] === 1 && $row['role'] === 'user', 'must_change_password = 1, rolle user');
F001Check::that('AK5', $row !== null && password_verify($otp, (string) $row['password_hash']), 'engangskoden passer til hashen');
F001Check::that('AK5', str_contains($a->get('/brugere')->body, 'Dorte &lt;b&gt;D&lt;/b&gt;'), 'navnet escapes i listen');

// Unik e-mail uden forskel på store og små bogstaver
$r = $a->post('/brugere/ny', ['email' => 'DORTE@example.COM', 'name' => 'Igen', 'role' => 'user']);
F001Check::that('AK5', $r->status === 422 && str_contains($r->body, 'findes allerede'), 'samme e-mail med store bogstaver afvises');
F001Check::that('AK5', (int) DB::scalar('SELECT COUNT(*) FROM users') === 2, 'ingen dublet oprettet');
$r = $a->post('/brugere/ny', ['email' => 'ikke-en-mail', 'name' => 'X', 'role' => 'user']);
F001Check::that('AK5', $r->status === 422 && str_contains($r->body, 'gyldig e-mailadresse'), 'ugyldig e-mail afvises');
$r = $a->post('/brugere/ny', ['email' => 'x@example.com', 'name' => 'X', 'role' => 'superuser']);
F001Check::that('AK5', $r->status === 422 && Users::findByEmail('x@example.com') === null, 'ukendt rolle afvises');

// Den nye bruger: tvunget til /konto før alt andet
$d = new F001Client('10.0.0.2');
$r = $d->login('dorte@example.com', $otp);
F001Check::that('AK5', $r->status === 303 && $r->header('Location') === '/konto', 'login med engangskode → /konto');
foreach (['/', '/brugere'] as $p) {
    F001Check::that('AK5', $d->get($p)->header('Location') === '/konto', "tvunget skift: GET $p → /konto");
}
F001Check::that('AK5', $d->get('/konto')->status === 200, '/konto vises');
$new = 'dortes-egen-lange-kode';
$r = $d->post('/konto', ['current' => $otp, 'new' => $new, 'confirm' => $new]);
F001Check::that('AK5', $r->status === 303 && $r->header('Location') === '/', 'skift af adgangskode → /');
F001Check::that('AK5', $d->get('/')->status === 200, 'derefter virker forsiden');
F001Check::that('AK5', (int) (Users::findByEmail('dorte@example.com')['must_change_password'] ?? 1) === 0, 'must_change_password = 0');

// Engangskoden virker kun én gang
$e = new F001Client('10.0.0.3');
$r = $e->login('dorte@example.com', $otp);
F001Check::that('AK5', $e->session->get('user_id') === null && str_contains($r->body, 'Forkert e-mail eller adgangskode.'), 'engangskoden virker ikke igen');
F001Check::that('AK5', (new F001Client('10.0.0.4'))->login('dorte@example.com', $new)->header('Location') === '/', 'den nye kode virker');

F001Check::done();
