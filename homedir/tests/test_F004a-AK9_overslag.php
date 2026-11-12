<?php
declare(strict_types=1);

// F004a-AK9: overslaget = antal succes-leads × gennemsnitligt antal optagede opkald pr. målt lead, og det er tydeligt
// mærket som et overslag. Er nogle opkald ikke tjekket, står der "mindst".

require_once __DIR__ . '/f004a_lib.php';

f004a_setup();

// 1. Standarddata: 30 succes-leads, 4 optagede opkald på 5 leads → 30 × 0,80 = 24
$r = f004a_run_api(f004a_api(), f004a_section());
F004aCheck::that('AK9', str_contains($r['out'],
    'OVERSLAG (ikke en optælling): ca. 24 optagelser at transcribere siden 2026-10-01 = 30 succes-leads × 0,80 optagede opkald pr. lead (gennemsnit af 5 lead(s))'),
    'overslag 30 × 0,80 = 24, mærket som overslag');
F004aCheck::that('AK9', !str_contains($r['out'], 'mindst'), 'alle opkald tjekket: intet "mindst"');

// 2. 7 succes-leads; lead 5001 har 12 opkald med samtale, alle optaget, ingen i /recordings. Højst 8 opslag →
//    8 optaget, 4 ikke tjekket på lead 1; lead 2–5 har ingen opkald. 8 / 5 = 1,60 → 7 × 1,6 = 11,2 → 11
$api = f004a_api();
$api->leads = [];
for ($i = 1; $i <= 7; $i++) {
    $api->leads[] = f004a_lead(5000 + $i, 'success');
}
$api->calls = [];
$api->recordings = [];
$api->hasRecording = [];
for ($i = 1; $i <= 12; $i++) {
    $api->calls[] = f004a_lead_call(700 + $i, 5001, 10 * $i);
    $api->hasRecording[700 + $i] = true;
}
$r = f004a_run_api($api, f004a_section());
echo $r['out'], "\n";
F004aCheck::that('AK9', str_contains($r['out'], 'Succes-lead 1: opkald 12 · samtale (s): 120, 110, 100, 90, 80, 70, 60, 50, 40, 30, … · optaget: 8 (0 fra /recordings, 8 via /calls/{id}/recording) · ikke tjekket: 4'),
    'højst 8 opslag; længste samtaler først; resten "ikke tjekket"');
F004aCheck::that('AK9', str_contains($r['out'],
    'OVERSLAG (ikke en optælling): ca. 11 optagelser at transcribere siden 2026-10-01 = 7 succes-leads × 1,60 optagede opkald pr. lead (gennemsnit af 5 lead(s)) — mindst: 4 opkald blev ikke tjekket'),
    'overslag 7 × 1,60 = 11 med "mindst"');
F004aCheck::that('AK9', count($api->to(F004A_API . '/calls/7')) === 8, 'præcis 8 opslag i /calls/{id}/recording (fik ' . count($api->to(F004A_API . '/calls/7')) . ')');

// 3. Ingen succes-leads → intet overslag
$api = f004a_api();
$api->leads = [f004a_lead(6001, 'notInterested')];
$r = f004a_run_api($api, f004a_section());
F004aCheck::that('AK9', str_contains($r['out'], 'Succes-leads siden 2026-10-01: 0') && str_contains($r['out'], 'OVERSLAG: kan ikke beregnes — ingen succes-leads at måle på'),
    'ingen succes-leads: intet overslag');

F004aCheck::done();
