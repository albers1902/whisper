<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Whisper\DB;
use Whisper\Paths;

/**
 * Henter Talk-opkald med optagelse fra Zendesk (cron/zendesk-sync.php hvert 10. minut).
 *
 * 1. Opkaldene: Talk Incremental Export GET /api/v2/channels/voice/stats/incremental/calls?start_time=<epoch>
 *    (opkald ændret siden; 10 kald/min; next_page er aldrig null, så vi stopper, når end_time ikke flytter sig).
 *    Cursoren (end_time) gemmes først, når hele siden er gemt — en fejl midt i giver samme side igen, og UPSERT på
 *    (source, external_id) gør det idempotent.
 * 2. Optagelsens URL står ikke i exporten, kun i ticketens VoiceComment (data.recording_url). Opkald og kommentar
 *    matches på data.call_id; findes den ikke, på started_at ±2 min og varighed ±5 s (Team Lead, F002 v0).
 * 3. Opkald uden optagelse gemmes ikke (F002 v0, punkt 1b.4). Kommer optagelsen senere, er opkaldet "ændret" og
 *    kommer med i exporten igen.
 * 4. Lydfilen hentes til var/recordings/ÅÅÅÅ/MM/<id>.<ext> (0600) via en .part-fil, højst 5 forsøg pr. opkald.
 *
 * Log-linjer nævner kun opkalds-id'er — aldrig URL'er, telefonnumre, navne, tokens eller stier.
 */
