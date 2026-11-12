<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Whisper\Config;
use Whisper\Flags;
use Whisper\Paths;

/**
 * Hvem taler (H011). Diarization kører kun, når flaget H011_diarization er on i miljøet OG config.ini ikke siger
 * `[diarization] enabled = off`; config er nødbremsen, der virker uden deploy. Valgfrit i config.ini [diarization]:
 *   enabled       = on|off (standard on: flaget afgør)
 *   agent_phrases = du snakker med|du taler med|…  (delstrenge; store/små bogstaver er ligegyldige)
 *   threads       = 1–32 (standard som whispers tråde pr. worker, WhisperSettings::defaultThreads)
 * Ugyldige værdier giver standarden og én advarsel pr. nøgle, som [whisper].
 * Modellerne lægges af cron/whisper-setup.sh i var/whisper/diarization; markøren ready-diarization skrives først, når
 * selvtesten er bestået. Uden den gør cron/diarize.php intet.
 */
final class DiarizationSettings
{
    public const FLAG = 'H011_diarization';
    public const DEFAULT_PHRASES = ['du snakker med', 'du taler med', 'du har ringet til', 'velkommen til', 'mit navn er',
        'fra tryg energi'];
    public const MAX_PHRASES = 30;
    public const MAX_PHRASE_LEN = 100;

    /** @param list<string> $phrases små bogstaver, ikke tomme */
    public function __construct(public readonly bool $enabled, public readonly array $phrases = self::DEFAULT_PHRASES,
        public readonly int $threads = WhisperSettings::FALLBACK_THREADS)
    {
        if ($threads < 1 || $threads > WhisperSettings::MAX_THREADS) {
            throw new EngineError('[diarization] threads skal være 1–32');
        }
    }

    /** @param (Closure(string): void)|null $warn */
    public static function fromConfig(?Closure $warn = null): self
    {
        $whisper = WhisperSettings::fromSection(Config::section('whisper'), WhisperSettings::cores());
        return self::fromSection(Config::section('diarization'), Flags::enabled(self::FLAG), $whisper->threads, $warn);
    }

    /**
     * @param array<string, mixed> $section config.ini [diarization]
     * @param int $defaultThreads whispers tråde pr. worker: diarization kører i ledig tid i stedet for en worker
     * @param (Closure(string): void)|null $warn
     */
    public static function fromSection(array $section, bool $flagOn, int $defaultThreads = WhisperSettings::FALLBACK_THREADS,
        ?Closure $warn = null): self
    {
        $warn ??= static function (string $line): void {
        };
        $on = true;
        $raw = self::raw($section, 'enabled');
        if ($raw !== null) {
            $v = strtolower($raw);
            if (in_array($v, ['on', '1', 'true', 'yes'], true)) {
                $on = true;
            } elseif (in_array($v, ['off', '0', 'false', 'no'], true)) {
                $on = false;
            } else {
                $warn('diarize: advarsel: [diarization] enabled skal være on eller off — bruger on');
            }
        }
        $phrases = self::DEFAULT_PHRASES;
        $raw = self::raw($section, 'agent_phrases');
        if ($raw !== null) {
            $list = [];
            foreach (explode('|', $raw) as $p) {
                $p = mb_strtolower(trim($p), 'UTF-8');
                if ($p !== '' && mb_strlen($p, 'UTF-8') <= self::MAX_PHRASE_LEN && !in_array($p, $list, true)) {
                    $list[] = $p;
                }
            }
            if ($list === [] || count($list) > self::MAX_PHRASES) {
                $warn('diarize: advarsel: [diarization] agent_phrases skal være 1–' . self::MAX_PHRASES
                    . ' sætninger adskilt med | — bruger standardlisten');
            } else {
                $phrases = $list;
            }
        }
        $default = max(1, min(WhisperSettings::MAX_THREADS, $defaultThreads));
        $threads = $default;
        $raw = self::raw($section, 'threads');
        if ($raw !== null) {
            if (preg_match('/^[0-9]{1,2}$/', $raw) === 1 && (int) $raw >= 1 && (int) $raw <= WhisperSettings::MAX_THREADS) {
                $threads = (int) $raw;
            } else {
                $warn('diarize: advarsel: [diarization] threads skal være 1–32 — bruger ' . $default);
            }
        }
        return new self($flagOn && $on, $phrases, $threads);
    }

    public static function modelDir(): string
    {
        return Paths::varDir() . '/whisper/diarization';
    }

    public static function marker(): string
    {
        return Paths::varDir() . '/whisper/ready-diarization';
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
}
