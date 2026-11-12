<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Closure;

/**
 * En motor, der transcriberer flere filer i ét kald, så modellen kun indlæses én gang (H006). Et eget interface, så
 * Engine (og testenes falske motorer) er uændret, og `[whisper] batch = 1` bruger den gamle vej.
 */
interface BatchEngine extends Engine
{
    /** Højst så mange filer pr. transcribeBatch ([whisper] batch, 1–50; 1 = Transcriber bruger transcribe()). */
    public function batchSize(): int;

    /**
     * Resultaterne afleveres til $onResult i den rækkefølge, motoren bliver færdig, mens den stadig kører, så hvert
     * opkald kan gemmes med det samme. En fil, motoren ikke kunne transcribere, afleveres som en EngineError.
     *
     * @param list<string> $files absolutte stier (Calls::audioFile)
     * @param int $limitSec tidsgrænse for hele batchen
     * @param Closure(string, array<mixed>|EngineError, float): void $onResult fil, rå JSON eller fejl, sekunder for filen
     * @return float|null sekunder brugt på at indlæse modellen (null = ikke oplyst)
     * @throws EngineError hvis motoren ikke kunne startes, fejlede eller blev stoppet ved tidsgrænsen — efter at de
     *   resultater, der allerede var læst, er afleveret
     */
    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float;
}
