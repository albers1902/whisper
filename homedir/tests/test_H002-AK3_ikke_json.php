<?php
declare(strict_types=1);

// H002-AK3: et svar, der ikke er JSON (fx en HTML-fejlside), giver en generisk tekst med HTTP-status og uden HTML.

require_once __DIR__ . '/h002_lib.php';

use Whisper\Zendesk\HttpResult;

$cfg = f002_cfg();
$html = '<!DOCTYPE html><html><head><title>Bad Request</title></head><body><h1>400</h1><script>x()</script></body></html>';

$msg = h002_token_error($cfg, new HttpResult(400, ['content-type' => 'text/html'], $html));
H002Check::that('AK3', $msg === 'Zendesk afviste OAuth-klienten (HTTP 400)', "HTML-side: kun HTTP-status (fik: $msg)");
H002Check::that('AK3', !str_contains($msg, '<') && !str_contains($msg, 'Bad Request'), 'ingen HTML i teksten');

$msg = h002_token_error($cfg, new HttpResult(502, [], 'upstream error'));
H002Check::that('AK3', $msg === 'Zendesk afviste OAuth-klienten (HTTP 502)', "ren tekst: kun HTTP-status (fik: $msg)");

$msg = h002_token_error($cfg, new HttpResult(400, [], '{"message":"nope"}'));
H002Check::that('AK3', $msg === 'Zendesk afviste OAuth-klienten (HTTP 400)', "JSON uden error-felter: kun HTTP-status (fik: $msg)");

$msg = h002_token_error($cfg, new HttpResult(401, [], ''));
H002Check::that('AK3', $msg === 'Zendesk afviste OAuth-klienten (HTTP 401)', "tomt svar: kun HTTP-status (fik: $msg)");

H002Check::done();
