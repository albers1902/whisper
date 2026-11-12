<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Whisper\Config;
use Whisper\Paths;

/**
 * sherpa-onnx i var/whisper/venv (H011), kørt som
 *   nice -n 19 [ionice -c3] var/whisper/venv/bin/python cron/diarize.py --models var/whisper/diarization --threads N -- <fil> …
 * med proc_open og et argv-array (ingen shell). Filstierne bygges af appen ud fra opkaldets id, tråde er et valideret
 * heltal. Svaret er JSON Lines, én linje pr. fil: {"file", "seconds", "intervals": […]} eller {"file", "error": <type>}.
 * Mens motoren kører, spørges $stop hvert STOP_CHECK sekund; et ventende opkald i transcription-køen (eller en
 * udrulning) stopper den straks: SIGTERM, efter højst KILL_WAIT sekunder SIGKILL (python har ingen handler, så SIGTERM
 * dræber den normalt med det samme), så diarization aldrig forsinker transcription mere end ca. STOP_CHECK sekunder.
 * Motorens stderr lægges efter hinanden i var/whisper/diarize-fejl.log (0600, ryddes over ERRLOG_MAX), så en fejl ikke
 * overskrives af næste kørsel.
 */
final class ProcessDiarizer implements DiarizationEngine
{
    public const STOP_CHECK = 5;
    public const KILL_WAIT = 2;
    public const ERRLOG_MAX = 1048576;

    public function __construct(private readonly int $threads)
    {
        if ($threads < 1 || $threads > WhisperSettings::MAX_THREADS) {
            throw new EngineError('[diarization] threads skal være 1–32');
        }
    }

    public function available(): bool
    {
        $py = ProcessEngine::python();
        return is_file($py) && is_executable($py) && is_file(self::script()) && is_file(DiarizationSettings::marker())
            && is_dir(DiarizationSettings::modelDir());
    }

    public static function errorFile(): string
    {
        return Paths::dir('whisper') . '/diarize-fejl.log';
    }

    private static function script(): string
    {
        return Config::baseDir() . '/cron/diarize.py';
    }

    public function diarize(array $files, int $limitSec, Closure $onResult, Closure $stop): ?float
    {
        if ($files === [] || count($files) > Diarizer::BATCH || count(array_unique($files)) !== count($files)) {
            throw new EngineError('ugyldig batch');
        }
        foreach ($files as $f) {
            if (!ProcessEngine::safePath($f)) {
                throw new EngineError('ugyldig lydsti');
            }
        }
        if (!$this->available()) {
            throw new EngineError('diarization er ikke sat op (cron/whisper-setup.sh)');
        }
        $pending = array_fill_keys($files, true);
        $onLine = static function (string $line) use (&$pending, $onResult): void {
            $d = json_decode($line, true);
            $file = is_array($d) ? ($d['file'] ?? null) : null;
            // Kun filer, vi selv sendte, og kun én gang: et svar kan aldrig ramme et andet opkald
            if (!is_array($d) || !is_string($file) || !isset($pending[$file])) {
                return;
            }
            unset($pending[$file]);
            $sec = is_numeric($d['seconds'] ?? null) ? max(0.0, (float) $d['seconds']) : 0.0;
            if (array_key_exists('error', $d) || !is_array($d['intervals'] ?? null)) {
                // Motorens fejltekst kan indeholde stier; detaljen står i diarize-fejl.log
                $onResult($file, new EngineError('diarization fejlede for lydfilen'), $sec);
                return;
            }
            $onResult($file, array_values($d['intervals']), $sec);
        };

        $argv = [];
        if (is_executable('/usr/bin/nice')) {
            $argv = ['/usr/bin/nice', '-n', '19'];
        }
        if (is_executable('/usr/bin/ionice')) {
            $argv = array_merge($argv, ['/usr/bin/ionice', '-c3']);
        }
        $argv = array_merge($argv, [ProcessEngine::python(), self::script(), '--models', DiarizationSettings::modelDir(),
            '--threads', (string) $this->threads, '--'], $files);

        $out = (string) tempnam(Paths::dir('whisper'), 'diar');
        $old = umask(077);
        $errLog = self::openErrorLog(count($files));
        $proc = null;
        $code = -1;
        $timedOut = false;
        $stopped = null;
        $pos = 0;
        $buf = '';
        $drain = static function (bool $final) use ($out, &$pos, &$buf, $onLine): void {
            clearstatcache(true, $out);
            $chunk = (string) @file_get_contents($out, false, null, $pos);
            $pos += strlen($chunk);
            $buf .= $chunk;
            while (($nl = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $nl);
                $buf = substr($buf, $nl + 1);
                if (trim($line) !== '') {
                    $onLine($line);
                }
            }
            if ($final && trim($buf) !== '') {
                $line = $buf;
                $buf = '';
                $onLine($line);
            }
        };
        try {
            $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => Config::baseDir(), 'HF_HOME' => Paths::dir('whisper/hf'),
                'HF_HUB_OFFLINE' => '1', 'LANG' => 'C.UTF-8'];
            $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $errLog, 'a']],
                $pipes, null, $env);
            if (!is_resource($proc)) {
                $proc = null;
                throw new EngineError('diarization kunne ikke startes');
            }
            $start = time();
            $checked = $start;
            while (true) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    $code = $st['exitcode'];
                    break;
                }
                $drain(false);
                if (time() - $start >= $limitSec) {
                    $timedOut = true;
                    self::kill($proc);
                    break;
                }
                if (time() - $checked >= self::STOP_CHECK) {
                    $checked = time();
                    if ($stop()) {
                        $t = microtime(true);
                        self::kill($proc);
                        $stopped = microtime(true) - $t;
                        break;
                    }
                }
                usleep(250000);
            }
            proc_close($proc);
            $proc = null;
            $drain(true);
        } finally {
            if ($proc !== null) {
                self::kill($proc);
                proc_close($proc);
            }
            umask($old);
            @unlink($out);
        }
        if ($stopped !== null) {
            return $stopped;
        }
        if ($timedOut) {
            throw new EngineError('diarization blev stoppet efter tidsgrænsen', EngineError::TIMEOUT);
        }
        if ($code !== 0) {
            throw new EngineError('diarization fejlede (exit ' . $code . ')');
        }
        return null;
    }

    /**
     * Fejlloggen klar til append: 0600 (også en fil, der fandtes i forvejen), ryddet over ERRLOG_MAX, og en linje, der
     * siger, hvilken kørsel det følgende stderr hører til.
     */
    private static function openErrorLog(int $files): string
    {
        $f = self::errorFile();
        clearstatcache(true, $f);
        if (is_file($f) && (int) filesize($f) > self::ERRLOG_MAX) {
            @unlink($f);
        }
        @file_put_contents($f, gmdate('Y-m-d\TH:i:s\Z') . ' diarize.py startet med ' . $files . " fil(er)\n", FILE_APPEND);
        @chmod($f, 0600);
        return $f;
    }

    /** @param resource $proc SIGTERM, efter højst KILL_WAIT s SIGKILL */
    private static function kill($proc): void
    {
        proc_terminate($proc, 15);
        for ($i = 0; $i < self::KILL_WAIT * 10 && proc_get_status($proc)['running']; $i++) {
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
        }
    }
}
