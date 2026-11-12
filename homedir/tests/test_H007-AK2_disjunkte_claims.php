<?php
declare(strict_types=1);

// H007-AK2: to samtidige claims på samme kø giver disjunkte mængder opkald; intet opkald claimes to gange. Først i
// samme proces (skiftevis batch-claims), derefter med to processer, der starter i samme øjeblik.

require_once __DIR__ . '/h007_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

/** @return list<int> 30 ventende opkald med lyd */
$queue = static function (): array {
    $ids = [];
    for ($i = 0; $i < 30; $i++) {
        $id = f002_insert(['started_at' => gmdate('Y-m-d H:i:s', 1790000000 + $i * 60), 'duration_sec' => 60]);
        f002_audio($id);
        $ids[] = $id;
    }
    return $ids;
};

// Samme proces: to Transcribere claimer skiftevis batches
f002_setup();
$all = $queue();
$clock = new stdClass();
$clock->now = F002_NOW;
$ta = new Transcriber(new FakeBatchEngine($clock), static fn(): int => F002_NOW, static fn(string $l) => null);
$tb = new Transcriber(new FakeBatchEngine($clock), static fn(): int => F002_NOW, static fn(string $l) => null);
$ga = [];
$gb = [];
for ($i = 0; $i < 4; $i++) {
    $ga = array_merge($ga, array_column($ta->claim(5), 'id'));
    $gb = array_merge($gb, array_column($tb->claim(5), 'id'));
}
H007Check::that('AK2', array_intersect($ga, $gb) === [] && count($ga) + count($gb) === 30
    && count(array_unique(array_merge($ga, $gb))) === 30, 'skiftevis claim: disjunkte mængder, alle 30 claimet');

// To processer på samme tid
f002_setup();
$all = $queue();
$at = sprintf('%.3f', microtime(true) + 1.5);
$procs = [];
$pipes = [];
foreach ([0, 1] as $k) {
    $procs[$k] = proc_open([PHP_BINARY, __DIR__ . '/h007_claim.php', $at], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
    $pipes[$k] = $p;
}
$got = [];
foreach ([0, 1] as $k) {
    $out = is_resource($procs[$k]) ? (string) stream_get_contents($pipes[$k][1]) . (string) stream_get_contents($pipes[$k][2]) : '';
    $code = is_resource($procs[$k]) ? proc_close($procs[$k]) : -1;
    $ids = json_decode(trim($out), true);
    H007Check::that('AK2', $code === 0 && is_array($ids), 'proces ' . ($k + 1) . ' claimede uden fejl (' . trim($out) . ')');
    $got[$k] = is_array($ids) ? array_map('intval', $ids) : [];
}
H007Check::that('AK2', array_intersect($got[0], $got[1]) === [], 'samtidige processer: intet opkald hos begge');
$union = array_merge($got[0], $got[1]);
sort($union);
$want = $all;
sort($want);
H007Check::that('AK2', $union === $want, 'samtidige processer: alle 30 claimet præcis én gang ('
    . count($got[0]) . ' + ' . count($got[1]) . ')');
H007Check::that('AK2', (int) DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_attempts <> 1") === 0,
    'hvert opkald har brugt præcis ét forsøg');

H007Check::done();
