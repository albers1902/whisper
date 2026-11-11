<?php
declare(strict_types=1);

// F001-AK9: adgangskoder findes kun som hash i databasen, og en ny adgangskode under 12 tegn afvises.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\Password;
use Whisper\UserError;
use Whisper\Users;

f001_setup();
f001_user('admin@example.com', 'admin');
$uid = f001_user('gro@example.com');
$a = new F001Client();
$a->login('admin@example.com', F001_PW);
$otp = f001_otp($a->post('/brugere/ny', ['email' => 'hans@example.com', 'name' => 'Hans', 'role' => 'user']));

// Ingen klartekst nogen steder i tabellerne
$dump = json_encode(DB::all('SELECT * FROM users')) . json_encode(DB::all('SELECT * FROM login_attempts'));
F001Check::that('AK9', $otp !== '' && !str_contains((string) $dump, $otp), 'engangskoden står ikke i databasen');
F001Check::that('AK9', !str_contains((string) $dump, F001_PW), 'adgangskoden står ikke i databasen');
foreach (DB::all('SELECT email, password_hash FROM users') as $row) {
    $info = password_get_info((string) $row['password_hash']);
    F001Check::that('AK9', $info['algo'] !== null && $info['algo'] !== 0, "{$row['email']}: password_hash er en rigtig hash");
}

// Under 12 tegn afvises på /konto og i modellen
$g = new F001Client('10.0.0.2');
$g->login('gro@example.com', F001_PW);
$r = $g->post('/konto', ['current' => F001_PW, 'new' => 'elleve-tegn', 'confirm' => 'elleve-tegn']);
F001Check::that('AK9', $r->status === 422 && str_contains($r->body, 'mindst 12 tegn'), '/konto: 11 tegn afvises');
F001Check::that('AK9', password_verify(F001_PW, (string) f001_row($uid)['password_hash']), 'koden er uændret efter afvisning');
$r = $g->post('/konto', ['current' => 'forkert-nuvaerende', 'new' => 'gyldig-ny-kode-123', 'confirm' => 'gyldig-ny-kode-123']);
F001Check::that('AK9', $r->status === 422 && str_contains($r->body, 'nuværende adgangskode er forkert'), '/konto: forkert nuværende kode afvises');
$r = $g->post('/konto', ['current' => F001_PW, 'new' => 'gyldig-ny-kode-123', 'confirm' => 'gyldig-ny-kode-124']);
F001Check::that('AK9', $r->status === 422 && str_contains($r->body, 'ikke ens'), '/konto: forskellige gentagelser afvises');
$r = $g->post('/konto', ['current' => F001_PW, 'new' => 'gyldig-ny-kode-123', 'confirm' => 'gyldig-ny-kode-123']);
F001Check::that('AK9', $r->status === 303 && password_verify('gyldig-ny-kode-123', (string) f001_row($uid)['password_hash']), '/konto: 18 tegn accepteres');
F001Check::that('AK9', $g->get('/konto')->status === 200, 'egen session fortsætter efter skift');

$err = '';
try {
    Users::create('kort@example.com', 'Kort', 'user', '12345678901', false, F001_T0);
} catch (UserError $e) {
    $err = $e->getMessage();
}
F001Check::that('AK9', str_contains($err, 'mindst 12 tegn') && Users::findByEmail('kort@example.com') === null, 'model: 11 tegn afvises');
F001Check::that('AK9', Password::problem('æøåæøåæøåæøå') === null, '12 tegn med æøå tælles som 12 (ikke bytes)');
F001Check::that('AK9', Password::problem(str_repeat('a', 12) . "\0") !== null, 'NUL-tegn afvises');

// Over 72 byte afvises (bcrypt ville stille ignorere resten), også når æøå fylder 2 byte
F001Check::that('AK9', Password::problem(str_repeat('a', 72)) === null, '72 byte accepteres');
F001Check::that('AK9', str_contains((string) Password::problem(str_repeat('a', 73)), 'højst være 72 byte'), '73 byte afvises med dansk tekst');
F001Check::that('AK9', Password::problem(str_repeat('æ', 37)) !== null, '37 × æ (74 byte) afvises');
$long = str_repeat('b', 80);
$r = $g->post('/konto', ['current' => 'gyldig-ny-kode-123', 'new' => $long, 'confirm' => $long]);
F001Check::that('AK9', $r->status === 422 && str_contains($r->body, 'højst være 72 byte'), '/konto: 80 tegn afvises');
F001Check::that('AK9', password_verify('gyldig-ny-kode-123', (string) f001_row($uid)['password_hash']), 'koden er uændret efter afvisning');

// "Nuværende adgangskode" på /konto begrænses som et login (security S1, QA F-8)
for ($i = 0; $i < 5; $i++) {
    $g->post('/konto', ['current' => "forkert-gaet-$i", 'new' => 'endnu-en-ny-kode', 'confirm' => 'endnu-en-ny-kode']);
}
$r = $g->post('/konto', ['current' => 'gyldig-ny-kode-123', 'new' => 'endnu-en-ny-kode', 'confirm' => 'endnu-en-ny-kode']);
F001Check::that('AK9', $r->status === 429 && str_contains($r->body, 'For mange forsøg'), '/konto: efter 5 forkerte gæt afvises også den rigtige kode');
F001Check::that('AK9', password_verify('gyldig-ny-kode-123', (string) f001_row($uid)['password_hash']), 'koden er uændret under låsen');
F001Check::that('AK9', (new F001Client('10.9.9.9'))->login('gro@example.com', 'gyldig-ny-kode-123')->status === 429,
    'låsen gælder også login for samme e-mail');

// Tabellerne har den kollation, kontrakten kræver
$coll = DB::all("SELECT table_name AS t, table_collation AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN ('users', 'login_attempts')");
F001Check::that('AK9', count($coll) === 2 && array_unique(array_column($coll, 'c')) === ['utf8mb4_unicode_ci'], 'users og login_attempts er utf8mb4_unicode_ci');

F001Check::done();
