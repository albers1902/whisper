<?php
declare(strict_types=1);

namespace Whisper;

use RuntimeException;

/**
 * PHP's egne sessioner med filer i <hjemmemappe>/var/sessions. var/ er projektets efter SERVERKONTRAKT §2, er i
 * open_basedir og røres ikke af deploy.sh. PHP's GC rydder selv op (1/100 requests), fordi serverens cron kun rydder
 * PHP's standardmappe.
 */
final class NativeSessionDriver implements SessionDriver
{
    private const NAME = 'whisper_session';

    public static function hasCookie(): bool
    {
        $v = $_COOKIE[self::NAME] ?? null;
        return is_string($v) && $v !== '';
    }

    public static function start(string $baseDir): self
    {
        $dir = $baseDir . '/var/sessions';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('kan ikke oprette ' . $dir);
        }
        ini_set('session.save_path', $dir);
        ini_set('session.gc_maxlifetime', (string) App::IDLE_SECONDS);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');   // et id, serveren ikke selv har udstedt, afvises (session fixation)
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,   // ubetinget: Whisper kører kun over HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('session_start fejlede');
        }
        return new self();
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        $data = [];
        foreach ($_SESSION as $k => $v) {
            $data[(string) $k] = $v;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = $data;
            session_write_close();
        }
    }

    public function id(): string
    {
        return (string) session_id();
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);   // true: den gamle sessionsfil slettes
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        setcookie(self::NAME, '', [
            'expires'  => 1,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}
