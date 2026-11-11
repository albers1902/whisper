<?php
declare(strict_types=1);

// F001-AK4: en bruger med rollen user får 403 på /brugere og på alle dens POST-handlinger.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;

f001_setup();
$admin = f001_user('admin@example.com', 'admin');
$uid = f001_user('anna@example.com');
$c = new F001Client();
$c->login('anna@example.com', F001_PW);

foreach (['/brugere', '/brugere/ny', "/brugere/$admin", "/brugere/$uid", "/brugere/$admin/nulstil", "/brugere/$admin/deaktiver"] as $p) {
    F001Check::that('AK4', $c->get($p)->status === 403, "user: GET $p → 403");
}
$r = $c->post('/brugere/ny', ['email' => 'ny@example.com', 'name' => 'Ny', 'role' => 'admin']);
F001Check::that('AK4', $r->status === 403, 'user: POST /brugere/ny → 403');
F001Check::that('AK4', (int) DB::scalar('SELECT COUNT(*) FROM users') === 2, 'ingen bruger oprettet');

$hashBefore = f001_row($admin)['password_hash'];
foreach (['save' => ['name' => 'Hacket', 'role' => 'user'], 'reset' => ['bekraeftet' => '1'], 'deactivate' => ['bekraeftet' => '1']] as $action => $extra) {
    $r = $c->post("/brugere/$admin", ['action' => $action] + $extra);
    F001Check::that('AK4', $r->status === 403, "user: POST /brugere/$admin action=$action → 403");
}
$row = f001_row($admin);
F001Check::that('AK4', $row['name'] === 'Admin' && $row['role'] === 'admin' && (int) $row['active'] === 1 && $row['password_hash'] === $hashBefore,
    'admin-brugeren er uændret');
$r = $c->post("/brugere/$uid", ['action' => 'save', 'name' => 'Anna', 'role' => 'admin']);
F001Check::that('AK4', $r->status === 403 && f001_row($uid)['role'] === 'user', 'user kan ikke give sig selv admin');
F001Check::that('AK4', !str_contains($c->get('/')->body, 'href="/brugere"'), 'menuen viser ikke Brugere for user');

F001Check::done();
