<?php
declare(strict_types=1);

// F002-AK5: opkaldslisten viser felterne, sorteres nyeste først, pagineres og filtreres på periode, retning, agent og
// fritekst. Tomme tilstande: "Ingen opkald endnu", "Zendesk er ikke sat op".

require_once __DIR__ . '/f002_lib.php';

use Whisper\Calls;
use Whisper\Response;

f002_setup();
$c = f002_login();

$rows = static fn(Response $r): int => substr_count($r->body, '<tr><td><a href="/opkald/');
$r = f002_get($c, '/');
F002Check::that('AK5', $r->status === 200 && str_contains($r->body, 'Ingen opkald endnu.'), 'tom liste: "Ingen opkald endnu."');
F002Check::that('AK5', str_contains($r->body, 'Zendesk er ikke sat op.'), 'uden [zendesk]: "Zendesk er ikke sat op."');
F002Check::that('AK5', str_contains($r->body, 'Sidst hentet fra Zendesk: aldrig'), 'status: aldrig hentet');

// 55 opkald, ét pr. time; agent og retning skifter
for ($i = 0; $i < 55; $i++) {
    f002_insert(['started_at' => gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + $i * 3600),
        'agent_name' => $i % 2 === 0 ? 'Anne' : 'Bo <b>', 'direction' => $i % 3 === 0 ? 'outbound' : 'inbound',
        'customer_phone' => '+45 1000 ' . sprintf('%04d', $i), 'ticket_id' => 7000 + $i, 'duration_sec' => 61]);
}
$r = f002_get($c, '/');
F002Check::that('AK5', $rows($r) === 50, '50 pr. side (fik ' . $rows($r) . ')');
F002Check::that('AK5', str_contains($r->body, '55 opkald') && str_contains($r->body, 'Side 1 af 2'), 'antal og sideangivelse');
// Nyeste først: opkald 54 (2026-10-03 06:00 UTC = 08:00 dansk sommertid) står før opkald 53
$p54 = strpos($r->body, '+45 1000 0054');
$p53 = strpos($r->body, '+45 1000 0053');
F002Check::that('AK5', $p54 !== false && $p53 !== false && $p54 < $p53, 'nyeste først');
F002Check::that('AK5', str_contains($r->body, '03.10.2026 08:00'), 'dato og tid i dansk tid');
F002Check::that('AK5', str_contains($r->body, 'Udgående') && str_contains($r->body, 'Indgående'), 'retning vises');
F002Check::that('AK5', str_contains($r->body, '1:01'), 'varighed vises');
F002Check::that('AK5', str_contains($r->body, '>Lyd mangler<') && !str_contains($r->body, '>Venter<'),
    'uden hentet lyd vises "Lyd mangler", ikke "Venter" (QA F-6)');
F002Check::that('AK5', str_contains($r->body, '0 venter på transcription · 55 venter på lyd'), 'statuslinjen tæller kun hentet lyd som ventende');
$withAudio = (int) \Whisper\DB::scalar('SELECT id FROM calls ORDER BY started_at DESC LIMIT 1');
f002_audio($withAudio);
$failedAudio = (int) \Whisper\DB::scalar('SELECT id FROM calls ORDER BY started_at DESC LIMIT 1 OFFSET 1');
\Whisper\DB::exec("UPDATE calls SET audio_status = 'fejlet' WHERE id = ?", [$failedAudio]);
$r = f002_get($c, '/');
F002Check::that('AK5', str_contains($r->body, '>Venter<') && str_contains($r->body, '>Lyd fejlet<'), 'status: Venter (lyd hentet) og Lyd fejlet');
F002Check::that('AK5', str_contains($r->body, '1 venter på transcription'), 'statuslinjen: 1 venter på transcription');
F002Check::that('AK5', str_contains($r->body, 'Ord på under 3 tegn'), 'hjælpetekst under søgefeltet (QA F-7)');
F002Check::that('AK5', str_contains($r->body, 'Bo &lt;b&gt;') && !str_contains($r->body, 'Bo <b>'), 'agentnavn escapes');
F002Check::that('AK5', str_contains($r->body, '>7054<'), 'ticket-nummer vises');
$r2 = f002_get($c, '/', ['side' => '2']);
F002Check::that('AK5', $rows($r2) === 5 && str_contains($r2->body, '+45 1000 0000'), 'side 2 har de sidste 5 (de ældste)');
$r99 = f002_get($c, '/', ['side' => '99999']);
F002Check::that('AK5', $rows($r99) === 5 && str_contains($r99->body, 'Side 2 af 2'), 'side ud over sidste: klemt til sidste side (QA F-9)');
F002Check::that('AK5', $rows(f002_get($c, '/', ['side' => ['x']])) === 50, 'ugyldig side-parameter: side 1');

