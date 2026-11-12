<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Throwable;
use Whisper\Calls;
use Whisper\DB;

/**
 * Én kø, én motor ad gangen (cron/transcribe.php hvert minut, under låsen var/locks/transcribe.lock).
 *
 * Status: ventende → transcriberer → faerdig | fejlet (kort fejltekst, højst 3 forsøg).
 * Claim er én atomisk UPDATE … WHERE status = ventende pr. opkald, så to samtidige kørsler aldrig tager samme opkald —
 * også hvis låsen af en eller anden grund ikke holdt. Et opkald, der har stået i "transcriberer" i over 2 timer, sættes
 * kun tilbage, når kalderen holder låsen: så kører der beviseligt ingen motor, og et legitimt langt job røres ikke.
 * Er motoren ikke klar (setup ikke kørt færdig for modellen), tages intet opkald, så intet forsøg bruges (QA F-3).
 * Efter et fejlet forsøg venter opkaldet RETRY_DELAY (15 min), før det prøves igen.
 *
 * Batch (H006): runBatch() claimer op til [whisper] batch opkald og kalder motoren én gang, så modellen kun indlæses
 * én gang. Hvert opkald gemmes, så snart motoren har svaret for det. Stopper motoren undervejs (tidsgrænse eller
 * fejl), tæller kun det opkald, den var i gang med, et forsøg; resten sættes tilbage til ventende.
 * Kun korte opkald batches: et langt (over 10 min lyd, eller ukendt længde) kører alene med den gamle grænse
 * max(2 t, 4 × varighed), for med en målt RTF over 1 kan det ikke nå at blive færdigt inden for batchens loft.
 */
final class Transcriber
{
    public const STALE_SECONDS = 7200;
    public const MAX_ATTEMPTS = 3;
    public const RETRY_DELAY = 900;
    /** Tidsgrænsen for en hel batch kan aldrig overstige kørslens 50-minutters-ramme */
    public const BATCH_LIMIT = 3000;
    /** Opkald over så mange sekunders lyd batches ikke */
    public const LONG_AUDIO = 600;
    /** Et langt opkald startes kun, hvis der er mere end så meget tilbage af kørslens ramme */
    public const LONG_MIN_REMAINING = 600;
    /** Batchens grænse er mindst nok til ét kort opkald: max(5 min, 4 × dets varighed) */
    public const SHORT_MIN_LIMIT = 300;

