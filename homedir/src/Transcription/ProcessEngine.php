<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;
use Whisper\Config;
use Whisper\Paths;

/**
 * faster-whisper i var/whisper/venv (lagt af cron/whisper-setup.sh), kørt som
 *   nice -n 19 [ionice -c3] var/whisper/venv/bin/python cron/transcribe.py --model <m> --models var/whisper/models
 *     --threads N --beam N --vad 0|1 <fil>
 * eller i batch (H006), hvor modellen indlæses én gang for flere filer, og svaret er JSON Lines:
 *     … --vad 0|1 --batch-max N -- <fil> <fil> …
 * med proc_open og et argv-array: ingen shell, og intet argument kommer fra brugeren eller Zendesk (filstien bygges af
 * appen ud fra opkaldets id, modellen fra en fast liste, threads/beam/vad/batch fra config.ini som validerede heltal,
 * se WhisperSettings). ionice bruges kun, hvis den findes.
 *
 * Klar betyder, at whisper-setup.sh er kørt færdig for netop den konfigurerede model (markøren
 * var/whisper/ready-<model>), ikke bare at venv'et findes (QA F-3).
 * Tidsgrænse: max(2 t, 4 × opkaldets varighed); for en batch sætter Transcriber den. Et opkald over en time
 * transcriberes stadig (spec), men en motor, der hænger, stoppes (SIGTERM, efter 10 s SIGKILL), så køen ikke står for
 * evigt (QA F-5, security S2).
 */
final class ProcessEngine implements BatchEngine
{
    public const MODELS = ['turbo', 'large-v3'];
    public const DEFAULT_MODEL = 'turbo';
    public const MIN_TIMEOUT = 7200;

    private readonly WhisperSettings $settings;

    /**
     * @param int|null $timeout fast tidsgrænse i sekunder (kun tests); null = max(2 t, 4 × varighed)
     * @param WhisperSettings|null $settings null = standarderne (kernerne delt mellem workers, beam 1, vad on, batch 10)
     * @param int $slot transcribe-slottet (Slots), der kører motoren: bestemmer filen til motorens stderr
     */
    public function __construct(private readonly string $model, private readonly ?int $timeout = null, ?WhisperSettings $settings = null,
        private readonly int $slot = 1)
    {
        if (!in_array($model, self::MODELS, true)) {
            throw new EngineError('[whisper] model skal være turbo eller large-v3');
        }
        $this->settings = $settings ?? WhisperSettings::fromSection([], WhisperSettings::cores());
    }

    /** @param (\Closure(string): void)|null $warn får én linje pr. ugyldig værdi i [whisper] */
    public static function fromConfig(?\Closure $warn = null): self
    {
        return self::fromSettings(WhisperSettings::fromSection(Config::section('whisper'), WhisperSettings::cores(), $warn));
    }

    /** Modellen fra config.ini, indstillingerne allerede læst (cron/transcribe.php skal kende workers før slotvalget) */
    public static function fromSettings(WhisperSettings $settings, int $slot = 1): self
    {
        $m = trim(Config::string('whisper', 'model', self::DEFAULT_MODEL));
        return new self($m === '' ? self::DEFAULT_MODEL : $m, null, $settings, $slot);
    }

    public function errorFile(): string
    {
        return Slots::errorFile($this->slot);
    }

    public function settings(): WhisperSettings
    {
        return $this->settings;
    }

    public function label(): string
    {
        return $this->settings->label();
    }

    public function batchSize(): int
    {
        return $this->settings->batch;
    }

    public static function python(): string
    {
        return Paths::varDir() . '/whisper/venv/bin/python';
    }

    public function marker(): string
    {
        return Paths::varDir() . '/whisper/ready-' . $this->model;
    }

    public function available(): bool
    {
        return is_file(self::python()) && is_executable(self::python()) && is_file(self::script()) && is_file($this->marker());
    }

    private static function script(): string
    {
        return Config::baseDir() . '/cron/transcribe.py';
    }