// Retning og agent
F002Check::that('AK5', $rows(f002_get($c, '/', ['retning' => 'outbound'])) === 19, 'retning udgående (19)');
F002Check::that('AK5', $rows(f002_get($c, '/', ['retning' => 'sideways'])) === 50, 'ugyldig retning ignoreres');
F002Check::that('AK5', $rows(f002_get($c, '/', ['agent' => 'Bo <b>'])) === 27, 'agent-filter (27)');
F002Check::that('AK5', str_contains(f002_get($c, '/', ['agent' => 'Ingen'])->body, 'Ingen opkald matcher filtret.'), 'tomt filterresultat');

// Periode (danske datoer): 2026-10-02 er 22:00 UTC 1/10 til 22:00 UTC 2/10 → opkald 22..45
$r = f002_get($c, '/', ['fra' => '2026-10-02', 'til' => '2026-10-02']);
F002Check::that('AK5', $rows($r) === 24, 'periode én dag i dansk tid (24, fik ' . $rows($r) . ')');
F002Check::that('AK5', $rows(f002_get($c, '/', ['fra' => '2026-10-03'])) === 9, 'kun fra-dato (9)');
F002Check::that('AK5', $rows(f002_get($c, '/', ['til' => '2026-10-01'])) === 22, 'kun til-dato (22)');
F002Check::that('AK5', $rows(f002_get($c, '/', ['fra' => '2026-13-45'])) === 50, 'ugyldig dato ignoreres');
F002Check::that('AK5', Calls::filter(['til' => '2026-02-30'])['til'] === '', 'dato, der ikke findes, ignoreres');

// Fritekst i transcripts (FULLTEXT ved ord på mindst 3 tegn, ellers LIKE)
$ids = array_map(static fn(array $r): int => (int) $r['id'], \Whisper\DB::all('SELECT id FROM calls ORDER BY started_at LIMIT 3'));
f002_transcript($ids[0], 'Kunden spørger om elmåleren og regningen.');
f002_transcript($ids[1], 'Flytning af elmåler til ny adresse.');
f002_transcript($ids[2], 'Noget helt andet: 50% rabat_kode.');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => 'elmåler'])) === 2, 'fritekst "elmåler" (præfiks) finder 2');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => 'ELMÅLER regningen'])) === 1, 'flere ord: alle skal findes; uden forskel på store/små');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => 'xyzzy'])) === 0, 'ingen træf');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => '50'])) === 1, 'kort ord via LIKE');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => '"+-*()~<>@'])) === 50, 'kun operatortegn: ignoreres (ingen SQL-fejl)');
F002Check::that('AK5', $rows(f002_get($c, '/', ['q' => 'elmåler', 'agent' => 'Anne'])) === 1, 'fritekst + agent kombineres');
$r = f002_get($c, '/', ['q' => 'elmåler', 'retning' => 'inbound']);
F002Check::that('AK5', str_contains($r->body, 'href="/opkald/download?q=elm%C3%A5ler&amp;retning=inbound"')
    || str_contains($r->body, 'href="/opkald/download?retning=inbound&amp;q=elm%C3%A5ler"'), 'zip-linket bærer filtret');
F002Check::that('AK5', Calls::filter(['agent' => str_repeat('x', 500)])['agent'] === str_repeat('x', 120), 'lange værdier afkortes');

F002Check::done();
