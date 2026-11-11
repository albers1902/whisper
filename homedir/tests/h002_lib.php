<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H002-testene (ikke selv en test: run.php kører kun test_*.php). Zendesk mockes med F002's
 * FakeTransport; hemmeligheder og tokens genereres i testen.
 */

require_once __DIR__ . '/f002_lib.php';

use Whisper\Paths;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

final class H002Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H002-$ak $msg\n";
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

/** Fejlteksten, som proben og Sync får den: beskeden fra den ZendeskError, accessToken kaster ('' = intet kastet). */
function h002_token_error(ZendeskConfig $cfg, HttpResult $response): string
{
    $http = new FakeTransport();
    $http->on('POST', F002_BASE . '/oauth/tokens', $response);
    try {
        f002_client($cfg, $http)->accessToken(true);
    } catch (ZendeskError $e) {
        return $e->getMessage();
    }
    return '';
}

/** Kun printbar ASCII. */
function h002_ascii(string $s): bool
{
    return preg_match('/^[\x20-\x7E]*$/', $s) === 1;
}

/** var/ i en midlertidig mappe uden database (probe-testene). */
function h002_var(): string
{
    $dir = sys_get_temp_dir() . '/whisper-h002-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    Paths::setVarDir($dir);
    return $dir;
}

/**
 * Et opkald som hos ejeren: optaget og besvaret, men recording_time 0 (H002).
 *
 * @return array<string, mixed>
 */
function h002_answered_call(int $id, int $ticket, string $updated, int $duration = 74): array
{
    return ['id' => $id, 'ticket_id' => $ticket, 'direction' => 'inbound', 'recording_time' => 0, 'not_recording_time' => 0,
        'duration' => $duration, 'talk_time' => $duration - 4, 'completion_status' => 'completed', 'agent_id' => 7,
        'created_at' => $updated, 'updated_at' => $updated, 'phone_number' => '+4570000000'];
}