    /** @var array<int, true> opkald i den igangværende batch, der har fået resultat, fejl eller er sat tilbage */
    private array $settled = [];

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
        return $this->claim(1)[0] ?? null;
    }

    /**
     * Op til $max opkald, hvert med sin egen atomiske UPDATE (status → transcriberer, ét forsøg brugt).
     * Med $maxAudio kun opkald med kendt lydlængde på højst så mange sekunder (de korte, der må batches).
     *
     * @return list<array{id: int, audio_path: string, duration: int}>
     */
    public function claim(int $max, ?int $maxAudio = null): array
    {
        $max = max(1, min(WhisperSettings::MAX_BATCH, $max));
        $cond = "audio_status = 'hentet' AND transcript_attempts < ? "
            . "AND (transcript_status = 'ventende' OR (transcript_status = 'fejlet' "
            . 'AND (transcript_retry_at IS NULL OR transcript_retry_at <= ?)))';
        $now = $this->now();
        $params = [self::MAX_ATTEMPTS, $now];
        if ($maxAudio !== null) {
            $cond .= ' AND duration_sec > 0 AND duration_sec <= ?';
            $params[] = $maxAudio;
        }
        // Lidt flere kandidater end nødvendigt: en samtidig kørsel kan have taget nogle af dem
        $ids = DB::all('SELECT id FROM calls WHERE ' . $cond . ' ORDER BY (transcript_status = \'fejlet\'), started_at ASC, id ASC LIMIT '
            . ($max + 4), $params);
        $jobs = [];
        foreach ($ids as $row) {
            $id = (int) $row['id'];
            $n = DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = ?, "
                . 'transcript_attempts = transcript_attempts + 1, updated_at = ? WHERE id = ? AND ' . $cond,
                array_merge([$now, $now, $id], $params));
            if ($n === 1) {
                $r = DB::all('SELECT audio_path, duration_sec FROM calls WHERE id = ?', [$id])[0] ?? [];
                $jobs[] = ['id' => $id, 'audio_path' => is_string($r['audio_path'] ?? null) ? $r['audio_path'] : '',
                    'duration' => (int) ($r['duration_sec'] ?? 0)];
                if (count($jobs) >= $max) {
                    break;
                }
            }
        }
        return $jobs;
    }

    /**
     * Langt = over LONG_AUDIO eller ukendt længde: kører alene med den gamle grænse. (4 × LONG_AUDIO er under
     * BATCH_LIMIT, så et kort opkald kan altid nå sin egen grænse inden for batchens loft.)
     */
    public static function isLong(int $durationSec): bool
    {
        return $durationSec <= 0 || $durationSec > self::LONG_AUDIO;
    }

    /**
     * Batchens tidsgrænse: summen af max(2 t, 4 × varighed), højst BATCH_LIMIT og højst kørslens resttid, men mindst
     * nok til det første opkald (max(SHORT_MIN_LIMIT, 4 × dets varighed)).
     *
     * @param list<int> $durations i køens rækkefølge
     */
    public static function batchLimit(array $durations, int $remaining): int
    {
        $sum = 0;
        foreach ($durations as $d) {
            $sum += ProcessEngine::timeoutFor($d);
        }
        $floor = min(self::BATCH_LIMIT, max(self::SHORT_MIN_LIMIT, 4 * ($durations[0] ?? 0)));
        return max($floor, min(self::BATCH_LIMIT, $sum, $remaining));
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
        $this->runJob($job);
        return $job['id'];
    }

    /**
     * Ét claimet opkald alene, med motorens egen grænse max(2 t, 4 × varighed).
     *
     * @param array{id: int, audio_path: string, duration: int} $job
     */
    private function runJob(array $job): void
    {
        $took = 0;
        try {
            $file = Calls::audioFile($job['audio_path']);
            if ($file === null) {
                throw new EngineError('Lydfilen mangler på serveren');
            }
            $t0 = ($this->clock)();
            $raw = $this->engine->transcribe($file, $job['duration']);
            $took = max(0, ($this->clock)() - $t0);
        } catch (Throwable $e) {
            $raw = $e instanceof EngineError ? $e : new EngineError('Intern fejl ved transcription');
        }
        $this->finish($job, $raw, $took);
    }

    /**
     * Op til [whisper] batch korte opkald i ét motorkald; et langt opkald forrest i køen kører alene. Med batch = 1,
     * eller en motor uden batch, som runOnce(). Returnerer antal opkald taget (0 = køen er tom, motoren er ikke klar,
     * eller et langt opkald venter på næste kørsel).
     *
     * @param int $remaining sekunder tilbage af kørslens ramme (cron/transcribe.php)
     */
    public function runBatch(int $remaining = self::BATCH_LIMIT): int
    {
        $engine = $this->engine;
        if (!$engine instanceof BatchEngine || $engine->batchSize() <= 1) {
            return $this->runOnce() === null ? 0 : 1;
        }
        if (!$engine->available()) {
            return 0;
        }
        $this->settled = [];
        $jobs = [];
        try {
            $jobs = $this->claim(1);
            if ($jobs === []) {
                return 0;
            }
            if (self::isLong($jobs[0]['duration'])) {
                if ($remaining <= self::LONG_MIN_REMAINING) {
                    // Ikke tid nok i denne kørsel: tilbage uden brugt forsøg, så det starter forrest i den næste
                    $this->release($jobs[0]['id']);
                    return 0;
                }
                $this->runJob($jobs[0]);
                $this->settled[$jobs[0]['id']] = true;
                return 1;
            }
            $jobs = array_merge($jobs, $this->claim($engine->batchSize() - 1, self::LONG_AUDIO));
            $this->batch($engine, $jobs, $remaining);
        } catch (Throwable $e) {
            try {
                ($this->log)('transcribe: batch afbrudt: Intern fejl ved transcription');
            } catch (Throwable) {
            }
        } finally {
            // Intet claimet opkald må blive stående i "transcriberer", til stale-reglen rydder det efter 2 timer
            foreach ($jobs as $job) {
                if (!isset($this->settled[$job['id']])) {
                    try {
                        $this->release($job['id']);
                    } catch (Throwable) {
                    }
                }
            }
        }
        return count($jobs);
    }

    /** @param list<array{id: int, audio_path: string, duration: int}> $jobs korte opkald, allerede claimet */
    private function batch(BatchEngine $engine, array $jobs, int $remaining): void
    {
        $t0 = ($this->clock)();
        /** @var array<string, array{id: int, audio_path: string, duration: int}> $byFile i claim-rækkefølge */
        $byFile = [];
        foreach ($jobs as $job) {
            $file = Calls::audioFile($job['audio_path']);
            if ($file === null || isset($byFile[$file])) {
                $this->finish($job, new EngineError('Lydfilen mangler på serveren'), 0);
                continue;
            }
            $byFile[$file] = $job;
        }
        if ($byFile === []) {
            return;
        }
        $limit = self::batchLimit(array_values(array_column($byFile, 'duration')), $remaining);

        $done = [];
        $load = null;
        $error = null;
        try {
            $load = $engine->transcribeBatch(array_keys($byFile), $limit,
                function (string $file, array|EngineError $raw, float $elapsed) use (&$done, $byFile): void {
                    if (!isset($byFile[$file]) || isset($done[$file])) {
                        return;
                    }
                    $done[$file] = true;
                    $this->finish($byFile[$file], $raw, (int) round($elapsed));
                });
        } catch (Throwable $e) {
            $error = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved transcription';
        }
        // Motoren tager filerne i rækkefølge: den første uden svar var den, den var i gang med (ét forsøg brugt);
        // de næste blev aldrig startet og får deres forsøg tilbage
        $rest = array_values(array_diff_key($byFile, $done));
        if ($rest !== []) {
            $this->finish(array_shift($rest), new EngineError($error ?? 'motoren gav et ugyldigt svar'), 0);
            foreach ($rest as $job) {
                $this->release($job['id']);
            }
        }
        ($this->log)('transcribe: batch: ' . count($byFile) . ' opkald, model indlæst på '
            . ($load === null ? '-' : self::clock((int) round($load))) . ', i alt ' . self::clock(max(0, ($this->clock)() - $t0)));
    }

    /**
     * Gemmer motorens svar for ét opkald (faerdig), eller markerer det fejlet med backoff.
     *
     * @param array{id: int, audio_path: string, duration: int} $job
     * @param array<mixed>|EngineError $raw
     */
    private function finish(array $job, array|EngineError $raw, int $took): void
    {
        $id = $job['id'];
        try {
            if ($raw instanceof EngineError) {
                throw $raw;
            }
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
        } catch (Throwable $e) {
            $msg = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved transcription';
            DB::ensureAlive();
            DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = ?, transcript_retry_at = ?, claimed_at = NULL, "
                . 'updated_at = ? WHERE id = ?',
                [mb_substr($msg, 0, 250), gmdate('Y-m-d H:i:s', ($this->clock)() + self::RETRY_DELAY), $this->now(), $id]);
            $this->settled[$id] = true;
            ($this->log)('transcribe: opkald ' . $id . ': fejlet: ' . $msg);
            return;
        }
        // Gemt: loglinjen står uden for try, så en fejl i loggen aldrig gør et gemt opkald til fejlet
        $this->settled[$id] = true;
        $audio = $job['duration'] > 0 ? $job['duration']
            : (is_array($raw) && is_numeric($raw['duration'] ?? null) ? (int) round((float) $raw['duration']) : 0);
        ($this->log)('transcribe: opkald ' . $id . ': ' . self::doneLine($audio, $took, $this->engine->label()));
    }

    /** Tilbage i køen uden at bruge et forsøg (motoren nåede aldrig til opkaldet). */
    private function release(int $id): void
    {
        DB::ensureAlive();
        DB::exec("UPDATE calls SET transcript_status = 'ventende', claimed_at = NULL, "
            . 'transcript_attempts = CASE WHEN transcript_attempts > 0 THEN transcript_attempts - 1 ELSE 0 END, updated_at = ? '
            . "WHERE id = ? AND transcript_status = 'transcriberer'", [$this->now(), $id]);
        $this->settled[$id] = true;
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
