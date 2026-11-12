<?php
declare(strict_types=1);

/**
 * F004a: read-only måling af Adversus' API. Køres manuelt af ejeren på DEV, efter [adversus] er skrevet i config.ini:
 *
 *   php ~/cron/adversus-probe.php              auth, endpoints, felter, sideinddeling, optællinger, én optagelse og
 *                                              "Leads med succes" (status, antal siden since, opkald og optagelser
 *                                              for op til 5 succes-leads, overslag) — højst 40 kald pr. kørsel
 *   php ~/cron/adversus-probe.php --call=123   ét opkalds tal og optagelsens statuskæde
 *
 * Valgfrit i [adversus]: success_status = success (lead-status for en succesfuld registrering).
 *
 * Udskriver KUN HTTP-status, værtsnavne, antal, feltnavne og dokumenterede enum-værdier — aldrig feltværdier,
 * brugernavn eller adgangskode. Gemmer intet; en hentet optagelse slettes straks. Logikken ligger i src/Adversus/Probe.php.
 * Ikke en cron-opgave (intet i crontab), så ingen deploying-vagt; den ligger her ved siden af zendesk-probe.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Adversus\Probe;
use Whisper\Config;
use Whisper\Zendesk\CurlTransport;

$say = static function (string $line): void {
    echo $line, "\n";
};
if (!CurlTransport::available()) {
    $say('✘ PHP-udvidelsen curl mangler (CLI)');
    exit(1);
}
exit(Probe::run(array_values(array_slice($argv, 1)), Config::section('adversus'), new CurlTransport(), $say));
