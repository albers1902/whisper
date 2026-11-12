<?php
declare(strict_types=1);

// H012-AK5: "N venter på transcription" tæller kun opkald, der faktisk kommer i køen: de sprungne (for kort, for
// gammelt) tæller ikke, de manuelt valgte og dem under transcription tæller.

require_once __DIR__ . '/h012_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\Transcription\QueueRules;

$now = F002_NOW;
$day = 86400;

f004_setup();
$ok = h012_zendesk($now, 3600);
h012_zendesk($now, 3600, 9);                                // for kort
h012_zendesk($now, 45 * $day);                              // for gammelt
$picked = h012_zendesk($now, 3600, 9);                      // for kort, men valgt
DB::exec('UPDATE calls SET manual_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $now), $picked]);
h012_adversus(5501, true, $now, 40 * $day);                 // Adversus succes, for gammelt
h012_adversus(5502, false, $now, 40 * $day);                // Adversus valgt (F004): tæller
h012_zendesk($now, 45 * $day, 125, ['transcript_status' => 'transcriberer', 'transcript_attempts' => 1]);   // i gang: tæller
f002_insert(['started_at' => h012_ago($now, 60)]);          // lyden er ikke hentet: tæller ikke (QA F-6)

$st = Calls::status(new QueueRules(), $now);
H012Check::that('AK5', $st['waiting'] === 4, 'med reglerne: 4 venter (fik ' . $st['waiting'] . ')');
H012Check::that('AK5', Calls::status(QueueRules::off(), $now)['waiting'] === 7, 'uden regler: 7 venter, som før H012');

// Tælleren er præcis det, claim tager (+ dem i gang)
$claimed = count(h012_order(new QueueRules(), $now));
H012Check::that('AK5', $claimed === 3, 'claim tager de 3 ventende, tælleren viste (+ 1 i gang)');

// Siden (rigtig tid)
f004_setup();
QueueRules::override(new QueueRules());
$c = f002_login();
$t = time();
h012_zendesk($t, 3600);
h012_zendesk($t, 3600, 5);
h012_zendesk($t, 60 * $day);
$body = f002_get($c, '/')->body;
H012Check::that('AK5', str_contains($body, '1 venter på transcription') && !str_contains($body, '3 venter på transcription'),
    'statuslinjen: 1 venter på transcription');
QueueRules::override(QueueRules::off());
H012Check::that('AK5', str_contains(f002_get($c, '/')->body, '3 venter på transcription'), 'reglerne slået fra: 3 venter');

H012Check::done();
