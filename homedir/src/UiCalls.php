<?php
declare(strict_types=1);

namespace Whisper;

use DateTimeImmutable;
use DateTimeZone;
use Whisper\Adversus\Feature;
use Whisper\Adversus\Sync as AdversusSync;
use Whisper\Zendesk\Sync;

/**
 * Opkaldslisten og detaljesiden i det nye UI (F005, UX §3.3–3.4). Listens filtre og side bæres i URL'en til
 * detaljen og tilbage (D2). Al dynamisk tekst går gennem Ui::e() eller Ui::highlight() (tokenisér før escape).
 */
final class UiCalls
{
    /** Opkald, der er hentet for under så mange sekunder siden, giver grøn statusprik. */
    private const FRESH = 30 * 60;
    public const TRANSCRIPT_MISSING = 'Transcriptet findes ikke i databasen. En administrator kan sende opkaldet tilbage i køen.';

    /**
     * @param array<string, mixed> $user
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @param list<array<string, mixed>> $rows
     * @param list<string> $agents
     * @param array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int} $status
     * @param 'ok'|'missing'|'invalid' $config  Zendesk-opsætningen
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool}> $snippets  fra Calls::snippets
     * @param array{config: string, status: array{last_ok_at: ?string, last_error: ?string}|null, warning?: ?string}|null $adversus
     *        F004: Adversus-opsætning ('ok'|'missing'|'invalid') og -status; null = flaget slukket (siden er som før)
     */
    public static function list(array $user, string $csrf, ?string $flash, array $f, array $rows, int $total, array $agents,
        array $status, string $config, bool $engineReady, ?string $subdomain, array $snippets, int $now, ?array $adversus = null): string
    {
        $e = Ui::e(...);
        $admin = ($user['role'] ?? '') === 'admin';
        $filtered = $f['fra'] !== '' || $f['til'] !== '' || $f['retning'] !== '' || $f['agent'] !== '' || $f['q'] !== '';
        $q = CallView::query($f);
        $all = ($f['vis'] ?? '') === 'alle';

        // Systembeskeder efter rolle (UX §3.3): opsætning og tekniske detaljer kun til administratorer
        $notes = '';
        $userSetup = 'Opkald hentes ikke fra Zendesk endnu. Kontakt en administrator, hvis du savner opkald.';
        if ($config === 'missing') {
            $notes .= Ui::msg('info', '<p>' . ($admin ? 'Zendesk er ikke sat op. Opkald hentes, når <code>[zendesk]</code> er udfyldt i config.ini.'
                : $e($userSetup)) . '</p>');
        } elseif ($config === 'invalid') {
            $notes .= $admin ? Ui::msg('error', '<p>Zendesk-opsætningen i config.ini er ugyldig.</p>') : Ui::msg('info', '<p>' . $e($userSetup) . '</p>');
        }
        if ($adversus !== null && $admin) {
            // F004: kun administratorer ser Adversus' opsætning og tekniske fejl (som for Zendesk, Team Lead 5)
            if ($adversus['config'] === 'missing') {
                $notes .= Ui::msg('info', '<p>Adversus er ikke sat op. Opkald hentes, når <code>[adversus]</code> er udfyldt i config.ini.</p>');
            } elseif ($adversus['config'] === 'invalid') {
                $notes .= Ui::msg('error', '<p>Adversus-opsætningen i config.ini er ugyldig.</p>');
            }
            $aErr = $adversus['status']['last_error'] ?? null;
            if ($aErr !== null && !str_starts_with($aErr, AdversusSync::RATE_LIMIT_PREFIX)) {
                $notes .= Ui::msg('error', '<p>Seneste hentning fra Adversus stoppede.</p><details><summary>Teknisk besked</summary><p>'
                    . $e($aErr) . '</p></details>');
            }
            // Forkastede optagelser og ukendt retning (QA F-1): synligt et døgn, ikke kun i loggen
            $warning = $adversus['warning'] ?? null;
            if ($warning !== null) {
                $notes .= Ui::msg('warn', '<p>Nogle Adversus-opkald kunne ikke hentes.</p><details><summary>Teknisk besked</summary><p>'
                    . $e($warning) . '</p></details>');
            }
        }
        if (!$engineReady) {
            $notes .= Ui::msg('info', '<p>' . ($admin ? 'Transcription er ikke sat op endnu (kør cron/whisper-setup.sh).'
                : 'Nye opkald bliver ikke transcriberet lige nu. Lyden kan stadig afspilles.') . '</p>');
        }
        $err = $status['last_error'];
        $rateLimited = $err !== null && str_starts_with($err, Sync::RATE_LIMIT_PREFIX);
        if ($err !== null && !$rateLimited && $admin) {
            $notes .= Ui::msg('error', '<p>Seneste hentning fra Zendesk stoppede.</p><details><summary>Teknisk besked</summary><p>'
                . $e($err) . '</p></details>');
        }
        if ($f['fra'] !== '' && $f['til'] !== '' && $f['til'] < $f['fra']) {
            $notes .= Ui::msg('info', '<p>Til-datoen er før fra-datoen.</p>');
        }

        $aLine = $adversus !== null && $adversus['config'] === 'ok' ? ($adversus['status'] ?? ['last_ok_at' => null, 'last_error' => null]) : null;
        $head = '<div class="page-head"><h1>Opkald</h1>' . self::statusLine($status, $rateLimited, $now, $aLine) . '</div>';
        $form = self::filters($f, $agents, $filtered, $now);
        $chips = ($adversus !== null ? self::viewSwitch($f) : '') . self::chips($f);

        $first = ($f['side'] - 1) * Calls::PAGE_SIZE + 1;
        $last = min($total, $f['side'] * Calls::PAGE_SIZE);
        $count = $total > 0 ? '<p class="count" id="antal">Viser ' . $first . '–' . $last . ' af ' . $total . ' opkald</p>' : '';
        $zip = '';
        if ($total > 0) {
            $zip = '<div class="zip"><a class="button secondary" href="/opkald/download' . ($q !== '' ? '?' . $e($q) : '') . '" '
                . 'aria-describedby="zip-hjaelp">' . Ui::icon('download') . 'Download færdige transcripts (.zip)</a>'
                . '<p class="hint" id="zip-hjaelp">' . ($total > Calls::ZIP_MAX ? 'Zip-filen får de ' . Calls::ZIP_MAX . ' nyeste færdige.'
                    : 'Alle færdige transcripts i filtret, højst ' . Calls::ZIP_MAX . '.') . '</p></div>';
        }
        $words = Calls::searchWords($f['q']);
        $tip = $words !== [] && mb_strlen($words[0]) < 3
            ? '<p class="hint">Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf.</p>' : '';

        if ($rows === [] && $adversus !== null) {
            // F004 (UX §5): tomtilstande efter visning; "Nulstil filtre" bevarer visningen
            $reset = '<a class="button secondary" href="' . ($all ? '/?vis=alle' : '/') . '">Nulstil filtre</a>';
            if ($filtered) {
                $table = self::empty('Ingen opkald matcher', 'Prøv en længere periode, færre søgeord eller en anden agent.',
                    $reset . ($all ? '' : ' <a class="button secondary" href="' . $e(self::listUrl(['vis' => 'alle'] + $f)) . '">Søg i alle opkald</a>'));
            } else {
                $table = $all
                    ? self::empty('Ingen opkald endnu', 'Opkald med optagelse fra Zendesk og Adversus dukker op her inden for 10 minutter.', '')
                    : self::empty('Ingen opkald endnu', 'Opkald fra Zendesk, Adversus-opkald på succes-leads og opkald, der er valgt til transcription, dukker op her inden for 10 minutter.',
                        '<a class="button secondary" href="/?vis=alle">Vis alle opkald</a>');
            }
        } elseif ($rows === []) {
            $table = $filtered
                ? self::empty('Ingen opkald matcher', 'Prøv en længere periode, færre søgeord eller en anden agent.',
                    '<a class="button secondary" href="/">Nulstil filtre</a>')
                : self::empty('Ingen opkald endnu', 'Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter.', '');
        } else {
            $table = self::table($rows, $f, $subdomain, $snippets, $adversus !== null);
        }

        return Ui::layout('Opkald', $head . Ui::message(null, $flash) . $notes . $form . $chips
            . ($total > 0 ? '<div class="results-bar">' . $count . $zip . '</div>' : '') . $tip . $table . self::pager($f, $total),
            $user, $csrf, 'opkald');
    }

