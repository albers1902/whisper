<?php
declare(strict_types=1);

// F004-AK5: med sync_since hentes intet før denne tid: ingen optagelser, opkald eller succes-leads før, og ingen
// filtergrænse under since − 1 s. Uden nøglen er standarden 2026-11-12 00:00 dansk tid; probens since bruges ikke.

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\AdversusError;
use Whisper\DB;

f004_setup();

// Standard og config
$sec = ['username' => 'u' . bin2hex(random_bytes(3)), 'password' => 'p' . bin2hex(random_bytes(8)), 'since' => '2026-10-01'];
$cfg = AdversusConfig::fromSection($sec);
F004Check::that('AK5', $cfg !== null && $cfg->syncSince === strtotime('2026-11-11 23:00:00 UTC'),
    'uden sync_since: 2026-11-12 00:00 Europe/Copenhagen (probens since = 2026-10-01 bruges ikke)');
$cfg2 = AdversusConfig::fromSection($sec + ['sync_since' => '2026-11-20']);
F004Check::that('AK5', $cfg2 !== null && $cfg2->syncSince === strtotime('2026-11-19 23:00:00 UTC'), 'sync_since tolkes som dansk midnat');
try {
    AdversusConfig::fromSection($sec + ['sync_since' => '2026-13-40']);
    F004Check::that('AK5', false, 'ugyldig sync_since afvises');
} catch (AdversusError $e) {
    F004Check::that('AK5', !str_contains($e->getMessage(), '2026-13-40'), 'ugyldig sync_since afvises uden at gentage værdien');
}

// Sommer- og vintertid: midnat på omskiftningsdagene er stadig midnat i dansk tid (QA)
F004Check::that('AK5', AdversusConfig::localMidnight('2026-10-25') === strtotime('2026-10-24 22:00:00 UTC'), 'midnat 2026-10-25 (sommertid ender kl. 3) = 22:00 UTC dagen før');
F004Check::that('AK5', AdversusConfig::localMidnight('2027-03-28') === strtotime('2027-03-27 23:00:00 UTC'), 'midnat 2027-03-28 (sommertid starter kl. 2) = 23:00 UTC dagen før');
F004Check::that('AK5', AdversusConfig::localMidnight('2026-10-26') === strtotime('2026-10-25 23:00:00 UTC'), 'dagen efter vintertid: 23:00 UTC');
// Tidsstempler uden offset er UTC, ikke serverens zone (QA)
F004Check::that('AK5', \Whisper\Adversus\Sync::epoch('2026-11-12T10:00:00') === strtotime('2026-11-12 10:00:00 UTC')
    && \Whisper\Adversus\Sync::epoch('2026-11-12T10:00:00.750Z') === strtotime('2026-11-12 10:00:00 UTC'), 'epoch(): uden offset = UTC, millisekunder rundes ned');
// lag_minutes (LAG kan sættes i config)
$cfgLag = AdversusConfig::fromSection($sec + ['lag_minutes' => '30']);
F004Check::that('AK5', $cfgLag !== null && $cfgLag->lag === 1800 && AdversusConfig::fromSection($sec)?->lag === 600, 'lag_minutes: standard 10 min, kan sættes');

// Data før og efter since
$S = f004_since() + 2 * 86400;    // sync_since = to dage efter standarden
$cfg = f004_cfg([F004_AUDIO_HOST], $S);
$http = new FakeAdversus();
f004_recorded($http, 5001, 801, $S - 7200, 60);            // før since: optaget og uploadet før
f004_recorded($http, 5002, 802, $S - 300, 60, 'outbound', 600);   // opkald før since, men optagelsen uploadet efter
f004_recorded($http, 5003, 803, $S + 600, 60);             // efter since
f004_lead($http, 801, 'success', $S - 3600);               // succes-lead før since
f004_lead($http, 803, 'success', $S + 100);

['sync' => $sync] = f004_sync($cfg, $http, $S + 3 * 3600);
$sync->run();
$ids = array_column(DB::all("SELECT external_id FROM calls WHERE `source` = 'adversus' ORDER BY external_id"), 'external_id');
F004Check::that('AK5', $ids === ['5003'], 'kun opkaldet efter since er gemt (fik ' . implode(',', $ids) . ')');
F004Check::that('AK5', array_map('intval', array_column(DB::all('SELECT lead_id FROM adversus_success_leads'), 'lead_id')) === [803],
    'intet succes-lead før since');

// Ingen filtergrænse under since − 1 s
$lowest = PHP_INT_MAX;
foreach ($http->requests as $r) {
    parse_str((string) parse_url($r['url'], PHP_URL_QUERY), $q);
    $f = is_string($q['filters'] ?? null) ? json_decode($q['filters'], true) : null;
    if (!is_array($f) || !str_contains($r['url'], '/v1/recordings') && !str_contains($r['url'], '/v1/leads')) {
        continue;
    }
    foreach ($f as $ops) {
        if (is_array($ops) && isset($ops['$gt'])) {
            $lowest = min($lowest, f004_filter_epoch((string) $ops['$gt']));   // H009: filtercifre er dansk væg-tid
        }
    }
}
F004Check::that('AK5', $lowest === $S - 1, 'laveste grænse i /recordings og /leads er since − 1 s ($gt, ingen $gte)');
F004Check::done();
