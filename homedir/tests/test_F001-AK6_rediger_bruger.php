<?php
declare(strict_types=1);

// F001-AK6: en admin ændrer rolle, nulstiller adgangskode og deaktiverer/genaktiverer; en deaktiveret brugers åbne
// session afvises ved næste request. Rollen læses fra databasen ved hver request.

require_once __DIR__ . '/f001_lib.php';

f001_setup();
f001_user('admin@example.com', 'admin');
$uid = f001_user('erik@example.com');
$a = new F001Client();
$a->login('admin@example.com', F001_PW);
$u = new F001Client('10.0.0.2');
$u->login('erik@example.com', F001_PW);

F001Check::that('AK6', $a->get("/brugere/$uid")->status === 200, 'admin: GET /brugere/{id} → 200');
F001Check::that('AK6', $a->get('/brugere/999999')->status === 404, 'ukendt id → 404');

// Rolle: forfremmelse og nedgradering virker med det samme i en åben session
F001Check::that('AK6', $u->get('/brugere')->status === 403, 'før: user → 403');
$r = $a->post("/brugere/$uid", ['action' => 'save', 'name' => 'Erik E', 'role' => 'admin']);
F001Check::that('AK6', $r->status === 303 && f001_row($uid)['role'] === 'admin' && f001_row($uid)['name'] === 'Erik E', 'navn og rolle gemt');
F001Check::that('AK6', $u->get('/brugere')->status === 200, 'åben session ser den nye rolle med det samme');
$a->post("/brugere/$uid", ['action' => 'save', 'name' => 'Erik E', 'role' => 'user']);
F001Check::that('AK6', $u->get('/brugere')->status === 403, 'nedgradering virker med det samme');

// Deaktivering: åben session afvises ved næste request; login afvises
$r = $a->post("/brugere/$uid", ['action' => 'deactivate']);
F001Check::that('AK6', $r->status === 303 && (int) f001_row($uid)['active'] === 0, 'deaktiveret');
$r = $u->get('/');
F001Check::that('AK6', $r->header('Location') === '/login' && $u->session->get('user_id') === null, 'deaktiveret brugers åbne session → /login');
F001Check::that('AK6', (new F001Client('10.0.0.3'))->login('erik@example.com', F001_PW)->header('Location') === null, 'deaktiveret bruger kan ikke logge ind');
$a->post("/brugere/$uid", ['action' => 'activate']);
F001Check::that('AK6', (int) f001_row($uid)['active'] === 1, 'genaktiveret');
$u = new F001Client('10.0.0.4');
F001Check::that('AK6', $u->login('erik@example.com', F001_PW)->header('Location') === '/', 'genaktiveret bruger kan logge ind');

// Nulstilling: ny engangskode, gammel kode virker ikke, åbne sessioner afvises, skift tvinges
$r = $a->post("/brugere/$uid", ['action' => 'reset']);
$otp = f001_otp($r);
F001Check::that('AK6', $r->status === 200 && strlen($otp) === 16, 'nulstilling viser engangskode');
F001Check::that('AK6', (int) f001_row($uid)['must_change_password'] === 1, 'must_change_password = 1 efter nulstilling');
F001Check::that('AK6', $u->get('/')->header('Location') === '/login', 'åben session afvises efter nulstilling');
F001Check::that('AK6', (new F001Client('10.0.0.5'))->login('erik@example.com', F001_PW)->header('Location') === null, 'gammel kode virker ikke');
F001Check::that('AK6', (new F001Client('10.0.0.6'))->login('erik@example.com', $otp)->header('Location') === '/konto', 'engangskoden → /konto');

$r = $a->post("/brugere/$uid", ['action' => 'noget-andet']);
F001Check::that('AK6', $r->status === 400, 'ukendt handling → 400');
F001Check::that('AK6', $a->get('/')->status === 200, 'admins egen session er uberørt');

F001Check::done();
