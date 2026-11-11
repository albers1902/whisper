<?php
declare(strict_types=1);

// H002-AK2: indeholder Zendesks error_description client_secret, client_id eller noget token-lignende, fjernes det.
// Teksten er højst 200 tegn og kun printbar ASCII. Fejlteksten ender i probe, log, sync_state og på siden.

require_once __DIR__ . '/h002_lib.php';

use Whisper\Zendesk\Client;

$cfg = f002_cfg();
$secret = $cfg->clientSecret;
$id = $cfg->clientId;
$tok = 'at' . bin2hex(random_bytes(16));
$jwt = 'eyJ' . bin2hex(random_bytes(10)) . '.' . bin2hex(random_bytes(10));

$desc = "client $id with client_secret=$secret is bad; access_token: \"$tok\" Authorization: Bearer $jwt; raw $secret";
$msg = h002_token_error($cfg, f002_json(['error' => 'invalid_client', 'error_description' => $desc], 400));
foreach (['client_secret' => $secret, 'client_id' => $id, 'token' => $tok, 'jwt' => $jwt] as $what => $v) {
    H002Check::that('AK2', !str_contains($msg, $v), "$what er fjernet fra fejlteksten");
}
H002Check::that('AK2', str_contains($msg, 'invalid_client') && str_contains($msg, '[fjernet]'), "koden står der, værdierne er erstattet ($msg)");

// Hemmeligheden skjult i ikke-printbare tegn samles ikke igen til den rigtige værdi
$split = substr($secret, 0, 6) . "\u{00e6}" . substr($secret, 6);
$t = Client::oauthErrorText(400, (string) json_encode(['error' => 'invalid_client', 'error_description' => "x $secret y"]), [$id, $secret]);
H002Check::that('AK2', !str_contains($t, $secret), 'secret fjernes også direkte');
$t = Client::oauthErrorText(400, (string) json_encode(['error' => 'x', 'error_description' => $split]), [$id, $secret]);
H002Check::that('AK2', !str_contains($t, $secret) && !str_contains($t, substr($secret, 6)), 'secret med indskudt ikke-ASCII lækker ikke');

// Kun printbar ASCII, højst 200 tegn
$long = "Linje\n\t\u{001b}[31mrød\u{00f8} " . str_repeat('ord ', 200);
$t = Client::oauthErrorText(400, (string) json_encode(['error' => "invalid_client\u{0000}", 'error_description' => $long]), [$id, $secret]);
H002Check::that('AK2', strlen($t) <= 200, 'højst 200 tegn (fik ' . strlen($t) . ')');
H002Check::that('AK2', h002_ascii($t), 'kun printbar ASCII');
$m = h002_token_error($cfg, f002_json(['error' => 'invalid_client', 'error_description' => $long], 400));
H002Check::that('AK2', h002_ascii($m) && strlen($m) <= 200 + strlen('Zendesk afviste OAuth-klienten ()'), 'hele fejlteksten er ASCII og kort');

// Almindelige fejlkoder bevares (ingen cifre, under 32 tegn)
$t = Client::oauthErrorText(400, '{"error":"unsupported_grant_type","error_description":"The authorization grant type is not supported"}', [$id, $secret]);
H002Check::that('AK2', $t === 'HTTP 400: unsupported_grant_type - The authorization grant type is not supported', "almindelig tekst bevares ($t)");

// Ikke-streng-felter ignoreres
$t = Client::oauthErrorText(400, '{"error":["a"],"error_description":{"x":1}}', [$id, $secret]);
H002Check::that('AK2', $t === 'HTTP 400', 'error/error_description, der ikke er tekst, ignoreres');

H002Check::done();
