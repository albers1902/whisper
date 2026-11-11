<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use RuntimeException;

/** Kort, fast fejltekst (vises på opkaldet og i logs): aldrig stier, aldrig motorens egen stderr. */
final class EngineError extends RuntimeException
{
}
