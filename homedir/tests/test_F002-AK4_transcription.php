<?php
declare(strict_types=1);

// F002-AK4: transcribe tager ét ventende opkald, kører motoren (mocket) og gemmer tekst og segmenter. Status går til
// faerdig, ved fejl til fejlet med fejltekst; et hængende job sættes tilbage efter 2 timer. To samtidige kørsler
// behandler aldrig samme opkald. Motor ikke klar bruger intet forsøg, fejl giver 15 min backoff, en hængende motor
// stoppes ved tidsgrænsen, og whisper-requeue.php sætter fejlede tilbage.

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Lock;
use Whisper\Paths;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;

$var = f002_setup();
$engine = new FakeEngine();
$log = [];
$now = F002_NOW;
$t = new Transcriber($engine, static function () use (&$now): int {
    return $now;
}, static function (string $l) use (&$log): void {
    $log[] = $l;
});

F002Check::that('AK4', $t->runOnce() === null && $engine->calls === 0, 'tom kø: intet sker');
$notYet = f002_insert(['started_at' => '2026-10-01 08:00:00']);   // lyden er ikke hentet: tages ikke
$first = f002_insert(['started_at' => '2026-10-03 08:00:00', 'duration_sec' => 4000]);
$second = f002_insert(['started_at' => '2026-10-02 08:00:00']);
f002_audio($first);
f002_audio($second);

// Motor ikke klar: intet opkald tages, intet forsøg bruges (QA F-3)
$engine->ready = false;
F002Check::that('AK4', $t->runOnce() === null && $engine->calls === 0, 'motor ikke klar: intet tages');
F002Check::that('AK4', (int) DB::scalar('SELECT SUM(transcript_attempts) FROM calls') === 0, 'motor ikke klar: intet forsøg brugt');
$engine->ready = true;

$id = $t->runOnce();
F002Check::that('AK4', $id === $first, 'nyeste ventende opkald med lyd tages først (H012)');
F002Check::that('AK4', $engine->calls === 1 && $engine->lastDuration === 4000, 'motoren kørt én gang med opkaldets varighed');
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$first])[0];
F002Check::that('AK4', $row['transcript_status'] === 'faerdig' && $row['transcript_error'] === null, 'status faerdig');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$second]) === 'ventende', 'det andet opkald venter stadig (ét ad gangen)');
$tr = DB::all('SELECT * FROM transcripts WHERE call_id = ?', [$first])[0] ?? [];
F002Check::that('AK4', ($tr['text'] ?? '') === 'Hej og velkommen. Det handler om strøm og måler.', 'tekst gemt (UTF-8)');
$seg = json_decode((string) ($tr['segments_json'] ?? ''), true);
F002Check::that('AK4', is_array($seg) && count($seg) === 2 && $seg[1]['start'] == 65.5, 'segmenter gemt som JSON');
F002Check::that('AK4', ($tr['model'] ?? '') === 'turbo' && ($tr['language'] ?? '') === 'da', 'model og sprog gemt');
F002Check::that('AK4', DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$notYet]) === 'ventende', 'opkald uden lyd røres ikke');

// Fejl i motoren: fejlet med kort fejltekst uden sti; backoff 15 min; højst 3 forsøg
$engine->result = new EngineError('motoren fejlede (exit 1)');
$id = $t->runOnce();
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$second])[0];
F002Check::that('AK4', $id === $second && $row['transcript_status'] === 'fejlet' && $row['transcript_error'] === 'motoren fejlede (exit 1)',
    'motorfejl: status fejlet med fejltekst');
F002Check::that('AK4', !str_contains((string) $row['transcript_error'], $var) && !str_contains((string) $row['transcript_error'], '/'),
    'fejlteksten har ingen sti');
F002Check::that('AK4', $t->runOnce() === null, 'backoff: ikke prøvet igen med det samme');
$now += 14 * 60;
F002Check::that('AK4', $t->runOnce() === null, 'backoff: ikke prøvet igen efter 14 min');
$now += 2 * 60;
F002Check::that('AK4', $t->runOnce() === $second, 'backoff: prøvet igen efter 15 min');
$now += 16 * 60;
$t->runOnce();
F002Check::that('AK4', (int) DB::scalar('SELECT transcript_attempts FROM calls WHERE id = ?', [$second]) === 3, 'tre forsøg');
$now += 16 * 60;
$before = $engine->calls;
F002Check::that('AK4', $t->runOnce() === null && $engine->calls === $before, 'efter 3 forsøg tages opkaldet ikke igen');

