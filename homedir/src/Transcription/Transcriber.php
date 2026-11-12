<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Throwable;
use Whisper\Calls;
use Whisper\DB;

/**
 * Én kø, ét opkald ad gangen (cron/transcribe.php hvert minut, under låsen var/locks/transcribe.lock).
 *
 * Status: ventende → transcriberer → faerdig | fejlet (kort fejltekst, højst 3 forsøg).
 * Claim er én atomisk UPDATE … WHERE status = ventende, så to samtidige kørsler aldrig tager samme opkald — også hvis
 * låsen af en eller anden grund ikke holdt. Et opkald, der har stået i "transcriberer" i over 2 timer, sættes kun
 * tilbage, når kalderen holder låsen: så kører der beviseligt ingen motor, og et legitimt langt job røres ikke.
 * Er motoren ikke klar (setup ikke kørt færdig for modellen), tages intet opkald, så intet forsøg bruges (QA F-3).
 * Efter et fejlet forsøg venter opkaldet RETRY_DELAY (15 min), før det prøves igen.
 */
final class Transcriber
{
    public const STALE_SECONDS = 7200;
    public const MAX_ATTEMPTS = 3;
    public const RETRY_DELAY = 900;

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     */
    public function __construct(private readonly Engine $engine, ?Closure $clock = null, ?Closure $log = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
    }

