<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Throwable;
use Whisper\Calls;
use Whisper\DB;
use Whisper\Paths;

/**
 * Én kø, op til [whisper] workers motorer samtidig (cron/transcribe.php hvert minut, hver under sit eget slot, se Slots).
 *
 * Status: ventende → transcriberer → faerdig | fejlet (kort fejltekst, højst 3 forsøg).
 * Claim er én atomisk UPDATE … WHERE status = ventende pr. opkald, så to samtidige kørsler aldrig tager samme opkald.
 * De claimede id'er meldes til $holding (transcribe.php skriver dem i slottets låsefil). Et opkald, der har stået i
 * "transcriberer" i over 2 timer, sættes tilbage, medmindre en anden levende kørsel har det (resetStale($spare)): et
 * legitimt langt job røres ikke.
 * Er motoren ikke klar (setup ikke kørt færdig for modellen), tages intet opkald, så intet forsøg bruges (QA F-3).
 * Efter et fejlet forsøg venter opkaldet RETRY_DELAY (15 min), før det prøves igen.
 *
 * Batch (H006): runBatch() claimer op til [whisper] batch opkald og kalder motoren én gang, så modellen kun indlæses
 * én gang. Hvert opkald gemmes, så snart motoren har svaret for det. Stopper motoren undervejs (tidsgrænse eller
 * fejl), tæller kun det opkald, den var i gang med, et forsøg; resten sættes tilbage til ventende.
 * En batch claimer kun opkald, så Σ varighed × [whisper] rtf_estimate holder sig inden for min(BATCH_LIMIT, resttid)
 * (H008). Stoppes motoren ved tidsgrænsen, før det igangværende opkald har haft sit eget budget (shortBudget), var det
 * kørslens tid, der slap op: så sættes alle uafsluttede opkald tilbage uden brugt forsøg.
 * Kun korte opkald batches: et langt (over 10 min lyd, eller ukendt længde) kører alene med den gamle grænse
 * max(2 t, 4 × varighed), for med en målt RTF over 1 kan det ikke nå at blive færdigt inden for batchens loft.
 * Køens rækkefølge, og hvilke opkald der kun tages ved et manuelt valg, står i QueueRules.
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
    /** @var list<int> opkald, denne kørsel har claimet og endnu ikke afgjort */
    private array $held = [];
    private QueueRules $rules;

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     * @param string|null $errorFile hvor detaljen om en afbrudt batch skrives (0600); null = ingen detalje
     * @param (Closure(list<int>): void)|null $holding får de claimede id'er, hver gang de ændres
     * @param float $rtf [whisper] rtf_estimate: forventede sekunder motortid pr. sekund lyd, når en batch claimes
     * @param QueueRules|null $rules køens regler (H012); null = ingen regler, kun rækkefølgen (transcribe.php giver altid
     *                               reglerne fra config)
     */
    public function __construct(private readonly Engine $engine, ?Closure $clock = null, ?Closure $log = null,
        private readonly ?string $errorFile = null, private readonly ?Closure $holding = null,
        private readonly float $rtf = WhisperSettings::DEFAULT_RTF, ?QueueRules $rules = null)
    {
        $this->rules = $rules ?? QueueRules::off();
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
    }

    /**
     * Hængende opkald (over 2 t i "transcriberer") tilbage, undtagen $spare: dem, en anden levende kørsel har claimet
     * (Slots::heldByOthers). Kaldes før denne kørsel selv claimer noget. Returnerer antal sat tilbage eller opgivet.
     *
     * @param list<int> $spare
     */
    public function resetStale(array $spare = []): int
    {
        $limit = gmdate('Y-m-d H:i:s', ($this->clock)() - self::STALE_SECONDS);
        $not = $spare === [] ? '' : ' AND id NOT IN (' . implode(',', array_fill(0, count($spare), '?')) . ')';
        $n = DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = 'Afbrudt for mange gange', claimed_at = NULL "
            . "WHERE transcript_status = 'transcriberer' AND claimed_at < ? AND transcript_attempts >= ?" . $not,
            array_merge([$limit, self::MAX_ATTEMPTS], $spare));
        $n += DB::exec("UPDATE calls SET transcript_status = 'ventende', claimed_at = NULL "
            . "WHERE transcript_status = 'transcriberer' AND claimed_at < ?" . $not, array_merge([$limit], $spare));
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
     * Med $budget stopper claim ved det første opkald i køens rækkefølge, hvis varighed × rtf ikke kan være i det,
     * der er tilbage af budgettet (det springes ikke over til fordel for senere opkald).
     *
     * @param float|null $budget sekunder motortid til rådighed for de opkald, der claimes her
     * @return list<array{id: int, audio_path: string, duration: int}>
     */
    public function claim(int $max, ?int $maxAudio = null, ?float $budget = null): array
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
        // Reglerne står også i den atomiske UPDATE nedenfor, så et opkald, der er blevet for gammelt imellem, ikke tages
        [$rule, $ruleParams] = $this->rules->eligibleSql(($this->clock)());
        $cond .= ' AND ' . $rule;
        $params = array_merge($params, $ruleParams);
        // Lidt flere kandidater end nødvendigt: en samtidig kørsel kan have taget nogle af dem. Manuelle valg kommer
        // efter de automatiske (F004, security S1)
        $ids = DB::all('SELECT id, duration_sec FROM calls WHERE ' . $cond . ' ORDER BY ' . $this->rules->orderSql()
            . ' LIMIT ' . ($max + 4), $params);
        $jobs = [];
        foreach ($ids as $row) {
            $id = (int) $row['id'];
            $cost = max(0, (int) $row['duration_sec']) * $this->rtf;
            if ($budget !== null && $cost > $budget) {
                break;
            }
            $n = DB::exec("UPDATE calls SET transcript_status = 'transcriberer', claimed_at = ?, "
                . 'transcript_attempts = transcript_attempts + 1, updated_at = ? WHERE id = ? AND ' . $cond,
                array_merge([$now, $now, $id], $params));
            if ($n === 1) {
                $r = DB::all('SELECT audio_path, duration_sec FROM calls WHERE id = ?', [$id])[0] ?? [];
                $jobs[] = ['id' => $id, 'audio_path' => is_string($r['audio_path'] ?? null) ? $r['audio_path'] : '',
                    'duration' => (int) ($r['duration_sec'] ?? 0)];
                if ($budget !== null) {
                    $budget -= $cost;
                }
                if (count($jobs) >= $max) {
                    break;
                }
            }
        }
        if ($jobs !== []) {
            // Meldt, før motoren startes: en anden kørsels stale-regel skal kunne se, at opkaldet er i gang
            $this->hold(array_merge($this->held, array_column($jobs, 'id')));
        }
        return $jobs;
    }

    /** @param list<int> $ids */
    private function hold(array $ids): void
    {
        $this->held = $ids;
        if ($this->holding === null) {
            return;
        }
        try {
            ($this->holding)($ids);
        } catch (Throwable) {
            // Må ikke afbryde claim(): de claimede opkald skal stadig køres eller frigives af runBatch
        }
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
        $floor = min(self::BATCH_LIMIT, self::shortBudget($durations[0] ?? 0));
        return max($floor, min(self::BATCH_LIMIT, $sum, $remaining));
    }

    /**
     * Et kort opkalds eget budget i en batch: max(5 min, 4 × varighed). Dets egen grænse max(2 t, 4 × varighed) kan
     * aldrig nås under batchens loft (3000 s), så en timeout, efter at opkaldet har kørt så længe, er opkaldets egen
     * skyld. Gulvet i batchLimit er det første opkalds budget, så en timeout på batchens første fil er det altid.
     */
    public static function shortBudget(int $durationSec): int
    {
        return max(self::SHORT_MIN_LIMIT, 4 * max(0, $durationSec));
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
        try {
            $this->runJob($job);
        } finally {
            $this->hold([]);
        }
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
     * et langt opkald venter på næste kørsel, eller en undtagelse afbrød batchen: så stopper kørslen til næste minut).
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
        $aborted = false;
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
            // Kun det, der kan nå at blive færdigt i kørslens resttid (det første opkald er allerede taget)
            $budget = min(self::BATCH_LIMIT, $remaining) - $jobs[0]['duration'] * $this->rtf;
            $jobs = array_merge($jobs, $this->claim($engine->batchSize() - 1, self::LONG_AUDIO, $budget));
            $this->batch($engine, $jobs, $remaining);
        } catch (Throwable $e) {
            // En undtagelse uden for motoren kan være deterministisk: kørslen stopper (0), så den højst gentages én gang
            // pr. cron-minut, og klasse og linje står i slottets fejlfil (beskeden kan indeholde data og vises ikke)
            $aborted = true;
            $this->recordError($e);
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
            $this->hold([]);
        }
        return $aborted ? 0 : count($jobs);
    }

    private function recordError(Throwable $e): void
    {
        if ($this->errorFile === null) {
            return;
        }
        try {
            Paths::writePrivate($this->errorFile, gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()) . ' batch afbrudt: '
                . get_class($e) . ' i ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
        } catch (Throwable) {
        }
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
        $timedOut = false;
        // Hvornår det igangværende opkald startede: batchens start (inkl. indlæsning) for det første, ellers det
        // seneste svar
        $mark = $t0;
        try {
            $load = $engine->transcribeBatch(array_keys($byFile), $limit,
                function (string $file, array|EngineError $raw, float $elapsed) use (&$done, &$mark, $byFile): void {
                    if (!isset($byFile[$file]) || isset($done[$file])) {
                        return;
                    }
                    $done[$file] = true;
                    $this->finish($byFile[$file], $raw, (int) round($elapsed));
                    $mark = ($this->clock)();
                });
        } catch (Throwable $e) {
            $error = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved transcription';
            $timedOut = $e instanceof EngineError && $e->getCode() === EngineError::TIMEOUT;
        }
        // Motoren tager filerne i rækkefølge: den første uden svar var den, den var i gang med; de næste blev aldrig
        // startet og får deres forsøg tilbage
        $rest = array_values(array_diff_key($byFile, $done));
        if ($rest !== []) {
            if ($timedOut && ($this->clock)() - $mark < self::shortBudget($rest[0]['duration'])) {
                // Kørslens tid slap op, før det igangværende opkald fik sit eget budget: ingen af dem bruger et forsøg
                foreach ($rest as $job) {
                    $this->release($job['id']);
                    ($this->log)('transcribe: opkald ' . $job['id'] . ': sat tilbage (kørslens tid opbrugt)');
                }
            } else {
                // Opkaldet selv (motorfejl, eller tidsgrænsen efter sit eget budget): ét forsøg brugt
                $this->finish(array_shift($rest), new EngineError($error ?? 'motoren gav et ugyldigt svar'), 0);
                foreach ($rest as $job) {
                    $this->release($job['id']);
                }
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
                    // Et nyt transcript har ingen talere endnu: diarization (H011) tager det igen i ledig tid
                    . '`language` = VALUES(`language`), created_at = VALUES(created_at), diar_status = NULL, diar_attempts = 0, diarized_at = NULL',
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
