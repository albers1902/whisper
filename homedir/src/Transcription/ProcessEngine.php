<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Whisper\Config;
use Whisper\Paths;

/**
 * faster-whisper i var/whisper/venv (lagt af cron/whisper-setup.sh), kørt som
 *   nice -n 19 [ionice -c3] var/whisper/venv/bin/python cron/transcribe.py --model <m> --models var/whisper/models
 *     --threads N --beam N --vad 0|1 <fil>
 * med proc_open og et argv-array: ingen shell, og intet argument kommer fra brugeren eller Zendesk (filstien bygges af
 * appen ud fra opkaldets id, modellen fra en fast liste, threads/beam/vad fra config.ini som validerede heltal,
 * se WhisperSettings). ionice bruges kun, hvis den findes.
 *
 * Klar betyder, at whisper-setup.sh er kørt færdig for netop den konfigurerede model (markøren
 * var/whisper/ready-<model>), ikke bare at venv'et findes (QA F-3).
 * Tidsgrænse: max(2 t, 4 × opkaldets varighed). Et opkald over en time transcriberes stadig (spec), men en motor, der
 * hænger, stoppes (SIGTERM, efter 10 s SIGKILL), så køen ikke står for evigt (QA F-5, security S2).
 */
final class ProcessEngine implements Engine
{
    public const MODELS = ['turbo', 'large-v3'];
    public const DEFAULT_MODEL = 'turbo';
    public const MIN_TIMEOUT = 7200;

    private readonly WhisperSettings $settings;

    /**
     * @param int|null $timeout fast tidsgrænse i sekunder (kun tests); null = max(2 t, 4 × varighed)
     * @param WhisperSettings|null $settings null = standarderne (kerner − 1, beam 1, vad on)
     */
    public function __construct(private readonly string $model, private readonly ?int $timeout = null, ?WhisperSettings $settings = null)
    {
        if (!in_array($model, self::MODELS, true)) {
            throw new EngineError('[whisper] model skal være turbo eller large-v3');
        }
        $this->settings = $settings ?? WhisperSettings::fromSection([], WhisperSettings::cores());
    }

    /** @param (\Closure(string): void)|null $warn får én linje pr. ugyldig værdi i [whisper] */
    public static function fromConfig(?\Closure $warn = null): self
    {
        $m = trim(Config::string('whisper', 'model', self::DEFAULT_MODEL));
        return new self($m === '' ? self::DEFAULT_MODEL : $m, null,
            WhisperSettings::fromSection(Config::section('whisper'), WhisperSettings::cores(), $warn));
    }

    public function settings(): WhisperSettings
    {
        return $this->settings;
    }

    public function label(): string
    {
        return $this->settings->label();
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
            '--models', Paths::dir('whisper/models')], $this->settings->argv(), [$audioFile]);

        $out = (string) tempnam(Paths::dir('whisper'), 'out');
        // Motorens stderr kan indeholde stier; den gemmes privat (0600) til fejlsøgning og vises aldrig
        $err = Paths::dir('whisper') . '/sidste-fejl.log';
        $limit = $this->timeout ?? self::timeoutFor($durationSec);
        $old = umask(077);
        $timedOut = false;
        try {
            // HF_HUB_OFFLINE: modellen er hentet af whisper-setup.sh; under transcription går intet kald ud af huset
            $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => Config::baseDir(), 'HF_HOME' => Paths::dir('whisper/hf'),
                'HF_HUB_OFFLINE' => '1', 'LANG' => 'C.UTF-8'];
            $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes,
                null, $env);
            if (!is_resource($proc)) {
                throw new EngineError('motoren kunne ikke startes');
            }
            $start = time();
            $code = -1;
            while (true) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    $code = $st['exitcode'];
                    break;
                }
                if (time() - $start >= $limit) {
                    $timedOut = true;
                    proc_terminate($proc, 15);
                    for ($i = 0; $i < 100 && proc_get_status($proc)['running']; $i++) {
                        usleep(100000);
                    }
                    if (proc_get_status($proc)['running']) {
                        proc_terminate($proc, 9);
                    }
                    break;
                }
                usleep(250000);
            }
            proc_close($proc);
            $json = (string) file_get_contents($out);
        } finally {
            umask($old);
            @unlink($out);
        }
        if ($timedOut) {
            throw new EngineError('motoren blev stoppet efter tidsgrænsen (' . intdiv($limit, 60) . ' min)');
        }
        if ($code !== 0) {
            throw new EngineError('motoren fejlede (exit ' . $code . ')');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new EngineError('motoren gav et ugyldigt svar');
        }
        return $data;
    }
}
