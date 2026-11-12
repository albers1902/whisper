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

// Tilbage-linket hedder "Tilbage til opkald", også uden kontekst (UX Finding 1); info-kvittering har role="status"
$plain = f002_get($c, '/opkald/' . $done);
F004Check::that('AK7', str_contains($plain->body, 'Tilbage til opkald</a>') && !str_contains($plain->body, 'Alle opkald</a></p>'), 'tilbage-link uden ctx');
$c->post('/opkald/' . $no . '/transcriber');
F004Check::that('AK7', str_contains(f002_get($c, '/opkald/' . $no)->body, '<div class="msg info" role="status">'), 'info-kvittering læses op (role="status")');

// Fejlet lyd (QA F-2): knappen vises igen og henter lyden igen med nulstillede forsøg; idempotent
$failed = f004_insert(4103, true, 'ventende');
DB::exec("UPDATE calls SET audio_status = 'fejlet', audio_attempts = 5, audio_error = 'HTTP 404' WHERE id = ?", [$failed]);
$fp = f002_get($c, '/opkald/' . $failed);
F004Check::that('AK7', str_contains($fp->body, 'action="/opkald/' . $failed . '/transcriber"') && str_contains($fp->body, 'Lyden hentes igen fra Adversus'),
    'knappen vises ved fejlet lyd');
$r = $c->post('/opkald/' . $failed . '/transcriber');
$row = DB::all('SELECT audio_status, audio_attempts, audio_error, transcript_status FROM calls WHERE id = ?', [$failed])[0];
F004Check::that('AK7', $r->status === 303 && $row['audio_status'] === 'venter' && (int) $row['audio_attempts'] === 0 && $row['audio_error'] === null
    && $row['transcript_status'] === 'ventende', 'lyden sat til venter med nulstillede forsøg');
F004Check::that('AK7', str_contains(f002_get($c, '/opkald/' . $failed)->body, 'Lyden hentes igen fra Adversus.'), 'kvittering for ny hentning');
$c->post('/opkald/' . $failed . '/transcriber');
F004Check::that('AK7', (int) DB::scalar('SELECT audio_attempts FROM calls WHERE id = ?', [$failed]) === 0
    && str_contains(f002_get($c, '/opkald/' . $failed)->body, 'Opkaldet er allerede i kø.'), 'anden gang: ingen ændring');

// Loft over manuelt valgte i gang (security S1): ved 50 ændres intet
for ($k = 0; $k < \Whisper\CallPages::MANUAL_MAX; $k++) {
    f004_insert(46000 + $k, false, 'ventende');
}
$extra = f004_insert(4104, false, 'ikke_valgt');
$r = $c->post('/opkald/' . $extra . '/transcriber');
F004Check::that('AK7', $r->status === 303 && $status($extra) === 'ikke_valgt', 'ved loftet: intet sat i kø');
F004Check::that('AK7', str_contains(f002_get($c, '/opkald/' . $extra)->body, 'Der er allerede mange opkald i kø. Prøv igen senere.'), 'besked ved loftet');
// $no + 50 = 51 manuelle i kø; to bliver succes → 49 under loftet
DB::exec("UPDATE calls SET is_success = 1 WHERE external_id IN ('46000', '46001') AND `source` = 'adversus'");
$c->post('/opkald/' . $extra . '/transcriber');
F004Check::that('AK7', $status($extra) === 'ventende', 'under loftet igen (succes tæller ikke med): sat i kø');

// Kun Adversus-opkald; ukendt id → 404; GET → 405
$r = $c->post('/opkald/' . $z . '/transcriber');
F004Check::that('AK7', $r->status === 404 && $status($z) === 'ventende', 'Zendesk-opkald: 404');
F004Check::that('AK7', $c->post('/opkald/999999/transcriber')->status === 404, 'ukendt opkald: 404');
F004Check::that('AK7', $c->get('/opkald/' . $no . '/transcriber')->status === 405, 'GET er ikke tilladt');
F004Check::done();
