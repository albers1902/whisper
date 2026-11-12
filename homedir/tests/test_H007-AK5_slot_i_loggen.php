<?php
declare(strict_types=1);

// H007-AK5: hver loglinje fra cron/transcribe.php har slot-nummeret, fx "transcribe[2]: opkald 120: færdig (…)".
// Linjeformatet er ellers uændret (UTC-tidsstempel foran). Kører scriptet som egen proces med en falsk motor.

require_once __DIR__ . '/h007_lib.php';

use Whisper\Lock;
use Whisper\Transcription\Slots;

if (DIRECTORY_SEPARATOR !== '/') {
    // Den falske motor er et sh-script (som H005/H006); testene køres på DEV (Linux)
    H007Check::that('AK5', false, 'kræver Unix (sh)');
    H007Check::done();
}

$ts = '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z';

/** @return list<string> */
$lines = static function (string $out): array {
    return array_values(array_filter(explode("\n", trim($out)), static fn(string $l): bool => $l !== ''));
};

// Slot 1: et hængende opkald og to ventende, som den falske motor transcriberer
$var = f002_setup();
h006_fake_python($var, $var . '/argv.txt');
$stale = h007_stale_now();
$calls = h006_three_calls();
[$code, $out] = h007_cron($var);
$l = $lines($out);
H007Check::that('AK5', $code === 0 && $l !== [], 'kørslen i slot 1 skrev linjer (exit ' . $code . ')');
H007Check::that('AK5', preg_match('/^' . $ts . ' transcribe\[1\]: 1 hængende opkald sat tilbage$/', $l[0] ?? '') === 1,
    'stale-linjen: ' . ($l[0] ?? ''));
foreach ($calls as $c) {
    $hit = array_filter($l, static fn(string $x): bool => preg_match('/^' . $ts . ' transcribe\[1\]: opkald ' . $c['id']
        . ': færdig \(lyd 2:05, tid [0-9:]+, RTF [0-9,]+, threads [0-9]+, beam 1, vad on, mode sequential\)$/', $x) === 1);
    H007Check::that('AK5', count($hit) === 1, 'opkald ' . $c['id'] . ' har sin færdig-linje med slot 1');
}
H007Check::that('AK5', count(array_filter($l, static fn(string $x): bool => preg_match('/^' . $ts . ' transcribe\[1\]: batch: /', $x) === 1)) >= 1,
    'batch-linjen har slot 1');
H007Check::that('AK5', array_filter($l, static fn(string $x): bool => preg_match('/^' . $ts . ' transcribe\[[1-4]\]: /', $x) !== 1) === [],
    'ingen linje uden slot-præfiks: ' . implode(' | ', $l));

// Slot 2: slot 1 er optaget af en anden kørsel
$held = Lock::tryAcquire(Slots::lockName(1));
$stale2 = h007_stale_now();
$more = h006_three_calls();
[$code, $out] = h007_cron($var);
$l = $lines($out);
H007Check::that('AK5', $code === 0 && $l !== [] && array_filter($l, static fn(string $x): bool
    => preg_match('/^' . $ts . ' transcribe\[2\]: /', $x) !== 1) === [], 'i slot 2 har alle linjer transcribe[2]: ' . implode(' | ', $l));
H007Check::that('AK5', count(array_filter($l, static fn(string $x): bool => str_contains($x, 'transcribe[2]: opkald ' . $more[0]['id'] . ': færdig'))) === 1,
    'færdig-linjen i slot 2');
$held?->release();

H007Check::done();
