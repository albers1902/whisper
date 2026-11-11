<?php
declare(strict_types=1);

// F005-AK1: intet style="", <style>, inline <script> eller on*-attribut i noget HTML-svar; CSP'en er uændret, og
// app.js og app.css indlæses fra /assets/. Med flaget slukket er det gamle UI (legacy.css, intet script) uændret.

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Ui;

const F005_CSP = "default-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

f005_setup();
$anon = new F001Client('10.0.0.9');
$login = $anon->get('/login');
F005Check::that('AK1', $login->status === 200 && f005_no_inline($login), 'login: intet inline');
F005Check::that('AK1', $login->header('Content-Security-Policy') === F005_CSP, 'CSP uændret');
F005Check::that('AK1', preg_match('#<link rel="stylesheet" href="/assets/app\.css\?v=[0-9]+">#', $login->body) === 1
    && preg_match('#<script src="/assets/app\.js\?v=[0-9]+" defer></script>#', $login->body) === 1, 'app.css og app.js fra /assets/ (defer)');

$c = f005_admin();
$id = f002_insert(['started_at' => '2026-10-05 08:03:00']);
f002_audio($id);
f002_transcript($id, 'Hej og velkommen.', [['start' => 0.0, 'end' => 2.0, 'text' => 'Hej og velkommen.']]);
$uid = (int) DB::scalar('SELECT id FROM users LIMIT 1');
$pages = [['/', []], ['/', ['q' => 'hej']], ['/opkald/' . $id, []], ['/opkald/' . $id, ['q' => 'hej']], ['/opkald/999999', []],
    ['/konto', []], ['/brugere', []], ['/brugere/ny', []], ['/brugere/' . $uid, []], ['/findes-ikke', []]];
foreach ($pages as [$path, $query]) {
    $r = f002_get($c, $path, $query);
    F005Check::that('AK1', f005_no_inline($r) && $r->header('Content-Security-Policy') === F005_CSP && str_contains($r->body, '/assets/app.js'),
        "$path: intet inline, samme CSP, app.js indlæst");
}
$otp = $c->post('/brugere/ny', ['email' => 'ny@f005.test', 'name' => 'Ny', 'role' => 'user']);
F005Check::that('AK1', $otp->status === 200 && f005_no_inline($otp), 'engangskode-siden: intet inline');

$assets = __DIR__ . '/../public_html/assets/';
$js = (string) file_get_contents($assets . 'app.js');
F005Check::that('AK1', is_file($assets . 'app.css') && $js !== '' && substr_count($js, "\n") < 150, 'app.css findes, app.js under 150 linjer');
F005Check::that('AK1', !str_contains((string) file_get_contents($assets . 'app.css'), 'url('), 'app.css henter intet udefra (ingen url())');

// Flaget slukket: det gamle UI
Flags::override(Ui::FLAG, false);
$old = f002_get($c, '/');
F005Check::that('AK1', str_contains($old->body, '<link rel="stylesheet" href="/assets/legacy.css">') && !str_contains($old->body, 'app.js')
    && str_contains($old->body, 'Download alle (.zip)'), 'flag off: gammelt UI med legacy.css og uden app.js');
F005Check::that('AK1', is_file($assets . 'legacy.css'), 'legacy.css findes');
F005Check::done();
