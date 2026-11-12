<?php
declare(strict_types=1);

// F004a-AK8: [adversus] success_status valideres som [A-Za-z_-]{1,40}. En ugyldig værdi giver en fejl, der ikke viser
// værdien, exit ≠ 0 og intet kald til Adversus. Uden linjen bruges "success"; en gyldig værdi bruges i filteret.

require_once __DIR__ . '/f004a_lib.php';

use Whisper\Adversus\AdversusConfig;

f004a_setup();

$bad = ['succ ess', 'success";DROP', str_repeat('a', 41), 'süccess', '"}', 'success1', 'status=success', "succ\tess", '{"$ne":1}'];
foreach ($bad as $v) {
    $api = f004a_api();
    $r = f004a_run_api($api, ['success_status' => $v] + f004a_section());
    $shown = trim($v) !== '' && str_contains($r['out'], trim($v));
    F004aCheck::that('AK8', $r['code'] !== 0 && str_contains($r['out'], 'success_status er ugyldig') && !$shown && $api->requests === [],
        'ugyldig success_status afvist uden at vise værdien: ' . json_encode($v));
}

// Standard: success
$cfg = AdversusConfig::fromSection(f004a_section());
F004aCheck::that('AK8', $cfg !== null && $cfg->successStatus === 'success', 'standardværdien er success');

// Gyldig egen værdi (med mellemrum omkring) bruges i filteret
$api = f004a_api();
foreach ($api->leads as $i => $l) {
    if ($l['status'] === 'success') {
        $api->leads[$i]['status'] = 'Salg_OK-';
    }
}
$r =f004a_run_api($api, ['success_status' => ' Salg_OK- '] + f004a_section());
$succQ = array_values(array_filter($api->to(F004A_API . '/leads?'), static fn(array $q): bool => str_contains(rawurldecode($q['url']), '"status"')));
F004aCheck::that('AK8', $r['code'] === 0 && $succQ !== [] && str_contains(rawurldecode($succQ[0]['url']), '{"status":{"$eq":"Salg_OK-"}'),
    'gyldig værdi trimmes og bruges i status-filteret');
F004aCheck::that('AK8', str_contains($r['out'], 'Leads med succes (success_status = Salg_OK-, since = 2026-10-01)'), 'den gyldige værdi vises');

F004aCheck::done();
