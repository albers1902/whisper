<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Closure;
use Whisper\Paths;
use Whisper\Zendesk\HttpTransport;

/**
 * Logikken bag cron/adversus-probe.php (F004a), lagt her, så den kan testes med en falsk transport.
 *
 * Udskriver KUN HTTP-status, værtsnavne, antal, feltnavne (der ligner feltnavne) og enum-værdier fra Adversus'
 * dokumenterede lister — aldrig værdier (telefonnumre, navne, URL'er, tekst), brugernavn, adgangskode eller headere.
 * Skriver intet i databasen. En hentet optagelse ligger i var/tmp og slettes straks.
 */
final class Probe
{
    public const PAGES = 5;
    public const PAGE_SIZE = 100;
    private const FIRST_PAGE_SIZE = 10;

    /**
     * Enum-værdierne fra api.adversus.io/swagger/apinew.json. Kun disse vises; alt andet bliver "(andet)", så en fri
     * tekst i et felt aldrig når skærmen.
     */
    private const ENUMS = [
        'origin'      => ['inbound', 'outbound'],
        'disposition' => ['answered', 'busy', 'no-answer', 'noAnswer', 'failed', 'fail', 'congestion'],
        'status'      => ['new', 'automaticRedial', 'privateRedial', 'notInterested', 'success', 'invalid', 'unqualified', 'unknown'],
    ];

    /**
     * Hele proben. $section er [adversus] fra config.ini ([] = mangler).
     *
     * @param list<string> $args argumenterne efter scriptnavnet
     * @param array<string, mixed> $section
     * @param Closure(string): void $say
     * @param (Closure(int): void)|null $sleep
     * @return int exit-kode
     */
    public static function run(array $args, array $section, HttpTransport $http, Closure $say, ?Closure $sleep = null): int
    {
        $callId = null;
        foreach ($args as $arg) {
            // Adversus' opkalds-id er et heltal (call-entry.id: integer)
            if ($callId === null && preg_match('/^--call=([1-9][0-9]{0,17})$/', $arg, $m) === 1) {
                $callId = (int) $m[1];
            } else {
                $say('✘ ukendt argument (brug: --call=<opkalds-id, kun cifre>)');
                return 2;
            }
        }
        try {
            $cfg = AdversusConfig::fromSection($section);
        } catch (AdversusError $e) {
            $say('✘ ' . $e->getMessage());
            return 1;
        }
        if ($cfg === null) {
            $say('Adversus er ikke sat op ([adversus] mangler i config/config.ini) — intet at måle.');
            return 0;
        }
        $c = new Client($cfg, $http, $sleep);
        $say('API: ' . Client::BASE . ' · auth: HTTP Basic (API-bruger) · since: ' . gmdate('Y-m-d', $cfg->since)
            . ' · godkendte optagelsesværter: ' . ($cfg->recordingHosts === [] ? '(ingen)' : implode(', ', $cfg->recordingHosts)));

        try {
            $org = $c->getJson('/organization');
            $say('Auth: HTTP 200 · /organization felter: ' . self::fieldList($org));
        } catch (RateLimited $e) {
            return self::stopped($c, $e, $say);
        } catch (AdversusError $e) {
            $say('✘ Auth: ' . $e->getMessage() . (in_array($e->getCode(), [401, 403], true)
                ? ' — tjek username/password i [adversus] og API-brugerens adgang' : ''));
            return 1;
        }

        try {
            $code = $callId !== null ? self::callReport($c, $cfg, $callId, $say) : self::overview($c, $cfg, $say);
        } catch (RateLimited $e) {
            return self::stopped($c, $e, $say);
        }
        $say('Kald til Adversus i denne kørsel: ' . $c->requests . ' (grænse: 60/min, 1000/time)');
        $say('PROBE: FÆRDIG');
        return $code;
    }

