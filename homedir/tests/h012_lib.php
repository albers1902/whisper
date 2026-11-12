<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til H012-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F002's, F004's og
 * F005's hjælpere. Testløberens config.ini har køens regler slået fra (run.php); H012-testene giver selv reglerne.
 */

require_once __DIR__ . '/f004_lib.php';

use Whisper\DB;
use Whisper\Transcription\QueueRules;
use Whisper\Transcription\Transcriber;

final class H012Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "H012-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        QueueRules::override(null);
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

/** UTC-tidspunktet $ago sekunder før $now, i kolonnens format. */
function h012_ago(int $now, int $ago): string
{
    return gmdate('Y-m-d H:i:s', $now - $ago);
}

/**
 * Et Zendesk-opkald med hentet lyd.
 *
 * @param array<string, mixed> $o
 */
function h012_zendesk(int $now, int $ago, int $duration = 125, array $o = []): int
{
    $id = f002_insert($o + ['started_at' => h012_ago($now, $ago), 'duration_sec' => $duration]);
    f002_audio($id);
    return $id;
}

/** Et Adversus-opkald med hentet lyd (succes = automatisk i kø; ikke succes + ventende = manuelt valgt, F004). */
function h012_adversus(int $extId, bool $success, int $now, int $ago, int $duration = 95): int
{
    $id = f004_insert($extId, $success, 'ventende', h012_ago($now, $ago));
    DB::exec('UPDATE calls SET duration_sec = ? WHERE id = ?', [$duration, $id]);
    f002_audio($id);
    return $id;
}

/** @return list<int> id'erne i den rækkefølge, claim tager dem (de står bagefter i "transcriberer") */
function h012_order(QueueRules $rules, int $now): array
{
    $t = new Transcriber(new FakeEngine(), static fn(): int => $now, static function (string $l): void {
    }, null, null, 1.5, $rules);
    return array_map('intval', array_column($t->claim(50), 'id'));
}

/**
 * @param array<string, mixed> $section
 * @return array{0: QueueRules, 1: list<string>} reglerne og advarslerne fra fromSection
 */
function h012_from(array $section): array
{
    $w = [];
    $rules = QueueRules::fromSection($section, static function (string $l) use (&$w): void {
        $w[] = $l;
    });
    return [$rules, $w];
}
