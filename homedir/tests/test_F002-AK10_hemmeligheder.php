<?php
declare(strict_types=1);

// F002-AK10: client_secret, access token og lydfil-stier vises aldrig i UI, fejltekster eller logs; logs indeholder kun
// opkalds-id. Token-cachen er 0600, og et udløbet eller afvist (401) token fornyes én gang automatisk.

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Zendesk\Client;
use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Sync;
use Whisper\Zendesk\ZendeskError;

$var = f002_setup();
$cfg = f002_cfg();
$tokenFile = $var . '/zendesk-token.json';
$tokens = [];
$tokenResp = static function () use (&$tokens): HttpResult {
    $r = f002_token_response();
    $tokens[] = (string) json_decode($r->body, true)['access_token'];
    return $r;
};

// 1. Token hentes med client_credentials, kun read-scope, og caches 0600 uden client_secret
$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', $tokenResp(), $tokenResp(), $tokenResp(), $tokenResp());
$http->on('GET', F002_BASE . '/api/v2/users/1.json', f002_json(['user' => ['name' => 'X']]));
$now = F002_NOW;
$client = new Client($cfg, $http, $tokenFile, static function () use (&$now): int {
    return $now;
});
$client->getJson('/api/v2/users/1.json');
$post = $http->to(F002_BASE . '/oauth/tokens');
$body = json_decode((string) ($post[0]['body'] ?? ''), true);
F002Check::that('AK10', count($post) === 1 && is_array($body) && $body['grant_type'] === 'client_credentials' && $body['scope'] === 'read'
    && $body['client_secret'] === $cfg->clientSecret && $post[0]['headers']['Content-Type'] === 'application/json',
    'POST /oauth/tokens med client_credentials, JSON og read-scope');
F002Check::that('AK10', is_file($tokenFile) && (fileperms($tokenFile) & 0777) === 0600, 'token-cachen er 0600');
$cache = (string) file_get_contents($tokenFile);
F002Check::that('AK10', !str_contains($cache, $cfg->clientSecret) && !str_contains($cache, $cfg->clientId), 'cachen har hverken secret eller klient-id');
F002Check::that('AK10', str_contains($cache, $tokens[0]), 'cachen har tokenet og udløbstiden');
$get = $http->to(F002_BASE . '/api/v2/users');
F002Check::that('AK10', ($get[0]['headers']['Authorization'] ?? '') === 'Bearer ' . $tokens[0], 'API-kald med Bearer-token');

// 2. Cachen genbruges af en ny proces; et udløbet token fornyes
$c2 = new Client($cfg, $http, $tokenFile, static fn(): int => F002_NOW + 100);
$c2->getJson('/api/v2/users/1.json');
F002Check::that('AK10', count($http->to(F002_BASE . '/oauth/tokens')) === 1, 'gyldigt cachet token genbruges');
$c3 = new Client($cfg, $http, $tokenFile, static fn(): int => F002_NOW + 3600);
$c3->getJson('/api/v2/users/1.json');
F002Check::that('AK10', count($http->to(F002_BASE . '/oauth/tokens')) === 2, 'udløbet token (inden for 60 s af udløb) fornyes');

// 3. 401: fornyes én gang automatisk; to 401 i træk giver en fejl (ingen løkke)
$http->on('GET', F002_BASE . '/api/v2/users/2.json', new HttpResult(401, [], ''), f002_json(['user' => ['name' => 'Y']]));
$c4 = new Client($cfg, $http, $tokenFile, static fn(): int => F002_NOW + 3600);
$d = $c4->getJson('/api/v2/users/2.json');
F002Check::that('AK10', ($d['user']['name'] ?? '') === 'Y' && count($http->to(F002_BASE . '/oauth/tokens')) === 3, '401: nyt token og ét nyt forsøg');
$http->on('GET', F002_BASE . '/api/v2/users/3.json', new HttpResult(401, [], ''));
$err = '';
try {
    $c4->getJson('/api/v2/users/3.json');
} catch (ZendeskError $e) {
    $err = $e->getMessage();
}
F002Check::that('AK10', $err === 'HTTP 401 fra Zendesk' && count($http->to(F002_BASE . '/api/v2/users/3.json')) === 2,
    'to 401 i træk: fejl efter ét fornyet forsøg');

// 4. Afvist klient: fejlteksten har ingen hemmeligheder
$bad = new FakeTransport();
$bad->on('POST', F002_BASE . '/oauth/tokens', new HttpResult(401, [], '{"error":"invalid_client","error_description":"' . $cfg->clientSecret . '"}'));
$logs = [];
$sync = new Sync(new Client($cfg, $bad, $var . '/t2.json'), $cfg, static fn(): int => F002_NOW, static function (string $l) use (&$logs): void {
    $logs[] = $l;
});
$r = $sync->run();
// H002: Zendesks fejlkode vises, men secret'en i error_description er fjernet
F002Check::that('AK10', $r['error'] === 'Zendesk afviste OAuth-klienten (HTTP 401: invalid_client - [fjernet])'
    && !str_contains((string) $r['error'], $cfg->clientSecret), 'afvist klient: kort fejltekst uden secret');
