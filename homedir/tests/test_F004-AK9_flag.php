<?php
declare(strict_types=1);

// F004-AK9: med flaget slået fra kører sync ikke, og brugerfladen er uændret: listen er byte for byte som uden
// Adversus-rækker, detaljen, knappen og lyden til et Adversus-opkald giver 404, og MCP ser kun Zendesk. F004 kræver
// også F005_ui: med F005_ui slukket opfører F004 sig som slukket (Team Lead 7).

require_once __DIR__ . '/f004_lib.php';

use Whisper\Adversus\Feature;
use Whisper\Calls;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Ui;

f004_setup();
$c = f002_login();
$z = f002_insert(['started_at' => '2026-11-12 09:00:00']);

Flags::override(Feature::FLAG, false);
F004Check::that('AK9', !Feature::on(), 'flaget slukket: Feature::on() er falsk');
$before = f002_get($c, '/');
$ok = f004_insert(4201, true, 'ventende');
$no = f004_insert(4202, false, 'ikke_valgt');
$after = f002_get($c, '/');
$norm = static fn(string $b): string => (string) preg_replace('/datetime="[^"]*"|title="[^"]*siden[^"]*"|for [0-9]+ [a-zæø.]+ siden/u', '', $b);
F004Check::that('AK9', $norm($before->body) === $norm($after->body), 'listen er uændret, selv om der ligger Adversus-rækker');
F004Check::that('AK9', !str_contains($after->body, 'Kilde') && !str_contains($after->body, 'view-switch'), 'ingen Kilde-kolonne og ingen segmentvælger');
F004Check::that('AK9', f002_get($c, '/opkald/' . $ok)->status === 404 && f002_get($c, '/opkald/' . $ok . '/lyd')->status === 404, 'Adversus-detalje og lyd: 404');
F004Check::that('AK9', str_contains(f002_get($c, '/opkald/' . $z)->body, 'Alle opkald</a>') && !str_contains(f002_get($c, '/opkald/' . $z)->body, 'Kilde'),
    'Zendesk-detaljen er som før (tilbage-link "Alle opkald", ingen Kilde)');
F004Check::that('AK9', $c->post('/opkald/' . $no . '/transcriber')->status === 404, 'knappens adresse findes ikke');
F004Check::that('AK9', (string) DB::scalar('SELECT transcript_status FROM calls WHERE id = ?', [$no]) === 'ikke_valgt', 'intet ændret');
F004Check::that('AK9', Calls::filter(['vis' => 'alle'])['vis'] === '', 'vis=alle ignoreres');
$range = Calls::range(Calls::filter([]), 0, 50);
F004Check::that('AK9', $range['total'] === 1, 'MCP-udsnittet ser kun Zendesk');
F004Check::that('AK9', Calls::agents() === ['Agent A'], 'agentlisten har kun Zendesk-agenter');

// Sync kører ikke: cron-scriptet afslutter før lås, database og netværk. Alle andre flag er tændt via testløberens
// hook (kun CLI under WHISPER_TEST), så det er netop F004_adversus, der stopper det.
putenv('WHISPER_TEST_FLAGS=F001_brugere,F002_zendesk,F005_ui');
$php = PHP_BINARY;
$out = [];
$code = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../cron/adversus-sync.php') . ' 2>&1', $out, $code);
F004Check::that('AK9', $code === 0 && $out === [], 'cron/adversus-sync.php gør intet med flaget slukket (exit 0, ingen output)');
F004Check::that('AK9', DB::scalar("SELECT COUNT(*) FROM sync_state WHERE `source` LIKE 'adversus%'") == 0, 'ingen sync_state for Adversus');

// F005_ui slukket: F004 er slukket, selv om dets eget flag er tændt
Flags::override(Feature::FLAG, true);
Flags::override(Ui::FLAG, false);
F004Check::that('AK9', !Feature::on(), 'F004 kræver F005_ui');
F004Check::that('AK9', f002_get($c, '/opkald/' . $ok)->status === 404, 'gamle UI: Adversus-detaljen findes ikke');
Flags::override(Ui::FLAG, true);
F004Check::that('AK9', Feature::on() && f002_get($c, '/opkald/' . $ok)->status === 200, 'begge tændt: detaljen findes');
F004Check::done();
