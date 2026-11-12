<?php
declare(strict_types=1);

// H005-AK3: loglinjen for et færdigt opkald har lyd, tid, RTF og motorens indstillinger:
//   transcribe: opkald 120: færdig (lyd 4:12, tid 1:05, RTF 0,26, threads 5, beam 1, vad on)

require_once __DIR__ . '/h005_lib.php';

use Whisper\Transcription\Engine;
use Whisper\Transcription\Transcriber;

f002_setup();
$clock = new stdClass();
$clock->now = F002_NOW;

// En motor, der "bruger" 65 sekunder (flytter testens ur)
$engine = new class ($clock) implements Engine {
    public function __construct(private readonly stdClass $clock)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'threads 5, beam 1, vad on';
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        $this->clock->now += 65;
        return (new FakeEngine())->result + ['duration' => 999.0];
    }
};
$log = [];
$t = new Transcriber($engine, static function () use ($clock): int {
    return (int) $clock->now;
},static function (string $l) use (&$log): void {
    $log[] = $l;
});

$id = f002_insert(['started_at' => '2026-10-02 08:00:00', 'duration_sec' => 252]);
f002_audio($id);
H005Check::that('AK3', $t->runOnce() === $id, 'opkaldet transcriberes');
H005Check::that('AK3', ($log[0] ?? '') === 'transcribe: opkald ' . $id . ': færdig (lyd 4:12, tid 1:05, RTF 0,26, threads 5, beam 1, vad on)',
    'loglinjen: ' . ($log[0] ?? '(ingen)'));
H005Check::that('AK3', preg_match('/^transcribe: opkald [0-9]+: /', $log[0] ?? '') === 1, 'præfikset fra F002 er uændret');

// Uden varighed på opkaldet bruges motorens duration
$log = [];
$id2 = f002_insert(['started_at' => '2026-10-03 08:00:00', 'duration_sec' => 0]);
f002_audio($id2);
$t->runOnce();
H005Check::that('AK3', str_contains($log[0] ?? '', 'færdig (lyd 16:39, tid 1:05, RTF 0,07,'), 'varighed 0: motorens duration (999 s): ' . ($log[0] ?? ''));

// Formatet
H005Check::that('AK3', Transcriber::doneLine(3725, 1800, 'threads 4, beam 5, vad off')
    === 'færdig (lyd 1:02:05, tid 30:00, RTF 0,48, threads 4, beam 5, vad off)', 'over en time: t:mm:ss');
H005Check::that('AK3', Transcriber::doneLine(0, 5, '') === 'færdig (lyd 0:00, tid 0:05, RTF -)', 'ukendt lyd: RTF -, ingen indstillinger');
H005Check::that('AK3', Transcriber::doneLine(60, 90, 'x') === 'færdig (lyd 1:00, tid 1:30, RTF 1,50, x)', 'langsommere end realtid: RTF over 1');

// En fejl logges som før (ingen tal)
$log = [];
$bad = f002_insert(['started_at' => '2026-10-04 08:00:00']);
f002_audio($bad);
$fail = new FakeEngine();
$fail->result = new \Whisper\Transcription\EngineError('motoren fejlede (exit 1)');
(new Transcriber($fail, static fn(): int => F002_NOW, static function (string $l) use (&$log): void {
    $log[] = $l;
}))->runOnce();
H005Check::that('AK3', ($log[0] ?? '') === 'transcribe: opkald ' . $bad . ': fejlet: motoren fejlede (exit 1)', 'fejllinjen er uændret');

H005Check::done();
