<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Closure;

/**
 * Read-only diagnose af, hvorfor sync ikke finder opkaldet til en optagelse (H009). Køres manuelt af ejeren på DEV:
 * `php ~/cron/adversus-diagnose.php`. Skriver intet (hverken database eller disk) og bruger højst MAX_REQUESTS kald.
 *
 * For op til SAMPLES friske optagelser og op til SAMPLES rækker fra adversus_pending prøves:
 *  - /calls med id-filter ($eq og interval) — udokumenteret, så svaret afgør det;
 *  - /calls med start-vindue omkring cdr.timestamp, forskudt 0, −1 t, +1 t, −2 t og +2 t (tidszone-hypotesen);
 *  - /calls med timestamp-filter omkring cdr.timestamp (er /calls.timestamp det samme tidsstempel?).
 * De rå tidsstempler udskrives side om side. Af opkaldet vises kun tidsfelter, varigheder og feltnavne — aldrig nummer,
 * navne, URL'er eller anden fri tekst (F004 security S5). En fejltekst fra Adversus er renset af Client::errorText.
 */
final class Diagnose
{
    public const MAX_REQUESTS = 40;
    public const SAMPLES = 3;
    /** Forskydninger (s), som start-vinduet prøves med; den første er den, sync bruger i dag */
    public const SHIFTS = [0, -3600, 3600, -7200, 7200];
    /** Start-vinduet går så langt før og efter cdr.timestamp + forskydning */
    public const BEFORE = 900;
    public const AFTER = 300;
    public const PAGES = 2;
    public const PAGE = 100;
    /** Optagelser tages fra så lang tid før nu − lag */
    public const REC_SPAN = 7200;
    /** Opkaldsfelter, hvis værdi må vises (kun tider og tal) */
    private const TIME_FIELDS = ['start', 'answer', 'connect', 'end', 'timestamp'];
    private const NUM_FIELDS = ['duration', 'conversation', 'dialing', 'queued'];

    /** @var Closure(string): void */
    private Closure $say;
    /** @var array<int, array<mixed>> fundne opkald efter id */
    private array $found = [];
    /** @var array<string, string> konklusioner */
    private array $verdict = [];

    /** @param Closure(string): void $say */
    private function __construct(private readonly Client $c, private readonly AdversusConfig $cfg, private readonly int $now, Closure $say)
    {
        $this->say = $say;
    }

    /**
     * @param list<array<string, mixed>> $pending rækker fra adversus_pending (cdr_id, cdr_at, tries, first_seen)
     * @param Closure(string): void $say
     * @return int exitkode (0 = diagnosen kørte, også når loftet eller 429 stoppede den undervejs)
     */
    public static function run(Client $c, AdversusConfig $cfg, array $pending, int $now, Closure $say): int
    {
        $d = new self($c, $cfg, $now, $say);
        $say('Adversus-diagnose (H009, read-only) · nu ' . Sync::iso($now) . ' · højst ' . self::MAX_REQUESTS . ' kald');
        try {
            $d->diagnose(array_slice($pending, 0, self::SAMPLES));
        } catch (RateLimited $e) {
            $say('✘ ' . $e->getMessage());
        }
        foreach (['id-filter', 'start-forskydning', 'timestamp-filter', 'recordings-filter', 'cdr.timestamp har offset', 'start har offset'] as $k) {
            $say('KONKLUSION ' . $k . ': ' . ($d->verdict[$k] ?? 'ikke afgjort'));
        }
        $say('Kald til Adversus i denne kørsel: ' . $c->requests . ' (loft ' . self::MAX_REQUESTS . ')');
        $say('DIAGNOSE: FÆRDIG');
        return 0;
    }

