<?php
declare(strict_types=1);

// F005-AK9: kopiér-knapper (data-copy, hidden i HTML) på engangskode, MCP-nøgle, MCP-konfiguration og transcript,
// og en fælles role="status"-region i layoutet.

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Mcp\Server;

f005_setup();
Flags::override(Server::FLAG, true);
DB::exec('DELETE FROM mcp_rate');
DB::exec('DELETE FROM mcp_tokens');
$c = f005_admin();

$copy = static fn(string $target): string => 'data-copy="#' . $target . '" hidden>';

// Engangskode
$otp = $c->post('/brugere/ny', ['email' => 'kopi@f005.test', 'name' => 'Kopi', 'role' => 'user']);
F005Check::that('AK9', $otp->status === 200 && str_contains($otp->body, 'id="engangskode" type="text" readonly')
    && str_contains($otp->body, $copy('engangskode')), 'engangskode: felt og skjult kopiér-knap');
F005Check::that('AK9', str_contains($otp->body, 'Færdig – til brugeren') && str_contains($otp->body, 'Til listen'), 'engangskode: Færdig og Til listen');
F005Check::that('AK9', str_contains($otp->body, '<div class="sr-only" role="status" id="live"></div>'), 'fælles role="status"-region');

// MCP-nøgle og konfiguration
$k = $c->post('/konto/mcp-noegle', ['name' => 'Laptop <1>']);
F005Check::that('AK9', $k->status === 200 && preg_match('/id="mcp-noegle" type="text" readonly value="wsp_[A-Za-z0-9_-]{43}"/', $k->body) === 1,
    'MCP-nøglen i readonly-felt');
F005Check::that('AK9', str_contains($k->body, $copy('mcp-noegle')) && str_contains($k->body, 'Kopiér nøgle'), 'MCP-nøgle: kopiér-knap');
F005Check::that('AK9', str_contains($k->body, '<code id="mcp-config" tabindex="0"') && str_contains($k->body, $copy('mcp-config'))
    && str_contains($k->body, 'Kopiér konfiguration'), 'MCP-konfiguration: kopiér-knap');
F005Check::that('AK9', str_contains($k->body, 'Nøglen «Laptop &lt;1&gt;» er oprettet') && $k->header('Cache-Control') === 'no-store',
    'navnet escapes, svaret caches ikke');
$konto = f002_get($c, '/konto')->body;
F005Check::that('AK9', str_contains($konto, '<div class="card medium">') && str_contains($konto, 'Dine nøgler (1 af 5)')
    && str_contains($konto, 'class="button danger-secondary small" aria-label="Tilbagekald nøglen «Laptop &lt;1&gt;»"'),
    'konto: .card.medium, nøglelisten og tilbagekald-knap');
F005Check::that('AK9', !str_contains($konto, 'mcp-config') && !preg_match('/wsp_[A-Za-z0-9_-]{43}/', $konto), 'nøglen vises ikke igen');

// Transcript
$id = f002_insert();
f002_audio($id);
f002_transcript($id, 'Hej.');
$d = f002_get($c, '/opkald/' . $id)->body;
F005Check::that('AK9', str_contains($d, '<ol class="transcript" id="transcript">') && str_contains($d, $copy('transcript'))
    && str_contains($d, 'Kopiér tekst'), 'transcript: kopiér-knap mod #transcript');
F005Check::that('AK9', str_contains($d, 'data-js-only hidden'), 'hastighedsknapper skjult uden JS');
F005Check::done();
