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

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
