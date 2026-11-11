<?php
declare(strict_types=1);

// H003-AK3: kombinationen i låsefilen er en, faster-whisper kan importere og bruge (målt i wheels og lokalt, se
// kommentaren øverst i cron/whisper-requirements.txt):
// - faster-whisper < 1.2 importerer requests direkte (DEV-fejlen) → requests skal da være låst;
// - huggingface-hub ≥ 1.0 har ikke snapshot_download(local_dir_use_symlinks=), som faster-whisper sender → 0.x;
// - av ≥ 19 afviser av.open(metadata_errors=), som faster-whisper 1.2.1 sender → < 19;
// - modelnavnet turbo kom i faster-whisper 1.1.0.

require_once __DIR__ . '/h003_lib.php';

use Whisper\Tests\H003Check;

use function Whisper\Tests\h003_lock_versions;

$v = h003_lock_versions();
$fw = $v['faster-whisper'] ?? '0';
$hub = $v['huggingface-hub'] ?? '';
$av = $v['av'] ?? '';

H003Check::that('AK3', version_compare($fw, '1.1.0', '>='), "faster-whisper $fw kender modelnavnet turbo (≥ 1.1.0)");
H003Check::that('AK3', version_compare($fw, '1.2.0', '>=') || isset($v['requests']),
    "faster-whisper $fw importerer requests direkte, så requests skal være låst");
H003Check::that('AK3', $hub !== '' && version_compare($hub, '0.21', '>=') && version_compare($hub, '1.0.0', '<'),
    "huggingface-hub $hub er 0.x (≥ 0.21, som faster-whisper kræver; 1.x mangler local_dir_use_symlinks)");
H003Check::that('AK3', isset($v['requests']), 'requests er låst (huggingface-hub 0.x kræver det)');
H003Check::that('AK3', $av !== '' && version_compare($av, '11', '>=') && version_compare($av, '19', '<'),
    "av $av er ≥ 11 og < 19 (19 afviser metadata_errors=)");
$ct2 = $v['ctranslate2'] ?? '';
H003Check::that('AK3', $ct2 !== '' && version_compare($ct2, '4.0', '>=') && version_compare($ct2, '5', '<'), "ctranslate2 $ct2 er 4.x");
$tok = $v['tokenizers'] ?? '';
H003Check::that('AK3', $tok !== '' && version_compare($tok, '0.13', '>=') && version_compare($tok, '1', '<'), "tokenizers $tok er 0.13–0.x");
$ort = $v['onnxruntime'] ?? '';
H003Check::that('AK3', $ort !== '' && version_compare($ort, '1.14', '>=') && version_compare($ort, '2', '<'), "onnxruntime $ort er 1.x ≥ 1.14");

H003Check::done('AK3');
