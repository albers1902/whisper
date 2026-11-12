<?php
declare(strict_types=1);

// H004-AK6: søgeuddraget centreres på første ORDSTART, der matcher, ikke på første delstreng (QA F005 F-1). For "tal"
// i en tekst, hvor "totalt" kommer langt før ordet "tal", indeholder uddraget "tal" og fremhæver det. Også når ordet
// foran er dansk (æ/ø/å er bogstaver, så "blåtal" er ikke en ordstart), og når ordet står med stort.

require_once __DIR__ . '/h004_lib.php';

use Whisper\Calls;
use Whisper\Ui;

f003_setup();
$filler = str_repeat('og så videre ', 60);   // ca. 780 tegn: "tal" ligger langt uden for et vindue fra "totalt"

$cases = [
    'totalt før tal'  => 'Det var totalt forvirrende. ' . $filler . 'Hun nævnte et tal for os.',
    'blåtal før tal'  => 'Et blåtal stod der. ' . $filler . 'Hun nævnte et tal for os.',
    'Tal med stort'   => 'Det var totalt forvirrende. ' . $filler . 'Tal er vigtige.',
];
$ids = [];
foreach ($cases as $label => $text) {
    $ids[$label] = f003_call([], $text);
}
$snips = Calls::snippets(array_values($ids), 'tal');
$terms = Ui::terms('tal');
foreach ($ids as $label => $id) {
    $s = $snips[$id] ?? null;
    $html = $s !== null ? Ui::snippet($s['text'], $s['cut_start'], $s['cut_end'], $terms) : '';
    H004Check::that('AK6', $s !== null && $s['cut_start'], "$label: vinduet starter inde i teksten (ikke ved \"totalt\")");
    H004Check::that('AK6', preg_match('/<mark>tal<\/mark>/i', $html) === 1 && !str_contains($html, 'totalt') && !str_contains($html, 'blåtal'),
        "$label: uddraget fremhæver det selvstændige ord");
}

// Uden ordstart-fund (fx kun som del af et ord) falder uddraget tilbage til første forekomst som før
$only = f003_call([], 'Ingen selvstændige ord her, kun totalt.');
$s = Calls::snippets([$only], 'tal')[$only] ?? null;
H004Check::that('AK6', $s !== null && str_contains($s['text'], 'totalt'), 'kun delstreng: LOCATE som før');

H004Check::done();
