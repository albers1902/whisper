<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;

/**
 * Motorens ydelsesindstillinger fra config.ini [whisper] (alle valgfri):
 *   threads   = 1–32   (standard: antal kerner − 1, mindst 1; kendes kernerne ikke: 4 som faster-whisper selv)
 *   beam_size = 1–10   (standard 1)
 *   vad       = on|off (standard on)
 *   batch     = 1–50   (standard 10: opkald pr. motorkald, så modellen indlæses én gang pr. batch; 1 = ét pr. kald)
 *   workers   = 1–4    (standard 2: samtidige transcribe-kørsler, hver med sin egen motor; 1 = én ad gangen)
 *   rtf_estimate = 0,3–5 (standard 1,5: forventet behandlingstid pr. sekund lyd; en batch claimer kun opkald, hvis
 *                  Σ varighed × rtf_estimate når at blive færdig inden for kørslens resttid). Både 1.5 og 1,5 læses.
 *   engine_mode  = sequential|batched (standard sequential: faster-whispers almindelige transcribe; batched =
 *                  BatchedInferencePipeline, der kører VAD-stykker på højst 30 s gennem modellen flere ad gangen)
 *   batched_size = 1–32 (standard 4: stykker pr. modelkald i batched; hvert stykke koster ca. 0,3 GB RAM pr. worker)
 * Batched kræver VAD (faster-whisper 1.2.1 fejler uden VAD på lyd over 30 s), så batched + vad off giver vad on og én
 * advarsel.
 * Uden threads deles kernerne mellem workers: max(1, kerner / workers) ved workers > 1, ellers kerner − 1.
 * Ugyldige værdier giver standarden og én advarsel pr. nøgle, så en tastefejl i config ikke stopper køen.
 * Værdierne ender som argv-elementer til transcribe.py, så konstruktøren afviser alt uden for intervallerne.
 */
final class WhisperSettings
{
    public const MAX_THREADS = 32;
    public const MAX_BEAM = 10;
    public const DEFAULT_BEAM = 1;
    public const FALLBACK_THREADS = 4;
    public const MAX_BATCH = 50;
    public const DEFAULT_BATCH = 10;
    public const MAX_WORKERS = Slots::MAX;
    public const DEFAULT_WORKERS = 2;
    public const MIN_RTF = 0.3;
    public const MAX_RTF = 5.0;
    public const DEFAULT_RTF = 1.5;
    public const MODE_SEQUENTIAL = 'sequential';
    public const MODE_BATCHED = 'batched';
    public const MAX_BATCHED = 32;
    public const DEFAULT_BATCHED = 4;

    public function __construct(public readonly int $threads, public readonly int $beam, public readonly bool $vad,
        public readonly int $batch = self::DEFAULT_BATCH, public readonly int $workers = 1,
        public readonly float $rtf = self::DEFAULT_RTF, public readonly string $mode = self::MODE_SEQUENTIAL,
        public readonly int $batchedSize = self::DEFAULT_BATCHED)
    {
        if ($threads < 1 || $threads > self::MAX_THREADS || $beam < 1 || $beam > self::MAX_BEAM
            || $batch < 1 || $batch > self::MAX_BATCH || $workers < 1 || $workers > self::MAX_WORKERS
            || !($rtf >= self::MIN_RTF && $rtf <= self::MAX_RTF)) {
            throw new EngineError('[whisper] threads skal være 1–32, beam_size 1–10, batch 1–50, workers 1–4 og rtf_estimate 0,3–5');
        }
        if (!in_array($mode, [self::MODE_SEQUENTIAL, self::MODE_BATCHED], true) || $batchedSize < 1
            || $batchedSize > self::MAX_BATCHED || ($mode === self::MODE_BATCHED && !$vad)) {
            throw new EngineError('[whisper] engine_mode skal være sequential eller batched (batched med vad on), batched_size 1–32');
        }
    }

    public static function defaultThreads(?int $cores, int $workers = 1): int
    {
        if ($cores === null || $cores < 1) {
            return self::FALLBACK_THREADS;
        }
        // Flere processer: hver får sin andel af kernerne (greedy decoding udnytter ikke flere tråde i én proces)
        return min(self::MAX_THREADS, max(1, $workers > 1 ? intdiv($cores, $workers) : $cores - 1));
    }