    /**
     * Standardkørslen: endpoints, sideinddeling, optællinger og én optagelse.
     *
     * @param Closure(string): void $say
     * @throws RateLimited
     */
    private static function overview(Client $c, AdversusConfig $cfg, Closure $say): int
    {
        // 1. Opkald (call detail records) fra since
        $calls = self::scan($c, '/calls', 'calls', ['start' => ['$gt' => $cfg->sinceIso()]], 'start', $say);
        $ids = [];
        if ($calls !== null) {
            $n = ['duration' => 0, 'conversation' => 0];
            $origin = [];
            $disp = [];
            foreach ($calls['items'] as $call) {
                if (is_int($call['id'] ?? null)) {
                    $ids[$call['id']] = true;
                }
                $n['duration'] += self::positive($call['duration'] ?? null) ? 1 : 0;
                $n['conversation'] += self::positive($call['conversation'] ?? null) ? 1 : 0;
                $o = self::enum('origin', $call['origin'] ?? null);
                $origin[$o] = ($origin[$o] ?? 0) + 1;
                $d = self::enum('disposition', $call['disposition'] ?? null);
                $disp[$d] = ($disp[$d] ?? 0) + 1;
            }
            $say(sprintf('/calls: %d opkald på %d side(r) · duration > 0: %d · conversation > 0: %d',
                count($calls['items']), $calls['pages'], $n['duration'], $n['conversation']));
            $say('/calls: origin: ' . self::counts($origin) . ' · disposition: ' . self::counts($disp));
            $say('/calls: optagelse kan ikke ses i opkaldet (intet felt) — se /recordings');
        }

        // 2. Optagelser (anbefalet til eksport), filtreret på upload-tid
        $recs = self::scan($c, '/recordings', 'recordings', ['timestamp' => ['$gt' => $cfg->sinceIso()]], null, $say);
        $pick = null;
        if ($recs !== null) {
            $withDl = 0;
            $clips = 0;
            $matched = 0;
            $dlHosts = [];
            $seen = [];
            foreach ($recs['items'] as $r) {
                $cdr = is_array($r['cdr'] ?? null) ? $r['cdr'] : [];
                $rec = is_array($r['recording'] ?? null) ? $r['recording'] : [];
                $dl = is_string($rec['download'] ?? null) && $rec['download'] !== '' ? $rec['download'] : null;
                $withDl += $dl !== null ? 1 : 0;
                if ($dl !== null) {
                    $h = Client::hostOf($dl);
                    $h = $h === '' ? '(ikke https)' : $h . (in_array($h, [Client::HOST, ...$cfg->recordingHosts], true) ? '' : ' (ikke godkendt)');
                    $dlHosts[$h] = ($dlHosts[$h] ?? 0) + 1;
                }
                $clips += is_array($r['clips'] ?? null) ? count($r['clips']) : 0;
                if (is_int($cdr['id'] ?? null)) {
                    if (isset($ids[$cdr['id']]) && !isset($seen[$cdr['id']])) {
                        $matched++;
                    }
                    $seen[$cdr['id']] = true;
                    $pick ??= $cdr['id'];
                }
            }
            $first = $recs['items'][0] ?? null;
            $say('/recordings: under-felter: cdr: ' . self::fieldList($first['cdr'] ?? null)
                . ' · recording: ' . self::fieldList($first['recording'] ?? null));
            $say(sprintf('/recordings: %d optagelse(r) på %d side(r) · med download-URL: %d · uden (null): %d · klip: %d',
                count($recs['items']), $recs['pages'], $withDl, count($recs['items']) - $withDl, $clips));
            $say('/recordings: download-URL\'ens vært: ' . ($dlHosts === [] ? '(ingen)' : self::counts($dlHosts)));
            if ($calls !== null) {
                $say(sprintf('Opkald med optagelse: %d af %d skannede opkald har et cdr.id blandt de skannede optagelser',
                    $matched, count($ids)));
            }
        }

        // 3. Sessioner, kampagner og leads: kun feltnavne, antal og sideinddeling på første side
        $sess = self::scan($c, '/sessions', 'sessions', ['startTime' => ['$gt' => $cfg->sinceIso()]], 'startTime', $say, 1, self::FIRST_PAGE_SIZE);
        if ($sess !== null) {
            $st = [];
            foreach ($sess['items'] as $s) {
                $v = self::enum('status', $s['status'] ?? null);
                $st[$v] = ($st[$v] ?? 0) + 1;
            }
            $say('/sessions: status (første side): ' . self::counts($st) . ' · cdr-felter: ' . self::fieldList($sess['items'][0]['cdr'] ?? null));
        }
        self::scan($c, '/campaigns', 'campaigns', null, null, $say, 1, self::FIRST_PAGE_SIZE);
        self::scan($c, '/leads', 'leads', null, null, $say, 1, self::FIRST_PAGE_SIZE);

        // 4. Én optagelse: /calls/{id}/recording → 307 → fil
        if ($pick === null) {
            $say('! ingen optagelse med cdr.id i de skannede sider — kør med --call=<id> for et kendt optaget opkald');
            return 0;
        }
        $say('Optagelse for ét opkald fra /recordings:');
        self::tryDownload($c, $pick, $say);
        return 0;
    }

