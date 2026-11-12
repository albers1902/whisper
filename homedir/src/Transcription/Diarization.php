<?php
declare(strict_types=1);

namespace Whisper\Transcription;

/**
 * Talere på whisper-segmenter (H011), uden database og uden Python, så reglerne kan testes direkte.
 *
 * - Hvert segment får taleren med størst samlet tidsoverlap med diarization-intervallerne; uden overlap det nærmeste
 *   interval. Segmenter deles ikke ved et talerskifte (det kræver ordtidsstempler fra whisper).
 * - Talerne nummereres 0, 1, … efter, hvem der taler først i transcriptet.
 * - Rolle: taleren i det første segment, der indeholder en agentmarkør, er "agent", alle andre "kunde". Kun segmenter,
 *   der starter inden for de første MARKER_WINDOW sekunder, tæller: agenten præsenterer sig i starten, og en kunde, der
 *   senere nævner en markør, kan ikke vende rollerne. Uden markør får ingen en rolle, og visningen siger "Taler 1"/"Taler 2".
 */
final class Diarization
{
    /** Motoren kører med 2 talere; et højere nummer i svaret er ugyldigt */
    public const MAX_SPEAKERS = 8;
    /** Agentmarkører tæller kun i segmenter, der starter inden for så mange sekunder */
    public const MARKER_WINDOW = 60.0;

    /**
     * Motorens intervaller: [{"start": s, "end": s, "speaker": n}, …].
     *
     * @return list<array{start: float, end: float, speaker: int}>
     * @throws EngineError
     */
    public static function intervals(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new EngineError('diarization gav et ugyldigt svar');
        }
        $out = [];
        foreach ($raw as $iv) {
            if (!is_array($iv) || !is_numeric($iv['start'] ?? null) || !is_numeric($iv['end'] ?? null) || !is_int($iv['speaker'] ?? null)
                || $iv['speaker'] < 0 || $iv['speaker'] >= self::MAX_SPEAKERS) {
                throw new EngineError('diarization gav et ugyldigt svar');
            }
            $s = max(0.0, (float) $iv['start']);
            $e = max($s, (float) $iv['end']);
            $out[] = ['start' => $s, 'end' => $e, 'speaker' => $iv['speaker']];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $segments transcriptets segmenter (start, end, text; gamle speaker/role erstattes)
     * @param list<array{start: float, end: float, speaker: int}> $intervals
     * @param list<string> $phrases agentmarkører med små bogstaver
     * @return array{segments: list<array<string, mixed>>, speakers: int, agent: bool}
     */
    public static function apply(array $segments, array $intervals, array $phrases): array
    {
        $raw = [];
        foreach ($segments as $i => $seg) {
            unset($seg['speaker'], $seg['role']);
            $segments[$i] = $seg;
            $raw[$i] = $intervals === [] ? null : self::best((float) ($seg['start'] ?? 0), (float) ($seg['end'] ?? 0), $intervals);
        }
        // Nummerering efter første optræden i transcriptet
        $map = [];
        foreach ($raw as $spk) {
            if ($spk !== null && !isset($map[$spk])) {
                $map[$spk] = count($map);
            }
        }
        $agent = null;
        foreach ($segments as $i => $seg) {
            if ($raw[$i] === null) {
                continue;
            }
            $segments[$i]['speaker'] = $map[$raw[$i]];
            if ($agent === null && (float) ($seg['start'] ?? 0) < self::MARKER_WINDOW && self::hasPhrase(is_string($seg['text'] ?? null) ? $seg['text'] : '', $phrases)) {
                $agent = $map[$raw[$i]];
            }
        }
        if ($agent !== null) {
            foreach ($segments as $i => $seg) {
                if (isset($seg['speaker'])) {
                    $segments[$i]['role'] = $seg['speaker'] === $agent ? 'agent' : 'kunde';
                }
            }
        }
        return ['segments' => array_values($segments), 'speakers' => count($map), 'agent' => $agent !== null];
    }

    /**
     * "Agent", "Kunde", "Taler 1" … eller null (segment uden taler: vises som før H011).
     *
     * @param array<string, mixed> $segment
     */
    public static function label(array $segment): ?string
    {
        $role = $segment['role'] ?? null;
        if ($role === 'agent') {
            return 'Agent';
        }
        if ($role === 'kunde') {
            return 'Kunde';
        }
        $spk = $segment['speaker'] ?? null;
        return is_int($spk) && $spk >= 0 && $spk < self::MAX_SPEAKERS ? 'Taler ' . ($spk + 1) : null;
    }

    /** @param list<array{start: float, end: float, speaker: int}> $intervals ikke tom */
    private static function best(float $start, float $end, array $intervals): int
    {
        $end = max($start, $end);
        $overlap = [];
        foreach ($intervals as $iv) {
            $o = min($end, $iv['end']) - max($start, $iv['start']);
            if ($o > 0) {
                $overlap[$iv['speaker']] = ($overlap[$iv['speaker']] ?? 0.0) + $o;
            }
        }
        if ($overlap !== []) {
            ksort($overlap);
            $bestSpk = -1;
            $bestO = -1.0;
            foreach ($overlap as $spk => $o) {
                if ($o > $bestO) {
                    $bestSpk = $spk;
                    $bestO = $o;
                }
            }
            return $bestSpk;
        }
        // Intet overlap (fx et segment i en pause): det nærmeste interval; ved lige afstand det laveste nummer
        $bestSpk = $intervals[0]['speaker'];
        $bestD = INF;
        foreach ($intervals as $iv) {
            $d = max($iv['start'] - $end, $start - $iv['end'], 0.0);
            if ($d < $bestD || ($d === $bestD && $iv['speaker'] < $bestSpk)) {
                $bestSpk = $iv['speaker'];
                $bestD = $d;
            }
        }
        return $bestSpk;
    }

    /** @param list<string> $phrases */
    private static function hasPhrase(string $text, array $phrases): bool
    {
        $t = mb_strtolower($text, 'UTF-8');
        foreach ($phrases as $p) {
            if ($p !== '' && str_contains($t, $p)) {
                return true;
            }
        }
        return false;
    }
}
