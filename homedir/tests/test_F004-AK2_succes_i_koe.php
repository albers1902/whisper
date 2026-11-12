<?php
declare(strict_types=1);

// F004-AK2: opkald på et succes-lead kommer i transcription-køen (lyd hentet, status ventende, og den eksisterende kø
// tager det). Opkald uden succes står som "ikke valgt", og der hentes ingen lyd til dem.

require_once __DIR__ . '/f004_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

f004_setup();
$S = f004_since();
$now = $S + 3 * 3600;
$http = new FakeAdversus();
f004_recorded($http, 2001, 601, $S + 1200, 80);   // succes-lead
f004_recorded($http, 2002, 602, $S + 1500, 90);   // ikke succes
f004_lead($http, 601, 'success', $S + 600);

['sync' => $sync] = f004_sync(f004_cfg(), $http, $now);
$r = $sync->run();

$a = DB::all("SELECT * FROM calls WHERE `source` = 'adversus' AND external_id = '2001'")[0];
$b = DB::all("SELECT * FROM calls WHERE `source` = 'adversus' AND external_id = '2002'")[0];
F004Check::that('AK2', (int) $a['is_success'] === 1 && $a['transcript_status'] === 'ventende', 'succes-opkaldet er markeret og venter');
F004Check::that('AK2', $a['audio_status'] === 'hentet' && \Whisper\Calls::audioFile((string) $a['audio_path']) !== null, 'lyden til succes-opkaldet er hentet');
F004Check::that('AK2', (int) $b['is_success'] === 0 && $b['transcript_status'] === 'ikke_valgt', 'det andet opkald står som "ikke valgt"');
F004Check::that('AK2', $b['audio_status'] === 'venter' && $b['audio_path'] === null, 'ingen lyd til "ikke valgt"');
F004Check::that('AK2', $http->to('/v1/calls/2002/recording') === [], 'der er ikke spurgt efter lyd til "ikke valgt"');
F004Check::that('AK2', count($http->to('/v1/calls/2001/recording')) === 1, 'lyden til succes-opkaldet er hentet via /calls/{id}/recording');

// Den eksisterende kø (F002/H006) tager kun succes-opkaldet
$t = new Transcriber(new FakeEngine(), static fn(): int => time());
$jobs = $t->claim(5);
F004Check::that('AK2', array_column($jobs, 'id') === [(int) $a['id']], 'transcription-køen tager succes-opkaldet og ikke "ikke valgt"');

// Prioritet (security S1): et manuelt valgt Adversus-opkald kommer efter Zendesk og succes, også når det er ældst
$manual = f004_insert(2099, false, 'ventende', '2026-11-01 08:00:00');
f002_audio($manual);
$zen = f002_insert(['started_at' => '2026-11-02 08:00:00']);
f002_audio($zen);
DB::exec("UPDATE calls SET transcript_status = 'ventende', claimed_at = NULL WHERE id = ?", [(int) $a['id']]);
$order = array_column($t->claim(3), 'id');
F004Check::that('AK2', $order === [$zen, (int) $a['id'], $manual], 'claim: Zendesk og succes før manuelt valgte (fik ' . implode(',', $order) . ')');
DB::exec('DELETE FROM calls WHERE id IN (?, ?)', [$zen, $manual]);
DB::exec("UPDATE calls SET transcript_status = 'ventende', claimed_at = NULL, transcript_attempts = 0 WHERE id = ?", [(int) $a['id']]);

// Siden tæller ikke "ikke valgt" som "venter på lyd"
$st = \Whisper\Calls::status();
F004Check::that('AK2', $st['audio_waiting'] === 0, '"ikke valgt" tælles ikke som "venter på lyd"');

// En ny kørsel henter stadig ikke lyd til "ikke valgt"
$before = count($http->requests);
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600);
$sync2->run();
F004Check::that('AK2', $http->to('/v1/calls/2002/recording') === [], 'heller ikke ved næste kørsel');
F004Check::that('AK2', count($http->requests) > $before, '(næste kørsel kørte)');
F004Check::done();
