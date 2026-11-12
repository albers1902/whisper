<?php
declare(strict_types=1);

// H012-AK7: [whisper] source_priority styrer kildens rækkefølge i claim: equal = nyeste først på tværs af kilderne,
// zendesk = Zendesk først, adversus = Adversus først. Fejlet sidst og manuelle valg efter de automatiske gælder under
// alle tre. En ugyldig værdi giver equal og én advarsel.

require_once __DIR__ . '/h012_lib.php';

use Whisper\Transcription\QueueRules;

$now = F002_NOW;

// Config
[$r, $w] = h012_from([]);
H012Check::that('AK7', $r->priority === 'equal' && $w === [], 'standard: equal');
foreach (['equal', 'zendesk', 'adversus', 'Zendesk', ' adversus '] as $v) {
    [$r, $w] = h012_from(['source_priority' => $v]);
    H012Check::that('AK7', $r->priority === strtolower(trim($v)) && $w === [], "source_priority '$v' godtages");
}
foreach (['begge', 'zendesk,adversus', '1'] as $bad) {
    [$r, $w] = h012_from(['source_priority' => $bad]);
    H012Check::that('AK7', $r->priority === 'equal' && count($w) === 1
        && str_contains($w[0], '[whisper] source_priority skal være equal, zendesk eller adversus — bruger equal'), "ugyldig '$bad': equal og én advarsel");
}

/** @return array<string, int> en kø med begge kilder i alle grupper */
$queue = static function () use ($now): array {
    f004_setup();
    return [
        'z1'  => h012_zendesk($now, 3600),                                       // Zendesk, 1 t
        'a2'  => h012_adversus(5701, true, $now, 2 * 3600),                     // Adversus succes, 2 t
        'z3'  => h012_zendesk($now, 3 * 3600),                                   // Zendesk, 3 t
        'a4'  => h012_adversus(5702, true, $now, 4 * 3600),                     // Adversus succes, 4 t
        'am'  => h012_adversus(5703, false, $now, 60),                          // Adversus, manuelt valgt (nyest)
        'zm'  => h012_zendesk($now, 120),                                       // Zendesk, valgt med knappen (sættes nedenfor)
        'zf'  => h012_zendesk($now, 30, 125, ['transcript_status' => 'fejlet', 'transcript_attempts' => 1]),
        'af'  => h012_adversus(5704, true, $now, 40),                           // Adversus succes, fejlet (sættes nedenfor)
    ];
};
$prepare = static function (array $q) use ($now): void {
    \Whisper\DB::exec('UPDATE calls SET manual_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $now), $q['zm']]);
    \Whisper\DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_attempts = 1 WHERE id = ?", [$q['af']]);
};
$names = static function (array $q, array $order): string {
    $flip = array_flip($q);
    return implode(' ', array_map(static fn(int $id): string => $flip[$id] ?? '?', $order));
};

$want = [
    'equal'    => 'z1 a2 z3 a4 am zm zf af',
    'zendesk'  => 'z1 z3 zm a2 a4 am zf af',
    'adversus' => 'a2 a4 am z1 z3 zm af zf',
];
foreach ($want as $priority => $expected) {
    $q = $queue();
    $prepare($q);
    $got = $names($q, h012_order(new QueueRules(15, 30, $priority), $now));
    H012Check::that('AK7', $got === $expected, "$priority: $expected (fik $got)");
}

H012Check::done();
