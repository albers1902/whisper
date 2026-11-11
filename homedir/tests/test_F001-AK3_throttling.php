<?php
declare(strict_types=1);

// F001-AK3: efter 5 fejlede forsøg inden for 15 min (samme e-mail eller samme IP) afvises også det korrekte login,
// indtil der er gået 15 min.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\LoginThrottle;
use Whisper\Users;

f001_setup();
f001_user('anna@example.com');
f001_user('carl@example.com');

// Samme e-mail, skiftende IP
for ($i = 1; $i <= 5; $i++) {
    $x = new F001Client("10.1.0.$i");
    $x->now = F001_T0 + $i * 60;
    $x->login('anna@example.com', 'forkert-adgangskode');
}
$c = new F001Client('10.1.0.99');
$c->now = F001_T0 + 6 * 60;
$r = $c->login('anna@example.com', F001_PW);
F001Check::that('AK3', $r->status === 429 && str_contains($r->body, 'For mange forsøg — prøv igen om lidt.'), "e-mail låst efter 5 fejl → korrekt login afvist (fik {$r->status})");
F001Check::that('AK3', $c->session->get('user_id') === null, 'ingen session-bruger under låsen');
F001Check::that('AK3', !str_contains($r->body, 'Forkert e-mail'), 'låsebeskeden afslører ikke, om koden var rigtig');

// Låsen gælder også en ukendt e-mail (afslører ikke, om e-mailen findes)
for ($i = 1; $i <= 5; $i++) {
    $x = new F001Client("10.2.0.$i");
    $x->now = F001_T0 + $i;
    $x->login('findes.ikke@example.com', 'forkert-adgangskode');
}
$x = new F001Client('10.2.0.50');
$x->now = F001_T0 + 10;
F001Check::that('AK3', $x->login('findes.ikke@example.com', 'noget')->status === 429, 'ukendt e-mail låses på samme måde');

// 14 min efter 5. fejl: stadig låst. 15 min efter: lukket op
$c->now = F001_T0 + 5 * 60 + 14 * 60;
F001Check::that('AK3', $c->login('anna@example.com', F001_PW)->status === 429, 'stadig låst 14 min efter femte fejl');
$c->now = F001_T0 + 5 * 60 + 15 * 60;
$r = $c->login('anna@example.com', F001_PW);
F001Check::that('AK3', $r->status === 303 && $c->session->get('user_id') !== null, 'efter 15 min lykkes det korrekte login');

// Samme IP, skiftende e-mails
for ($i = 1; $i <= 5; $i++) {
    $x = new F001Client('10.3.0.1');
    $x->now = F001_T0 + 3600 + $i;
    $x->login("gaet$i@example.com", 'forkert-adgangskode');
}
$x = new F001Client('10.3.0.1');
$x->now = F001_T0 + 3600 + 10;
F001Check::that('AK3', $x->login('carl@example.com', F001_PW)->status === 429, 'IP låst efter 5 fejl → også en anden brugers korrekte login afvist');
$y = new F001Client('10.3.0.2');
$y->now = F001_T0 + 3600 + 10;
F001Check::that('AK3', $y->login('carl@example.com', F001_PW)->status === 303, 'samme bruger fra en anden IP er ikke låst');

// 5 fejl spredt over mere end 15 min låser ikke
DB::exec('DELETE FROM login_attempts');
for ($i = 0; $i < 5; $i++) {
    LoginThrottle::record('carl@example.com', '10.4.0.1', false, F001_T0 + $i * 300);   // 0, 5, 10, 15, 20 min
}
F001Check::that('AK3', !LoginThrottle::isLocked('carl@example.com', '10.4.0.9', F001_T0 + 1201), '5 fejl over 20 min låser ikke');

// Forsøg ældre end 30 dage slettes ved login
LoginThrottle::record('gammel@example.com', '10.5.0.1', false, F001_T0 - 31 * 86400);
(new F001Client('10.5.0.2'))->login('carl@example.com', F001_PW);
F001Check::that('AK3', (int) DB::scalar("SELECT COUNT(*) FROM login_attempts WHERE email = 'gammel@example.com'") === 0, 'forsøg over 30 dage slettes');
F001Check::that('AK3', Users::findByEmail('carl@example.com') !== null, 'brugeren er uberørt');

F001Check::done();
