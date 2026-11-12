<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Closure;
use Throwable;
use Whisper\Zendesk\HttpTransport;

/**
 * Read-only diagnose af matchet mellem Adversus-optagelser og opkald (H009). Køres manuelt af ejeren på DEV:
 * `php ~/cron/adversus-diagnose.php`. Skriver intet (hverken database eller disk) og bruger højst MAX_REQUESTS kald.
 *
 * Alle tidsfiltre skrives som sync skriver dem (AdversusConfig::filterTime: væg-tid i filter_tz), så diagnosen viser,
 * om sync's opslag rammer:
 *  1. én side /recordings i det seneste lukkede vindue — ligger upload-tiderne inden for det vindue, vi bad om?
 *  2. formatprøve: samme vindue med suffikset Z, med zonens offset og uden suffiks (accepteret, og inden for vinduet?);
 *  3. for op til SAMPLES optagelser: /calls med start i [cdr.timestamp − 15 min, + 5 min], ALLE sider (højst MAX_PAGES);
 *  4. /calls med timestamp = cdr.timestamp ± 2 s (er det udokumenterede /calls.timestamp det samme?);
 *  5. én ventende række fra adversus_pending, slået op som i 3;
 *  6. fundne opkald side om side med de rå tidsstempler; 7. antal opkald i den seneste time.
 * Af opkaldet vises kun tidsstempler, varigheder og feltnavne — aldrig nummer, navne, URL'er eller anden fri tekst ud
 * over Adversus' fejltekst renset af Client::errorText (DECISIONS #53).
 */
final class Diagnose
{
    public const MAX_REQUESTS = 40;
    public const SAMPLES = 3;
    public const BEFORE = 900;
    public const AFTER = 300;
    /** Sider à PAGE pr. start-vindue; målt op til 467 opkald pr. 20 min ved spidsbelastning */
    public const MAX_PAGES = 6;
    public const PAGE = 100;
    /** Optagelser tages fra så lang tid før nu − lag */
    public const REC_SPAN = 7200;
    /** Formatprøvens vindue (de seneste minutter før nu − lag) */
    public const FORMAT_SPAN = 1800;
    private const TIME_FIELDS = ['start', 'answer', 'connect', 'end', 'timestamp'];
    private const NUM_FIELDS = ['duration', 'conversation', 'dialing', 'queued'];
    /** Kun et rigtigt tidsstempel vises råt (security H009_v1 S1: en tegnklasse slap telefonnumre igennem) */
    private const TIMESTAMP = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?(Z|[+-][0-9]{2}:?[0-9]{2})?$/D';

    /** @var Closure(string): void */
    private Closure $say;
    /** @var array<int, array<mixed>> fundne opkald efter id */
    private array $found = [];
    /** @var array<string, string> */
    private array $verdict = [];

    /** @param Closure(string): void $say */
    private function __construct(private readonly Client $c, private readonly AdversusConfig $cfg, private readonly int $now, Closure $say)
    {
        $this->say = $say;
    }

    /**
     * Hele kommandoen (cron/adversus-diagnose.php er kun en skal om denne): deploy-stop, config, ventende rækker og
     * kørsel. Enhver uventet fejl bliver til én linje med klassens korte navn (security H009_v1 S2: aldrig PHP's egen
     * fejludskrift med stakspor).
     *
     * @param array<string, mixed> $section [adversus] fra config.ini
     * @param Closure(): list<array<string, mixed>> $pending læser adversus_pending (kun SELECT)
     * @param Closure(string): void $say
     */
    public static function cli(string $home, array $section, HttpTransport $http, Closure $pending, int $now, Closure $say): int
    {
        if (file_exists($home . '/logs/deploying')) {
            $say('✘ deploy kører — prøv igen om lidt');   // security H009_v1 S4: aldrig tavs
            return 1;
        }
        try {
            $cfg = AdversusConfig::fromSection($section);
            if ($cfg === null) {
                $say('✘ [adversus] mangler i config.ini');
                return 1;
            }
            $client = new Client($cfg, $http, null, self::MAX_REQUESTS);
            return self::run($client, $cfg, $pending(), $now, $say);
        } catch (AdversusError $e) {
            $say('✘ ' . $e->getMessage());
            return 1;
        } catch (Throwable $e) {
            $say('✘ uventet fejl (' . self::shortClass($e) . ')');
            return 1;
        }
    }

