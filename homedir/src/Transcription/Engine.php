<?php
declare(strict_types=1);

namespace Whisper\Transcription;

/**
 * Transcriptionsmotoren. I drift faster-whisper i projektets venv (ProcessEngine); i tests en falsk motor
 * (CI-brugeren må ikke hente modeller, spec F002 beslutning 3).
 */
interface Engine
{
    /** Klar = venv, script og den konfigurerede model er hentet (markøren fra whisper-setup.sh). */
    public function available(): bool;

    /** Motorens indstillinger til loglinjen, fx "threads 5, beam 1, vad on" ('' = ingen). */
    public function label(): string;

    /**
     * @param int $durationSec opkaldets varighed (giver motorens tidsgrænse)
     * @return array<mixed> motorens rå JSON-svar (valideres af Transcriber::validate)
     * @throws EngineError
     */
    public function transcribe(string $audioFile, int $durationSec): array;
}
