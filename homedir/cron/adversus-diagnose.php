<?php
declare(strict_types=1);

/**
 * H009: read-only diagnose af matchet mellem Adversus-optagelser og opkald. Køres manuelt af ejeren på DEV:
 *
 *   php ~/cron/adversus-diagnose.php
 *
 * Læser én række fra adversus_pending (kun SELECT) og bruger højst 40 Adversus-kald. Skriver intet i databasen eller
 * på disken. Udskriver id'er, tidsstempler, varigheder, HTTP-status, feltnavne og Adversus' fejltekst renset af
 * Client::errorText — aldrig telefonnumre eller URL'er. Logikken (også deploy-stop og fejlhåndtering) ligger i
 * src/Adversus/Diagnose.php. Ikke en cron-opgave (intet i crontab).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
// Et stakspor må aldrig vise argumenter (fx Authorization-headeren), heller ikke ved en fejl før Diagnose::cli
ini_set('zend.exception_ignore_args', '1');
require_once __DIR__ . '/../src/Bootstrap.php';

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
$pending = static fn(): array => array_values(DB::all(
    'SELECT cdr_id, cdr_at, tries, first_seen FROM adversus_pending ORDER BY first_seen DESC, cdr_id DESC LIMIT 1'));
exit(Diagnose::cli(dirname(__DIR__), Config::section('adversus'), new CurlTransport(), $pending, time(), $say));
