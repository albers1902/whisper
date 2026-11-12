<?php
declare(strict_types=1);

// F004a-AK10: en kørsel bruger højst 40 kald til Adversus (talt med en falsk transport), også når alle lister er store,
// optagelsen redirecter i ring, og når Adversus svarer 429 på hvert andet kald (så stopper proben pænt ved loftet).

require_once __DIR__ . '/f004a_lib.php';

f004a_setup();
$cdn = 'media.example-cdn.test';

/** Det største realistiske udtræk: alle lister fylder mere end 5 sider, alle succes-leads har mange optagede opkald. */
$big = static function (): F004aApi {
    $api = new F004aApi();
    for ($i = 1; $i <= 800; $i++) {
        $api->leads[] = f004a_lead($i, $i % 3 === 0 ? 'success' : 'notInterested');
    }
    for ($i = 1; $i <= 700; $i++) {
        $leadId = 3 * (1 + intdiv($i - 1, 20));   // 20 opkald pr. succes-lead (3, 6, 9, …)
        $api->calls[] = f004a_lead_call($i, $leadId, 30 + $i % 50);
        $api->hasRecording[$i] = true;
        $api->recordings[] = f004a_recording(100000 + $i);   // optagelser på andre opkald: intet match
    }
    $api->hasRecording[100001] = true;   // optagelsen i afsnit 4 redirecter i ring hos optagelsesværten
    return $api;
};

// 1. Stort udtræk, optagelsesværten godkendt (så redirect-løkken bruger det maksimale antal kald)
$api = $big();
$r = f004a_run_api($api, f004a_section([$cdn]));
echo $r['out'], "\n";
$n = count($api->requests);
F004aCheck::that('AK10', $n <= 40, "højst 40 kald (fik $n)");
F004aCheck::that('AK10', $r['code'] === 0 && str_contains($r['out'], "Kald til Adversus i denne kørsel: $n (loft for kørslen: 40"), 'antallet vises og stemmer');
F004aCheck::that('AK10', str_contains($r['out'], 'for mange redirects') && str_contains($r['out'], 'OVERSLAG (ikke en optælling)'),
    'alle afsnit nås inden for loftet');

// 2. 429 på hvert andet kald: loftet stopper proben pænt
$api = $big();
$api->flaky = true;
$r = f004a_run_api($api, f004a_section([$cdn]));
echo "--- 429 hvert andet kald\n", $r['out'], "\n";
$n = count($api->requests);
F004aCheck::that('AK10', $n <= 40, "højst 40 kald med 429 (fik $n)");
F004aCheck::that('AK10', $r['code'] === 1 && str_contains($r['out'], 'kaldsgrænsen for kørslen (40 kald) er nået') && str_ends_with($r['out'], 'PROBE: FÆRDIG'),
    'stopper pænt ved loftet');

// 3. --call bruges som før og holder sig også under loftet
$api = $big();
$r = f004a_run_api($api, f004a_section([$cdn]), ['--call=5']);
F004aCheck::that('AK10', count($api->requests) <= 40 && !str_contains($r['out'], 'Leads med succes'), '--call: under loftet og uden succes-sektionen');

F004aCheck::done();
