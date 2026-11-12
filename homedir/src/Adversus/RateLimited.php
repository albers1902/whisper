<?php
declare(strict_types=1);

namespace Whisper\Adversus;

/** Adversus svarede 429 og bad os vente længere end 60 s (eller blev ved). Proben stopper pænt. */
final class RateLimited extends AdversusError
{
}
