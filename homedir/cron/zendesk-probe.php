<?php
declare(strict_types=1);

/**
 * F002: read-only måling af Zendesk-integrationen. Køres af ejeren på DEV, efter [zendesk] er skrevet:
 *
 *   php ~/cron/zendesk-probe.php                 scopes, token, op til 5 sider af Talk-exporten, felter, statuskæde
 *   php ~/cron/zendesk-probe.php --ticket=3901   ét tickets VoiceComments og opkaldene med det ticket_id (H002)
 *
 * Den afgør det, Zendesks dokumentation ikke siger (F002 v0 §4): om et smalt scope kan læse Talk-exporten, hvilke felter
 * opkald og voice-kommentarer har (findes data.call_id?), og hvilke værter optagelsen hentes fra (statuskæden).
 * Udskriver KUN HTTP-status, værtsnavne, antal, feltnavne og exportens rene tal (recording_time, duration …) —
 * aldrig URL'er, tokens, telefonnumre, navne eller tekst. Afvises tokenet, står Zendesks egen fejlkode renset i
 * parentesen (H002). Den skriver intet i databasen og rører ikke token-cachen. En hentet optagelse slettes straks.
 * Målingerne ligger i src/Zendesk/Probe.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Config;
use Whisper\Zendesk\Client;
use Whisper\Zendesk\CurlTransport;
use Whisper\Zendesk\Probe;
use Whisper\Zendesk\Sync;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

$say = static function (string $line): void {
    echo $line, "\n";
};

$ticket = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ticket=([1-9][0-9]{0,15})$/', $arg, $m) === 1) {
        $ticket = (int) $m[1];
    } else {
        $say('✘ ukendt argument (brug: --ticket=<ticket-id>)');
        exit(2);
    }
}

try {
    $cfg = ZendeskConfig::fromSection(Config::section('zendesk'));
} catch (ZendeskError $e) {
    $say('✘ ' . $e->getMessage());
    exit(1);
}
if ($cfg === null) {
    $say('✘ [zendesk] mangler i config/config.ini');
    exit(1);
}
if (!CurlTransport::available()) {
    $say('✘ PHP-udvidelsen curl mangler (CLI)');
    exit(1);
}
$say('✔ curl findes · scope i config: ' . $cfg->scope);
$say('token-request: POST /oauth/tokens · request-format: ' . Client::TOKEN_REQUEST_FORMAT . ' · grant_type: client_credentials');
$http = new CurlTransport();

if ($ticket !== null) {
    $client = new Client($cfg, $http);
    try {
        $client->accessToken(true);
    } catch (ZendeskError $e) {
        $say('✘ token afvist (' . $e->getMessage() . ')');
        exit(1);
    }
    $code = Probe::ticketReport($client, $ticket, $say);
    $say('PROBE: FÆRDIG');
    exit($code);
}

// 1. Scope-varianter: token og ét kald til Talk-exporten pr. variant (ingen token-cache)
foreach (array_values(array_unique([$cfg->scope, 'tickets:read users:read', 'read'])) as $scope) {
    $say(Probe::scopeLine($cfg, $http, $scope, $cfg->since));
}

// 2. Op til 5 sider af exporten med det konfigurerede scope
$client = new Client($cfg, $http);
try {
    $scan = Probe::scanExport($client, $cfg->since);
} catch (ZendeskError $e) {
    $say('✘ Talk-export: ' . $e->getMessage());
    exit(1);
}
$say('Talk-export: svar-felter: ' . implode(', ', $scan['response_fields']));
$say(sprintf('Talk-export: %d side(r) · %d opkald · recording_time > 0: %d · med ticket_id: %d · begge: %d · Sync slår op: %d',
    $scan['pages'], $scan['calls'], $scan['recording'], $scan['ticket'], $scan['both'], $scan['candidates']));
$say('Opkald: felter: ' . (implode(', ', Probe::keys($scan['first'])) ?: '(intet)'));
$say('Opkald: recording_url i exporten: ' . (is_array($scan['first']) && array_key_exists('recording_url', $scan['first']) ? 'ja' : 'nej'));
$withRec = $scan['recorded'] ?? $scan['candidate'];
if ($withRec === null) {
    $say('! intet opkald med ticket_id, som Sync ville slå op, i de skannede sider — kør med --ticket=<id> for et kendt optaget opkald');
    exit(0);
}
$say('Opkald valgt (' . ($scan['recorded'] !== null ? 'recording_time > 0' : 'besvaret, recording_time 0') . '): '
    . Probe::numbers($withRec) . ' · Sync-regel: ' . Sync::recordingHint($withRec));

// 3. Ticketens kommentarer: findes VoiceComment, data-felterne, og matcher data.call_id?
try {
    $cm = $client->getJson('/api/v2/tickets/' . (int) $withRec['ticket_id'] . '/comments.json?page%5Bsize%5D=100');
} catch (ZendeskError $e) {
    $say('✘ ticket-kommentarer: ' . $e->getMessage());
    exit(1);
}
$voice = [];
foreach (is_array($cm['comments'] ?? null) ? $cm['comments'] : [] as $c) {
    if (is_array($c) && ($c['type'] ?? '') === 'VoiceComment') {
        $voice[] = is_array($c['data'] ?? null) ? $c['data'] : [];
    }
}
$say('Kommentarer: svar-felter: ' . implode(', ', Probe::keys($cm)) . ' · VoiceComment: ' . count($voice));
$recUrl = null;
foreach ($voice as $i => $d) {
    $match = !array_key_exists('call_id', $d) ? 'mangler'
        : ((string) (is_scalar($d['call_id']) ? $d['call_id'] : '') === (string) (is_scalar($withRec['id'] ?? null) ? $withRec['id'] : '')
            ? 'matcher opkaldets id' : 'et andet id');
    $say('VoiceComment ' . ($i + 1) . ': data-felter: ' . implode(', ', Probe::keys($d)) . ' · call_id: ' . $match
        . ' · recording_url-vært: ' . (is_string($d['recording_url'] ?? null) ? (Client::hostOf($d['recording_url']) ?: '(ikke https)') : '(ingen)'));
    if ($recUrl === null && is_string($d['recording_url'] ?? null)) {
        $recUrl = $d['recording_url'];
    }
}

// 4. Agentens navn kan læses?
if (is_int($withRec['agent_id'] ?? null)) {
    try {
        $u = $client->getJson('/api/v2/users/' . $withRec['agent_id'] . '.json');
        $say('Bruger (agent): HTTP 200 · felter: ' . implode(', ', Probe::keys($u['user'] ?? null)));
    } catch (ZendeskError $e) {
        $say('Bruger (agent): ' . $e->getMessage());
    }
}

// 5. Optagelsen: statuskæden (kun værter). recording_hosts fra config gælder; ellers stopper den ved første fremmede vært.
if ($recUrl !== null) {
    Probe::tryDownload($client, $recUrl, $say);
    $say('  (Står der "ikke godkendt" ved en vært: skriv den i [zendesk] recording_hosts, og kør proben igen.)');
}
$say('PROBE: FÆRDIG');
