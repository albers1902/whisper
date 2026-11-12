<?php
declare(strict_types=1);

namespace Whisper;

use DateTimeImmutable;
use DateTimeZone;
use Whisper\Adversus\Sync as AdversusSync;
use Whisper\Mcp\Server;
use Whisper\OAuth\OAuth;
use Whisper\Transcription\Diarization;
use Whisper\Transcription\QueueRules;
use Whisper\Zendesk\Sync;

/**
 * Opkaldslisten og detaljen i det nye udseende (F007, ui-ux/F007_design_v2.md). Zendesk og Adversus hver på sin fane
 * (?kilde=adversus; standarden Zendesk står aldrig i URL'en), kort i stedet for tabel, chatbobler og egen afspiller.
 * Al dynamisk tekst går gennem Ui::e() eller Ui::highlight() (tokenisér før escape), som i F005.
 *
 * @phpstan-type Filter array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string, kilde?: string, status?: string}
 */
final class UiCallsV2
{
    private const EXCERPT_MAX = 200;

    /**
     * @param array<string, mixed> $user
     * @param Filter $f  fra Calls::uiFilter (kilde er 'zendesk' eller 'adversus')
     * @param list<array<string, mixed>> $rows
     * @param list<string> $agents  den aktive fanes agenter
     * @param array{last_ok_at: ?string, last_error: ?string} $zendesk  Zendesk' sync-status
     * @param 'ok'|'missing'|'invalid' $config  Zendesk-opsætningen
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool}> $snippets  søgeuddrag (kun med q)
     * @param array<int, array{segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>, text: string, cut: bool}> $excerpts
     * @param array{config: string, status: array{last_ok_at: ?string, last_error: ?string}|null, warning: ?string}|null $adversus  null = F004 slukket (ingen faner)
     * @param ?int $other  antal på den anden fane (det et klik giver); null = intet tal
     */
    public static function list(array $user, string $csrf, ?string $flash, array $f, array $rows, int $total, array $agents, array $zendesk,
        string $config, ?string $subdomain, array $snippets, array $excerpts, int $now, ?array $adversus, ?int $other): string
    {
        $e = Ui::e(...);
        $admin = ($user['role'] ?? '') === 'admin';
        $adv = $adversus !== null && ($f['kilde'] ?? '') === 'adversus';
        $name = $adv ? 'Adversus' : 'Zendesk';

        // Kildens statuslinje og beskeder — kun den aktive fanes (AK4)
        $zErr = $zendesk['last_error'];
        $zRate = $zErr !== null && str_starts_with($zErr, Sync::RATE_LIMIT_PREFIX);
        $aStatus = $adversus['status'] ?? null;
        $aErr = $aStatus['last_error'] ?? null;
        $aRate = $aErr !== null && str_starts_with($aErr, AdversusSync::RATE_LIMIT_PREFIX);
        $notes = '';
        if ($adv && $adversus !== null) {
            $setup = 'Opkald hentes ikke fra Adversus endnu. Kontakt en administrator, hvis du savner opkald.';
            if ($adversus['config'] === 'missing') {
                $notes .= Ui::msg('info', '<p>' . ($admin ? 'Adversus er ikke sat op. Opkald hentes, når <code>[adversus]</code> er udfyldt i config.ini.'
                    : $e($setup)) . '</p>');
            } elseif ($adversus['config'] === 'invalid') {
                $notes .= $admin ? Ui::msg('error', '<p>Adversus-opsætningen i config.ini er ugyldig.</p>') : Ui::msg('info', '<p>' . $e($setup) . '</p>');
            }
            if ($admin && $aErr !== null && !$aRate) {
                $notes .= Ui::msg('error', '<p>Seneste hentning fra Adversus stoppede.</p><details><summary>Teknisk besked</summary><p>' . $e($aErr) . '</p></details>');
            }
            if ($admin && ($adversus['warning'] ?? null) !== null) {
                $notes .= Ui::msg('warn', '<p>Nogle opkald kunne ikke hentes.</p><details><summary>Teknisk besked</summary><p>'
                    . $e((string) $adversus['warning']) . '</p></details>');
            }
            $line = $adversus['config'] === 'ok'
                ? UiCalls::sourceLine('Adversus', $aStatus['last_ok_at'] ?? null, $aErr, $aRate, $now)
                : '<p class="sync-line"><span class="dot dot-none" aria-hidden="true"></span>Adversus er ikke sat op</p>';
        } else {
            $setup = 'Opkald hentes ikke fra Zendesk endnu. Kontakt en administrator, hvis du savner opkald.';
            if ($config === 'missing') {
                $notes .= Ui::msg('info', '<p>' . ($admin ? 'Zendesk er ikke sat op. Opkald hentes, når <code>[zendesk]</code> er udfyldt i config.ini.'
                    : $e($setup)) . '</p>');
            } elseif ($config === 'invalid') {
                $notes .= $admin ? Ui::msg('error', '<p>Zendesk-opsætningen i config.ini er ugyldig.</p>') : Ui::msg('info', '<p>' . $e($setup) . '</p>');
            }
            if ($admin && $zErr !== null && !$zRate) {
                $notes .= Ui::msg('error', '<p>Seneste hentning fra Zendesk stoppede.</p><details><summary>Teknisk besked</summary><p>' . $e($zErr) . '</p></details>');
            }
            $line = UiCalls::sourceLine('Zendesk', $zendesk['last_ok_at'], $zErr, $zRate, $now);
        }
        if ($f['fra'] !== '' && $f['til'] !== '' && $f['til'] < $f['fra']) {
            $notes .= Ui::msg('info', '<p>Til-datoen er før fra-datoen.</p>');
        }

        $q = CallView::query($f);
        $zipNote = $total > Calls::ZIP_MAX;
        // Ingen zip ved "I kø": zip tager kun færdige transcripts (QA F-5)
        $zip = $total > 0 && ($f['status'] ?? '') !== Calls::STATUS_QUEUE ? '<a class="button secondary zip" href="/opkald/download' . ($q !== '' ? '?' . $e($q) : '') . '" aria-describedby="zip-hjaelp">'
            . Ui::icon('download') . '<span class="wide-only">Download transcripts (.zip)</span><span class="narrow-only">Download .zip</span></a>' : '';
        $head = '<div class="page-head"><div><h1>Opkald</h1>' . $line . '</div>' . $zip . '</div>';

        // Fanerne (kun med F004) med antal og markør på den inaktive fane
        $tabs = '';
        if ($adversus !== null) {
            $zMark = $zErr !== null && !$zRate ? 'error' : null;
            $aMark = $aErr !== null && !$aRate ? 'error' : ($admin && ($adversus['warning'] ?? null) !== null ? 'warn' : null);
            $tab = static function (string $kilde, string $label, ?int $count, ?string $mark, bool $active) use ($f, $e): string {
                $href = self::listUrl(['kilde' => $kilde, 'agent' => '', 'vis' => '', 'side' => 1] + $f);
                $flag = '';
                if (!$active && $mark !== null) {
                    $flag = '<span class="tab-flag tab-flag-' . $mark . '">' . Ui::icon($mark === 'error' ? 'circle-alert' : 'triangle-alert', 14)
                        . '<span class="sr-only">' . ($mark === 'error' ? ', hentningen er stoppet' : ', har en advarsel') . '</span></span>';
                }
                return '<li><a href="' . $e($href) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label
                    . ($count !== null ? '<span class="tab-count">' . self::countText($count) . '<span class="sr-only"> opkald</span></span>' : '') . $flag . '</a></li>';
            };
            $tabs = '<nav class="src-tabs" aria-label="Kilde"><ul>'
                . $tab('zendesk', 'Zendesk', $adv ? $other : $total, $zMark, !$adv)
                . $tab('adversus', 'Adversus', $adv ? $total : $other, $aMark, $adv) . '</ul></nav>';
        }
        $tools = self::statusPills($f) . ($adv ? self::viewSwitch($f) : '');

        $first = ($f['side'] - 1) * Calls::PAGE_SIZE + 1;
        $last = min($total, $f['side'] * Calls::PAGE_SIZE);
        $summary = $total > 0 ? '<p class="count" id="antal">Viser ' . UiV2::num($first) . '–' . UiV2::num($last) . ' af '
            . ($total > Calls::COUNT_CAP ? 'flere end ' . UiV2::num(Calls::COUNT_CAP) : UiV2::num($total)) . ' opkald'
            . ($zipNote ? ' · zip-filen får de ' . Calls::ZIP_MAX . ' nyeste færdige' : '') . '</p>'
            . '<p class="sr-only" id="zip-hjaelp">' . ($zipNote ? 'Zip-filen får de ' . Calls::ZIP_MAX . ' nyeste færdige.'
                : 'Alle færdige transcripts i filtret, højst ' . Calls::ZIP_MAX . '.') . '</p>' : '';
        $words = Calls::searchWords($f['q']);
        $tip = $words !== [] && mb_strlen($words[0]) < 3 ? '<p class="hint">Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf.</p>' : '';

        // Ved loftet: ældre opkald nås kun ved at indsnævre (QA F-6)
        $capHint = $total > Calls::COUNT_CAP && $f['side'] >= (int) (Calls::COUNT_CAP / Calls::PAGE_SIZE)
            ? '<p class="hint cap-hint">Kun de nyeste ' . UiV2::num(Calls::COUNT_CAP) . ' opkald kan bladres. Indsnævr med periode, agent eller søgning for at se ældre opkald.</p>' : '';
        $list = $rows === [] ? self::emptyState($f, $adv, $adversus !== null, $other)
            : self::cards($rows, $f, $subdomain, $snippets, $excerpts, $now);

        return Ui::layout('Opkald · ' . $name, '<div class="list-page">' . $head . Ui::message(null, $flash) . self::searchCard($f, $agents, $now)
            . '<div class="tab-row">' . $tabs . '<div class="tab-tools">' . $tools . '</div></div>' . $notes
            . $summary . $tip . $list . self::pager($f, $total) . $capHint . '</div>', $user, $csrf, 'opkald');
    }

