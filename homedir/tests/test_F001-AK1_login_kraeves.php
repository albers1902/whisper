<?php
declare(strict_types=1);

// F001-AK1: anonyme requests til /, /brugere og /konto omdirigeres til /login; /health er uændret uden login.

require_once __DIR__ . '/f001_lib.php';

use Whisper\DB;
use Whisper\Health;

f001_setup();
$c = new F001Client();

foreach (['/', '/brugere', '/konto', '/brugere/ny', '/brugere/1', '/konto/'] as $path) {
    $r = $c->get($path);
    F001Check::that('AK1', $r->status === 303 && $r->header('Location') === '/login', "anonym GET $path → 303 /login (fik {$r->status})");
}
F001Check::that('AK1', $c->session->all() === [], 'anonyme GET skriver intet i sessionen');
$r = $c->app->handle(new \Whisper\Request('HEAD', '/', [], $c->ip), $c->session);
F001Check::that('AK1', $r->status === 303 && $r->header('Location') === '/login', 'anonym HEAD / → 303 /login (HEAD som GET)');
$r = $c->app->handle(new \Whisper\Request('HEAD', '/login', [], $c->ip), $c->session);
F001Check::that('AK1', $r->status === 200, 'HEAD /login → 200');
$r = $c->post('/brugere/ny', ['email' => 'x@example.com', 'name' => 'X', 'role' => 'admin']);
F001Check::that('AK1', $r->status === 303 && $r->header('Location') === '/login', 'anonym POST /brugere/ny → /login');
F001Check::that('AK1', (int) DB::scalar('SELECT COUNT(*) FROM users') === 0, 'anonym POST oprettede ingen bruger');

$r = $c->get('/login');
F001Check::that('AK1', $r->status === 200 && str_contains($r->body, 'name="password"'), '/login vises uden login');
F001Check::that('AK1', $r->header('Cache-Control') === 'no-store' && str_contains((string) $r->header('Content-Security-Policy'), "frame-ancestors 'none'"),
    'sikkerheds-headere sat');

// /health: rewrite-reglen står før front controlleren og stopper der, og health.php kender intet til login
$ht = (string) file_get_contents(__DIR__ . '/../public_html/.htaccess');
$posHealth = strpos($ht, 'RewriteRule ^health/?$ health.php [L]');
$posFront = strpos($ht, 'RewriteRule ^ index.php [L]');
F001Check::that('AK1', $posHealth !== false && $posFront !== false && $posHealth < $posFront, '.htaccess: /health før front controlleren');
F001Check::that('AK1', str_contains($ht, 'RewriteCond %{REQUEST_FILENAME} !-f'), '.htaccess: eksisterende filer (css, health.php) sendes ikke til index.php');
$hp = (string) file_get_contents(__DIR__ . '/../public_html/health.php');
F001Check::that('AK1', !str_contains($hp, 'Session') && !str_contains($hp, 'Flags') && !str_contains($hp, 'App'), 'health.php bruger hverken session, flag eller App');
$rep = Health::report(sys_get_temp_dir(), static fn(): PDO => DB::pdo());
F001Check::that('AK1', $rep['db_read'] === 'ok' && $rep['db_write'] === 'ok', '/health-rapporten virker stadig mod databasen');

F001Check::done();
