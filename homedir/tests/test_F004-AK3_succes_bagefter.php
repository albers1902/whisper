<?php
declare(strict_types=1);

// F004-AK3: et lead, der bliver success, efter opkaldet er gemt, markerer opkaldet og sætter det i kø ved næste kørsel.
// Opkald i kø eller færdige ændres ikke, og markeringen fjernes ikke, hvis leadet senere skifter status.

require_once __DIR__ . '/f004_lib.php';

use Whisper\DB;

f004_setup();
$S = f004_since();
$now = $S + 3 * 3600;
$http = new FakeAdversus();
f004_recorded($http, 3001, 701, $S + 1200, 80);
f004_recorded($http, 3002, 702, $S + 1300, 80);
f004_lead($http, 701, 'notInterested', $S + 600);

['sync' => $sync] = f004_sync(f004_cfg(), $http, $now);
$sync->run();
$row = static fn(string $ext): array => DB::all("SELECT * FROM calls WHERE `source` = 'adversus' AND external_id = ?", [$ext])[0];
F004Check::that('AK3', $row('3001')['transcript_status'] === 'ikke_valgt' && (int) $row('3001')['is_success'] === 0, 'før: ikke valgt');

// Opkald 3002 er valgt manuelt og færdigt i mellemtiden
$id2 = (int) $row('3002')['id'];
f002_transcript($id2, 'Hej');

// Leadet bliver success efter opkaldet er gemt (lastUpdatedTime efter lead-cursoren); 702 bliver også success
f004_lead($http, 701, 'success', $now + 100);
f004_lead($http, 702, 'success', $now + 100);
['sync' => $sync2] = f004_sync(f004_cfg(), $http, $now + 600);
$r = $sync2->run();
$a = $row('3001');
F004Check::that('AK3', (int) $a['is_success'] === 1 && $a['audio_status'] === 'hentet' && $a['transcript_status'] === 'ventende',
    'næste kørsel: markeret, lyd hentet og i kø');
F004Check::that('AK3', $r['success'] >= 1, 'loglinjen tæller den nye succes');
$b = $row('3002');
F004Check::that('AK3', (int) $b['is_success'] === 1 && $b['transcript_status'] === 'faerdig', 'et færdigt opkald markeres, men status ændres ikke');

// Markeringen fjernes ikke automatisk
f004_lead($http, 701, 'notInterested', $now + 900);
['sync' => $sync3] = f004_sync(f004_cfg(), $http, $now + 1200);
$sync3->run();
F004Check::that('AK3', (int) $row('3001')['is_success'] === 1, 'succes-markeringen bliver stående');

// Den anden vej: leadet er success, FØR opkaldet hentes (opkald gemt efter leadet)
f004_lead($http, 703, 'success', $now + 1300);
f004_recorded($http, 3003, 703, $now + 1250, 60);
['sync' => $sync4] = f004_sync(f004_cfg(), $http, $now + 4000);
$sync4->run();
F004Check::that('AK3', (int) $row('3003')['is_success'] === 1 && $row('3003')['transcript_status'] === 'ventende', 'opkald hentet efter leadet: succes straks');
F004Check::done();
