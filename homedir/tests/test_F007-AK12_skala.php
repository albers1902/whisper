<?php
declare(strict_types=1);

// F007-AK12 (spec v3; ejerens krav 2026-11-12: "der er flere 100.000 opkald"): liste, antal pr. fane, statusfiltre,
// agentliste, kø-kort og uddrag er hurtige ved 256.000 rækker i calls — både med mange træf (A) og med få (B: "I kø"
// med 5, "Færdige" med 5, en agent med 3, Adversus med næsten kun "ikke valgt" som efter H013). EXPLAIN-planerne skal
// bruge et indeks og læse få rækker ved få træf. Planer og tider skrives ud (én linje pr. tilfælde).

require_once __DIR__ . '/f007_lib.php';

use Whisper\Calls;
use Whisper\DB;
use Whisper\UiV2;

f007_setup();
$t0 = microtime(true);
// 1.000 rækker, fordoblet 8 gange = 256.000. Fordelingen ligner DEV: flest Adversus uden succes ("ikke valgt").
$rows = [];
$params = [];
for ($i = 0; $i < 1000; $i++) {
    $adv = $i % 5 !== 0;
    $success = $adv && $i % 20 === 1;
    $status = !$adv ? ($i % 3 === 0 ? 'ventende' : 'faerdig') : ($success ? 'faerdig' : ($i % 50 === 2 ? 'ventende' : 'ikke_valgt'));
    $audio = $status === 'ikke_valgt' ? 'venter' : 'hentet';
    $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
    array_push($params, $adv ? 'adversus' : 'zendesk', 's' . $i, 'inbound', 'Agent ' . ($i % 40), gmdate('Y-m-d H:i:s', 1790000000 + $i * 31536),
        30 + $i % 600, '', $audio, $status, $success ? 1 : 0, $adv ? 7000 + $i : null);
}
$cols = '`source`, external_id, direction, agent_name, started_at, duration_sec, recording_url, audio_status, transcript_status, is_success, lead_id, '
    . 'created_at, updated_at';
DB::exec('INSERT INTO calls (' . $cols . ') VALUES ' . implode(',', $rows), $params);
for ($k = 1; $k <= 8; $k++) {
    DB::exec('INSERT INTO calls (' . $cols . ') SELECT `source`, CONCAT(external_id, ?), direction, agent_name, DATE_SUB(started_at, INTERVAL ? MINUTE), '
        . 'duration_sec, recording_url, audio_status, transcript_status, is_success, lead_id, created_at, updated_at FROM calls', ['-' . $k, $k * 7]);
}
DB::exec('ANALYZE TABLE calls');
$total = (int) DB::scalar('SELECT COUNT(*) FROM calls');
printf("seed: %d rækker på %.1f s\n", $total, microtime(true) - $t0);
F007Check::that('AK12', $total === 256000, "seed: $total rækker");

/** @return array{0: float, 1: mixed} */
$time = static function (callable $fn): array {
    $t = microtime(true);
    $r = $fn();
    return [microtime(true) - $t, $r];
};
/** @return array{0: string, 1: int} planen som tekst og det største rows-estimat */
$explain = static function (string $sql, array $p): array {
    $plan = DB::all('EXPLAIN ' . $sql, $p);
    $max = 0;
    $txt = [];
    foreach ($plan as $r) {
        if (!str_starts_with((string) ($r['table'] ?? ''), '<')) {   // <derivedN> er LIMIT-loftet, ikke læste rækker
            $max = max($max, (int) ($r['rows'] ?? 0));
        }
        $txt[] = ($r['table'] ?? '') . ':' . ($r['type'] ?? '') . ' key=' . ($r['key'] ?? 'NULL') . ' rows=' . ($r['rows'] ?? '?')
            . (($r['Extra'] ?? '') !== '' ? ' (' . $r['Extra'] . ')' : '');
    }
    return [implode(' | ', $txt), $max];
};
// QA R-6: præcis den SQL, siden kører (Calls::listSql/countSql), ikke en kopi
$run = static function (string $name, array $f, ?int $maxRows, float $maxSec) use ($time, $explain): void {
    [$sec, $res] = $time(static fn(): array => Calls::searchCapped($f));
    [$sql, $p] = Calls::listSql($f, $res['total'], $res['side']);
    [$plan, $rows] = $explain($sql, $p);
    [$csql, $pc] = Calls::countSql($f);
    [$cplan, $crows] = $explain($csql, $pc);
    printf("%-34s %6.1f ms antal=%-7s liste: %s || antal: %s\n", $name, $sec * 1000, $res['total'] > Calls::COUNT_CAP ? '10.000+' : $res['total'], $plan, $cplan);
    $short = static fn(string $pl): string => trim((string) preg_replace(['/<derived2>:[^|]*\| ?/', '/ \(Using [^)]*\)/', '/c:eq_ref key=PRIMARY rows=1 \| ?/'],
        ['', '', ''], $pl));
    $GLOBALS['f007_sum'][] = sprintf('%s %.0fms n=%s L[%s] C[%s]', $name, $sec * 1000, $res['total'] > Calls::COUNT_CAP ? '10k+' : $res['total'],
        $short($plan), $short($cplan));
    F007Check::that('AK12', !str_contains($plan, 'c:ALL') && !str_contains($cplan, 'c:ALL'), "$name: liste og antal bruger indeks");
    if ($maxRows !== null) {
        F007Check::that('AK12', $rows <= $maxRows && $crows <= $maxRows, "$name: få rækker i planen (liste $rows, antal $crows ≤ $maxRows)");
    }
    F007Check::that('AK12', $sec < $maxSec, sprintf('%s: %.0f ms (< %d)', $name, $sec * 1000, $maxSec * 1000));
};

