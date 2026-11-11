<?php
declare(strict_types=1);

namespace Whisper;

/** En HTTP-request som værdi, så App kan testes uden webserver. */
final class Request
{
    /** @param array<mixed> $post */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $post = [],
        public readonly string $ip = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_POST,
            // Kun REMOTE_ADDR: der er ingen proxy foran Apache, og X-Forwarded-For kan klienten selv skrive
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /** Et formularfelt som streng; arrays og manglende felter bliver ''. */
    public function str(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