    /**
     * @param array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int} $status
     * @param array{last_ok_at: ?string, last_error: ?string}|null $adversus  F004: en linje mere for Adversus (null = ingen)
     */
    private static function statusLine(array $status, bool $rateLimited, int $now, ?array $adversus = null): string
    {
        $lines = self::sourceLine('Zendesk', $status['last_ok_at'], $status['last_error'], $rateLimited, $now);
        if ($adversus !== null) {
            $aErr = $adversus['last_error'];
            $lines .= self::sourceLine('Adversus', $adversus['last_ok_at'], $aErr,
                $aErr !== null && str_starts_with($aErr, AdversusSync::RATE_LIMIT_PREFIX), $now);
        }
        $line2 = [];
        if ($status['waiting'] > 0) {
            $line2[] = $status['waiting'] . ' venter på transcription';
        }
        if ($status['audio_waiting'] > 0) {
            $line2[] = $status['audio_waiting'] . ' venter på lyd';
        }
        return '<div class="sync">' . $lines
            . ($line2 !== [] ? '<p class="sync-queue">' . implode(' · ', $line2) . '</p>' : '') . '</div>';
    }

    /** Én kildes linje med prik (samme regler for Zendesk og Adversus). $name er fast tekst. */
    private static function sourceLine(string $name, ?string $ok, ?string $err, bool $rateLimited, int $now): string
    {
        $okTs = $ok !== null ? (int) strtotime($ok . ' UTC') : 0;
        if ($rateLimited) {
            [$dot, $text] = ['warn', $name . ' bad os vente. Hentningen fortsætter ved næste kørsel.'];
        } elseif ($err !== null) {
            [$dot, $text] = ['error', 'Hentningen fra ' . $name . ' er stoppet'];
        } elseif ($ok === null) {
            [$dot, $text] = ['warn', 'Endnu ikke hentet fra ' . $name];
        } elseif ($now - $okTs < self::FRESH) {
            [$dot, $text] = ['ok', 'Hentet fra ' . $name . ' ' . Ui::time($ok, Ui::ago($okTs, $now))];
        } else {
            [$dot, $text] = ['warn', 'Sidst hentet fra ' . $name . ' ' . Ui::time($ok, Ui::ago($okTs, $now))];
        }
        // $text er enten fast tekst eller bygget af Ui::time (escaped)
        return '<p class="sync-line"><span class="dot dot-' . $dot . '" aria-hidden="true"></span>' . $text . '</p>';
    }

