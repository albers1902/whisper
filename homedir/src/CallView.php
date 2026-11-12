<?php
declare(strict_types=1);

namespace Whisper;

use Whisper\Transcription\Diarization;

/**
 * Siderne for F002 (opkaldsliste og detaljeside) i View's ramme. Al dynamisk tekst går gennem View::e().
 */
final class CallView
{
    /**
     * @param array<string, mixed> $user
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @param list<array<string, mixed>> $rows
     * @param list<string> $agents
     * @param array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int} $status
     * @param list<string> $notices  faste beskeder (fx "Zendesk er ikke sat op")
     */
    public static function list(array $user, string $csrf, ?string $flash, array $f, array $rows, int $total, array $agents,
        array $status, array $notices, ?string $subdomain): string
    {
        $e = View::e(...);
        $info = [];
        foreach ($notices as $n) {
            $info[] = '<p class="msg info">' . $e($n) . '</p>';
        }
        if ($status['last_error'] !== null && str_starts_with($status['last_error'], \Whisper\Zendesk\Sync::RATE_LIMIT_PREFIX)) {
            // En rate limit er normal drift, ikke en fejl (QA F-8)
            $info[] = '<p class="msg info">Zendesk bad os vente (rate limit). Hentningen fortsætter ved næste kørsel.</p>';
        } elseif ($status['last_error'] !== null) {
            $info[] = '<p class="msg error">Seneste hentning fra Zendesk stoppede: ' . $e($status['last_error']) . '</p>';
        }
        $last = $status['last_ok_at'] !== null ? Calls::local($status['last_ok_at']) : 'aldrig';
        $statusLine = '<p class="status muted">Sidst hentet fra Zendesk: ' . $e($last) . ' · '
            . $status['waiting'] . ' venter på transcription'
            . ($status['audio_waiting'] > 0 ? ' · ' . $status['audio_waiting'] . ' venter på lyd' : '') . '</p>';

        $opt = static function (string $value, string $label, string $current) use ($e): string {
            return '<option value="' . $e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . $e($label) . '</option>';
        };
        $agentOpts = $opt('', 'Alle agenter', $f['agent']);
        foreach ($agents as $a) {
            $agentOpts .= $opt($a, $a, $f['agent']);
        }
        $form = '<form method="get" action="/" class="filters">'
            . '<label>Fra<input type="date" name="fra" value="' . $e($f['fra']) . '"></label>'
            . '<label>Til<input type="date" name="til" value="' . $e($f['til']) . '"></label>'
            . '<label>Retning<select name="retning">' . $opt('', 'Begge', $f['retning']) . $opt('inbound', 'Indgående', $f['retning'])
            . $opt('outbound', 'Udgående', $f['retning']) . '</select></label>'
            . '<label>Agent<select name="agent">' . $agentOpts . '</select></label>'
            . '<label class="wide">Søg i transcripts<input type="search" name="q" value="' . $e($f['q']) . '" maxlength="200" '
            . 'aria-describedby="q-hjaelp"><small id="q-hjaelp" class="hint">Finder ord, der begynder med det, du skriver, og alle '
            . 'ordene skal være med. Ord på under 3 tegn tæller kun, hvis du kun søger på korte ord.</small></label>'
            . '<div class="actions"><button type="submit">Filtrér</button> <a href="/">Nulstil</a></div></form>';

        $filtered = $f['fra'] !== '' || $f['til'] !== '' || $f['retning'] !== '' || $f['agent'] !== '' || $f['q'] !== '';
        if ($rows === []) {
            $table = '<p class="empty">' . ($filtered ? 'Ingen opkald matcher filtret.' : 'Ingen opkald endnu.') . '</p>';
        } else {
            $trs = '';
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $ticket = (int) ($r['ticket_id'] ?? 0);
                $ticketCell = $ticket > 0
                    ? ($subdomain !== null
                        ? '<a href="' . $e('https://' . $subdomain . '.zendesk.com/agent/tickets/' . $ticket) . '" rel="noopener noreferrer" target="_blank">' . $ticket . '</a>'
                        : (string) $ticket)
                    : '—';
                $trs .= '<tr><td><a href="/opkald/' . $id . '">' . $e(Calls::local($r['started_at'])) . '</a></td>'
                    . '<td>' . $e(Calls::directionName((string) $r['direction'])) . '</td>'
                    . '<td>' . $e((string) $r['customer_phone']) . '</td>'
                    . '<td>' . $e((string) $r['agent_name']) . '</td>'
                    . '<td>' . $e(Calls::duration((int) $r['duration_sec'])) . '</td>'
                    . '<td>' . $ticketCell . '</td>'
                    . '<td>' . self::badge($r) . '</td></tr>';
            }
            $table = '<table class="calls"><thead><tr><th>Dato og tid</th><th>Retning</th><th>Telefonnummer</th><th>Agent</th>'
                . '<th>Varighed</th><th>Ticket</th><th>Transcription</th></tr></thead><tbody>' . $trs . '</tbody></table>';
        }

