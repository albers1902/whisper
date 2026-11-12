<?php
declare(strict_types=1);

/**
 * F004: henter Adversus-opkald med optagelse, succes-leads og lyden til opkald i kø (src/Adversus/Sync.php). Cron hvert
 * 10. minut, lagt i sitets egen crontab af cron/whisper-cron.sh:
 *
 *   * /10 * * * * /usr/bin/php /home/<bruger>/cron/adversus-sync.php >> /home/<bruger>/logs/adversus-sync.log 2>&1
 *
 * Låsen tages her (flock på var/locks/adversus-sync.lock), aldrig i cron-linjen. Mangler [adversus] i config.ini, gøres
 * intet (én linje i loggen). Højst 100 Adversus-kald pr. kørsel. Exit 0 = ok eller intet at gøre; 1 = fejl.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
// Framework (LIFECYCLE §8): workers holder pause under en udrulning — som [ -e logs/deploying ] && exit 0
if (file_exists(dirname(__DIR__) . '/logs/deploying')) {
    exit(0);
}

require_once __DIR__ . '/../src/Bootstrap.php';

// Flaget slukket (eller F001, F002 eller F005_ui slukket): intet hentes — før lås, database og netværk (AK9)
if (!\Whisper\Adversus\Feature::on()) {
    exit(0);
}

$log = static function (string $line): void {
    echo gmdate('Y-m-d\TH:i:s\Z'), ' ', $line, "\n";
};

$lock = \Whisper\Lock::tryAcquire('adversus-sync');
if ($lock === null) {
    exit(0);   // forrige kørsel er ikke færdig
}
\Whisper\Paths::heartbeat('adversus-sync');

try {
    $cfg = \Whisper\Adversus\AdversusConfig::fromSection(\Whisper\Config::section('adversus'));
} catch (\Whisper\Adversus\AdversusError $e) {
    $log('adversus-sync: ' . $e->getMessage());
    exit(1);
}
if ($cfg === null) {
    $log('adversus-sync: [adversus] mangler i config.ini — intet hentet');
    exit(0);
}
if (!\Whisper\Zendesk\CurlTransport::available()) {
    $log('adversus-sync: PHP-udvidelsen curl mangler — intet hentet (spørg sysadmin)');
    exit(1);
}

$client = new \Whisper\Adversus\Client($cfg, new \Whisper\Zendesk\CurlTransport(), null, \Whisper\Adversus\Sync::MAX_REQUESTS);
$result = (new \Whisper\Adversus\Sync($client, $cfg, null, $log))->run();
$log(\Whisper\Adversus\Sync::logLine($result));
$lock->release();
exit($result['error'] === null || str_starts_with($result['error'], \Whisper\Adversus\Sync::RATE_LIMIT_PREFIX) ? 0 : 1);
