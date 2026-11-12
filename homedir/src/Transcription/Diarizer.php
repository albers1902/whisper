<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Throwable;
use Whisper\Calls;
use Whisper\DB;

/**
 * Hvem taler, som et separat trin i ledig tid (H011, cron/diarize.php). Transcription er uændret og går altid først:
 *
 * - Kører kun, når transcription-køen er tom (ingen opkald med hentet lyd i "ventende" eller "transcriberer").
 * - Tager færdige transcripts uden talere (transcripts.diar_status IS NULL, højst MAX_ATTEMPTS forsøg), nyeste opkald
 *   først, BATCH ad gangen, og lægger "speaker"/"role" ind i segments_json (Diarization::apply).
 * - Mens motoren kører, spørges køen hvert ProcessDiarizer::STOP_CHECK sekund. Kommer der et opkald, stoppes motoren
 *   straks; det igangværende opkald bruger intet forsøg og tages igen senere.
 * - Talerne gemmes kun, hvis transcriptet er uændret (samme created_at): er opkaldet transcriberet igen imens, kasseres
 *   resultatet, og Transcriber::finish har allerede nulstillet bogføringen.
 * - En fejl rører aldrig transcriptet: det bliver stående uden talere og vises som før.
 */
final class Diarizer
{
    public const BATCH = 10;
    public const MAX_ATTEMPTS = 3;

    /** true, når seneste runOnce() blev stoppet, fordi der kom opkald i transcription-køen */
    public bool $stopped = false;

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     */
    public function __construct(private readonly DiarizationEngine $engine, private readonly DiarizationSettings $settings,
        ?Closure $clock = null, ?Closure $log = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
    }

    /** Er der opkald, som transcription skal have først? */
    public static function busy(): bool
    {
        return DB::all("SELECT 1 FROM calls WHERE audio_status = 'hentet' AND transcript_status IN ('ventende', 'transcriberer') LIMIT 1") !== [];
    }

    /**
     * Færdige transcripts uden talere, nyeste opkald først.
     *
     * @return list<array{id: int, audio_path: string, duration: int, created_at: string}>
     */
    public static function next(int $max): array
    {
        $rows = DB::all("SELECT c.id, c.audio_path, c.duration_sec, t.created_at FROM calls c JOIN transcripts t ON t.call_id = c.id "
            . "WHERE c.transcript_status = 'faerdig' AND t.diar_status IS NULL AND t.diar_attempts < ? "
            . 'ORDER BY c.started_at DESC, c.id DESC LIMIT ' . max(1, min(self::BATCH, $max)), [self::MAX_ATTEMPTS]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'audio_path' => is_string($r['audio_path'] ?? null) ? $r['audio_path'] : '',
                'duration' => (int) ($r['duration_sec'] ?? 0), 'created_at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * Én batch. Returnerer antal opkald, der fik et udfald (0 = intet at gøre, motoren er ikke klar, køen er ikke tom,
     * eller motoren blev stoppet: så slutter kørslen, se $stopped).
     *
     * @param int $remaining sekunder tilbage af kørslens ramme
     */
    public function runOnce(int $remaining): int
    {
        $this->stopped = false;
        if (!$this->engine->available() || self::busy()) {
            return 0;
        }
        $jobs = self::next(self::BATCH);
        $n = 0;
        /** @var array<string, array{id: int, audio_path: string, duration: int, created_at: string}> $byFile */
        $byFile = [];
        foreach ($jobs as $job) {
            $file = Calls::audioFile($job['audio_path']);
            if ($file === null || isset($byFile[$file])) {
                // Uden lyd kan det aldrig lykkes: opgivet med det samme, ingen nye forsøg
                $this->fail($job, 'Lydfilen mangler på serveren', true);
                $n++;
                continue;
            }
            $byFile[$file] = $job;
        }
        if ($byFile === []) {
            return $n;
        }
        $done = [];
        $error = null;
        try {
            $this->stopped = $this->engine->diarize(array_keys($byFile), max(60, $remaining),
                function (string $file, array|EngineError $raw, float $sec) use (&$done, $byFile): void {
                    if (!isset($byFile[$file]) || isset($done[$file])) {
                        return;
                    }
                    $done[$file] = true;
                    $this->save($byFile[$file], $raw, $sec);
                }, static fn(): bool => self::busy());
        } catch (Throwable $e) {
            $error = $e instanceof EngineError ? $e->getMessage() : 'Intern fejl ved diarization';
        }
        $n += count($done);
        if ($this->stopped) {
            ($this->log)('diarize: stoppet — nye opkald i transcription-køen');
            return 0;
        }
        $rest = array_values(array_diff_key($byFile, $done));
        if ($rest !== [] && $error !== null) {
            // Motoren tager filerne i rækkefølge: den første uden svar var den, den var i gang med
            $this->fail($rest[0], $error, false);
            $n++;
        }
        return $n;
    }

    /**
     * @param array{id: int, audio_path: string, duration: int, created_at: string} $job
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
                throw new EngineError('transcriptet har ingen segmenter');
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

    /** @param array{id: int, audio_path: string, duration: int, created_at: string} $job */
    private function fail(array $job, string $msg, bool $final): void
    {
        try {
            DB::ensureAlive();
            // Venstre mod højre i MariaDB: diar_status ser den nye diar_attempts
            DB::exec('UPDATE transcripts SET diar_attempts = ' . ($final ? '?' : 'LEAST(diar_attempts + 1, ?)') . ', '
                . "diar_status = CASE WHEN diar_attempts >= ? THEN 'fejlet' ELSE NULL END, diarized_at = ? "
                . 'WHERE call_id = ? AND created_at = ?',
                [self::MAX_ATTEMPTS, self::MAX_ATTEMPTS, $this->now(), $job['id'], $job['created_at']]);
        } catch (Throwable) {
            // Bogføringen kunne ikke skrives: opkaldet prøves igen næste gang; transcriptet er urørt
        }
        ($this->log)('diarize: opkald ' . $job['id'] . ': fejlet: ' . $msg);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
