<?php
declare(strict_types=1);

/**
 * H011: hvem taler. Lægger talere (Agent/Kunde, ellers Taler 1/2) på færdige transcripts i ledig tid
 * (src/Transcription/Diarizer.php). Cron hvert minut, lagt i sitets egen crontab af cron/whisper-cron.sh:
 *
 *   * * * * * /usr/bin/php /home/<bruger>/cron/diarize.php >> /home/<bruger>/logs/diarize.log 2>&1
 *
 * Gør intet, når flaget H011_diarization er slukket, `[diarization] enabled = off` står i config.ini, en udrulning kører,
 * eller der er opkald i transcription-køen. Højst én kørsel ad gangen (var/locks/diarize.lock). Motoren kører med
 * nice 19 / ionice -c3 og stoppes inden for ca. 5 s, når der kommer et opkald eller en udrulning starter, så
 * transcription aldrig venter på den.
 *
 * Talerne beregnes igen (fx efter en ændring af [diarization] agent_phrases) ved at nulstille bogføringen:
 *
 *   php ~/cron/diarize.php --reset <opkalds-id|fejlet|alle>
 */

use Whisper\Transcription\DiarizationSettings;
use Whisper\Transcription\Diarizer;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessDiarizer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
// Framework (LIFECYCLE §8): workers holder pause under en udrulning — som [ -e logs/deploying ] && exit 0
$deploying = dirname(__DIR__) . '/logs/deploying';
if (file_exists($deploying)) {
    exit(0);
}

require_once __DIR__ . '/../src/Bootstrap.php';

if (($argv[1] ?? '') === '--reset') {
    try {
        $n = Diarizer::reset((string) ($argv[2] ?? ''));
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, 'diarize: ' . $e->getMessage() . "\n");
        exit(2);
    }
    echo 'diarize: ', $n, " transcript(s) nulstillet — talerne beregnes igen, når transcription-køen er tom\n";
    exit(0);
}

if (!\Whisper\Flags::enabled(\Whisper\App::FLAG) || !\Whisper\Flags::enabled(\Whisper\CallPages::FLAG)) {
    exit(0);
}
// Advarsler om ugyldige [diarization]-værdier skrives først sammen med kørslens første opkald, ikke hvert minut
$warnings = [];
$settings = DiarizationSettings::fromConfig(static function (string $w) use (&$warnings): void {
    $warnings[] = $w;
});
if (!$settings->enabled) {
    exit(0);   // flaget slukket eller nødbremsen [diarization] enabled = off
}

$lock = \Whisper\Lock::tryAcquire('diarize');
if ($lock === null) {
    exit(0);   // en kørsel er i gang
}
$log = static function (string $line): void {
    echo gmdate('Y-m-d\TH:i:s\Z'), ' ', $line, "\n";
};
try {
    $engine = new ProcessDiarizer($settings->threads);
} catch (EngineError $e) {
    $log('diarize: ' . $e->getMessage());
    exit(1);
}
if (!$engine->available()) {
    if ((int) gmdate('i') === 0) {   // én linje i timen, ikke hvert minut
        $log('diarize: diarization er slået til, men ikke sat op (kør cron/whisper-setup.sh)');
    }
    exit(0);
}
$jobLog = static function (string $line) use (&$warnings, $log): void {
    foreach ($warnings as $w) {
        $log($w);
    }
    $warnings = [];
    $log($line);
};
$d = new Diarizer($engine, $settings, null, $jobLog, static fn(): bool => file_exists($deploying));
$d->run(3000);
$lock->release();
