<?php
declare(strict_types=1);

// F003-AK5: call_stats tæller korrekt pr. dag (dansk tid), retning og agent og afviser perioder over 366 dage.

require_once __DIR__ . '/f003_lib.php';

f003_setup();
[, $uid] = f003_login();
$m = new F003Mcp(f003_token($uid));

// Tider i UTC; i oktober er dansk tid UTC+2
f002_insert(['started_at' => '2026-10-04 21:00:00']);                                    // 04.10 23:00 — uden for perioden
f002_insert(['started_at' => '2026-10-04 23:59:00']);                                    // 05.10 01:59
f002_insert(['started_at' => '2026-10-05 21:30:00']);                                    // 05.10 23:30
f002_insert(['started_at' => '2026-10-05 22:30:00']);                                    // 06.10 00:30
f002_insert(['started_at' => '2026-10-06 10:00:00', 'direction' => 'outbound', 'agent_name' => 'Agent B']);   // 06.10 12:00
f002_insert(['started_at' => '2026-10-06 22:00:00']);                                    // 07.10 00:00 — uden for perioden

[$err, $text, $s] = f003_result($m->tool('call_stats', ['from' => '2026-10-05', 'to' => '2026-10-06']));
F003Check::that('AK5', !$err && ($s['total'] ?? 0) === 4 && ($s['days'] ?? 0) === 2, '4 opkald på 2 danske døgn');
F003Check::that('AK5', ($s['per_day'] ?? null) === [
    ['date' => '2026-10-05', 'inbound' => 2, 'outbound' => 0, 'total' => 2],
    ['date' => '2026-10-06', 'inbound' => 1, 'outbound' => 1, 'total' => 2],
], 'pr. dag i dansk tid (midnat UTC+2 respekteret)');
F003Check::that('AK5', ($s['per_direction'] ?? null) === ['inbound' => 3, 'outbound' => 1], 'pr. retning');
F003Check::that('AK5', ($s['per_agent'] ?? null) === [['agent' => 'Agent A', 'count' => 3], ['agent' => 'Agent B', 'count' => 1]], 'pr. agent');
F003Check::that('AK5', json_decode($text, true) === $s, 'tekst = structuredContent');

// Tom periode: alle dage med 0
[, , $empty] = f003_result($m->tool('call_stats', ['from' => '2026-01-01', 'to' => '2026-01-03']));
F003Check::that('AK5', ($empty['total'] ?? -1) === 0 && count($empty['per_day'] ?? []) === 3 && ($empty['per_agent'] ?? null) === [],
    'ingen opkald: 0 og tre tomme dage');

// Vintertid: 26.10 er dansk tid UTC+1
f002_insert(['started_at' => '2026-11-02 23:30:00']);                                    // 03.11 00:30
[, , $w] = f003_result($m->tool('call_stats', ['from' => '2026-11-03', 'to' => '2026-11-03']));
F003Check::that('AK5', ($w['total'] ?? 0) === 1, 'vintertid: 23:30 UTC tæller på næste danske dag');

// Lofter og validering
[$e1, $t1] = f003_result($m->tool('call_stats', ['from' => '2025-01-01', 'to' => '2026-01-02']));
F003Check::that('AK5', $e1 && str_contains($t1, 'højst 366 dage'), 'over 366 dage: isError');
[$e2] = f003_result($m->tool('call_stats', ['from' => '2025-01-02', 'to' => '2026-01-02']));
F003Check::that('AK5', !$e2, 'præcis 366 dage er tilladt');
F003Check::that('AK5', f003_result($m->tool('call_stats', ['from' => '2026-10-06', 'to' => '2026-10-05']))[0], 'from efter to: isError');
F003Check::that('AK5', f003_result($m->tool('call_stats', ['from' => '2026-02-30']))[0], 'ugyldig dato: isError');
F003Check::that('AK5', f003_result($m->tool('call_stats', ['agent' => 'x']))[0], 'ukendt argument: isError');

[$e3, , $d] = f003_result($m->tool('call_stats'));
F003Check::that('AK5', !$e3 && ($d['days'] ?? 0) === 30 && ($d['to'] ?? '') === '2027-01-15', 'uden periode: de seneste 30 dage til i dag');

F003Check::done();