    /**
     * @param list<array<string, mixed>> $pending rækker fra adversus_pending (cdr_id, cdr_at, tries, first_seen)
     * @param Closure(string): void $say
     * @return int 0 = kørt (også når loftet eller 429 stoppede den undervejs), 1 = uventet fejl
     */
    public static function run(Client $c, AdversusConfig $cfg, array $pending, int $now, Closure $say): int
    {
        $d = new self($c, $cfg, $now, $say);
        $say('Adversus-diagnose (H009, read-only) · nu ' . Sync::iso($now) . ' · filter_tz ' . $cfg->filterTz->getName()
            . ' · højst ' . self::MAX_REQUESTS . ' kald');
        $code = 0;
        try {
            $d->diagnose(array_slice($pending, 0, 1));
        } catch (RateLimited $e) {
            $say('✘ ' . $e->getMessage());
        } catch (Throwable $e) {
            $say('✘ uventet fejl (' . self::shortClass($e) . ')');
            $code = 1;
        }
        foreach (['recordings-filter', 'format Z', 'format offset', 'format uden suffiks', 'opkald fundet', 'timestamp-filter',
            'cdr.timestamp har offset', 'start har offset', 'cdr.timestamp − start'] as $k) {
            $say('KONKLUSION ' . $k . ': ' . ($d->verdict[$k] ?? 'ikke afgjort'));
        }
        $say('Kald til Adversus i denne kørsel: ' . $c->requests . ' (loft ' . self::MAX_REQUESTS . ')');
        $say('DIAGNOSE: FÆRDIG');
        return $code;
    }

