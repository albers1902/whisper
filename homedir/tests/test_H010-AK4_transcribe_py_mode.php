<?php
declare(strict_types=1);

// H010-AK4: transcribe.py afviser ugyldige --mode og --batched-size (og batched uden VAD) med exit 2, før
// faster_whisper importeres. Med en falsk faster_whisper-pakke kontrolleres, at batched bruger
// BatchedInferencePipeline(model).transcribe(batch_size=N, vad_filter=True, without_timestamps=False), at pipelinen
// oprettes én gang pr. proces, og at uddata er det samme JSON som i sequential. Python køres kun, hvis den findes
// (/usr/bin/python3, eller H010_PYTHON lokalt); ellers læses kun kilden.

require_once __DIR__ . '/h010_lib.php';

$script = dirname(__DIR__) . '/cron/transcribe.py';
$src = (string) file_get_contents($script);

H010Check::that('AK4', str_contains($src, 'p.add_argument("--mode", choices=["sequential", "batched"]'), '--mode kun sequential|batched');
H010Check::that('AK4', str_contains($src, 'p.add_argument("--batched-size", type=int_range(1, 32)'), '--batched-size valideres 1–32');
H010Check::that('AK4', str_contains($src, 'p.error("--mode batched kræver --vad 1")'), 'batched uden VAD afvises');
H010Check::that('AK4', strpos($src, 'p.error("--mode batched kræver --vad 1")') < strpos($src, 'from faster_whisper import'),
    'valideringen sker før faster_whisper importeres');
H010Check::that('AK4', str_contains($src, 'batch_size=a.batched_size, without_timestamps=False')
    && substr_count($src, 'model = BatchedInferencePipeline(model)') === 1, 'pipelinen kaldes med batch_size og without_timestamps=False');

$python = (string) (getenv('H010_PYTHON') ?: '/usr/bin/python3');
if (!is_file($python) || !is_executable($python)) {
    echo "info: $python findes ikke her — kun scriptets kilde er tjekket\n";
    H010Check::done();
}

$tmp = sys_get_temp_dir() . '/whisper-h010-' . bin2hex(random_bytes(6));
mkdir($tmp . '/faster_whisper', 0700, true);
$log = $tmp . '/kald.jsonl';
file_put_contents($tmp . '/faster_whisper/__init__.py', <<<'PY'
import json, os
LOG = os.environ["H010_LOG"]

def _log(kind, kw):
    with open(LOG, "a", encoding="utf-8") as f:
        f.write(json.dumps({"kind": kind, **kw}) + "\n")

class _Seg:
    def __init__(self, a, b, t):
        self.start, self.end, self.text = a, b, t

class _Info:
    duration = 12.345

def _result():
    return iter([_Seg(0.0, 1.234, " hej "), _Seg(1.5, 3.0, " med dig ")]), _Info()

class WhisperModel:
    def __init__(self, *a, **kw):
        _log("model", {"cpu_threads": kw.get("cpu_threads")})
    def transcribe(self, audio, **kw):
        _log("sequential", kw)
        return _result()

class BatchedInferencePipeline:
    def __init__(self, model):
        assert isinstance(model, WhisperModel)
        _log("pipeline", {})
    def transcribe(self, audio, **kw):
        _log("batched", kw)
        return _result()
PY);

$run = static function (array $args) use ($python, $script, $tmp, $log): array {
    $env = ['PATH' => (string) getenv('PATH'), 'PYTHONPATH' => $tmp, 'H010_LOG' => $log, 'PYTHONIOENCODING' => 'utf-8'];
    if (getenv('SYSTEMROOT') !== false) {
        $env['SYSTEMROOT'] = (string) getenv('SYSTEMROOT');
    }
    $p = proc_open(array_merge([$python, $script, '--model', 'turbo', '--models', $tmp, '--threads', '3', '--beam', '1'], $args),
        [0 => ['file', DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        return [-1, '', ''];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};
$calls = static function () use ($log): array {
    $rows = is_file($log) ? array_map(static fn(string $l): mixed => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
    @unlink($log);
    return $rows;
};

foreach ([['--mode', 'parallel'], ['--mode', 'batched', '--batched-size', '0'], ['--mode', 'batched', '--batched-size', '33'],
    ['--mode', 'batched', '--batched-size', 'x'], ['--mode', 'batched', '--vad', '0'], ['--batched-size', '4'],
    ['--mode', 'sequential', '--batched-size', '4']] as $bad) {
    [$code, , $err] = $run(array_merge($bad, ['/lyd.mp3']));
    H010Check::that('AK4', $code === 2 && $calls() === [], 'python afviser ' . implode(' ', $bad) . ' (exit ' . $code . ') uden at importere faster_whisper'
        . ($code === 2 ? '' : ': ' . trim($err)));
}

[$code, $seqOut] = $run(['--vad', '1', '/lyd.mp3']);
$seq = $calls();
[$code2, $batOut] = $run(['--vad', '1', '--mode', 'batched', '--batched-size', '8', '/lyd.mp3']);
$bat = $calls();
H010Check::that('AK4', $code === 0 && $code2 === 0 && $seqOut !== '' && $seqOut === $batOut,
    'samme JSON i sequential og batched: ' . $batOut);
H010Check::that('AK4', ($seq[1]['kind'] ?? '') === 'sequential' && ($seq[1]['vad_filter'] ?? null) === true
    && !array_key_exists('batch_size', $seq[1] ?? []), 'sequential kalder WhisperModel.transcribe som før');
H010Check::that('AK4', ($bat[1]['kind'] ?? '') === 'pipeline' && ($bat[2]['kind'] ?? '') === 'batched'
    && ($bat[2]['batch_size'] ?? null) === 8 && ($bat[2]['vad_filter'] ?? null) === true
    && ($bat[2]['without_timestamps'] ?? null) === false && ($bat[2]['beam_size'] ?? null) === 1 && ($bat[2]['language'] ?? '') === 'da'
    && ($bat[0]['cpu_threads'] ?? null) === 3, 'batched: pipeline(model).transcribe(batch_size=8, vad, beam, sprog, tråde): ' . json_encode($bat));
[$code3] = $run(['--vad', '1', '--mode', 'batched', '/lyd.mp3']);
$def = $calls();
H010Check::that('AK4', $code3 === 0 && ($def[2]['batch_size'] ?? null) === 4, 'batched uden --batched-size: batch_size 4');

[$code4, $lines] = $run(['--vad', '1', '--mode', 'batched', '--batched-size', '2', '--batch-max', '3', '--', '/a.mp3', '/b.mp3']);
$b = $calls();
$rows = array_map(static fn(string $l): mixed => json_decode($l, true), explode("\n", trim($lines)));
H010Check::that('AK4', $code4 === 0 && count(array_filter($b, static fn($r): bool => ($r['kind'] ?? '') === 'pipeline')) === 1
    && count(array_filter($b, static fn($r): bool => ($r['kind'] ?? '') === 'batched')) === 2, 'fil-batch: én pipeline genbrugt til begge filer');
H010Check::that('AK4', count($rows) === 3 && ($rows[0]['file'] ?? '') === '/a.mp3' && ($rows[1]['file'] ?? '') === '/b.mp3'
    && isset($rows[0]['elapsed'], $rows[2]['load_seconds']) && ($rows[0]['segments'][0] ?? null) === ['start' => 0.0, 'end' => 1.23, 'text' => 'hej'],
    'fil-batch: JSON Lines som før (file, segmenter, elapsed, load_seconds)');

H010Check::done();