        $q = self::query($f);
        $pages = max(1, (int) ceil($total / Calls::PAGE_SIZE));
        $nav = '';
        if ($pages > 1) {
            $link = static fn(int $p, string $label): string => '<a href="/?' . $e(self::query($f, $p)) . '">' . $label . '</a>';
            $nav = '<p class="pager">' . ($f['side'] > 1 ? $link($f['side'] - 1, '← Forrige') . ' ' : '')
                . 'Side ' . min($f['side'], $pages) . ' af ' . $pages
                . ($f['side'] < $pages ? ' ' . $link($f['side'] + 1, 'Næste →') : '') . '</p>';
        }
        $zip = $total > 0 ? '<a class="button" href="/opkald/download' . ($q !== '' ? '?' . $e($q) : '') . '">Download alle (.zip)</a>' : '';
        $count = '<p class="muted">' . $total . ' opkald' . ($total > Calls::ZIP_MAX ? ' (zip-filen har de nyeste ' . Calls::ZIP_MAX . ' færdige)' : '') . '</p>';

        return View::layout('Opkald', '<div class="row"><h1>Opkald</h1>' . $zip . '</div>' . View::message(null, $flash)
            . implode('', $info) . $statusLine . $form . $count . $table . $nav, $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $call
     * @param array{text: string, segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>}|null $transcript
     */
    public static function show(array $user, string $csrf, array $call, ?array $transcript, bool $audioOnDisk, ?string $subdomain): string
    {
        $e = View::e(...);
        $id = (int) $call['id'];
        $ticket = (int) ($call['ticket_id'] ?? 0);
        $ticketHtml = $ticket > 0
            ? ($subdomain !== null ? '<a href="' . $e('https://' . $subdomain . '.zendesk.com/agent/tickets/' . $ticket)
                . '" rel="noopener noreferrer" target="_blank">' . $ticket . '</a>' : (string) $ticket)
            : '—';
        $meta = '<dl class="meta">'
            . '<dt>Dato og tid</dt><dd>' . $e(Calls::local($call['started_at'])) . '</dd>'
            . '<dt>Retning</dt><dd>' . $e(Calls::directionName((string) $call['direction'])) . '</dd>'
            . '<dt>Telefonnummer</dt><dd>' . $e((string) $call['customer_phone']) . '</dd>'
            . '<dt>Agent</dt><dd>' . $e((string) $call['agent_name']) . '</dd>'
            . '<dt>Varighed</dt><dd>' . $e(Calls::duration((int) $call['duration_sec'])) . '</dd>'
            . '<dt>Ticket</dt><dd>' . $ticketHtml . '</dd>'
            . '<dt>Transcription</dt><dd>' . $e(Calls::statusName((string) $call['transcript_status'])) . '</dd></dl>';

        $audioStatus = (string) $call['audio_status'];
        if ($audioStatus === 'hentet' && $audioOnDisk) {
            $audio = '<audio controls preload="metadata" src="/opkald/' . $id . '/lyd"></audio>';
        } elseif ($audioStatus === 'hentet') {
            $audio = '<p class="msg error">Lydfilen mangler på serveren.</p>';
        } elseif ($audioStatus === 'fejlet') {
            $audio = '<p class="msg error">Lyden kunne ikke hentes fra Zendesk (' . (int) $call['audio_attempts'] . ' forsøg): '
                . $e((string) ($call['audio_error'] ?? '')) . '</p>';
        } else {
            $audio = '<p class="msg info">Lyden er ikke hentet fra Zendesk endnu.</p>';
        }

        $status = (string) $call['transcript_status'];
        if ($status === 'faerdig' && $transcript !== null) {
            $lines = '';
            $prev = null;
            foreach ($transcript['segments'] as $s) {
                // Taleren (H011) står kun ved et skift; uden taler er linjen som før
                $label = Diarization::label($s);
                $who = $label !== null && $label !== $prev ? '<strong class="speaker">' . $e($label) . ':</strong> ' : '';
                $prev = $label;
                $lines .= '<p><span class="ts">[' . $e(Calls::stamp($s['start'])) . ']</span> ' . $who . $e($s['text']) . '</p>';
            }
            if ($lines === '') {
                $lines = '<p>' . $e($transcript['text']) . '</p>';
            }
            $tr = '<div class="row"><h2>Transcript</h2><a class="button" href="/opkald/' . $id . '/transcript">Download transcript (.txt)</a></div>'
                . '<div class="transcript">' . $lines . '</div>';
        } elseif ($status === 'faerdig') {
            $tr = '<h2>Transcript</h2><p class="msg info">' . $e(UiCalls::TRANSCRIPT_MISSING) . '</p>';
        } elseif ($status === 'fejlet') {
            $tr = '<h2>Transcript</h2><p class="msg error">Transcription fejlede: ' . $e((string) ($call['transcript_error'] ?? '')) . '</p>';
        } else {
            $tr = '<h2>Transcript</h2><p class="msg info">' . ($status === 'transcriberer' ? 'Transcriberes nu.' : 'Venter på transcription.') . '</p>';
        }
        return View::layout('Opkald ' . Calls::local($call['started_at']), '<p><a href="/">← Alle opkald</a></p>'
            . '<section class="card"><h1>Opkald ' . $e(Calls::local($call['started_at'])) . '</h1>' . $meta . $audio . $tr . '</section>',
            $user, $csrf);
    }

    /**
     * Status i listen. Uden hentet lyd kan intet transcriberes, så dér vises lydens status (QA F-6).
     *
     * @param array<string, mixed> $r
     */
    private static function badge(array $r): string
    {
        $audio = (string) $r['audio_status'];
        if ($audio === 'fejlet') {
            return '<span class="badge s-fejlet">Lyd fejlet</span>';
        }
        if ($audio !== 'hentet') {
            return '<span class="badge s-lyd">Lyd mangler</span>';
        }
        $t = (string) $r['transcript_status'];
        return '<span class="badge s-' . View::e($t) . '">' . View::e(Calls::statusName($t)) . '</span>';
    }

    /** @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f */
    public static function query(array $f, int $side = 1): string
    {
        // vis (F004) er kun sat, når flaget er tændt og "Alle opkald" er valgt, så den gamle query er uændret ellers
        $q = array_filter(['fra' => $f['fra'], 'til' => $f['til'], 'retning' => $f['retning'], 'agent' => $f['agent'], 'q' => $f['q'],
            'vis' => $f['vis'] ?? '',
            // F007 (kun med flaget sat): fanen — standarden Zendesk skrives aldrig — og statusfiltret
            'kilde' => ($f['kilde'] ?? '') === 'adversus' ? 'adversus' : '', 'status' => $f['status'] ?? ''], static fn(string $v): bool => $v !== '');
        if ($side > 1) {
            $q['side'] = (string) $side;
        }
        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }
}
