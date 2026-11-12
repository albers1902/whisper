<?php
declare(strict_types=1);

// F004a-AK7: med et API, der understøtter filtrene, vises antal succes-leads siden since og pr. lead antal opkald og
// optagede opkald. Uden filterstøtte (ignoreret eller afvist) står der "kan ikke filtreres", og proben fortsætter.

require_once __DIR__ . '/f004a_lib.php';

f004a_setup();

// 1. Filtrene virker (f004a_api() beskriver de fem leads)
$api = f004a_api();
$r = f004a_run_api($api, f004a_section());
$out = $r['out'];
echo $out, "\n";
F004aCheck::that('AK7', $r['code'] === 0 && str_ends_with($out, 'PROBE: FÆRDIG'), 'exit 0 og PROBE: FÆRDIG');
F004aCheck::that('AK7', str_contains($out, 'Succes-leads siden 2026-10-01: 30 (meta.pagination.total)'), 'antal succes-leads siden since');
$succQ = array_values(array_filter($api->to(F004A_API . '/leads?'), static fn(array $q): bool => str_contains(rawurldecode($q['url']), '"status"')));
F004aCheck::that('AK7', count($succQ) === 1 && str_contains(rawurldecode($succQ[0]['url']), '{"status":{"$eq":"success"},"lastUpdatedTime":{"$gt":"2026-09-30T23:59:59Z"}}'),
    'ét kald med status- og tidsfilter');
F004aCheck::that('AK7', str_contains($out, 'Succes-lead 1: opkald 3 · samtale (s): 120, 45, 0 · optaget: 2 (1 fra /recordings, 1 via /calls/{id}/recording)'), 'lead 1');
F004aCheck::that('AK7', str_contains($out, 'Succes-lead 2: opkald 1 · samtale (s): 300 · optaget: 1 (0 fra /recordings, 1 via'), 'lead 2');
F004aCheck::that('AK7', str_contains($out, 'Succes-lead 3: opkald 2 · samtale (s): 0, 0 · optaget: 0 (0 fra /recordings, 0 via'), 'lead 3');
F004aCheck::that('AK7', str_contains($out, 'Succes-lead 4: opkald 0 · samtale (s): (ingen) · optaget: 0'), 'lead 4 uden opkald');
F004aCheck::that('AK7', str_contains($out, 'Succes-lead 5: opkald 1 · samtale (s): 60 · optaget: 1'), 'lead 5');
$leadQ = array_values(array_filter($api->to(F004A_API . '/calls?'), static fn(array $q): bool => str_contains(rawurldecode($q['url']), '"leadId"')));
F004aCheck::that('AK7', count($leadQ) === 5 && str_contains(rawurldecode($leadQ[0]['url']), '{"leadId":{"$eq":5001}}'), 'ét /calls-kald pr. lead med leadId-filter');
// Opkald, der allerede er set i /recordings, slås ikke op igen; de andre slås op uden at følge 307'en
F004aCheck::that('AK7', count($api->to(F004A_API . '/calls/101/recording')) === 1, 'opkald 101 (fra /recordings) kun hentet én gang (afsnit 4)');
F004aCheck::that('AK7', count($api->to(F004A_API . '/calls/103/recording')) === 1 && count($api->to(F004A_API . '/calls/102/recording')) === 1,
    'de øvrige opkald slået op i /calls/{id}/recording');
F004aCheck::that('AK7', count($api->to('https://media.example-cdn.test')) === 0, '307 fra opslagene følges ikke (ingen godkendt vært, intet hentet)');
foreach (['5001', '5002', 'Jens Hansen', '+4520304050', 'X-Signature', 'groen'] as $v) {
    F004aCheck::that('AK7', !str_contains($out, $v), "ingen id'er eller lead-data i output: $v");
}

// 2. /calls ignorerer leadId-filteret (svarer med alle opkald) → "kan ikke filtreres", proben fortsætter
$api = f004a_api();
$api->leadFilter = false;
$r = f004a_run_api($api, f004a_section());
echo "--- leadId ignoreres\n", $r['out'], "\n";
F004aCheck::that('AK7', str_contains($r['out'], '/calls [leadId]: kan ikke filtreres — filteret ignoreres'), 'ignoreret leadId-filter: kan ikke filtreres');
F004aCheck::that('AK7', str_contains($r['out'], 'OVERSLAG: kan ikke beregnes — opkald kan ikke filtreres på lead'), 'intet overslag uden leadId-filter');
$leadQ = array_filter($api->to(F004A_API . '/calls?'), static fn(array $q): bool => str_contains(rawurldecode($q['url']), '"leadId"'));
F004aCheck::that('AK7', count($leadQ) === 1, 'stopper efter første lead (sparer kald)');
F004aCheck::that('AK7', $r['code'] === 0 && str_ends_with($r['out'], 'PROBE: FÆRDIG'), 'proben fortsætter til slut');

// 3. /calls afviser leadId-filteret (HTTP 400)
$api = f004a_api();
$api->leadFilterReject = 400;
$r = f004a_run_api($api, f004a_section());
F004aCheck::that('AK7', str_contains($r['out'], '/calls [leadId]: HTTP 400') && str_contains($r['out'], 'kan ikke filtreres')
    && $r['code'] === 0 && str_ends_with($r['out'], 'PROBE: FÆRDIG'), 'afvist leadId-filter: kan ikke filtreres, fortsætter');

// 4. /leads ignorerer status-filteret → "kan ikke filtreres"; succes-leads tages fra de skannede sider
$api = f004a_api();
$api->statusFilter = false;
$r = f004a_run_api($api, f004a_section());
echo "--- status ignoreres\n", $r['out'], "\n";
F004aCheck::that('AK7', str_contains($r['out'], 'Succes-leads: status kan ikke filtreres') && str_contains($r['out'], 'antallet kan ikke tælles'),
    'ignoreret status-filter: kan ikke filtreres');
F004aCheck::that('AK7', str_contains($r['out'], 'Succes-leads fundet i de skannede sider: 5') && str_contains($r['out'], 'Succes-lead 1: opkald 3'),
    'succes-leads fra de skannede sider måles alligevel');
F004aCheck::that('AK7', str_contains($r['out'], 'OVERSLAG: kan ikke beregnes — antallet af succes-leads kan ikke tælles') && $r['code'] === 0,
    'intet overslag uden status-filter, exit 0');

// 5. /leads ignorerer tidsfilteret → succes-leads tælles for alle tider og siges tydeligt
$api = f004a_api();
$api->timeFilter = false;
$r = f004a_run_api($api, f004a_section());
F004aCheck::that('AK7', str_contains($r['out'], 'tidsfilteret (lastUpdatedTime): kan ikke filtreres')
    && str_contains($r['out'], 'Succes-leads i alt (alle tider — tidsfilteret virker ikke): 40'), 'ignoreret tidsfilter: alle tider, tydeligt mærket');

F004aCheck::done();
