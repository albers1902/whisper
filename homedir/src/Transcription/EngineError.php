<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use RuntimeException;

/** Kort, fast fejltekst (vises på opkaldet og i logs): aldrig stier, aldrig motorens egen stderr. */
final class EngineError extends RuntimeException
{
    /** Koden, når motoren blev stoppet ved tidsgrænsen (så Transcriber ikke skal læse teksten) */
    public const TIMEOUT = 1;
}
