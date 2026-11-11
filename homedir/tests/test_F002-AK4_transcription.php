<?php
declare(strict_types=1);

// F002-AK4: transcribe tager ét ventende opkald, kører motoren (mocket) og gemmer tekst og segmenter. Status går til
// faerdig, ved fejl til fejlet med fejltekst; et hængende job sættes tilbage efter 2 timer. To samtidige kørsler
// behandler aldrig samme opkald.

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Lock;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

$var = f002_setup();
$engine = new FakeEngine();
$log = [];
$t = new Transcriber($engine, static fn(): int => F002_NOW, static function (string $l) use (&$log): void {
    $log[] = $l;
});

F002Check::that('AK4', $t->runOnce() === null && $engine->calls === 0, 'tom kø: intet sker');
$notYet = f002_insert(['started_at' => '2026-10-01 08:00:00']);   // lyden er ikke hentet: tages ikke
$old = f002_insert(['started_at' => '2026-10-02 08:00:00']);
$new = f002_insert(['started_at' => '2026-10-03 08:00:00']);
f002_audio($old);
f002_audio($new);

$id = $t->runOnce();
F002Check::that('AK4', $id === $old, 'ældste ventende opkald med lyd tages først');
F002Check::that('AK4', $engine->calls === 1, 'motoren kørt én gang');
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$old])[0];
F002Check::that('AK4', $row['transcript_status'] === 'faerdig' && $row['transcript_error'] === null, 'status faerdig');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$new]) === 'ventende', 'det andet opkald venter stadig (ét ad gangen)');
$tr = DB::all('SELECT * FROM transcripts WHERE call_id = ?', [$old])[0] ?? [];
F002Check::that('AK4', ($tr['text'] ?? '') === 'Hej og velkommen. Det handler om strøm og måler.', 'tekst gemt (UTF-8)');
$seg = json_decode((string) ($tr['segments_json'] ?? ''), true);
F002Check::that('AK4', is_array($seg) && count($seg) === 2 && $seg[1]['start'] == 65.5, 'segmenter gemt som JSON');
F002Check::that('AK4', ($tr['model'] ?? '') === 'turbo' && ($tr['language'] ?? '') === 'da', 'model og sprog gemt');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$notYet]) === 'ventende', 'opkald uden lyd røres ikke');

// Fejl i motoren: fejlet med kort fejltekst uden sti; højst 3 forsøg
$engine->result = new EngineError('motoren fejlede (exit 1)');
$id = $t->runOnce();
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$new])[0];
F002Check::that('AK4', $id === $new && $row['transcript_status'] === 'fejlet' && $row['transcript_error'] === 'motoren fejlede (exit 1)',
    'motorfejl: status fejlet med fejltekst');
F002Check::that('AK4', !str_contains((string) $row['transcript_error'], $var) && !str_contains((string) $row['transcript_error'], '/'),
    'fejlteksten har ingen sti');
$t->runOnce();
$t->runOnce();
F002Check::that('AK4', (int) DB::scalar('SELECT transcript_attempts FROM calls WHERE id = ?', [$new]) === 3, 'tre forsøg');
$before = $engine->calls;
F002Check::that('AK4', $t->runOnce() === null && $engine->calls === $before, 'efter 3 forsøg tages opkaldet ikke igen');

// En uventet undtagelse fra motoren giver en fast tekst (ingen detaljer)
$x = f002_insert(['started_at' => '2026-10-04 08:00:00']);
f002_audio($x);
$engine->result = ['segments' => 'ikke en liste'];
$t->runOnce();
F002Check::that('AK4', DB::scalar('SELECT transcript_error FROM calls WHERE id = ?', [$x]) === 'motoren gav et ugyldigt svar', 'ugyldigt motorsvar afvises');

