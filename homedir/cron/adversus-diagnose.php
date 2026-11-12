<?php
declare(strict_types=1);

/**
 * H009: read-only diagnose af matchet mellem Adversus-optagelser og opkald. Køres manuelt af ejeren på DEV:
 *
 *   php ~/cron/adversus-diagnose.php
 *
 * Læser op til 3 rækker fra adversus_pending (kun SELECT) og bruger højst 40 Adversus-kald. Skriver intet i databasen
 * eller på disken. Udskriver id'er, tidsstempler, varigheder, HTTP-status og feltnavne — aldrig telefonnumre, navne
 * eller URL'er. Logikken ligger i src/Adversus/Diagnose.php. Ikke en cron-opgave (intet i crontab).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if (file_exists(dirname(__DIR__) . '/logs/deploying')) {
    exit(0);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\AdversusError;
use Whisper\Adversus\Client;
use Whisper\Adversus\Diagnose;
use Whisper\Config;
use Whisper\DB;
use Whisper\Zendesk\CurlTransport;

$say = static function (string $line): void {
    echo $line, "\n";
};
if (!CurlTransport::available()) {
    $say('✘ PHP-udvidelsen curl mangler (CLI)');
    exit(1);
}
try {
    $cfg = AdversusConfig::fromSection(Config::section('adversus'));
} catch (AdversusError $e) {
    $say('✘ ' . $e->getMessage());
    exit(1);
}
if ($cfg === null) {
    $say('✘ [adversus] mangler i config.ini');
    exit(1);
}
$pending = DB::all('SELECT cdr_id, cdr_at, tries, first_seen FROM adversus_pending ORDER BY first_seen DESC, cdr_id DESC LIMIT '
    . Diagnose::SAMPLES);
$client = new Client($cfg, new CurlTransport(), null, Diagnose::MAX_REQUESTS);
exit(Diagnose::run($client, $cfg, array_values($pending), time(), $say));
