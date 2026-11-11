<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use Closure;
use Whisper\Paths;

/**
 * Målingerne bag cron/zendesk-probe.php, lagt her, så de kan testes med en falsk transport.
 * Alt, der udskrives, er HTTP-status, værtsnavne, antal, feltnavne og rene tal/enum-værdier fra exporten
 * (recording_time, duration, completion_status …) — aldrig URL'er, tokens, telefonnumre, navne eller tekst.
 */
final class Probe
{
    public const EXPORT_PATH = '/api/v2/channels/voice/stats/incremental/calls.json?start_time=';
    public const EXPORT_PAGES = 5;
    /** Exporten sorteres efter updated_at; et opkald opdateres tidligst, når det starter, lidt før ticketen oprettes. */
    public const TICKET_WINDOW_BEFORE = 900;
    private const MAX_MATCHES = 20;
    private const COMMENT_PAGES = 5;

    /** Probens linje for én scope-variant: token, og ét kald til Talk-exporten. */
    public static function scopeLine(ZendeskConfig $cfg, HttpTransport $http, string $scope, int $start): string
    {
        $c = new Client(new ZendeskConfig($cfg->subdomain, $cfg->clientId, $cfg->clientSecret, $scope, $cfg->since,
            $cfg->recordingHosts), $http);
        try {
            $c->accessToken(true);
        } catch (ZendeskError $e) {
            return "scope \"$scope\": token afvist (" . $e->getMessage() . ')';
        }
        try {
            $c->getJson(self::EXPORT_PATH . $start);
            return "scope \"$scope\": token OK · Talk-export HTTP 200";
        } catch (ZendeskError $e) {
            return "scope \"$scope\": token OK · Talk-export " . $e->getMessage();
        }
    }

    /**
     * Op til $pages sider af Talk-exporten fra $start (samme sidevending som Sync).
     *
     * @param (Closure(array<mixed>): bool)|null $match opkald, der skal returneres i 'matches' (højst 20)
     * @return array{pages: int, calls: int, recording: int, ticket: int, both: int, candidates: int, end: int,
     *     response_fields: list<string>, first: ?array<mixed>, recorded: ?array<mixed>, candidate: ?array<mixed>,
     *     matches: list<array<mixed>>}
     * @throws ZendeskError
     */
    public static function scanExport(Client $c, int $start, int $pages = self::EXPORT_PAGES, ?Closure $match = null): array
    {
        $r = ['pages' => 0, 'calls' => 0, 'recording' => 0, 'ticket' => 0, 'both' => 0, 'candidates' => 0, 'end' => $start,
            'response_fields' => [], 'first' => null, 'recorded' => null, 'candidate' => null, 'matches' => []];
        for ($p = 0; $p < $pages; $p++) {
            $data = $c->getJson(self::EXPORT_PATH . $start);
            $r['pages']++;
            if ($r['response_fields'] === []) {
                $r['response_fields'] = self::keys($data);
            }
            $calls = is_array($data['calls'] ?? null) ? $data['calls'] : [];
            foreach ($calls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $r['calls']++;
                $r['first'] ??= $call;
                $rec = is_numeric($call['recording_time'] ?? null) && (int) $call['recording_time'] > 0;
                $tic = is_int($call['ticket_id'] ?? null) && $call['ticket_id'] > 0;
                $r['recording'] += $rec ? 1 : 0;
                $r['ticket'] += $tic ? 1 : 0;
                if ($rec && $tic) {
                    $r['both']++;
                    $r['recorded'] ??= $call;
                }
                if ($tic && Sync::recordingHint($call) !== '') {
                    $r['candidates']++;
                    $r['candidate'] ??= $call;
                }
                if ($match !== null && count($r['matches']) < self::MAX_MATCHES && $match($call)) {
                    $r['matches'][] = $call;
                }
            }
            $end = is_int($data['end_time'] ?? null) ? $data['end_time'] : $start;
            $r['end'] = $end;
            $count = is_int($data['count'] ?? null) ? $data['count'] : count($calls);
            if ($count === 0 || $end <= $start) {
                break;
            }
            $start = $end;
        }
        return $r;
    }

