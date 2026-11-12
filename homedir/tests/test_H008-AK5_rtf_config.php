<?php
declare(strict_types=1);

// H008-AK5: [whisper] rtf_estimate er en decimal 0,3–5 (standard 1,5). En ugyldig værdi giver 1,5 og én advarsel, som
// ikke gentager værdien (samme mønster som H005–H007).

require_once __DIR__ . '/h008_lib.php';

use Whisper\Transcription\EngineError;
use Whisper\Transcription\WhisperSettings;

$collect = static function (array &$into): Closure {
    return static function (string $w) use (&$into): void {
        $into[] = $w;
    };
};

$w = [];
H008Check::that('AK5', WhisperSettings::fromSection([], 6, $collect($w))->rtf === 1.5 && $w === [], 'uden nøgle: 1,5, ingen advarsel');
H008Check::that('AK5', WhisperSettings::fromSection(['rtf_estimate' => ''], 6, $collect($w))->rtf === 1.5 && $w === [], 'tom: 1,5, ingen advarsel');
foreach (['1.5' => 1.5, '1,5' => 1.5, '0.3' => 0.3, '5' => 5.0, ' 2.25 ' => 2.25, '0,75' => 0.75] as $ok => $want) {
    $w = [];
    $s = WhisperSettings::fromSection(['rtf_estimate' => (string) $ok], 6, $collect($w));
    H008Check::that('AK5', abs($s->rtf - $want) < 1e-9 && $w === [], 'gyldig "' . $ok . '" → ' . $want);
}
foreach (['0.2', '5.01', '5.5', '0', 'x', '-1', '1.555', '1e3', '10', '1.5.0', 'nan', '1,5; rm -rf /'] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['rtf_estimate' => $bad], 6, $collect($w));
    H008Check::that('AK5', $s->rtf === 1.5 && count($w) === 1
        && str_contains($w[0], '[whisper] rtf_estimate skal være 0,3–5 — bruger 1,5') && ($bad === '0' || !str_contains($w[0], $bad)),
        'ugyldig "' . $bad . '" → 1,5 og én advarsel');
}
$w = [];
WhisperSettings::fromSection(['rtf_estimate' => 'x', 'batch' => '0'], 6, $collect($w));
H008Check::that('AK5', count($w) === 2, 'én advarsel pr. nøgle (rtf_estimate og batch)');
foreach ([0.29, 5.1, NAN, INF] as $bad) {
    $ok = false;
    try {
        new WhisperSettings(2, 1, true, 10, 1, $bad);
    } catch (EngineError) {
        $ok = true;
    }
    H008Check::that('AK5', $ok, 'WhisperSettings(rtf ' . var_export($bad, true) . ') afvises');
}

// transcribe.php giver værdien videre til Transcriber
$src = (string) file_get_contents(__DIR__ . '/../cron/transcribe.php');
H008Check::that('AK5', str_contains($src, '$settings->rtf'), 'cron/transcribe.php sender rtf_estimate til Transcriber');

H008Check::done();
