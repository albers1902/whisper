<?php
declare(strict_types=1);

// F006-AK5: Afvis på godkendelsessiden returnerer error=access_denied til redirect_uri med state (og iss), uden kode
// og uden bevilling. Godkendelsessiden har CSP form-action 'self' + præcis den registrerede redirect-origin (Chrome
// anvender form-action på redirecten efter POST), og stadig frame-ancestors 'none' og X-Frame-Options DENY.

require_once __DIR__ . '/f006_lib.php';

use Whisper\DB;

f006_setup();
[$c] = f003_login();
$client = f006_register();
[, $challenge] = f006_pkce();
$q = f006_query($client, $challenge);
$page = f006_authorize($c, $q);

$csp = (string) $page->header('Content-Security-Policy');
F006Check::that('AK5', str_contains($csp, "form-action 'self' https://claude.ai;"), "godkendelsessiden: form-action 'self' https://claude.ai");
F006Check::that('AK5', str_contains($csp, "frame-ancestors 'none'") && $page->header('X-Frame-Options') === 'DENY', 'clickjacking-værn bevaret');
F006Check::that('AK5', $page->header('Cache-Control') === 'no-store', 'godkendelsessiden: no-store');
$konto = $c->get('/konto');
F006Check::that('AK5', str_contains((string) $konto->header('Content-Security-Policy'), "form-action 'self';"), "andre sider: form-action kun 'self'");

$r = f006_decide($c, f006_rid($page), 'afvis');
$loc = f006_location($r);
F006Check::that('AK5', $r->status === 303 && str_starts_with((string) $r->header('Location'), F006_CALLBACK . '?'), 'Afvis: redirect til callback');
F006Check::that('AK5', ($loc['error'] ?? '') === 'access_denied' && ($loc['state'] ?? '') === $q['state'] && ($loc['iss'] ?? '') === F006_SITE
    && !isset($loc['code']), 'error=access_denied med state og iss, ingen kode');
F006Check::that('AK5', (int) DB::scalar('SELECT COUNT(*) FROM oauth_grants') === 0 && (int) DB::scalar('SELECT COUNT(*) FROM oauth_codes') === 0,
    'ingen bevilling og ingen kode');
F006Check::that('AK5', str_contains((string) @file_get_contents(\Whisper\AccessLog::file()), 'oauth_afvis'), 'afvisningen står i adgangsloggen');

// Uden state: ingen tom state-parameter
$page = f006_authorize($c, f006_query($client, $challenge, ['state' => '']));
$loc = f006_location(f006_decide($c, f006_rid($page), 'afvis'));
F006Check::that('AK5', ($loc['error'] ?? '') === 'access_denied' && !array_key_exists('state', $loc), 'uden state: ingen state i svaret');

F006Check::done();