    /**
     * `--ticket=<id>`: ticketens VoiceComments (feltnavne, recording_url ja/nej, vært, statuskæde ved hentning) og
     * opkaldene i exporten med det ticket_id (rene tal).
     *
     * @param Closure(string): void $say
     * @return int exit-kode
     */
    public static function ticketReport(Client $c, int $ticketId, Closure $say): int
    {
        try {
            $t = $c->getJson('/api/v2/tickets/' . $ticketId . '.json');
        } catch (ZendeskError $e) {
            $say("✘ ticket $ticketId: " . $e->getMessage());
            return 1;
        }
        $ticket = is_array($t['ticket'] ?? null) ? $t['ticket'] : [];
        $created = strtotime(is_string($ticket['created_at'] ?? null) ? $ticket['created_at'] : '');
        $say("Ticket $ticketId: HTTP 200 · oprettet " . ($created === false ? '(ukendt)' : gmdate('Y-m-d\TH:i:s\Z', $created)));

        $voices = [];
        $url = '/api/v2/tickets/' . $ticketId . '/comments.json?page%5Bsize%5D=100';
        $total = 0;
        try {
            for ($i = 0; $i < self::COMMENT_PAGES && $url !== ''; $i++) {
                $cm = $c->getJson($url);
                foreach (is_array($cm['comments'] ?? null) ? $cm['comments'] : [] as $k) {
                    $total++;
                    if (is_array($k) && ($k['type'] ?? '') === 'VoiceComment') {
                        $voices[] = is_array($k['data'] ?? null) ? $k['data'] : [];
                    }
                }
                $meta = is_array($cm['meta'] ?? null) ? $cm['meta'] : [];
                $links = is_array($cm['links'] ?? null) ? $cm['links'] : [];
                $url = ($meta['has_more'] ?? false) === true && is_string($links['next'] ?? null) ? $links['next'] : '';
            }
        } catch (ZendeskError $e) {
            $say('✘ ticket-kommentarer: ' . $e->getMessage());
            return 1;
        }
        $say('Kommentarer: ' . $total . ' · VoiceComment: ' . count($voices));
        foreach ($voices as $i => $d) {
            $rec = is_string($d['recording_url'] ?? null) && $d['recording_url'] !== '' ? $d['recording_url'] : null;
            $say('VoiceComment ' . ($i + 1) . ': data-felter: ' . implode(', ', self::keys($d))
                . ' · recording_url: ' . ($rec === null ? 'nej' : 'ja · vært: ' . (Client::hostOf($rec) ?: '(ikke https)')));
            if ($rec !== null) {
                self::tryDownload($c, $rec, $say);
            }
        }

        if ($created === false) {
            $say('! ticketens oprettelsestid er ukendt — opkaldet kan ikke findes i exporten');
            return 0;
        }
        $start = max(0, $created - self::TICKET_WINDOW_BEFORE);
        try {
            $scan = self::scanExport($c, $start, self::EXPORT_PAGES,
                static fn(array $call): bool => ($call['ticket_id'] ?? null) === $ticketId);
        } catch (ZendeskError $e) {
            $say('✘ Talk-export: ' . $e->getMessage());
            return 1;
        }
        if ($scan['matches'] === []) {
            $say("! intet opkald med ticket_id $ticketId i " . $scan['pages'] . ' side(r) af exporten fra '
                . gmdate('Y-m-d\TH:i:s\Z', $start));
        }
        foreach ($scan['matches'] as $call) {
            $say('Opkald i exporten: ' . self::numbers($call) . ' · Sync slår kommentarer op: '
                . (Sync::recordingHint($call) === '' ? 'nej' : 'ja (' . Sync::recordingHint($call) . ')'));
        }
        return 0;
    }

    /**
     * Felterne, Sync-reglen bygger på, som rene tal/enum-værdier (ingen PII).
     *
     * @param array<mixed> $call
     */
    public static function numbers(array $call): string
    {
        $out = [];
        foreach (['recording_time', 'duration', 'not_recording_time', 'talk_time'] as $k) {
            $v = $call[$k] ?? null;
            $out[] = $k . ' ' . (is_int($v) || is_float($v) ? (string) (int) $v : '(intet)');
        }
        foreach (['completion_status', 'direction'] as $k) {
            $v = $call[$k] ?? null;
            $out[] = $k . ' ' . (is_string($v) && preg_match('/^[a-z_]{1,40}$/', $v) === 1 ? $v : '(intet)');
        }
        return implode(' · ', $out);
    }

    /** @param Closure(string): void $say */
    public static function tryDownload(Client $c, string $url, Closure $say): void
    {
        $tmp = Paths::dir('tmp') . '/probe-' . bin2hex(random_bytes(6)) . '.part';
        try {
            $type = $c->download($url, $tmp);
            $say('  Optagelse: hentet · Content-Type: ' . self::token($type) . ' · ' . (int) @filesize($tmp) . ' bytes');
        } catch (ZendeskError $e) {
            $say('  Optagelse: ' . $e->getMessage());
        } finally {
            @unlink($tmp);
        }
        foreach ($c->lastChain as $i => $hop) {
            $say('    ' . ($i + 1) . '. ' . $hop['host'] . ' → HTTP ' . $hop['status']);
        }
    }

    /** @return list<string> sorterede nøgler */
    public static function keys(mixed $a): array
    {
        if (!is_array($a)) {
            return [];
        }
        $k = array_map('strval', array_keys($a));
        sort($k);
        return $k;
    }

    private static function token(string $s): string
    {
        return (string) preg_replace('/[^A-Za-z0-9.\/;=+ -]/', '', substr($s, 0, 80));
    }
}
