<?php
declare(strict_types=1);

namespace Whisper;

/** En HTTP-request som værdi, så App kan testes uden webserver. */
final class Request
{
    /**
     * @param array<mixed> $post
     * @param array<mixed> $query    query-strengen (F002: filtre)
     * @param array<string, string> $headers  kun de headere, appen bruger, med små bogstaver (F002: range)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $post = [],
        public readonly string $ip = '',
        public readonly array $query = [],
        public readonly array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $headers = [];
        if (is_string($_SERVER['HTTP_RANGE'] ?? null)) {
            $headers['range'] = $_SERVER['HTTP_RANGE'];
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_POST,
            // Kun REMOTE_ADDR: der er ingen proxy foran Apache, og X-Forwarded-For kan klienten selv skrive
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $_GET,
            $headers,
        );
    }

    /** Et formularfelt som streng; arrays og manglende felter bliver ''. */
    public function str(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
