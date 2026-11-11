<?php
declare(strict_types=1);

namespace Whisper\Transcription;

/**
 * Transcriptionsmotoren. I drift faster-whisper i projektets venv (ProcessEngine); i tests en falsk motor
 * (CI-brugeren må ikke hente modeller, spec F002 beslutning 3).
 */
interface Engine
{
    public function available(): bool;

    /**
     * @return array<mixed> motorens rå JSON-svar (valideres af Transcriber::validate)
     * @throws EngineError
     */
    public function transcribe(string $audioFile): array;
}