    /** Kun med låsen holdt (se klassens kommentar). Returnerer antal opkald sat tilbage eller opgivet. */
    public function resetStale(): int
    {
        $limit = gmdate('Y-m-d H:i:s', ($this->clock)() - self::STALE_SECONDS);
        $n = DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = 'Afbrudt for mange gange', claimed_at = NULL "
            . "WHERE transcript_status = 'transcriberer' AND claimed_at < ? AND transcript_attempts >= ?", [$limit, self::MAX_ATTEMPTS]);
        $n += DB::exec("UPDATE calls SET transcript_status = 'ventende', claimed_at = NULL "
            . "WHERE transcript_status = 'transcriberer' AND claimed_at < ?", [$limit]);
        return $n;
    }

    /** @return array{id: int, audio_path: string, duration: int}|null */
    public function claimNext(): ?array
    {
        $cond = "audio_status = 'hentet' AND transcript_attempts < ? "
            . "AND (transcript_status = 'ventende' OR (transcript_status = 'fejlet' "
            . 'AND (transcript_retry_at IS NULL OR transcript_retry_at <= ?)))';
        $now = $this->now();
        $ids = DB::all('SELECT id FROM calls WHERE ' . $cond . ' ORDER BY (transcript_status = \'fejlet\'), started_at ASC, id ASC LIMIT 5',
            [self::MAX_ATTEMPTS, $now]);
        foreach ($ids as $row) {
            $id = (int) $row['id'];
            $n = DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = ?, "
                . 'transcript_attempts = transcript_attempts + 1, updated_at = ? WHERE id = ? AND ' . $cond,
                [$now, $now, $id, self::MAX_ATTEMPTS, $now]);
            if ($n === 1) {
                $r = DB::all('SELECT audio_path, duration_sec FROM calls WHERE id = ?', [$id])[0] ?? [];
                return ['id' => $id, 'audio_path' => is_string($r['audio_path'] ?? null) ? $r['audio_path'] : '',
                    'duration' => (int) ($r['duration_sec'] ?? 0)];
            }
        }
        return null;
    }

    /** Ét opkald. Returnerer id'et, eller null hvis køen er tom. */
    public function runOnce(): ?int
    {
        if (!$this->engine->available()) {
            return null;   // motor ikke klar: intet opkald tages, intet forsøg bruges
        }
        $job = $this->claimNext();
        if ($job === null) {
            return null;
        }
        $id = $job['id'];
        try {
            $file = Calls::audioFile($job['audio_path']);
            if ($file === null) {
                throw new EngineError('Lydfilen mangler på serveren');
            }
            $t0 = ($this->clock)();
            $raw = $this->engine->transcribe($file, $job['duration']);
            $took = max(0, ($this->clock)() - $t0);
            $result = self::validate($raw);
            DB::ensureAlive();   // motoren kan have kørt i timer
            $pdo = DB::pdo();
            $pdo->beginTransaction();
            try {
                DB::exec('INSERT INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) VALUES (?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `text` = VALUES(`text`), segments_json = VALUES(segments_json), model = VALUES(model), '
                    . '`language` = VALUES(`language`), created_at = VALUES(created_at)',
                    [$id, $result['text'], (string) json_encode($result['segments'], JSON_UNESCAPED_UNICODE),
                        $result['model'], $result['language'], $this->now()]);
                DB::exec("UPDATE calls SET transcript_status = 'faerdig', transcript_error = NULL, transcript_retry_at = NULL, "
                    . 'claimed_at = NULL, updated_at = ? WHERE id = ?', [$this->now(), $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $audio = $job['duration'] > 0 ? $job['duration']
                : (is_numeric($raw['duration'] ?? null) ? (int) round((float) $raw['duration']) : 0);
            ($this->log)('transcribe: opkald ' . $id . ': ' . self::doneLine($audio, $took, $this->engine->label()));
        } catch (Throwable $e) {
            $msg = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved transcription';
            DB::ensureAlive();
            DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = ?, transcript_retry_at = ?, claimed_at = NULL, "
                . 'updated_at = ? WHERE id = ?',
                [mb_substr($msg, 0, 250), gmdate('Y-m-d H:i:s', ($this->clock)() + self::RETRY_DELAY), $this->now(), $id]);
            ($this->log)('transcribe: opkald ' . $id . ': fejlet: ' . $msg);
        }
        return $id;
    }

    /**
     * Motorens JSON: {"text": …, "segments": [{"start": s, "end": s, "text": …}], "model": …, "language": "da"}.
     *
     * @param array<mixed> $raw
     * @return array{text: string, segments: list<array{start: float, end: float, text: string}>, model: string, language: string}
     * @throws EngineError
     */
    public static function validate(array $raw): array
    {
        $segments = [];
        foreach (is_array($raw['segments'] ?? null) ? $raw['segments'] : [] as $s) {
            if (!is_array($s) || !is_numeric($s['start'] ?? null) || !is_numeric($s['end'] ?? null) || !is_string($s['text'] ?? null)) {
                throw new EngineError('motoren gav et ugyldigt svar');
            }
            $segments[] = ['start' => max(0.0, (float) $s['start']), 'end' => max(0.0, (float) $s['end']), 'text' => trim($s['text'])];
        }
        if (!is_array($raw['segments'] ?? null)) {
            throw new EngineError('motoren gav et ugyldigt svar');
        }
        $text = is_string($raw['text'] ?? null) ? trim($raw['text'])
            : trim(implode(' ', array_column($segments, 'text')));
        $model = is_string($raw['model'] ?? null) ? mb_substr($raw['model'], 0, 40) : '';
        $lang = is_string($raw['language'] ?? null) && preg_match('/^[a-z]{2}$/', $raw['language']) === 1 ? $raw['language'] : 'da';
        return ['text' => $text, 'segments' => $segments, 'model' => $model, 'language' => $lang];
    }

    /**
     * "færdig (lyd 4:12, tid 1:05, RTF 0,26, threads 5, beam 1, vad on)". RTF = tid / lyd; under 1 er hurtigere end
     * realtid. Ukendt lydlængde giver "RTF -".
     */
    public static function doneLine(int $audioSec, int $tookSec, string $label): string
    {
        $rtf = $audioSec > 0 ? str_replace('.', ',', sprintf('%.2f', $tookSec / $audioSec)) : '-';
        return 'færdig (lyd ' . self::clock($audioSec) . ', tid ' . self::clock($tookSec) . ', RTF ' . $rtf
            . ($label !== '' ? ', ' . $label : '') . ')';
    }

    /** m:ss, eller t:mm:ss fra en time */
    public static function clock(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
