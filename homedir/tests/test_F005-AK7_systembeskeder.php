<?php
declare(strict_types=1);

// F005-AK7: systembeskederne på listen er rollestyrede efter UX §3.3's tabel. Opsætning og tekniske detaljer
// vises kun for administratorer; brugere får en forklaring uden config.ini og scripts.

require_once __DIR__ . '/f005_lib.php';

use Whisper\DB;
use Whisper\UiCalls;

f005_setup();
$admin = f005_admin();
$a = f002_get($admin, '/')->body;
// Testmiljøet har ingen [zendesk] og ingen klar motor
F005Check::that('AK7', str_contains($a, 'Zendesk er ikke sat op. Opkald hentes, når <code>[zendesk]</code> er udfyldt i config.ini.'),
    'admin: Zendesk ikke sat op (config.ini)');
F005Check::that('AK7', str_contains($a, 'Transcription er ikke sat op endnu (kør cron/whisper-setup.sh).'), 'admin: motor ikke klar');

DB::exec("INSERT INTO sync_state (`source`, last_ok_at, last_error) VALUES ('zendesk', ?, 'HTTP 500 <intern>')",
    [gmdate('Y-m-d H:i:s', time() - 7200)]);
$a = f002_get($admin, '/')->body;
F005Check::that('AK7', str_contains($a, 'Seneste hentning fra Zendesk stoppede.</p><details><summary>Teknisk besked</summary><p>HTTP 500 &lt;intern&gt;</p></details>'),
    'admin: sync-fejl med teknisk besked i <details>');
F005Check::that('AK7', str_contains($a, 'dot-error') && str_contains($a, 'Hentningen fra Zendesk er stoppet'), 'statuslinjen: rød prik');

$user = f002_login();
$u = f002_get($user, '/')->body;
F005Check::that('AK7', str_contains($u, 'Opkald hentes ikke fra Zendesk endnu. Kontakt en administrator, hvis du savner opkald.'),
    'bruger: Zendesk-forklaring');
F005Check::that('AK7', str_contains($u, 'Nye opkald bliver ikke transcriberet lige nu. Lyden kan stadig afspilles.'), 'bruger: motor-forklaring');
F005Check::that('AK7', !str_contains($u, 'config.ini') && !str_contains($u, 'whisper-setup') && !str_contains($u, 'HTTP 500')
    && !str_contains($u, 'Seneste hentning fra Zendesk stoppede'), 'bruger: ingen teknik, kun den røde prik');
F005Check::that('AK7', str_contains($u, 'dot-error'), 'bruger: rød prik i statuslinjen');

// Rate limit: intet banner, kun statuslinjen (for begge roller)
DB::exec("UPDATE sync_state SET last_error = 'rate limit (429) — Zendesk bad os vente'");
$u = f002_get($user, '/')->body;
F005Check::that('AK7', str_contains($u, 'Zendesk bad os vente. Hentningen fortsætter ved næste kørsel.') && !str_contains($u, 'msg error'),
    'rate limit: kun statuslinjen');

// Frisk hentning: grøn prik og relativ tid i <time>
DB::exec("UPDATE sync_state SET last_error = NULL, last_ok_at = ?", [gmdate('Y-m-d H:i:s', time() - 240)]);
$u = f002_get($user, '/')->body;
F005Check::that('AK7', str_contains($u, 'dot-ok') && preg_match('#Hentet fra Zendesk <time datetime="[0-9T:-]+Z" title="[0-9.: ]+">for [45] min\. siden</time>#', $u) === 1,
    'frisk: grøn prik og "for 4 min. siden"');

// Ugyldig opsætning og klar motor (direkte, da testmiljøet ikke har en config.ini med [zendesk])
$f = ['fra' => '', 'til' => '', 'retning' => '', 'agent' => '', 'q' => '', 'side' => 1];
$st = ['last_ok_at' => null, 'last_error' => null, 'waiting' => 3, 'audio_waiting' => 2];
$adm = ['id' => 1, 'name' => 'A', 'role' => 'admin'];
$usr = ['id' => 2, 'name' => 'B', 'role' => 'user'];
$ai = UiCalls::list($adm, 'x', null, $f, [], 0, [], $st, 'invalid', true, null, [], time());
$ui = UiCalls::list($usr, 'x', null, $f, [], 0, [], $st, 'invalid', true, null, [], time());
F005Check::that('AK7', str_contains($ai, 'class="msg error" role="alert"') && str_contains($ai, 'Zendesk-opsætningen i config.ini er ugyldig.'),
    'admin: ugyldig opsætning som fejl');
F005Check::that('AK7', str_contains($ui, 'Opkald hentes ikke fra Zendesk endnu.') && !str_contains($ui, 'ugyldig'), 'bruger: samme forklaring som "ikke sat op"');
F005Check::that('AK7', !str_contains($ai, 'Transcription er ikke sat op') && !str_contains($ui, 'Nye opkald bliver ikke'), 'klar motor: ingen motor-besked');
F005Check::that('AK7', str_contains($ui, '3 venter på transcription · 2 venter på lyd'), 'statuslinjens anden linje');
F005Check::done();
