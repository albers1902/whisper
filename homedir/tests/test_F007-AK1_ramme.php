<?php
declare(strict_types=1);

// F007-AK1: alle indloggede sider har den nye ramme — mørk sidebar med Hovedmenu og kø-kort, topbar med menu-knap under
// 900 px — og app-v2.css/app-v2.js i stedet for F005's assets. Login, konsent og fejlsider har ingen sidebar. Lyst og
// mørkt tema findes i CSS'en.

require_once __DIR__ . '/f007_lib.php';

use Whisper\DB;

f007_setup();
$c = f005_admin();
$id = f002_insert(['started_at' => '2026-11-12 08:03:00']);
f002_audio($id);
f002_transcript($id, 'Hej og velkommen.');
$uid = (int) DB::scalar('SELECT id FROM users LIMIT 1');

foreach (['/', '/?kilde=adversus', '/opkald/' . $id, '/konto', '/brugere', '/brugere/ny', '/brugere/' . $uid] as $url) {
    [$path, $qs] = array_pad(explode('?', $url, 2), 2, '');
    parse_str($qs, $q);
    $r = f007_get($c, $path, $q);
    $b = $r->body;
    F007Check::that('AK1', $r->status === 200 && str_contains($b, '<aside class="sidebar" id="sidemenu">')
        && str_contains($b, '<nav class="side-nav" aria-label="Hovedmenu">') && str_contains($b, '<header class="topbar">')
        && str_contains($b, 'aria-controls="sidemenu"') && str_contains($b, '<section class="q-card" aria-labelledby="ko-h">'),
        "$url: sidebar, Hovedmenu, topbar med menu-knap og kø-kort");
    F007Check::that('AK1', preg_match('#<link rel="stylesheet" href="/assets/app-v2\.css\?v=[0-9]+">#', $b) === 1
        && preg_match('#<script src="/assets/app-v2\.js\?v=[0-9]+" defer></script>#', $b) === 1
        && !str_contains($b, '/assets/app.css') && !str_contains($b, '/assets/app.js'), "$url: kun v2-assets");
    F007Check::that('AK1', str_contains($b, '<main id="indhold" tabindex="-1">') && str_contains($b, 'Spring til indhold')
        && str_contains($b, 'id="live"'), "$url: skip-link, main og #live");
}
$home = f007_get($c, '/');
F007Check::that('AK1', str_contains($home->body, '<a href="/" aria-current="page">') && str_contains($home->body, '>Brugere</span>')
    && str_contains($home->body, 'Administrator') && str_contains($home->body, '<form method="post" action="/logout" class="side-logout">'),
    'opkald er aktivt punkt; admin ser Brugere; rolle og Log ud (POST) i bunden');

$anon = new F001Client('10.0.0.8');
$login = $anon->get('/login');
F007Check::that('AK1', str_contains($login->body, 'class="plain"') && !str_contains($login->body, 'class="sidebar"')
    && str_contains($login->body, '/assets/app-v2.css'), 'login: nyt udseende uden sidebar');
$err = f007_get($c, '/findes-ikke');
F007Check::that('AK1', $err->status === 404 && !str_contains($err->body, 'class="sidebar"') && str_contains($err->body, 'class="plain"'),
    'fejlside: ingen sidebar');
$missing = f007_get($c, '/opkald/999999');
F007Check::that('AK1', $missing->status === 404 && !str_contains($missing->body, 'class="sidebar"'), '404 for opkald: ingen sidebar');

$css = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.css');
F007Check::that('AK1', str_contains($css, '@media (prefers-color-scheme: dark)') && str_contains($css, '@media (max-width: 899px)')
    && preg_match('/\.topbar\s*\{\s*display: none;/', $css) === 1 && str_contains($css, '.js .sidebar { display: none; }')
    && str_contains($css, '.js.menu-open .sidebar { display: flex; }'), 'CSS: mørkt tema, topbar under 900 px, menu skjult kun med JS');
$js = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.js');
F007Check::that('AK1', str_contains($js, "setAttribute('aria-expanded'") && str_contains($js, "ev.key !== 'Escape'"),
    'JS: menu-knappen sætter aria-expanded, Esc lukker');
F007Check::done();
