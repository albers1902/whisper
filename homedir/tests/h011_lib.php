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
 * en fil uden svar giver ingen intervaller. Med $throwAt kaster motoren, når den når den fil (som en motor, der dør),
 * og med $busyAt lægges et ventende opkald i transcription-køen, når den fil nås, hvorefter $stop() spørges.
 */
final class FakeDiarizer implements DiarizationEngine
{
    /** @var array<string, list<mixed>|EngineError> */
    public array $answers = [];
    /** @var list<list<string>> */
    public array $calls = [];
    public ?string $throwAt = null;
    public ?string $busyAt = null;
    public bool $ready = true;
    /** @var (Closure(string): void)|null kaldes med filen, lige før dens svar afleveres */
    public ?Closure $before = null;

    public function available(): bool
    {
        return $this->ready;
    }

    public function diarize(array $files, int $limitSec, Closure $onResult, Closure $stop): bool
    {
        $this->calls[] = $files;
        foreach ($files as $f) {
            if ($f === $this->busyAt) {
                h011_new_call();
                if ($stop()) {
                    return true;
                }
            }
            if ($this->before !== null) {
                ($this->before)($f);
            }
            if ($f === $this->throwAt) {
                throw new EngineError('diarization fejlede (exit 1)');
            }
            $onResult($f, $this->answers[$f] ?? [], 1.5);
        }
        return false;
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
 * @param list<array{start: float, end: float, text: string}> $segments
 * @return array{id: int, file: string}
 */
function h011_done_call(array $segments, string $startedAt = '2026-10-05 10:00:00'): array
{
    $id = f002_insert(['started_at' => $startedAt]);
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

/**
 * @param list<string> $log
 */
function h011_diarizer(FakeDiarizer $engine, array &$log, ?DiarizationSettings $settings = null): Diarizer
{
    return new Diarizer($engine, $settings ?? new DiarizationSettings(true), null, static function (string $l) use (&$log): void {
        $log[] = $l;
    });
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
