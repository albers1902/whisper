<?php
declare(strict_types=1);

namespace Whisper;

use RuntimeException;

/**
 * En lille zip-skriver i ren PHP: metoden "stored" (ingen komprimering), CRC32 med hash('crc32b') og UTF-8-filnavne
 * (flag-bit 11). Så afhænger zip-download ikke af ZipArchive-udvidelsen (Team Lead, F002 v0 spørgsmål 5).
 * Kun til små tekstfiler: under 65 535 filer og 4 GB i alt (ZIP_MAX er 2000 transcripts).
 */
final class ZipWriter
{
    /** @var resource */
    private $fh;
    private string $central = '';
    private int $offset = 0;
    private int $count = 0;

    public function __construct(string $file)
    {
        $h = fopen($file, 'xb');
        if ($h === false) {
            throw new RuntimeException('zip-filen kunne ikke oprettes');
        }
        $this->fh = $h;
    }

    public function add(string $name, string $data, int $mtime): void
    {
        if ($this->count >= 65535 || $this->offset + strlen($data) > 0xFFFFFFF0) {
            throw new RuntimeException('zip-filen er for stor');
        }
        $crc = (int) hexdec(hash('crc32b', $data));
        [$time, $date] = self::dos($mtime);
        $size = strlen($data);
        $common = pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0);
        $local = pack('V', 0x04034b50) . $common . $name;
        $this->write($local . $data);
        $this->central .= pack('V', 0x02014b50) . pack('v', 20) . $common . pack('vvvVV', 0, 0, 0, 0, $this->offset) . $name;
        $this->offset += strlen($local) + $size;
        $this->count++;
    }

    public function close(): void
    {
        $this->write($this->central . pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count,
            strlen($this->central), $this->offset, 0));
        fclose($this->fh);
    }

    private function write(string $bytes): void
    {
        if (fwrite($this->fh, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('zip-filen kunne ikke skrives (fuld disk?)');
        }
    }

    /** @return array{0: int, 1: int} DOS-tid og -dato (lokal tid, som zip-programmer forventer) */
    private static function dos(int $ts): array
    {
        $d = getdate(max($ts, 315532800));   // zip kan ikke før 1980
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
