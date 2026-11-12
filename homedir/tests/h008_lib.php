<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H008-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's og H006's
 * hjælpere (database, var/ i en midlertidig mappe, falsk python).
 */

require_once __DIR__ . '/h006_lib.php';

use Whisper\DB;
use Whisper\Transcription\BatchEngine;
use Whisper\Transcription\EngineError;

final class H008Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H008-$ak $msg\n";
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
 * En falsk batch-motor, der stopper som den rigtige: når $hangOn nås, hænger filen, til batchens grænse er brugt
 * (uret flyttes til start + grænse), og motoren kaster EngineError med koden TIMEOUT. $singleTimeout gør det samme for
 * et opkald, der kører alene (transcribe()).
 */
final class H008Engine implements BatchEngine
{
    /** @var list<list<string>> */
    public array $calls = [];
    /** @var list<int> */
    public array $limits = [];
    public ?string $hangOn = null;
    public bool $singleTimeout = false;
    public int $size = 10;

    public function __construct(private readonly stdClass $clock, public int $load = 12, public int $perFile = 65)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'threads 3, beam 1, vad on';
    }

    public function batchSize(): int
    {
        return $this->size;
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        if ($this->singleTimeout) {
            $limit = max(7200, 4 * $durationSec);
            $this->clock->now += $limit;
            throw new EngineError('motoren blev stoppet efter tidsgrænsen (' . intdiv($limit, 60) . ' min)', EngineError::TIMEOUT);
        }
        return (new FakeEngine())->result;
    }

    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float
    {
        $this->calls[] = $files;
        $this->limits[] = $limitSec;
        $start = (int) $this->clock->now;
        $this->clock->now += $this->load;
        foreach ($files as $f) {
            if ($f === $this->hangOn) {
                $this->clock->now = $start + $limitSec;
                throw new EngineError('motoren blev stoppet efter tidsgrænsen (' . intdiv($limitSec, 60) . ' min)', EngineError::TIMEOUT);
            }
            $this->clock->now += $this->perFile;
            $onResult($f, (new FakeEngine())->result + ['duration' => 30.0], (float) $this->perFile);
        }
        return (float) $this->load;
    }
}

/**
 * $n ventende opkald med lyd i køens rækkefølge (hvert et minut senere end det forrige).
 *
 * @param list<int> $durations
 * @return list<array{id: int, file: string}>
 */
function h008_calls(array $durations): array
{
    $out = [];
    foreach ($durations as $i => $d) {
        $id = f002_insert(['started_at' => gmdate('Y-m-d H:i:s', 1790000000 + $i * 60), 'duration_sec' => $d]);
        $out[] = ['id' => $id, 'file' => f002_audio($id)];
    }
    return $out;
}

function h008_count(string $status): int
{
    return (int) DB::scalar('SELECT COUNT(*) FROM calls WHERE transcript_status = ?', [$status]);
}

/** @param list<string> $log */
function h008_lines(array $log, string $needle): int
{
    return count(array_filter($log, static fn(string $l): bool => str_contains($l, $needle)));
}
