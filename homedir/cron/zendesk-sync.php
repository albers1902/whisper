<?php
declare(strict_types=1);

/**
 * F002: henter Talk-opkald med optagelse fra Zendesk og deres lydfiler (src/Zendesk/Sync.php). Cron hvert 10. minut,
 * lagt i sitets egen crontab af cron/whisper-cron.sh:
 *
 *   * /10 * * * * /usr/bin/php /home/<bruger>/cron/zendesk-sync.php >> /home/<bruger>/logs/zendesk-sync.log 2>&1
 *
 * Låsen tages her (flock på var/locks/zendesk-sync.lock), aldrig i cron-linjen. Mangler [zendesk] i config.ini, gøres
 * intet (én linje i loggen). Exit 0 = ok eller intet at gøre; 1 = fejl (står også på siden).
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

$log = static function (string $line): void {
    echo gmdate('Y-m-d\TH:i:s\Z'), ' ', $line, "\n";
};

$lock = \Whisper\Lock::tryAcquire('zendesk-sync');
if ($lock === null) {
    exit(0);   // forrige kørsel er ikke færdig
}
\Whisper\Paths::heartbeat('zendesk-sync');

try {
    $cfg = \Whisper\Zendesk\ZendeskConfig::fromSection(\Whisper\Config::section('zendesk'));
} catch (\Whisper\Zendesk\ZendeskError $e) {
    $log('zendesk-sync: ' . $e->getMessage());
    exit(1);
}
if ($cfg === null) {
    $log('zendesk-sync: [zendesk] mangler i config.ini — intet hentet');
    exit(0);
}
if (!\Whisper\Zendesk\CurlTransport::available()) {
    $log('zendesk-sync: PHP-udvidelsen curl mangler — intet hentet (spørg sysadmin)');
    exit(1);
}

$client = new \Whisper\Zendesk\Client($cfg, new \Whisper\Zendesk\CurlTransport(), \Whisper\Zendesk\Client::tokenFile());
$result = (new \Whisper\Zendesk\Sync($client, $cfg, null, $log))->run();
if ($result['calls'] > 0 || $result['downloaded'] > 0 || $result['failed'] > 0) {
    $log(sprintf('zendesk-sync: %d opkald gemt/opdateret, %d optagelser hentet, %d fejlede',
        $result['calls'], $result['downloaded'], $result['failed']));
}
$lock->release();
exit($result['error'] === null ? 0 : 1);
