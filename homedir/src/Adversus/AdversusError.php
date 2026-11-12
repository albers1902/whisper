<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use RuntimeException;

/**
 * Fejl fra Adversus-integrationen. Beskeden udskrives af proben og må derfor aldrig indeholde en URL (optagelses-URL'er
 * er signerede), brugernavn, adgangskode, Authorization-header eller en sti — kun en kort, fast eller renset tekst.
 */
class AdversusError extends RuntimeException
{
}
