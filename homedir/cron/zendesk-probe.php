<?php
declare(strict_types=1);

/**
 * F002: read-only måling af Zendesk-integrationen. Køres af ejeren én gang på DEV, efter [zendesk] er skrevet:
 *
 *   php ~/cron/zendesk-probe.php
 *
 * Den afgør det, Zendesks dokumentation ikke siger (F002 v0 §4): om et smalt scope kan læse Talk-exporten, hvilke felter
 * opkald og voice-kommentarer har (findes data.call_id?), og hvilke værter optagelsen hentes fra (statuskæden).
 * Udskriver KUN HTTP-status, værtsnavne, antal og feltnavne — aldrig værdier, URL'er, tokens eller persondata.
 * Den skriver intet i databasen og rører ikke token-cachen. En hentet optagelse slettes straks.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Config;
use Whisper\Paths;
use Whisper\Zendesk\Client;
use Whisper\Zendesk\CurlTransport;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

$say = static function (string $line): void {
    echo $line, "\n";
};
$keys = static function (mixed $a): string {
    if (!is_array($a)) {
        return '(intet)';
    }
    $k = array_map('strval', array_keys($a));
    sort($k);
    return implode(', ', $k);
};

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
$http = new CurlTransport();
$callsPath = '/api/v2/channels/voice/stats/incremental/calls.json?start_time=' . $cfg->since;

// 1. Scope-varianter: token og ét kald til Talk-exporten pr. variant (ingen token-cache)
$variants = array_values(array_unique([$cfg->scope, 'tickets:read users:read', 'read']));
foreach ($variants as $scope) {
    $c = new Client(new ZendeskConfig($cfg->subdomain, $cfg->clientId, $cfg->clientSecret, $scope, $cfg->since), $http);
    try {
        $c->accessToken(true);
    } catch (ZendeskError $e) {
        $say("scope \"$scope\": token afvist (" . $e->getMessage() . ')');
        continue;
    }
    try {
        $c->getJson($callsPath);
        $say("scope \"$scope\": token OK · Talk-export HTTP 200");
    } catch (ZendeskError $e) {
        $say("scope \"$scope\": token OK · Talk-export " . $e->getMessage());
    }
}

// 2. Felterne med det konfigurerede scope
$client = new Client($cfg, $http);
try {
    $data = $client->getJson($callsPath);
} catch (ZendeskError $e) {
    $say('✘ Talk-export: ' . $e->getMessage());
    exit(1);
}
$calls = is_array($data['calls'] ?? null) ? $data['calls'] : [];
$say('Talk-export: svar-felter: ' . $keys($data) . ' · count ' . count($calls));
$withRec = null;
foreach ($calls as $c) {
    if (is_array($c) && (int) ($c['recording_time'] ?? 0) > 0 && is_int($c['ticket_id'] ?? null)) {
        $withRec = $c;
        break;
    }
}
$say('Opkald: felter: ' . $keys($calls[0] ?? null));
$say('Opkald: recording_url i exporten: ' . (is_array($calls[0] ?? null) && array_key_exists('recording_url', $calls[0]) ? 'ja' : 'nej'));
if ($withRec === null) {
    $say('! intet opkald med recording_time > 0 og ticket_id siden since — vælg en tidligere since og kør igen');
    exit(0);
}
$say('Opkald med optagelse fundet (direction: ' . (is_string($withRec['direction'] ?? null) ? $withRec['direction'] : '?') . ')');

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
$say('Kommentarer: svar-felter: ' . $keys($cm) . ' · VoiceComment: ' . count($voice));
$recUrl = null;
foreach ($voice as $i => $d) {
    $match = !array_key_exists('call_id', $d) ? 'mangler'
        : ((string) (is_scalar($d['call_id']) ? $d['call_id'] : '') === (string) $withRec['id'] ? 'matcher opkaldets id' : 'et andet id');
    $say('VoiceComment ' . ($i + 1) . ': data-felter: ' . $keys($d) . ' · call_id: ' . $match
        . ' · recording_url-vært: ' . (is_string($d['recording_url'] ?? null) ? (Client::hostOf($d['recording_url']) ?: '(ikke https)') : '(ingen)'));
    if ($recUrl === null && is_string($d['recording_url'] ?? null)) {
        $recUrl = $d['recording_url'];
    }
}

// 4. Agentens navn kan læses?
if (is_int($withRec['agent_id'] ?? null)) {
    try {
        $u = $client->getJson('/api/v2/users/' . $withRec['agent_id'] . '.json');
        $say('Bruger (agent): HTTP 200 · felter: ' . $keys($u['user'] ?? null));
    } catch (ZendeskError $e) {
        $say('Bruger (agent): ' . $e->getMessage());
    }
}

// 5. Optagelsen: statuskæden (kun værter). recording_hosts fra config gælder; ellers stopper den ved første fremmede vært.
if ($recUrl !== null) {
    $tmp = Paths::dir('tmp') . '/probe-' . bin2hex(random_bytes(6)) . '.part';
    try {
        $type = $client->download($recUrl, $tmp);
        $say('Optagelse: hentet · Content-Type: ' . $type . ' · ' . (int) @filesize($tmp) . ' bytes');
    } catch (ZendeskError $e) {
        $say('Optagelse: ' . $e->getMessage());
    } finally {
        @unlink($tmp);
    }
    foreach ($client->lastChain as $i => $hop) {
        $say('  ' . ($i + 1) . '. ' . $hop['host'] . ' → HTTP ' . $hop['status']);
    }
    $say('  (Står der "ikke godkendt" ved en vært: skriv den i [zendesk] recording_hosts, og kør proben igen.)');
}
$say('PROBE: FÆRDIG');
