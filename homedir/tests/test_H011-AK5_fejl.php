<?php
declare(strict_types=1);

// H011-AK5: fejler diarization, bliver transcriptet stående færdigt og uændret (vises uden taler). Et opkalds egen fejl
// bruger ét forsøg, tidligst igen efter 30 min, og efter 3 opgives det. En motorfejl bruger intet forsøg, stopper
// kørslen og giver 30 min pause, så en ødelagt motor ikke brænder hele historikken (ingen hot-loop).

require_once __DIR__ . '/h011_lib.php';

use Whisper\DB;
use Whisper\Transcription\Diarizer;
use Whisper\Transcription\EngineError;

$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 9.2, 'speaker' => 1], ['start' => 9.4, 'end' => 12.0, 'speaker' => 0]];
$status = static fn(int $id): string => (string) DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$id]);

// Én fil fejler (motorens "error"-linje for netop den fil): kun den rammes, de andre får talere
f002_setup();
$clock = h011_clock();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$before = h011_row($b['id']);
$engine = new FakeDiarizer();
$engine->answers = [$a['file'] => $iv, $b['file'] => new EngineError('diarization fejlede for lydfilen'), $c['file'] => $iv];
$log = [];
$n = h011_diarizer($engine, $log, $clock)->runOnce(3000);
$rb = h011_row($b['id']);
H011Check::that('AK5', $n === 3 && $engine->calls === [[$a['file'], $b['file'], $c['file']]], 'nyeste først, én batch med tre opkald');
H011Check::that('AK5', $rb['json'] === $before['json'] && $rb['status'] === null && $rb['attempts'] === 1,
    'fejlet opkald: transcriptet er uændret, ét forsøg brugt');
H011Check::that('AK5', $status($b['id']) === 'faerdig', 'fejlet opkald: stadig færdigt');
H011Check::that('AK5', h011_row($a['id'])['status'] === 'faerdig' && h011_row($c['id'])['status'] === 'faerdig', 'de andre fik talere');
H011Check::that('AK5', in_array('diarize: opkald ' . $b['id'] . ': fejlet: diarization fejlede for lydfilen (forsøg 1 af 3)', $log, true)
    && count(array_filter($log, static fn(string $l): bool => str_contains($l, 'opkald ' . $b['id'] . ':'))) === 1, 'én loglinje for fejlen');

// Mindst 30 min mellem forsøg; efter 3 forsøg opgivet, og det tages ikke igen
$calls = count($engine->calls);
H011Check::that('AK5', h011_diarizer($engine, $log, $clock)->runOnce(3000) === 0 && count($engine->calls) === $calls,
    'samme opkald prøves ikke igen inden for 30 min');
for ($i = 0; $i < 2; $i++) {
    $clock->now += Diarizer::RETRY_GAP + 1;
    h011_diarizer($engine, $log, $clock)->runOnce(3000);
}
$rb = h011_row($b['id']);
H011Check::that('AK5', $rb['status'] === 'fejlet' && $rb['attempts'] === 3 && $rb['json'] === $before['json'], 'efter 3 forsøg: fejlet, uændret');
$clock->now += Diarizer::RETRY_GAP + 1;
$calls = count($engine->calls);
H011Check::that('AK5', h011_diarizer($engine, $log, $clock)->runOnce(3000) === 0 && count($engine->calls) === $calls, 'et opgivet opkald tages ikke igen');

// Motoren dør ved hver start, og kørslens løkke (Diarizer::run, som cron/diarize.php bruger) kører: intet forsøg,
// ét motorkald, pause; efter pausen får det samme første opkald ét forsøg, og intet bliver fejlet
f002_setup();
$clock = h011_clock();
$ids = [];
for ($i = 1; $i <= 5; $i++) {
    $ids[] = h011_done_call(h011_segments(), '2026-10-0' . $i . ' 10:00:00')['id'];
}
$engine = new FakeDiarizer();
$engine->throwAlways = true;
$log = [];
$attempts = static fn(): int => (int) DB::scalar('SELECT COUNT(*) FROM transcripts WHERE diar_attempts > 0');
$failed = static fn(): int => (int) DB::scalar("SELECT COUNT(*) FROM transcripts WHERE diar_status = 'fejlet'");
h011_diarizer($engine, $log, $clock)->run(3000);
H011Check::that('AK5', count($engine->calls) === 1 && $attempts() === 0 && $failed() === 0, 'motorfejl: ét motorkald, intet forsøg, intet fejlet');
H011Check::that('AK5', is_file(Diarizer::pauseFile()) && count(array_filter($log, static fn(string $l): bool => str_contains($l, 'motoren fejlede'))) === 1,
    'én loglinje og en pausemarkør: ' . implode(' | ', $log));
$clock->now += 60;
h011_diarizer($engine, $log, $clock)->run(3000);
H011Check::that('AK5', count($engine->calls) === 1, 'under pausen startes motoren ikke');
$clock->now += Diarizer::PAUSE;
h011_diarizer($engine, $log, $clock)->run(3000);
H011Check::that('AK5', count($engine->calls) === 2 && $attempts() === 1 && $failed() === 0
    && h011_row($ids[4])['attempts'] === 1, 'efter pausen: samme første opkald dør igen og får ét forsøg, intet fejlet');

