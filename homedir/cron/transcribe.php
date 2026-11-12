<?php
declare(strict_types=1);

/**
 * F002: transcriberer ventende opkald (i batches, H006) med faster-whisper (src/Transcription/). Cron hvert minut, lagt i
 * sitets egen crontab af cron/whisper-cron.sh:
 *
 *   * * * * * /usr/bin/php /home/<bruger>/cron/transcribe.php >> /home/<bruger>/logs/transcribe.log 2>&1
 *
 * Låsen var/locks/transcribe.lock gør det til én kø: kører et job (det kan tage lang tid), afslutter de næste kørsler
 * straks. Kun den, der holder låsen, sætter hængende opkald (over 2 timer i "transcriberer") tilbage — så kører der
 * beviseligt ingen motor. Jobbet tager opkald, til køen er tom, en udrulning starter, eller der er gået 50 minutter.
 */

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

// Flaget slukket i miljøet (eller F001 slukket): intet transcriberes — før lås, database og motor (security S1)
if (!\Whisper\Flags::enabled(\Whisper\App::FLAG) || !\Whisper\Flags::enabled(\Whisper\CallPages::FLAG)) {
    exit(0);
}

$log = static function (string $line): void {
    echo gmdate('Y-m-d\TH:i:s\Z'), ' ', $line, "\n";
};

$lock = \Whisper\Lock::tryAcquire('transcribe');
if ($lock === null) {
    exit(0);   // et job kører allerede
}
\Whisper\Paths::heartbeat('transcribe');

// Advarsler om ugyldige [whisper]-værdier skrives først sammen med kørslens første opkald, så en tom kø ikke giver
// en linje hvert minut
$warnings = [];
try {
    $engine = \Whisper\Transcription\ProcessEngine::fromConfig(static function (string $w) use (&$warnings): void {
        $warnings[] = $w;
    });
} catch (\Whisper\Transcription\EngineError $e) {
    $log('transcribe: ' . $e->getMessage());
    exit(1);
}
$jobLog = static function (string $line) use (&$warnings, $log): void {
    foreach ($warnings as $w) {
        $log($w);
    }
    $warnings = [];
    $log($line);
};
$t = new \Whisper\Transcription\Transcriber($engine, null, $jobLog);
$reset = $t->resetStale();
if ($reset > 0) {
    $log('transcribe: ' . $reset . ' hængende opkald sat tilbage');
}
if (!$engine->available()) {
    // Motor ikke klar (setup ikke kørt færdig for den konfigurerede model): intet opkald tages, intet forsøg bruges
    $waiting = (int) \Whisper\DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'ventende' AND audio_status = 'hentet'");
    if ($waiting > 0 && (int) gmdate('i') === 0) {   // én linje i timen, ikke hvert minut
        $log('transcribe: motoren er ikke klar (kør cron/whisper-setup.sh) — ' . $waiting . ' opkald venter');
    }
    exit(0);
}

$start = time();
while (time() - $start < 3000 && !file_exists($deploying)) {
    // Op til [whisper] batch opkald pr. motorkald, så modellen indlæses én gang pr. batch (H006; batch = 1: én ad gangen)
    if ($t->runBatch() === 0) {
        break;
    }
}
$lock->release();
exit(0);
