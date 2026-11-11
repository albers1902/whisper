<?php
declare(strict_types=1);

// F002-AK8 (stor): zip med 2000 transcripts på ca. 30 KB hver (tekst + segmenter) bygges med flad hukommelse —
// transcripts læses 50 ad gangen (QA F-4, security S3). Egen testfil, så known-failures kan ramme præcis den.

require_once __DIR__ . '/f002_lib.php';

use Whisper\Calls;
use Whisper\DB;

f002_setup();
$c = f002_login();

$words = ['strøm', 'måler', 'regning', 'adresse', 'flytning', 'aftale', 'kunde', 'betaling', 'tak', 'hej'];
$segments = [];
$text = '';
for ($i = 0; strlen($text) < 15000; $i++) {
    $line = implode(' ', array_map(static fn(int $k): string => $words[($i + $k) % 10], range(0, 11))) . '.';
    $segments[] = ['start' => $i * 4.0, 'end' => $i * 4.0 + 3.5, 'text' => $line];
    $text .= $line . ' ';
}
$segJson = (string) json_encode($segments, JSON_UNESCAPED_UNICODE);
$n = Calls::ZIP_MAX;
$base = strtotime('2026-10-01 00:00:00 UTC');
for ($b = 0; $b < $n; $b += 100) {
    $vals = [];
    $p = [];
    for ($i = $b; $i < min($n, $b + 100); $i++) {
        $vals[] = "('zendesk', ?, ?, 'inbound', '+45', 'A', ?, 60, 'u', 'hentet', 'faerdig', NOW(), NOW())";
        array_push($p, 'stor-' . $i, 100000 + $i, gmdate('Y-m-d H:i:s', $base + $i * 60));
    }
    DB::exec('INSERT INTO calls (`source`, external_id, ticket_id, direction, customer_phone, agent_name, started_at, duration_sec, '
        . 'recording_url, audio_status, transcript_status, created_at, updated_at) VALUES ' . implode(', ', $vals), $p);
}
DB::exec("INSERT INTO transcripts (call_id, `text`, segments_json, model, `language`, created_at) "
    . "SELECT id, ?, ?, 'turbo', 'da', NOW() FROM calls WHERE external_id LIKE 'stor-%'", [$text, $segJson]);
F002Check::that('AK8', (int) DB::scalar('SELECT COUNT(*) FROM transcripts') === $n, "$n transcripts à ca. "
    . intdiv(strlen($text) + strlen($segJson), 1024) . ' KB');

gc_collect_cycles();
memory_reset_peak_usage();
$before = memory_get_usage();
$t0 = microtime(true);
$r = f002_get($c, '/opkald/download');
$peak = memory_get_peak_usage() - $before;
$took = microtime(true) - $t0;
F002Check::that('AK8', $r->status === 200 && $r->file !== null, sprintf('zip bygget på %.1f s', $took));
F002Check::that('AK8', $peak < 24 * 1024 * 1024, sprintf('hukommelsen er flad: %.1f MB ekstra (loft 24 MB; data i alt %.0f MB)',
    $peak / 1048576, $n * (strlen($text) + strlen($segJson)) / 1048576));
$fh = fopen((string) $r->file, 'rb');
fseek($fh, -12, SEEK_END);
$cnt = unpack('v', (string) fread($fh, 2))[1] ?? 0;
fclose($fh);
F002Check::that('AK8', $cnt === $n, "zip-filen har $n filer (fik $cnt)");
@unlink((string) $r->file);

F002Check::done();