    /**
     * @param list<array<string, mixed>> $pending
     * @throws RateLimited
     */
    private function diagnose(array $pending): void
    {
        $say = $this->say;
        $samples = $this->recordings();
        $this->formats();

        // 3. start-vinduet, som sync søger det, alle sider
        $hits = 0;
        foreach ($samples as $i => $s) {
            if ($s['t'] === null) {
                $say('3. optagelse ' . ($i + 1) . ': cdr.timestamp kan ikke læses som tid — springes over');
                continue;
            }
            $r = $this->startWindow($s['id'], $s['t']);
            $hits += $r['page'] !== null ? 1 : 0;
            $say('3. optagelse ' . ($i + 1) . ' (cdr.id ' . $s['id'] . '): /calls med start i [cdr.timestamp − ' . intdiv(self::BEFORE, 60)
                . ' min, + ' . intdiv(self::AFTER, 60) . ' min]: ' . $r['text']);
        }
        if ($samples !== []) {
            $this->verdict['opkald fundet'] = $hits . ' af ' . count($samples) . ' optagelser';
        }

        // 4. timestamp-filter på /calls omkring cdr.timestamp
        $ts = [];
        foreach ($samples as $i => $s) {
            if ($s['t'] === null) {
                continue;
            }
            $r = $this->callsWith(['timestamp' => ['$gt' => $this->cfg->filterTime($s['t'] - 3), '$lt' => $this->cfg->filterTime($s['t'] + 3)]],
                $s['id'], 1, 'timestamp');
            $say('4. optagelse ' . ($i + 1) . ': /calls med timestamp = cdr.timestamp ± 2 s: ' . $r['text']);
            $ts[] = $r['page'] !== null;
        }
        if ($ts !== []) {
            $this->verdict['timestamp-filter'] = count(array_filter($ts)) . ' af ' . count($ts) . ' optagelser fundet';
        }

        // 5. én ventende optagelse (cdr_at er gemt i UTC)
        foreach ($pending as $p) {
            $id = self::posInt($p['cdr_id'] ?? null);
            if ($id === null) {
                continue;
            }
            $at = is_string($p['cdr_at'] ?? null) ? (int) strtotime($p['cdr_at'] . ' UTC') : null;
            $text = '5. ventende: cdr.id ' . $id . ' · cdr_at (gemt, UTC) ' . ($at === null ? 'null' : Sync::iso($at))
                . ' · forsøg ' . (int) ($p['tries'] ?? 0);
            if ($at !== null) {
                $text .= ' · start-vindue: ' . $this->startWindow($id, $at)['text'];
            }
            $say($text);
        }

        // 6. side om side
        $say('6. Fundne opkald (kun tidsstempler, varigheder og feltnavne):');
        if ($this->found === []) {
            $say('   (intet opkald fundet)');
        }
        $byId = [];
        foreach ($samples as $s) {
            $byId[$s['id']] = $s;
        }
        $diffs = [];
        foreach ($this->found as $id => $call) {
            $s = $byId[$id] ?? null;
            $parts = ['cdr.id ' . $id];
            if ($s !== null) {
                $parts[] = 'cdr.timestamp ' . self::raw($s['raw']);
                $parts[] = 'recordings.timestamp ' . self::raw($s['upload']);
            }
            foreach (self::TIME_FIELDS as $f) {
                if (array_key_exists($f, $call)) {
                    $parts[] = $f . ' ' . self::raw($call[$f]);
                }
            }
            foreach (self::NUM_FIELDS as $f) {
                if (array_key_exists($f, $call)) {
                    $parts[] = $f . ' ' . self::number($call[$f]);
                }
            }
            $say('   ' . implode(' · ', $parts));
            if ($s !== null && $s['t'] !== null) {
                $line = [];
                foreach (['start', 'end'] as $f) {
                    $t = Sync::epoch($call[$f] ?? null);
                    if ($t !== null) {
                        $line[] = 'cdr.timestamp − ' . $f . ' = ' . self::signed($s['t'] - $t) . ' s';
                        if ($f === 'start') {
                            $diffs[] = self::signed($s['t'] - $t) . ' s';
                        }
                    }
                }
                $say('   ' . ($line === [] ? 'forskel: kan ikke beregnes' : implode(' · ', $line)));
            }
            $say('   felter: ' . self::fieldList($call));
            $this->verdict['start har offset'] ??= self::offsetText($call['start'] ?? null);
        }
        if ($diffs !== []) {
            $this->verdict['cdr.timestamp − start'] = implode(', ', $diffs);
        }

        // 7. volumen
        $to = $this->now - $this->cfg->lag;
        $r = $this->callsWith(['start' => ['$gt' => $this->cfg->filterTime($to - 3601), '$lt' => $this->cfg->filterTime($to)]], null, 1, 'start', 1);
        $say('7. /calls med start i den seneste time før nu − lag: total ' . self::num($r['total']));
    }

