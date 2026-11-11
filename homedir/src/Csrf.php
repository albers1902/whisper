<?php
declare(strict_types=1);

namespace Whisper;

/** Ét CSRF-token pr. session (synchronizer token). Alle POST-formularer sender det i feltet _csrf. */
final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(Session $s): string
    {
        $t = $s->get('csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            $s->set('csrf', $t);
        }
        return $t;
    }

    public static function valid(Session $s, string $given): bool
    {
        $t = $s->get('csrf');
        return is_string($t) && strlen($t) === 64 && $given !== '' && hash_equals($t, $given);
    }
}
