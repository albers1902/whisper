<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F005-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's hjælpere
 * (database, opkald, login) og tænder F005_ui oveni.
 */

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Response;
use Whisper\Ui;

final class F005Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F005-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

function f005_setup(): string
{
    $dir = f002_setup();
    Flags::override(Ui::FLAG, true);
    return $dir;
}

/** En indlogget administrator. */
function f005_admin(): F001Client
{
    DB::exec('DELETE FROM login_attempts');
    DB::exec('DELETE FROM users');
    f001_user('admin@f005.test', 'admin');
    $c = new F001Client();
    $c->login('admin@f005.test', F001_PW);
    return $c;
}

/** Ingen inline style, <style>, inline script eller on*-attributter (CSP, AK1). */
function f005_no_inline(Response $r): bool
{
    $b = $r->body;
    return !str_contains($b, 'style=') && stripos($b, '<style') === false
        && preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $b) !== 1
        && preg_match('/<[^>]+\son[a-z]+\s*=/i', $b) !== 1;
}

/** Teksten uden markup og entities (til længdemåling af uddrag). */
function f005_plain(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
