<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H007-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's og H006's
 * hjælpere (database, var/ i en midlertidig mappe, falske motorer).
 *
 * flock er pr. åben fil: en lås taget med Lock::tryAcquire i testprocessen opfører sig over for Slots som en anden
 * kørende transcribe-proces.
 */

require_once __DIR__ . '/h006_lib.php';

final class H007Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H007-$ak $msg\n";
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

/**
 * Kører cron/transcribe.php som egen proces med flagene tændt og testens var/ (som F002-AK9). Config er testløberens
 * (ingen [whisper]: workers 2), og motoren er ikke sat op, så kørslen stopper efter slotvalg og stale-reglen.
 *
 * @return array{0: int, 1: string} exitkode, stdout + stderr
 */
function h007_cron(string $var): array
{
    putenv('WHISPER_VAR_DIR=' . $var);
    putenv('WHISPER_TEST_FLAGS=F001_brugere,F002_zendesk');
    $p = proc_open([PHP_BINARY, __DIR__ . '/../cron/transcribe.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]) : '';
    $code = is_resource($p) ? proc_close($p) : -1;
    putenv('WHISPER_TEST_FLAGS=');
    return [$code, $out];
}

/** Et opkald, der har stået i "transcriberer" i 3 timer (efter den rigtige tid, som cron-scriptet bruger). */
function h007_stale_now(int $attempts = 1): int
{
    $id = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => $attempts,
        'claimed_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
    f002_audio($id);
    return $id;
}

function h007_status(int $id): string
{
    return (string) \Whisper\DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$id]);
}
