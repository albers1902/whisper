<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;

/** Diarization-motoren (H011). I drift sherpa-onnx i whisper-venv'et (ProcessDiarizer); i tests en falsk motor. */
interface DiarizationEngine
{
    /** Klar = whisper-setup.sh har hentet modellerne og bestået selvtesten (markøren ready-diarization). */
    public function available(): bool;

    /**
     * Filerne i rækkefølge. Hvert resultat afleveres, så snart det er klar.
     *
     * @param list<string> $files absolutte stier (Calls::audioFile)
     * @param Closure(string, list<mixed>|EngineError, float): void $onResult fil, rå intervaller eller fejl, sekunder
     * @param Closure(): bool $stop spørges ca. hvert ProcessDiarizer::STOP_CHECK sekund, mens motoren kører; true = stop den
     * @return float|null null = motoren kørte færdig; ellers stoppet af $stop, og tallet er sekunder fra beslutningen, til
     *   motoren var død (resultaterne før det er afleveret)
     * @throws EngineError hvis motoren ikke kunne startes, fejlede (exit ≠ 0) eller nåede tidsgrænsen
     */
    public function diarize(array $files, int $limitSec, Closure $onResult, Closure $stop): ?float;
}