// whisper-requeue.php sætter fejlede tilbage til ventende med nulstillede forsøg (CLI)
$run = static function (array $args): array {
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../cron/whisper-requeue.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
    return [is_resource($p) ? proc_close($p) : -1, $out];
};
[$code, $out] = $run(['--id=' . $second]);
$row = DB::all('SELECT * FROM calls WHERE id = ?', [$second])[0];
F002Check::that('AK4', $code === 0 && str_contains($out, '1 opkald') && $row['transcript_status'] === 'ventende'
    && (int) $row['transcript_attempts'] === 0 && $row['transcript_error'] === null && $row['transcript_retry_at'] === null,
    'whisper-requeue.php --id: tilbage til ventende, forsøg nulstillet');
[$code] = $run(['--id=1; DROP TABLE calls']);
F002Check::that('AK4', $code === 2 && (int) DB::scalar('SELECT COUNT(*) FROM calls') > 0, 'ugyldigt argument afvises');
$src = (string) file_get_contents(__DIR__ . '/../cron/whisper-requeue.php');
F002Check::that('AK4', str_contains($src, "PHP_SAPI !== 'cli'"), 'whisper-requeue.php er kun CLI');

// En uventet undtagelse fra motoren giver en fast tekst (ingen detaljer)
$engine->result = (new FakeEngine())->result;
$t->runOnce();   // $second bliver færdig
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
    'claimed_at' => gmdate('Y-m-d H:i:s', $now - 7201)]);
$busy = f002_insert(['started_at' => '2026-10-05 09:00:00', 'transcript_status' => 'transcriberer', 'transcript_attempts' => 1,
    'claimed_at' => gmdate('Y-m-d H:i:s', $now - 3600)]);
$dead = f002_insert(['started_at' => '2026-10-05 10:00:00', 'transcript_status' => 'transcriberer', 'transcript_attempts' => 3,
    'claimed_at' => gmdate('Y-m-d H:i:s', $now - 9000)]);
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

// Motoren: kun turbo og large-v3; ingen shell (argv-array); klar kræver markøren for modellen; tidsgrænse
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
F002Check::that('AK4', ProcessEngine::timeoutFor(60) === 7200 && ProcessEngine::timeoutFor(3 * 3600) === 4 * 3 * 3600,
    'tidsgrænse = max(2 t, 4 × varighed)');
F002Check::that('AK4', (new ProcessEngine('turbo'))->available() === false, 'uden venv er motoren ikke klar');

if (DIRECTORY_SEPARATOR === '/') {
    // En falsk "python" i venv'et: motorens rigtige procesvej (proc_open, exitkode, JSON, tidsgrænse) uden model
    $bin = Paths::dir('whisper/venv/bin');
    $py = $bin . '/python';
    $audio = f002_audio(f002_insert(['started_at' => '2026-10-07 08:00:00']));
    file_put_contents($py, "#!/bin/sh\necho '{\"text\":\"ok\",\"segments\":[{\"start\":0,\"end\":1,\"text\":\"ok\"}],\"model\":\"turbo\",\"language\":\"da\"}'\n");
    chmod($py, 0700);
    $pe = new ProcessEngine('turbo', 5);
    F002Check::that('AK4', $pe->available() === false, 'venv uden markøren ready-turbo: ikke klar');
    file_put_contents($var . '/whisper/ready-large-v3', 'x');
    F002Check::that('AK4', $pe->available() === false, 'markør for en anden model: ikke klar');
    file_put_contents($var . '/whisper/ready-turbo', 'x');
    F002Check::that('AK4', $pe->available() === true, 'markøren for den konfigurerede model: klar');
    F002Check::that('AK4', ($pe->transcribe($audio, 10)['text'] ?? '') === 'ok', 'motorens JSON læses fra stdout');
    file_put_contents($py, "#!/bin/sh\nexec sleep 60\n");
    $t0 = microtime(true);
    $msg = '';
    try {
        (new ProcessEngine('turbo', 2))->transcribe($audio, 10);
    } catch (EngineError $e) {
        $msg = $e->getMessage();
    }
    $took = microtime(true) - $t0;
    F002Check::that('AK4', str_starts_with($msg, 'motoren blev stoppet efter tidsgrænsen') && $took < 20,
        sprintf('hængende motor stoppes ved tidsgrænsen (%.1f s)', $took));
    file_put_contents($py, "#!/bin/sh\nexit 3\n");
    $msg = '';
    try {
        $pe->transcribe($audio, 10);
    } catch (EngineError $e) {
        $msg = $e->getMessage();
    }
    F002Check::that('AK4', $msg === 'motoren fejlede (exit 3)', 'exitkode ≠ 0: kort fejltekst');
}

foreach ($log as $l) {
    F002Check::that('AK4', preg_match('/^transcribe: opkald [0-9]+: /', $l) === 1, 'log-linjen nævner kun opkalds-id: ' . $l);
}

F002Check::done();
