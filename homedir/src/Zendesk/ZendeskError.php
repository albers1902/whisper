<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use RuntimeException;

/**
 * Fejl fra Zendesk-integrationen. Beskeden skrives i logs og i sync_state.last_error og må derfor aldrig indeholde
 * en URL (optagelses-URL'er kan være signerede), et token, client_secret eller en sti — kun en kort, fast tekst.
 */
class ZendeskError extends RuntimeException
{
}
