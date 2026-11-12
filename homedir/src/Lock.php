<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Ikke-blokerende flock på var/locks/<navn>.lock (som deploy.sh: låsen i scriptet, aldrig i cron-linjen).
 * Låsen slippes, når processen dør, så "ingen holder låsen" betyder "intet job kører" — det bruger transcribe.php,
 * før et hængende opkald sættes tilbage.
 */
final class Lock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    public static function tryAcquire(string $name): ?self
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $name) !== 1) {
            return null;
        }
        $h = fopen(Paths::dir('locks') . '/' . $name . '.lock', 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return new self($h);
    }

    /**
     * Erstatter låsefilens indhold (på det låste håndtag). Bruges af transcribe-slots til at vise, hvilke opkald
     * processen kører, så en anden proces' stale-regel kan skåne dem.
     */
    public function write(string $content): void
    {
        if (is_resource($this->handle)) {
            ftruncate($this->handle, 0);
            rewind($this->handle);
            fwrite($this->handle, $content);
            fflush($this->handle);
        }
    }

    /**
     * Indholdet af <navn>.lock, hvis en anden holder låsen; null, hvis ingen holder den (så er dens indhold forældet).
     * Låsen tages kort for at afgøre det og slippes straks.
     */
    public static function heldContent(string $name): ?string
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $name) !== 1) {
            return null;
        }
        $file = Paths::dir('locks') . '/' . $name . '.lock';
        if (!is_file($file)) {
            return null;
        }
        $free = self::tryAcquire($name);
        if ($free !== null) {
            $free->release();
            return null;
        }
        return (string) @file_get_contents($file);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
