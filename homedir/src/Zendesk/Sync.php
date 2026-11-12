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
 *    Cursoren flyttes for hver 25 behandlede opkald og ved sidens slutning, kun fremad. Hver kørsel starter
 *    LOOKBACK (24 t) før cursoren: opkald, hvis VoiceComment endnu ikke fandtes ved sidste kørsel, prøves dermed igen
 *    i et døgn (QA F-2). Det er ufarligt, fordi UPSERT på (source, external_id) er idempotent, og et opkald, der
 *    allerede er gemt, kun får sine metadata opdateret uden et nyt kald efter kommentarer.
 * 2. Optagelsens URL står ikke i exporten, kun i ticketens VoiceComment (data.recording_url). Opkald og kommentar
 *    matches på data.call_id; findes den ikke, på started_at ±2 min og varighed ±5 s (Team Lead, F002 v0).
 * 3. Opkald uden optagelse gemmes ikke (F002 v0, punkt 1b.4). Hvem der slås op: recordingHint() (H002).
 * 4. En HTTP-fejl på ét ticket (fx 403 eller 500) springer kun det opkald over (logges med id, højst én gang i døgnet) — 429 og netværksfejl
 *    stopper kørslen pænt (QA F-1). Optagelserne hentes også, når opkaldshentningen er stoppet.
 * 5. Lydfilen hentes til var/recordings/ÅÅÅÅ/MM/<id>.<ext> (0600) via en .part-fil, højst 5 forsøg pr. opkald.
 *
 * Log-linjer nævner kun opkalds-id'er — aldrig URL'er, telefonnumre, navne, tokens eller stier.
 */
final class Sync
{
    public const SOURCE = 'zendesk';
    public const MAX_AUDIO_ATTEMPTS = 5;
    public const LOOKBACK = 86400;
    public const RATE_LIMIT_PREFIX = 'rate limit';
    public const HINT_RECORDED = 'recording_time';
    public const HINT_ANSWERED = 'besvaret';
    private const MAX_PAGES = 8;
    private const MAX_DOWNLOADS = 50;
    private const CURSOR_BATCH = 25;
    private const MATCH_SECONDS = 120;
    private const MATCH_DURATION = 5;
    private const SKIP_FILE = 'zendesk-skip.json';

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(string): void */
    private Closure $log;
    /** @var array<int, list<array<string, mixed>>> */
    private array $commentCache = [];
    /** @var array<int, string> */
    private array $agentCache = [];
    /** @var array<string, int>|null external_id → tidspunkt for seneste logline om overspring */
    private ?array $skipLogged = null;
    private int $storedCursor = 0;
    private int $runCursor = 0;

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
     * Hele kørslen: opkald, derefter optagelser (også når opkaldene stoppede). Fejl står i sync_state.last_error;
     * en rate limit står der med præfikset "rate limit", så siden viser den som information, ikke som fejl.
     *
     * @return array{calls: int, downloaded: int, failed: int, error: ?string}
     */
    public function run(): array
    {
        DB::exec('INSERT INTO sync_state (`source`, last_run_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)',
            [self::SOURCE, $this->now()]);
        $calls = 0;
        $dl = ['ok' => 0, 'failed' => 0];
        $error = null;
        try {
            $calls = $this->fetchCalls();
        } catch (Throwable $e) {
            $error = self::describe($e);
        }
        try {
            $dl = $this->downloadPending();
        } catch (Throwable $e) {
            $error ??= self::describe($e);
        }
        if ($error === null) {
            DB::exec('UPDATE sync_state SET last_ok_at = ?, last_error = NULL WHERE `source` = ?', [$this->now(), self::SOURCE]);
        } else {
            DB::exec('UPDATE sync_state SET last_error = ? WHERE `source` = ?', [mb_substr($error, 0, 250), self::SOURCE]);
            ($this->log)('zendesk-sync: stoppet: ' . $error);
        }
        return ['calls' => $calls, 'downloaded' => $dl['ok'], 'failed' => $dl['failed'], 'error' => $error];
    }

    private static function describe(Throwable $e): string
    {
        if ($e instanceof RateLimited) {
            return self::RATE_LIMIT_PREFIX . ' (429) — Zendesk bad os vente; hentningen fortsætter ved næste kørsel';
        }
        return $e instanceof ZendeskError ? $e->getMessage() : 'intern fejl (' . (new \ReflectionClass($e))->getShortName() . ')';
    }

