<?php
declare(strict_types=1);

// F006-AK1: metadata-endpointene svarer med korrekt JSON (RFC 9728 og RFC 8414), og /mcp uden token giver 401 med
// WWW-Authenticate: Bearer … resource_metadata="…".

require_once __DIR__ . '/f006_lib.php';

f006_setup();

foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $path) {
    $r = f006_ep('GET', $path);
    $j = f006_json($r);
    F006Check::that('AK1', $r->status === 200 && str_starts_with((string) $r->header('Content-Type'), 'application/json'), "$path: 200 JSON");
    F006Check::that('AK1', ($j['resource'] ?? null) === F006_RESOURCE, "$path: resource = https://<vært>/mcp");
    F006Check::that('AK1', ($j['authorization_servers'] ?? null) === [F006_SITE], "$path: authorization_servers = [issuer] (samme vært)");
}

$r = f006_ep('GET', '/.well-known/oauth-authorization-server');
$j = f006_json($r);
F006Check::that('AK1', $r->status === 200, 'AS-metadata: 200');
F006Check::that('AK1', ($j['issuer'] ?? null) === F006_SITE, 'issuer = https://<vært>');
F006Check::that('AK1', ($j['authorization_endpoint'] ?? null) === F006_SITE . '/oauth/authorize'
    && ($j['token_endpoint'] ?? null) === F006_SITE . '/oauth/token' && ($j['registration_endpoint'] ?? null) === F006_SITE . '/oauth/register',
    'authorize-, token- og registration-endpoint');
F006Check::that('AK1', ($j['code_challenge_methods_supported'] ?? null) === ['S256'], 'kun S256');
F006Check::that('AK1', ($j['grant_types_supported'] ?? null) === ['authorization_code', 'refresh_token'], 'grant_types');
F006Check::that('AK1', ($j['response_types_supported'] ?? null) === ['code'], 'response_types = [code]');
F006Check::that('AK1', ($j['token_endpoint_auth_methods_supported'] ?? null) === ['none'], 'kun public clients');
F006Check::that('AK1', !array_key_exists('client_id_metadata_document_supported', $j), 'CIMD annonceres ikke (DCR i stedet)');
F006Check::that('AK1', ($j['authorization_response_iss_parameter_supported'] ?? null) === true, 'iss i autorisationssvaret (RFC 9207)');

F006Check::that('AK1', f006_ep('POST', '/.well-known/oauth-authorization-server')->status === 405, 'POST på metadata: 405');

$r = (new F003Mcp(null))->rpc('tools/list');
$www = (string) $r->header('WWW-Authenticate');
F006Check::that('AK1', $r->status === 401, '/mcp uden token: 401');
F006Check::that('AK1', str_starts_with($www, 'Bearer ')
    && str_contains($www, 'resource_metadata="' . F006_SITE . '/.well-known/oauth-protected-resource/mcp"'),
    'WWW-Authenticate peger på protected resource metadata');
F006Check::that('AK1', !str_contains($r->body, 'search_calls'), '401 uden data');

F006Check::done();