    /**
     * @param list<array<string, mixed>> $pending
     * @throws RateLimited
     */
    private function diagnose(array $pending): void
    {
        $say = $this->say;
        $samples = $this->recordings();

        // 2. id-filter: én optagelse (ellers en ventende) som prøve; virker $eq, slås resten også op på id
        $probeId = $samples[0]['id'] ?? (isset($pending[0]) ? self::posInt($pending[0]['cdr_id'] ?? null) : null);
        $idWorks = false;
        if ($probeId !== null) {
            $say('2. /calls med id-filter (cdr.id ' . $probeId . ')');
            $eq = $this->idLookup($probeId, ['$eq' => $probeId]);
            $range = $this->idLookup($probeId, ['$gt' => $probeId - 1, '$lt' => $probeId + 1]);
            $idWorks = $eq === 'virker';
            $this->verdict['id-filter'] = '$eq ' . $eq . ' · interval ' . $range;
        }

        // 3. start-vindue forskudt; alle forskydninger for første optagelse, derefter kun 0 og den, der fandt den
        $best = null;
        foreach ($samples as $i => $s) {
            if ($s['t'] === null) {
                $say('3. optagelse ' . ($i + 1) . ': cdr.timestamp kan ikke læses som tid — start-vinduer springes over');
                continue;
            }
            $shifts = $i === 0 ? self::SHIFTS : array_values(array_unique([0, $best ?? 0]));
            $say('3. optagelse ' . ($i + 1) . ' (cdr.id ' . $s['id'] . '): /calls med start i [cdr.timestamp − '
                . intdiv(self::BEFORE, 60) . ' min, + ' . intdiv(self::AFTER, 60) . ' min], forskudt:');
            $hits = [];
            foreach ($shifts as $shift) {
                $r = $this->startWindow($s['id'], $s['t'] + $shift);
                $say('   ' . self::hours($shift) . ': ' . ($r['page'] !== null ? 'fundet på side ' . $r['page'] : 'ikke fundet på '
                    . $r['pages'] . ' side(r)') . ' (total i vinduet ' . self::num($r['total']) . ')');
                if ($r['page'] !== null) {
                    $hits[] = $shift;
                }
            }
            if ($i === 0) {
                $best = $hits[0] ?? null;
                $this->verdict['start-forskydning'] = $hits === [] ? 'ingen forskydning fandt opkaldet'
                    : implode(', ', array_map([self::class, 'hours'], $hits)) . ' fandt opkaldet';
            } elseif ($best !== null) {
                $this->verdict['start-forskydning'] .= ' · optagelse ' . ($i + 1) . ': ' . (in_array($best, $hits, true) ? 'samme' : 'afviger');
            }
        }

        // 4. timestamp-filter på /calls omkring cdr.timestamp (±2 s), og som rå streng med $eq, hvis den ikke er Z-tid
        $tsHits = [];
        foreach ($samples as $i => $s) {
            if ($s['t'] === null) {
                continue;
            }
            $r = $this->callsWith(['timestamp' => ['$gt' => Sync::iso($s['t'] - 3), '$lt' => Sync::iso($s['t'] + 3)]], $s['id'], 1, 'timestamp');
            $line = '4. optagelse ' . ($i + 1) . ': /calls med timestamp = cdr.timestamp ± 2 s: ' . $r['text'];
            if (is_string($s['raw']) && $s['raw'] !== Sync::iso($s['t'])) {
                $raw = $this->callsWith(['timestamp' => ['$eq' => $s['raw']]], $s['id'], 1, null);
                $line .= ' · som rå streng ($eq): ' . $raw['text'];
                $r['page'] ??= $raw['page'];
            }
            $say($line);
            $tsHits[] = $r['page'] !== null;
        }
        if ($tsHits !== []) {
            $this->verdict['timestamp-filter'] = count(array_filter($tsHits)) . ' af ' . count($tsHits) . ' optagelser fundet';
        }

        // 5. ventende optagelser (som sync gemte dem: cdr_at i UTC)
        foreach ($pending as $i => $p) {
            $id = self::posInt($p['cdr_id'] ?? null);
            if ($id === null) {
                continue;
            }
            $at = is_string($p['cdr_at'] ?? null) ? (int) strtotime($p['cdr_at'] . ' UTC') : null;
            $text = '5. ventende ' . ($i + 1) . ': cdr.id ' . $id . ' · cdr_at (gemt, UTC) ' . ($at === null ? 'null' : Sync::iso($at))
                . ' · forsøg ' . (int) ($p['tries'] ?? 0) . ' · først set ' . (is_string($p['first_seen'] ?? null) ? $p['first_seen'] : '?');
            if ($idWorks) {
                $text .= ' · id $eq: ' . $this->idLookup($id, ['$eq' => $id], false);
            } elseif ($at !== null) {
                $r = $this->startWindow($id, $at + ($best ?? 0));
                $text .= ' · start-vindue ' . self::hours($best ?? 0) . ': ' . ($r['page'] !== null ? 'fundet' : 'ikke fundet');
            }
            $say($text);
        }

        // 6. side om side
        $say('6. Fundne opkald (kun tidsfelter, varigheder og feltnavne):');
        if ($this->found === []) {
            $say('   (intet opkald fundet)');
        }
        $byId = [];
        foreach ($samples as $s) {
            $byId[$s['id']] = $s;
        }
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
                    $parts[] = $f . ' ' . (is_int($call[$f]) ? $call[$f] . ' s' : self::raw($call[$f]));
                }
            }
            $say('   ' . implode(' · ', $parts));
            if ($s !== null && $s['t'] !== null) {
                $diff = [];
                foreach (['start', 'end'] as $f) {
                    $t = Sync::epoch($call[$f] ?? null);
                    if ($t !== null) {
                        $diff[] = 'cdr.timestamp − ' . $f . ' = ' . self::signed($s['t'] - $t) . ' s';
                    }
                }
                $say('   ' . ($diff === [] ? 'forskel: kan ikke beregnes' : implode(' · ', $diff)));
            }
            $say('   felter: ' . self::fieldList($call));
            $this->verdict['start har offset'] ??= self::offsetText($call['start'] ?? null);
        }

        // 7. volumen: hvor mange opkald dækker en time (hypotese c: det brede opslag på 1000 opkald)
        $to = $this->now - $this->cfg->lag;
        $r = $this->callsWith(['start' => ['$gt' => Sync::iso($to - 3601), '$lt' => Sync::iso($to)]], null, 1, 'start', 1);
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
        try {
            $data = $this->c->getJson('/recordings', [
                'filters' => (string) json_encode(['timestamp' => ['$gt' => Sync::iso($from - 1), '$lt' => Sync::iso($to)]]),
                'page' => 1, 'pageSize' => self::PAGE, 'includeMeta' => 'true',
            ]);
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdversusError $e) {
            ($this->say)('1. /recordings: ✘ ' . $e->getMessage());
            return [];
        }
        $items = self::items($data, 'recordings');
        ($this->say)('1. /recordings med timestamp i [' . Sync::iso($from) . ', ' . Sync::iso($to) . '): total '
            . self::num(self::total($data)) . ' · på side 1: ' . count($items));
        // Ligger upload-tiderne uden for det vindue, vi bad om, tolker Adversus også /recordings-filtret forskudt
        $ups = array_values(array_filter(array_map(static fn(array $r): ?int => Sync::epoch($r['timestamp'] ?? null), $items),
            static fn(?int $t): bool => $t !== null));
        if ($ups !== []) {
            $inside = count(array_filter($ups, static fn(int $t): bool => $t >= $from && $t < $to));
            ($this->say)('   upload-tider på side 1 (som sync læser dem): ' . Sync::iso(min($ups)) . ' – ' . Sync::iso(max($ups))
                . ' · inden for vinduet: ' . $inside . ' af ' . count($ups));
            $this->verdict['recordings-filter'] = $inside === count($ups) ? 'som sync forventer'
                : (count($ups) - $inside) . ' af ' . count($ups) . ' upload-tider uden for vinduet (forskudt fortolkning?)';
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
                . ($t === null ? '' : ' (sync læser ' . Sync::iso($t) . ')') . ' · timestamp (upload) ' . self::raw($rec['timestamp'] ?? null)
                . ($t !== null && $up !== null ? ' · upload − cdr.timestamp = ' . self::signed($up - $t) . ' s' : ''));
            $this->verdict['cdr.timestamp har offset'] ??= self::offsetText($raw);
        }
        if ($out === []) {
            ($this->say)('   ingen optagelser i perioden — diagnosen bruger kun ventende rækker');
        }
        return $out;
    }

    /**
     * /calls med {"id": $ops}: "virker" (kun opkaldet selv), "afvist (…)", "ignoreret (…)" eller "0 opkald".
     *
     * @param array<string, int> $ops
     * @throws RateLimited
     */
    private function idLookup(int $id, array $ops, bool $print = true): string
    {
        $label = '{"id":' . (string) json_encode($ops) . '}';
        try {
            $data = $this->c->getJson('/calls', ['filters' => (string) json_encode(['id' => $ops]), 'page' => 1, 'pageSize' => 5,
                'includeMeta' => 'true']);
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdversusError $e) {
            $text = 'afvist (' . $e->getMessage() . ')';
            if ($print) {
                ($this->say)('   ' . $label . ': ' . $text);
            }
            return $text;
        }
        $items = self::items($data, 'calls');
        $ids = array_map(static fn(array $c): ?int => self::posInt($c['id'] ?? null), $items);
        if ($items === []) {
            $text = '0 opkald (total ' . self::num(self::total($data)) . ')';
        } elseif (array_unique($ids) === [$id]) {
            $text = 'virker';
            $this->found[$id] ??= $items[0];
        } else {
            $text = 'ignoreret (' . count($items) . ' opkald på side 1 med andre id\'er, total ' . self::num(self::total($data)) . ')';
        }
        if ($print) {
            ($this->say)('   ' . $label . ': ' . $text);
        }
        return $text;
    }

    /**
     * /calls med start i [t − BEFORE, t + AFTER], sorteret på start, højst PAGES sider; leder efter opkaldet $id.
     *
     * @return array{page: ?int, pages: int, total: ?int, text: string}
     * @throws RateLimited
     */
    private function startWindow(int $id, int $t): array
    {
        return $this->callsWith(['start' => ['$gt' => Sync::iso($t - self::BEFORE - 1), '$lt' => Sync::iso($t + self::AFTER)]], $id,
            self::PAGES, 'start');
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
                    return ['page' => $p, 'pages' => $p, 'total' => $total, 'text' => 'fundet (total ' . self::num($total) . ')'];
                }
            }
            if (count($items) < $pageSize) {
                break;
            }
        }
        return ['page' => null, 'pages' => $p, 'total' => $total, 'text' => 'ikke fundet (total ' . self::num($total) . ')'];
    }

    // ---------------------------------------------------------------- hjælpere

    /** En rå værdi fra Adversus, kun hvis den ligner et tidsstempel eller et tal; ellers kun typen og længden. */
    public static function raw(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_int($v) || is_float($v)) {
            return 'tal ' . $v;
        }
        if (is_string($v) && preg_match('/^[0-9TZ:+\-. ]{1,40}$/', $v) === 1) {
            return '"' . $v . '"';
        }
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

    private static function hours(int $s): string
    {
        return ($s === 0 ? '±' : ($s > 0 ? '+' : '−')) . abs(intdiv($s, 3600)) . ' t';
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
        $keys = array_filter(array_map('strval', array_keys($a)), static fn(string $k): bool => preg_match('/^[A-Za-z0-9_]{1,40}$/', $k) === 1);
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
