<?php
declare(strict_types=1);

namespace Whisper\Mcp;

use RuntimeException;

/** Et ugyldigt værktøjskald (argumenter, ukendt id). Beskeden er dansk og må vises til klienten — den har ingen data. */
final class ToolError extends RuntimeException
{
}
