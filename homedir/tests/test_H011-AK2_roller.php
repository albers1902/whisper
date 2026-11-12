<?php
declare(strict_types=1);

// H011-AK2: taleren med den tidligste agentmarkør bliver Agent og den anden Kunde; uden markør Taler 1 og 2 efter
// rækkefølge. Markørerne kommer fra [diarization] agent_phrases (standardlisten i koden).

require_once __DIR__ . '/h011_lib.php';

use Whisper\Transcription\Diarization;
use Whisper\Transcription\DiarizationSettings;

$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 9.2, 'speaker' => 1],
    ['start' => 9.4, 'end' => 12.0, 'speaker' => 0]];
$labels = static fn(array $r): array => array_map(static fn(array $s): ?string => Diarization::label($s), $r['segments']);
$def = DiarizationSettings::DEFAULT_PHRASES;

// Agenten præsenterer sig
$r = Diarization::apply(h011_segments(), $iv, $def);
H011Check::that('AK2', $labels($r) === ['Agent', 'Kunde', 'Agent'] && $r['agent'], 'markør "du taler med": Agent/Kunde/Agent');
H011Check::that('AK2', array_column($r['segments'], 'role') === ['agent', 'kunde', 'agent'], 'role gemmes pr. segment');

// Kunden taler først (udgående opkald), agenten præsenterer sig bagefter
$segs = [['start' => 0.0, 'end' => 4.0, 'text' => 'Hallo?'], ['start' => 4.5, 'end' => 9.0, 'text' => 'Hej, mit navn er Ole fra Tryg Energi.'],
    ['start' => 9.5, 'end' => 12.0, 'text' => 'Nå, hej.']];
$r = Diarization::apply($segs, $iv, $def);
H011Check::that('AK2', $labels($r) === ['Kunde', 'Agent', 'Kunde'], 'agenten er den anden taler: Kunde/Agent/Kunde');

// Den tidligste markør vinder: kunden siger senere "mit navn er"
$segs = [['start' => 0.0, 'end' => 4.0, 'text' => 'Velkommen til Tryg Energi.'], ['start' => 4.5, 'end' => 9.0, 'text' => 'Mit navn er Hans.'],
    ['start' => 9.5, 'end' => 12.0, 'text' => 'Tak.']];
$r = Diarization::apply($segs, $iv, $def);
H011Check::that('AK2', $labels($r) === ['Agent', 'Kunde', 'Agent'], 'kundens senere "mit navn er" gør ham ikke til agent');

// Ingen markør: Taler 1 og 2 efter rækkefølge
$segs = [['start' => 0.0, 'end' => 4.0, 'text' => 'Hej.'], ['start' => 4.5, 'end' => 9.0, 'text' => 'Goddag.'], ['start' => 9.5, 'end' => 12.0, 'text' => 'Ja.']];
$r = Diarization::apply($segs, [['start' => 0.0, 'end' => 4.2, 'speaker' => 1], ['start' => 4.3, 'end' => 9.2, 'speaker' => 0],
    ['start' => 9.4, 'end' => 12.0, 'speaker' => 1]], $def);
H011Check::that('AK2', $labels($r) === ['Taler 1', 'Taler 2', 'Taler 1'] && !$r['agent'], 'uden markør: Taler 1/Taler 2 efter første optræden');
H011Check::that('AK2', !isset($r['segments'][0]['role']), 'uden markør gemmes ingen role');

// Kun én taler med markør: Agent
$r = Diarization::apply([['start' => 0.0, 'end' => 4.0, 'text' => 'Du snakker med Mette.']], [['start' => 0.0, 'end' => 4.0, 'speaker' => 0]], $def);
H011Check::that('AK2', $labels($r) === ['Agent'], 'én taler med markør: Agent');

// Store/små bogstaver er ligegyldige, og markørerne kommer fra config
$s = DiarizationSettings::fromSection(['agent_phrases' => ' Kundeservice her | DU TALER MED |'], true);
H011Check::that('AK2', $s->phrases === ['kundeservice her', 'du taler med'], 'agent_phrases fra config, små bogstaver: ' . json_encode($s->phrases));
$r = Diarization::apply([['start' => 0.0, 'end' => 4.0, 'text' => 'Hej.'], ['start' => 4.5, 'end' => 9.0, 'text' => 'KUNDESERVICE HER, goddag.']],
    $iv, $s->phrases);
H011Check::that('AK2', $labels($r) === ['Kunde', 'Agent'], 'egen markør fra config virker');
$r = Diarization::apply(h011_segments(), $iv, ['noget helt andet']);
H011Check::that('AK2', $labels($r) === ['Taler 1', 'Taler 2', 'Taler 1'], 'config-listen erstatter standardlisten');

$warn = [];
$s = DiarizationSettings::fromSection(['agent_phrases' => ' | | '], true, 3, static function (string $w) use (&$warn): void {
    $warn[] = $w;
});
H011Check::that('AK2', $s->phrases === $def && count($warn) === 1, 'tom agent_phrases: standardlisten og én advarsel');
H011Check::that('AK2', DiarizationSettings::fromSection([], true)->phrases === $def, 'uden config: standardlisten');

H011Check::that('AK2', Diarization::label(['speaker' => 0]) === 'Taler 1' && Diarization::label(['speaker' => 1, 'role' => 'kunde']) === 'Kunde'
    && Diarization::label([]) === null && Diarization::label(['speaker' => '0']) === null, 'etiketter');

H011Check::done();
