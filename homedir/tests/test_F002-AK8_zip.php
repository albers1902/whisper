<?php
declare(strict_types=1);

// F002-AK8: download af alle (zip) indeholder netop de færdige transcripts i filtret, med de rigtige filnavne, og
// kræver login. Zip-filen skrives i ren PHP (ingen ZipArchive) og kan læses af andre programmer.

require_once __DIR__ . '/f002_lib.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\Request;
use Whisper\Session;
use Whisper\ZipWriter;

$var = f002_setup();

$anon = (new App())->handle(new Request('GET', '/opkald/download', [], '10.0.0.9'), new Session(new ArraySessionDriver()));
F002Check::that('AK8', $anon->status === 303 && $anon->header('Location') === '/login' && $anon->file === null, 'anonym: omdirigeret til /login');

$c = f002_login();
$empty = f002_get($c, '/opkald/download');
F002Check::that('AK8', $empty->status === 200 && $empty->file === null && str_contains($empty->body, 'ingen færdige transcripts'),
    'ingen færdige: en side, ikke en tom zip');

$a = f002_insert(['started_at' => '2026-10-05 08:03:00', 'direction' => 'inbound', 'ticket_id' => 111]);
$b = f002_insert(['started_at' => '2026-10-06 12:30:00', 'direction' => 'outbound', 'ticket_id' => 222]);
$d = f002_insert(['started_at' => '2026-10-06 12:30:00', 'direction' => 'outbound', 'ticket_id' => 222]);   // samme navn
$pending = f002_insert(['started_at' => '2026-10-07 09:00:00', 'direction' => 'inbound', 'ticket_id' => 333]);
$other = f002_insert(['started_at' => '2026-11-20 09:00:00', 'direction' => 'inbound', 'ticket_id' => 444]);
f002_transcript($a, 'Første samtale om strøm.');
f002_transcript($b, 'Anden samtale.');
f002_transcript($d, 'Tredje samtale.');
f002_transcript($other, 'Uden for perioden.');

$r = f002_get($c, '/opkald/download', ['fra' => '2026-10-01', 'til' => '2026-10-31']);
F002Check::that('AK8', $r->status === 200 && $r->header('Content-Type') === 'application/zip', 'zip-svar');
F002Check::that('AK8', preg_match('/^attachment; filename="transcripts_[0-9]{4}-[0-9]{2}-[0-9]{2}\.zip"$/', (string) $r->header('Content-Disposition')) === 1,
    'Content-Disposition med .zip-filnavn');
$zip = $r->fileContents();
F002Check::that('AK8', $r->header('Content-Length') === (string) strlen($zip), 'Content-Length passer');
$files = f002_unzip($zip);
$names = array_keys($files);
sort($names);
$want = ['2026-10-05_1003_ind_111.txt', '2026-10-06_1430_ud_222.txt', '2026-10-06_1430_ud_222_' . $b . '.txt'];   // samme navn: den ældste (lavere id) får id'et på
sort($want);
F002Check::that('AK8', $names === $want, 'netop de færdige i filtret med rigtige filnavne: ' . implode(', ', $names));
F002Check::that('AK8', array_filter($files, static fn(array $f): bool => !$f['crc_ok']) === [], 'CRC32 passer for alle filer');
F002Check::that('AK8', str_contains($files['2026-10-05_1003_ind_111.txt']['data'] ?? '', "Retning:  Indgående")
    && str_contains($files['2026-10-05_1003_ind_111.txt']['data'] ?? '', 'Første samtale om strøm.'), 'filen har hoved-blok og tekst (UTF-8)');
F002Check::that('AK8', substr($zip, -22, 4) === "PK\x05\x06", 'slutpost (end of central directory) findes');
F002Check::that('AK8', unpack('v', substr($zip, -12, 2))[1] === 3, 'central directory har 3 poster');
@unlink((string) $r->file);

$r2 = f002_get($c, '/opkald/download', ['retning' => 'inbound']);
$n2 = array_keys(f002_unzip($r2->fileContents()));
sort($n2);
F002Check::that('AK8', $n2 === ['2026-10-05_1003_ind_111.txt', '2026-11-20_1000_ind_444.txt'], 'retnings-filter (vintertid: 10:00)');
@unlink((string) $r2->file);
F002Check::that('AK8', (fileperms($var . '/tmp') & 0777) === 0700, 'midlertidig mappe er 0700');

// ZipArchive kan læse den, hvis udvidelsen findes (ikke et krav)
if (class_exists('ZipArchive')) {
    $tmp = $var . '/check.zip';
    $zw = new ZipWriter($tmp);
    $zw->add('æøå.txt', "Hej æøå\n", 1790000000);
    $zw->close();
    $za = new ZipArchive();
    F002Check::that('AK8', $za->open($tmp) === true && $za->getFromName('æøå.txt') === "Hej æøå\n", 'ZipArchive læser zip-filen');
    $za->close();
}

// Højst én zip ad gangen: mens en anden laves, svares 429 med en dansk besked (security S3)
$busy = \Whisper\Lock::tryAcquire('zip');
$r3 = f002_get($c, '/opkald/download');
F002Check::that('AK8', $r3->status === 429 && $r3->file === null && str_contains($r3->body, 'Der laves allerede en zip-fil')
    && $r3->header('Retry-After') === '30', 'anden zip samtidig: 429 med dansk besked');
$busy?->release();
$r4 = f002_get($c, '/opkald/download');
F002Check::that('AK8', $r4->status === 200 && $r4->file !== null, 'når den første er færdig, kan en ny laves');
@unlink((string) $r4->file);
$log = (string) @file_get_contents($var . '/adgang.log');
F002Check::that('AK8', preg_match('/ bruger=[0-9]+ zip antal=3 filter=[0-9a-f]{16}\n/', $log) === 1, 'zip-hentningen logges med antal og filter-hash');

// Højst ZIP_MAX filer pr. download
F002Check::that('AK8', \Whisper\Calls::ZIP_MAX === 2000, 'loftet er 2000');
F002Check::done();
