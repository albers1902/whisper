<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

/** Zendesk svarede 429 og bad os vente længere, end en kørsel må vente. Sync stopper pænt; næste kørsel fortsætter. */
final class RateLimited extends ZendeskError
{
}
