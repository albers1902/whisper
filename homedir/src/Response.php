<?php
declare(strict_types=1);

namespace Whisper;

final class Response
{
    /**
     * Et fil-svar (F002: lyd og zip) streames fra disk i stedet for at ligge i body: $file fra $offset, $length bytes.
     * $deleteAfter sletter filen bagefter (den midlertidige zip).
     */
    public ?string $file = null;
    public int $offset = 0;
    public int $length = 0;
    public bool $deleteAfter = false;

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

    /** @param array<string, string> $headers */
    public static function file(int $status, string $file, int $offset, int $length, array $headers, bool $deleteAfter = false): self
    {
        $r = new self($status, '', $headers + ['Content-Length' => (string) $length]);
        $r->file = $file;
        $r->offset = $offset;
        $r->length = $length;
        $r->deleteAfter = $deleteAfter;
        return $r;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** Fil-svarets bytes (til tests; i drift streamer send() dem). */
    public function fileContents(): string
    {
        if ($this->file === null) {
            return $this->body;
        }
        $data = file_get_contents($this->file, false, null, $this->offset, $this->length);
        return $data === false ? '' : $data;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->file === null) {
            echo $this->body;
            return;
        }
        try {
            $isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
            $h = $isHead ? false : fopen($this->file, 'rb');
            if ($h !== false) {
                fseek($h, $this->offset);
                $left = $this->length;
                while ($left > 0 && !feof($h) && connection_status() === CONNECTION_NORMAL) {
                    $chunk = fread($h, min(65536, $left));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();
                    $left -= strlen($chunk);
                }
                fclose($h);
            }
        } finally {
            if ($this->deleteAfter) {
                @unlink($this->file);
            }
        }
    }
}
