<?php
declare(strict_types=1);

// H012-AK3: et opkald, der startede for over [whisper] max_age_days (standard 30) dage siden, får "Ikke automatisk" og
// claimes ikke. max_age_days = 0 slår reglen fra. Gamle opkald forsvinder ikke: de vises i listen og kan afspilles.

require_once __DIR__ . '/h012_lib.php';

use Whisper\DB;
use Whisper\Transcription\QueueRules;
use Whisper\Ui;

$now = F002_NOW;
$day = 86400;

// Config
[$r, $w] = h012_from([]);
H012Check::that('AK3', $r->maxAgeDays === 30 && $w === [], 'standard max_age_days = 30');
[$r, $w] = h012_from(['max_age_days' => '0']);
H012Check::that('AK3', $r->maxAgeDays === 0 && $w === [], 'max_age_days = 0 godtages');
[$r, $w] = h012_from(['max_age_days' => '3650']);
H012Check::that('AK3', $r->maxAgeDays === 3650 && $w === [], 'max_age_days = 3650 godtages');
foreach (['3651', 'tredive', ''] as $bad) {
    [$r, $w] = h012_from(['max_age_days' => $bad]);
    $want = $bad === '' ? 0 : 1;   // en tom nøgle er som en manglende
    H012Check::that('AK3', $r->maxAgeDays === 30 && count($w) === $want, "max_age_days '$bad': 30 og $want advarsel");
}

// Claim: 31 dage springes over, 29 dage tages
f004_setup();
$old = h012_zendesk($now, 31 * $day);
$young = h012_zendesk($now, 29 * $day);
$oldAdv = h012_adversus(5301, true, $now, 40 * $day);
H012Check::that('AK3', h012_order(new QueueRules(), $now) === [$young], 'claim tager 29 dage, ikke 31 og 40 dage (begge kilder)');
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$old])[0];
H012Check::that('AK3', $row['transcript_status'] === 'ventende' && (int) $row['transcript_attempts'] === 0, 'det gamle opkald er urørt');
H012Check::that('AK3', (new QueueRules())->skipReason($row, $now) === QueueRules::SKIP_OLD, 'skipReason: gammel');
H012Check::that('AK3', (new QueueRules(15, 30))->skipReason(['transcript_status' => 'ventende', 'duration_sec' => 5,
    'started_at' => h012_ago($now, 40 * $day)], $now) === QueueRules::SKIP_SHORT, 'kort og gammel: "for kort" vinder');
H012Check::that('AK3', (new QueueRules())->skipReason(['transcript_status' => 'faerdig', 'duration_sec' => 5,
    'started_at' => h012_ago($now, 40 * $day)], $now) === null, 'et færdigt opkald er aldrig sprunget over');

// max_age_days = 0: de gamle tages
$order = h012_order(new QueueRules(15, 0), $now);
sort($order);
$want = [$old, $oldAdv];
sort($want);
H012Check::that('AK3', $order === $want, 'max_age_days = 0: de gamle opkald tages');

// Badge og sider (rigtig tid: siderne bruger time())
H012Check::that('AK3', Ui::status('hentet', 'ventende', null, QueueRules::SKIP_OLD) === ['s-ikke-valgt', 'Ikke automatisk'], 'badge: Ikke automatisk');
f004_setup();
QueueRules::override(new QueueRules());
$c = f002_login();
$t = time();
$gammel = h012_zendesk($t, 45 * $day, 125, ['customer_phone' => '+45 3000 0045']);
$list = f002_get($c, '/', ['vis' => 'alle'])->body;
H012Check::that('AK3', str_contains($list, '+45 3000 0045') && str_contains($list, '<span class="badge s-ikke-valgt">Ikke automatisk</span>'),
    'listen viser det gamle opkald med "Ikke automatisk"');
$detail = f002_get($c, '/opkald/' . $gammel)->body;
H012Check::that('AK3', str_contains($detail, 'Ikke automatisk') && str_contains($detail, 'ældre end 30 dage') && str_contains($detail, '<audio'),
    'detaljen viser status, årsag og afspiller');
$audio = f002_get($c, '/opkald/' . $gammel . '/lyd');
H012Check::that('AK3', $audio->status === 200, 'lyden kan afspilles (' . $audio->status . ')');

H012Check::done();
