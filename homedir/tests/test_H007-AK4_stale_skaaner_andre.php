<?php
declare(strict_types=1);

// H007-AK4: stale-reglen rører ikke et opkald i "transcriberer" over 2 t, når en anden proces holder dens slot.
// Opkaldet ryddes, når ingen proces holder det. Uden H007 ville en anden kørsel nulstille et langt opkald (lovligt op
// til 4 × varighed), mens motoren stadig arbejdede på det.

require_once __DIR__ . '/h007_lib.php';

use Whisper\DB;
use Whisper\Lock;
use Whisper\Transcription\BatchEngine;
use Whisper\Transcription\Slots;
use Whisper\Transcription\Transcriber;

$var = f002_setup();
$now = F002_NOW;
$t = new Transcriber(new FakeEngine(), static fn(): int => $now, static fn(string $l) => null);
$old = gmdate('Y-m-d H:i:s', $now - 3 * 3600);
$live = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => 1, 'claimed_at' => $old, 'duration_sec' => 4000]);
$dead = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => 1, 'claimed_at' => $old]);
$liveMax = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => 3, 'claimed_at' => $old, 'duration_sec' => 4000]);

// "En anden proces" i slot 2 kører $live og $liveMax (id'erne i dens låsefil); denne kørsel har slot 1
[$mine, $myLock] = Slots::acquire(2) ?? [0, null];
$other = Lock::tryAcquire(Slots::lockName(2));
$other?->write(Slots::format([$live, $liveMax]));
H007Check::that('AK4', $mine === 1 && $other !== null, 'denne kørsel har slot 1, en anden holder slot 2');
$spare = Slots::heldByOthers(1);
sort($spare);
$want = [$live, $liveMax];
sort($want);
H007Check::that('AK4', $spare === $want, 'heldByOthers læser id\'erne fra det holdte slot');
H007Check::that('AK4', $t->resetStale($spare) === 1, 'kun ét opkald ryddet');
H007Check::that('AK4', h007_status($live) === 'transcriberer' && h007_status($liveMax) === 'transcriberer',
    'opkald, som slot 2 kører, røres ikke (heller ikke ved 3 forsøg)');
H007Check::that('AK4', h007_status($dead) === 'ventende', 'opkald uden levende ejer ryddes som før');

// Processen i slot 2 dør: kernen slipper låsen, filens indhold tæller ikke længere
$other?->release();
$spare = Slots::heldByOthers(1);
H007Check::that('AK4', $spare === [], 'frigivet slot: ingen skånes, selv om låsefilen stadig har id\'erne');
H007Check::that('AK4', $t->resetStale($spare) === 2, 'begge ryddes nu');
H007Check::that('AK4', h007_status($live) === 'ventende' && h007_status($liveMax) === 'fejlet', 'tilbage til ventende / opgivet efter 3 forsøg');

// Alle fire slots tjekkes, også over workers (workers kan være sat ned, mens en kørsel i slot 4 stadig arbejder)
$x = f002_insert(['transcript_status' => 'transcriberer', 'transcript_attempts' => 1, 'claimed_at' => $old]);
$four = Lock::tryAcquire(Slots::lockName(4));
$four?->write(Slots::format([$x]));
H007Check::that('AK4', Slots::heldByOthers(1) === [$x] && $t->resetStale(Slots::heldByOthers(1)) === 0 && h007_status($x) === 'transcriberer',
    'slot 4 skånes, selv om workers er 2');
$four?->release();
$myLock?->release();

// Transcriber melder et langt opkald i låsefilen, før motoren starter, og tømmer den bagefter: en anden kørsel kan
// altså altid se, at opkaldet er i gang
f002_setup();
$long = f002_insert(['started_at' => '2026-10-02 08:00:00', 'duration_sec' => 4000]);
f002_audio($long);
$acq = Slots::acquire(2);
H007Check::that('AK4', $acq !== null && $acq[0] === 1, 'Transcriberen kører i slot 1');
$lock = $acq[1] ?? null;
$seen = new stdClass();
$seen->ids = null;
$engine = new class ($seen) implements BatchEngine {
    public function __construct(private readonly stdClass $seen)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function label(): string
    {
        return '';
    }

    public function batchSize(): int
    {
        return 10;
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        // Set fra slot 2 mens motoren kører
        $this->seen->ids = Slots::heldByOthers(2);
        return (new FakeEngine())->result;
    }

    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float
    {
        return null;
    }
};
$t = new Transcriber($engine, static fn(): int => $now, static fn(string $l) => null, null,
    static function (array $ids) use ($lock): void {
        $lock?->write(Slots::format($ids));
    });
H007Check::that('AK4', $t->runBatch() === 1 && h007_status($long) === 'faerdig', 'det lange opkald er kørt alene og gemt');
H007Check::that('AK4', $seen->ids === [$long], 'mens motoren kørte, så slot 2 opkaldet i slot 1\'s låsefil');
H007Check::that('AK4', Slots::heldByOthers(2) === [], 'efter kørslen er slot 1-låsefilen tom, mens låsen stadig holdes');
$lock?->release();
DB::exec('DELETE FROM calls');

H007Check::done();
