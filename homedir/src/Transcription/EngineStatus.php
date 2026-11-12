<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Throwable;
use Whisper\Config;
use Whisper\Paths;

/**
 * Motorens status til kø-kortet (F007, AK7), læst fra det, motoren selv efterlader: markøren var/whisper/ready-<model>
 * (whisper-setup.sh), logs/transcribe.log (skrives kun, når der er arbejde) og om et opkald står i "transcriberer".
 * Siden tjekker ikke venv'ets python: det er et symlink ud af hjemmemappen, som web-PHP ikke nødvendigvis kan se,
 * selv om cron kører. Kan intet læses, er status ukendt — aldrig "ikke sat op" på et gæt.
 */
final class EngineStatus
{
    public const BUSY = 'busy';
    public const READY = 'ready';
    public const STALLED = 'stalled';
    public const SETUP = 'setup';
    public const MISSING = 'missing';
    public const UNKNOWN = 'unknown';

    /** Aktivitet inden for så mange sekunder tæller som fremdrift (samme grænse som FRESH på listen) */
    public const ACTIVE = 30 * 60;
    /** Uden markør, men med så frisk aktivitet: whisper-setup.sh kører (den fjerner markøren midlertidigt) */
    public const SETUP_FRESH = 10 * 60;

    /**
     * @param int $queue opkald i kø (Calls::status waiting)
     * @return array{state: string, since: ?int} since = seneste aktivitet (unix-tid) eller null
     */
    public static function read(int $queue, bool $transcribing, int $now, ?string $logFile = null): array
    {
        try {
            $model = trim(Config::string('whisper', 'model', ProcessEngine::DEFAULT_MODEL));
            $model = $model === '' ? ProcessEngine::DEFAULT_MODEL : $model;
            if (!in_array($model, ProcessEngine::MODELS, true)) {
                return ['state' => self::MISSING, 'since' => null];   // motoren kan ikke starte med en ukendt model
            }
            $var = Paths::varDir();
            $log = $logFile ?? Config::baseDir() . '/logs/transcribe.log';
            $mtime = is_file($log) ? @filemtime($log) : false;
            $since = $mtime === false ? null : $mtime;
            $age = $since === null ? PHP_INT_MAX : max(0, $now - $since);
            if (!is_dir($var) || !is_readable($var)) {
                return ['state' => self::UNKNOWN, 'since' => $since];
            }
            if (!is_file($var . '/whisper/ready-' . $model)) {
                return ['state' => $transcribing || $age < self::SETUP_FRESH ? self::SETUP : self::MISSING, 'since' => $since];
            }
            // $transcribing er kun sand inden for motorens tidsgrænse (Calls::transcribingNow): et langt opkald skriver ingen
            // loglinjer undervejs (QA R-5), mens et efterladt af en dræbt proces er udløbet (QA F-8)
            if ($transcribing) {
                return ['state' => self::BUSY, 'since' => $since];
            }
            if ($queue === 0) {
                return ['state' => self::READY, 'since' => $since];
            }
            return ['state' => $age < self::ACTIVE ? self::BUSY : self::STALLED, 'since' => $since];
        } catch (Throwable) {
            return ['state' => self::UNKNOWN, 'since' => null];
        }
    }
}