F002Check::that('AK10', !is_file($var . '/t2.json'), 'intet token caches ved en afvisning');

// 5. Download-fejl, UI og logs: ingen secret, token, optagelses-URL eller sti
$http->on('GET', F002_BASE . '/api/v2/channels/voice/stats/incremental/calls.json*', f002_json(['calls' => [], 'count' => 0, 'end_time' => 1]));
$secretUrl = F002_BASE . '/api/v2/rec/x?signature=' . bin2hex(random_bytes(8));
$id = f002_insert(['recording_url' => $secretUrl, 'started_at' => '2026-10-05 08:00:00']);
$http->on('GET', $secretUrl, new HttpResult(302, ['location' => 'https://intern.evil.test/' . bin2hex(random_bytes(4))], ''));
$ok = f002_insert(['started_at' => '2026-10-04 08:00:00']);
f002_audio($ok);
f002_transcript($ok, 'Tekst.');
$sync = new Sync(new Client($cfg, $http, $tokenFile, static fn(): int => F002_NOW + 3600), $cfg, static fn(): int => F002_NOW,
    static function (string $l) use (&$logs): void {
        $logs[] = $l;
    });
$sync->run();
DB::exec("UPDATE calls SET transcript_status = 'fejlet', transcript_error = 'Lydfilen mangler på serveren' WHERE id = ?", [$id]);

$c = f002_login();
$pages = [f002_get($c, '/')->body, f002_get($c, '/opkald/' . $id)->body, f002_get($c, '/opkald/' . $ok)->body,
    f002_get($c, '/opkald/' . $ok . '/transcript')->body];
$state = DB::all('SELECT last_error FROM sync_state');
$errors = array_merge(array_column(DB::all('SELECT audio_error, transcript_error FROM calls'), 'audio_error'),
    array_column(DB::all('SELECT transcript_error FROM calls'), 'transcript_error'), array_column($state, 'last_error'));
$haystack = implode("\n", array_merge($pages, $logs, array_map('strval', $errors)));
$forbidden = ['client_secret' => $cfg->clientSecret, 'optagelses-URL' => $secretUrl, 'URL-signatur' => 'signature=',
    'redirect-vært' => 'intern.evil.test', 'var-sti' => $var, 'lydfil-sti' => 'recordings/', 'Bearer' => 'Bearer'];
foreach ($tokens as $i => $t) {
    $forbidden['token ' . ($i + 1)] = $t;
}
foreach ($forbidden as $what => $needle) {
    F002Check::that('AK10', !str_contains($haystack, $needle), "$what findes ikke i UI, fejltekster eller logs");
}
F002Check::that('AK10', $logs !== [], 'der er log-linjer at tjekke');
foreach ($logs as $l) {
    F002Check::that('AK10', preg_match('/^zendesk-sync: (stoppet: [^\/]+|opkald [0-9]+: lyd ikke hentet: [^\/]+)$/u', $l) === 1,
        'log-linjen nævner kun opkalds-id og en fast tekst: ' . $l);
}
F002Check::that('AK10', str_contains(f002_get($c, '/opkald/' . $id)->body, 'optagelsen ligger på en vært, der ikke er godkendt'),
    'fejlen ses på opkaldet uden værtsnavn');

// Adgangsloggen (security S4): én linje pr. hentning af lyd og transcript med bruger-id og opkalds-id, ingen PII
$uid = (int) \Whisper\Users::findByEmail('bruger@f002.test')['id'];
@unlink($var . '/adgang.log');
f002_get($c, '/opkald/' . $ok . '/lyd');
f002_get($c, '/opkald/' . $ok . '/lyd', [], ['range' => 'bytes=0-99']);
f002_get($c, '/opkald/' . $ok . '/lyd', [], ['range' => 'bytes=100-199']);   // afspilleren spoler: ingen ny linje
f002_get($c, '/opkald/' . $ok . '/transcript');
$lines = file($var . '/adgang.log', FILE_IGNORE_NEW_LINES) ?: [];
$want = ["bruger=$uid lyd opkald=$ok", "bruger=$uid lyd opkald=$ok", "bruger=$uid transcript opkald=$ok"];
F002Check::that('AK10', array_map(static fn(string $l): string => substr($l, 21), $lines) === $want,
    'adgang.log: lyd (hel og første interval) og transcript med bruger- og opkalds-id: ' . implode(' | ', $lines));
F002Check::that('AK10', preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z /', $lines[0] ?? '') === 1, 'linjerne har tidsstempel');
$al = implode("\n", $lines);
F002Check::that('AK10', !str_contains($al, '+45') && !str_contains($al, 'Agent') && !str_contains($al, 'recordings') && !str_contains($al, 'bruger@'),
    'ingen telefonnumre, navne, e-mails eller stier i adgangsloggen');

// En rate limit vises som information, ikke som rød fejl (QA F-8)
DB::exec("UPDATE sync_state SET last_error = 'rate limit (429) — Zendesk bad os vente; hentningen fortsætter ved næste kørsel'");
$home = f002_get($c, '/')->body;
F002Check::that('AK10', str_contains($home, '<p class="msg info">Zendesk bad os vente (rate limit).') && !str_contains($home, 'msg error'),
    '429 vises som info');

F002Check::done();
