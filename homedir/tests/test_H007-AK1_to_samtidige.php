<?php
declare(strict_types=1);

// H007-AK1: uden workers i config kan to transcribe-kørsler køre samtidig (slot 1 og 2), og en tredje afslutter
// stille. workers = 1 giver kun én samtidig kørsel. Slot 1 er den gamle lås (var/locks/transcribe.lock).

require_once __DIR__ . '/h007_lib.php';

use Whisper\Lock;
use Whisper\Transcription\Slots;
use Whisper\Transcription\WhisperSettings;

$var = f002_setup();

H007Check::that('AK1', WhisperSettings::fromSection([], 6)->workers === 2, 'uden workers i config: 2');
H007Check::that('AK1', Slots::lockName(1) === 'transcribe' && Slots::lockName(2) === 'transcribe-2',
    'slot 1 = transcribe.lock (som før H007), slot 2 = transcribe-2.lock');

// Slotvalget i processen
$a = Slots::acquire(2);
$b = Slots::acquire(2);
$c = Slots::acquire(2);
H007Check::that('AK1', $a !== null && $a[0] === 1 && $b !== null && $b[0] === 2 && $c === null,
    'workers 2: første kørsel får slot 1, anden slot 2, tredje intet');
$a[1]?->release();
$d = Slots::acquire(2);
H007Check::that('AK1', $d !== null && $d[0] === 1, 'et frigivet slot kan tages igen');
$d[1]?->release();
$b[1]?->release();

$one = Slots::acquire(1);
$two = Slots::acquire(1);
H007Check::that('AK1', $one !== null && $one[0] === 1 && $two === null, 'workers 1: kun én samtidig kørsel');
$one[1]?->release();

// Hele vejen gennem cron/transcribe.php (egen proces). Et hængende opkald viser, at kørslen kom forbi slotvalget.
$held1 = Lock::tryAcquire(Slots::lockName(1));
$stale = h007_stale_now();
[$code, $out] = h007_cron($var);
H007Check::that('AK1', $code === 0 && str_contains($out, 'transcribe[2]: 1 hængende opkald sat tilbage'),
    'slot 1 optaget: cron-kørslen tager slot 2 (' . trim($out) . ')');
H007Check::that('AK1', h007_status($stale) === 'ventende', 'kørslen i slot 2 har arbejdet');

$held2 = Lock::tryAcquire(Slots::lockName(2));
$stale2 = h007_stale_now();
[$code, $out] = h007_cron($var);
H007Check::that('AK1', $code === 0 && trim($out) === '', 'slot 1 og 2 optaget: tredje kørsel afslutter stille med exit 0');
H007Check::that('AK1', h007_status($stale2) === 'transcriberer', 'tredje kørsel har ikke rørt databasen');
$held1?->release();
$held2?->release();

H007Check::done();