    /** @param Filter $f */
    private static function filtered(array $f): bool
    {
        return $f['fra'] !== '' || $f['til'] !== '' || $f['retning'] !== '' || $f['agent'] !== '' || $f['q'] !== '' || ($f['status'] ?? '') !== '';
    }

    /**
     * Søgekortet med piller (UI/UX v2 §2.2): native details/select, ingen auto-submit.
     *
     * @param Filter $f
     * @param list<string> $agents
     */
    private static function searchCard(array $f, array $agents, int $now): string
    {
        $e = Ui::e(...);
        $opt = static fn(string $value, string $label, string $current): string
            => '<option value="' . $e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . $e($label) . '</option>';
        $agentOpts = $opt('', 'Alle agenter', $f['agent']);
        foreach ($agents as $a) {
            $agentOpts .= $opt($a, $a, $f['agent']);
        }
        if ($f['agent'] !== '' && !in_array($f['agent'], $agents, true)) {
            $agentOpts .= $opt($f['agent'], $f['agent'], $f['agent']);   // filtret står stadig i URL'en; pillen viser det
        }
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(Calls::TZ));
        $quick = [
            'Alle datoer'    => ['', ''],
            'I dag'          => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            'Seneste 7 dage' => [$today->modify('-6 day')->format('Y-m-d'), $today->format('Y-m-d')],
            'Denne måned'    => [$today->format('Y-m-01'), $today->format('Y-m-d')],
        ];
        $d = static fn(string $ymd): string => substr($ymd, 8, 2) . '.' . substr($ymd, 5, 2) . '.' . substr($ymd, 0, 4);
        $period = $f['fra'] !== '' && $f['til'] !== '' ? $d($f['fra']) . '–' . $d($f['til'])
            : ($f['fra'] !== '' ? 'Fra ' . $d($f['fra']) : ($f['til'] !== '' ? 'Til ' . $d($f['til']) : 'Alle datoer'));
        $links = '';
        foreach ($quick as $label => [$fra, $til]) {
            $active = $f['fra'] === $fra && $f['til'] === $til;
            if ($active) {
                $period = $label;
            }
            $links .= '<li><a href="' . $e(self::listUrl(['fra' => $fra, 'til' => $til, 'side' => 1] + $f)) . '"' . ($active ? ' aria-current="true"' : '')
                . '>' . $e($label) . '</a></li>';
        }
        $dateSet = $f['fra'] !== '' || $f['til'] !== '';
        $hidden = (($f['kilde'] ?? '') === 'adversus' ? '<input type="hidden" name="kilde" value="adversus">' : '')
            . (($f['vis'] ?? '') === 'alle' ? '<input type="hidden" name="vis" value="alle">' : '')
            . (($f['status'] ?? '') !== '' ? '<input type="hidden" name="status" value="' . $e((string) $f['status']) . '">' : '');
        $reset = self::filtered(['status' => ''] + $f)
            ? '<a class="reset" href="' . $e(self::resetUrl($f)) . '">Nulstil filtre</a>' : '';
        return '<form method="get" action="/" class="search-card" role="search" aria-label="Søg og filtrér opkald">' . $hidden
            . '<div class="search-input">' . Ui::icon('search', 20) . '<label for="q" class="sr-only">Søg i transcripts</label>'
            . '<input type="search" id="q" name="q" value="' . $e($f['q']) . '" maxlength="200" '
            . 'placeholder="Søg i alt, der er sagt — fx opsigelse, flytning, faktura" aria-describedby="q-hjaelp">'
            . '<span class="sr-only" id="q-hjaelp">Alle ord skal indgå. Ord på mindst 3 bogstaver giver det bedste resultat.</span></div>'
            . '<div class="pills">'
            . '<details class="pill-menu"><summary class="pill' . ($dateSet ? ' is-set' : '') . '">' . Ui::icon('calendar')
            . '<span class="sr-only">Periode: </span><span class="pill-text">' . $e($period) . '</span>' . Ui::icon('chevron-down') . '</summary>'
            . '<div class="pill-panel"><ul class="quick">' . $links . '</ul><div class="date-fields">'
            . '<label>Fra<input type="date" name="fra" value="' . $e($f['fra']) . '"></label>'
            . '<label>Til<input type="date" name="til" value="' . $e($f['til']) . '"></label></div>'
            . '<button type="submit" class="button secondary">Brug datoer</button></div></details>'
            . '<span class="pill-select' . ($f['agent'] !== '' ? ' is-set' : '') . '"><label for="f-agent" class="sr-only">Agent</label>'
            . '<select id="f-agent" name="agent">' . $agentOpts . '</select></span>'
            . '<span class="pill-select' . ($f['retning'] !== '' ? ' is-set' : '') . '"><label for="f-retning" class="sr-only">Retning</label>'
            . '<select id="f-retning" name="retning">' . $opt('', 'Ind og ud', $f['retning']) . $opt('inbound', 'Indgående', $f['retning'])
            . $opt('outbound', 'Udgående', $f['retning']) . '</select></span>' . $reset . '</div>'
            . '<button type="submit" class="button search-submit">Søg</button></form>';
    }

    /** @param Filter $f  "Nulstil filtre" bevarer fanen og visningen (og intet andet) */
    private static function resetUrl(array $f): string
    {
        return self::listUrl(['fra' => '', 'til' => '', 'retning' => '', 'agent' => '', 'q' => '', 'status' => '', 'side' => 1] + $f);
    }

    /**
     * "Alle / Færdige / I kø" (AK10): links, der bevarer fanen og filtrene og nulstiller siden.
     *
     * @param Filter $f
     */
    private static function statusPills(array $f): string
    {
        $cur = $f['status'] ?? '';
        $out = '';
        foreach ([['', 'Alle'], [Calls::STATUS_DONE, 'Færdige'], [Calls::STATUS_QUEUE, 'I kø']] as [$v, $label]) {
            $out .= '<a class="fpill" href="' . Ui::e(self::listUrl(['status' => $v, 'side' => 1] + $f)) . '"' . ($cur === $v ? ' aria-current="true"' : '')
                . '>' . $label . '</a>';
        }
        return '<div class="pill-group" role="group" aria-label="Status">' . $out . '</div>';
    }

    /** @param Filter $f  Succes-vælgeren (kun Adversus) */
    private static function viewSwitch(array $f): string
    {
        $all = ($f['vis'] ?? '') === 'alle';
        $link = static fn(string $vis, string $label, bool $active): string => '<a class="fpill' . ($active ? ' fpill-success' : '') . '" href="'
            . Ui::e(self::listUrl(['vis' => $vis, 'side' => 1] + $f)) . '"' . ($active ? ' aria-current="true"' : '') . '>' . $label . '</a>';
        return '<div class="pill-group" role="group" aria-label="Vis">' . $link('', 'Succes og valgte', !$all) . $link('alle', 'Alle opkald', $all) . '</div>';
    }

    /** @param Filter $f */
    private static function emptyState(array $f, bool $adv, bool $tabs, ?int $other): string
    {
        $e = Ui::e(...);
        $all = ($f['vis'] ?? '') === 'alle';
        if (($f['status'] ?? '') === Calls::STATUS_QUEUE && !self::filtered(['status' => ''] + $f)) {
            return self::empty('Ingen opkald i kø lige nu', 'Nye opkald transcriberes, så snart lyden er hentet.', '');   // QA F-5
        }
        if (self::filtered($f)) {
            $actions = '<a class="button secondary" href="' . $e(self::resetUrl($f)) . '">Nulstil filtre</a>';
            if ($adv && !$all) {
                $actions .= '<a class="button secondary" href="' . $e(self::listUrl(['vis' => 'alle', 'side' => 1] + $f)) . '">Søg i alle opkald</a>';
            }
            $hint = '';
            if ($tabs && $other !== null && $other > 0) {
                $otherF = ['kilde' => $adv ? 'zendesk' : 'adversus', 'agent' => '', 'vis' => '', 'side' => 1] + $f;
                $hint = '<p class="empty-text">Søgningen giver ' . self::countText($other) . ' træf under ' . ($adv ? 'Zendesk' : 'Adversus') . '. <a href="'
                    . $e(self::listUrl($otherF)) . '">Se dem</a></p>';
            }
            return self::empty('Ingen opkald matcher', 'Prøv en længere periode, færre søgeord eller en anden agent.', $hint . '<div class="actions">' . $actions . '</div>');
        }
        if (!$adv) {
            return self::empty('Ingen Zendesk-opkald endnu', 'Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter.', '');
        }
        return $all
            ? self::empty('Ingen Adversus-opkald endnu', 'Opkald med optagelse fra Adversus dukker op her inden for 10 minutter.', '')
            : self::empty('Ingen Adversus-opkald endnu', 'Opkald på succes-leads og opkald, der er valgt til transcription, dukker op her inden for 10 minutter.',
                '<div class="actions"><a class="button secondary" href="/?kilde=adversus&amp;vis=alle">Vis alle opkald</a></div>');
    }

    private static function empty(string $title, string $text, string $action): string
    {
        return '<div class="empty"><div class="empty-icon">' . Ui::icon('inbox', 24) . '</div><h2 class="empty-title">' . Ui::e($title)
            . '</h2><p class="empty-text">' . Ui::e($text) . '</p>' . $action . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param Filter $f
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool}> $snippets
     * @param array<int, array{segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>, text: string, cut: bool}> $excerpts
     */
    private static function cards(array $rows, array $f, ?string $subdomain, array $snippets, array $excerpts, int $now): string
    {
        $e = Ui::e(...);
        $ctx = CallView::query($f, $f['side']);
        $terms = Ui::terms($f['q']);
        $items = '';
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $adv = Calls::isAdversus($r);
            $out = (string) $r['direction'] === 'outbound';
            $agent = trim((string) $r['agent_name']);
            $shown = $agent !== '' ? $agent : 'Ukendt agent';
            $phone = (string) $r['customer_phone'];
            $when = UiV2::when($r['started_at'] ?? null, $now);
            [$badgeClass, $badgeText] = self::status($r, null);
            $meta = '<span class="dir"><span aria-hidden="true">' . ($out ? '↑ Ud' : '↓ Ind') . '</span><span class="sr-only">'
                . ($out ? 'Udgående' : 'Indgående') . '</span></span>'
                . ($phone !== '' ? ' · <span class="cc-phone">' . $e($phone) . '</span>' : '')
                . ($when !== '' ? ' · ' . Ui::time((string) $r['started_at'], $when) : '');
            $tags = Ui::sourceTag((string) $r['source']);
            if ($adv) {
                $campaign = (int) ($r['campaign_id'] ?? 0);
                $lead = (int) ($r['lead_id'] ?? 0);
                $tags .= ($campaign > 0 ? '<span class="tag">Kampagne #' . $campaign . '</span>' : '')
                    . ($lead > 0 ? '<span class="tag">Lead #' . $lead . '</span>' : '')
                    . ((int) ($r['is_success'] ?? 0) === 1 ? Ui::successTag() : '');
            } else {
                $tags .= self::ticketTag((int) ($r['ticket_id'] ?? 0), $subdomain);
            }
            $excerpt = '';
            if ($terms !== [] && isset($snippets[$id])) {
                $excerpt = Ui::snippet($snippets[$id]['text'], $snippets[$id]['cut_start'], $snippets[$id]['cut_end'], $terms);
            } elseif ((string) $r['transcript_status'] === 'faerdig' && isset($excerpts[$id])) {
                $excerpt = self::excerpt($excerpts[$id]);
            }
            $items .= '<li class="call-card"><div class="cc-main">' . UiV2::avatar($agent) . '<div class="cc-who">'
                . '<a class="card-link" href="/opkald/' . $id . ($ctx !== '' ? '?' . $e($ctx) : '') . '" title="' . $e($shown) . '">' . $e($shown)
                . '<span class="sr-only">, ' . ($out ? 'udgående' : 'indgående') . ' opkald' . ($when !== '' ? ', ' . $e($when) : '') . ', ' . $e($badgeText) . '</span></a>'
                . '<p class="cc-meta">' . $meta . '</p></div><span class="tags">' . $tags . '</span></div>'
                . '<div class="cc-side"><span class="cc-dur"><span class="sr-only">Varighed </span>' . $e(Calls::duration((int) $r['duration_sec'])) . '</span>'
                . '<span class="badge ' . $badgeClass . '">' . $e($badgeText) . '</span></div>'
                . ($excerpt !== '' ? '<p class="cc-excerpt"><span class="sr-only">Uddrag: </span>' . $excerpt . '</p>' : '') . '</li>';
        }
        return '<ol class="call-list" aria-labelledby="antal">' . $items . '</ol>';
    }

    /**
     * Status-pillen som Ui::status (samme afbildning i liste og detalje); "Venter" hedder "I kø" (D-F7v2-10).
     *
     * @param array<string, mixed> $call
     * @return array{0: string, 1: string}
     */
    public static function status(array $call, ?bool $audioOnDisk): array
    {
        [$class, $text] = Ui::status((string) ($call['audio_status'] ?? ''), (string) ($call['transcript_status'] ?? ''), $audioOnDisk,
            QueueRules::fromConfig()->skipReason($call, time()));
        return [$class, $text === 'Venter' ? 'I kø' : $text];
    }

    private static function ticketTag(int $ticket, ?string $subdomain): string
    {
        if ($ticket <= 0) {
            return '';
        }
        if ($subdomain === null) {
            return '<span class="tag tag-ticket">Ticket #' . $ticket . '</span>';
        }
        return '<a class="tag tag-ticket ticket" href="' . Ui::e('https://' . $subdomain . '.zendesk.com/agent/tickets/' . $ticket)
            . '" rel="noopener noreferrer" target="_blank">Ticket #' . $ticket . Ui::icon('external-link', 14)
            . '<span class="sr-only"> (åbner Zendesk i en ny fane)</span></a>';
    }

    /**
     * Samtalens begyndelse (AK5): med talere "Agent: … Kunde: …" ved hvert skift, ellers de første ord. Ca. EXCERPT_MAX tegn.
     *
     * @param array{segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>, text: string, cut: bool} $x
     */
    public static function excerpt(array $x): string
    {
        $labelled = false;
        foreach ($x['segments'] as $s) {
            if (Diarization::label($s) !== null) {
                $labelled = true;
                break;
            }
        }
        if (!$labelled) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $x['text']));
            if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
                return '';
            }
            [$piece, $cut] = self::clip($text, self::EXCERPT_MAX);
            return Ui::e($piece) . ($cut || $x['cut'] ? '…' : '');
        }
        $out = '';
        $len = 0;
        $prev = null;
        foreach ($x['segments'] as $s) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $s['text']));
            if ($t === '') {
                continue;
            }
            $label = Diarization::label($s);
            if ($label !== null && $label !== $prev) {
                $cls = $label === 'Agent' ? 'who-agent' : ($label === 'Kunde' ? 'who-kunde' : 'who-n');
                $out .= ($out !== '' ? ' ' : '') . '<strong class="' . $cls . '">' . Ui::e($label) . ':</strong> ';
                $prev = $label;
            } elseif ($out !== '') {
                $out .= ' ';
            }
            [$piece, $cut] = self::clip($t, self::EXCERPT_MAX - $len);
            $out .= Ui::e($piece);
            $len += mb_strlen($piece);
            if ($cut || $len >= self::EXCERPT_MAX) {
                return $out . '…';
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: bool} højst $max tegn, klippet ved et mellemrum */
    private static function clip(string $text, int $max): array
    {
        if ($max <= 0) {
            return ['', true];
        }
        if (mb_strlen($text) <= $max) {
            return [$text, false];
        }
        $piece = mb_substr($text, 0, $max);
        $space = mb_strrpos($piece, ' ');
        return [$space !== false && $space > $max / 2 ? mb_substr($piece, 0, $space) : $piece, true];
    }

    /** Et antal fra Calls::count: over loftet "10.000+". */
    public static function countText(int $n): string
    {
        return $n > Calls::COUNT_CAP ? UiV2::num(Calls::COUNT_CAP) . '+' : UiV2::num($n);
    }

    /** @param Filter $f  højst COUNT_CAP / PAGE_SIZE sider (Calls::searchCapped) */
    private static function pager(array $f, int $total): string
    {
        $capped = $total > Calls::COUNT_CAP;
        $pages = max(1, (int) ceil(min($total, Calls::COUNT_CAP) / Calls::PAGE_SIZE));
        if ($pages <= 1) {
            return '';
        }
        $side = min($f['side'], $pages);
        $btn = static function (int $p, bool $enabled, string $label, string $icon, bool $iconFirst) use ($f): string {
            $word = '<span class="pg-word" aria-hidden="true">' . $label . '</span><span class="sr-only pg-sr">' . $label . ' side</span>';
            $inner = $iconFirst ? Ui::icon($icon) . $word : $word . Ui::icon($icon);
            return $enabled ? '<a class="button secondary small" href="' . Ui::e(self::listUrl($f, $p)) . '">' . $inner . '</a>'
                : '<span class="button secondary small" aria-disabled="true">' . $inner . '</span>';
        };
        return '<nav class="pager" aria-label="Sider">' . $btn($side - 1, $side > 1, 'Forrige', 'chevron-left', true)
            . '<span class="page-info">Side ' . UiV2::num($side) . ' af ' . UiV2::num($pages) . ($capped ? '+' : '') . '</span>'
            . $btn($side + 1, $side < $pages, 'Næste', 'chevron-right', false) . '</nav>';
    }

    /** @param array<string, mixed> $f */
    private static function listUrl(array $f, ?int $side = null): string
    {
        /** @var Filter $f */
        $q = CallView::query($f, $side ?? (int) $f['side']);
        return $q === '' ? '/' : '/?' . $q;
    }

    /**
     * Detaljen (UI/UX v2 §1.5, §2.8–2.11): sidehoved med chips, afspillerkort, samtale som bobler eller én kolonne med
     * "Find i samtalen", metadata og Claude-boks.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $call
     * @param array{text: string, segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>}|null $transcript
     * @param Filter $f
     */
    public static function show(array $user, string $csrf, array $call, ?array $transcript, bool $audioOnDisk, ?string $subdomain, array $f,
        ?string $flash = null, ?string $info = null): string
    {
        $e = Ui::e(...);
        $admin = ($user['role'] ?? '') === 'admin';
        $id = (int) $call['id'];
        $adv = Calls::isAdversus($call);
        $ticket = (int) ($call['ticket_id'] ?? 0);
        $success = $adv && (int) ($call['is_success'] ?? 0) === 1;
        $phone = (string) $call['customer_phone'];
        $agent = trim((string) $call['agent_name']);
        $out = (string) $call['direction'] === 'outbound';
        $sourceName = $adv ? 'Adversus' : 'Zendesk';
        $ctx = CallView::query($f, $f['side']);
        // Tilbage til opkaldets egen fane (D-F7-9); Zendesk har ingen visning
        $backF = ['kilde' => $adv ? 'adversus' : 'zendesk'] + $f;
        if (!$adv) {
            $backF['vis'] = '';
        }
        $back = '<p class="back"><a href="' . $e(self::listUrl($backF)) . '">' . Ui::icon('arrow-left') . 'Tilbage til opkald</a></p>';

        $title = ($out ? 'Udgående' : 'Indgående') . ' opkald · ' . Calls::local($call['started_at'], 'd.m.Y') . ' kl. '
            . Calls::local($call['started_at'], 'H:i');
        $h1 = ($agent !== '' ? $agent : 'Ukendt agent') . ' · ' . ($out ? 'udgående' : 'indgående') . ' opkald';
        $audioStatus = (string) $call['audio_status'];
        $playable = $audioStatus === 'hentet' && $audioOnDisk;
        [$bClass, $bText] = self::status($call, $audioStatus === 'hentet' ? $audioOnDisk : null);
        $badge = '<span class="badge ' . $bClass . '">' . $e($bText) . '</span>';
        $chips = '<span class="chip-meta">' . $e(UiV2::longDate($call['started_at'] ?? null)) . '</span>'
            . '<span class="chip-meta">' . $e(Calls::duration((int) $call['duration_sec'])) . ' min</span>'
            . '<span class="chip-meta chip-source">' . $sourceName . '</span>'
            . (!$adv && $ticket > 0 ? self::ticketTag($ticket, $subdomain) : '')
            . ($success ? Ui::successTag() : '') . $badge;

        $meta = self::meta($call, $adv, $success, $subdomain, $badge);
        $tStatus = (string) $call['transcript_status'];
        $notChosen = $adv && $tStatus === 'ikke_valgt';
        $rules = QueueRules::fromConfig();
        $skip = $rules->skipReason($call, time());
        $skipped = $skip !== null && $audioStatus === 'hentet';

        // Afspillerkortet eller lydens tilstand
        if ($notChosen) {
            $audio = Ui::msg('info', '<p>Lyden er ikke hentet. Opkaldet er ikke på et succes-lead.</p>');
        } elseif ($playable) {
            $audio = self::player($id, (int) $call['duration_sec']);
        } elseif ($audioStatus === 'hentet') {
            $audio = Ui::msg('error', '<p>Lydfilen mangler på serveren. Kontakt en administrator.</p>');
        } elseif ($audioStatus === 'fejlet') {
            $audio = Ui::msg('error', '<p>Lyden kunne ikke hentes fra ' . $sourceName . ' (' . (int) $call['audio_attempts'] . ' forsøg).</p>'
                . ((string) ($call['audio_error'] ?? '') !== '' ? '<details><summary>Teknisk besked</summary><p>' . $e((string) $call['audio_error'])
                    . '</p></details>' : '')
                . (!$adv && $ticket > 0 && $subdomain !== null ? '<p>Optagelsen kan måske afspilles direkte i ticket ' . Ui::ticket($ticket, $subdomain) . '.</p>' : ''));
        } else {
            $audio = Ui::msg('info', '<p>Lyden er ikke hentet fra ' . $sourceName . ' endnu. Det sker normalt inden for 10 minutter.</p>');
        }

        $actions = '';
        $hits = '';
        $find = '';
        $ctxField = $ctx !== '' ? '<input type="hidden" name="ctx" value="' . $e($ctx) . '">' : '';
        $button = static fn(string $hint): string => '<form method="post" action="/opkald/' . $id . '/transcriber" class="actions select-call">'
            . View::csrfField($csrf) . $ctxField
            . '<button type="submit" class="button" aria-describedby="vaelg-hjaelp" data-busy="Sætter i kø …">Transcribér dette opkald</button></form>'
            . '<p class="hint" id="vaelg-hjaelp">' . $hint . '</p>';
        if ($notChosen) {
            $body = Ui::msg('info', '<p>Opkaldet er ikke på et succes-lead og bliver derfor ikke transcriberet automatisk.</p>'
                . $button('Lyden hentes fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter.'));
        } elseif ($skipped) {
            $why = $skip === QueueRules::SKIP_SHORT ? 'kortere end ' . $rules->minSeconds . ' sekunder' : 'ældre end ' . $rules->maxAgeDays . ' dage';
            $body = Ui::msg('info', '<p>Opkaldet er ' . $why . ' og bliver derfor ikke transcriberet automatisk.</p>'
                . $button('Opkaldet sættes i kø og transcriberes, når opkaldene foran det er færdige.'));
        } elseif ($adv && CallPages::selectable($call, $skip)) {
            $body = Ui::msg('info', '<p>Der kan ikke laves et transcript uden lyd.</p>'
                . $button('Lyden hentes igen fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter.'));
        } elseif ($audioStatus !== 'hentet') {
            $body = '<p class="neutral">' . ($audioStatus === 'fejlet' ? 'Der kan ikke laves et transcript uden lyd.' : 'Transcriptet laves, når lyden er hentet.') . '</p>';
        } elseif ($tStatus === 'faerdig' && $transcript !== null) {
            [$body, $found] = self::conversation($transcript, Ui::terms($f['q']), $playable);
            if ($body === '') {
                $body = Ui::msg('info', '<p>Der blev ikke fundet tale i optagelsen.</p>');
            } else {
                $actions = '<div class="actions head-actions">' . Ui::copyButton('transcript', 'Kopiér tekst')
                    . '<a class="button" href="/opkald/' . $id . '/transcript">' . Ui::icon('download') . 'Download .txt</a></div>';
                $find = '<div class="find" data-js-only hidden><label for="find-q" class="sr-only">Find i samtalen</label>' . Ui::icon('search')
                    . '<input type="search" id="find-q" placeholder="Find i samtalen" autocomplete="off" aria-describedby="find-status">'
                    . '<span class="find-status" id="find-status" aria-live="polite"></span></div>';
                if (Ui::terms($f['q']) !== []) {
                    $hits = $found > 0
                        ? '<p class="hits">' . $found . ' fund for «' . $e($f['q']) . '» · <a href="#fund-1">Gå til første fund</a></p>'
                        : '<p class="hits">Ingen fund for «' . $e($f['q']) . '» i dette transcript.</p>';
                }
            }
        } elseif ($tStatus === 'faerdig') {
            $body = Ui::msg('info', '<p>' . $e(UiCalls::TRANSCRIPT_MISSING) . '</p>');
        } elseif ($tStatus === 'fejlet') {
            $body = Ui::msg('error', '<p>Transcriptet kunne ikke laves.</p>'
                . ((string) ($call['transcript_error'] ?? '') !== '' ? '<details><summary>Teknisk besked</summary><p>'
                    . $e((string) $call['transcript_error']) . '</p></details>' : '')
                . ($admin ? '<p>En administrator kan sende opkaldet tilbage i køen.</p>' : ''));
        } elseif ($tStatus === 'transcriberer') {
            $body = Ui::msg('info', '<p>Transcriptet laves lige nu. Genindlæs siden om et par minutter.</p>');
        } else {
            $body = Ui::msg('info', '<p>Transcriptet er på vej. Opkald transcriberes ét ad gangen. Genindlæs siden om lidt.</p>');
        }

        $claude = '';
        if (Server::enabled()) {
            $claude = '<aside class="claude-box" aria-labelledby="claude-boks-h"><h2 id="claude-boks-h">' . Ui::icon('message-square')
                . 'Spørg Claude om dette opkald</h2><p>Brug Whisper i Claude, og skriv fx: <q>Opsummér opkald <strong>' . $id
                . '</strong> og find aftaler.</q></p><p><a href="/konto#' . (OAuth::enabled() ? 'claude-h' : 'mcp-overskrift') . '">Sådan forbinder du Claude</a></p></aside>';
        }

        $html = '<div class="detail-v2">' . $back
            . Ui::message(null, $flash) . ($info !== null && $info !== '' ? Ui::msg('info', '<p>' . $e($info) . '</p>', 'div', 'status') : '')
            . '<div class="d-head"><div class="d-title"><h1>' . $e($h1) . '</h1><div class="d-chips">' . $chips . '</div></div>' . $actions . '</div>'
            . '<div class="d-grid">'
            . '<div class="d-player">' . $audio . '</div>'
            . '<section class="d-conv" aria-labelledby="transcript-h"><div class="conv-head"><h2 id="transcript-h">Samtale</h2>' . $find . '</div>'
            . $hits . $body . '</section>'
            . '<div class="d-side"><div class="meta-card">' . $meta . '</div>'
            . '<details class="meta-details"><summary>Detaljer</summary>' . $meta . '</details>' . $claude . '</div>'
            . '</div></div>';
        return Ui::layout($title, $html, $user, $csrf, 'opkald');
    }

    /** @param array<string, mixed> $call */
    private static function meta(array $call, bool $adv, bool $success, ?string $subdomain, string $badge): string
    {
        $e = Ui::e(...);
        $phone = (string) $call['customer_phone'];
        $row = static fn(string $dt, string $dd): string => '<div><dt>' . $dt . '</dt><dd>' . $dd . '</dd></div>';
        $html = $row('Dato og tid', $e(Calls::local($call['started_at']))) . $row('Kilde', $adv ? 'Adversus' : 'Zendesk')
            . $row('Retning', $e(Calls::directionName((string) $call['direction'])));
        if ($adv) {
            $lead = (int) ($call['lead_id'] ?? 0);
            $campaign = (int) ($call['campaign_id'] ?? 0);
            $html .= ($phone !== '' ? $row('Kunde', $e($phone)) : '') . $row('Agent', $e((string) $call['agent_name']))
                . $row('Varighed', $e(Calls::duration((int) $call['duration_sec'])))
                . ($campaign > 0 ? $row('Kampagne', '#' . $campaign) : '')
                . ($lead > 0 ? $row('Lead', '#' . $lead . ($success ? ' · Succes-lead' : '')) : '');
        } else {
            $html .= $row('Kunde', $e($phone)) . $row('Agent', $e((string) $call['agent_name']))
                . $row('Varighed', $e(Calls::duration((int) $call['duration_sec'])))
                . $row('Ticket', Ui::ticket((int) ($call['ticket_id'] ?? 0), $subdomain));
        }
        return '<dl class="meta">' . $html . $row('Status', $badge) . '</dl>';
    }

    /** Det mørke afspillerkort (§2.9): native <audio> uden JS; app-v2.js skjuler controls og viser de egne kontroller. */
    private static function player(int $id, int $duration): string
    {
        $rates = '';
        foreach ([['0.75', '0,75×'], ['1', '1×'], ['1.25', '1,25×'], ['1.5', '1,5×'], ['2', '2×']] as [$rate, $label]) {
            $rates .= '<button type="button" class="rate" data-rate="' . $rate . '" aria-pressed="' . ($rate === '1' ? 'true' : 'false') . '">' . $label . '</button>';
        }
        $total = Calls::duration($duration);
        return '<section class="player-v2" aria-label="Optagelse">'
            . '<audio id="afspiller" controls preload="metadata" src="/opkald/' . $id . '/lyd"></audio>'
            . '<div class="pl-controls" data-js-only hidden>'
            . '<div class="pl-row"><button type="button" class="pl-play" aria-label="Afspil">' . Ui::icon('play', 18)
            . str_replace('<svg class="icon icon-18"', '<svg class="icon icon-18 icon-pause"', Ui::icon('pause', 18)) . '</button>'
            . '<input type="range" class="pl-seek" min="0" max="' . max(1, $duration) . '" step="1" value="0" aria-label="Spol" aria-valuetext="0:00 af ' . $total . '">'
            . '</div><div class="pl-times"><span class="pl-cur">0:00</span><span class="pl-total">' . ($duration > 0 ? $total : '–:––') . '</span></div>'
            . '<div class="pl-rates" role="group" aria-label="Afspilningshastighed">' . $rates . '</div>'
            . '<p class="pl-error" hidden>Lyden kunne ikke afspilles.</p></div>'
            . '<p class="pl-download"><a href="/opkald/' . $id . '/lyd" download>' . Ui::icon('download') . 'Download lyd</a></p></section>';
    }

    /**
     * Samtalen (§2.8): chatbobler, når mindst ét segment har en taler (H011), ellers én kolonne; uden segmenter afsnit.
     * '' = ingen tale.
     *
     * @param array{text: string, segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>} $t
     * @param list<string> $terms
     * @return array{0: string, 1: int} HTML og antal fund
     */
    private static function conversation(array $t, array $terms, bool $playable): array
    {
        $found = 0;
        $chat = false;
        foreach ($t['segments'] as $s) {
            if (Diarization::label($s) !== null) {
                $chat = true;
                break;
            }
        }
        $items = '';
        $ids = [];
        $prev = null;
        $side = 'l';
        foreach ($t['segments'] as $s) {
            $text = trim($s['text']);
            if ($text === '') {
                continue;
            }
            $sec = (int) floor($s['start']);
            $stamp = Calls::stamp($s['start']);
            $idAttr = isset($ids[$sec]) ? '' : ' id="t-' . $sec . '"';
            $ids[$sec] = true;
            $start = sprintf('%.2f', $s['start']);
            $ts = $playable
                ? '<button type="button" class="ts" data-start="' . $start . '" disabled aria-label="Afspil fra ' . $stamp . '">' . $stamp . '</button>'
                : '<span class="ts">' . $stamp . '</span>';
            $words = Ui::highlight($text, $terms, $found, true);
            if (!$chat) {
                $items .= '<li' . $idAttr . ' data-start="' . $start . '">' . $ts . '<p class="say">' . $words . '</p></li>';
                continue;
            }
            $label = Diarization::label($s);
            if ($label !== null) {
                $role = $s['role'] ?? null;
                $kind = $role === 'agent' ? 'agent' : ($role === 'kunde' ? 'kunde' : 'n');
                $side = $role === 'agent' ? 'l' : ($role === 'kunde' ? 'r' : ((int) ($s['speaker'] ?? 0) % 2 === 0 ? 'l' : 'r'));
            } else {
                $kind = 'none';   // et segment uden taler står på samme side som det forrige, uden etiket
            }
            $change = $label !== null && $label !== $prev;
            $who = $label !== null ? '<span class="who' . ($change ? '' : ' sr-only') . '">' . Ui::e($label) . '</span>' : '';
            $items .= '<li' . $idAttr . ' class="b b-' . $kind . ' b-' . $side . ($change || $prev === null ? '' : ' b-cont') . '" data-start="' . $start . '"'
                . ($label !== null ? ' data-who="' . Ui::e($label) . '"' : '') . '><div class="b-head">' . $who . $ts . '</div>'
                . '<p class="say">' . $words . '</p></li>';
            if ($label !== null) {
                $prev = $label;
            }
        }
        if ($items !== '') {
            return ['<ol class="transcript' . ($chat ? ' chat' : '') . ($playable ? ' playable' : '') . '" id="transcript">' . $items . '</ol>', $found];
        }
        if (trim($t['text']) === '') {
            return ['', 0];
        }
        $paras = '';
        foreach (preg_split('/\R{2,}/u', trim($t['text'])) ?: [trim($t['text'])] as $p) {
            $paras .= '<p class="say">' . Ui::highlight($p, $terms, $found, true) . '</p>';
        }
        return ['<div class="transcript-plain" id="transcript">' . $paras . '</div>', $found];
    }
}
