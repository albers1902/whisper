<?php
declare(strict_types=1);

// H013-AK2: lydfiler hentes kun for besvarede succes-opkald og for manuelt valgte. Ikke-succes og opkald uden samtale
// (busy, no-answer …) får ingen fil og intet kald til /calls/{id}/recording. Bliver et lead success senere, hentes
// filen ved næste kørsel. Transcription-køen tager kun succes og manuelt valgte.

require_once __DIR__ . '/f004_lib.php';
require_once __DIR__ . '/h013_lib.php';

use Whisper\DB;
use Whisper\Transcription\Transcriber;

f004_setup();
$S = f004_since();
$now = $S + 3 * 3600;
$http = new FakeAdversus();
f004_call($http, 21, 31, $S + 600, 90);                                  // succes, besvaret
f004_call($http, 22, 31, $S + 700, 0, 'outbound', 77, 'no-answer');     // succes, ingen samtale
f004_call($http, 23, 32, $S + 800, 60);                                  // ikke succes
f004_call($http, 24, 33, $S + 900, 60);                                  // bliver succes senere
f004_call($http, 25, 34, $S + 1000, 60);                                 // vælges manuelt
f004_lead($http, 31, 'success', $S + 60);
f004_lead($http, 32, 'notInterested', $S + 60);
f004_lead($http, 33, 'automaticRedial', $S + 60);
['sync' => $sync] = f004_sync(f004_cfg(), $http, $now);
$sync->run();
$asked = static fn(int $ext): int => count($http->to('/v1/calls/' . $ext . '/recording'));
$row = static fn(int $ext): array => DB::all("SELECT * FROM calls WHERE `source` = 'adversus' AND external_id = ?", [(string) $ext])[0];

H013Check::that('AK2', $row(21)['audio_status'] === 'hentet' && $asked(21) === 1, 'besvaret succes-opkald: lyden er hentet');
H013Check::that('AK2', $row(22)['transcript_status'] === 'ikke_valgt' && $asked(22) === 0, 'succes uden samtale: ingen lyd, ikke i kø');
H013Check::that('AK2', $asked(23) === 0 && $asked(24) === 0 && $asked(25) === 0, 'ikke-succes: intet kald efter lyd');
H013Check::that('AK2', $row(23)['transcript_status'] === 'ikke_valgt' && $row(23)['audio_path'] === null, 'ikke-succes: "ikke valgt" og ingen fil');

// Leadet bliver success; opkald 25 vælges manuelt (som knappen gør)
f004_lead($http, 33, 'success', $now + 100);
f004_lead($http, 31, 'success', $now + 100);
DB::exec("UPDATE calls SET transcript_status = 'ventende', manual_at = ? WHERE `source` = 'adversus' AND external_id = '25'",
    [gmdate('Y-m-d H:i:s', $now + 200)]);
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600);
$r2 = $sync2->run();
H013Check::that('AK2', (int) $row(24)['is_success'] === 1 && $row(24)['audio_status'] === 'hentet' && $asked(24) === 1,
    'lead blev success: markeret og lyden hentet ved næste kørsel');
H013Check::that('AK2', $row(25)['audio_status'] === 'hentet' && $asked(25) === 1, 'manuelt valgt: lyden hentet');
H013Check::that('AK2', $asked(22) === 0 && $asked(23) === 0, 'stadig ingen lyd til de andre');
H013Check::that('AK2', $r2['queued'] === 2, 'loglinjen: 2 hentet (fik ' . $r2['queued'] . ')');

// Transcription-køen tager kun succes og det manuelt valgte
$jobs = array_map('intval', array_column((new Transcriber(new FakeEngine(), static fn(): int => time()))->claim(10), 'id'));
sort($jobs);
$want = [(int) $row(21)['id'], (int) $row(24)['id'], (int) $row(25)['id']];
sort($want);
H013Check::that('AK2', $jobs === $want, 'køen tager succes og manuelt valgte, intet andet');

// Et langt succes-opkald (40 min) logges først, når det er slut — efter at cursoren er passeret dets start. Den nylige
// kontrol finder det ved første kørsel efter, at det er logget (højst ca. 10–20 min), ikke først timer senere (QA M1).
$T = $now + 3600;
f004_lead($http, 35, 'success', $T - 600);
f004_call($http, 27, 36, $T + 200, 30);                       // et kort opkald efter T: cursoren (seneste start) passerer T
['sync' => $a] = f004_sync(f004_cfg(), $http, $T + 900);    // cursoren passerer T, opkaldet er ikke logget endnu
$a->run();
f004_call($http, 26, 35, $T, 2400);                           // logges ved slut, T + 40 min
['sync' => $b] = f004_sync(f004_cfg(), $http, $T + 2400 + 600);
$rb = $b->run();
H013Check::that('AK2', $row(26)['audio_status'] === 'hentet' && (int) $row(26)['is_success'] === 1 && $rb['rescanned'] >= 1,
    'langt succes-opkald (40 min) er hentet med lyd ved første kørsel efter, at det blev logget');
H013Check::done();
