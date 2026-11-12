<?php
declare(strict_types=1);

// H005-AK4: transcribe.py afviser ugyldige --threads, --beam og --vad med exit ≠ 0 og sender gyldige videre som
// faster-whisper 1.2.1's cpu_threads, beam_size og vad_filter. Python køres kun, hvis /usr/bin/python3 findes; ellers
// læses scriptet. Argumenterne valideres før faster_whisper importeres, så testen kræver ikke pakken.

require_once __DIR__ . '/h005_lib.php';

$script = dirname(__DIR__) . '/cron/transcribe.py';
$src = (string) file_get_contents($script);

H005Check::that('AK4', str_contains($src, 'p.add_argument("--threads", type=int_range(1, 32)'), '--threads valideres 1–32');
H005Check::that('AK4', str_contains($src, 'p.add_argument("--beam", type=int_range(1, 10)'), '--beam valideres 1–10');
H005Check::that('AK4', str_contains($src, 'p.add_argument("--vad", choices=["0", "1"]'), '--vad kun 0 eller 1');
H005Check::that('AK4', str_contains($src, 'raise argparse.ArgumentTypeError') && str_contains($src, 'v.isascii() and v.isdigit()'),
    'int_range afviser alt andet end ASCII-heltal i intervallet');
H005Check::that('AK4', str_contains($src, 'cpu_threads=a.threads'), 'WhisperModel(cpu_threads=…)');
H005Check::that('AK4', str_contains($src, 'beam_size=a.beam') && str_contains($src, 'vad_filter=a.vad == "1"'),
    'transcribe(beam_size=…, vad_filter=…)');
H005Check::that('AK4', strpos($src, 'a = p.parse_args()') < strpos($src, 'from faster_whisper import'),
    'argumenterne valideres før faster_whisper importeres');

$python = '/usr/bin/python3';
if (DIRECTORY_SEPARATOR === '/' && is_executable($python)) {
    $run = static function (array $args) use ($python, $script): array {
        $p = proc_open(array_merge([$python, '-I', $script, '--model', 'turbo', '--models', sys_get_temp_dir()], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [-1, ''];
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    foreach ([['--threads', '0'], ['--threads', '33'], ['--threads', 'x'], ['--beam', '0'], ['--beam', '11'],
        ['--beam', '1.5'], ['--vad', '2'], ['--vad', 'on']] as $bad) {
        [$code, $out] = $run(array_merge($bad, ['lyd.mp3']));
        H005Check::that('AK4', $code === 2 && str_contains($out, $bad[0]), 'python afviser ' . implode(' ', $bad) . ' (exit ' . $code . ')');
    }
    [$code, $out] = $run(['--help']);
    H005Check::that('AK4', $code === 0 && str_contains($out, '--threads') && str_contains($out, '--beam') && str_contains($out, '--vad'),
        '--help viser de nye argumenter');
} else {
    echo "info: /usr/bin/python3 findes ikke her — kun scriptets kilde er tjekket\n";
}

H005Check::done();
