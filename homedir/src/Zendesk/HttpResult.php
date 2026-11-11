<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

/** Ét HTTP-svar. Headernavne er små bogstaver. Ved download (saveTo) er body tom — indholdet ligger i filen. */
final class HttpResult
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