    /**
     * 1. Én side /recordings i [nu − lag − REC_SPAN, nu − lag); op til SAMPLES optagelser (første, midterste, sidste).
     *
     * @return list<array{id: int, raw: mixed, t: ?int, upload: mixed}>
     * @throws RateLimited
     */
    private function recordings(): array
    {
        $to = $this->now - $this->cfg->lag;
        $from = $to - self::REC_SPAN;
        $r = $this->recordingsPage($from, $to, $this->cfg->filterTime($from - 1), $this->cfg->filterTime($to), self::PAGE);
        if ($r['items'] === null) {
            ($this->say)('1. /recordings: ✘ ' . $r['text']);
            return [];
        }
        $items = $r['items'];
        ($this->say)('1. /recordings med timestamp i [' . Sync::iso($from) . ', ' . Sync::iso($to) . ') (filter ' . $this->cfg->filterTime($from - 1)
            . ' … ' . $this->cfg->filterTime($to) . '): total ' . self::num($r['total']) . ' · på side 1: ' . count($items));
        if ($r['ups'] > 0) {
            ($this->say)('   upload-tider på side 1: ' . $r['range'] . ' · inden for vinduet: ' . $r['inside'] . ' af ' . $r['ups']);
            $this->verdict['recordings-filter'] = $r['inside'] === $r['ups'] ? 'alle ' . $r['ups'] . ' upload-tider inden for vinduet'
                : ($r['ups'] - $r['inside']) . ' af ' . $r['ups'] . ' upload-tider uden for vinduet';
        }
        $n = count($items);
        $picks = $n === 0 ? [] : array_values(array_unique([0, intdiv($n, 2), $n - 1]));
        $out = [];
        foreach (array_slice($picks, 0, self::SAMPLES) as $k) {
            $rec = $items[$k];
            $cdr = is_array($rec['cdr'] ?? null) ? $rec['cdr'] : [];
            $id = self::posInt($cdr['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $raw = $cdr['timestamp'] ?? null;
            $t = Sync::epoch($raw);
            $up = Sync::epoch($rec['timestamp'] ?? null);
            $out[] = ['id' => $id, 'raw' => $raw, 't' => $t, 'upload' => $rec['timestamp'] ?? null];
            ($this->say)('   optagelse ' . count($out) . ': cdr.id ' . $id . ' · cdr.timestamp ' . self::raw($raw)
                . ' · timestamp (upload) ' . self::raw($rec['timestamp'] ?? null)
                . ($t !== null && $up !== null ? ' · upload − cdr.timestamp = ' . self::signed($up - $t) . ' s' : ''));
            $this->verdict['cdr.timestamp har offset'] ??= self::offsetText($raw);
        }
        if ($out === []) {
            ($this->say)('   ingen optagelser i perioden');
        }
        return $out;
    }

    /**
     * 2. Formatprøve: samme korte vindue med tre skrivemåder af væg-tiden. Viser, hvilke Adversus accepterer, og om
     * upload-tiderne så ligger i vinduet.
     *
     * @throws RateLimited
     */
    private function formats(): void
    {
        $to = $this->now - $this->cfg->lag;
        $from = $to - self::FORMAT_SPAN;
        $z = fn(int $t): string => $this->cfg->filterTime($t);
        $bare = fn(int $t): string => substr($this->cfg->filterTime($t), 0, -1);
        $offset = fn(int $t): string => $bare($t) . (new \DateTimeImmutable('@' . $t))->setTimezone($this->cfg->filterTz)->format('P');
        foreach (['format Z' => $z, 'format offset' => $offset, 'format uden suffiks' => $bare] as $name => $fmt) {
            $r = $this->recordingsPage($from, $to, $fmt($from - 1), $fmt($to), 10);
            $text = $r['items'] === null ? $r['text'] : ($r['ups'] === 0 ? 'accepteret, 0 optagelser'
                : 'accepteret, ' . $r['inside'] . ' af ' . $r['ups'] . ' inden for vinduet');
            ($this->say)('2. ' . $name . ' (' . $fmt($from - 1) . '): ' . $text);
            $this->verdict[$name] = $text;
        }
    }

    /**
     * @return array{items: ?list<array<mixed>>, total: ?int, ups: int, inside: int, range: string, text: string}
     * @throws RateLimited
     */
    private function recordingsPage(int $from, int $to, string $gt, string $lt, int $size): array
    {
        try {
            $data = $this->c->getJson('/recordings', ['filters' => (string) json_encode(['timestamp' => ['$gt' => $gt, '$lt' => $lt]]),
                'page' => 1, 'pageSize' => $size, 'includeMeta' => 'true']);
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdversusError $e) {
            return ['items' => null, 'total' => null, 'ups' => 0, 'inside' => 0, 'range' => '', 'text' => 'afvist (' . $e->getMessage() . ')'];
        }
        $items = self::items($data, 'recordings');
        $ups = array_values(array_filter(array_map(static fn(array $r): ?int => Sync::epoch($r['timestamp'] ?? null), $items),
            static fn(?int $t): bool => $t !== null));
        $inside = count(array_filter($ups, static fn(int $t): bool => $t >= $from && $t < $to));
        return ['items' => $items, 'total' => self::total($data), 'ups' => count($ups), 'inside' => $inside,
            'range' => $ups === [] ? '' : Sync::iso(min($ups)) . ' – ' . Sync::iso(max($ups)), 'text' => ''];
    }

    /**
     * /calls med start i [t − BEFORE, t + AFTER], sorteret på start, alle sider (højst MAX_PAGES); leder efter $id.
     *
     * @return array{page: ?int, pages: int, total: ?int, text: string}
     * @throws RateLimited
     */
    private function startWindow(int $id, int $t): array
    {
        return $this->callsWith(['start' => ['$gt' => $this->cfg->filterTime($t - self::BEFORE - 1), '$lt' => $this->cfg->filterTime($t + self::AFTER)]],
            $id, self::MAX_PAGES, 'start');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{page: ?int, pages: int, total: ?int, text: string}
     * @throws RateLimited
     */
    private function callsWith(array $filters, ?int $id, int $pages, ?string $sort, int $pageSize = self::PAGE): array
    {
        $total = null;
        $p = 0;
        while ($p < $pages) {
            $p++;
            $q = ['filters' => (string) json_encode($filters), 'page' => $p, 'pageSize' => $pageSize, 'includeMeta' => 'true'];
            if ($sort !== null) {
                $q['sortProperty'] = $sort;
                $q['sortDirection'] = 'ASC';
            }
            try {
                $data = $this->c->getJson('/calls', $q);
            } catch (RateLimited $e) {
                throw $e;
            } catch (AdversusError $e) {
                return ['page' => null, 'pages' => $p, 'total' => null, 'text' => 'afvist (' . $e->getMessage() . ')'];
            }
            $total ??= self::total($data);
            $items = self::items($data, 'calls');
            foreach ($items as $call) {
                if ($id !== null && self::posInt($call['id'] ?? null) === $id) {
                    $this->found[$id] ??= $call;
                    return ['page' => $p, 'pages' => $p, 'total' => $total, 'text' => 'fundet på side ' . $p . ' (total ' . self::num($total) . ')'];
                }
            }
            if (count($items) < $pageSize) {
                return ['page' => null, 'pages' => $p, 'total' => $total, 'text' => 'ikke fundet på alle ' . $p . ' side(r) (total ' . self::num($total) . ')'];
            }
        }
        return ['page' => null, 'pages' => $p, 'total' => $total, 'text' => 'ikke fundet på de første ' . $p . ' sider (total ' . self::num($total) . ')'];
    }

    // ---------------------------------------------------------------- hjælpere

    /** En rå værdi fra Adversus vises kun, hvis den er et rigtigt tidsstempel; ellers kun typen og længden. */
    public static function raw(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v) && preg_match(self::TIMESTAMP, $v) === 1) {
            return '"' . $v . '"';
        }
        return self::unexpected($v);
    }

    /** En varighed vises kun, hvis den er et tal (ikke en tekst med cifre). */
    public static function number(mixed $v): string
    {
        return is_int($v) || is_float($v) ? $v . ' s' : self::unexpected($v);
    }

    private static function unexpected(mixed $v): string
    {
        return '(uventet format' . (is_string($v) ? ', ' . strlen($v) . ' tegn' : ', ' . get_debug_type($v)) . ')';
    }

    private static function offsetText(mixed $v): string
    {
        if (!is_string($v)) {
            return 'ikke en tekst (' . self::raw($v) . ')';
        }
        if (preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/', $v, $m) === 1) {
            return 'ja (' . $m[1] . ')';
        }
        return 'nej — sync læser den som UTC';
    }

    private static function shortClass(Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName();
    }

    private static function signed(int $n): string
    {
        return ($n >= 0 ? '+' : '−') . abs($n);
    }

    private static function num(?int $n): string
    {
        return $n === null ? '?' : (string) $n;
    }

    /** @param array<mixed> $data */
    private static function total(array $data): ?int
    {
        $t = $data['meta']['pagination']['total'] ?? null;
        return is_int($t) ? $t : null;
    }

    /** @param array<mixed> $a */
    private static function fieldList(array $a): string
    {
        $keys = array_filter(array_map('strval', array_keys($a)), static fn(string $k): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,39}$/', $k) === 1);
        return implode(', ', $keys);
    }

    private static function posInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        return is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v) === 1 ? (int) $v : null;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function items(array $data, string $key): array
    {
        $list = array_is_list($data) ? $data : (is_array($data[$key] ?? null) ? $data[$key] : []);
        return array_values(array_filter($list, 'is_array'));
    }
}
