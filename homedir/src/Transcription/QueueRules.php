<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Whisper\Config;
use Whisper\DB;

/**
 * Transcription-køens regler fra config.ini [whisper] (alle valgfri):
 *   min_seconds     = 0–120  (standard 15: opkald med kendt varighed under så mange sekunder transcriberes ikke automatisk)
 *   max_age_days    = 0–3650 (standard 30: opkald, der startede for over så mange dage siden, transcriberes ikke automatisk)
 *   source_priority = equal|zendesk|adversus (standard equal: kilderne sorteres sammen, nyeste først)
 * 0 slår en regel fra. Ugyldige værdier giver standarden og én advarsel pr. nøgle, som WhisperSettings.
 *
 * Reglerne gemmes ikke som status: de regnes ud, når køen bruges (claim, tæller, badge), så en config-ændring virker
 * med det samme, og et opkald bliver "for gammelt", mens det venter. Et manuelt valgt opkald (manual_at, eller et
 * Adversus-opkald uden succes, som kun kommer i kø ved et valg) er undtaget.
 */
final class QueueRules
{
    public const DEFAULT_MIN_SECONDS = 15;
    public const MAX_MIN_SECONDS = 120;
    public const DEFAULT_MAX_AGE_DAYS = 30;
    public const MAX_MAX_AGE_DAYS = 3650;
    public const PRIORITY_EQUAL = 'equal';
    public const PRIORITY_ZENDESK = 'zendesk';
    public const PRIORITY_ADVERSUS = 'adversus';
    public const SKIP_SHORT = 'kort';
    public const SKIP_OLD = 'gammel';

    /** Kun til tests (som Flags::override): siderne læser ellers reglerne fra config.ini */
    private static ?self $override = null;

    public function __construct(public readonly int $minSeconds = self::DEFAULT_MIN_SECONDS,
        public readonly int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS, public readonly string $priority = self::PRIORITY_EQUAL)
    {
        if ($minSeconds < 0 || $minSeconds > self::MAX_MIN_SECONDS || $maxAgeDays < 0 || $maxAgeDays > self::MAX_MAX_AGE_DAYS
            || !in_array($priority, [self::PRIORITY_EQUAL, self::PRIORITY_ZENDESK, self::PRIORITY_ADVERSUS], true)) {
            throw new EngineError('[whisper] min_seconds skal være 0–120, max_age_days 0–3650 og source_priority equal, zendesk eller adversus');
        }
    }

    /** Ingen regler: alt ventende kommer i køen, kilderne sorteres sammen (rollback-tilstanden). */
    public static function off(): self
    {
        return new self(0, 0, self::PRIORITY_EQUAL);
    }

    public static function override(?self $rules): void
    {
        self::$override = $rules;
    }

    /** Reglerne fra config.ini, uden advarsler (siderne); transcribe.php bruger fromSection med advarsler. */
    public static function fromConfig(): self
    {
        return self::$override ?? self::fromSection(Config::section('whisper'));
    }

    /**
     * @param array<string, mixed> $section config.ini [whisper]
     * @param (Closure(string): void)|null $warn
     */
    public static function fromSection(array $section, ?Closure $warn = null): self
    {
        $warn ??= static function (string $line): void {
        };
        $min = self::intOr($section, 'min_seconds', self::MAX_MIN_SECONDS, self::DEFAULT_MIN_SECONDS, $warn);
        $age = self::intOr($section, 'max_age_days', self::MAX_MAX_AGE_DAYS, self::DEFAULT_MAX_AGE_DAYS, $warn);
        $priority = self::PRIORITY_EQUAL;
        $raw = $section['source_priority'] ?? null;
        if (is_scalar($raw) && trim((string) $raw) !== '') {
            $v = strtolower(trim((string) $raw));
            if (in_array($v, [self::PRIORITY_EQUAL, self::PRIORITY_ZENDESK, self::PRIORITY_ADVERSUS], true)) {
                $priority = $v;
            } else {
                $warn('transcribe: advarsel: [whisper] source_priority skal være equal, zendesk eller adversus — bruger equal');
            }
        }
        return new self($min, $age, $priority);
    }

    /** Manuelt valgt: knappen (manual_at) eller et Adversus-opkald uden succes (det kommer kun i kø ved et valg, F004). */
    public static function manualSql(string $alias = ''): string
    {
        $a = $alias === '' ? '' : $alias . '.';
        return '(' . $a . 'manual_at IS NOT NULL OR (' . $a . "`source` = 'adversus' AND " . $a . 'is_success = 0))';
    }

    /**
     * Betingelsen for, at et ventende/fejlet opkald må tages automatisk: manuelt valgt, eller inden for begge regler.
     * Ukendt varighed (0) er aldrig "for kort".
     *
     * @return array{0: string, 1: list<int|string>} [SQL, parametre]
     */
    public function eligibleSql(int $now, string $alias = ''): array
    {
        $a = $alias === '' ? '' : $alias . '.';
        $conds = [];
        $params = [];
        if ($this->minSeconds > 0) {
            $conds[] = '(' . $a . 'duration_sec = 0 OR ' . $a . 'duration_sec >= ?)';
            $params[] = $this->minSeconds;
        }
        if ($this->maxAgeDays > 0) {
            $conds[] = $a . 'started_at >= ?';
            $params[] = $this->cutoff($now);
        }
        if ($conds === []) {
            return ['1 = 1', []];
        }
        return ['(' . self::manualSql($alias) . ' OR (' . implode(' AND ', $conds) . '))', $params];
    }

