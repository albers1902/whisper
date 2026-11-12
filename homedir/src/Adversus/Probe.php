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
    /** Loft over kald pr. kørsel (Adversus: 60/min, 1000/time). Overblikket bruger højst 19, succes-sektionen resten. */
    public const MAX_REQUESTS = 40;
    /** Højst 5 succes-leads måles, og højst 8 opkald slås op i /calls/{id}/recording. */
    public const SAMPLE_LEADS = 5;
    public const MAX_RECORDING_CHECKS = 8;
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
        $c = new Client($cfg, $http, $sleep, self::MAX_REQUESTS);
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
        $say('Kald til Adversus i denne kørsel: ' . $c->requests . ' (loft for kørslen: ' . self::MAX_REQUESTS . ' · Adversus: 60/min, 1000/time)');
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
        /** @var array<int, true> $recorded opkalds-id'er (cdr.id) med en optagelse i de skannede sider */
        $recorded = [];
        if ($recs !== null) {
            $withDl = 0;
            $clips = 0;
            $matched = 0;
            $dlHosts = [];
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
                    if (isset($ids[$cdr['id']]) && !isset($recorded[$cdr['id']])) {
                        $matched++;
                    }
                    $recorded[$cdr['id']] = true;
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

        // 3. Sessioner og kampagner: kun feltnavne, antal og sideinddeling på første side (leads: afsnit 5)
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

        // 4. Én optagelse: /calls/{id}/recording → 307 → fil
        if ($pick === null) {
            $say('! ingen optagelse med cdr.id i de skannede sider — kør med --call=<id> for et kendt optaget opkald');
        } else {
            $say('Optagelse for ét opkald fra /recordings:');
            self::tryDownload($c, $pick, $say);
        }

        // 5. Leads med succes: kun de leads transcriberes, så vejen fra succes-lead til optagelse og antallet måles
        self::successLeads($c, $cfg, $recorded, $say);
        return 0;
    }

    /**
     * "Leads med succes": status-fordeling, antal succes-leads siden since, opkald og optagelser for op til 5 af dem og
     * et overslag. Filtrene prøves af: et filter "virker" kun, når alle returnerede elementer opfylder det — et API kan
     * ignorere et filter, det ikke kender, og svare med alt. Lead- og opkalds-id'er vises aldrig.
     *
     * @param array<int, true> $recorded cdr.id'er med optagelse fra afsnit 2
     * @param Closure(string): void $say
     * @throws RateLimited
     */
    private static function successLeads(Client $c, AdversusConfig $cfg, array $recorded, Closure $say): void
    {
        $succ = $cfg->successStatus;
        $since = gmdate('Y-m-d', $cfg->since);
        $say("Leads med succes (success_status = $succ, since = $since):");
        // Adversus har $gt, men ikke $gte: ét sekund før since giver "fra og med since"
        $timeFilter = ['lastUpdatedTime' => ['$gt' => gmdate('Y-m-d\TH:i:s\Z', $cfg->since - 1)]];

        // 5.1 Status uden filter og med tidsfilter (spec'en: /leads kan filtreres på lastUpdatedTime og status)
        $all = self::scan($c, '/leads', 'leads', null, null, $say);
        if ($all !== null) {
            $say(sprintf('/leads: status (uden filter, %d lead(s) på %d side(r)): %s', count($all['items']), $all['pages'],
                self::statusCounts($all['items'])));
        }
        $recent = self::scan($c, '/leads', 'leads', $timeFilter, null, $say, self::PAGES, self::PAGE_SIZE, '/leads [lastUpdatedTime ≥ since]');
        $timeOk = false;
        if ($recent === null) {
            $say('/leads: tidsfilteret (lastUpdatedTime) kan ikke filtreres — Adversus afviste det');
        } else {
            $v = self::verdict($recent['items'], static fn(array $l): ?bool => self::modifiedSince($l, $cfg->since),
                ($all['items'] ?? []) !== []);
            $timeOk = $v === 'virker';
            $say("/leads: tidsfilteret (lastUpdatedTime): $v · total " . self::int($recent['total'])
                . ' (uden filter: ' . self::int($all['total'] ?? null) . ')');
            $say(sprintf('/leads: status (siden since, %d lead(s) på %d side(r)): %s', count($recent['items']), $recent['pages'],
                self::statusCounts($recent['items'])));
        }

        // 5.2 Antal succes-leads (meta.pagination.total) og op til 5 af dem
        $filter = ['status' => ['$eq' => $succ]] + ($timeOk ? $timeFilter : []);
        $label = '/leads [status = ' . $succ . ($timeOk ? ', lastUpdatedTime ≥ since' : '') . ']';
        $hit = self::scan($c, '/leads', 'leads', $filter, null, $say, 1, self::SAMPLE_LEADS, $label);
        $isSucc = static fn(array $l): bool => ($l['status'] ?? null) === $succ;
        $v = $hit === null ? 'kan ikke filtreres (Adversus afviste filteret)' : self::verdict($hit['items'], $isSucc, true);
        // Fem elementer kan tilfældigvis alle have succes-status. Samme total som uden statusfilter, mens de skannede
        // sider har andre statusser, betyder, at filteret ignoreres.
        $base = $timeOk ? $recent : $all;
        if ($hit !== null && $v === 'virker' && $base !== null && $hit['total'] !== null && $hit['total'] === $base['total']) {
            foreach ($base['items'] as $l) {
                if ($isSucc($l) !== true) {
                    $v = 'kan ikke filtreres (filteret ignoreres — samme total som uden statusfilter)';
                    break;
                }
            }
        }
        $successTotal = null;
        $sample = [];
        if ($hit !== null && $v === 'virker') {
            $successTotal = $hit['total'] ?? count($hit['items']);
            $sample = $hit['items'];
            $say(($timeOk ? "Succes-leads siden $since: " : 'Succes-leads i alt (alle tider — tidsfilteret virker ikke): ')
                . $successTotal . ($hit['total'] === null ? ' (talt på siden — ingen meta.pagination.total)' : ' (meta.pagination.total)'));
        } else {
            $say("Succes-leads: status $v — antallet kan ikke tælles");
            // Proben fortsætter med succes-leads fra de skannede sider, så vejen til optagelserne stadig kan måles
            foreach ([...($recent['items'] ?? []), ...($all['items'] ?? [])] as $l) {
                if ($isSucc($l) === true && count($sample) < self::SAMPLE_LEADS) {
                    $sample[] = $l;
                }
            }
            $say('Succes-leads fundet i de skannede sider: ' . count($sample) . ' (bruges til målingen herunder)');
        }

        // 5.3 Opkald og optagelser pr. succes-lead
        $leadFilter = null;   // null = ikke prøvet, true = virker, false = kan ikke filtreres
        $measured = 0;
        $recSum = 0;
        $unchecked = 0;
        $checks = 0;
        foreach (array_values($sample) as $i => $lead) {
            // Opslag i /calls/{id}/recording må ikke tage de kald, de resterende leads skal bruge på /calls
            $reserve = count($sample) - $i - 1;
            $leadId = $lead['id'] ?? null;
            if (!is_int($leadId)) {
                continue;
            }
            try {
                $data = $c->getJson('/calls', ['page' => 1, 'pageSize' => self::PAGE_SIZE, 'includeMeta' => 'true',
                    'filters' => (string) json_encode(['leadId' => ['$eq' => $leadId]]), 'sortProperty' => 'start', 'sortDirection' => 'ASC']);
            } catch (RateLimited $e) {
                throw $e;
            } catch (AdversusError $e) {
                $say('/calls [leadId]: ' . $e->getMessage() . ' — kan ikke filtreres (opkald pr. lead)');
                $leadFilter = false;
                break;
            }
            $calls = array_values(array_filter(self::items($data, 'calls'), 'is_array'));
            if (self::verdict($calls, static fn(array $x): bool => ($x['leadId'] ?? null) === $leadId, true) !== 'virker') {
                $say('/calls [leadId]: kan ikke filtreres — filteret ignoreres af Adversus (svaret har opkald på andre leads)');
                $leadFilter = false;
                break;
            }
            $leadFilter = true;
            $measured++;
            $total = is_int($data['meta']['pagination']['total'] ?? null) ? $data['meta']['pagination']['total'] : null;
            // Længste samtaler først: de har størst sandsynlighed for en optagelse, så opslagene bruges bedst
            usort($calls, static fn(array $a, array $b): int => self::seconds($b['conversation'] ?? null) <=> self::seconds($a['conversation'] ?? null));
            $fromScan = 0;
            $from307 = 0;
            $notChecked = 0;
            $conv = [];
            foreach ($calls as $call) {
                $conv[] = self::seconds($call['conversation'] ?? null);
                $id = $call['id'] ?? null;
                if (is_int($id) && isset($recorded[$id])) {
                    $fromScan++;
                } elseif (is_int($id) && $checks < self::MAX_RECORDING_CHECKS && $c->remaining() > $reserve) {
                    $checks++;
                    try {
                        $from307 += $c->hasRecording($id) ? 1 : 0;
                    } catch (RateLimited $e) {
                        throw $e;
                    } catch (AdversusError $e) {
                        $notChecked++;
                    }
                } else {
                    $notChecked++;
                }
            }
            $recSum += $fromScan + $from307;
            $unchecked += $notChecked;
            $shown = array_slice($conv, 0, 10);
            $say(sprintf('  Succes-lead %d: opkald %d%s · samtale (s): %s · optaget: %d (%d fra /recordings, %d via /calls/{id}/recording)%s',
                $measured, count($calls), $total !== null && $total !== count($calls) ? " (total $total, kun side 1 målt)" : '',
                $shown === [] ? '(ingen)' : implode(', ', $shown) . (count($conv) > 10 ? ', …' : ''),
                $fromScan + $from307, $fromScan, $from307, $notChecked > 0 ? " · ikke tjekket: $notChecked" : ''));
        }
        if ($sample === []) {
            $say('  (ingen succes-leads at måle på)');
        }

        // 5.4 Overslag: succes-leads × gennemsnitligt antal optagede opkald pr. målt lead
        if ($successTotal === null) {
            $say('OVERSLAG: kan ikke beregnes — antallet af succes-leads kan ikke tælles');
        } elseif ($leadFilter !== true) {
            $say('OVERSLAG: kan ikke beregnes — ' . ($leadFilter === false ? 'opkald kan ikke filtreres på lead' : 'ingen succes-leads at måle på'));
        } else {
            $avg = $recSum / $measured;
            $say(sprintf('OVERSLAG (ikke en optælling): ca. %d optagelser at transcribere %s = %d succes-leads × %s optagede opkald pr. lead (gennemsnit af %d lead(s))%s',
                (int) round($successTotal * $avg), $timeOk ? "siden $since" : '(alle tider)', $successTotal,
                number_format($avg, 2, ',', ''), $measured, $unchecked > 0 ? " — mindst: $unchecked opkald blev ikke tjekket" : ''));
        }
    }

    /**
     * Virker et filter? Et element, $test siger false til, betyder, at filteret ignoreres. Mindst ét true og intet false =
     * "virker". En tom side er "virker", når $emptyOk (et ignoreret filter ville have givet elementer).
     *
     * @param list<array<mixed>> $items
     * @param Closure(array<mixed>): ?bool $test
     */
    private static function verdict(array $items, Closure $test, bool $emptyOk = false): string
    {
        $ok = 0;
        foreach ($items as $it) {
            $r = $test($it);
            if ($r === false) {
                return 'kan ikke filtreres (filteret ignoreres — svaret har elementer, der ikke opfylder det)';
            }
            $ok += $r === true ? 1 : 0;
        }
        if ($ok > 0 || ($items === [] && $emptyOk)) {
            return 'virker';
        }
        return $items === [] ? 'kan ikke afgøres (ingen elementer)' : 'kan ikke afgøres (tidsfelterne mangler)';
    }

    /**
     * Er leadet ændret siden since? Filteret hedder lastUpdatedTime i spec'en, men elementet har lastModifiedTime og
     * updated (målt af v1) — det nyeste af dem tæller. null = intet af dem kan læses.
     *
     * @param array<mixed> $lead
     */
    private static function modifiedSince(array $lead, int $since): ?bool
    {
        $latest = null;
        foreach (['lastUpdatedTime', 'lastModifiedTime', 'updated'] as $k) {
            $t = is_string($lead[$k] ?? null) ? strtotime($lead[$k]) : false;
            if ($t !== false) {
                $latest = max($latest ?? $t, $t);
            }
        }
        return $latest === null ? null : $latest >= $since;
    }

    /** @param list<array<mixed>> $leads */
    private static function statusCounts(array $leads): string
    {
        $st = [];
        foreach ($leads as $l) {
            $v = self::enum('status', $l['status'] ?? null);
            $st[$v] = ($st[$v] ?? 0) + 1;
        }
        return self::counts($st);
    }

    private static function seconds(mixed $v): int
    {
        return is_int($v) || is_float($v) ? max(0, (int) $v) : 0;
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
     * @return array{pages: int, items: list<array<mixed>>, total: ?int}|null
     * @throws RateLimited
     */
    public static function scan(Client $c, string $path, string $key, ?array $filters, ?string $sort, Closure $say,
        int $pages = self::PAGES, int $pageSize = self::PAGE_SIZE, ?string $label = null): ?array
    {
        $label ??= $path;
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
                $say("✘ $label (side $p): " . $e->getMessage());
                return $done === 0 ? null : ['pages' => $done, 'items' => $items, 'total' => self::total($meta)];
            }
            $done++;
            $list = self::items($data, $key);
            if ($p === 1) {
                $meta = is_array($data['meta']['pagination'] ?? null) ? $data['meta']['pagination'] : null;
                $say("$label: HTTP 200 · svar-felter: " . (array_is_list($data) ? '(liste)' : self::fieldList($data))
                    . " · antal på side 1: " . count($list));
                $say("$label: sideinddeling: page/pageSize=$pageSize · meta.pagination: "
                    . ($meta === null ? '(ingen)' : self::fieldList($meta) . ' · pageCount ' . self::int($meta['pageCount'] ?? null)
                        . ' · total ' . self::int($meta['total'] ?? null)));
                $say("$label: felter: " . self::fieldList($list[0] ?? null));
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
        return ['pages' => $done, 'items' => $items, 'total' => self::total($meta)];
    }

    /** @param array<mixed>|null $meta */
    private static function total(?array $meta): ?int
    {
        return is_int($meta['total'] ?? null) ? $meta['total'] : null;
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