    public static function timeoutFor(int $durationSec): int
    {
        return max(self::MIN_TIMEOUT, 4 * max(0, $durationSec));
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        $limit = $this->timeout ?? self::timeoutFor($durationSec);
        [$code, $timedOut, $json] = $this->run([$audioFile], $limit, null);
        self::check($code, $timedOut, $limit);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new EngineError('motoren gav et ugyldigt svar');
        }
        return $data;
    }

    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float
    {
        // Stierne bliver argv-elementer efter "--"; transcribe.py afviser det samme, men intet ugyldigt skal derud
        if ($files === [] || count($files) > $this->settings->batch || count(array_unique($files)) !== count($files)) {
            throw new EngineError('ugyldig batch');
        }
        foreach ($files as $f) {
            if (!self::safePath($f)) {
                throw new EngineError('ugyldig lydsti');
            }
        }
        $limit = $this->timeout ?? $limitSec;
        $pending = array_fill_keys($files, true);
        $load = null;
        $onLine = static function (string $line) use (&$pending, &$load, $onResult): void {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                return;
            }
            if (array_key_exists('load_seconds', $d)) {
                $load = is_numeric($d['load_seconds']) ? max(0.0, (float) $d['load_seconds']) : null;
                return;
            }
            $file = $d['file'] ?? null;
            // Kun filer, vi selv sendte, og kun én gang: et svar kan aldrig ramme et andet opkald
            if (!is_string($file) || !isset($pending[$file])) {
                return;
            }
            unset($pending[$file]);
            $elapsed = is_numeric($d['elapsed'] ?? null) ? max(0.0, (float) $d['elapsed']) : 0.0;
            if (array_key_exists('error', $d)) {
                // Motorens fejltekst kan indeholde stier; detaljen står i sidste-fejl.log
                $onResult($file, new EngineError('motoren kunne ikke transcribere lydfilen'), $elapsed);
                return;
            }
            unset($d['file'], $d['elapsed']);
            $onResult($file, $d, $elapsed);
        };
        [$code, $timedOut] = $this->run(array_merge(['--batch-max', (string) $this->settings->batch, '--'], $files), $limit, $onLine);
        self::check($code, $timedOut, $limit);
        return $load;
    }

    /** Absolut, uden ".."-led og kontroltegn (Calls::audioFile giver altid sådan en). */
    public static function safePath(string $file): bool
    {
        return str_starts_with($file, '/') && preg_match('/[\x00-\x1f]/', $file) !== 1
            && !in_array('..', explode('/', $file), true);
    }

    private static function check(int $code, bool $timedOut, int $limit): void
    {
        if ($timedOut) {
            throw new EngineError('motoren blev stoppet efter tidsgrænsen (' . intdiv($limit, 60) . ' min)', EngineError::TIMEOUT);
        }
        if ($code !== 0) {
            throw new EngineError('motoren fejlede (exit ' . $code . ')');
        }
    }

    /**
     * Kører transcribe.py med $args til sidst. Med $onLine afleveres hver hele stdout-linje, mens motoren kører.
     *
     * @param list<string> $args
     * @param (Closure(string): void)|null $onLine
     * @return array{0: int, 1: bool, 2: string} exitkode, stoppet ved tidsgrænsen, stdout ('' med $onLine)
     */
    private function run(array $args, int $limit, ?Closure $onLine): array
    {
        if (!$this->available()) {
            throw new EngineError('transcriptionsmotoren er ikke sat op (cron/whisper-setup.sh)');
        }
        $argv = [];
        if (is_executable('/usr/bin/nice')) {
            $argv = ['/usr/bin/nice', '-n', '19'];
        }
        if (is_executable('/usr/bin/ionice')) {
            $argv = array_merge($argv, ['/usr/bin/ionice', '-c3']);
        }
        $argv = array_merge($argv, [self::python(), self::script(), '--model', $this->model,
            '--models', Paths::dir('whisper/models')], $this->settings->argv(), $args);

        $out = (string) tempnam(Paths::dir('whisper'), 'out');
        // Motorens stderr kan indeholde stier; den gemmes privat (0600) til fejlsøgning og vises aldrig. Én fil pr. slot,
        // så to samtidige motorer ikke trunkerer hinandens
        $err = $this->errorFile();
        $old = umask(077);
        $timedOut = false;
        $code = -1;
        $proc = null;
        $pos = 0;
        $buf = '';
        // Læser det nye i stdout-filen og afleverer hele linjer; $final afleverer også en sidste linje uden \n
        $drain = static function (bool $final) use ($out, &$pos, &$buf, $onLine): void {
            if ($onLine === null) {
                return;
            }
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
            // HF_HUB_OFFLINE: modellen er hentet af whisper-setup.sh; under transcription går intet kald ud af huset
            $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => Config::baseDir(), 'HF_HOME' => Paths::dir('whisper/hf'),
                'HF_HUB_OFFLINE' => '1', 'LANG' => 'C.UTF-8'];
            $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes,
                null, $env);
            if (!is_resource($proc)) {
                $proc = null;
                throw new EngineError('motoren kunne ikke startes');
            }
            $start = time();
            while (true) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    $code = $st['exitcode'];
                    break;
                }
                // Status før læsning: alt, motoren skrev før den sluttede, kommer med i den sidste læsning nedenfor
                $drain(false);
                if (time() - $start >= $limit) {
                    $timedOut = true;
                    self::stop($proc);
                    break;
                }
                usleep(250000);
            }
            proc_close($proc);
            $proc = null;
            // Også efter tidsgrænsen: filer, motoren nåede at blive færdig med, har et gyldigt resultat
            $drain(true);
            $json = $onLine === null ? (string) file_get_contents($out) : '';
        } finally {
            if ($proc !== null) {
                // $onLine kastede, mens motoren kørte: den må ikke køre videre uden for låsen
                self::stop($proc);
                proc_close($proc);
            }
            umask($old);
            @unlink($out);
        }
        return [$code, $timedOut, $json];
    }

    /** @param resource $proc SIGTERM, efter 10 s SIGKILL */
    private static function stop($proc): void
    {
        proc_terminate($proc, 15);
        for ($i = 0; $i < 100 && proc_get_status($proc)['running']; $i++) {
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
        }
    }
}
