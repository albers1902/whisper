<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Throwable;
use Whisper\Calls;
use Whisper\DB;
use Whisper\Paths;

/**
 * Hvem taler, som et separat trin i ledig tid (H011, cron/diarize.php). Transcription er uændret og går altid først:
 *
 * - Kører kun, når transcription-køen er tom (ingen opkald med hentet lyd i "ventende" eller "transcriberer").
 * - Tager færdige transcripts uden talere (transcripts.diar_status IS NULL), nyeste opkald først, BATCH ad gangen, og
 *   lægger "speaker"/"role" ind i segments_json (Diarization::apply).
 * - Mens motoren kører, spørges køen (og udrulningen) hvert ProcessDiarizer::STOP_CHECK sekund. Skal den stoppe, dræbes
 *   motoren straks; det igangværende opkald bruger intet forsøg og tages igen senere.
 * - Talerne gemmes kun, hvis transcriptet er uændret (samme created_at).
 *
 * Fejl skelnes efter, hvis skyld de er:
 * - Opkaldets egen (motoren svarede "error" for netop den fil, ugyldigt svar, eller exit 0 uden svar for filen): ét
 *   forsøg, tidligst igen efter RETRY_GAP (diarized_at = sidst forsøgt); efter MAX_ATTEMPTS opgives det ("fejlet").
 * - Motorens (start, exit ≠ 0 eller tidsgrænse, før den har svaret for nogen fil): intet forsøg, kørslen stopper, og
 *   diarization holder PAUSE (markørfil), så en ødelagt motor ikke brænder forsøg for hele historikken. Rammer en
 *   motorfejl igen det samme første opkald efter pausen, får netop det ét forsøg, så én fil, der får motoren til at dø,
 *   ikke kan stoppe alt andet for altid. Døde motoren efter at have svaret for andre filer, får det opkald, den var i
 *   gang med, ét forsøg.
 * - En fejl rører aldrig transcriptet: det bliver stående uden talere og vises som før.
 */
final class Diarizer
{
    public const BATCH = 10;
    public const MAX_ATTEMPTS = 3;
    /** Mindst så længe mellem to forsøg på samme opkald */
    public const RETRY_GAP = 1800;
    /** Pause efter en motorfejl */
    public const PAUSE = 1800;
    /** Længere opkald diarizeres ikke: hele lyden afkodes til hukommelsen (float32) */
    public const MAX_AUDIO = 3600;

    /** true, når seneste runOnce() blev stoppet (kø eller udrulning) */
    public bool $stopped = false;
    /** true, når seneste runOnce() endte i en motorfejl eller pause: kørslen skal stoppe */
    public bool $paused = false;

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;
    /** @var Closure(): bool */
    private Closure $halt;
    /** Opkald i den igangværende batch, der fik talere */
    private int $ok = 0;

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     * @param (Closure(): bool)|null $halt true = stop motoren nu (fx under en udrulning), ud over transcription-køen
     */
    public function __construct(private readonly DiarizationEngine $engine, private readonly DiarizationSettings $settings,
        ?Closure $clock = null, ?Closure $log = null, ?Closure $halt = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
        $this->halt = $halt ?? static fn(): bool => false;
    }

    /**
     * Er der opkald, som transcription skal have først? (Svaret ændrer sig mellem kald.)
     *
     * @phpstan-impure
     */
    public static function busy(): bool
    {
        return DB::all("SELECT 1 FROM calls WHERE audio_status = 'hentet' AND transcript_status IN ('ventende', 'transcriberer') LIMIT 1") !== [];
    }

    public static function pauseFile(): string
    {
        return Paths::dir('whisper') . '/diarize-pause';
    }