    /**
     * `--call=<id>`: opkaldets tal (hvis det findes i de første 5 sider fra since) og optagelsens statuskæde.
     *
     * @param Closure(string): void $say
     * @throws RateLimited
     */
    private static function callReport(Client $c, AdversusConfig $cfg, int $callId, Closure $say): int
    {
        // /calls har hverken /calls/{id} eller et id-filter (spec'en), så opkaldet søges i de skannede sider
        $calls = self::scan($c, '/calls', 'calls', ['start' => ['$gt' => $cfg->sinceIso()]], 'start', $say);
        $found = null;
        foreach ($calls['items'] ?? [] as $call) {
            if (($call['id'] ?? null) === $callId) {
                $found = $call;
                break;
            }
        }
        $say($found === null
            ? "Opkald $callId: ikke fundet i " . ($calls['pages'] ?? 0) . ' side(r) af /calls fra since'
            : "Opkald $callId: " . self::numbers($found));
        $say("Optagelse for opkald $callId:");
        return self::tryDownload($c, $callId, $say) ? 0 : 1;
    }

    /**
     * Op til $pages sider af et list-endpoint med includeMeta. Udskriver HTTP-status, svarets felter, sideinddelingen og
     * elementernes felter. null = endpointet fejlede (fejlen er udskrevet).
     *
     * @param array<string, mixed>|null $filters
     * @param Closure(string): void $say
     * @return array{pages: int, items: list<array<mixed>>}|null
     * @throws RateLimited
     */
    public static function scan(Client $c, string $path, string $key, ?array $filters, ?string $sort, Closure $say,
        int $pages = self::PAGES, int $pageSize = self::PAGE_SIZE): ?array
    {
        $items = [];
        $done = 0;
        $meta = null;
        for ($p = 1; $p <= $pages; $p++) {
            $q = ['page' => $p, 'pageSize' => $pageSize, 'includeMeta' => 'true'];
            if ($filters !== null) {
                $q['filters'] = (string) json_encode($filters);
            }
            if ($sort !== null) {
                $q['sortProperty'] = $sort;
                $q['sortDirection'] = 'ASC';
            }
            try {
                $data = $c->getJson($path, $q);
            } catch (RateLimited $e) {
                throw $e;
            } catch (AdversusError $e) {
                $say("✘ $path (side $p): " . $e->getMessage());
                return $done === 0 ? null : ['pages' => $done, 'items' => $items];
            }
            $done++;
            $list = self::items($data, $key);
            if ($p === 1) {
                $meta = is_array($data['meta']['pagination'] ?? null) ? $data['meta']['pagination'] : null;
                $say("$path: HTTP 200 · svar-felter: " . (array_is_list($data) ? '(liste)' : self::fieldList($data))
                    . " · antal på side 1: " . count($list));
                $say("$path: sideinddeling: page/pageSize=$pageSize · meta.pagination: "
                    . ($meta === null ? '(ingen)' : self::fieldList($meta) . ' · pageCount ' . self::int($meta['pageCount'] ?? null)
                        . ' · total ' . self::int($meta['total'] ?? null)));
                $say("$path: felter: " . self::fieldList($list[0] ?? null));
            }
            foreach ($list as $it) {
                if (is_array($it)) {
                    $items[] = $it;
                }
            }
            $pageCount = is_array($meta) && is_int($meta['pageCount'] ?? null) ? $meta['pageCount'] : null;
            if (count($list) < $pageSize || ($pageCount !== null && $p >= $pageCount)) {
                break;
            }
        }
        return ['pages' => $done, 'items' => $items];
    }

