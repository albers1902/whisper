<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H011-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's hjælpere
 * (database, var/ i en midlertidig mappe).
 */

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Transcription\DiarizationEngine;
use Whisper\Transcription\DiarizationSettings;
use Whisper\Transcription\Diarizer;
use Whisper\Transcription\EngineError;

final class H011Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H011-$ak $msg\n";
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
 * En falsk diarization-motor i PHP (ingen proces). Svaret pr. fil står i $answers (intervaller eller EngineError);
 * en fil uden svar giver ingen intervaller, og en fil i $silent får slet intet svar (exit 0). Med $throwAt kaster
 * motoren, når den når den fil, og med $throwAlways ved start (en motor, der dør). Med $busyAt lægges et ventende
 * opkald i transcription-køen, når den fil nås, hvorefter $stop() spørges.
 */
final class FakeDiarizer implements DiarizationEngine
{
    /** @var array<string, list<mixed>|EngineError> */
    public array $answers = [];
    /** @var list<string> */
    public array $silent = [];
    /** @var list<list<string>> */
    public array $calls = [];
    public ?string $throwAt = null;
    public bool $throwAlways = false;
    public ?string $busyAt = null;
    public bool $ready = true;
    /** @var (Closure(string): void)|null kaldes med filen, lige før dens svar afleveres */
    public ?Closure $before = null;

    public function available(): bool
    {
        return $this->ready;
    }

    public function diarize(array $files, int $limitSec, Closure $onResult, Closure $stop): ?float
    {
        $this->calls[] = $files;
        if ($this->throwAlways) {
            throw new EngineError('diarization fejlede (exit 1)');
        }
        foreach ($files as $f) {
            if ($f === $this->busyAt) {
                h011_new_call();
            }
            if (($f === $this->busyAt || $this->busyAt === '*') && $stop()) {
                return 0.1;
            }
            if ($this->before !== null) {
                ($this->before)($f);
            }
            if ($f === $this->throwAt) {
                throw new EngineError('diarization fejlede (exit 1)');
            }
            if (in_array($f, $this->silent, true)) {
                continue;
            }
            $onResult($f, $this->answers[$f] ?? [], 1.5);
        }
        return null;
    }
}

/** Et nyt opkald med hentet lyd, der venter på transcription (gør transcription-køen ikke-tom). */
function h011_new_call(): int
{
    $id = f002_insert(['started_at' => '2026-10-20 08:00:00']);
    f002_audio($id);
    return $id;
}

/**
 * Et færdigt opkald med transcript og lyd.
 *
 * @param list<array<string, mixed>> $segments
 * @return array{id: int, file: string}
 */
function h011_done_call(array $segments, string $startedAt = '2026-10-05 10:00:00', int $duration = 125): array
{
    $id = f002_insert(['started_at' => $startedAt, 'duration_sec' => $duration]);
    $file = f002_audio($id);
    f002_transcript($id, implode(' ', array_column($segments, 'text')), $segments);
    return ['id' => $id, 'file' => (string) realpath($file)];
}

/** @return array{segments: list<array<string, mixed>>, status: ?string, attempts: int, json: string} */
function h011_row(int $id): array
{
    $r = DB::all('SELECT segments_json, diar_status, diar_attempts FROM transcripts WHERE call_id = ?', [$id])[0] ?? [];
    $json = (string) ($r['segments_json'] ?? '');
    $seg = json_decode($json, true);
    return ['segments' => is_array($seg) ? $seg : [], 'status' => isset($r['diar_status']) ? (string) $r['diar_status'] : null,
        'attempts' => (int) ($r['diar_attempts'] ?? -1), 'json' => $json];
}

/** Testens ur (sekunder); flyttes med $clock->now += … */
function h011_clock(): stdClass
{
    $c = new stdClass();
    $c->now = time();
    return $c;
}

/**
 * @param list<string> $log
 * @param (Closure(): bool)|null $halt
 */
function h011_diarizer(FakeDiarizer $engine, array &$log, ?stdClass $clock = null, ?Closure $halt = null,
    ?DiarizationSettings $settings = null): Diarizer
{
    $clock ??= h011_clock();
    return new Diarizer($engine, $settings ?? new DiarizationSettings(true), static fn(): int => (int) $clock->now,
        static function (string $l) use (&$log): void {
            $log[] = $l;
        }, $halt);
}

/** Tre segmenter: agenten præsenterer sig, kunden svarer, agenten igen. */
function h011_segments(): array
{
    return [
        ['start' => 0.0, 'end' => 4.0, 'text' => 'Hej, du taler med Mette fra Tryg Energi.'],
        ['start' => 4.5, 'end' => 9.0, 'text' => 'Hej, jeg ringer om min regning.'],
        ['start' => 9.5, 'end' => 12.0, 'text' => 'Det kan jeg hjælpe med.'],
    ];
}
