<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;

/**
 * Motorens ydelsesindstillinger fra config.ini [whisper] (alle valgfri):
 *   threads   = 1–32   (standard: antal kerner − 1, mindst 1; kendes kernerne ikke: 4 som faster-whisper selv)
 *   beam_size = 1–10   (standard 1)
 *   vad       = on|off (standard on)
 * Ugyldige værdier giver standarden og én advarsel pr. nøgle, så en tastefejl i config ikke stopper køen.
 * Værdierne ender som argv-elementer til transcribe.py, så konstruktøren afviser alt uden for intervallerne.
 */
final class WhisperSettings
{
    public const MAX_THREADS = 32;
    public const MAX_BEAM = 10;
    public const DEFAULT_BEAM = 1;
    public const FALLBACK_THREADS = 4;

    public function __construct(public readonly int $threads, public readonly int $beam, public readonly bool $vad)
    {
        if ($threads < 1 || $threads > self::MAX_THREADS || $beam < 1 || $beam > self::MAX_BEAM) {
            throw new EngineError('[whisper] threads skal være 1–32 og beam_size 1–10');
        }
    }

    public static function defaultThreads(?int $cores): int
    {
        if ($cores === null || $cores < 1) {
            return self::FALLBACK_THREADS;
        }
        return min(self::MAX_THREADS, max(1, $cores - 1));
    }

    /**
     * @param array<string, mixed> $section config.ini [whisper]
     * @param (Closure(string): void)|null $warn
     */
    public static function fromSection(array $section, ?int $cores, ?Closure $warn = null): self
    {
        $warn ??= static function (string $line): void {
        };
        $threads = self::intOr($section, 'threads', 1, self::MAX_THREADS, self::defaultThreads($cores), $warn);
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
        return new self($threads, $beam, $vad);
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
        return ['--threads', (string) $this->threads, '--beam', (string) $this->beam, '--vad', $this->vad ? '1' : '0'];
    }

    public function label(): string
    {
        return 'threads ' . $this->threads . ', beam ' . $this->beam . ', vad ' . ($this->vad ? 'on' : 'off');
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