// Lydfilen mangler på disken
$gone = f002_insert(['started_at' => '2026-10-04 09:00:00']);
unlink(f002_audio($gone));
$engine->result = (new FakeEngine())->result;
$t->runOnce();
F002Check::that('AK4', DB::scalar('SELECT transcript_error FROM calls WHERE id = ?', [$gone]) === 'Lydfilen mangler på serveren', 'manglende lydfil: fejlet');

// Hængende job: over 2 timer i "transcriberer" sættes tilbage; under 2 timer røres ikke
$hung = f002_insert(['started_at' => '2026-10-05 08:00:00', 'transcript_status' => 'transcriberer', 'transcript_attempts' => 1,
    'claimed_at' => gmdate('Y-m-d H:i:s', F002_NOW - 7201)]);
$busy = f002_insert(['started_at' => '2026-10-05 09:00:00', 'transcript_status' => 'transcriberer', 'transcript_attempts' => 1,
    'claimed_at' => gmdate('Y-m-d H:i:s', F002_NOW - 3600)]);
$dead = f002_insert(['started_at' => '2026-10-05 10:00:00', 'transcript_status' => 'transcriberer', 'transcript_attempts' => 3,
    'claimed_at' => gmdate('Y-m-d H:i:s', F002_NOW - 9000)]);
F002Check::that('AK4', $t->resetStale() === 2, 'to hængende job behandlet');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$hung]) === 'ventende', 'over 2 timer: tilbage til ventende');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$busy]) === 'transcriberer', 'under 2 timer: røres ikke');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$dead]) === 'fejlet', 'hængt 3 gange: fejlet');

// To samtidige kørsler: samme opkald tages kun af én
DB::exec("UPDATE calls SET transcript_status = 'faerdig' WHERE transcript_status IN ('ventende', 'fejlet')");
$one = f002_insert(['started_at' => '2026-10-06 08:00:00']);
f002_audio($one);
$a = new Transcriber($engine, static fn(): int => F002_NOW, static fn(string $l) => null);
$b = new Transcriber($engine, static fn(): int => F002_NOW, static fn(string $l) => null);
$ja = $a->claimNext();
$jb = $b->claimNext();
F002Check::that('AK4', $ja !== null && $ja['id'] === $one && $jb === null, 'anden kørsel får intet, mens den første har opkaldet');
F002Check::that('AK4', (int) DB::scalar('SELECT transcript_attempts FROM calls WHERE id = ?', [$one]) === 1, 'forsøget er talt én gang');

// Låsen: kun én transcribe-kørsel ad gangen (den, der holder låsen, er den eneste, der nulstiller hængende job)
$l1 = Lock::tryAcquire('transcribe');
$l2 = Lock::tryAcquire('transcribe');
F002Check::that('AK4', $l1 !== null && $l2 === null, 'låsen kan ikke tages to gange');
$l1?->release();
$l3 = Lock::tryAcquire('transcribe');
F002Check::that('AK4', $l3 !== null, 'låsen kan tages igen, når den er sluppet');
$l3?->release();

// Motoren: kun turbo og large-v3; ingen shell (argv-array) — tjekkes i kilden
$ok = false;
try {
    new ProcessEngine('large-v3; rm -rf /');
} catch (EngineError) {
    $ok = true;
}
F002Check::that('AK4', $ok, 'ukendt model afvises');
$src = (string) file_get_contents(__DIR__ . '/../src/Transcription/ProcessEngine.php');
F002Check::that('AK4', str_contains($src, 'proc_open($argv') && !preg_match('/\b(shell_exec|exec|system|passthru|popen)\s*\(/', $src),
    'motoren startes med proc_open og et argv-array (ingen shell)');
F002Check::that('AK4', (new ProcessEngine('turbo'))->available() === false, 'uden venv er motoren ikke klar');
foreach ($log as $l) {
    F002Check::that('AK4', preg_match('/^transcribe: opkald [0-9]+: /', $l) === 1, 'log-linjen nævner kun opkalds-id: ' . $l);
}

F002Check::done();
