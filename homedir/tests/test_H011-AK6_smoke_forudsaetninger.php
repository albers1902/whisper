<?php
declare(strict_types=1);

// H011-AK6: forudsætningerne for ejerens smoke på DEV. AK6 selv (meningsfulde roller på rigtige opkald) vurderer ejeren.
// Her: whisper-cron.sh lægger diarize-linjen, whisper-setup.sh henter modellerne på låst revision og sha256 og melder
// DIARIZATION: KLAR/FEJL uden at stoppe transcription, låsefilen har sherpa-onnx med hash, og loglinjen har diar-tid.

require_once __DIR__ . '/h011_lib.php';

use Whisper\Transcription\Diarizer;

$cron = (string) file_get_contents(dirname(__DIR__) . '/cron/whisper-cron.sh');
H011Check::that('AK6', str_contains($cron, '"cron/diarize.php|* * * * * $PHP $H/cron/diarize.php >> $H/logs/diarize.log 2>&1"'),
    'whisper-cron.sh lægger diarize.php hvert minut med log i logs/diarize.log');

$setup = (string) file_get_contents(dirname(__DIR__) . '/cron/whisper-setup.sh');
foreach (['"segmentation.onnx", "csukuangfj/sherpa-onnx-pyannote-segmentation-3-0", "9403a6902bb58e3d5ae8c7e77c3422de279db2e0"',
    '"model.onnx", "220ad67ca923bef2fa91f2390c786097bf305bceb5e261d4af67b38e938e1079"',
    '"embedding.onnx", "csukuangfj/speaker-embedding-models", "0743f301363dec56491a490f6d6cbc9d67f9a3bf"',
    '"3dspeaker_speech_campplus_sv_en_voxceleb_16k.onnx", "357a834f702b80161e5b981182c038e18553c1f2ca752ed6cec2052365d4129b"'] as $pin) {
    H011Check::that('AK6', str_contains($setup, $pin), 'modellen er låst på revision og sha256: ' . substr($pin, 0, 40) . '…');
}
H011Check::that('AK6', str_contains($setup, 'if sha256(src) != want:') && str_contains($setup, 'hf_hub_download(repo, name, revision=rev)'),
    'en fil med forkert sha256 bruges ikke');
$posDiar = strpos($setup, 'echo "DIARIZATION: KLAR"');
$posReady = strpos($setup, '> "var/whisper/ready-$model"');
H011Check::that('AK6', $posDiar !== false && $posReady !== false && $posDiar < $posReady && str_contains($setup, 'DIARIZATION: FEJL'),
    'DIARIZATION: KLAR/FEJL før WHISPER: KLAR, og en fejl stopper ikke whisper-markøren');
H011Check::that('AK6', str_contains($setup, 'rm -f var/whisper/ready-diarization')
    && strpos($setup, 'date -u +%Y-%m-%dT%H:%M:%SZ > var/whisper/ready-diarization') > strpos($setup, 'd.diarize(d.create(root, 2)'),
    'markøren ready-diarization skrives først efter selvtesten');

H011Check::that('AK6', str_contains($setup, "rm -f \"var/whisper/ready-\$model\"   # en afbrudt kørsel må ikke efterlade en gammel markør\nrm -f var/whisper/ready-diarization"),
    'ready-diarization fjernes samtidig med ready-$model, før venv\'et ændres');
H011Check::that('AK6', str_contains($setup, "extra() { comm -23 <(have) <(printf '%s\\n' \"\$want\"); }")
    && str_contains($setup, '|| [ -n "$(extra)" ]; }; then') && !str_contains($setup, '|| [ "$(have)" != "$want" ]; }; then'),
    'venv\'et bygges kun forfra, når det har pakker uden for låsen; nye pakker installeres oveni');
H011Check::that('AK6', str_contains($setup, '"$H/cron/diarize.py" 2>"$derr"') && str_contains($setup, 'Fejlen: $(grep -v'),
    'ved DIARIZATION: FEJL står selve fejlen sidst på linjen');

$lock = (string) file_get_contents(dirname(__DIR__) . '/cron/whisper-requirements.txt');
H011Check::that('AK6', str_contains($lock, "sherpa-onnx==2.0.1 --hash=sha256:c7780d8af6921c18e8e920376b32d7707294e5cd3838f4762cf746ab919bcc40\n")
    && str_contains($lock, "sherpa-onnx-core==2.0.1 --hash=sha256:f4ee0ab91c4ae596e0e49d7de32522a946295e038a652f1c1884d89014ee4ec2\n"),
    'sherpa-onnx og sherpa-onnx-core 2.0.1 er låst med sha256');

H011Check::that('AK6', Diarizer::doneLine(2, true, 252, 41.4) === '2 talere, agent fundet (lyd 4:12, diar 0:41)'
    && Diarizer::doneLine(2, false, 60, 9.0) === '2 talere, ingen agent (lyd 1:00, diar 0:09)'
    && Diarizer::doneLine(0, false, 60, 3.0) === 'ingen tale (lyd 1:00, diar 0:03)', 'loglinjen har talere, lydlængde og diar-tid');

H011Check::done();
