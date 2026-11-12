<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use RuntimeException;

/** Sync's optagelsesfase har ikke flere kald uden at gå ind i reserven til lyd. Intern: fanges altid i Sync::recordings(). */
final class OutOfBudget extends RuntimeException
{
}
