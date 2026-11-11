<?php
declare(strict_types=1);

// F005-AK4: badges efter UX §3.2 med tekst, klasse og en "Ukendt"-fallback; samme kortlægning i liste og detalje.

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;
use Whisper\Ui;

f005_setup();
$c = f002_login();

$cases = [
    // [audio_status, transcript_status, forventet klasse, forventet tekst]
    ['venter', 'ventende', 's-ventende', 'Venter på lyd'],
    ['fejlet', 'ventende', 's-lyd', 'Lyd mangler'],
    ['hentet', 'ventende', 's-ventende', 'Venter'],
    ['hentet', 'transcriberer', 's-transcriberer', 'Transcriberer'],
    ['hentet', 'faerdig', 's-faerdig', 'Færdig'],
    ['hentet', 'fejlet', 's-fejlet', 'Fejlet'],
    ['hentet', 'ingen', 's-ukendt', 'Ukendt'],
];
foreach ($cases as [$audio, $t, $class, $text]) {
    F005Check::that('AK4', Ui::status($audio, $t) === [$class, $text], "$audio/$t → $class «$text»");
}
F005Check::that('AK4', Ui::status('hentet', 'faerdig', false) === ['s-lyd', 'Lyd mangler'], 'detalje: hentet, men filen mangler → Lyd mangler');
F005Check::that('AK4', Ui::status('noget', 'faerdig') === ['s-ukendt', 'Ukendt'], 'ukendt lydstatus → Ukendt');
F005Check::that('AK4', Ui::badge(['audio_status' => 'hentet', 'transcript_status' => '<x>']) === '<span class="badge s-ukendt">Ukendt</span>',
    'ukendt værdi escapes aldrig ind i klassen');

// Samme kortlægning i liste og detalje
$ids = [];
foreach (array_slice($cases, 0, 6) as $i => [$audio, $t]) {
    $id = f002_insert(['started_at' => '2026-10-0' . ($i + 1) . ' 10:00:00', 'transcript_status' => $t === 'faerdig' ? 'ventende' : $t]);
    if ($audio === 'hentet') {
        f002_audio($id);
    } else {
        DB::exec('UPDATE calls SET audio_status = ? WHERE id = ?', [$audio, $id]);
    }
    if ($t === 'faerdig') {
        f002_transcript($id, 'Tekst.');
    }
    $ids[$id] = $cases[$i];
}
$list = f002_get($c, '/')->body;
foreach ($ids as $id => [$audio, $t, $class, $text]) {
    $badge = '<span class="badge ' . $class . '">' . $text . '</span>';
    F005Check::that('AK4', str_contains($list, $badge), "liste: $badge");
    F005Check::that('AK4', str_contains(f002_get($c, '/opkald/' . $id)->body, $badge), "detalje: $badge");
}
F005Check::done();
