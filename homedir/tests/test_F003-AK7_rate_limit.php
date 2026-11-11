<?php
declare(strict_types=1);

// F003-AK7: over 60 requests pr. minut pr. nøgle giver 429 med Retry-After. Tællingen er pr. nøgle, og næste minut
// er der plads igen.

require_once __DIR__ . '/f003_lib.php';

use Whisper\DB;

f003_setup();
[, $uid] = f003_login();
$a = new F003Mcp(f003_token($uid, 'A'));
$b = new F003Mcp(f003_token($uid, 'B'));
$a->now = $b->now = F003_NOW - F003_NOW % 60 + 15;   // 15 s inde i et minut

$ok = 0;
for ($i = 0; $i < 60; $i++) {
    $ok += $a->rpc('ping')->status === 200 ? 1 : 0;
}
F003Check::that('AK7', $ok === 60, '60 requests i samme minut er tilladt');

$r = $a->rpc('ping');
F003Check::that('AK7', $r->status === 429, 'nr. 61 giver 429');
F003Check::that('AK7', $r->header('Retry-After') === '45', 'Retry-After = sekunder til næste minut (45)');
F003Check::that('AK7', !str_contains($r->body, 'result'), 'ingen data i 429-svaret');
F003Check::that('AK7', $a->tool('list_recent_calls') === [] , 'også værktøjskald afvises (ingen JSON-RPC-svar)');

F003Check::that('AK7', $b->rpc('ping')->status === 200, 'en anden nøgle har sin egen tæller');

$a->now += 45;
F003Check::that('AK7', $a->rpc('ping')->status === 200, 'næste minut: tilladt igen');

$count = (int) DB::scalar('SELECT `count` FROM mcp_rate WHERE window_start = ?', [F003_NOW - F003_NOW % 60]);
F003Check::that('AK7', $count >= 61, 'afviste requests tælles også (tæl først, tjek bagefter)');

// En ugyldig nøgle tæller ikke på nogen gyldig nøgles kvote
$x = new F003Mcp('wsp_' . str_repeat('A', 43));
$x->now = $a->now;
for ($i = 0; $i < 5; $i++) {
    $x->rpc('ping');
}
F003Check::that('AK7', (int) DB::scalar('SELECT COALESCE(SUM(`count`), 0) FROM mcp_rate WHERE window_start = ?', [$a->now - $a->now % 60]) === 1,
    'ugyldige nøgler skriver ikke i rate-tabellen');

F003Check::done();