// Motoren dør midt i en batch (efter et svar): kun det igangværende opkald bruger et forsøg, de næste er urørte
f002_setup();
$clock = h011_clock();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$engine = new FakeDiarizer();
$engine->answers = [$a['file'] => $iv];
$engine->throwAt = $b['file'];
$log = [];
$d = h011_diarizer($engine, $log, $clock);
$d->run(3000);
H011Check::that('AK5', h011_row($a['id'])['status'] === 'faerdig' && h011_row($b['id'])['attempts'] === 1 && h011_row($c['id'])['attempts'] === 0
    && h011_row($c['id'])['status'] === null && count($engine->calls) === 1, 'motoren døde ved B: A færdig, B ét forsøg, C urørt, kørslen stoppet');

// Exit 0 uden svar for en fil: ét forsøg; ugyldigt svar: ét forsøg
$engine = new FakeDiarizer();
$engine->silent = [$c['file']];
h011_diarizer($engine, $log, $clock)->runOnce(3000);
H011Check::that('AK5', h011_row($c['id'])['attempts'] === 1 && h011_row($c['id'])['status'] === null,
    'intet svar for filen (exit 0): ét forsøg');
$clock->now += Diarizer::RETRY_GAP + 1;
$engine = new FakeDiarizer();
$engine->answers = [$c['file'] => [['start' => 0, 'end' => 1, 'speaker' => 'x']], $b['file'] => $iv];
$before = h011_row($c['id']);
h011_diarizer($engine, $log, $clock)->runOnce(3000);
H011Check::that('AK5', h011_row($c['id'])['json'] === $before['json'] && h011_row($c['id'])['attempts'] === 2, 'ugyldige intervaller: uændret, ét forsøg');

// Flere opkald, og intet lykkes: pause, så ikke hele historikken bruger forsøg
f002_setup();
$clock = h011_clock();
$x = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$y = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$engine = new FakeDiarizer();
$engine->answers = [$x['file'] => new EngineError('diarization fejlede for lydfilen'), $y['file'] => new EngineError('diarization fejlede for lydfilen')];
$log = [];
$d = h011_diarizer($engine, $log, $clock);
$d->run(3000);
H011Check::that('AK5', $d->paused && is_file(Diarizer::pauseFile()) && in_array('diarize: intet af 2 opkald lykkedes — pause i 30 min', $log, true),
    'intet af flere opkald lykkedes: pause');

// Før motoren: ugyldige segmenter opgives, ingen tale er færdig, over 60 min diarizeres ikke, manglende lyd opgives,
// og en dublet-lydfil venter til næste batch
f002_setup();
$clock = h011_clock();
$bad = h011_done_call(h011_segments(), '2026-10-09 10:00:00');
DB::exec('UPDATE transcripts SET segments_json = ? WHERE call_id = ?', ['"ikke en liste"', $bad['id']]);
$empty = h011_done_call(h011_segments(), '2026-10-08 10:00:00');
DB::exec('UPDATE transcripts SET segments_json = ? WHERE call_id = ?', ['[]', $empty['id']]);
$long = h011_done_call(h011_segments(), '2026-10-07 10:00:00', Diarizer::MAX_AUDIO + 1);
$gone = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
unlink($gone['file']);
$first = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$dup = h011_done_call(h011_segments(), '2026-10-04 10:00:00');
DB::exec('UPDATE calls SET audio_path = ? WHERE id = ?', [(string) DB::scalar('SELECT audio_path FROM calls WHERE id = ?', [$first['id']]), $dup['id']]);
$engine = new FakeDiarizer();
$engine->answers = [$first['file'] => $iv];
$log = [];
h011_diarizer($engine, $log, $clock)->runOnce(3000);
H011Check::that('AK5', h011_row($bad['id'])['status'] === 'fejlet' && h011_row($empty['id'])['status'] === 'faerdig'
    && h011_row($long['id'])['status'] === 'fejlet' && h011_row($gone['id'])['status'] === 'fejlet',
    'ugyldige segmenter, over 60 min og manglende lyd: opgivet; ingen tale: færdig');
H011Check::that('AK5', $engine->calls === [[$first['file']]], 'kun det gyldige opkald går til motoren');
H011Check::that('AK5', h011_row($dup['id'])['status'] === null && h011_row($dup['id'])['attempts'] === 0, 'dublet-lydfil: urørt, tages i næste batch');
$engine->answers = [$first['file'] => $iv];
h011_diarizer($engine, $log, $clock)->runOnce(3000);
H011Check::that('AK5', h011_row($dup['id'])['status'] === 'faerdig', 'dubletten får talere i næste batch');

// diarize.py: argumenterne valideres, før noget importeres (kun hvor python3 findes)
$script = dirname(__DIR__) . '/cron/diarize.py';
$src = (string) file_get_contents($script);
H011Check::that('AK5', strpos($src, 'a = p.parse_args()') < strpos($src, 'from faster_whisper.audio import decode_audio')
    && str_contains($src, 'except Exception as e:  # noqa: BLE001'), 'diarize.py: validering før import, én fil ad gangen fanges');
$python = '/usr/bin/python3';
if (DIRECTORY_SEPARATOR === '/' && is_executable($python)) {
    foreach ([['--models', 'rel', '--', '/a.mp3'], ['--models', '/m', '--threads', '0', '--', '/a.mp3'], ['--models', '/m', '--', 'a.mp3'],
        ['--models', '/m', '--', '/x/../a.mp3'], ['--models', '/m', '--', '/a.mp3', '/a.mp3']] as $badArgs) {
        $p = proc_open(array_merge([$python, '-I', $script], $badArgs), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
        $code = is_resource($p) ? proc_close($p) : -1;
        H011Check::that('AK5', $code === 2, 'diarize.py afviser ' . implode(' ', $badArgs) . ' (exit ' . $code . ')');
    }
}

H011Check::done();
