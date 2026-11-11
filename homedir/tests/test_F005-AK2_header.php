<?php
declare(strict_types=1);

// F005-AK2: headeren markerer det aktive punkt med aria-current="page" (detaljesider markerer forælderen), har et
// skip-link til #indhold og en brugermenu til højre (navn, Konto, Log ud).

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;

f005_setup();
$c = f005_admin();
$id = f002_insert();
$uid = (int) DB::scalar('SELECT id FROM users LIMIT 1');

/** @return list<string> */
$current = static function (string $body): array {
    preg_match_all('#<a href="([^"]+)" aria-current="page">#', $body, $m);
    return $m[1];
};
$home = f002_get($c, '/');
F005Check::that('AK2', str_contains($home->body, '<a class="skip" href="#indhold">Spring til indhold</a>')
    && str_contains($home->body, '<main id="indhold"'), 'skip-link til #indhold');
F005Check::that('AK2', str_contains($home->body, '<nav class="main-nav" aria-label="Hovedmenu">'), 'hovedmenu med aria-label');
F005Check::that('AK2', $current($home->body) === ['/'], 'forsiden: Opkald er aktiv');
F005Check::that('AK2', $current(f002_get($c, '/opkald/' . $id)->body) === ['/'], 'detaljesiden markerer forælderen Opkald');
F005Check::that('AK2', $current(f002_get($c, '/brugere')->body) === ['/brugere'], '/brugere: Brugere er aktiv');
F005Check::that('AK2', $current(f002_get($c, '/brugere/' . $uid)->body) === ['/brugere'], 'brugerredigering markerer Brugere');
F005Check::that('AK2', $current(f002_get($c, '/brugere/ny')->body) === ['/brugere'], 'opret bruger markerer Brugere');
F005Check::that('AK2', $current(f002_get($c, '/konto')->body) === ['/konto'], '/konto: Konto er aktiv');
F005Check::that('AK2', preg_match('#<div class="user"><span class="who">[^<]+</span><a href="/konto"[^>]*>Konto</a><form method="post" action="/logout"#',
    $home->body) === 1 && str_contains($home->body, 'Log ud</button>'), 'brugermenu: navn, Konto og Log ud samlet til højre');
F005Check::that('AK2', str_contains($home->body, 'role="status" id="live"'), 'fælles status-region');

// Almindelig bruger: intet Brugere-punkt
$u = f002_login();
F005Check::that('AK2', !str_contains(f002_get($u, '/')->body, 'href="/brugere"'), 'bruger ser ikke Brugere');
F005Check::done();