    /**
     * Segmentvælgeren "Vis: Succes og valgte · Alle opkald" (QA N-4) (F004, UX §3.1, D-F4-2): links, der bevarer filtrene og nulstiller
     * siden. Det aktive valg har aria-current og fed tekst (ikke kun farve).
     *
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     */
    private static function viewSwitch(array $f): string
    {
        $all = ($f['vis'] ?? '') === 'alle';
        $link = static fn(string $vis, string $label, bool $active): string => '<a href="' . Ui::e(self::listUrl(['vis' => $vis] + $f)) . '"'
            . ($active ? ' aria-current="true"' : '') . '>' . $label . '</a>';
        return '<div class="view-switch" role="group" aria-labelledby="vis-label"><span id="vis-label" class="muted">Vis:</span>'
            . '<div class="segmented">' . $link('', 'Succes og valgte', !$all) . $link('alle', 'Alle opkald', $all) . '</div></div>';
    }

    /**
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @param list<string> $agents
     */
    private static function filters(array $f, array $agents, bool $filtered, int $now): string
    {
        $e = Ui::e(...);
        $opt = static fn(string $value, string $label, string $current): string
            => '<option value="' . $e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . $e($label) . '</option>';
        $agentOpts = $opt('', 'Alle agenter', $f['agent']);
        foreach ($agents as $a) {
            $agentOpts .= $opt($a, $a, $f['agent']);
        }
        // Hurtigvalg i dansk tid; de bevarer de andre filtre
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(Calls::TZ));
        $quick = [
            'I dag'           => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            'Seneste 7 dage'  => [$today->modify('-6 day')->format('Y-m-d'), $today->format('Y-m-d')],
            'Denne måned'     => [$today->format('Y-m-01'), $today->format('Y-m-d')],
        ];
        $links = [];
        foreach ($quick as $label => [$fra, $til]) {
            $active = $f['fra'] === $fra && $f['til'] === $til;
            $links[] = '<a href="' . $e(self::listUrl(['fra' => $fra, 'til' => $til] + $f)) . '"' . ($active ? ' aria-current="true"' : '')
                . '>' . $e($label) . '</a>';
        }
        return '<form method="get" action="/" class="filters card" role="search" aria-label="Søg og filtrér opkald">'
            . (($f['vis'] ?? '') === 'alle' ? '<input type="hidden" name="vis" value="alle">' : '')
            . '<div class="search-row"><label class="search" for="q">Søg i transcripts</label>'
            . '<div class="search-field"><input type="search" id="q" name="q" value="' . $e($f['q']) . '" maxlength="200" '
            . 'placeholder="fx faktura flytning" aria-describedby="q-hjaelp"><button type="submit">Søg</button></div>'
            . '<p class="hint" id="q-hjaelp">Alle ord skal indgå. Ord på mindst 3 bogstaver giver det bedste resultat.</p></div>'
            . '<div class="filter-grid">'
            . '<label>Fra<input type="date" name="fra" value="' . $e($f['fra']) . '"></label>'
            . '<label>Til<input type="date" name="til" value="' . $e($f['til']) . '"></label>'
            . '<label>Retning<select name="retning">' . $opt('', 'Begge', $f['retning']) . $opt('inbound', 'Indgående', $f['retning'])
            . $opt('outbound', 'Udgående', $f['retning']) . '</select></label>'
            . '<label>Agent<select name="agent">' . $agentOpts . '</select></label></div>'
            . '<div class="filter-foot"><p class="quick"><span class="muted">Hurtigt:</span> ' . implode(' <span aria-hidden="true">·</span> ', $links) . '</p>'
            . ($filtered ? '<a class="reset" href="' . (($f['vis'] ?? '') === 'alle' ? '/?vis=alle' : '/') . '">Nulstil filtre</a>' : '') . '</div></form>';
    }

    /** @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f */
    private static function chips(array $f): string
    {
        $e = Ui::e(...);
        $d = static fn(string $ymd): string => substr($ymd, 8, 2) . '.' . substr($ymd, 5, 2) . '.' . substr($ymd, 0, 4);
        $labels = [
            'q'       => $f['q'] !== '' ? 'Søgning: ' . $f['q'] : '',
            'fra'     => $f['fra'] !== '' ? 'Fra ' . $d($f['fra']) : '',
            'til'     => $f['til'] !== '' ? 'Til ' . $d($f['til']) : '',
            'retning' => $f['retning'] !== '' ? 'Retning: ' . Calls::directionName($f['retning']) : '',
            'agent'   => $f['agent'] !== '' ? 'Agent: ' . $f['agent'] : '',
        ];
        $items = '';
        foreach ($labels as $key => $label) {
            if ($label === '') {
                continue;
            }
            $without = $f;
            $without[$key] = '';
            $items .= '<li><a class="chip" href="' . $e(self::listUrl($without)) . '"><span aria-hidden="true">' . $e($label) . ' ×</span>'
                . '<span class="sr-only">Fjern filter: ' . $e(str_replace(':', '', $label)) . '</span></a></li>';
        }
        return $items === '' ? '' : '<div class="chips"><span class="muted" id="chips-label">Aktive filtre:</span>'
            . '<ul aria-labelledby="chips-label">' . $items . '</ul></div>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool}> $snippets
     * @param bool $adv  F004: kolonnen Kilde (nr. 2) og Succes-lead-mærket under "Alle opkald" (Team Lead 1–2)
     */
    private static function table(array $rows, array $f, ?string $subdomain, array $snippets, bool $adv = false): string
    {
        $all = ($f['vis'] ?? '') === 'alle';
        $e = Ui::e(...);
        $ctx = CallView::query($f, $f['side']);
        $terms = Ui::terms($f['q']);
        $trs = '';
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out = (string) $r['direction'] === 'outbound';
            $full = Calls::directionName((string) $r['direction']);
            $trs .= '<tr>'
                . '<td data-label="Dato og tid"><a class="row-link" href="/opkald/' . $id . ($ctx !== '' ? '?' . $e($ctx) : '') . '">'
                . $e(Calls::local($r['started_at'], 'd.m.Y')) . '<span class="time">' . $e(Calls::local($r['started_at'], 'H:i')) . '</span></a></td>'
                . ($adv ? '<td data-label="Kilde"><span class="tags">' . Ui::sourceTag((string) $r['source'])
                    . ($all && Calls::isAdversus($r) && (int) ($r['is_success'] ?? 0) === 1 ? Ui::successTag() : '') . '</span></td>' : '')
                . '<td data-label="Retning"><span class="dir" title="' . $e($full) . '"><span aria-hidden="true">'
                . ($out ? '↑ Ud' : '↓ Ind') . '</span><span class="sr-only">' . $e($full) . '</span></span></td>'
                . '<td data-label="Kunde" class="ellipsis" title="' . $e((string) $r['customer_phone']) . '">'
                . ($adv && (string) $r['customer_phone'] === '' ? '—' : $e((string) $r['customer_phone'])) . '</td>'
                . '<td data-label="Agent" class="ellipsis" title="' . $e((string) $r['agent_name']) . '">' . $e((string) $r['agent_name']) . '</td>'
                . '<td data-label="Varighed" class="num">' . $e(Calls::duration((int) $r['duration_sec'])) . '</td>'
                . '<td data-label="Ticket" class="num">' . Ui::ticket((int) ($r['ticket_id'] ?? 0), $subdomain) . '</td>'
                . '<td data-label="Status">' . Ui::badge($r) . '</td></tr>';
            if (isset($snippets[$id])) {
                $s = Ui::snippet($snippets[$id]['text'], $snippets[$id]['cut_start'], $snippets[$id]['cut_end'], $terms);
                if ($s !== '') {
                    $trs .= '<tr class="snippet-row"><td colspan="' . ($adv ? 8 : 7) . '" class="snippet"><span class="sr-only">Uddrag: </span><span class="snippet-text">' . $s . '</span></td></tr>';
                }
            }
        }
        $caption = $adv ? ($all ? 'Opkald – alle' : 'Opkald – succes og valgte') : 'Opkald';
        return '<div class="table-wrap"><table class="calls"><caption class="sr-only">' . $caption . '</caption><thead><tr><th scope="col">Dato og tid</th>'
            . ($adv ? '<th scope="col">Kilde</th>' : '')
            . '<th scope="col">Retning</th><th scope="col">Kunde</th><th scope="col">Agent</th><th scope="col" class="num">Varighed</th>'
            . '<th scope="col" class="num">Ticket</th><th scope="col">Status</th></tr></thead><tbody>' . $trs . '</tbody></table></div>';
    }

    /** @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f */
    private static function pager(array $f, int $total): string
    {
        $pages = max(1, (int) ceil($total / Calls::PAGE_SIZE));
        if ($pages <= 1) {
            return '';
        }
        $side = min($f['side'], $pages);
        $btn = static function (int $p, bool $enabled, string $label, string $icon, bool $iconFirst) use ($f): string {
            $inner = $iconFirst ? Ui::icon($icon) . $label : $label . Ui::icon($icon);
            return $enabled ? '<a class="button secondary small" href="' . Ui::e(self::listUrl($f, $p)) . '">' . $inner . '</a>'
                : '<span class="button secondary small" aria-disabled="true">' . $inner . '</span>';
        };
        return '<nav class="pager" aria-label="Sider">' . $btn($side - 1, $side > 1, 'Forrige', 'chevron-left', true)
            . '<span class="page-info">Side ' . $side . ' af ' . $pages . '</span>'
            . $btn($side + 1, $side < $pages, 'Næste', 'chevron-right', false) . '</nav>';
    }

    /**
     * Listen med de givne filtre og side: "/" uden filtre.
     *
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f
     */
    private static function listUrl(array $f, int $side = 1): string
    {
        $q = CallView::query($f, $side);
        return $q === '' ? '/' : '/?' . $q;
    }

    private static function empty(string $title, string $text, string $action): string
    {
        return '<div class="empty"><div class="empty-icon">' . Ui::icon('inbox', 24) . '</div><h2 class="empty-title">' . Ui::e($title)
            . '</h2><p class="empty-text">' . Ui::e($text) . '</p>' . $action . '</div>';
    }

    /**
     * Detaljesiden (UX §3.4): afspiller og metadata til venstre (sticky), transcript til højre.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $call
     * @param array{text: string, segments: list<array{start: float, end: float, text: string}>}|null $transcript
     * @param array{fra: string, til: string, retning: string, agent: string, q: string, side: int, vis?: string} $f  listens kontekst
     * @param ?string $flash  ok-besked efter en handling (F004: "sat i kø")
     * @param ?string $info   info-besked efter en handling, der ikke ændrede noget (F004: idempotent)
     */
    public static function show(array $user, string $csrf, array $call, ?array $transcript, bool $audioOnDisk, ?string $subdomain, array $f,
        ?string $flash = null, ?string $info = null): string
    {
        $e = Ui::e(...);
        $admin = ($user['role'] ?? '') === 'admin';
        $id = (int) $call['id'];
        $ticket = (int) ($call['ticket_id'] ?? 0);
        // F004: Adversus-opkald har lead, kampagne og "ikke valgt"; Zendesk-opkald får kun rækken Kilde, når flaget er tændt
        $adv = Calls::isAdversus($call);
        $featureOn = Feature::on();
        $success = $adv && (int) ($call['is_success'] ?? 0) === 1;
        $phone = (string) $call['customer_phone'];
        $ctx = CallView::query($f, $f['side']);
        // Med F004 er "Alle opkald" navnet på en visning, så linket til "/" hedder "Tilbage til opkald" (UX F004 Finding 1)
        $back = $ctx !== '' ? '<a href="/?' . $e($ctx) . '">' . Ui::icon('chevron-left') . 'Tilbage til opkald</a>'
            : '<a href="/">' . Ui::icon('chevron-left') . ($featureOn ? 'Tilbage til opkald' : 'Alle opkald') . '</a>';

        $title = ((string) $call['direction'] === 'outbound' ? 'Udgående' : 'Indgående') . ' opkald · '
            . Calls::local($call['started_at'], 'd.m.Y') . ' kl. ' . Calls::local($call['started_at'], 'H:i');
        $sub = [$e($phone), $e((string) $call['agent_name']), $e(Calls::duration((int) $call['duration_sec']))];
        if ($adv) {
            if ($phone === '') {
                array_shift($sub);
            }
            $sub[] = 'Adversus';
        } elseif ($ticket > 0) {
            $sub[] = 'Ticket ' . Ui::ticket($ticket, $subdomain);
        }
        $audioStatus = (string) $call['audio_status'];
        $playable = $audioStatus === 'hentet' && $audioOnDisk;
        $badge = Ui::badge($call, $audioStatus === 'hentet' ? $audioOnDisk : null);

        if ($adv) {
            $lead = (int) ($call['lead_id'] ?? 0);
            $campaign = (int) ($call['campaign_id'] ?? 0);
            // Rækker uden værdi udelades på detaljen (UX §3.4)
            $meta = '<dl class="meta">'
                . '<div><dt>Dato og tid</dt><dd>' . $e(Calls::local($call['started_at'])) . '</dd></div>'
                . '<div><dt>Kilde</dt><dd>Adversus</dd></div>'
                . '<div><dt>Retning</dt><dd>' . $e(Calls::directionName((string) $call['direction'])) . '</dd></div>'
                . ($phone !== '' ? '<div><dt>Kunde</dt><dd>' . $e($phone) . '</dd></div>' : '')
                . '<div><dt>Agent</dt><dd>' . $e((string) $call['agent_name']) . '</dd></div>'
                . '<div><dt>Varighed</dt><dd>' . $e(Calls::duration((int) $call['duration_sec'])) . '</dd></div>'
                . ($campaign > 0 ? '<div><dt>Kampagne</dt><dd>#' . $campaign . '</dd></div>' : '')
                . ($lead > 0 ? '<div><dt>Lead</dt><dd>#' . $lead . ($success ? ' · Succes-lead' : '') . '</dd></div>' : '')
                . '<div><dt>Status</dt><dd>' . $badge . '</dd></div></dl>';
        } else {
            $meta = '<dl class="meta">'
                . '<div><dt>Dato og tid</dt><dd>' . $e(Calls::local($call['started_at'])) . '</dd></div>'
                . ($featureOn ? '<div><dt>Kilde</dt><dd>Zendesk</dd></div>' : '')
                . '<div><dt>Retning</dt><dd>' . $e(Calls::directionName((string) $call['direction'])) . '</dd></div>'
                . '<div><dt>Kunde</dt><dd>' . $e((string) $call['customer_phone']) . '</dd></div>'
                . '<div><dt>Agent</dt><dd>' . $e((string) $call['agent_name']) . '</dd></div>'
                . '<div><dt>Varighed</dt><dd>' . $e(Calls::duration((int) $call['duration_sec'])) . '</dd></div>'
                . '<div><dt>Ticket</dt><dd>' . Ui::ticket($ticket, $subdomain) . '</dd></div>'
                . '<div><dt>Status</dt><dd>' . $badge . '</dd></div></dl>';
        }
        $tStatus = (string) $call['transcript_status'];
        $notChosen = $adv && $tStatus === 'ikke_valgt';
        $sourceName = $adv ? 'Adversus' : 'Zendesk';

        // Venstre: lyd
        if ($notChosen) {
            $audio = Ui::msg('info', '<p>Lyden er ikke hentet. Opkaldet er ikke på et succes-lead.</p>');
        } elseif ($playable) {
            $audio = '<div class="player"><p class="player-label">' . Ui::icon('headphones') . 'Optagelse</p>'
                . '<audio id="afspiller" controls preload="metadata" src="/opkald/' . $id . '/lyd"></audio>'
                . '<div class="speed" role="group" aria-label="Afspilningshastighed" data-js-only hidden><span class="muted">Hastighed:</span>';
            // Par i stedet for nøgler: PHP gør nøglerne '1' og '2' til heltal
            foreach ([['1', '1×'], ['1.25', '1,25×'], ['1.5', '1,5×'], ['2', '2×']] as [$rate, $label]) {
                $audio .= '<button type="button" class="button ghost small" data-rate="' . $rate . '" aria-pressed="' . ($rate === '1' ? 'true' : 'false')
                    . '">' . $label . '</button>';
            }
            $audio .= '</div></div>';
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

        // Højre: transcript efter tilstandstabellen (UX §3.4)
        $actions = '';
        $hits = '';
        // F004 (UX §4): knappen står kun her; en almindelig POST-formular med CSRF, så den virker uden JS
        $ctxField = $ctx !== '' ? '<input type="hidden" name="ctx" value="' . $e($ctx) . '">' : '';
        $button = static fn(string $hint): string => '<form method="post" action="/opkald/' . $id . '/transcriber" class="actions select-call">'
            . View::csrfField($csrf) . $ctxField
            . '<button type="submit" class="button" aria-describedby="vaelg-hjaelp" data-busy="Sætter i kø …">Transcribér dette opkald</button></form>'
            . '<p class="hint" id="vaelg-hjaelp">' . $hint . '</p>';
        if ($notChosen) {
            $body = Ui::msg('info', '<p>Opkaldet er ikke på et succes-lead og bliver derfor ikke transcriberet automatisk.</p>'
                . $button('Lyden hentes fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter.'));
        } elseif ($adv && CallPages::selectable($call)) {
            // Lyden er fejlet: knappen henter den igen med nulstillede forsøg (QA F-2)
            $body = Ui::msg('info', '<p>Der kan ikke laves et transcript uden lyd.</p>'
                . $button('Lyden hentes igen fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter.'));
        } elseif ($audioStatus !== 'hentet') {
            $body = '<p class="neutral">' . ($audioStatus === 'fejlet' ? 'Der kan ikke laves et transcript uden lyd.'
                : 'Transcriptet laves, når lyden er hentet.') . '</p>';
        } elseif ($tStatus === 'faerdig' && $transcript !== null) {
            [$body, $found] = self::transcript($transcript, Ui::terms($f['q']), $playable);
            if ($body === '') {
                $body = Ui::msg('info', '<p>Der blev ikke fundet tale i optagelsen.</p>');
            } else {
                $actions = '<div class="actions">' . Ui::copyButton('transcript', 'Kopiér tekst')
                    . '<a class="button secondary" href="/opkald/' . $id . '/transcript">' . Ui::icon('download')
                    . '<span class="wide-only">Download transcript (.txt)</span><span class="narrow-only">Download .txt</span></a></div>';
                if (Ui::terms($f['q']) !== []) {
                    $hits = $found > 0
                        ? '<p class="hits">' . $found . ' fund for «' . $e($f['q']) . '» · <a href="#fund-1">Gå til første fund</a></p>'
                        : '<p class="hits">Ingen fund for «' . $e($f['q']) . '» i dette transcript.</p>';
                }
            }
        } elseif ($tStatus === 'faerdig') {
            // Status siger færdig, men rækken mangler: "på vej" ville være forkert (QA F-4)
            $body = Ui::msg('info', '<p>' . $e(self::TRANSCRIPT_MISSING) . '</p>');
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

        $html = '<div class="detail">'
            . '<p class="back">' . $back . '</p>'
            . Ui::message(null, $flash) . ($info !== null && $info !== '' ? Ui::msg('info', '<p>' . $e($info) . '</p>', 'div', 'status') : '')
            . '<div class="detail-head"><div><h1>' . $e($title) . '</h1><p class="sub">' . implode(' · ', $sub) . '</p></div>'
            . ($success ? '<div class="head-tags">' . Ui::successTag() . $badge . '</div>' : $badge) . '</div>'
            . '<div class="detail-grid">'
            . '<aside class="detail-side" aria-label="Optagelse og detaljer">' . $audio . '<div class="meta-side">' . $meta . '</div></aside>'
            . '<section class="detail-main" aria-labelledby="transcript-h"><div class="row"><h2 id="transcript-h">Transcript</h2>' . $actions . '</div>'
            . $hits . $body . '</section>'
            . '<details class="meta-details"><summary>Detaljer</summary>' . $meta . '</details>'
            . '</div></div>';
        return Ui::layout($title, $html, $user, $csrf, 'opkald');
    }

    /**
     * Segmenterne som <ol class="transcript">, eller den samlede tekst i afsnit uden segmenter. '' = ingen tale.
     *
     * @param array{text: string, segments: list<array{start: float, end: float, text: string}>} $t
     * @param list<string> $terms
     * @return array{0: string, 1: int} HTML og antal fund
     */
    private static function transcript(array $t, array $terms, bool $playable): array
    {
        $found = 0;
        $items = '';
        $ids = [];
        foreach ($t['segments'] as $s) {
            if (trim($s['text']) === '') {
                continue;
            }
            $sec = (int) floor($s['start']);
            $stamp = Calls::stamp($s['start']);
            $idAttr = isset($ids[$sec]) ? '' : ' id="t-' . $sec . '"';
            $ids[$sec] = true;
            $ts = $playable
                ? '<button type="button" class="ts" data-start="' . sprintf('%.2f', $s['start']) . '" disabled aria-label="Afspil fra ' . $stamp . '">'
                    . $stamp . '</button>'
                : '<span class="ts">' . $stamp . '</span>';
            $items .= '<li' . $idAttr . '>' . $ts . '<p>' . Ui::highlight(trim($s['text']), $terms, $found, true) . '</p></li>';
        }
        if ($items !== '') {
            return ['<ol class="transcript" id="transcript">' . $items . '</ol>', $found];
        }
        if (trim($t['text']) === '') {
            return ['', 0];
        }
        $paras = '';
        foreach (preg_split('/\R{2,}/u', trim($t['text'])) ?: [trim($t['text'])] as $p) {
            $paras .= '<p>' . Ui::highlight($p, $terms, $found, true) . '</p>';
        }
        return ['<div class="transcript-plain" id="transcript">' . $paras . '</div>', $found];
    }
}
