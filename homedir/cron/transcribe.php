<?php
declare(strict_types=1);

/**
 * F002: transcriberer ventende opkald (i batches, H006) med faster-whisper (src/Transcription/). Cron hvert minut, lagt i
 * sitets egen crontab af cron/whisper-cron.sh:
 *
 *   * * * * * /usr/bin/php /home/<bruger>/cron/transcribe.php >> /home/<bruger>/logs/transcribe.log 2>&1
 *
 * Op til [whisper] workers kørsler ad gangen (standard 2), hver i sit eget slot (Slots: var/locks/transcribe.lock,
 * transcribe-2.lock …). Er alle slots optaget, afslutter kørslen straks; cron starter næste slot minuttet efter. Hængende
 * opkald (over 2 timer i "transcriberer") sættes tilbage, undtagen dem en anden levende kørsel står med i sin låsefil.
 * Hver kørsel tager opkald, til køen er tom, en udrulning starter, eller der er gået 50 minutter.
 */

use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\QueueRules;
use Whisper\Transcription\Slots;
use Whisper\Transcription\Transcriber;
use Whisper\Transcription\WhisperSettings;

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

// Advarsler om ugyldige [whisper]-værdier skrives først sammen med kørslens første opkald, så en tom kø ikke giver
// en linje hvert minut. Indstillingerne læses før slotvalget, fordi workers bestemmer antallet af slots.
$warnings = [];
$collect = static function (string $w) use (&$warnings): void {
    $warnings[] = $w;
};
$settings = WhisperSettings::fromSection(\Whisper\Config::section('whisper'), WhisperSettings::cores(), $collect);
// H012: nyeste først; korte og gamle opkald kun, når en bruger har valgt dem
$rules = QueueRules::fromSection(\Whisper\Config::section('whisper'), $collect);

$acquired = Slots::acquire($settings->workers);
if ($acquired === null) {
    exit(0);   // alle slots er i gang
}
[$slot, $lock] = $acquired;
\Whisper\Paths::heartbeat('transcribe');

// Hver linje får slottet: "transcribe[2]: opkald 120: færdig (…)"
$log = static function (string $line) use ($slot): void {
    if (str_starts_with($line, 'transcribe: ')) {
        $line = 'transcribe[' . $slot . ']: ' . substr($line, strlen('transcribe: '));
    }
    echo gmdate('Y-m-d\TH:i:s\Z'), ' ', $line, "\n";
};

try {
    $engine = ProcessEngine::fromSettings($settings, $slot);
} catch (EngineError $e) {
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
$t = new Transcriber($engine, null, $jobLog, $engine->errorFile(), static function (array $ids) use ($lock): void {
    $lock->write(Slots::format($ids));
}, $settings->rtf, $rules);
$reset = $t->resetStale(Slots::heldByOthers($slot));
if ($reset > 0) {
    $log('transcribe: ' . $reset . ' hængende opkald sat tilbage');
}
// Hvad reglerne springer over: én linje i timen (minut 0) fra slot 1, ikke hvert minut
if ($slot === 1 && (int) gmdate('i') === 0) {
    $skipped = $rules->skippedLine(time());
    if ($skipped !== null) {
        $log($skipped);
    }
}
if (!$engine->available()) {
    // Motor ikke klar (setup ikke kørt færdig for den konfigurerede model): intet opkald tages, intet forsøg bruges
    [$rule, $ruleParams] = $rules->eligibleSql(time());
    $waiting = (int) \Whisper\DB::scalar("SELECT COUNT(*) FROM calls WHERE transcript_status = 'ventende' AND audio_status = 'hentet' AND "
        . $rule, $ruleParams);
    if ($waiting > 0 && (int) gmdate('i') === 0) {   // én linje i timen, ikke hvert minut
        $log('transcribe: motoren er ikke klar (kør cron/whisper-setup.sh) — ' . $waiting . ' opkald venter');
    }
    exit(0);
}

$start = time();
while (time() - $start < 3000 && !file_exists($deploying)) {
    // Op til [whisper] batch opkald pr. motorkald, så modellen indlæses én gang pr. batch (H006; batch = 1: én ad gangen).
    // Resttiden af rammen begrænser batchen, og et langt opkald venter til næste kørsel, hvis der er under 10 min tilbage.
    // 0 = tom kø, eller en undtagelse afbrød batchen: stop til næste cron-minut.
    if ($t->runBatch(3000 - (time() - $start)) === 0) {
        break;
    }
}
$lock->release();
exit(0);
