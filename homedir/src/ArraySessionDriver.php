<?php
declare(strict_types=1);

namespace Whisper;

/** Session-driver uden cookies og filer — til tests og CLI. */
final class ArraySessionDriver implements SessionDriver
{
    private string $id;
    public bool $destroyed = false;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->destroyed = true;
        $this->id = bin2hex(random_bytes(16));
    }
}