    /**
     * Færdige transcripts uden talere, nyeste opkald først; et opkald, der fejlede, tidligst RETRY_GAP efter forsøget.
     *
     * @return list<array{id: int, audio_path: string, duration: int, created_at: string, attempts: int, segments: string}>
     */
    public function next(int $max): array
    {
        $rows = DB::all("SELECT c.id, c.audio_path, c.duration_sec, t.created_at, t.diar_attempts, t.segments_json "
            . "FROM calls c JOIN transcripts t ON t.call_id = c.id "
            . "WHERE c.transcript_status = 'faerdig' AND t.diar_status IS NULL AND t.diar_attempts < ? "
            . 'AND (t.diar_attempts = 0 OR t.diarized_at IS NULL OR t.diarized_at <= ?) '
            . 'ORDER BY c.started_at DESC, c.id DESC LIMIT ' . max(1, min(self::BATCH, $max)),
            [self::MAX_ATTEMPTS, gmdate('Y-m-d H:i:s', ($this->clock)() - self::RETRY_GAP)]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'audio_path' => is_string($r['audio_path'] ?? null) ? $r['audio_path'] : '',
                'duration' => (int) ($r['duration_sec'] ?? 0), 'created_at' => (string) $r['created_at'],
                'attempts' => (int) $r['diar_attempts'], 'segments' => (string) $r['segments_json']];
        }
        return $out;
    }

    /**
     * Nulstiller bogføringen, så talerne beregnes (igen) ved næste kørsel: ét opkald, alle opgivne ("fejlet") eller
     * alle. Talerne i segments_json bliver stående, til de er beregnet igen. Returnerer antal nulstillede transcripts.
     */
    public static function reset(string $what): int
    {
        $sql = 'UPDATE transcripts SET diar_status = NULL, diar_attempts = 0, diarized_at = NULL';
        if ($what === 'alle') {
            return DB::exec($sql);
        }
        if ($what === 'fejlet') {
            return DB::exec($sql . " WHERE diar_status = 'fejlet'");
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/', $what) === 1) {
            return DB::exec($sql . ' WHERE call_id = ?', [(int) $what]);
        }
        throw new \InvalidArgumentException('--reset tager et opkalds-id, fejlet eller alle');
    }

    /**
     * Kørslen (cron/diarize.php): batches, til der ikke er mere at gøre, køen får et opkald, motoren fejler, eller
     * $frame sekunder er gået. Returnerer antal opkald med et udfald.
     */
    public function run(int $frame = 3000): int
    {
        $start = ($this->clock)();
        $total = 0;
        while (($used = ($this->clock)() - $start) < $frame && !($this->halt)()) {
            $n = $this->runOnce($frame - $used);
            $total += $n;
            if ($n === 0 || $this->stopped || $this->paused) {
                break;
            }
        }
        return $total;
    }

    /**
     * Én batch. Returnerer antal opkald, der fik et udfald (0 = intet at gøre, motoren er ikke klar, køen er ikke tom,
     * pause, eller motoren blev stoppet: så slutter kørslen, se $stopped og $paused).
     *
     * @param int $remaining sekunder tilbage af kørslens ramme
     */
    public function runOnce(int $remaining): int
    {
        $this->stopped = false;
        $this->paused = false;
        if (!$this->engine->available() || self::busy()) {
            return 0;
        }
        [$until, $lastFirst] = $this->pause();
        if ($until > ($this->clock)()) {
            $this->paused = true;
            return 0;
        }
        $n = 0;
        /** @var array<string, array{id: int, audio_path: string, duration: int, created_at: string, attempts: int, segments: string}> $byFile */
        $byFile = [];
        foreach ($this->next(self::BATCH) as $job) {
            $segments = json_decode($job['segments'], true);
            if (!is_array($segments) || !array_is_list($segments) || array_filter($segments, 'is_array') !== $segments) {
                $this->fail($job, 'transcriptet har ugyldige segmenter', true);
                $n++;
                continue;
            }
            if ($segments === []) {
                // Ingen tale i transcriptet: intet at lægge talere på, og motoren skal ikke køre
                $this->mark($job, 'faerdig', 0);
                ($this->log)('diarize: opkald ' . $job['id'] . ': ' . self::doneLine(0, false, $job['duration'], 0.0));
                $n++;
                continue;
            }
            if ($job['duration'] > self::MAX_AUDIO) {
                $this->fail($job, 'over ' . intdiv(self::MAX_AUDIO, 60) . ' min lyd — diarizeres ikke', true);
                $n++;
                continue;
            }
            $file = Calls::audioFile($job['audio_path']);
            if ($file === null) {
                // Uden lyd kan det aldrig lykkes: opgivet med det samme, ingen nye forsøg
                $this->fail($job, 'Lydfilen mangler på serveren', true);
                $n++;
                continue;
            }
            if (isset($byFile[$file])) {
                continue;   // samme lydfil som et andet opkald i batchen: tages i en senere batch
            }
            $byFile[$file] = $job;
        }
        if ($byFile === []) {
            return $n;
        }
        $done = [];
        $error = null;
        $stopTook = null;
        $this->ok = 0;
        try {
            $stopTook = $this->engine->diarize(array_keys($byFile), max(60, $remaining),
                function (string $file, array|EngineError $raw, float $sec) use (&$done, $byFile): void {
                    if (!isset($byFile[$file]) || isset($done[$file])) {
                        return;
                    }
                    $done[$file] = true;
                    $this->save($byFile[$file], $raw, $sec);
                }, fn(): bool => self::busy() || ($this->halt)());
        } catch (Throwable $e) {
            $error = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved diarization';
        }
        $n += count($done);
        if ($done !== [] && $lastFirst !== 0) {
            @unlink(self::pauseFile());   // motoren virker igen: en gammel motorfejl tæller ikke som "igen"
        }
        if ($stopTook !== null) {
            $this->stopped = true;
            ($this->log)('diarize: stoppet — ' . (self::busy() ? 'nye opkald i transcription-køen' : 'udrulning') . ' (motoren stoppet på '
                . str_replace('.', ',', sprintf('%.1f', $stopTook)) . ' s, køen tjekkes hvert ' . ProcessDiarizer::STOP_CHECK . '. s)');
            return 0;
        }
        $rest = array_values(array_diff_key($byFile, $done));
        if ($error !== null && $done === []) {
            // Motorens fejl, ikke opkaldenes: intet forsøg, kørslen stopper, pause
            $first = $rest[0];
            $repeat = $lastFirst === $first['id'];
            $this->setPause(($this->clock)() + self::PAUSE, $first['id']);
            $this->paused = true;
            ($this->log)('diarize: motoren fejlede (' . $error . ') — ' . ($repeat ? 'igen på opkald ' . $first['id'] . ', som får ét forsøg'
                : 'intet forsøg brugt') . '; pause i ' . intdiv(self::PAUSE, 60) . ' min (detaljen står i var/whisper/diarize-fejl.log)');
            if ($repeat) {
                $this->fail($first, 'motoren døde på opkaldet', false);
                return 1;
            }
            return 0;
        }
        if ($rest !== []) {
            // Døde efter andre svar, eller sluttede uden svar for filen: det igangværende opkald får ét forsøg
            $this->fail($rest[0], $error ?? 'motoren gav intet svar for lydfilen', false);
            $n++;
            if ($error !== null) {
                $this->paused = true;   // en motor, der dør, skal ikke startes igen i samme kørsel
            }
        }
        if ($this->ok === 0 && count($byFile) >= 2 && !$this->paused) {
            // Ingen af flere opkald lykkedes: sandsynligvis motoren (fx afkodningen), ikke opkaldene. Pause, så ikke hele
            // historikken bruger sine forsøg
            $this->setPause(($this->clock)() + self::PAUSE, 0);
            $this->paused = true;
            ($this->log)('diarize: intet af ' . count($byFile) . ' opkald lykkedes — pause i ' . intdiv(self::PAUSE, 60) . ' min');
        }
        return $n;
    }

    /**
     * @param array{id: int, audio_path: string, duration: int, created_at: string, attempts: int, segments: string} $job
     * @param list<mixed>|EngineError $raw
     */
    private function save(array $job, array|EngineError $raw, float $sec): void
    {
        try {
            if ($raw instanceof EngineError) {
                throw $raw;
            }
            $intervals = Diarization::intervals($raw);
            DB::ensureAlive();
            $rows = DB::all('SELECT segments_json FROM transcripts WHERE call_id = ? AND created_at = ?', [$job['id'], $job['created_at']]);
            if ($rows === []) {
                ($this->log)('diarize: opkald ' . $job['id'] . ': transcriptet er lavet igen imens — tages forfra senere');
                return;
            }
            $segments = json_decode((string) $rows[0]['segments_json'], true);
            if (!is_array($segments) || !array_is_list($segments)) {
                throw new EngineError('transcriptet har ugyldige segmenter');
            }
            $list = [];
            foreach ($segments as $s) {
                if (!is_array($s)) {
                    throw new EngineError('transcriptet har ugyldige segmenter');
                }
                $list[] = $s;
            }
            $res = Diarization::apply($list, $intervals, $this->settings->phrases);
            DB::exec("UPDATE transcripts SET segments_json = ?, diar_status = 'faerdig', diarized_at = ? WHERE call_id = ? AND created_at = ?",
                [(string) json_encode($res['segments'], JSON_UNESCAPED_UNICODE), $this->now(), $job['id'], $job['created_at']]);
            $this->ok++;
        } catch (Throwable $e) {
            $this->fail($job, $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved diarization', false);
            return;
        }
        ($this->log)('diarize: opkald ' . $job['id'] . ': ' . self::doneLine($res['speakers'], $res['agent'], $job['duration'], $sec));
    }

    /** "2 talere, agent fundet (lyd 4:12, diar 0:41)" */
    public static function doneLine(int $speakers, bool $agent, int $audioSec, float $sec): string
    {
        $who = $speakers === 0 ? 'ingen tale' : ($speakers === 1 ? '1 taler' : $speakers . ' talere')
            . ($speakers > 0 ? ($agent ? ', agent fundet' : ', ingen agent') : '');
        return $who . ' (lyd ' . Transcriber::clock($audioSec) . ', diar ' . Transcriber::clock((int) round($sec)) . ')';
    }

    /**
     * Ét forsøg brugt ($final: opgivet med det samme). Status beregnes her, ikke i SQL.
     *
     * @param array{id: int, audio_path: string, duration: int, created_at: string, attempts: int, segments: string} $job
     */
    private function fail(array $job, string $msg, bool $final): void
    {
        $attempts = $final ? self::MAX_ATTEMPTS : min(self::MAX_ATTEMPTS, $job['attempts'] + 1);
        try {
            $this->mark($job, $attempts >= self::MAX_ATTEMPTS ? 'fejlet' : null, $attempts);
        } catch (Throwable) {
            // Bogføringen kunne ikke skrives: opkaldet prøves igen næste gang; transcriptet er urørt
        }
        ($this->log)('diarize: opkald ' . $job['id'] . ': fejlet: ' . $msg
            . ($attempts >= self::MAX_ATTEMPTS ? ' (opgivet)' : ' (forsøg ' . $attempts . ' af ' . self::MAX_ATTEMPTS . ')'));
    }

    /** @param array{id: int, audio_path: string, duration: int, created_at: string, attempts: int, segments: string} $job */
    private function mark(array $job, ?string $status, int $attempts): void
    {
        DB::ensureAlive();
        DB::exec('UPDATE transcripts SET diar_status = ?, diar_attempts = ?, diarized_at = ? WHERE call_id = ? AND created_at = ?',
            [$status, $attempts, $this->now(), $job['id'], $job['created_at']]);
    }

    /** @return array{0: int, 1: int} pause til (unix-tid) og første opkald ved seneste motorfejl (0 = ingen) */
    private function pause(): array
    {
        $raw = @file_get_contents(self::pauseFile());
        if (!is_string($raw) || preg_match('/^([0-9]{1,12}) ([0-9]{1,10})\s*$/', $raw, $m) !== 1) {
            return [0, 0];
        }
        return [(int) $m[1], (int) $m[2]];
    }

    private function setPause(int $until, int $firstId): void
    {
        try {
            Paths::writePrivate(self::pauseFile(), $until . ' ' . $firstId . "\n");
        } catch (Throwable) {
            // Uden markør: næste cron-minut prøver igen; forsøg brændes stadig ikke ved motorfejl
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
