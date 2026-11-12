<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F007-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F004 (Adversus, F005_ui)
 * og tænder F007_kilder oveni. Kø-kortets tal læses én gang pr. request (UiV2), så f007_get nulstiller dem før hver side.
 */

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/f007_snapshot.php';

use Whisper\Flags;
use Whisper\Response;
use Whisper\Transcription\DiarizationSettings;
use Whisper\UiV2;

final class F007Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F007-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
            self::$failed[] = "F007-$ak $msg";
        }
    }

    /** @var list<string> */
    private static array $failed = [];

    public static function done(): never
    {
        // De fejlede gentages til sidst: DEV's PR-kommentar viser kun loggens sidste linjer
        foreach (self::$failed as $f) {
            echo 'FAIL (gentaget): ' . $f . "\n";
        }
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

function f007_setup(): string
{
    $dir = f004_setup();
    Flags::override(UiV2::FLAG, true);
    Flags::override(DiarizationSettings::FLAG, true);
    UiV2::reset();
    return $dir;
}

/** @param array<mixed> $query */
function f007_get(F001Client $c, string $path, array $query = []): Response
{
    UiV2::reset();
    return f002_get($c, $path, $query);
}

/** Id'erne på kortene i en liste, i rækkefølge. @return list<int> */
function f007_ids(Response $r): array
{
    preg_match_all('#<a class="card-link" href="/opkald/([0-9]+)#', $r->body, $m);
    return array_map('intval', $m[1]);
}

/** Alle href-værdier (afkodet) i en side. @return list<string> */
function f007_hrefs(Response $r): array
{
    preg_match_all('/href="([^"]*)"/', $r->body, $m);
    return array_map(static fn(string $h): string => html_entity_decode($h, ENT_QUOTES | ENT_HTML5), $m[1]);
}

/** WCAG 2.x-kontrast mellem to #RRGGBB. */
function f007_contrast(string $a, string $b): float
{
    $lum = static function (string $hex): float {
        $c = array_map(static fn(string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
        $c = array_map(static fn(float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$l1, $l2] = [$lum($a), $lum($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
