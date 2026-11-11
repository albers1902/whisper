<?php
declare(strict_types=1);

// F002-AK7: /opkald/{id}/lyd kræver login, understøtter Range (206 med korrekt Content-Range) og afviser ugyldigt id og
// sti-manipulation.

require_once __DIR__ . '/f002_lib.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\CallPages;
use Whisper\Calls;
use Whisper\DB;
use Whisper\Request;
use Whisper\Session;

$var = f002_setup();
$bytes = 'ID3' . random_bytes(1000);   // 1003 bytes
$id = f002_insert(['started_at' => '2026-10-05 08:00:00']);
f002_audio($id, $bytes);
$size = strlen($bytes);

$anon = (new App())->handle(new Request('GET', '/opkald/' . $id . '/lyd', [], '10.0.0.9'), new Session(new ArraySessionDriver()));
F002Check::that('AK7', $anon->status === 303 && $anon->header('Location') === '/login', 'anonym: omdirigeret til /login');
F002Check::that('AK7', $anon->file === null && $anon->body === '', 'anonym: ingen lyd i svaret');

$c = f002_login();
$r = f002_get($c, '/opkald/' . $id . '/lyd');
F002Check::that('AK7', $r->status === 200 && $r->fileContents() === $bytes, 'hele filen: 200');
F002Check::that('AK7', $r->header('Content-Type') === 'audio/mpeg' && $r->header('Accept-Ranges') === 'bytes', 'Content-Type og Accept-Ranges');
F002Check::that('AK7', $r->header('Cache-Control') === 'private, no-store', 'Cache-Control: private, no-store');
F002Check::that('AK7', $r->header('Content-Length') === (string) $size, 'Content-Length');

$cases = [
    ['bytes=0-9', 206, 0, 9],
    ['bytes=100-', 206, 100, $size - 1],
    ['bytes=-5', 206, $size - 5, $size - 1],
    ['bytes=10-99999', 206, 10, $size - 1],
    ['bytes=-99999', 206, 0, $size - 1],
];
foreach ($cases as [$h, $st, $a, $b]) {
    $rr = f002_get($c, '/opkald/' . $id . '/lyd', [], ['range' => $h]);
    F002Check::that('AK7', $rr->status === $st && $rr->header('Content-Range') === "bytes $a-$b/$size"
        && $rr->fileContents() === substr($bytes, $a, $b - $a + 1) && $rr->header('Content-Length') === (string) ($b - $a + 1),
        "Range $h → $st, bytes $a-$b/$size");
}
foreach (['bytes=' . $size . '-', 'bytes=50-10', 'bytes=-0'] as $h) {
    $rr = f002_get($c, '/opkald/' . $id . '/lyd', [], ['range' => $h]);
    F002Check::that('AK7', $rr->status === 416 && $rr->header('Content-Range') === "bytes */$size" && $rr->file === null, "Range $h → 416");
}
foreach (['bytes=0-1,5-9', 'items=0-5', 'bytes=abc', 'bytes=0-99999999999999999999999'] as $h) {
    F002Check::that('AK7', f002_get($c, '/opkald/' . $id . '/lyd', [], ['range' => $h])->status === 200, "Range \"$h\" ignoreres: 200");
}

// Ugyldigt id
foreach (['/opkald/0/lyd', '/opkald/abc/lyd', '/opkald/-1/lyd', '/opkald/1.5/lyd', '/opkald/99999999999/lyd', '/opkald/999999/lyd',
    '/opkald/' . $id . '/lyd/x', '/opkald/' . $id . '/../lyd'] as $p) {
    $rr = f002_get($c, $p);
    F002Check::that('AK7', $rr->status === 404 && $rr->file === null, "$p → 404");
}

// Sti-manipulation via databasen: kun stier, appen selv skriver, under var/recordings
file_put_contents($var . '/hemmelig.mp3', 'ID3hemmelig');
foreach (['../hemmelig.mp3', 'recordings/../hemmelig.mp3', '/etc/passwd', 'recordings/2026/10/../../../hemmelig.mp3',
    'recordings/2026/10/' . $id . '.mp3/../../../../hemmelig.mp3', 'recordings/2026/10/x.php', ''] as $bad) {
    DB::exec('UPDATE calls SET audio_path = ? WHERE id = ?', [$bad, $id]);
    $rr = f002_get($c, '/opkald/' . $id . '/lyd');
    F002Check::that('AK7', $rr->status === 404 && $rr->file === null, 'audio_path "' . $bad . '" → 404');
}
if (function_exists('symlink') && DIRECTORY_SEPARATOR === '/') {
    $link = $var . '/recordings/2026/10/' . ($id + 1) . '.mp3';
    @symlink($var . '/hemmelig.mp3', $link);
    F002Check::that('AK7', Calls::audioFile('recordings/2026/10/' . ($id + 1) . '.mp3') === null, 'symlink ud af var/recordings afvises');
}
DB::exec('UPDATE calls SET audio_path = ? WHERE id = ?', ['recordings/2026/10/' . $id . '.mp3', $id]);
DB::exec("UPDATE calls SET audio_status = 'fejlet' WHERE id = ?", [$id]);
F002Check::that('AK7', f002_get($c, '/opkald/' . $id . '/lyd')->status === 404, 'lyd, der ikke er markeret hentet: 404');

// Kun GET/HEAD
$post = $c->post('/opkald/' . $id . '/lyd');
F002Check::that('AK7', $post->status === 405, 'POST → 405');
F002Check::that('AK7', CallPages::range('bytes=0-0', 0) === false, 'tom fil: 416');

F002Check::done();
