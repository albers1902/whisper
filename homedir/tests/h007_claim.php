<?php
declare(strict_types=1);

/**
 * Hjælpeproces til H007-AK2 (ikke selv en test): venter til tidspunktet i argv[1] (microtime), claimer så opkald ét ad
 * gangen fra den fælles kø, til den er tom, og skriver id'erne som JSON. To af dem startet samtidig er to transcribe-
 * kørsler, der claimer i samme øjeblik. Databasen er testløberens (config.ini skrevet af run.php).
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
require_once __DIR__ . '/../src/Bootstrap.php';

$at = (float) ($argv[1] ?? '0');
while (microtime(true) < $at) {
    usleep(1000);
}
$t = new \Whisper\Transcription\Transcriber(new \Whisper\Transcription\ProcessEngine('turbo'), null,
    static function (string $l): void {
    });
$ids = [];
while (($job = $t->claimNext()) !== null) {
    $ids[] = $job['id'];
}
echo json_encode($ids), "\n";