// A. Mange træf
$run('A Zendesk, alle', Calls::uiFilter([]), null, 0.3);
$run('A Adversus, succes og valgte', Calls::uiFilter(['kilde' => 'adversus']), null, 0.3);
$run('A Adversus, alle opkald', Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle']), null, 0.3);
$run('A Adversus, alle, Færdige', Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'faerdig']), null, 0.3);
$run('A Zendesk, I kø', Calls::uiFilter(['status' => 'koe']), null, 0.3);
$run('A Zendesk, periode + agent', Calls::uiFilter(['fra' => '2026-11-01', 'til' => '2026-12-31', 'agent' => 'Agent 5']), null, 0.3);
$run('A Adversus, alle, side 200', Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'side' => '200']), null, 0.3);
[$sec, $n] = $time(static fn(): int => Calls::count(Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle'])));
printf("A anden fanes antal %.1f ms (%d)\n", $sec * 1000, $n);
F007Check::that('AK12', $n === Calls::COUNT_CAP + 1 && $sec < 0.5, sprintf('den anden fanes antal har loft (%d) på %.0f ms', $n, $sec * 1000));
[$sec, $agents] = $time(static fn(): array => Calls::agents('adversus'));
[$planAgents] = $explain("SELECT c.agent_name FROM calls c WHERE c.`source` = 'adversus' GROUP BY c.agent_name ORDER BY c.agent_name", []);
printf("A agenter (Adversus) %.1f ms: %s\n", $sec * 1000, $planAgents);
F007Check::that('AK12', count($agents) === 32 && $sec < 0.3 && str_contains($planAgents, 'calls_source_agent'),
    sprintf('agentlisten pr. fane: %d agenter på %.0f ms, indeks', count($agents), $sec * 1000));
UiV2::reset();
UiV2::agents('adversus');
[$secA] = $time(static fn(): array => UiV2::agents('adversus'));
F007Check::that('AK12', $secA < 0.01 && UiV2::agents('adversus') === $agents, sprintf('agentlisten fra cache: %.1f ms', $secA * 1000));
UiV2::reset();
[$secQ, $q] = $time(static fn(): array => UiV2::queue());
UiV2::reset(false);
[$secQ2] = $time(static fn(): array => UiV2::queue());
printf("A kø-kort: tælling %.1f ms (i kø=%d), fra cache %.1f ms\n", $secQ * 1000, $q['status']['waiting'], $secQ2 * 1000);
F007Check::that('AK12', $secQ < 1.0 && $secQ2 < 0.05, sprintf('kø-kort: tælling %.0f ms, fra cache %.1f ms', $secQ * 1000, $secQ2 * 1000));
$cap = f007_get(f005_admin(), '/', ['kilde' => 'adversus', 'vis' => 'alle', 'side' => '200']);
F007Check::that('AK12', str_contains($cap->body, 'Side 200 af 200+') && str_contains($cap->body, 'Indsnævr med periode, agent eller søgning'),
    'loftet: sidste side siger, hvordan ældre opkald findes (QA F-6)');

// B. Få træf (normaltilstanden: køen er næsten tom; Adversus næsten kun "ikke valgt" som efter H013)
$t1 = microtime(true);
DB::exec("UPDATE calls SET transcript_status = 'ikke_valgt', is_success = 0, audio_status = 'venter' WHERE `source` = 'adversus'");
DB::exec("UPDATE calls SET transcript_status = 'faerdig' WHERE `source` = 'zendesk'");
DB::exec("UPDATE calls SET transcript_status = 'ventende', audio_status = 'hentet' WHERE `source` = 'zendesk' ORDER BY id LIMIT 5");
DB::exec("UPDATE calls SET transcript_status = 'ventende', audio_status = 'hentet', is_success = 1 WHERE `source` = 'adversus' ORDER BY id LIMIT 5");
DB::exec("UPDATE calls SET transcript_status = 'faerdig', audio_status = 'hentet', is_success = 1 WHERE `source` = 'adversus' "
    . "AND transcript_status = 'ikke_valgt' ORDER BY id DESC LIMIT 5");
DB::exec("UPDATE calls SET agent_name = 'Sjælden Agent' WHERE `source` = 'zendesk' ORDER BY id LIMIT 3");
DB::exec("UPDATE calls SET agent_name = 'Travl Agent' WHERE `source` = 'zendesk' AND agent_name <> 'Sjælden Agent' ORDER BY id DESC LIMIT 5000");
DB::exec('ANALYZE TABLE calls');
printf("B: omfordelt på %.1f s\n", microtime(true) - $t1);
$run('B Zendesk, I kø (5)', Calls::uiFilter(['status' => 'koe']), 1000, 0.2);
$run('B Adversus, I kø (5)', Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'koe']), 1000, 0.2);
$run('B Adversus, alle, Færdige (5)', Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle', 'status' => 'faerdig']), 1000, 0.2);
$run('B Adversus, succes og valgte (10)', Calls::uiFilter(['kilde' => 'adversus']), null, 0.2);
$run('B Zendesk, agent med 3', Calls::uiFilter(['agent' => 'Sjælden Agent']), 1000, 0.2);
$run('B Zendesk, agent med 5.000 (QA R-3)', Calls::uiFilter(['agent' => 'Travl Agent']), 20000, 0.2);
$run('B Zendesk, agent med 5.000, side 100', Calls::uiFilter(['agent' => 'Travl Agent', 'side' => '100']), 20000, 0.2);
$run('B Zendesk, I kø + agent (0)', Calls::uiFilter(['status' => 'koe', 'agent' => 'Agent 7']), null, 0.2);
F007Check::that('AK12', count(Calls::searchCapped(Calls::uiFilter(['status' => 'koe']))['rows']) === 5
    && count(Calls::searchCapped(Calls::uiFilter(['kilde' => 'adversus']))['rows']) === 10, 'B: de rigtige rækker kommer frem');
UiV2::reset();
[$secQ] = $time(static fn(): array => UiV2::queue());
printf("B kø-kort: tælling %.1f ms\n", $secQ * 1000);
F007Check::that('AK12', $secQ < 0.5, sprintf('B kø-kort: %.0f ms', $secQ * 1000));

// Uddrag: én forespørgsel for en side, skåret i SQL
$ids = array_map(static fn(array $r): int => (int) $r['id'], Calls::searchCapped(Calls::uiFilter([]))['rows']);
foreach ($ids as $id) {
    DB::exec('INSERT IGNORE INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [$id, str_repeat('Lang tekst. ', 5000), (string) json_encode(array_fill(0, 2000, ['start' => 1.0, 'end' => 2.0, 'text' => 'Hej', 'speaker' => 0,
            'role' => 'agent'])), 'turbo', 'da']);
}
[$sec, $x] = $time(static fn(): array => Calls::excerpts($ids));
printf("uddrag for %d opkald: %.1f ms\n", count($x), $sec * 1000);
F007Check::that('AK12', count($x) === 50 && $sec < 0.5, sprintf('uddrag for en side: %.0f ms', $sec * 1000));

// QA R-2: mangler indekset (down/0010 med flaget tændt), falder listen tilbage uden FORCE (fejl 1176) i stedet for en fejlside
DB::exec('ALTER TABLE calls DROP INDEX calls_source_started');
$fb = Calls::uiFilter(['kilde' => 'adversus', 'vis' => 'alle']);
try {
    $res = Calls::searchCapped($fb);
    $okFb = $res['total'] > Calls::COUNT_CAP && count($res['rows']) === 50;
} catch (Throwable $e) {
    $okFb = false;
}
F007Check::that('AK12', $okFb, 'uden calls_source_started: listen virker stadig (fallback uden FORCE)');
DB::exec('DELETE FROM transcripts');
DB::exec('DELETE FROM calls');
DB::exec('ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_started (`source`, started_at, id, is_success, transcript_status)');
$GLOBALS['f007_sum'][] = sprintf('agenter (Adversus): %s', $planAgents);
echo "== RESUME\n" . implode("\n", $GLOBALS['f007_sum']) . "\n";
F007Check::done();
