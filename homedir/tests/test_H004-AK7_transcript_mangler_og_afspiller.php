<?php
declare(strict_types=1);

// H004-AK7: status faerdig uden transcript-række viser infoteksten, ikke "på vej" (QA F005 F-4). app.js aktiverer
// kun tidsstempler, når lyden har en kilde, og viser en besked, når play() fejler (QA F005 F-3; læses fra filen).

require_once __DIR__ . '/h004_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Ui;
use Whisper\UiCalls;

f003_setup();
$c = f002_login();
$id = f002_insert();
f002_audio($id);
DB::exec("UPDATE calls SET transcript_status = 'faerdig' WHERE id = ?", [$id]);
H004Check::that('AK7', (int) DB::scalar('SELECT COUNT(*) FROM transcripts WHERE call_id = ?', [$id]) === 0, 'opsætning: ingen transcript-række');

foreach ([true => 'nyt UI (F005)', false => 'gammelt UI'] as $modern => $label) {
    Flags::override(Ui::FLAG, (bool) $modern);
    $r = f002_get($c, '/opkald/' . $id);
    H004Check::that('AK7', $r->status === 200 && str_contains($r->body, UiCalls::TRANSCRIPT_MISSING), "infoteksten vises ($label)");
    H004Check::that('AK7', !str_contains($r->body, 'Transcriptet er på vej') && !str_contains($r->body, 'Venter på transcription'),
        "ingen \"på vej\" ($label)");
}

$js = (string) file_get_contents(__DIR__ . '/../public_html/assets/app.js');
H004Check::that('AK7', str_contains($js, 'audio.networkState !== 3') && str_contains($js, '!audio.error'),
    'app.js: tidsstempler kun med en lydkilde og uden fejl');
H004Check::that('AK7', preg_match("/p\\.catch\\(function \\(err\\) \\{[^}]*\\}[^}]*say\\('Lyden kunne ikke afspilles\\.'\\);\\s*setStamps\\(false\\);/s", $js) === 1,
    'app.js: en fejl fra play() vises i #live, og stemplerne gøres inaktive');
H004Check::that('AK7', !str_contains($js, 'p.catch(function () {})'), 'app.js: fejlen sluges ikke længere');

H004Check::done();
