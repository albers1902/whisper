<?php
declare(strict_types=1);

// F008-AK4: "Find i samtalen" virker på tværs af leadets opkald: ét felt over forløbet, der søger i alle samtaler
// ("n af m · opkald x af y"), og søgningen fra listen (q) markeres i alle opkald med "N fund i K opkald · Gå til første fund".

require_once __DIR__ . '/f008_lib.php';

f008_setup();
$c = f002_login();
$a = f008_call(['lead_id' => 801, 'is_success' => 1, 'started_at' => '2026-11-03 08:00:00', 'transcript_status' => 'faerdig']);
f008_done($a, 'Prisen er god og prisen holder', [['start' => 0.0, 'end' => 2.0, 'text' => 'Prisen er god'], ['start' => 3.0, 'end' => 4.0, 'text' => 'prisen holder']]);
$b = f008_call(['lead_id' => 801, 'is_success' => 1, 'started_at' => '2026-11-04 08:00:00', 'transcript_status' => 'faerdig']);
f008_done($b, 'Hvad var prisen', [['start' => 0.0, 'end' => 2.0, 'text' => 'Hvad var prisen']]);
$x = f008_call(['lead_id' => 801, 'is_success' => 1, 'started_at' => '2026-11-05 08:00:00', 'transcript_status' => 'faerdig']);
f008_done($x, 'Farvel', [['start' => 0.0, 'end' => 2.0, 'text' => 'Farvel']]);

$r = f007_get($c, '/lead/801');
$body = $r->body;
F008Check::that('AK4', str_contains($body, '<div class="find lead-find" data-js-only hidden><label for="lead-find-q" class="sr-only">Find i alle opkald</label>')
    && str_contains($body, 'placeholder="Find i alle opkald"') && str_contains($body, 'id="lead-find-status" aria-live="polite"'), 'ét find-felt for alle opkald (kun med JS)');
F008Check::that('AK4', str_contains($body, 'id="forloeb" data-total="3"') && substr_count($body, 'data-num="') === 3, 'sektionerne bærer deres nummer til "opkald x af y"');

$js = (string) file_get_contents(__DIR__ . '/../public_html/assets/app-v2.js');
F008Check::that('AK4', str_contains($js, "d.getElementById('lead-find-q')") && str_contains($js, "' · opkald ' + (sec ? sec.getAttribute('data-num') : '?') + ' af ' + (flow.getAttribute('data-total') || sections.length)")
    && str_contains($js, "lfStatus.textContent = 'Ingen træf'"), 'JS: søger i alle sektioner og viser "n af m · opkald x af y"');
F008Check::that('AK4', str_contains($js, "flow.querySelectorAll('.lead-call')") && str_contains($js, "if (o !== au && !o.paused) { o.pause(); }"),
    'JS: én afspiller pr. sektion, kun én spiller ad gangen');
F008Check::that('AK4', !str_contains($js, 'innerHTML') && !str_contains($js, 'insertAdjacentHTML'), 'JS: ingen HTML-strenge');
F008Check::that('AK4', str_contains($js, "var card = d.getElementById('forloeb') ? null : d.querySelector('.player-v2');"),
    'F007-afspilleren rører ikke lead-sidens afspillere');

// Søgning fra listen: fund i to af tre opkald
$q = f007_get($c, '/lead/801', ['kilde' => 'adversus', 'q' => 'prisen']);
F008Check::that('AK4', str_contains($q->body, '<p class="hits">3 fund for «prisen» i 2 opkald · <a href="#fund-' . $a . '-1">Gå til første fund</a></p>'),
    'N fund i K opkald og link til første');
F008Check::that('AK4', str_contains($q->body, '<mark id="fund-' . $a . '-1">Prisen</mark>') && str_contains($q->body, '<mark id="fund-' . $b . '-1">prisen</mark>')
    && str_contains($q->body, '<span class="lt-hits">2 træf</span>'), 'unikke fund-ankre pr. opkald og træf-chip');
$none = f007_get($c, '/lead/801', ['q' => 'faktura']);
F008Check::that('AK4', str_contains($none->body, 'Ingen fund for «faktura» i dette lead.'), 'ingen fund');
F008Check::done();
