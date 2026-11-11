<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Whisper\Config;
use Whisper\Paths;

/**
 * faster-whisper i var/whisper/venv (lagt af cron/whisper-setup.sh), kørt som
 *   nice -n 19 [ionice -c3] var/whisper/venv/bin/python cron/transcribe.py --model <m> --models var/whisper/models <fil>
 * med proc_open og et argv-array: ingen shell, og intet argument kommer fra brugeren eller Zendesk (filstien bygges af
 * appen ud fra opkaldets id, modellen fra en fast liste). ionice bruges kun, hvis den findes. Ingen tidsgrænse: et
 * opkald over en time transcriberes som alle andre (spec).
 */
final class ProcessEngine implements Engine
{
    public const MODELS = ['turbo', 'large-v3'];
    public const DEFAULT_MODEL = 'turbo';

    public function __construct(private readonly string $model)
    {
        if (!in_array($model, self::MODELS, true)) {
            throw new EngineError('[whisper] model skal være turbo eller large-v3');
        }
    }

    public static function fromConfig(): self
    {
        $m = trim(Config::string('whisper', 'model', self::DEFAULT_MODEL));
        return new self($m === '' ? self::DEFAULT_MODEL : $m);
    }

    public static function python(): string
    {
        return Paths::varDir() . '/whisper/venv/bin/python';
    }

    public function available(): bool
    {
        return is_file(self::python()) && is_executable(self::python()) && is_file(self::script());
    }

    private static function script(): string
    {
        return Config::baseDir() . '/cron/transcribe.py';
    }

    public function transcribe(string $audioFile): array
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
            '--models', Paths::dir('whisper/models'), $audioFile]);

        $out = (string) tempnam(Paths::dir('whisper'), 'out');
        // Motorens stderr kan indeholde stier; den gemmes privat (0600) til fejlsøgning og vises aldrig
        $err = Paths::dir('whisper') . '/sidste-fejl.log';
        $old = umask(077);
        try {
            // HF_HUB_OFFLINE: modellen er hentet af whisper-setup.sh; under transcription går intet kald ud af huset
            $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => Config::baseDir(), 'HF_HOME' => Paths::dir('whisper/hf'),
                'HF_HUB_OFFLINE' => '1', 'LANG' => 'C.UTF-8'];
            $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes,
                null, $env);
            if (!is_resource($proc)) {
                throw new EngineError('motoren kunne ikke startes');
            }
            $code = proc_close($proc);
            $json = (string) file_get_contents($out);
        } finally {
            umask($old);
            @unlink($out);
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
