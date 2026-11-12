<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H006-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's hjælpere
 * (database, var/ i en midlertidig mappe) og H005's falske python.
 */

require_once __DIR__ . '/h005_lib.php';

use Whisper\DB;
use Whisper\Transcription\BatchEngine;
use Whisper\Transcription\EngineError;

final class H006Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H006-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

/**
 * En falsk batch-motor i PHP (ingen proces): afleverer resultaterne i rækkefølge og flytter testens ur. En fil i
 * $fail giver en fejl for netop den fil; når $stopAt nås, "stoppes" motoren (EngineError), som ved tidsgrænsen.
 */
final class FakeBatchEngine implements BatchEngine
{
    /** @var list<list<string>> */
    public array $calls = [];
    /** @var list<int> */
    public array $limits = [];
    /** @var list<string> */
    public array $fail = [];
    public ?string $stopAt = null;
    public int $size = 10;
    public int $singleCalls = 0;

    public function __construct(private readonly stdClass $clock, public float $load = 12.4, public int $perFile = 65)
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

    public function batchSize(): int
    {
        return $this->size;
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        $this->singleCalls++;
        return (new FakeEngine())->result;
    }

    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float
    {
        $this->calls[] = $files;
        $this->limits[] = $limitSec;
        $this->clock->now += (int) round($this->load);
        foreach ($files as $f) {
            $this->clock->now += $this->perFile;
            if ($f === $this->stopAt) {
                throw new EngineError('motoren blev stoppet efter tidsgrænsen (50 min)');
            }
            if (in_array($f, $this->fail, true)) {
                $onResult($f, new EngineError('motoren kunne ikke transcribere lydfilen'), 0.4);
                continue;
            }
            $onResult($f, (new FakeEngine())->result + ['duration' => 30.0], (float) $this->perFile);
        }
        return $this->load;
    }
}

/**
 * En falsk "python" i var/whisper/venv/bin, der lægger sit argv (én pr. linje, afsluttet med "==") i $argvFile og i
 * batch-tilstand svarer med JSON Lines for hver fil efter "--": en fil med en søsterfil "<fil>.fejl" giver en
 * error-linje, en med "<fil>.haeng" sover (tidsgrænsen). Uden "--" svarer den som H005's (ét JSON-objekt). Kun Unix.
 */
function h006_fake_python(string $var, string $argvFile): void
{
    $sh = <<<'SH'
#!/bin/sh
{ printf '%s\n' "$@"; echo '=='; } >> '@ARGV@'
batch=0
for a in "$@"; do
  if [ "$batch" = 1 ]; then
    if [ -e "$a.fejl" ]; then
      printf '{"file":"%s","error":"RuntimeError","elapsed":0.4}\n' "$a"
    elif [ -e "$a.haeng" ]; then
      exec sleep 60
    else
      printf '{"file":"%s","text":"ok","segments":[{"start":0,"end":1,"text":"ok"}],"duration":30,"model":"turbo","language":"da","elapsed":65}\n' "$a"
    fi
  fi
  if [ "$a" = "--" ]; then batch=1; fi
done
if [ "$batch" = 1 ]; then
  echo '{"load_seconds":12.4}'
else
  echo '{"text":"ok","segments":[{"start":0,"end":1,"text":"ok"}],"duration":30,"model":"turbo","language":"da"}'
fi

SH;
    $py = \Whisper\Paths::dir('whisper/venv/bin') . '/python';
    file_put_contents($py, str_replace('@ARGV@', $argvFile, $sh));
    chmod($py, 0700);
    file_put_contents($var . '/whisper/ready-turbo', 'x');
}

/** @return list<list<string>> argv for hvert kald af den falske python */
function h006_invocations(string $argvFile): array
{
    $raw = is_file($argvFile) ? (string) file_get_contents($argvFile) : '';
    $out = [];
    $cur = [];
    foreach ($raw === '' ? [] : explode("\n", rtrim($raw, "\n")) as $line) {
        if ($line === '==') {
            $out[] = $cur;
            $cur = [];
        } else {
            $cur[] = $line;
        }
    }
    return $out;
}

/** @param list<string> $argv @return list<string> filerne efter "--" */
function h006_files(array $argv): array
{
    $i = array_search('--', $argv, true);
    return is_int($i) ? array_slice($argv, $i + 1) : [];
}

/** @return array{status: string, attempts: int, retry: ?string, error: ?string, transcript: bool} */
function h006_state(int $id): array
{
    $r = DB::all('SELECT transcript_status, transcript_attempts, transcript_retry_at, transcript_error FROM calls WHERE id = ?', [$id])[0] ?? [];
    return ['status' => (string) ($r['transcript_status'] ?? ''), 'attempts' => (int) ($r['transcript_attempts'] ?? -1),
        'retry' => isset($r['transcript_retry_at']) ? (string) $r['transcript_retry_at'] : null,
        'error' => isset($r['transcript_error']) ? (string) $r['transcript_error'] : null,
        'transcript' => (int) DB::scalar('SELECT COUNT(*) FROM transcripts WHERE call_id = ?', [$id]) === 1];
}

/**
 * Tre ventende opkald med lyd (A før B før C).
 *
 * @return list<array{id: int, file: string}>
 */
function h006_three_calls(): array
{
    $out = [];
    foreach (['2026-10-01 08:00:00', '2026-10-01 09:00:00', '2026-10-01 10:00:00'] as $at) {
        $id = f002_insert(['started_at' => $at, 'duration_sec' => 125]);
        $out[] = ['id' => $id, 'file' => f002_audio($id)];
    }
    return $out;
}
