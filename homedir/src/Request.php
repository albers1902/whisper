<?php
declare(strict_types=1);

namespace Whisper;

/** En HTTP-request som værdi, så App kan testes uden webserver. */
final class Request
{
    /**
     * @param array<mixed> $post
     * @param array<mixed> $query    query-strengen (F002: filtre)
     * @param array<string, string> $headers  kun de headere, appen bruger, med små bogstaver (F002: range;
     *                                        F003: authorization, origin, host, content-type, mcp-protocol-version)
     * @param string $body  rå body; læses kun for /mcp og med loft (F003)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $post = [],
        public readonly string $ip = '',
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** @param int $maxBody >0: læs højst så mange bytes af body'en (F003: kun /mcp). 0: ingen body. */
    public static function fromGlobals(int $maxBody = 0): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $map = ['range' => 'HTTP_RANGE', 'origin' => 'HTTP_ORIGIN', 'host' => 'HTTP_HOST', 'content-type' => 'CONTENT_TYPE',
            'mcp-protocol-version' => 'HTTP_MCP_PROTOCOL_VERSION', 'authorization' => 'HTTP_AUTHORIZATION'];
        $headers = [];
        foreach ($map as $name => $key) {
            if (is_string($_SERVER[$key] ?? null)) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        // Uden CGIPassAuth i vhosten findes headeren kun her, hvis en RewriteRule har sat den (SERVERKONTRAKT §3)
        if (!isset($headers['authorization']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $body = '';
        if ($maxBody > 0) {
            $read = file_get_contents('php://input', false, null, 0, $maxBody);
            $body = is_string($read) ? $read : '';
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_POST,
            // Kun REMOTE_ADDR: der er ingen proxy foran Apache, og X-Forwarded-For kan klienten selv skrive
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $_GET,
            $headers,
            $body,
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
