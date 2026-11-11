<?php
declare(strict_types=1);

// H003-AK2: whisper-setup.sh installerer fra låsefilen med --require-hashes (ikke en løs "faster-whisper==…", som
// lod pip vælge de transitive pakker), laver import-tjekket før modelhentningen og skriver ready-markøren til sidst.

require_once __DIR__ . '/h003_lib.php';

use Whisper\Tests\H003Check;

use function Whisper\Tests\h003_setup_path;

$s = is_file(h003_setup_path()) ? (string) file_get_contents(h003_setup_path()) : '';
H003Check::that('AK2', $s !== '', 'cron/whisper-setup.sh findes');

H003Check::that('AK2', str_starts_with($s, "#!/bin/bash\n") && str_contains($s, '[ -e "$(dirname "$0")/../logs/deploying" ] && {'),
    'scriptet starter stadig med deploying-tjekket');
H003Check::that('AK2', str_contains($s, 'LOCK=cron/whisper-requirements.txt'), 'scriptet peger på låsefilen');

$pipLines = array_values(array_filter(explode("\n", $s), static fn(string $l): bool => preg_match('/-m pip install\b/', $l) === 1
    && !str_starts_with(ltrim($l), '#')));
H003Check::that('AK2', count($pipLines) === 1, 'der er præcis én pip install (fandt ' . count($pipLines) . ')');
$pip = $pipLines[0] ?? '';
foreach (['--require-hashes', '--only-binary :all:', '--no-deps', '-r "$LOCK"'] as $flag) {
    H003Check::that('AK2', str_contains($pip, $flag), "pip install bruger $flag");
}
H003Check::that('AK2', preg_match('/pip install[^\n]*faster-whisper==/', $s) !== 1, 'ingen løs pip install "faster-whisper==…"');
H003Check::that('AK2', !str_contains($s, 'FASTER_WHISPER_V'), 'den gamle løse version er væk');

// Rækkefølge: installation → import-tjek → modelhentning → markør
$posPip = strpos($s, $pip !== '' ? $pip : 'pip install');
$posImport = strpos($s, 'import-tjek fejlede');
$posDownload = strpos($s, 'download_model(name');
$posReady = strpos($s, '> "var/whisper/ready-$model"');
H003Check::that('AK2', $posPip !== false && $posImport !== false && $posPip < $posImport, 'import-tjekket kommer efter installationen');
H003Check::that('AK2', $posImport !== false && $posDownload !== false && $posImport < $posDownload, 'import-tjekket kommer før modelhentningen');
$after = $posReady !== false ? array_slice(explode("\n", trim(substr($s, $posReady))), 1) : [];
H003Check::that('AK2', $posDownload !== false && $posReady !== false && $posDownload < $posReady
    && $after === ['echo "WHISPER: KLAR ($model)"'], 'ready-markøren skrives til sidst');
H003Check::that('AK2', str_contains($s, 'rm -f "var/whisper/ready-$model"'), 'en gammel markør fjernes ved start');

// Et venv, der ikke passer til låsen, bygges forfra; pip check og en låst Python-version
H003Check::that('AK2', str_contains($s, 'rm -rf "$VENV"') && str_contains($s, '[ "$(have)" != "$want" ]'),
    'et venv med andre pakker bygges forfra');
H003Check::that('AK2', str_contains($s, 'pip check'), 'pip check efter installationen');
H003Check::that('AK2', str_contains($s, 'PYV=3.12'), 'låsen er til Python 3.12, og scriptet tjekker det');

// Modelrevisionen er låst pr. model
H003Check::that('AK2', preg_match('/turbo\)\s+rev=[0-9a-f]{40} ;;/', $s) === 1, 'turbo har en låst revision (40 hex)');
H003Check::that('AK2', preg_match('/large-v3\)\s+rev=[0-9a-f]{40} ;;/', $s) === 1, 'large-v3 har en låst revision (40 hex)');
H003Check::that('AK2', str_contains($s, 'if main != rev:'), 'en anden revision end den låste afvises før download');

H003Check::done('AK2');
