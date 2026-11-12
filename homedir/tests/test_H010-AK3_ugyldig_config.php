<?php
declare(strict_types=1);

// H010-AK3: ugyldige engine_mode og batched_size giver standarden (sequential, 4) og én advarsel pr. nøgle.
// batched med vad off giver vad on og én advarsel (faster-whisper 1.2.1's pipeline kræver VAD ved lyd over 30 s), og
// konstruktøren afviser alt uden for intervallerne, fordi værdierne bliver argv-elementer.

require_once __DIR__ . '/h010_lib.php';

use Whisper\Transcription\EngineError;
use Whisper\Transcription\WhisperSettings;

foreach (['parallel', 'batchet', '1', 'batched; rm -rf /', 'sequential batched'] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['engine_mode' => $bad], 6, h010_collect($w));
    H010Check::that('AK3', $s->mode === 'sequential' && count($w) === 1 && str_contains($w[0], '[whisper] engine_mode')
        && str_contains($w[0], 'bruger sequential') && !str_contains($w[0], $bad), 'engine_mode "' . $bad . '" → sequential og én advarsel');
}
foreach (['0', '33', '-1', '4.5', '1e1', 'otte', '８'] as $bad) {
    $w = [];
    $s = WhisperSettings::fromSection(['engine_mode' => 'batched', 'batched_size' => $bad], 6, h010_collect($w));
    H010Check::that('AK3', $s->batchedSize === 4 && $s->mode === 'batched' && count($w) === 1
        && str_contains($w[0], '[whisper] batched_size skal være 1–32 — bruger 4'), 'batched_size "' . $bad . '" → 4 og én advarsel');
}
$w = [];
$s = WhisperSettings::fromSection(['engine_mode' => 'nej', 'batched_size' => '99'], 6, h010_collect($w));
H010Check::that('AK3', $s->mode === 'sequential' && $s->batchedSize === 4 && count($w) === 2, 'begge ugyldige: to advarsler, én pr. nøgle');

$w = [];
$s = WhisperSettings::fromSection(['engine_mode' => 'batched', 'vad' => 'off'], 6, h010_collect($w));
H010Check::that('AK3', $s->vad === true && count($w) === 1 && str_contains($w[0], 'engine_mode = batched kræver vad on — bruger vad on')
    && h005_opt($s->argv(), '--vad') === '1', 'batched + vad off → vad on og én advarsel');

foreach ([['x', 4, true], ['batched', 0, true], ['batched', 33, true], ['batched', 4, false], ['', 4, true]] as [$m, $n, $vad]) {
    $ok = false;
    try {
        new WhisperSettings(3, 1, $vad, 10, 2, 1.5, $m, $n);
    } catch (EngineError) {
        $ok = true;
    }
    H010Check::that('AK3', $ok, 'konstruktøren afviser mode "' . $m . '", batched_size ' . $n . ', vad ' . ($vad ? 'on' : 'off'));
}

H010Check::done();