    /**
     * Opkaldets tal og enum-værdier (ingen PII).
     *
     * @param array<mixed> $call
     */
    public static function numbers(array $call): string
    {
        $out = [];
        foreach (['duration', 'conversation', 'billable', 'queued', 'dialing'] as $k) {
            $v = $call[$k] ?? null;
            $out[] = $k . ' ' . (is_int($v) || is_float($v) ? (string) (int) $v : '(intet)');
        }
        foreach (['origin', 'disposition'] as $k) {
            $out[] = $k . ' ' . self::enum($k, $call[$k] ?? null);
        }
        return implode(' · ', $out);
    }

    /**
     * Henter optagelsen til var/tmp, udskriver Content-Type, størrelse og værtskæden, og sletter filen.
     *
     * @param Closure(string): void $say
     * @throws RateLimited
     */
    public static function tryDownload(Client $c, int $callId, Closure $say): bool
    {
        $tmp = Paths::dir('tmp') . '/adversus-probe-' . bin2hex(random_bytes(6)) . '.part';
        $ok = false;
        try {
            $type = $c->downloadRecording($callId, $tmp);
            clearstatcache(true, $tmp);
            $say('  Optagelse: hentet · Content-Type: ' . self::token($type) . ' · ' . (int) @filesize($tmp) . ' bytes');
            $ok = true;
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdversusError $e) {
            $say('  Optagelse: ' . $e->getMessage());
        } finally {
            @unlink($tmp);
        }
        foreach ($c->lastChain as $i => $hop) {
            $say('    ' . ($i + 1) . '. ' . $hop['host'] . ($hop['status'] > 0 ? ' → HTTP ' . $hop['status'] : ' → ikke hentet'));
        }
        $say('  Filen er slettet igen: ' . (is_file($tmp) ? 'NEJ' : 'ja'));
        foreach ($c->lastChain as $hop) {
            if (str_ends_with($hop['host'], '(ikke godkendt)')) {
                $say('  (Står der "ikke godkendt" ved en vært: skriv den i [adversus] recording_hosts, og kør proben igen.)');
                break;
            }
        }
        return $ok;
    }

    /**
     * @param Closure(string): void $say
     */
    private static function stopped(Client $c, RateLimited $e, Closure $say): int
    {
        $say('✘ ' . $e->getMessage());
        $say('Kald til Adversus i denne kørsel: ' . $c->requests);
        $say('PROBE: FÆRDIG');
        return 1;
    }

    /**
     * Elementerne i et list-svar: et JSON-array, eller arrayet under $key (med includeMeta).
     *
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private static function items(array $data, string $key): array
    {
        if (array_is_list($data)) {
            return $data;
        }
        $v = $data[$key] ?? null;
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    /** Sorterede feltnavne. Et "navn", der ikke ligner et feltnavn (fx en værdi brugt som nøgle), vises ikke. */
    public static function fieldList(mixed $a): string
    {
        if (!is_array($a) || $a === []) {
            return '(intet)';
        }
        if (array_is_list($a)) {
            return '(liste)';
        }
        $keys = [];
        $other = 0;
        foreach (array_keys($a) as $k) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,39}$/', (string) $k) === 1) {
                $keys[] = (string) $k;
            } else {
                $other++;
            }
        }
        sort($keys);
        return implode(', ', $keys) . ($other > 0 ? ($keys === [] ? '' : ', ') . "($other andre nøgler)" : '');
    }

    /** En dokumenteret enum-værdi, "(andet)" for alt andet, "(intet)" hvis feltet mangler. */
    private static function enum(string $field, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '(intet)';
        }
        return is_string($v) && in_array($v, self::ENUMS[$field] ?? [], true) ? $v : '(andet)';
    }

    /** @param array<string, int> $counts */
    private static function counts(array $counts): string
    {
        if ($counts === []) {
            return '(intet)';
        }
        ksort($counts);
        $out = [];
        foreach ($counts as $k => $n) {
            $out[] = "$k $n";
        }
        return implode(', ', $out);
    }

    private static function positive(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && $v > 0;
    }

    private static function int(mixed $v): string
    {
        return is_int($v) ? (string) $v : '(intet)';
    }

    private static function token(string $s): string
    {
        $t = (string) preg_replace('/[^A-Za-z0-9.\/;=+ -]/', '', substr($s, 0, 80));
        return $t === '' ? '(ingen)' : $t;
    }
}