    /** Køens rækkefølge: fejlet sidst, kilden efter source_priority, manuelle valg efter de automatiske, nyeste først. */
    public function orderSql(string $alias = ''): string
    {
        $a = $alias === '' ? '' : $alias . '.';
        $order = ['(' . $a . "transcript_status = 'fejlet')"];
        if ($this->priority !== self::PRIORITY_EQUAL) {
            $order[] = '(' . $a . "`source` <> '" . ($this->priority === self::PRIORITY_ZENDESK ? 'zendesk' : 'adversus') . "')";
        }
        $order[] = self::manualSql($alias);
        $order[] = $a . 'started_at DESC';
        $order[] = $a . 'id DESC';
        return implode(', ', $order);
    }

    /**
     * Hvorfor et opkald ikke tages automatisk: kort | gammel | null. Kun ventende opkald og fejlede med forsøg tilbage;
     * "for kort" vinder, når begge gælder.
     *
     * @param array<string, mixed> $call
     */
    public function skipReason(array $call, int $now): ?string
    {
        $status = (string) ($call['transcript_status'] ?? '');
        if ($status !== 'ventende' && !($status === 'fejlet' && (int) ($call['transcript_attempts'] ?? 0) < Transcriber::MAX_ATTEMPTS)) {
            return null;
        }
        if (($call['manual_at'] ?? null) !== null
            || (($call['source'] ?? '') === 'adversus' && (int) ($call['is_success'] ?? 0) === 0)) {
            return null;
        }
        $d = (int) ($call['duration_sec'] ?? 0);
        if ($this->minSeconds > 0 && $d > 0 && $d < $this->minSeconds) {
            return self::SKIP_SHORT;
        }
        if ($this->maxAgeDays > 0 && (string) ($call['started_at'] ?? '') < $this->cutoff($now)) {
            return self::SKIP_OLD;
        }
        return null;
    }

    /**
     * Opkald med hentet lyd, som reglerne lige nu springer over (til loglinjen), talt som skipReason: "for kort" vinder.
     *
     * @return array{short: int, old: int}
     */
    public function skippedCounts(int $now): array
    {
        $short = 'duration_sec > 0 AND duration_sec < ?';
        $row = DB::all('SELECT COALESCE(SUM(CASE WHEN ' . $short . ' THEN 1 ELSE 0 END), 0) AS s, '
            . 'COALESCE(SUM(CASE WHEN NOT (' . $short . ') AND started_at < ? THEN 1 ELSE 0 END), 0) AS o '
            . "FROM calls WHERE audio_status = 'hentet' AND (transcript_status = 'ventende' "
            . "OR (transcript_status = 'fejlet' AND transcript_attempts < ?)) AND NOT " . self::manualSql(),
            [$this->minSeconds, $this->minSeconds, $this->maxAgeDays > 0 ? $this->cutoff($now) : '1000-01-01 00:00:00',
                Transcriber::MAX_ATTEMPTS])[0] ?? [];
        return ['short' => (int) ($row['s'] ?? 0), 'old' => (int) ($row['o'] ?? 0)];
    }

    /** "transcribe: 120 opkald sprunget over (for korte 80, ældre end 30 dage 40)", eller null når intet springes over. */
    public function skippedLine(int $now): ?string
    {
        $c = $this->skippedCounts($now);
        $total = $c['short'] + $c['old'];
        if ($total === 0) {
            return null;
        }
        return 'transcribe: ' . $total . ' opkald sprunget over (for korte ' . $c['short'] . ', ældre end ' . $this->maxAgeDays
            . ' dage ' . $c['old'] . ')';
    }

    /** Ældste started_at (UTC, samme format som kolonnen), der stadig tages automatisk. */
    public function cutoff(int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now - $this->maxAgeDays * 86400);
    }

    /** "For kort" / "Ikke automatisk" — badge-teksten for en skipReason. */
    public static function label(string $reason): string
    {
        return $reason === self::SKIP_SHORT ? 'For kort' : 'Ikke automatisk';
    }

    /**
     * @param array<string, mixed> $section
     * @param Closure(string): void $warn
     */
    private static function intOr(array $section, string $key, int $max, int $default, Closure $warn): int
    {
        $raw = $section[$key] ?? null;
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return $default;
        }
        $raw = trim((string) $raw);
        if (preg_match('/^[0-9]{1,4}$/', $raw) === 1 && (int) $raw <= $max) {
            return (int) $raw;
        }
        $warn('transcribe: advarsel: [whisper] ' . $key . ' skal være 0–' . $max . ' — bruger ' . $default);
        return $default;
    }
}
