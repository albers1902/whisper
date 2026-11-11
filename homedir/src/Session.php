<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Sessionens data som et array, adskilt fra PHP's $_SESSION. index.php læser $_SESSION ind og skriver det tilbage
 * efter requesten (NativeSessionDriver), og testene bruger samme klasse med ArraySessionDriver.
 */
final class Session
{
    /** @param array<string, mixed> $data */
    public function __construct(private SessionDriver $driver, private array $data = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function id(): string
    {
        return $this->driver->id();
    }

    /** Nyt session-id (login, skift af adgangskode), så et kendt eller stjålet id ikke kan genbruges. */
    public function regenerate(): void
    {
        $this->driver->regenerate();
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->driver->destroy();
    }

    public function driver(): SessionDriver
    {
        return $this->driver;
    }
}
