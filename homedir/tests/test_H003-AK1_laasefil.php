<?php
declare(strict_types=1);

// H003-AK1: cron/whisper-requirements.txt findes, og hver linje er en præcis version (==) med mindst én sha256-hash,
// så pip med --require-hashes aldrig vælger en anden pakke. Uden rettelsen fandtes filen ikke, og pip valgte selv
// nyeste huggingface_hub (ModuleNotFoundError: requests på DEV).

require_once __DIR__ . '/h003_lib.php';

use Whisper\Tests\H003Check;

use function Whisper\Tests\h003_lock_lines;
use function Whisper\Tests\h003_lock_path;
use function Whisper\Tests\h003_lock_versions;

H003Check::that('AK1', is_file(h003_lock_path()), 'cron/whisper-requirements.txt findes');

$lines = h003_lock_lines();
H003Check::that('AK1', count($lines) >= 10, 'låsefilen har faster-whisper og de transitive pakker (' . count($lines) . ' linjer)');

$names = [];
foreach ($lines as $l) {
    // Kun "navn==version --hash=sha256:<64 hex> …": ingen -e, URL'er, --index-url eller løse versioner
    H003Check::that('AK1', preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*==[0-9][A-Za-z0-9.+!-]*( --hash=sha256:[0-9a-f]{64})+$/', $l) === 1,
        'linjen er navn==version med --hash=sha256: ' . substr($l, 0, 60));
    $names[] = strtolower(explode('==', $l)[0]);
}
H003Check::that('AK1', count($names) === count(array_unique($names)), 'ingen pakke står to gange');

$v = h003_lock_versions();
foreach (['faster-whisper', 'ctranslate2', 'huggingface-hub', 'tokenizers', 'onnxruntime', 'av', 'numpy', 'tqdm'] as $p) {
    H003Check::that('AK1', isset($v[$p]), "$p er låst");
}

H003Check::done('AK1');
