<?php
declare(strict_types=1);

// F008-AK9: med F008_leads slukket er Adversus-fanen som F007 (opkaldskort, "Alle opkald", antal i opkald), /lead/... giver
// 404, /opkald/{id} har intet lead-link, og MCP har kun de fire værktøjer. Flaget virker kun oven på F007 (og F004).

require_once __DIR__ . '/f008_lib.php';

use Whisper\Flags;
use Whisper\Leads;
use Whisper\Mcp\ToolError;
use Whisper\Mcp\Tools;
use Whisper\UiV2;

f008_setup(false);
$c = f002_login();
$a = f008_call(['lead_id' => 1201, 'is_success' => 1, 'started_at' => '2026-11-03 08:00:00', 'transcript_status' => 'faerdig']);
f008_done($a, 'Hej');
$b = f008_call(['lead_id' => 1201, 'is_success' => 1, 'started_at' => '2026-11-04 08:00:00', 'transcript_status' => 'ventende']);

$r = f007_get($c, '/', ['kilde' => 'adversus']);
F008Check::that('AK9', f007_ids($r) === [$b, $a] && !str_contains($r->body, 'lead-card') && !str_contains($r->body, '/lead/'), 'listen er F007\'s opkaldskort');
F008Check::that('AK9', str_contains($r->body, '>Alle opkald</a>') && str_contains($r->body, 'Viser 1–2 af 2 opkald') && !str_contains($r->body, ' leads</span>'),
    'tekster og antal som F007');
foreach (['/lead/1201', '/lead/uden', '/lead/1201/transcript', '/lead/1201/download'] as $path) {
    F008Check::that('AK9', f007_get($c, $path)->status === 404, "$path: 404");
}
$d = f007_get($c, '/opkald/' . $a);
F008Check::that('AK9', !str_contains($d->body, 'Se hele leadet') && str_contains($d->body, '<dd>#1201 · Succes-lead</dd>'), '/opkald/{id}: intet lead-link');
$names = array_map(static fn(array $t): string => (string) $t['name'], Tools::definitions());
sort($names);
F008Check::that('AK9', $names === ['call_stats', 'get_transcript', 'list_recent_calls', 'search_calls'], 'MCP: de fire værktøjer');
$err = '';
try {
    Tools::call('get_lead', ['lead_id' => 1201], 'https://whisper.test', time());
} catch (ToolError $e) {
    $err = $e->getMessage();
}
F008Check::that('AK9', $err === 'Ukendt værktøj.', 'MCP: get_lead findes ikke');
$rp = $c->post('/opkald/' . $b . '/transcriber', ['retur' => 'lead']);
F008Check::that('AK9', ($rp->headers['Location'] ?? '') === '/opkald/' . $b, '"Transcribér" med retur=lead: som før til /opkald');

// Flaget tændt, men F007 slukket: intet af F008
Flags::override(Leads::FLAG, true);
Flags::override(UiV2::FLAG, false);
F008Check::that('AK9', !Leads::on() && f007_get($c, '/lead/1201')->status === 404, 'kræver F007_kilder');
Flags::override(UiV2::FLAG, true);
F008Check::that('AK9', Leads::on(), 'tændt med F007 og F004');
F008Check::done();
