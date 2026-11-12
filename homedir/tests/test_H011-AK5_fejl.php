<?php
declare(strict_types=1);

// H011-AK5: fejler diarization, bliver transcriptet stående færdigt og uændret (vises uden taler). Fejlen logges som én
// linje, et forsøg bruges, og efter 3 forsøg opgives opkaldet. diarize.py afviser ugyldige argumenter.

require_once __DIR__ . '/h011_lib.php';

use Whisper\DB;
use Whisper\Transcription\EngineError;

f002_setup();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$before = h011_row($b['id']);
$iv = [['start' => 0.0, 'end' => 4.2, 'speaker' => 0], ['start' => 4.3, 'end' => 9.2, 'speaker' => 1], ['start' => 9.4, 'end' => 12.0, 'speaker' => 0]];

// Én fil fejler i motoren: kun den rammes, de andre får talere
$engine = new FakeDiarizer();
$engine->answers = [$a['file'] => $iv, $b['file'] => new EngineError('diarization fejlede for lydfilen'), $c['file'] => $iv];
$log = [];
$n = h011_diarizer($engine, $log)->runOnce(3000);
$rb = h011_row($b['id']);
H011Check::that('AK5', $n === 3 && $engine->calls === [[$a['file'], $b['file'], $c['file']]], 'nyeste først, én batch med tre opkald');
H011Check::that('AK5', $rb['json'] === $before['json'] && $rb['status'] === null && $rb['attempts'] === 1,
    'fejlet opkald: transcriptet er uændret, ét forsøg brugt');
H011Check::that('AK5', (string) DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$b['id']]) === 'faerdig',
    'fejlet opkald: stadig færdigt');
H011Check::that('AK5', h011_row($a['id'])['status'] === 'faerdig' && h011_row($c['id'])['status'] === 'faerdig', 'de andre fik talere');
H011Check::that('AK5', count(array_filter($log, static fn(string $l): bool => str_contains($l, 'opkald ' . $b['id'] . ':'))) === 1
    && in_array('diarize: opkald ' . $b['id'] . ': fejlet: diarization fejlede for lydfilen', $log, true), 'én loglinje for fejlen');

// Efter 3 forsøg: opgivet (fejlet), og det tages ikke igen
for ($i = 0; $i < 2; $i++) {
    h011_diarizer($engine, $log)->runOnce(3000);
}
$rb = h011_row($b['id']);
H011Check::that('AK5', $rb['status'] === 'fejlet' && $rb['attempts'] === 3 && $rb['json'] === $before['json'], 'efter 3 forsøg: fejlet, uændret');
$calls = count($engine->calls);
H011Check::that('AK5', h011_diarizer($engine, $log)->runOnce(3000) === 0 && count($engine->calls) === $calls, 'et opgivet opkald tages ikke igen');

// Motoren dør midt i en batch: kun det igangværende opkald bruger et forsøg, de næste er urørte
f002_setup();
$a = h011_done_call(h011_segments(), '2026-10-07 10:00:00');
$b = h011_done_call(h011_segments(), '2026-10-06 10:00:00');
$c = h011_done_call(h011_segments(), '2026-10-05 10:00:00');
$engine = new FakeDiarizer();
$engine->answers = [$a['file'] => $iv];
$engine->throwAt = $b['file'];
$log = [];
h011_diarizer($engine, $log)->runOnce(3000);
H011Check::that('AK5', h011_row($a['id'])['status'] === 'faerdig' && h011_row($b['id'])['attempts'] === 1 && h011_row($c['id'])['attempts'] === 0
    && h011_row($c['id'])['status'] === null, 'motoren døde ved B: A færdig, B ét forsøg, C urørt');

// Ugyldigt svar fra motoren: som en fejl
$engine = new FakeDiarizer();
$engine->answers = [$c['file'] => [['start' => 0, 'end' => 1, 'speaker' => 'x']]];
$engine->answers[$b['file']] = $iv;
$before = h011_row($c['id']);
h011_diarizer($engine, $log)->runOnce(3000);
H011Check::that('AK5', h011_row($c['id'])['json'] === $before['json'] && h011_row($c['id'])['attempts'] === 1, 'ugyldige intervaller: uændret, ét forsøg');

// Lydfilen mangler: opgivet med det samme
f002_setup();
$d = h011_done_call(h011_segments());
unlink($d['file']);
$log = [];
$n = h011_diarizer(new FakeDiarizer(), $log)->runOnce(3000);
H011Check::that('AK5', $n === 1 && h011_row($d['id'])['status'] === 'fejlet' && h011_row($d['id'])['attempts'] === 3,
    'lydfilen mangler: opgivet uden flere forsøg');

// diarize.py: argumenterne valideres, før noget importeres (kun hvor python3 findes)
$script = dirname(__DIR__) . '/cron/diarize.py';
$src = (string) file_get_contents($script);
H011Check::that('AK5', strpos($src, 'a = p.parse_args()') < strpos($src, 'from faster_whisper.audio import decode_audio')
    && str_contains($src, 'except Exception as e:  # noqa: BLE001'), 'diarize.py: validering før import, én fil ad gangen fanges');
$python = '/usr/bin/python3';
if (DIRECTORY_SEPARATOR === '/' && is_executable($python)) {
    foreach ([['--models', 'rel', '--', '/a.mp3'], ['--models', '/m', '--threads', '0', '--', '/a.mp3'], ['--models', '/m', '--', 'a.mp3'],
        ['--models', '/m', '--', '/x/../a.mp3'], ['--models', '/m', '--', '/a.mp3', '/a.mp3']] as $bad) {
        $p = proc_open(array_merge([$python, '-I', $script], $bad), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
        $code = is_resource($p) ? proc_close($p) : -1;
        H011Check::that('AK5', $code === 2, 'diarize.py afviser ' . implode(' ', $bad) . ' (exit ' . $code . ')');
    }
}

H011Check::done();
