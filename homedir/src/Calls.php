<?php
declare(strict_types=1);

namespace Whisper;

use DateTimeImmutable;
use DateTimeZone;
use Whisper\Adversus\Feature;
use Whisper\Transcription\Diarization;
use Whisper\Transcription\DiarizationSettings;

/**
 * Opkald og transcripts til siderne (F002). Tider gemmes i UTC og vises i dansk tid; filtrenes datoer er danske.
 * Fritekst søger med FULLTEXT i BOOLEAN MODE (InnoDB indekserer kun ord på mindst 3 tegn), ellers LIKE.
 */
final class Calls
{
    public const PAGE_SIZE = 50;
    public const ZIP_MAX = 2000;
    public const ZIP_BATCH = 50;
    private const SNIPPET_LEAD = 120;
    private const SNIPPET_WINDOW = 480;
    public const TZ = 'Europe/Copenhagen';
    private const AUDIO_PATH = '#^recordings/[0-9]{4}/[0-9]{2}/[1-9][0-9]{0,9}\.(mp3|wav)$#';

    /**
     * Filtrene fra query-strengen, renset. Ugyldige værdier ignoreres.
     *
     * @param array<mixed> $q
     * @return array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string}
     */
    public static function filter(array $q): array
    {
        $s = static fn(string $k): string => is_string($q[$k] ?? null) ? trim($q[$k]) : '';
        // createFromFormat accepterer 2026-13-45 (løber over), så datoen skal give sig selv tilbage
        $date = static function (string $v): string {
            $d = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $v) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
        };
        $side = ctype_digit($s('side')) ? (int) $s('side') : 1;
        return [
            'fra'     => $date($s('fra')),
            'til'     => $date($s('til')),
            'retning' => in_array($s('retning'), ['inbound', 'outbound'], true) ? $s('retning') : '',
            'agent'   => mb_substr($s('agent'), 0, 120),
            'q'       => mb_substr($s('q'), 0, 200),
            'side'    => max(1, min($side, 100000)),
            // F004: visningen "Alle opkald" (vis=alle); standarden "Succes-leads" står aldrig i URL'en (UX D-F4-1)
            'vis'     => Feature::on() && $s('vis') === 'alle' ? 'alle' : '',
        ];
    }

    /**
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @return array{0: string, 1: list<mixed>} WHERE-del (faste SQL-stykker) og parametre
     */
    private static function where(array $f, bool $onlyDone = false): array
    {
        $sql = ['1 = 1'];
        $p = [];
        $sql[] = self::sourceSql($f['vis'] ?? '');
        if ($f['fra'] !== '') {
            $sql[] = 'c.started_at >= ?';
            $p[] = self::utcDayStart($f['fra'], 0);
        }
        if ($f['til'] !== '') {
            $sql[] = 'c.started_at < ?';
            $p[] = self::utcDayStart($f['til'], 1);
        }
        if ($f['retning'] !== '') {
            $sql[] = 'c.direction = ?';
            $p[] = $f['retning'];
        }
        if ($f['agent'] !== '') {
            $sql[] = 'c.agent_name = ?';
            $p[] = $f['agent'];
        }
        if ($f['q'] !== '') {
            $words = self::searchWords($f['q']);
            if ($words !== [] && mb_strlen($words[0]) >= 3) {
                $sql[] = 'c.id IN (SELECT call_id FROM transcripts WHERE MATCH(`text`) AGAINST (? IN BOOLEAN MODE))';
                $p[] = implode(' ', array_map(static fn(string $w): string => '+' . $w . '*', $words));
            } elseif ($words !== []) {
                $sql[] = 'c.id IN (SELECT call_id FROM transcripts WHERE `text` LIKE ?)';   // \ er LIKE's standard-escape
                $p[] = '%' . addcslashes(implode(' ', $words), '%_\\') . '%';
            }
        }
        if ($onlyDone) {
            $sql[] = "c.transcript_status = 'faerdig'";
        }
        return [implode(' AND ', $sql), $p];
    }

    /**
     * Kildeværnet (F004): med flaget slukket kun Zendesk (AK9: siderne og MCP er som før, selv om der ligger
     * Adversus-rækker). Tændt: "Succes-leads" (standard) = Zendesk + Adversus på succes-leads + Adversus-opkald, som en
     * bruger har valgt (alt, der ikke er "ikke valgt"; Team Lead, QA F-4); "alle" = alt.
     */
    public static function sourceSql(string $vis, string $alias = 'c'): string
    {
        if (!Feature::on()) {
            return $alias . ".`source` = 'zendesk'";
        }
        return $vis === 'alle' ? '1 = 1' : '(' . $alias . ".`source` = 'zendesk' OR " . $alias . '.is_success = 1 OR '
            . $alias . ".transcript_status <> 'ikke_valgt')";
    }

    /**
     * Ordene, som fritekst-søgningen bruger: ord på mindst 3 tegn (FULLTEXT indekserer kun dem), og kun hvis der
     * ingen er, de korte (LIKE). F005 fremhæver præcis de samme ord.
     *
     * @return list<string>
     */
    public static function searchWords(string $q): array
    {
        $words = preg_match_all('/[\p{L}\p{N}]+/u', $q, $m) > 0 ? $m[0] : [];
        $long = array_values(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 3));
        return $long !== [] ? $long : $words;
    }

    /**
     * Søgeuddrag til listen (F005): for hvert id et vindue på SNIPPET_WINDOW tegn omkring første forekomst af det
     * første søgeord. Vinduet skæres i SQL, så hverken hele transcriptet eller mere end én side (højst PAGE_SIZE
     * rækker) når PHP.
     *
     * @param list<int> $ids
     * @return array<int, array{text: string, cut_start: bool, cut_end: bool}>
     */
    public static function snippets(array $ids, string $q): array
    {
        $words = self::searchWords($q);
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::PAGE_SIZE);
        if ($ids === [] || $words === []) {
            return [];
        }
        // Første ORDSTART, der matcher (som FULLTEXT's +ord* og fremhævningen), ikke første delstreng: LOCATE('tal') finder
        // "totalt" (QA F-1). REGEXP_INSTR tæller i tegn som SUBSTRING. \b er ASCII-baseret i PCRE uden UCP, så ordgrænsen
        // er et lookbehind på bogstav/ciffer. Ordet er kun [\p{L}\p{N}]+ (searchWords), så det har ingen regex-tegn.
        // Uden regex-fund (fx en accentvariant, som unicode_ci og FULLTEXT matcher) bruges LOCATE som før.
        $at = 'COALESCE(NULLIF(REGEXP_INSTR(`text`, ?), 0), LOCATE(?, `text`))';
        $from = 'GREATEST(1, ' . $at . ' - ' . self::SNIPPET_LEAD . ')';
        $regex = '(?i)(?<![\p{L}\p{N}])' . $words[0];
        $rows = DB::all('SELECT call_id, ' . $from . ' AS off, SUBSTRING(`text`, ' . $from . ', ' . self::SNIPPET_WINDOW . ') AS win, '
            . 'CHAR_LENGTH(`text`) AS len FROM transcripts WHERE call_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$regex, $words[0], $regex, $words[0]], $ids));
        $out = [];
        foreach ($rows as $r) {
            $off = (int) $r['off'];
            $out[(int) $r['call_id']] = ['text' => (string) $r['win'], 'cut_start' => $off > 1,
                'cut_end' => $off - 1 + self::SNIPPET_WINDOW < (int) $r['len']];
        }
        return $out;
    }

    /**
     * Én side af listen. Siden klemmes til sidste side, så ?side=99999 hverken giver en tom side eller et dyrt OFFSET
     * (QA F-9, security S3).
     *
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int, side: int}
     */
    public static function search(array $f): array
    {
        [$w, $p] = self::where($f);
        $total = (int) DB::scalar('SELECT COUNT(*) FROM calls c WHERE ' . $w, $p);
        $side = max(1, min($f['side'], (int) ceil($total / self::PAGE_SIZE)));
        $offset = ($side - 1) * self::PAGE_SIZE;
        $rows = $total === 0 ? [] : DB::all('SELECT c.* FROM calls c WHERE ' . $w . ' ORDER BY c.started_at DESC, c.id DESC LIMIT '
            . self::PAGE_SIZE . ' OFFSET ' . (int) $offset, $p);
        return ['rows' => $rows, 'total' => $total, 'side' => $side];
    }

    /**
     * Et udsnit af samme liste som search() (samme WHERE og rækkefølge) med frit offset og højst PAGE_SIZE rækker —
     * til MCP (F003), hvor klienten vælger limit. Et offset ud over listen giver ingen rækker og ingen dyr OFFSET-query.
     *
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function range(array $f, int $offset, int $limit): array
    {
        [$w, $p] = self::where($f);
        $total = (int) DB::scalar('SELECT COUNT(*) FROM calls c WHERE ' . $w, $p);
        $limit = max(1, min($limit, self::PAGE_SIZE));
        $offset = max(0, $offset);
        $rows = $offset >= $total ? [] : DB::all('SELECT c.* FROM calls c WHERE ' . $w . ' ORDER BY c.started_at DESC, c.id DESC LIMIT '
            . $limit . ' OFFSET ' . $offset, $p);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Id'erne på de færdige transcripts i filtret til zip (nyeste først, højst ZIP_MAX). Teksterne læses bagefter i
     * batches med zipRows(), så hukommelsen ikke vokser med antallet (QA F-4).
     *
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @return list<int>
     */
    public static function zipIds(array $f): array
    {
        [$w, $p] = self::where($f, true);
        $rows = DB::all('SELECT c.id FROM calls c JOIN transcripts t ON t.call_id = c.id WHERE ' . $w
            . ' ORDER BY c.started_at DESC, c.id DESC LIMIT ' . self::ZIP_MAX, $p);
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @param list<int> $ids  højst ZIP_BATCH
     * @return list<array<string, mixed>> i samme rækkefølge som $ids
     */
    public static function zipRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::all('SELECT c.*, t.`text` AS t_text, t.segments_json AS t_segments FROM calls c JOIN transcripts t ON t.call_id = c.id '
            . 'WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $rows = DB::all('SELECT * FROM calls c WHERE c.id = ? AND ' . self::sourceSql('alle'), [$id]);
        return $rows[0] ?? null;
    }

    /** @return array{text: string, segments: list<array{start: float, end: float, text: string}>}|null */
    public static function transcript(int $id): ?array
    {
        $rows = DB::all('SELECT `text`, segments_json FROM transcripts WHERE call_id = ?', [$id]);
        if ($rows === []) {
            return null;
        }
        return ['text' => (string) $rows[0]['text'], 'segments' => self::segments((string) $rows[0]['segments_json'])];
    }

    /**
     * Segmenterne med taleren, når diarization (H011) har lagt den på: "speaker" (0, 1, …) og evt. "role" (agent|kunde).
     * Ugyldige taler-nøgler udelades, så segmentet vises som før H011. Med flaget H011_diarization slukket udelades de
     * altid: så er al visning (UI, .txt, zip, MCP) som før H011, også for transcripts, der allerede har talere.
     *
     * @return list<array{start: float, end: float, text: string, speaker?: int, role?: string}>
     */
    public static function segments(string $json): array
    {
        $out = [];
        $data = json_decode($json, true);
        $speakers = null;
        foreach (is_array($data) ? $data : [] as $s) {
            if (is_array($s) && is_numeric($s['start'] ?? null) && is_string($s['text'] ?? null)) {
                $seg = ['start' => (float) $s['start'], 'end' => (float) ($s['end'] ?? $s['start']), 'text' => $s['text']];
                if (isset($s['speaker'])) {
                    $speakers ??= Flags::enabled(DiarizationSettings::FLAG);   // features.ini læses kun, hvis der er talere
                }
                if ($speakers === true && is_int($s['speaker'] ?? null) && $s['speaker'] >= 0 && $s['speaker'] < Diarization::MAX_SPEAKERS) {
                    $seg['speaker'] = $s['speaker'];
                    if (in_array($s['role'] ?? null, ['agent', 'kunde'], true)) {
                        $seg['role'] = (string) $s['role'];
                    }
                }
                $out[] = $seg;
            }
        }
        return $out;
    }

    /**
     * "[00:12] Agent: tekst", eller "[00:12] tekst" uden taler (.txt, zip og MCP).
     *
     * @param array{start: float, end: float, text: string, speaker?: int, role?: string} $segment
     */
    public static function segmentLine(array $segment): string
    {
        $label = Diarization::label($segment);
        return '[' . self::stamp((float) $segment['start']) . '] ' . ($label !== null ? $label . ': ' : '') . (string) $segment['text'];
    }

    /** @return list<string> */
    public static function agents(): array
    {
        $rows = DB::all("SELECT DISTINCT c.agent_name FROM calls c WHERE c.agent_name <> '' AND " . self::sourceSql('alle') . ' ORDER BY c.agent_name');
        return array_map(static fn(array $r): string => (string) $r['agent_name'], $rows);
    }

    /** @return array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int} */
    public static function status(): array
    {
        $s = DB::all('SELECT last_ok_at, last_error FROM sync_state WHERE `source` = ?', ['zendesk']);
        // Kun opkald, hvis lyd er hentet, venter reelt på transcription (QA F-6)
        $src = self::sourceSql('alle');
        $waiting = (int) DB::scalar("SELECT COUNT(*) FROM calls c WHERE c.audio_status = 'hentet' "
            . "AND c.transcript_status IN ('ventende', 'transcriberer') AND " . $src);
        // "Ikke valgt" (F004) venter ikke på lyd: den hentes først, når nogen vælger opkaldet
        $audio = (int) DB::scalar("SELECT COUNT(*) FROM calls c WHERE c.audio_status = 'venter' AND c.transcript_status <> 'ikke_valgt' AND " . $src);
        $ok = $s[0]['last_ok_at'] ?? null;
        $err = $s[0]['last_error'] ?? null;
        return ['last_ok_at' => is_string($ok) ? $ok : null, 'last_error' => is_string($err) ? $err : null,
            'waiting' => $waiting, 'audio_waiting' => $audio];
    }

    /**
     * Adversus-hentningens status (F004), eller null når Adversus aldrig har kørt.
     *
     * @return array{last_ok_at: ?string, last_error: ?string}|null
     */
    public static function adversusStatus(): ?array
    {
        $s = DB::all('SELECT last_run_at, last_ok_at, last_error FROM sync_state WHERE `source` = ?', ['adversus']);
        if ($s === []) {
            return null;
        }
        $ok = $s[0]['last_ok_at'] ?? null;
        $err = $s[0]['last_error'] ?? null;
        return ['last_ok_at' => is_string($ok) ? $ok : null, 'last_error' => is_string($err) ? $err : null];
    }

    /** F004: seneste advarsel fra Adversus-hentningen (forkastede optagelser, ukendt retning), hvis den er under et døgn gammel. */
    public static function adversusWarning(int $now): ?string
    {
        $s = DB::all('SELECT last_run_at, last_error FROM sync_state WHERE `source` = ?', ['adversus-warn']);
        $at = $s[0]['last_run_at'] ?? null;
        $text = $s[0]['last_error'] ?? null;
        if (!is_string($at) || !is_string($text) || $now - (int) strtotime($at . ' UTC') > Adversus\Sync::WARN_TTL) {
            return null;
        }
        return $text;
    }

    /** @param array<string, mixed> $call */
    public static function isAdversus(array $call): bool
    {
        return ($call['source'] ?? '') === 'adversus';
    }

    public static function sourceName(string $source): string
    {
        return ['zendesk' => 'Zendesk', 'adversus' => 'Adversus'][$source] ?? '—';
    }

    /**
     * Den fulde sti til en optagelse, kun hvis stien fra databasen har den form, appen selv skriver, og filen ligger
     * under var/recordings (realpath: ingen ../ og ingen symlink ud af mappen). Ellers null.
     */
    public static function audioFile(?string $rel): ?string
    {
        if ($rel === null || preg_match(self::AUDIO_PATH, $rel) !== 1) {
            return null;
        }
        $root = realpath(Paths::varDir() . '/recordings');
        $file = realpath(Paths::varDir() . '/' . $rel);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        return $file;
    }

    /** Lokal dato (dansk tid) + $plusDays som UTC-tidspunkt for døgnets start. */
    public static function utcDayStart(string $ymd, int $plusDays): string
    {
        $d = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone(self::TZ));
        if ($plusDays !== 0) {
            $d = $d->modify('+' . $plusDays . ' day');
        }
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC fra databasen som dansk tid i det givne format; '' ved en tom værdi. */
    public static function local(mixed $utc, string $format = 'd.m.Y H:i'): string
    {
        if (!is_string($utc) || $utc === '') {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->setTimezone(new DateTimeZone(self::TZ))->format($format);
    }

    /** [mm:ss] eller [t:mm:ss] for et tidsstempel i sekunder. */
    public static function stamp(float $sec): string
    {
        $s = (int) floor($sec);
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }

    /** Varighed som m:ss eller t:mm:ss. */
    public static function duration(int $sec): string
    {
        return $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60)
            : sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }

    public static function directionName(string $d): string
    {
        return $d === 'outbound' ? 'Udgående' : 'Indgående';
    }

    public static function statusName(string $s): string
    {
        return ['ventende' => 'Venter', 'transcriberer' => 'Transcriberer', 'faerdig' => 'Færdig', 'fejlet' => 'Fejlet',
            'ikke_valgt' => 'Ikke valgt'][$s] ?? $s;
    }

    /**
     * Transcriptet som tekst med hoved-blok (spec: dato, retning, nummer, agent, ticket).
     *
     * @param array<string, mixed> $call
     * @param list<array{start: float, end: float, text: string, speaker?: int, role?: string}> $segments
     */
    public static function transcriptText(array $call, array $segments, string $text, ?string $ticketUrl): string
    {
        $ticket = (int) ($call['ticket_id'] ?? 0);
        $head = [
            'Opkald:   ' . self::local($call['started_at'] ?? null) . ' (dansk tid)',
            'Retning:  ' . self::directionName((string) $call['direction']),
            'Nummer:   ' . (string) $call['customer_phone'],
            'Agent:    ' . (string) $call['agent_name'],
            'Varighed: ' . self::duration((int) $call['duration_sec']),
        ];
        if (self::isAdversus($call)) {
            // F004: Adversus-opkald har ingen ticket, men et lead
            $lead = (int) ($call['lead_id'] ?? 0);
            $head[] = 'Kilde:    Adversus';
            $head[] = 'Lead:     ' . ($lead > 0 ? $lead . ((int) ($call['is_success'] ?? 0) === 1 ? ' (succes-lead)' : '') : '—');
        } else {
            $head[] = 'Ticket:   ' . ($ticket > 0 ? $ticket . ($ticketUrl !== null ? ' (' . $ticketUrl . ')' : '') : '—');
        }
        $body = [];
        foreach ($segments as $s) {
            $body[] = self::segmentLine($s);
        }
        if ($body === []) {
            $body[] = $text;
        }
        return implode("\n", $head) . "\n\n" . implode("\n", $body) . "\n";
    }

    /**
     * Filnavnet ÅÅÅÅ-MM-DD_HHMM_<retning>_<ticket>.txt (dansk tid; retning ind/ud).
     *
     * @param array<string, mixed> $call
     */
    public static function fileName(array $call): string
    {
        $ticket = (int) ($call['ticket_id'] ?? 0);
        if (self::isAdversus($call)) {
            $lead = (int) ($call['lead_id'] ?? 0);
            return self::local($call['started_at'] ?? null, 'Y-m-d_Hi') . '_' . ($call['direction'] === 'outbound' ? 'ud' : 'ind')
                . '_adversus_' . ($lead > 0 ? 'lead' . $lead : 'ingen') . '.txt';
        }
        return self::local($call['started_at'] ?? null, 'Y-m-d_Hi') . '_' . ($call['direction'] === 'outbound' ? 'ud' : 'ind')
            . '_' . ($ticket > 0 ? (string) $ticket : 'ingen') . '.txt';
    }
}
