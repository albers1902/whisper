<?php
declare(strict_types=1);

// F001-AK8: en POST uden gyldigt CSRF-token giver 403 og ændrer intet.

require_once __DIR__ . '/f001_lib.php';

use Whisper\Csrf;
use Whisper\DB;
use Whisper\Request;

f001_setup();
$admin = f001_user('admin@example.com', 'admin');
$uid = f001_user('fie@example.com');

// Login uden token
$l = new F001Client();
$l->get('/login');
$r = $l->post('/login', ['email' => 'admin@example.com', 'password' => F001_PW], false);
F001Check::that('AK8', $r->status === 403 && $l->session->get('user_id') === null, 'POST /login uden token → 403, ikke logget ind');

$a = new F001Client();
$a->login('admin@example.com', F001_PW);
$users = (int) DB::scalar('SELECT COUNT(*) FROM users');
$before = f001_row($uid);

$cases = [
    ['/brugere/ny', ['email' => 'ny@example.com', 'name' => 'Ny', 'role' => 'admin']],
    ["/brugere/$uid", ['action' => 'save', 'name' => 'Ændret', 'role' => 'admin']],
    ["/brugere/$uid", ['action' => 'reset']],
    ["/brugere/$uid", ['action' => 'deactivate']],
    ['/konto', ['current' => F001_PW, 'new' => 'en-helt-ny-adgangskode', 'confirm' => 'en-helt-ny-adgangskode']],
    ['/logout', []],
];
foreach ($cases as [$path, $data]) {
    $r = $a->post($path, $data, false);
    F001Check::that('AK8', $r->status === 403, "uden token: POST $path " . ($data['action'] ?? '') . " → 403 (fik {$r->status})");
    $r = $a->app->handle(new Request('POST', $path, $data + [Csrf::FIELD => str_repeat('a', 64)], $a->ip), $a->session);
    F001Check::that('AK8', $r->status === 403, "forkert token: POST $path " . ($data['action'] ?? '') . ' → 403');
    $r = $a->app->handle(new Request('POST', $path, $data + [Csrf::FIELD => ['array']], $a->ip), $a->session);
    F001Check::that('AK8', $r->status === 403, "token som array: POST $path → 403");
}
F001Check::that('AK8', (int) DB::scalar('SELECT COUNT(*) FROM users') === $users, 'ingen bruger oprettet');
F001Check::that('AK8', f001_row($uid) === $before, 'brugeren er uændret');
F001Check::that('AK8', password_verify(F001_PW, (string) f001_row($admin)['password_hash']), 'admins adgangskode er uændret');
F001Check::that('AK8', $a->get('/')->status === 200, 'admin er stadig logget ind (logout uden token virkede ikke)');
F001Check::that('AK8', str_contains($a->get('/brugere/ny')->body, 'name="_csrf" value="' . Csrf::token($a->session) . '"'), 'formularer har tokenet');

F001Check::done();
