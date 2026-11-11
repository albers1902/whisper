<?php
declare(strict_types=1);

// F001-AK2: korrekt login for en aktiv bruger lykkes og fornyer session-id; forkert adgangskode, ukendt e-mail og
// deaktiveret bruger giver samme generiske fejl. Desuden: logout, inaktivitets-timeout og /login når man er logget ind.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\Users;

f001_setup();
$uid = f001_user('anna@example.com');
$inactive = f001_user('bo@example.com');
DB::exec('UPDATE users SET active = 0 WHERE id = ?', [$inactive]);

// Korrekt login (e-mail med store bogstaver og mellemrum normaliseres)
$c = new F001Client();
$c->get('/login');
$before = $c->session->id();
$r = $c->post('/login', ['email' => '  Anna@Example.COM ', 'password' => F001_PW]);
F001Check::that('AK2', $r->status === 303 && $r->header('Location') === '/', "korrekt login → 303 / (fik {$r->status})");
F001Check::that('AK2', $c->session->id() !== $before, 'session-id er fornyet ved login');
F001Check::that('AK2', $c->session->get('user_id') === $uid, 'sessionen har bruger-id');
F001Check::that('AK2', $c->get('/')->status === 200, 'GET / efter login → 200');
F001Check::that('AK2', str_contains($c->get('/')->body, 'Opkald kommer her (F002)'), 'forsiden viser pladsholderen');
F001Check::that('AK2', f001_row($uid)['last_login_at'] !== null, 'last_login_at er sat');
$r = $c->get('/login');
F001Check::that('AK2', $r->status === 303 && $r->header('Location') === '/', 'logget ind: /login → /');

// Tre fejlveje: samme status og samme besked
$bodies = [];
foreach ([['anna@example.com', 'forkert-adgangskode'], ['ukendt@example.com', F001_PW], ['bo@example.com', F001_PW]] as [$e, $p]) {
    $x = new F001Client('10.0.0.' . (count($bodies) + 2));
    $r = $x->login($e, $p);
    $bodies[] = [$r->status, str_contains($r->body, 'Forkert e-mail eller adgangskode.')];
    F001Check::that('AK2', $x->session->get('user_id') === null, "fejlet login ($e) giver ingen session-bruger");
}
F001Check::that('AK2', count(array_unique(array_map('serialize', $bodies))) === 1 && $bodies[0] === [200, true],
    'forkert kode, ukendt e-mail og deaktiveret bruger giver samme svar');
F001Check::that('AK2', (int) DB::scalar('SELECT COUNT(*) FROM login_attempts WHERE success = 0') === 3, 'fejlede forsøg er registreret');

// Logout: sessionen ødelægges helt
$r = $c->post('/logout');
F001Check::that('AK2', $r->status === 303 && $r->header('Location') === '/login', 'logout → /login');
F001Check::that('AK2', $c->driver->destroyed && $c->session->all() === [], 'sessionen er ødelagt ved logout');
F001Check::that('AK2', $c->get('/')->header('Location') === '/login', 'efter logout kræver / login igen');
F001Check::that('AK2', $c->get('/logout')->status === 405, 'GET /logout → 405 (kun POST)');

// Inaktivitets-timeout på 8 timer
$t = new F001Client('10.0.0.9');
$t->login('anna@example.com', F001_PW);
$t->now += 8 * 3600 - 10;
F001Check::that('AK2', $t->get('/')->status === 200, 'aktiv inden for 8 timer');
$t->now += 8 * 3600 + 1;
$r = $t->get('/');
F001Check::that('AK2', $r->header('Location') === '/login' && $t->session->get('user_id') === null, 'over 8 timers inaktivitet → /login');

// En hash med lavere cost rehashes ved login
DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(F001_PW, PASSWORD_BCRYPT, ['cost' => 4]), $uid]);
(new F001Client('10.0.0.10'))->login('anna@example.com', F001_PW);
F001Check::that('AK2', !password_needs_rehash((string) f001_row($uid)['password_hash'], PASSWORD_DEFAULT), 'svag hash rehashes ved login');
F001Check::that('AK2', Users::normalizeEmail(' A@B.DK ') === 'a@b.dk', 'e-mail normaliseres');

F001Check::done();