    /** @return int antal opkald gemt eller opdateret */
    public function fetchCalls(): int
    {
        $cursor = DB::scalar('SELECT cursor_value FROM sync_state WHERE `source` = ?', [self::SOURCE]);
        $this->storedCursor = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : 0;
        $this->runCursor = $this->storedCursor;
        $start = max($this->cfg->since, $this->storedCursor - self::LOOKBACK);
        $saved = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->client->getJson('/api/v2/channels/voice/stats/incremental/calls.json?start_time=' . $start);
            $calls = is_array($data['calls'] ?? null) ? $data['calls'] : [];
            $end = is_int($data['end_time'] ?? null) ? $data['end_time'] : $start;
            $done = 0;
            $progress = 0;
            foreach ($calls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                try {
                    if ($this->saveCall($call)) {
                        $saved++;
                    }
                } catch (RateLimited $e) {
                    throw $e;
                } catch (ZendeskError $e) {
                    if ($e->getCode() < 400) {
                        throw $e;   // netværksfejl eller afvist token: hele kørslen stopper
                    }
                    $ext = is_scalar($call['id'] ?? null) ? (string) $call['id'] : '?';
                    if ($this->firstSkipToday($ext)) {
                        ($this->log)('zendesk-sync: opkald ' . $ext . ': springes over (' . $e->getMessage() . ')');
                    }
                }
                $u = strtotime(is_string($call['updated_at'] ?? null) ? $call['updated_at'] : '');
                $progress = max($progress, $u === false ? 0 : min($u, $end));
                if (++$done % self::CURSOR_BATCH === 0) {
                    $this->moveCursor($progress);
                }
            }
            $this->moveCursor($end);
            $count = is_int($data['count'] ?? null) ? $data['count'] : count($calls);
            if ($count === 0 || $end <= $start) {
                break;
            }
            $start = $end;
        }
        return $saved;
    }

    /**
     * Kan opkaldet have en optagelse, der er værd et kommentaropslag? Feltbeskrivelserne fra developer.zendesk.com
     * (Talk API, Incremental Exports, Calls):
     * - recording_time: "How long in seconds spent recording on the call" > 0 → HINT_RECORDED.
     * - Ellers et besvaret opkald: duration ("Call duration in seconds") > 0 og enten completion_status "completed" eller
     *   talk_time ("Sum of how long in seconds the customer was in conference with an agent(s)") > 0 → HINT_ANSWERED.
     *   Hos ejeren er recording_time 0 på opkald, der ER optaget (H002), så recording_time alene er ikke nok.
     * VoiceComment med recording_url afgør stadig, om der er en optagelse; reglen styrer kun, hvem der slås op.
     *
     * @param array<mixed> $call
     * @return string HINT_RECORDED | HINT_ANSWERED | '' (intet opslag)
     */
    public static function recordingHint(array $call): string
    {
        $num = static fn(string $k): int => is_numeric($call[$k] ?? null) ? (int) $call[$k] : 0;
        if ($num('recording_time') > 0) {
            return self::HINT_RECORDED;
        }
        if ($num('duration') > 0 && (($call['completion_status'] ?? null) === 'completed' || $num('talk_time') > 0)) {
            return self::HINT_ANSWERED;
        }
        return '';
    }

    /**
     * Nyt siden sidste kørsel: updated_at efter cursoren, som den stod, da kørslen startede (ikke i lookback-vinduet).
     *
     * @param array<mixed> $call
     */
    private function isNew(array $call): bool
    {
        $u = strtotime(is_string($call['updated_at'] ?? null) ? $call['updated_at'] : '');
        return $u === false || $u > $this->runCursor;
    }

    /** Cursoren flyttes kun fremad. */
    private function moveCursor(int $t): void
    {
        if ($t <= $this->storedCursor) {
            return;
        }
        DB::exec('INSERT INTO sync_state (`source`, cursor_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value)',
            [self::SOURCE, (string) $t]);
        $this->storedCursor = $t;
    }

    /** @param array<mixed> $call */
    private function saveCall(array $call): bool
    {
        $extId = is_scalar($call['id'] ?? null) ? (string) $call['id'] : '';
        $ticketId = is_int($call['ticket_id'] ?? null) ? $call['ticket_id'] : 0;
        $direction = $call['direction'] ?? '';
        $hint = self::recordingHint($call);
        if (preg_match('/^[0-9]{1,30}$/', $extId) !== 1 || $ticketId <= 0 || $hint === ''
            || !in_array($direction, ['inbound', 'outbound'], true)) {
            return false;   // ingen optagelse (eller intet at hente den fra): gemmes ikke
        }
        $callStart = self::utc($call['created_at'] ?? null);
        $duration = is_numeric($call['duration'] ?? null) ? max(0, (int) $call['duration']) : null;
        $agent = is_int($call['agent_id'] ?? null) && $call['agent_id'] > 0 ? mb_substr($this->agentName($call['agent_id']), 0, 120) : '';

        $existing = DB::scalar('SELECT id FROM calls WHERE `source` = ? AND external_id = ?', [self::SOURCE, $extId]);
        if ($existing !== false && $existing !== null) {
            // Allerede gemt (fx i lookback-vinduet): kun exportens metadata opdateres, ingen nye kommentarkald
            DB::exec('UPDATE calls SET ticket_id = ?, direction = ?, duration_sec = COALESCE(?, duration_sec), '
                . "agent_name = IF(? = '', agent_name, ?) WHERE id = ?",
                [$ticketId, $direction, $duration, $agent, $agent, (int) $existing]);
            return true;
        }

        if ($hint === self::HINT_ANSWERED && !$this->isNew($call)) {
            return false;   // kun recording_time > 0 prøves igen i lookback-vinduet; besvarede opkald slås op én gang
        }
        $voice = $this->voiceComment($ticketId, $extId, $callStart, $duration);
        if ($voice === null) {
            return false;   // endnu ingen optagelse: prøves igen i lookback-vinduet
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
        $now = $this->now();
        DB::exec(
            'INSERT INTO calls (`source`, external_id, ticket_id, direction, customer_phone, agent_name, started_at, duration_sec, '
            . 'recording_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id), direction = VALUES(direction), '
            . 'customer_phone = VALUES(customer_phone), agent_name = VALUES(agent_name), started_at = VALUES(started_at), '
            . 'duration_sec = VALUES(duration_sec), '
            . "recording_url = IF(audio_status = 'hentet', recording_url, VALUES(recording_url)), updated_at = VALUES(updated_at)",
            [self::SOURCE, $extId, $ticketId, $direction, $phone, $agent, $started, $duration, $url, $now, $now],
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
                // Navnet er pynt: en HTTP-fejl (4xx/5xx) gemmer opkaldet med tomt agent_name, og lookback retter det
                // senere (QA F002 N-2). Rate limit og netværksfejl stopper stadig kørslen som alle andre kald.
                if ($e instanceof RateLimited || $e->getCode() < 400) {
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
        // Nyeste først, som transcription-køen (H012)
        $rows = DB::all("SELECT id, recording_url, started_at FROM calls WHERE `source` = ? AND (audio_status = 'venter' "
            . "OR (audio_status = 'fejlet' AND audio_attempts < ?)) ORDER BY started_at DESC, id DESC LIMIT " . self::MAX_DOWNLOADS,
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

    /**
     * Et opkald, der springes over pga. en HTTP-fejl, prøves igen hver kørsel i lookback-døgnet. Loggen får det højst
     * én gang pr. 24 timer pr. opkald (QA F002 N-3). Markørerne ligger i var/ (ingen migration), og en markør, der ikke
     * kan læses eller skrives, giver bare en logline for meget.
     */
    public function firstSkipToday(string $extId): bool
    {
        $now = ($this->clock)();
        $file = Paths::varDir() . '/' . self::SKIP_FILE;
        if ($this->skipLogged === null) {
            $raw = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->skipLogged = [];
            foreach (is_array($raw) ? $raw : [] as $id => $t) {
                if (is_int($t) && $t > $now - self::LOOKBACK) {
                    $this->skipLogged[(string) $id] = $t;
                }
            }
        }
        if (isset($this->skipLogged[$extId])) {
            return false;
        }
        $this->skipLogged[$extId] = $now;
        try {
            Paths::dir('');
            Paths::writePrivate($file, (string) json_encode($this->skipLogged, JSON_FORCE_OBJECT));
        } catch (Throwable) {
            // kun støj i loggen, ingen datatab
        }
        return true;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
