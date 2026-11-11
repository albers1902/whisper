<?php
declare(strict_types=1);

// H002-AK7: `zendesk-probe.php --ticket=<id>` viser for hver VoiceComment kun feltnavne i data, om recording_url
// findes, dens vært og statuskæden — og for opkaldene i exporten med det ticket_id recording_time, duration og
// not_recording_time som rene tal. Ingen URL'er, telefonnumre, navne eller tekst.

require_once __DIR__ . '/h002_lib.php';

use Whisper\Zendesk\HttpResult;
use Whisper\Zendesk\Probe;

h002_var();
$cfg = f002_cfg();
$ticket = 3901;
$created = '2026-11-11T11:17:00Z';
$start = (int) strtotime($created) - Probe::TICKET_WINDOW_BEFORE;
$recUrl = F002_BASE . '/api/v2/channels/voice/calls/CA' . bin2hex(random_bytes(8)) . '/twilio/recording';
$voice = f002_voice(null, $recUrl, '+4512345678', '+4587654321', '2026-11-11T11:16:00Z', 74);
$voice['data']['transcription_text'] = 'Kunden hedder Hemmelig Hansen';
$voice['data']['answered_by_name'] = 'Agent Navnesen';

$http = new FakeTransport();
$http->on('POST', F002_BASE . '/oauth/tokens', f002_token_response());
$http->on('GET', F002_BASE . '/api/v2/tickets/3901.json', f002_json(['ticket' => ['id' => $ticket, 'created_at' => $created,
    'subject' => 'Opkald fra +4512345678']]));
$http->on('GET', f002_comments_url($ticket), f002_json(['comments' => [
    ['id' => 1, 'type' => 'Comment', 'body' => 'Optagelse af opkald', 'public' => false], $voice], 'meta' => ['has_more' => false]]));
$http->on('GET', $recUrl, new HttpResult(302, ['location' => F002_BASE . '/rec/final.mp3'], ''));
$http->on('GET', F002_BASE . '/rec/final.mp3', new HttpResult(200, ['content-type' => 'audio/mpeg'], 'ID3' . str_repeat('x', 50)));
$end = $start + 1800;
$http->on('GET', f002_export_url($start), f002_json(['calls' => [
    h002_answered_call(77, 3900, '2026-11-11T11:05:00Z'),
    h002_answered_call(78, $ticket, '2026-11-11T11:17:30Z')], 'count' => 2, 'end_time' => $end, 'next_page' => 'x']));
$http->on('GET', f002_export_url($end), f002_json(['calls' => [], 'count' => 0, 'end_time' => $end, 'next_page' => 'x']));

$out = [];
$client = f002_client($cfg, $http);
$code = Probe::ticketReport($client, $ticket, static function (string $l) use (&$out): void {
    $out[] = $l;
});
$all = implode("\n", $out);
echo $all, "\n";
H002Check::that('AK7', $code === 0, 'exit 0');
H002Check::that('AK7', str_contains($all, 'VoiceComment: 1'), 'VoiceComment fundet');
H002Check::that('AK7', str_contains($all, 'data-felter: answered_by_name, call_duration, from, recording_url, started_at, to, transcription_text'),
    'kun feltnavne i data');
H002Check::that('AK7', str_contains($all, 'recording_url: ja · vært: testsub.zendesk.com'), 'recording_url findes, med vært');
H002Check::that('AK7', str_contains($all, '1. testsub.zendesk.com → HTTP 302') && str_contains($all, '2. testsub.zendesk.com → HTTP 200'),
    'statuskæden vises');
H002Check::that('AK7', str_contains($all, 'Opkald i exporten: recording_time 0 · duration 74 · not_recording_time 0 · talk_time 70'
    . ' · completion_status completed · direction inbound · Sync slår kommentarer op: ja (besvaret)'), 'opkaldets tal vises');
H002Check::that('AK7', substr_count($all, 'Opkald i exporten') === 1, 'kun opkald med det ticket_id');
foreach (['+4512345678', '4587654321', 'Hemmelig', 'Navnesen', $recUrl, 'twilio', 'final.mp3', 'Optagelse af opkald', 'Opkald fra'] as $leak) {
    H002Check::that('AK7', !str_contains($all, $leak), "udskriver ikke \"$leak\"");
}
H002Check::that('AK7', glob(sys_get_temp_dir() . '/whisper-h002-*/tmp/probe-*') === [], 'den hentede optagelse er slettet');

// Ticketen findes ikke
$http->on('GET', F002_BASE . '/api/v2/tickets/3999.json', f002_json(['error' => 'RecordNotFound'], 404));
$out = [];
$code = Probe::ticketReport($client, 3999, static function (string $l) use (&$out): void {
    $out[] = $l;
});
H002Check::that('AK7', $code === 1 && str_contains($out[0] ?? '', 'HTTP 404'), 'ukendt ticket: HTTP 404 og exit 1');

// Argumentet valideres i scriptet
$src = (string) file_get_contents(__DIR__ . '/../cron/zendesk-probe.php');
H002Check::that('AK7', str_contains($src, "'/^--ticket=([1-9][0-9]{0,15})$/'") && str_contains($src, 'Probe::ticketReport('),
    'cron/zendesk-probe.php tager --ticket=<id> og bruger Probe::ticketReport');

H002Check::done();
