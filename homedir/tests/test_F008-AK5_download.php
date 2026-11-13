<?php
declare(strict_types=1);

// F008-AK5: hele leadet kan hentes som én .txt (alle opkald i rækkefølge med en overskrift pr. opkald; opkald uden
// transcript står med "(intet transcript)") og som .zip med lyd og .txt. Lyden streames ind i zip'en (CRC passer). Én
// download ad gangen (samme lås som listens zip). Over loftet står det i LÆS-MIG.txt.

require_once __DIR__ . '/f008_lib.php';

use Whisper\Lock;
use Whisper\ZipWriter;

f008_setup();
$c = f002_login();
$a = f008_call(['lead_id' => 901, 'is_success' => 1, 'started_at' => '2026-11-03 08:12:00', 'agent_name' => 'Sara', 'transcript_status' => 'faerdig']);
f008_done($a, 'Hej', [['start' => 1.0, 'end' => 2.0, 'text' => 'Hej det er Sara', 'speaker' => 0, 'role' => 'agent']]);
f008_call(['lead_id' => 901, 'is_success' => 1, 'started_at' => '2026-11-03 10:40:00', 'disposition' => 'busy']);
$q = f008_call(['lead_id' => 901, 'is_success' => 1, 'started_at' => '2026-11-04 10:40:00', 'transcript_status' => 'ventende']);
$b = f008_call(['lead_id' => 901, 'is_success' => 1, 'started_at' => '2026-11-06 11:09:00', 'agent_name' => 'Mads', 'transcript_status' => 'faerdig']);
f008_done($b, 'Vi sender faktura');

$page = f007_get($c, '/lead/901');
F008Check::that('AK5', str_contains($page->body, 'href="/lead/901/transcript"') && str_contains($page->body, 'href="/lead/901/download" aria-describedby="lead-zip-hjaelp"')
    && str_contains($page->body, 'Lyd og transcripts for alle opkald på leadet.'), 'knapperne på lead-siden');

$t = f007_get($c, '/lead/901/transcript');
$txt = $t->fileContents();
F008Check::that('AK5', $t->status === 200 && $t->header('Content-Type') === 'text/plain; charset=utf-8'
    && $t->header('Content-Disposition') === 'attachment; filename="lead_901.txt"', '.txt: header og filnavn');
$p1 = strpos($txt, '=== Opkald 1 af 4 · 03.11.2026 09:12 (dansk tid) · Udgående · Agent: Sara');
$p2 = strpos($txt, '=== Opkald 2 af 4');
$p3 = strpos($txt, '=== Opkald 3 af 4');
$p4 = strpos($txt, '=== Opkald 4 af 4 · 06.11.2026 12:09 (dansk tid) · Udgående · Agent: Mads');
F008Check::that('AK5', $p1 !== false && $p2 !== false && $p3 !== false && $p4 !== false && $p1 < $p2 && $p2 < $p3 && $p3 < $p4, '.txt: en overskrift pr. opkald i rækkefølge');
F008Check::that('AK5', str_contains($txt, "[00:01] Agent: Hej det er Sara") && str_contains($txt, '(ikke besvaret: optaget)')
    && str_contains($txt, '(intet transcript)') && str_contains($txt, '[00:00] Vi sender faktura'), '.txt: transcripts, ubesvaret og uden transcript');
@unlink((string) $t->file);

$z = f007_get($c, '/lead/901/download');
$files = f002_unzip($z->fileContents());
$names = array_keys($files);
sort($names);
F008Check::that('AK5', $z->status === 200 && $z->header('Content-Type') === 'application/zip'
    && $z->header('Content-Disposition') === 'attachment; filename="lead_901.zip"', '.zip: header og filnavn');
F008Check::that('AK5', $names === ['2026-11-03_0912_ud_adversus_lead901.mp3', '2026-11-03_0912_ud_adversus_lead901.txt',
    '2026-11-06_1209_ud_adversus_lead901.mp3', '2026-11-06_1209_ud_adversus_lead901.txt'], '.zip: lyd og .txt pr. opkald: ' . implode(', ', $names));
$allOk = true;
foreach ($files as $f) {
    $allOk = $allOk && $f['crc_ok'];
}
F008Check::that('AK5', $allOk && $files['2026-11-03_0912_ud_adversus_lead901.mp3']['data'] === 'ID3' . str_repeat("\x01\x02\x03\x04", 256),
    '.zip: lyden er streamet uændret, og CRC passer');
@unlink((string) $z->file);

// Én ad gangen: samme lås som listens zip
$lock = Lock::tryAcquire('zip');
$busy = f007_get($c, '/lead/901/download');
F008Check::that('AK5', $busy->status === 429 && $busy->file === null && str_contains($busy->body, 'Der laves allerede en zip-fil'), 'låst: 429');
$lock?->release();

// Ukendt lead og lead uden opkald: 404
F008Check::that('AK5', f007_get($c, '/lead/999998/download')->status === 404 && f007_get($c, '/lead/999998/transcript')->status === 404, 'ukendt lead: 404');

// ZipWriter::addFile direkte: samme bytes og CRC som add()
$dir = sys_get_temp_dir() . '/f008-zip-' . bin2hex(random_bytes(4));
mkdir($dir);
$src = $dir . '/lyd.bin';
file_put_contents($src, random_bytes(200000));
$zw = new ZipWriter($dir . '/x.zip');
$zw->addFile('lyd.bin', $src, time());
$zw->add('note.txt', 'hej', time());
$zw->close();
$un = f002_unzip((string) file_get_contents($dir . '/x.zip'));
F008Check::that('AK5', ($un['lyd.bin']['data'] ?? '') === file_get_contents($src) && $un['lyd.bin']['crc_ok'] && ($un['note.txt']['data'] ?? '') === 'hej',
    'addFile: indhold og CRC');
F008Check::done();
