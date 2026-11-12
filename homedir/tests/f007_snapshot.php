<?php
declare(strict_types=1);

/**
 * F007-AK8: siderne, som renderfunktionerne giver dem med faste data og uden database (ikke selv en test: run.php kører
 * kun test_*.php). Snapshottet i fixtures/f007_ak8.json er lavet med denne fil på main @ 3c0be86, FØR F007 — testen
 * kører den igen med F007_kilder slukket og kræver byte for byte samme output. Kun eksisterende signaturer bruges.
 */

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\Calls;
use Whisper\CallView;
use Whisper\Flags;
use Whisper\Transcription\QueueRules;
use Whisper\Ui;
use Whisper\UiCalls;
use Whisper\UiPages;
use Whisper\View;

/** Asset-versionen (?v=<filemtime>) er ikke en del af siden. */
function f007_normalize(string $html): string
{
    return (string) preg_replace('/\?v=[0-9]+/', '?v=0', $html);
}

/**
 * @param array<string, bool> $flags
 * @return array<string, string> navn => normaliseret HTML
 */
function f007_snapshot_pages(array $flags = []): array
{
    QueueRules::override(QueueRules::off());
    $base = ['F001_brugere' => true, 'F002_zendesk' => true, 'F003_mcp' => true, 'F004_adversus' => true, 'F005_ui' => true,
        'F006_oauth' => false, 'H011_diarization' => true, 'F007_kilder' => false];
    $now = 1800000000;   // 2027-01-15 08:00 UTC
    $admin = ['id' => 1, 'name' => 'Ada Admin', 'email' => 'ada@f007.test', 'role' => 'admin', 'must_change_password' => 0];
    $user = ['id' => 2, 'name' => 'Bo Bruger', 'email' => 'bo@f007.test', 'role' => 'user', 'must_change_password' => 0];
    $csrf = 'csrf-fast-vaerdi';
    $call = static fn(array $o): array => $o + ['id' => 11, 'source' => 'zendesk', 'external_id' => 'x', 'ticket_id' => 1290,
        'direction' => 'inbound', 'customer_phone' => '+4512348975', 'agent_name' => 'Sara Hansen', 'started_at' => '2027-01-15 07:09:00',
        'duration_sec' => 181, 'audio_path' => null, 'audio_status' => 'hentet', 'audio_attempts' => 0, 'audio_error' => null,
        'transcript_status' => 'faerdig', 'transcript_error' => null, 'lead_id' => null, 'campaign_id' => null, 'is_success' => 0,
        'manual_at' => null];
    $rows = [
        $call([]),
        $call(['id' => 12, 'direction' => 'outbound', 'ticket_id' => null, 'transcript_status' => 'ventende', 'agent_name' => 'Bo']),
        $call(['id' => 13, 'source' => 'adversus', 'ticket_id' => null, 'lead_id' => 555, 'campaign_id' => 7, 'is_success' => 1,
            'customer_phone' => '', 'transcript_status' => 'transcriberer']),
        $call(['id' => 14, 'source' => 'adversus', 'ticket_id' => null, 'lead_id' => 556, 'audio_status' => 'venter',
            'transcript_status' => 'ikke_valgt']),
        $call(['id' => 15, 'audio_status' => 'fejlet', 'audio_attempts' => 3, 'audio_error' => 'HTTP 404']),
        $call(['id' => 16, 'transcript_status' => 'fejlet', 'transcript_error' => 'motor <fejl> & "x"']),
    ];
    $f = ['fra' => '', 'til' => '', 'retning' => '', 'agent' => '', 'q' => '', 'side' => 1, 'vis' => ''];
    $fq = ['fra' => '2027-01-01', 'til' => '2027-01-15', 'retning' => 'inbound', 'agent' => 'Sara Hansen', 'q' => 'faktura flyt', 'side' => 2, 'vis' => 'alle'];
    $status = ['last_ok_at' => '2027-01-15 07:52:00', 'last_error' => null, 'waiting' => 1022, 'audio_waiting' => 18];
    $statusErr = ['last_ok_at' => '2027-01-14 07:52:00', 'last_error' => 'HTTP 500', 'waiting' => 0, 'audio_waiting' => 0];
    $adv = ['config' => 'ok', 'status' => ['last_ok_at' => '2027-01-15 07:40:00', 'last_error' => null], 'warning' => 'adversus-advarsel: 3 optagelser'];
    $advBad = ['config' => 'invalid', 'status' => ['last_ok_at' => null, 'last_error' => 'HTTP 403: code 403'], 'warning' => null];
    $snip = [11 => ['text' => 'Hej, det er om min faktura og flytning af aftalen <b>', 'cut_start' => true, 'cut_end' => true]];
    $segs = [
        ['start' => 0.4, 'end' => 2.0, 'text' => 'Tryg Energi, du taler med Sara.', 'speaker' => 0, 'role' => 'agent'],
        ['start' => 2.5, 'end' => 4.0, 'text' => 'Hej, det er om min faktura <script>.', 'speaker' => 1, 'role' => 'kunde'],
        ['start' => 4.5, 'end' => 6.0, 'text' => 'Den er sendt.', 'speaker' => 0, 'role' => 'agent'],
    ];
    $plain = [['start' => 0.0, 'end' => 2.0, 'text' => 'Hej og velkommen.'], ['start' => 75.0, 'end' => 80.0, 'text' => 'Farvel & tak.']];

    $pages = [];
    $run = static function (string $name, array $over, Closure $render) use (&$pages, $base, $flags): void {
        foreach ($over + $flags + $base as $k => $v) {
            Flags::override($k, $v);
        }
        $pages[$name] = f007_normalize($render());
    };
    $run('list_admin', [], fn() => UiCalls::list($admin, $csrf, 'Gemt.', $f, $rows, 120, ['Bo', 'Sara Hansen'], $status, 'ok', false, 'testsub', [], $now, $adv));
    $run('list_user_filtered', [], fn() => UiCalls::list($user, $csrf, null, $fq, $rows, 3000, ['Sara Hansen'], $statusErr, 'missing', true, null, $snip, $now, $advBad));
    $run('list_admin_invalid', [], fn() => UiCalls::list($admin, $csrf, null, $fq, $rows, 3000, [], $statusErr, 'invalid', true, null, $snip, $now, $advBad));
    $run('list_empty', [], fn() => UiCalls::list($admin, $csrf, null, $f, [], 0, [], $status, 'ok', true, 'testsub', [], $now, $adv));
    $run('list_empty_filtered', [], fn() => UiCalls::list($user, $csrf, null, $fq, [], 0, [], $status, 'ok', true, 'testsub', [], $now, $adv));
    $run('list_no_f004', ['F004_adversus' => false], fn() => UiCalls::list($admin, $csrf, null, $f, array_slice($rows, 0, 2), 2, [], $status, 'ok', true, 'testsub', [], $now, null));
    $run('list_no_f004_empty', ['F004_adversus' => false], fn() => UiCalls::list($admin, $csrf, null, $fq, [], 0, [], $status, 'ok', true, 'testsub', [], $now, null));
    foreach ($rows as $i => $r) {
        $t = ['text' => 'Tryg Energi, du taler med Sara. Hej.', 'segments' => $segs];
        $run('show_' . $i, [], fn() => UiCalls::show($admin, $csrf, $r, $r['transcript_status'] === 'faerdig' ? $t : null, $i !== 4, 'testsub', $fq, null, null));
    }
    $run('show_plain', [], fn() => UiCalls::show($user, $csrf, $rows[0], ['text' => 'Hej og velkommen.', 'segments' => $plain], true, 'testsub', $f, 'Sat i kø.', 'Info.'));
    $run('show_text_only', [], fn() => UiCalls::show($user, $csrf, $rows[0], ['text' => "Afsnit et.\n\nAfsnit to faktura.", 'segments' => []], false, null, $fq));
    $run('show_empty', [], fn() => UiCalls::show($user, $csrf, $rows[0], ['text' => '', 'segments' => []], true, null, $f));
    $run('show_missing', [], fn() => UiCalls::show($user, $csrf, $rows[0], null, true, null, $f));
    $run('login', [], fn() => UiPages::login($csrf, 'Forkert e-mail eller adgangskode.', 'a@b.dk', 'Claude vil forbinde.'));
    $run('account', [], fn() => UiPages::account($admin, $csrf, null, 'Adgangskoden er skiftet.', null));
    $run('account_forced', [], fn() => UiPages::account($user + ['must_change_password' => 1], $csrf, 'Fejl', null, null));
    $run('users', [], fn() => UiPages::users($admin, $csrf, [['id' => 1, 'name' => 'Ada', 'email' => 'ada@x.dk', 'role' => 'admin', 'active' => 1,
        'last_login_at' => '2027-01-14 10:00:00'], ['id' => 2, 'name' => 'Bo', 'email' => 'bo@x.dk', 'role' => 'user', 'active' => 0, 'last_login_at' => null]], 'Oprettet.'));
    $run('user_new', [], fn() => UiPages::userNew($admin, $csrf, 'Fejl', ['email' => 'x@y.dk', 'name' => 'X', 'role' => 'user']));
    $run('user_edit', [], fn() => UiPages::userEdit($admin, $csrf, ['id' => 2, 'name' => 'Bo', 'email' => 'bo@x.dk', 'role' => 'user', 'active' => 1,
        'last_login_at' => null], null, null));
    $run('confirm', [], fn() => UiPages::confirm($admin, $csrf, ['id' => 2, 'name' => 'Bo'], 'reset'));
    $run('otp', [], fn() => UiPages::oneTimePassword($admin, $csrf, ['id' => 2, 'email' => 'bo@x.dk'], 'Abc-123', 'Bruger oprettet', 'Note.'));
    $run('error_user', [], fn() => UiPages::error(404, 'Opkaldet findes ikke.', $csrf, $user, '/?q=x'));
    $run('error_anon', [], fn() => UiPages::error(403, 'Ingen adgang.', null, null, '/', null, 'kode_1'));
    $run('consent', [], fn() => UiPages::consent($admin, $csrf, ['client_name' => 'Claude', 'redirect_uri' => 'https://claude.ai/api/cb', 'rid' => 'r1'], true));
    $run('home', [], fn() => UiPages::home($admin, $csrf, 'Hej'));
    // Det gamle UI (F005 slukket)
    $run('old_list', ['F005_ui' => false], fn() => CallView::list($admin, $csrf, null, $fq, $rows, 3, ['Bo'], $status, ['Zendesk er ikke sat op.'], 'testsub'));
    $run('old_show', ['F005_ui' => false], fn() => CallView::show($admin, $csrf, $rows[0], ['text' => 'Hej.', 'segments' => $segs], true, 'testsub'));
    $run('old_login', ['F005_ui' => false], fn() => View::login($csrf, null, ''));
    $run('old_account', ['F005_ui' => false], fn() => View::account($admin, $csrf, null, null));
    $run('old_query', [], fn() => CallView::query($fq, 3) . '|' . CallView::query($f));
    $run('old_filter', [], fn() => (string) json_encode([Calls::filter(['fra' => '2027-01-01', 'q' => 'x', 'vis' => 'alle', 'kilde' => 'adversus',
        'status' => 'koe', 'side' => '3']), Calls::filter([]), Calls::sourceSql(''), Calls::sourceSql('alle')]));
    $run('ui_layout', [], fn() => Ui::layout('Titel', '<p>x</p>', $admin, $csrf, 'konto'));
    foreach (array_keys($over = $flags + $base) as $k) {
        Flags::override($k, null);
    }
    QueueRules::override(null);
    return $pages;
}