final class Sync
{
    public const SOURCE = 'zendesk';
    public const MAX_AUDIO_ATTEMPTS = 5;
    private const MAX_PAGES = 8;
    private const MAX_DOWNLOADS = 50;
    private const MATCH_SECONDS = 120;
    private const MATCH_DURATION = 5;

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;
    /** @var array<int, list<array<string, mixed>>> */
    private array $commentCache = [];
    /** @var array<int, string> */
    private array $agentCache = [];

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(string): void)|null $log
     */
    public function __construct(
        private readonly Client $client,
        private readonly ZendeskConfig $cfg,
        ?Closure $clock = null,
        ?Closure $log = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->log = $log ?? static function (string $line): void {
            echo $line, "\n";
        };
    }

    /**
     * Hele kørslen: opkald, derefter optagelser. Fejl stopper pænt og står i sync_state.last_error.
     *
     * @return array{calls: int, downloaded: int, failed: int, error: ?string}
     */
    public function run(): array
    {
        $now = $this->now();
        DB::exec('INSERT INTO sync_state (`source`, last_run_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)',
            [self::SOURCE, $now]);
        $calls = 0;
        $dl = ['ok' => 0, 'failed' => 0];
        $error = null;
        try {
            $calls = $this->fetchCalls();
            $dl = $this->downloadPending();
        } catch (ZendeskError $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'intern fejl (' . (new \ReflectionClass($e))->getShortName() . ')';
        }
        if ($error === null) {
            DB::exec('UPDATE sync_state SET last_ok_at = ?, last_error = NULL WHERE `source` = ?', [$this->now(), self::SOURCE]);
        } else {
            DB::exec('UPDATE sync_state SET last_error = ? WHERE `source` = ?', [mb_substr($error, 0, 250), self::SOURCE]);
            ($this->log)('zendesk-sync: stoppet: ' . $error);
        }
        return ['calls' => $calls, 'downloaded' => $dl['ok'], 'failed' => $dl['failed'], 'error' => $error];
    }

    /** @return int antal opkald gemt eller opdateret */
    public function fetchCalls(): int
    {
        $cursor = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [self::SOURCE]);
        $start = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : $this->cfg->since;
        $saved = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->client->getJson('/api/v2/channels/voice/stats/incremental/calls.json?start_time=' . $start);
            $calls = is_array($data['calls'] ?? null) ? $data['calls'] : [];
            foreach ($calls as $call) {
                if (is_array($call) && $this->saveCall($call)) {
                    $saved++;
                }
            }
            $end = is_int($data['end_time'] ?? null) ? $data['end_time'] : $start;
            if ($end > $start) {
                // Først nu er hele siden gemt; derfor flyttes cursoren først her
                DB::exec('INSERT INTO sync_state (`source`, cursor_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value)',
                    [self::SOURCE, (string) $end]);
            }
            $count = is_int($data['count'] ?? null) ? $data['count'] : count($calls);
            if ($count === 0 || $end <= $start) {
                break;
            }
            $start = $end;
        }
        return $saved;
    }

    /** @param array<mixed> $call */
    private function saveCall(array $call): bool
    {
        $extId = is_scalar($call['id'] ?? null) ? (string) $call['id'] : '';
        $ticketId = is_int($call['ticket_id'] ?? null) ? $call['ticket_id'] : 0;
        $direction = $call['direction'] ?? '';
        $recTime = is_numeric($call['recording_time'] ?? null) ? (int) $call['recording_time'] : 0;
        if (preg_match('/^[0-9]{1,30}$/', $extId) !== 1 || $ticketId <= 0 || $recTime <= 0
            || !in_array($direction, ['inbound', 'outbound'], true)) {
            return false;   // ingen optagelse (eller intet at hente den fra): gemmes ikke
        }
        $callStart = self::utc($call['created_at'] ?? null);
        $duration = is_numeric($call['duration'] ?? null) ? max(0, (int) $call['duration']) : null;
        $voice = $this->voiceComment($ticketId, $extId, $callStart, $duration);
        if ($voice === null) {
            return false;
        }
        $url = (string) $voice['recording_url'];
        $host = Client::hostOf($url);
        if (strlen($url) > 500 || ($host !== $this->cfg->host() && !in_array($host, $this->cfg->recordingHosts, true))) {
            ($this->log)('zendesk-sync: opkald ' . $extId . ': optagelsen ligger på en vært, der ikke er godkendt — springes over');
            return false;
        }
        $started = self::utc($voice['started_at'] ?? null) ?? $callStart;
        if ($started === null) {
            return false;
        }
        $duration ??= is_numeric($voice['call_duration'] ?? null) ? max(0, (int) $voice['call_duration']) : 0;
        $phoneRaw = $direction === 'inbound' ? ($voice['from'] ?? '') : ($voice['to'] ?? '');
        $phone = mb_substr((string) preg_replace('/[^0-9+ ]/', '', is_string($phoneRaw) ? $phoneRaw : ''), 0, 40);
        $agent = is_int($call['agent_id'] ?? null) && $call['agent_id'] > 0 ? $this->agentName($call['agent_id']) : '';
        $now = $this->now();
        DB::exec(
            'INSERT INTO calls (`source`, external_id, ticket_id, direction, customer_phone, agent_name, started_at, duration_sec, '
            . 'recording_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id), direction = VALUES(direction), '
            . 'customer_phone = VALUES(customer_phone), agent_name = VALUES(agent_name), started_at = VALUES(started_at), '
            . 'duration_sec = VALUES(duration_sec), '
            . "recording_url = IF(audio_status = 'hentet', recording_url, VALUES(recording_url)), updated_at = VALUES(updated_at)",
            [self::SOURCE, $extId, $ticketId, $direction, $phone, mb_substr($agent, 0, 120), $started, $duration, $url, $now, $now],
        );
        return true;
    }

    /**
     * Ticketens VoiceComment for dette opkald: data med recording_url.
     *
     * @return array<string, mixed>|null
     */
    private function voiceComment(int $ticketId, string $callId, ?string $callStart, ?int $duration): ?array
    {
        $voices = [];
        foreach ($this->comments($ticketId) as $c) {
            $data = $c['data'] ?? null;
            if (($c['type'] ?? '') === 'VoiceComment' && is_array($data) && is_string($data['recording_url'] ?? null)
                && $data['recording_url'] !== '') {
                /** @var array<string, mixed> $data */
                if (is_scalar($data['call_id'] ?? null) && (string) $data['call_id'] === $callId) {
                    return $data;
                }
                $voices[] = $data;
            }
        }
        if ($callStart === null) {
            return null;
        }
        $t0 = strtotime($callStart . ' UTC');
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($voices as $v) {
            if (is_scalar($v['call_id'] ?? null) && (string) $v['call_id'] !== '') {
                continue;   // har sit eget call_id, og det er et andet opkalds
            }
            $s = self::utc($v['started_at'] ?? null);
            if ($s === null) {
                continue;
            }
            $diff = abs(strtotime($s . ' UTC') - $t0);
            $vd = is_numeric($v['call_duration'] ?? null) ? (int) $v['call_duration'] : null;
            if ($diff > self::MATCH_SECONDS || ($duration !== null && $vd !== null && abs($vd - $duration) > self::MATCH_DURATION)) {
                continue;
            }
            if ($diff < $bestDiff) {
                $best = $v;
                $bestDiff = $diff;
            }
        }
        return $best;
    }

    /** @return list<array<string, mixed>> */
    private function comments(int $ticketId): array
    {
        if (isset($this->commentCache[$ticketId])) {
            return $this->commentCache[$ticketId];
        }
        $out = [];
        $url = '/api/v2/tickets/' . $ticketId . '/comments.json?page%5Bsize%5D=100';
        for ($i = 0; $i < 20 && $url !== ''; $i++) {
            try {
                $data = $this->client->getJson($url);
            } catch (ZendeskError $e) {
                if ($e->getCode() === 404) {
                    break;   // ticketen er slettet: ingen optagelse at hente
                }
                throw $e;
            }
            foreach (is_array($data['comments'] ?? null) ? $data['comments'] : [] as $c) {
                if (is_array($c)) {
                    /** @var array<string, mixed> $c */
                    $out[] = $c;
                }
            }
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $links = is_array($data['links'] ?? null) ? $data['links'] : [];
            $url = ($meta['has_more'] ?? false) === true && is_string($links['next'] ?? null) ? $links['next'] : '';
        }
        return $this->commentCache[$ticketId] = $out;
    }

    private function agentName(int $agentId): string
    {
        if (!isset($this->agentCache[$agentId])) {
            try {
                $data = $this->client->getJson('/api/v2/users/' . $agentId . '.json');
                $user = is_array($data['user'] ?? null) ? $data['user'] : [];
                $this->agentCache[$agentId] = is_string($user['name'] ?? null) ? $user['name'] : '';
            } catch (ZendeskError $e) {
                if ($e->getCode() !== 404 && $e->getCode() !== 403) {
                    throw $e;
                }
                $this->agentCache[$agentId] = '';
            }
        }
        return $this->agentCache[$agentId];
    }

    /** @return array{ok: int, failed: int} */
    public function downloadPending(): array
    {
        $rows = DB::all("SELECT id, recording_url, started_at FROM calls WHERE `source` = ? AND (audio_status = 'venter' "
            . "OR (audio_status = 'fejlet' AND audio_attempts < ?)) ORDER BY started_at ASC, id ASC LIMIT " . self::MAX_DOWNLOADS,
            [self::SOURCE, self::MAX_AUDIO_ATTEMPTS]);
        $ok = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            try {
                $rel = $this->downloadOne($id, (string) $r['recording_url'], (string) $r['started_at']);
                DB::exec("UPDATE calls SET audio_status = 'hentet', audio_path = ?, audio_attempts = audio_attempts + 1, "
                    . 'audio_error = NULL, updated_at = ? WHERE id = ?', [$rel, $this->now(), $id]);
                $ok++;
            } catch (RateLimited $e) {
                throw $e;   // intet forsøg brugt; næste kørsel fortsætter
            } catch (Throwable $e) {
                $msg = $e instanceof ZendeskError ? $e->getMessage() : 'intern fejl ved hentning';
                DB::exec("UPDATE calls SET audio_status = 'fejlet', audio_attempts = audio_attempts + 1, audio_error = ?, "
                    . 'updated_at = ? WHERE id = ?', [mb_substr($msg, 0, 250), $this->now(), $id]);
                ($this->log)('zendesk-sync: opkald ' . $id . ': lyd ikke hentet: ' . $msg);
                $failed++;
            }
        }
        return ['ok' => $ok, 'failed' => $failed];
    }

    /** @return string stien relativt til var/ (fx recordings/2026/10/17.mp3) */
    private function downloadOne(int $id, string $url, string $startedAt): string
    {
        $t = strtotime($startedAt . ' UTC') ?: ($this->clock)();
        $relDir = 'recordings/' . gmdate('Y', $t) . '/' . gmdate('m', $t);
        $dir = Paths::dir($relDir);
        $part = $dir . '/' . $id . '.part';
        $old = umask(077);
        try {
            $type = $this->client->download($url, $part);
            clearstatcache(true, $part);
            if (!is_file($part) || (int) filesize($part) === 0) {
                throw new ZendeskError('optagelsen var tom');
            }
            $ext = self::extension($type, $url, $part);
            $final = $dir . '/' . $id . '.' . $ext;
            chmod($part, 0600);
            if (!rename($part, $final)) {
                throw new ZendeskError('optagelsen kunne ikke gemmes');
            }
            return $relDir . '/' . $id . '.' . $ext;
        } catch (Throwable $e) {
            @unlink($part);   // en fuld disk eller en afbrudt download efterlader aldrig en halv fil
            throw $e;
        } finally {
            umask($old);
        }
    }

    /** mp3 eller wav (Zendesk: "The audio must be an MP3 or WAV file"), ud fra Content-Type, URL eller filens start. */
    public static function extension(string $contentType, string $url, string $file): string
    {
        $ct = strtolower(trim(explode(';', $contentType)[0]));
        if (in_array($ct, ['audio/mpeg', 'audio/mp3', 'audio/mpeg3'], true)) {
            return 'mp3';
        }
        if (in_array($ct, ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true)) {
            return 'wav';
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (str_ends_with($path, '.mp3') || str_ends_with($path, '.wav')) {
            return substr($path, -3);
        }
        $head = (string) @file_get_contents($file, false, null, 0, 4);
        if (str_starts_with($head, 'RIFF')) {
            return 'wav';
        }
        if (str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
            return 'mp3';
        }
        throw new ZendeskError('ukendt lydformat');
    }

    /** ISO 8601 fra Zendesk som 'Y-m-d H:i:s' i UTC; null ved en ugyldig værdi. */
    public static function utc(mixed $iso): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
