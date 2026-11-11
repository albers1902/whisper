<?php
declare(strict_types=1);

// F005-AK8: aktive filtre vises som chips, der hver linker til query'en uden netop det filter. "Viser a–b af N
// opkald" og nav.pager vises, og zip-knappen har den nye tekst og skjules ved 0.

require_once __DIR__ . '/f005_lib.php';

f005_setup();
$c = f002_login();

$empty = f002_get($c, '/')->body;
F005Check::that('AK8', str_contains($empty, 'Ingen opkald endnu') && str_contains($empty, 'Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter.'),
    'tom liste: "Ingen opkald endnu"');
F005Check::that('AK8', !str_contains($empty, 'Download færdige transcripts') && !str_contains($empty, 'Nulstil filtre')
    && !str_contains($empty, 'Aktive filtre'), 'ved 0: ingen zip, ingen Nulstil, ingen chips');

for ($i = 0; $i < 120; $i++) {
    f002_insert(['started_at' => gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + $i * 3600), 'agent_name' => 'Anne',
        'direction' => $i % 2 === 0 ? 'inbound' : 'outbound']);
}
$r = f002_get($c, '/', ['side' => '2']);
$b = $r->body;
F005Check::that('AK8', str_contains($b, 'Viser 51–100 af 120 opkald'), '"Viser 51–100 af 120 opkald"');
F005Check::that('AK8', str_contains($b, '<nav class="pager" aria-label="Sider">') && str_contains($b, 'Side 2 af 3'), 'nav.pager med "Side 2 af 3"');
F005Check::that('AK8', str_contains($b, '<a class="button secondary small" href="/">'), 'Forrige til side 1 (/)');
F005Check::that('AK8', str_contains($b, 'href="/?side=3"'), 'Næste til side 3');
$last = f002_get($c, '/', ['side' => '3'])->body;
F005Check::that('AK8', str_contains($last, 'Viser 101–120 af 120 opkald') && str_contains($last, '<span class="button secondary small" aria-disabled="true">Næste'),
    'sidste side: Næste er deaktiveret');
F005Check::that('AK8', str_contains($b, 'Download færdige transcripts (.zip)') && str_contains($b, 'Alle færdige transcripts i filtret, højst 2000.')
    && str_contains($b, 'href="/opkald/download"'), 'zip: ny tekst og hjælpetekst');

$q = ['q' => 'faktura', 'agent' => 'Anne', 'fra' => '2026-10-01', 'retning' => 'inbound'];
$f = f002_get($c, '/', $q)->body;
F005Check::that('AK8', str_contains($f, '<ul aria-labelledby="chips-label">'), 'chips i en liste med label');
$chip = static function (string $body, string $label): ?string {
    $re = '#<a class="chip" href="([^"]*)"><span aria-hidden="true">' . preg_quote($label, '#') . ' ×</span>#';
    return preg_match($re, $body, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
F005Check::that('AK8', $chip($f, 'Søgning: faktura') === '/?fra=2026-10-01&retning=inbound&agent=Anne', 'chip Søgning fjerner kun q');
F005Check::that('AK8', $chip($f, 'Agent: Anne') === '/?fra=2026-10-01&retning=inbound&q=faktura', 'chip Agent fjerner kun agent');
F005Check::that('AK8', $chip($f, 'Fra 01.10.2026') === '/?retning=inbound&agent=Anne&q=faktura', 'chip Fra fjerner kun fra');
F005Check::that('AK8', $chip($f, 'Retning: Indgående') === '/?fra=2026-10-01&agent=Anne&q=faktura', 'chip Retning fjerner kun retning');
F005Check::that('AK8', str_contains($f, '<span class="sr-only">Fjern filter: Søgning faktura</span>'), 'skjult tekst "Fjern filter: …"');
F005Check::that('AK8', str_contains($f, 'Ingen opkald matcher') && str_contains($f, 'Nulstil filtre'), 'filter uden resultat: tomtilstand med Nulstil');
F005Check::that('AK8', !str_contains($f, 'Download færdige transcripts'), 'zip skjult ved 0 resultater');

$x = f002_get($c, '/', ['agent' => '<b>x</b>'])->body;
F005Check::that('AK8', str_contains($x, 'Agent: &lt;b&gt;x&lt;/b&gt; ×') && !str_contains($x, '<b>x</b>'), 'chip-tekst escapes');
$rev = f002_get($c, '/', ['fra' => '2026-10-05', 'til' => '2026-10-01'])->body;
F005Check::that('AK8', str_contains($rev, 'Til-datoen er før fra-datoen.'), 'Til før Fra: besked');
F005Check::that('AK8', str_contains(f002_get($c, '/', ['q' => 'ab'])->body, 'Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf.'),
    'kun korte søgeord: tip');
F005Check::done();
