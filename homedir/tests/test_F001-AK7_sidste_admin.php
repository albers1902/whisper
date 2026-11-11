<?php
declare(strict_types=1);

// F001-AK7: den sidste aktive admin kan ikke deaktiveres eller nedgraderes, og en admin kan ikke deaktivere sig selv.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\UserError;
use Whisper\Users;

f001_setup();
$a1 = f001_user('admin1@example.com', 'admin');
$c = new F001Client();
$c->login('admin1@example.com', F001_PW);

$r = $c->post("/brugere/$a1", ['action' => 'deactivate']);
F001Check::that('AK7', $r->status === 422 && str_contains($r->body, 'Du kan ikke deaktivere dig selv.'), 'admin kan ikke deaktivere sig selv');
$r = $c->post("/brugere/$a1", ['action' => 'save', 'name' => 'Admin1', 'role' => 'user']);
F001Check::that('AK7', $r->status === 422 && str_contains($r->body, 'sidste aktive administrator kan ikke nedgraderes'), 'eneste admin kan ikke nedgradere sig selv');
$row = f001_row($a1);
F001Check::that('AK7', $row['role'] === 'admin' && (int) $row['active'] === 1, 'admin1 er uændret');

// Med to admins: admin1 kan nedgradere admin2; derefter er admin1 sidste og kan ikke nedgraderes
$a2 = f001_user('admin2@example.com', 'admin');
$r = $c->post("/brugere/$a2", ['action' => 'deactivate']);
F001Check::that('AK7', $r->status === 303 && (int) f001_row($a2)['active'] === 0, 'en anden admin kan deaktiveres, når der er flere');
$r = $c->post("/brugere/$a1", ['action' => 'save', 'name' => 'Admin1', 'role' => 'user']);
F001Check::that('AK7', $r->status === 422 && f001_row($a1)['role'] === 'admin', 'en deaktiveret admin tæller ikke: admin1 er stadig sidste');

// Direkte i modellen (det samme tjek uden om controlleren): sidste aktive admin deaktiveret af en anden
DB::exec('UPDATE users SET active = 1 WHERE id = ?', [$a2]);
Users::setActive($a2, false, $a1, F001_T0);
$err = '';
try {
    Users::setActive($a1, false, $a2, F001_T0);   // a2 er deaktiveret; a1 er sidste aktive
} catch (UserError $e) {
    $err = $e->getMessage();
}
F001Check::that('AK7', str_contains($err, 'sidste aktive administrator kan ikke deaktiveres') && (int) f001_row($a1)['active'] === 1,
    'sidste aktive admin kan ikke deaktiveres');
F001Check::that('AK7', !DB::pdo()->inTransaction(), 'transaktionen er rullet tilbage efter afvisning');
F001Check::that('AK7', (int) DB::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") === 1, 'der er stadig én aktiv admin');

F001Check::done();
