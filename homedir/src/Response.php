<?php
declare(strict_types=1);

namespace Whisper;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 303, så en POST altid efterfølges af en GET (ingen gensendt formular ved genindlæsning). */
    public static function redirect(string $to): self
    {
        return new self(303, '', ['Location' => $to]);
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
