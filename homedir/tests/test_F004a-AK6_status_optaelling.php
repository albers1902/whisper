<?php
declare(strict_types=1);

// F004a-AK6: status-optællingen på leads viser kun kendte enum-værdier fra Adversus' spec; ukendte vises som
// "(andet)". Højst 5 sider læses, og ingen lead-data når output.

require_once __DIR__ . '/f004a_lib.php';

f004a_setup();
$api = new F004aApi();
// 650 leads siden since (7 sider à 100): proben må kun tælle de første 500. Pr. 10 leads: 4 success, 3 notInterested,
// 1 automaticRedial og 2 med fri tekst i status (persondata-agtigt)
for ($i = 1; $i <= 650; $i++) {
    $m = $i % 10;
    $status = match (true) {
        $m < 4 => 'success',
        $m < 7 => 'notInterested',
        $m === 7 => 'automaticRedial',
        $m === 8 => 'Ring til Jens efter kl 16',
        default => 'VIP',
    };
    $api->leads[] = f004a_lead($i, $status);
}
// 50 ældre leads (før since) bagest: med i "uden filter", men ikke i "siden since"
for ($i = 1; $i <= 50; $i++) {
    $api->leads[] = f004a_lead(9000 + $i, 'invalid', '2026-09-01T00:00:00Z');
}

$r = f004a_run_api($api, f004a_section());
$out = $r['out'];
echo $out, "\n";

F004aCheck::that('AK6', $r['code'] === 0 && str_ends_with($out, 'PROBE: FÆRDIG'), 'exit 0 og PROBE: FÆRDIG');
F004aCheck::that('AK6', str_contains($out, '/leads: status (uden filter, 500 lead(s) på 5 side(r)): (andet) 100, automaticRedial 50, notInterested 150, success 200'),
    'status uden filter: kun enum-værdier, fri tekst som (andet), højst 5 sider');
F004aCheck::that('AK6', str_contains($out, '/leads: status (siden since, 500 lead(s) på 5 side(r)): (andet) 100, automaticRedial 50, notInterested 150, success 200'),
    'status med tidsfilter');
F004aCheck::that('AK6', str_contains($out, '/leads: tidsfilteret (lastUpdatedTime): virker · total 650 (uden filter: 700)'), 'tidsfilteret virker og total vises');
$timeQ = array_values(array_filter($api->to(F004A_API . '/leads?'), static fn(array $q): bool => str_contains(rawurldecode($q['url']), 'lastUpdatedTime')));
F004aCheck::that('AK6', $timeQ !== [] && str_contains(rawurldecode($timeQ[0]['url']), '"lastUpdatedTime":{"$gt":"2026-09-30T23:59:59Z"}'),
    'tidsfilteret er lastUpdatedTime > ét sekund før since (Adversus har ingen $gte)');
$plain = array_filter($api->to(F004A_API . '/leads?'), static fn(array $q): bool => !str_contains($q['url'], 'filters='));
F004aCheck::that('AK6', count($plain) === 5, 'højst 5 sider /leads uden filter (fik ' . count($plain) . ')');

foreach (['Ring til Jens', 'VIP', 'Jens Hansen', '+4520304050', 'groen stroem', '2026-10-15T10', 'TrygEnergi Test'] as $v) {
    F004aCheck::that('AK6', !str_contains($out, $v), "ingen lead-data i output: $v");
}
F004aCheck::that('AK6', str_contains($out, '/leads: felter: active, campaignId') && str_contains($out, 'resultData'), 'kun feltnavne for leads');

F004aCheck::done();
