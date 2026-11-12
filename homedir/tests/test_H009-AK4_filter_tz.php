<?php
declare(strict_types=1);

// H009-AK4: [adversus] filter_tz. Standarden (Europe/Copenhagen) giver vintertid +1 t og sommertid +2 t i filtrenes
// cifre, en gyldig egen værdi bruges, en ugyldig giver en fejl og 0 kald, og alle sync's tidsfiltre (også
// lead-cursorens) omregnes.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h009_lib.php';

use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\AdversusError;
use Whisper\Adversus\Diagnose;
use Whisper\DB;

$winter = (int) strtotime('2026-11-12T14:54:42Z');
$summer = (int) strtotime('2026-07-01T10:00:00Z');
$cfg = f004_cfg();
H009Check::that('AK4', $cfg->filterTz->getName() === 'Europe/Copenhagen', 'standard: Europe/Copenhagen');
H009Check::that('AK4', $cfg->filterTime($winter) === '2026-11-12T15:54:42Z', 'vintertid: +1 t (som målt på DEV)');
H009Check::that('AK4', $cfg->filterTime($summer) === '2026-07-01T12:00:00Z', 'sommertid: +2 t');
H009Check::that('AK4', f004_cfg([F004_AUDIO_HOST], null, 'UTC')->filterTime($winter) === '2026-11-12T14:54:42Z', 'UTC (nødvejen): uændret');

$section = ['username' => 'apiuser' . bin2hex(random_bytes(4)), 'password' => 'pw-' . bin2hex(random_bytes(12)), 'since' => '2026-10-01'];
$own = AdversusConfig::fromSection($section + ['filter_tz' => ' America/New_York ']);
H009Check::that('AK4', $own !== null && $own->filterTime($winter) === '2026-11-12T09:54:42Z', 'en gyldig egen zone bruges (trimmet)');
H009Check::that('AK4', AdversusConfig::fromSection($section)?->filterTz->getName() === 'Europe/Copenhagen', 'uden filter_tz: standarden');
$err = null;
try {
    AdversusConfig::fromSection($section + ['filter_tz' => 'Mars/Olympus']);
} catch (AdversusError $e) {
    $err = $e->getMessage();
}
H009Check::that('AK4', $err === '[adversus] filter_tz er ikke en gyldig tidszone (fx Europe/Copenhagen eller UTC)', 'ugyldig zone: fast fejltekst');
$http = new FakeAdversus();
$out = [];
$code = Diagnose::cli(sys_get_temp_dir(), $section + ['filter_tz' => 'Mars/Olympus'], $http, static fn(): array => [], $winter,
    static function (string $l) use (&$out): void {
        $out[] = $l;
    });
H009Check::that('AK4', $code === 1 && count($http->requests) === 0 && $out === ['✘ ' . $err], 'ugyldig zone: exit 1 og 0 kald');

// Alle tidsfiltre i en sync-kørsel er væg-tid (lead-cursoren, /recordings og /calls)
f004_setup();
DB::exec('DELETE FROM adversus_pending');
$S = f004_since();
$http = new FakeAdversus();
f004_recorded($http, 9001, 901, $S + 3600, 120);
f004_lead($http, 901, 'success', $S + 1800);
['sync' => $sync] = f004_sync(f004_cfg(), $http, $S + 4 * 3600);
$r = $sync->run();
$seen = [];
$ok = true;
foreach ($http->requests as $req) {
    parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $q);
    $f = is_string($q['filters'] ?? null) ? json_decode($q['filters'], true) : null;
    foreach (is_array($f) ? $f : [] as $field => $ops) {
        foreach (is_array($ops) ? $ops : [] as $v) {
            if (is_string($v)) {
                $seen[(string) parse_url($req['url'], PHP_URL_PATH) . ' ' . $field] = true;
                $ok = $ok && (int) strtotime($v) - f004_filter_epoch($v) === 3600;
            }
        }
    }
}
ksort($seen);
H009Check::that('AK4', array_keys($seen) === ['/v1/calls start', '/v1/leads lastUpdatedTime', '/v1/recordings timestamp'],
    'filtre på /calls, /leads og /recordings (fik ' . implode(', ', array_keys($seen)) . ')');
H009Check::that('AK4', $ok, 'alle filtertider er dansk væg-tid (cifrene 1 t foran UTC i november)');
H009Check::that('AK4', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE `source` = 'adversus' AND external_id = '9001' AND is_success = 1") === 1
    && (int) DB::scalar('SELECT COUNT(*) FROM adversus_success_leads WHERE lead_id = 901') === 1, 'opkaldet og succes-leadet er fundet');
H009Check::that('AK4', $r['error'] === null, 'kørslen er ok');

H009Check::done();
