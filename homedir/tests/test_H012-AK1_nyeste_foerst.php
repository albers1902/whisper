<?php
declare(strict_types=1);

// H012-AK1: claim tager det nyeste ventende opkald først. Prioritetsgrupperne er uændrede: fejlet sidst, manuelt valgte
// efter de automatiske. Lydhentningen fra Zendesk tager også nyeste først.

require_once __DIR__ . '/h012_lib.php';

use Whisper\DB;
use Whisper\Transcription\QueueRules;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Sync;

$now = F002_NOW;
$day = 86400;

foreach (['standardregler' => new QueueRules(), 'uden regler' => QueueRules::off()] as $name => $rules) {
    f004_setup();
    $zOld = h012_zendesk($now, 3 * $day);
    $zNew = h012_zendesk($now, 3600);
    $aSucc = h012_adversus(5101, true, $now, 2 * 3600);
    $manual = h012_adversus(5102, false, $now, 600);   // nyest, men manuelt valgt
    $failed = h012_zendesk($now, 300, 125, ['transcript_status' => 'fejlet', 'transcript_attempts' => 1]);
    $order = h012_order($rules, $now);
    H012Check::that('AK1', $order === [$zNew, $aSucc, $zOld, $manual, $failed],
        "$name: nyeste først, manuelt valgt efter de automatiske, fejlet sidst (fik " . implode(',', $order) . ')');
}

// Den atomiske UPDATE holder rækkefølgen: claim(1) tager det nyeste
f004_setup();
$older = h012_zendesk($now, 7200);
$newest = h012_zendesk($now, 60);
H012Check::that('AK1', h012_order(new QueueRules(), $now) !== [] && DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$newest]) === 'transcriberer',
    'claim tager det nyeste');

// Lydhentningen fra Zendesk: nyeste først
f002_setup();
$cfg = f002_cfg();
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$ids = [];
foreach (['2026-10-01 08:00:00', '2026-10-03 08:00:00', '2026-10-02 08:00:00'] as $i => $at) {
    $ids[$at] = f002_insert(['recording_url' => F002_BASE . '/api/v2/rec/h012-' . $i, 'started_at' => $at]);
    $http->on('GET', F002_BASE . '/api/v2/rec/h012-' . $i, new HttpResult(200, ['content-type' => 'audio/mpeg'], 'ID3' . random_bytes(200)));
}
$sync = new Sync(f002_client($cfg, $http), $cfg, static fn(): int => F002_NOW, static fn(string $l) => null);
$r = $sync->downloadPending();
$urls = array_map(static fn(array $q): string => $q['url'], $http->to(F002_BASE . '/api/v2/rec/'));
H012Check::that('AK1', $r['ok'] === 3 && $urls === [F002_BASE . '/api/v2/rec/h012-1', F002_BASE . '/api/v2/rec/h012-2', F002_BASE . '/api/v2/rec/h012-0'],
    'Zendesk-lyd hentes nyeste først (' . implode(' ', $urls) . ')');

// Kilden: Adversus-hentningen sorterer også nyeste først efter succes
$src = (string) file_get_contents(__DIR__ . '/../src/Adversus/Sync.php');
H012Check::that('AK1', str_contains($src, "'ORDER BY is_success DESC, started_at DESC, id DESC LIMIT '"), 'Adversus-lyd: succes først, derefter nyeste først');

H012Check::done();
