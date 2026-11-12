<?php
declare(strict_types=1);

// H010-AK5: loglinjen pr. opkald har "mode batched" eller "mode sequential" til sidst, så RTF kan sammenlignes:
//   transcribe: opkald 120: færdig (lyd 4:12, tid 1:05, RTF 0,26, threads 3, beam 1, vad on, mode batched)

require_once __DIR__ . '/h010_lib.php';

use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\Transcriber;
use Whisper\Transcription\WhisperSettings;

$b = WhisperSettings::fromSection(['engine_mode' => 'batched', 'threads' => '3'], 6);
$q = WhisperSettings::fromSection(['threads' => '3'], 6);
H010Check::that('AK5', $b->label() === 'threads 3, beam 1, vad on, mode batched', 'label batched: ' . $b->label());
H010Check::that('AK5', $q->label() === 'threads 3, beam 1, vad on, mode sequential', 'label sequential: ' . $q->label());
H010Check::that('AK5', (new ProcessEngine('turbo', null, $b))->label() === $b->label(), 'motorens label er indstillingernes');
H010Check::that('AK5', Transcriber::doneLine(252, 65, $b->label())
    === 'færdig (lyd 4:12, tid 1:05, RTF 0,26, threads 3, beam 1, vad on, mode batched)', 'loglinjen med mode batched');
H010Check::that('AK5', preg_match('/RTF ([0-9]+,[0-9]{2}).*, mode (batched|sequential)\)$/', Transcriber::doneLine(252, 65, $q->label())) === 1,
    'RTF og mode står i samme linje');

H010Check::done();
