<?php
declare(strict_types=1);

namespace Whisper\Transcription;

use Whisper\Lock;
use Whisper\Paths;

/**
 * Op til [whisper] workers samtidige transcribe-kørsler, hver med sin egen lås: slot 1 = var/locks/transcribe.lock
 * (samme fil som med én kørsel, så workers = 1 er den gamle opførsel), slot n = transcribe-n.lock.
 *
 * Den, der holder et slot, skriver id'erne på de opkald, den har claimet, i sin låsefil. Stale-reglen skåner dem, der
 * står i en låsefil, som en levende proces holder. Det er nok: kun et langt opkald, der kører alene, kan legitimt være
 * over 2 t i "transcriberer", og dets id står i filen, før motoren starter, og røres ikke, mens den kører. Dør en
 * proces, slipper kernen låsen, og filens indhold tæller ikke længere.
 */
final class Slots
{
    /** Alle slots tjekkes altid, også over [whisper] workers, så en ændring af workers midt i en kørsel ikke åbner et hul */
    public const MAX = 4;

    public static function lockName(int $slot): string
    {
        return $slot === 1 ? 'transcribe' : 'transcribe-' . $slot;
    }

    /** Motorens stderr og detaljen om en afbrudt batch: én fil pr. slot, så to motorer ikke overskriver hinanden */
    public static function errorFile(int $slot): string
    {
        return Paths::dir('whisper') . '/' . ($slot === 1 ? 'sidste-fejl.log' : 'sidste-fejl-' . $slot . '.log');
    }

    /**
     * Det første ledige slot af 1..$workers, eller null, hvis alle er optaget. Låsefilen tømmes, så indhold fra en død
     * proces ikke står tilbage.
     *
     * @return array{0: int, 1: Lock}|null
     */
    public static function acquire(int $workers): ?array
    {
        $workers = max(1, min(self::MAX, $workers));
        for ($slot = 1; $slot <= $workers; $slot++) {
            $lock = Lock::tryAcquire(self::lockName($slot));
            if ($lock !== null) {
                $lock->write('');
                return [$slot, $lock];
            }
        }
        return null;
    }

    /**
     * Id'er, som andre levende transcribe-kørsler har claimet (alle slots undtagen $mine).
     *
     * @return list<int>
     */
    public static function heldByOthers(int $mine): array
    {
        $ids = [];
        for ($slot = 1; $slot <= self::MAX; $slot++) {
            if ($slot === $mine) {
                continue;
            }
            $content = Lock::heldContent(self::lockName($slot));
            if ($content === null) {
                continue;
            }
            foreach (self::parse($content) as $id) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /** @param list<int> $ids */
    public static function format(array $ids): string
    {
        return $ids === [] ? '' : implode(',', $ids) . "\n";
    }

    /** @return list<int> */
    public static function parse(string $content): array
    {
        $ids = [];
        foreach (explode(',', trim($content)) as $part) {
            if (preg_match('/^[0-9]{1,18}$/', $part) === 1) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }
}