    /**
     * @param array<string, mixed> $section config.ini [whisper]
     * @param (Closure(string): void)|null $warn
     */
    public static function fromSection(array $section, ?int $cores, ?Closure $warn = null): self
    {
        $warn ??= static function (string $line): void {
        };
        $workers = self::intOr($section, 'workers', 1, self::MAX_WORKERS, self::DEFAULT_WORKERS, $warn);
        $threads = self::intOr($section, 'threads', 1, self::MAX_THREADS, self::defaultThreads($cores, $workers), $warn);
        $beam = self::intOr($section, 'beam_size', 1, self::MAX_BEAM, self::DEFAULT_BEAM, $warn);
        $vad = true;
        $raw = self::raw($section, 'vad');
        if ($raw !== null) {
            $v = strtolower($raw);
            if (in_array($v, ['on', '1', 'true', 'yes'], true)) {
                $vad = true;
            } elseif (in_array($v, ['off', '0', 'false', 'no'], true)) {
                $vad = false;
            } else {
                $warn('transcribe: advarsel: [whisper] vad skal være on eller off — bruger on');
            }
        }
        $batch = self::intOr($section, 'batch', 1, self::MAX_BATCH, self::DEFAULT_BATCH, $warn);
        $rtf = self::DEFAULT_RTF;
        $raw = self::raw($section, 'rtf_estimate');
        if ($raw !== null) {
            $v = preg_match('/^[0-9](?:[.,][0-9]{1,2})?$/', $raw) === 1 ? (float) str_replace(',', '.', $raw) : -1.0;
            if ($v >= self::MIN_RTF && $v <= self::MAX_RTF) {
                $rtf = $v;
            } else {
                $warn('transcribe: advarsel: [whisper] rtf_estimate skal være 0,3–5 — bruger 1,5');
            }
        }
        $mode = self::MODE_SEQUENTIAL;
        $raw = self::raw($section, 'engine_mode');
        if ($raw !== null) {
            $v = strtolower($raw);
            if ($v === self::MODE_SEQUENTIAL || $v === self::MODE_BATCHED) {
                $mode = $v;
            } else {
                $warn('transcribe: advarsel: [whisper] engine_mode skal være sequential eller batched — bruger sequential');
            }
        }
        $batchedSize = self::intOr($section, 'batched_size', 1, self::MAX_BATCHED, self::DEFAULT_BATCHED, $warn);
        if ($mode === self::MODE_BATCHED && !$vad) {
            $warn('transcribe: advarsel: [whisper] engine_mode = batched kræver vad on — bruger vad on');
            $vad = true;
        }
        return new self($threads, $beam, $vad, $batch, $workers, $rtf, $mode, $batchedSize);
    }

    /** Antal kerner uden shell: /proc/cpuinfo, ellers /sys/devices/system/cpu/online. null = ukendt. */
    public static function cores(string $cpuinfo = '/proc/cpuinfo', string $online = '/sys/devices/system/cpu/online'): ?int
    {
        if (@is_readable($cpuinfo)) {
            $n = preg_match_all('/^processor\s*:/m', (string) @file_get_contents($cpuinfo));
            if (is_int($n) && $n > 0) {
                return $n;
            }
        }
        if (@is_readable($online)) {
            $n = 0;
            foreach (explode(',', trim((string) @file_get_contents($online))) as $part) {
                if (preg_match('/^(\d+)(?:-(\d+))?$/', $part, $m) === 1) {
                    $n += isset($m[2]) ? max(0, (int) $m[2] - (int) $m[1]) + 1 : 1;
                }
            }
            if ($n > 0) {
                return $n;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function argv(): array
    {
        $argv = ['--threads', (string) $this->threads, '--beam', (string) $this->beam, '--vad', $this->vad ? '1' : '0'];
        // sequential sender intet --mode, så argv er præcis som før engine_mode fandtes
        if ($this->mode === self::MODE_BATCHED) {
            $argv = array_merge($argv, ['--mode', self::MODE_BATCHED, '--batched-size', (string) $this->batchedSize]);
        }
        return $argv;
    }

    public function label(): string
    {
        return 'threads ' . $this->threads . ', beam ' . $this->beam . ', vad ' . ($this->vad ? 'on' : 'off')
            . ', mode ' . $this->mode;
    }

    /** @param array<string, mixed> $section */
    private static function raw(array $section, string $key): ?string
    {
        $v = $section[$key] ?? null;
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /**
     * @param array<string, mixed> $section
     * @param Closure(string): void $warn
     */
    private static function intOr(array $section, string $key, int $min, int $max, int $default, Closure $warn): int
    {
        $raw = self::raw($section, $key);
        if ($raw === null) {
            return $default;
        }
        if (preg_match('/^[0-9]{1,3}$/', $raw) === 1 && (int) $raw >= $min && (int) $raw <= $max) {
            return (int) $raw;
        }
        $warn('transcribe: advarsel: [whisper] ' . $key . ' skal være ' . $min . '–' . $max . ' — bruger ' . $default);
        return $default;
    }
}
