<?php
declare(strict_types=1);

// F004-AK7: "Transcribér dette opkald" kræver login og CSRF, sætter kun "ikke valgt"-opkald i kø og er idempotent.
// Knappen står kun på detaljesiden for "ikke valgt"; svaret er en 303-redirect (PRG) med kvittering.

require_once __DIR__ . '/f004_lib.php';

use Whisper\DB;

f004_setup();
$c = f002_login();
$no = f004_insert(4101, false, 'ikke_valgt');
$done = f004_insert(4102, true, 'faerdig');
$z = f002_insert();
$status = static fn(int $id): string => (string) DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$id]);

$page = f002_get($c, '/opkald/' . $no, ['vis' => 'alle']);
F004Check::that('AK7', str_contains($page->body, 'action="/opkald/' . $no . '/transcriber"') && str_contains($page->body, 'Transcribér dette opkald'),
    'knappen står på detaljen for "ikke valgt"');
F004Check::that('AK7', str_contains($page->body, 'name="_csrf"') || str_contains($page->body, 'name="' . \Whisper\Csrf::FIELD . '"'), 'formularen har CSRF-felt');
F004Check::that('AK7', str_contains($page->body, 'name="ctx" value="vis=alle"'), 'formularen bærer listens kontekst');
F004Check::that('AK7', f005_no_inline($page), 'ingen inline JS/CSS (CSP)');
F004Check::that('AK7', !str_contains(f002_get($c, '/opkald/' . $done)->body, '/transcriber"'), 'ingen knap på et færdigt opkald');
F004Check::that('AK7', !str_contains(f002_get($c, '/', ['vis' => 'alle'])->body, '/transcriber"'), 'ingen knap i listen (D-F4-3)');

// Uden CSRF: 403, intet ændres
$r = $c->post('/opkald/' . $no . '/transcriber', [], false);
F004Check::that('AK7', $r->status === 403 && $status($no) === 'ikke_valgt', 'uden CSRF: 403 og intet ændres');

// Uden login: redirect til login, intet ændres
$anon = new F001Client('10.0.0.9');
$anon->get('/login');
$r = $anon->post('/opkald/' . $no . '/transcriber');
F004Check::that('AK7', $r->status === 303 && ($r->headers['Location'] ?? '') === '/login' && $status($no) === 'ikke_valgt', 'uden login: til login og intet ændres');

// Med login og CSRF: i kø, PRG til detaljen med konteksten
$r = $c->post('/opkald/' . $no . '/transcriber', ['ctx' => 'vis=alle&q=x"><script>']);
F004Check::that('AK7', $r->status === 303 && $status($no) === 'ventende', 'sat i kø (303)');
$loc = $r->headers['Location'] ?? '';
F004Check::that('AK7', str_starts_with($loc, '/opkald/' . $no . '?') && str_contains($loc, 'vis=alle') && !str_contains($loc, '<'), 'redirect til detaljen med renset kontekst');
$after = f002_get($c, '/opkald/' . $no);
F004Check::that('AK7', str_contains($after->body, 'Opkaldet er sat i kø.') && !str_contains($after->body, '/transcriber"'), 'kvittering, og knappen er væk');
F004Check::that('AK7', str_contains($after->body, 'Venter på lyd'), 'badget er "Venter på lyd"');

// Idempotent: igen → "allerede i kø"; et færdigt → "allerede transcriberet"; intet ændres
$r = $c->post('/opkald/' . $no . '/transcriber');
F004Check::that('AK7', $r->status === 303 && $status($no) === 'ventende', 'anden gang: ingen ændring');
F004Check::that('AK7', str_contains(f002_get($c, '/opkald/' . $no)->body, 'Opkaldet er allerede i kø.'), 'kvittering "allerede i kø"');
$r = $c->post('/opkald/' . $done . '/transcriber');
F004Check::that('AK7', $r->status === 303 && $status($done) === 'faerdig', 'færdigt opkald ændres ikke');
F004Check::that('AK7', str_contains(f002_get($c, '/opkald/' . $done)->body, 'Opkaldet er allerede transcriberet.'), 'kvittering "allerede transcriberet"');

// Kun Adversus-opkald; ukendt id → 404; GET → 405
$r = $c->post('/opkald/' . $z . '/transcriber');
F004Check::that('AK7', $r->status === 404 && $status($z) === 'ventende', 'Zendesk-opkald: 404');
F004Check::that('AK7', $c->post('/opkald/999999/transcriber')->status === 404, 'ukendt opkald: 404');
F004Check::that('AK7', $c->get('/opkald/' . $no . '/transcriber')->status === 405, 'GET er ikke tilladt');
F004Check::done();
