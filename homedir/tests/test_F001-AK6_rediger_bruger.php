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

// Deaktivering kræver bekræftelsessiden (uden JS): uden bekræftelse sendes man dertil, og intet ændres
$r = $a->post("/brugere/$uid", ['action' => 'deactivate']);
F001Check::that('AK6', $r->status === 303 && $r->header('Location') === "/brugere/$uid/deaktiver" && (int) f001_row($uid)['active'] === 1,
    'deaktiver uden bekræftelse → bekræftelsessiden, intet ændret');
$r = $a->get("/brugere/$uid/deaktiver");
F001Check::that('AK6', $r->status === 200 && str_contains($r->body, 'name="bekraeftet" value="1"') && str_contains($r->body, 'value="deactivate"'),
    'bekræftelsessiden for deaktivering har en formular');
$edit = $a->get("/brugere/$uid")->body;
F001Check::that('AK6', !str_contains($edit, 'value="reset"') && str_contains($edit, "href=\"/brugere/$uid/nulstil\""),
    'redigeringssiden har ingen direkte nulstil-knap, kun et link til bekræftelsen');

// Deaktivering: åben session afvises ved næste request; login afvises
$r = $a->post("/brugere/$uid", ['action' => 'deactivate', 'bekraeftet' => '1']);
F001Check::that('AK6', $r->status === 303 && (int) f001_row($uid)['active'] === 0, 'deaktiveret');
$r = $u->get('/');
F001Check::that('AK6', $r->header('Location') === '/login' && $u->session->get('user_id') === null, 'deaktiveret brugers åbne session → /login');
F001Check::that('AK6', (new F001Client('10.0.0.3'))->login('erik@example.com', F001_PW)->header('Location') === null, 'deaktiveret bruger kan ikke logge ind');
$a->post("/brugere/$uid", ['action' => 'activate']);
F001Check::that('AK6', (int) f001_row($uid)['active'] === 1, 'genaktiveret');
$u = new F001Client('10.0.0.4');
F001Check::that('AK6', $u->login('erik@example.com', F001_PW)->header('Location') === '/', 'genaktiveret bruger kan logge ind');

// Nulstilling: ny engangskode, gammel kode virker ikke, åbne sessioner afvises, skift tvinges
$hashBefore = f001_row($uid)['password_hash'];
$r = $a->post("/brugere/$uid", ['action' => 'reset']);
F001Check::that('AK6', $r->header('Location') === "/brugere/$uid/nulstil" && f001_row($uid)['password_hash'] === $hashBefore,
    'nulstil uden bekræftelse → bekræftelsessiden, koden uændret');
F001Check::that('AK6', str_contains($a->get("/brugere/$uid/nulstil")->body, 'Ja, nulstil adgangskoden'), 'bekræftelsessiden for nulstilling vises');
$r = $a->post("/brugere/$uid", ['action' => 'reset', 'bekraeftet' => '1']);
$otp = f001_otp($r);
F001Check::that('AK6', $r->status === 200 && strlen($otp) === 16, 'nulstilling viser engangskode');
F001Check::that('AK6', (int) f001_row($uid)['must_change_password'] === 1, 'must_change_password = 1 efter nulstilling');
F001Check::that('AK6', $u->get('/')->header('Location') === '/login', 'åben session afvises efter nulstilling');
F001Check::that('AK6', (new F001Client('10.0.0.5'))->login('erik@example.com', F001_PW)->header('Location') === null, 'gammel kode virker ikke');
F001Check::that('AK6', (new F001Client('10.0.0.6'))->login('erik@example.com', $otp)->header('Location') === '/konto', 'engangskoden → /konto');

$r = $a->post("/brugere/$uid", ['action' => 'noget-andet']);
F001Check::that('AK6', $r->status === 400, 'ukendt handling → 400');
F001Check::that('AK6', $a->get('/')->status === 200, 'admins egen session er uberørt');

// Sidste login vises som dd.mm.åååå tt:mm
$list = $a->get('/brugere')->body;
F001Check::that('AK6', preg_match('/<td>\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}<\/td>/', $list) === 1 && preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $list) === 0,
    'sidste login vises som dd.mm.åååå tt:mm');

// Egen konto: nulstilling afvises (henvis til Konto), egen nedgradering sender til forsiden med en besked
$aid = (int) (\Whisper\Users::findByEmail('admin@example.com')['id'] ?? 0);
$hashA = f001_row($aid)['password_hash'];
$r = $a->get("/brugere/$aid/nulstil");
F001Check::that('AK6', $r->status === 422 && str_contains($r->body, 'Skift din adgangskode under Konto.'), 'egen nulstilling: bekræftelsessiden afviser');
$r = $a->post("/brugere/$aid", ['action' => 'reset', 'bekraeftet' => '1']);
F001Check::that('AK6', $r->status === 422 && str_contains($r->body, 'Skift din adgangskode under Konto.') && f001_row($aid)['password_hash'] === $hashA,
    'egen nulstilling afvises, koden uændret');
$a->post("/brugere/$uid", ['action' => 'save', 'name' => 'Erik E', 'role' => 'admin']);   // så admin ikke er den sidste
$r = $a->post("/brugere/$aid", ['action' => 'save', 'name' => 'Admin', 'role' => 'user']);
F001Check::that('AK6', $r->status === 303 && $r->header('Location') === '/' && f001_row($aid)['role'] === 'user', 'egen nedgradering → /');
F001Check::that('AK6', str_contains($a->get('/')->body, 'har ikke længere adgang til Brugere'), 'forsiden forklarer nedgraderingen');

F001Check::done();
