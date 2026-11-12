<?php
declare(strict_types=1);

// H012-AK2: et opkald under [whisper] min_seconds (standard 15) får "For kort" og claimes ikke. min_seconds = 0 slår
// reglen fra. Ukendt varighed (0) transcriberes som før. En ugyldig værdi giver standarden og én advarsel. Loglinjen
// tæller de sprungne opkald.

require_once __DIR__ . '/h012_lib.php';

use Whisper\DB;
use Whisper\Transcription\QueueRules;
use Whisper\Ui;

$now = F002_NOW;

// Config: standard, 0, gyldig værdi, ugyldige værdier
[$r, $w] = h012_from([]);
H012Check::that('AK2', $r->minSeconds === 15 && $w === [], 'standard min_seconds = 15 uden advarsel');
[$r, $w] = h012_from(['min_seconds' => '0']);
H012Check::that('AK2', $r->minSeconds === 0 && $w === [], 'min_seconds = 0 godtages');
[$r, $w] = h012_from(['min_seconds' => '120']);
H012Check::that('AK2', $r->minSeconds === 120 && $w === [], 'min_seconds = 120 godtages');
foreach (['121', 'abc', '-1', '1.5'] as $bad) {
    [$r, $w] = h012_from(['min_seconds' => $bad]);
    H012Check::that('AK2', $r->minSeconds === 15 && count($w) === 1
        && str_contains($w[0], '[whisper] min_seconds skal være 0–120 — bruger 15'), "ugyldig min_seconds '$bad': 15 og én advarsel");
}

// Claim: 14 s springes over, 15 s og ukendt (0) tages
f004_setup();
$short = h012_zendesk($now, 600, 14);
$edge = h012_zendesk($now, 700, 15);
$unknown = h012_zendesk($now, 800, 0);
$order = h012_order(new QueueRules(), $now);
H012Check::that('AK2', $order === [$edge, $unknown], 'claim tager 15 s og ukendt varighed, ikke 14 s (fik ' . implode(',', $order) . ')');
H012Check::that('AK2', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$short]) === 'ventende'
    && (int) DB::scalar('SELECT transcript_attempts FROM calls WHERE id = ?', [$short]) === 0, 'det korte opkald er urørt (ventende, 0 forsøg)');
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$short])[0];
H012Check::that('AK2', (new QueueRules())->skipReason($row, $now) === QueueRules::SKIP_SHORT, 'skipReason: kort');

// min_seconds = 0: det korte opkald tages
H012Check::that('AK2', h012_order(new QueueRules(0, 30), $now) === [$short], 'min_seconds = 0: det korte opkald tages');

// Et fejlet kort opkald tages heller ikke igen automatisk
f004_setup();
$fs = h012_zendesk($now, 600, 8, ['transcript_status' => 'fejlet', 'transcript_attempts' => 1]);
H012Check::that('AK2', h012_order(new QueueRules(), $now) === [], 'et fejlet kort opkald prøves ikke igen automatisk');

// Badge: neutral "For kort", kun når lyden er hentet
H012Check::that('AK2', Ui::status('hentet', 'ventende', null, QueueRules::SKIP_SHORT) === ['s-ikke-valgt', 'For kort'], 'badge: For kort (neutral)');
H012Check::that('AK2', Ui::status('venter', 'ventende', null, QueueRules::SKIP_SHORT) === ['s-ventende', 'Venter på lyd'], 'uden lyd: Venter på lyd');
H012Check::that('AK2', Ui::status('hentet', 'ventende') === ['s-ventende', 'Venter'], 'uden skip: Venter som før');

// Loglinjen: én linje med antal pr. regel
f004_setup();
h012_zendesk($now, 600, 10);
h012_zendesk($now, 700, 12);
h012_zendesk($now, 40 * 86400, 125);
h012_zendesk($now, 900, 125);
H012Check::that('AK2', (new QueueRules())->skippedLine($now) === 'transcribe: 3 opkald sprunget over (for korte 2, ældre end 30 dage 1)',
    'loglinjen: ' . ((new QueueRules())->skippedLine($now) ?? '(ingen)'));
H012Check::that('AK2', QueueRules::off()->skippedLine($now) === null, 'uden regler: ingen loglinje');
$cron = (string) file_get_contents(__DIR__ . '/../cron/transcribe.php');
H012Check::that('AK2', str_contains($cron, "\$slot === 1 && (int) gmdate('i') === 0") && str_contains($cron, '$rules->skippedLine(time())'),
    'transcribe.php: loglinjen én gang i timen fra slot 1');

H012Check::done();
