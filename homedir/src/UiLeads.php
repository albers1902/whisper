<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Adversus pr. lead i det nye udseende (F008, ui-ux/F008_design_v1.md og graphic-design/F008_visual_v1.md): lead-kort på
 * Adversus-fanen, kortet "Opkald uden lead" og lead-siden med opkaldene som en tidslinje. Bygger på F007's komponenter
 * (UiCallsV2); al dynamisk tekst går gennem Ui::e(), Ui::highlight() eller Ui::snippet().
 *
 * @phpstan-import-type Filter from Leads
 * @phpstan-import-type Summary from Leads
 */
final class UiLeads
{
    /** Kendte Adversus-lead-statusser (design §2.3); ukendte vises rå (Team Lead). */
    public const STATUS_TEXT = ['notInterested' => 'Ikke interesseret', 'unqualified' => 'Ukvalificeret', 'automaticRedial' => 'Genopkald',
        'privateRedial' => 'Genopkald', 'sharedRedial' => 'Genopkald', 'invalid' => 'Ugyldig'];
    /** Ubesvarede opkald (design §2.5). */
    public const MISSED = ['busy' => 'optaget', 'no-answer' => 'intet svar', 'failed' => 'fejlet', 'congestion' => 'ikke igennem'];
    /** Aktivitetspillen (design §2.3): klasse og tekst som UiCallsV2::status. */
    private const ACTIVITY = ['transcriberer' => ['s-transcriberer', 'Transcriberer'], 'koe' => ['s-ventende', 'I kø'],
        'fejlet' => ['s-fejlet', 'Fejlet'], 'lyd' => ['s-lyd', 'Lyd mangler'], 'venter' => ['s-ventende', 'Venter på lyd']];
    private const MONTHS = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];

    /** Leadets status som tag: Succes-lead, en oversat kendt værdi, den rå værdi, eller intet uden status. */
    public static function statusTag(?string $status, bool $success, string $successStatus): string
    {
        if ($success || ($status !== null && $status === $successStatus)) {
            return Ui::successTag();
        }
        if ($status === null || $status === '') {
            return '';
        }
        return '<span class="tag">' . Ui::e(self::STATUS_TEXT[$status] ?? $status) . '</span>';
    }

    /** @param Summary $s  tekst til skærmlæserens hale og opsummeringen */
    public static function statusText(array $s): string
    {
        return $s['answered'] === 0 ? 'Ikke besvaret' : $s['done'] . ' af ' . $s['answered'] . ' transcriberet';
    }

    /** @param Summary $s  højst to piller: tællepille (eller "Ikke besvaret") og den højeste aktivitet */
    public static function pills(array $s): string
    {
        if ($s['answered'] === 0) {
            return '<span class="badge s-ikke-valgt">Ikke besvaret</span>';
        }
        $out = '<span class="badge ' . ($s['done'] === $s['answered'] ? 's-faerdig' : 's-ikke-valgt') . '">' . UiV2::num($s['done']) . ' af '
            . UiV2::num($s['answered']) . ' transcriberet</span>';
        if ($s['activity'] !== null && isset(self::ACTIVITY[$s['activity']])) {
            [$cls, $text] = self::ACTIVITY[$s['activity']];
            $out .= '<span class="badge ' . $cls . '">' . $text . '</span>';
        }
        return $out;
    }

    /**
     * "Sara", "Sara og Mads", "Sara, Mads og Jonas", "Sara, Mads og 3 mere". Tomme navne er "Ukendt agent".
     *
     * @param list<string> $agents
     */
    public static function agentList(array $agents): string
    {
        $names = array_map(static fn(string $a): string => $a !== '' ? $a : 'Ukendt agent', $agents);
        $n = count($names);
        if ($n <= 1) {
            return $names[0] ?? '';
        }
        if ($n <= 3) {
            return implode(', ', array_slice($names, 0, $n - 1)) . ' og ' . $names[$n - 1];
        }
        return $names[0] . ', ' . $names[1] . ' og ' . ($n - 2) . ' mere';
    }

    /** "3. nov. 2026 kl. 09:12" (dansk tid). */
    public static function dateTime(string $utc): string
    {
        $d = Calls::local($utc, 'Y-n-j H:i');
        if ($d === '') {
            return '';
        }
        [$date, $hm] = explode(' ', $d);
        [$y, $m, $day] = explode('-', $date);
        return $day . '. ' . self::MONTHS[(int) $m - 1] . ' ' . $y . ' kl. ' . $hm;
    }

    /**
     * Opsummering, "Uden lead", kortene (eller den tomme tilstand) og pager for Adversus-fanen (design §1.1). Erstatter
     * F007's opkaldskort i UiCallsV2::list.
     *
     * @param Filter $f
     * @param array<int, string> $leads  lead-id => seneste matchende opkald, i listens rækkefølge
     * @param array<int, Summary> $sums
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool, started_at: string}> $snippets  pr. lead (kun med q)
     */
    public static function listBody(array $f, array $leads, array $sums, int $total, int $orphans, array $snippets, int $now,
        bool $tabs, ?int $other, string $successStatus): string
    {
        $e = Ui::e(...);
        $orphanCard = '';
        if ($orphans > 0 && $f['side'] === 1) {
            $ctx = CallView::query($f);
            $orphanCard = '<li class="call-card lead-card lead-orphan"><div class="cc-main"><span class="avatar av-0 lead-orphan-icon" aria-hidden="true">'
                . Ui::icon('phone') . '</span><div class="cc-who"><a class="card-link" href="/lead/uden' . ($ctx !== '' ? '?' . $e($ctx) : '') . '">'
                . 'Opkald uden lead<span class="sr-only">, ' . UiCallsV2::countText($orphans) . ' opkald</span></a>'
                . '<p class="cc-meta">Opkald, som Adversus ikke har knyttet til et lead</p></div></div>'
                . '<div class="cc-side"><span class="lead-orphan-n">' . UiCallsV2::countText($orphans) . ' opkald</span></div></li>';
        }
        $orphanNote = $orphans > 0 ? ' · ' . UiCallsV2::countText($orphans) . ' opkald uden lead' : '';
        $first = ($f['side'] - 1) * Calls::PAGE_SIZE + 1;
        $last = min($total, $f['side'] * Calls::PAGE_SIZE);
        $summary = $total > 0
            ? '<p class="count" id="antal">Viser ' . UiV2::num($first) . '–' . UiV2::num($last) . ' af '
                . ($total > Calls::COUNT_CAP ? 'flere end ' . UiV2::num(Calls::COUNT_CAP) : UiV2::num($total)) . ' leads' . $orphanNote . '</p>'
            : ($orphans > 0 ? '<p class="count" id="antal">' . UiCallsV2::countText($orphans) . ' opkald uden lead</p>' : '');
        $zipHelp = $total > 0 || $orphans > 0 ? '<p class="sr-only" id="zip-hjaelp">Alle færdige transcripts i filtret, højst ' . Calls::ZIP_MAX . '.</p>' : '';
        $words = Calls::searchWords($f['q']);
        $tip = $words !== [] && mb_strlen($words[0]) < 3 ? '<p class="hint">Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf.</p>' : '';
        $capHint = $total > Calls::COUNT_CAP && $f['side'] >= (int) (Calls::COUNT_CAP / Calls::PAGE_SIZE)
            ? '<p class="hint cap-hint">Kun de nyeste ' . UiV2::num(Calls::COUNT_CAP) . ' leads kan bladres. Indsnævr med periode, agent eller søgning for at se ældre leads.</p>' : '';

        $ctx = CallView::query($f, $f['side']);
        $terms = Ui::terms($f['q']);
        $items = '';
        foreach ($leads as $lead => $lastMatch) {
            $s = $sums[$lead] ?? null;
            if ($s === null) {
                continue;
            }
            $items .= self::card($s, $ctx, $terms, $snippets[$lead] ?? null, $now, $successStatus);
        }
        $list = $items !== '' || $orphanCard !== '' ? '<ol class="call-list lead-list" aria-labelledby="antal">' . $orphanCard . $items . '</ol>' : '';
        if ($items === '') {
            $list .= self::emptyState($f, $tabs, $other);
        }
        return $summary . $zipHelp . $tip . $list . UiCallsV2::pager($f, $total) . $capHint;
    }

    /**
     * @param Summary $s
     * @param list<string> $terms
     * @param array{text: string, cut_start: bool, cut_end: bool, started_at: string}|null $snippet
     */
    private static function card(array $s, string $ctx, array $terms, ?array $snippet, int $now, string $successStatus): string
    {
        $e = Ui::e(...);
        $id = $s['lead_id'];
        $stack = '';
        foreach (array_slice($s['agents'], 0, 3) as $a) {
            $stack .= UiV2::avatar($a);
        }
        if ($stack === '') {
            $stack = UiV2::avatar('');
        }
        if (count($s['agents']) > 3) {
            $stack .= '<span class="avatar av-0 av-more" aria-hidden="true">+' . (count($s['agents']) - 3) . '</span>';
        }
        $success = $s['success'] || $s['status'] === $successStatus;
        $tags = self::statusTag($s['status'], $s['success'], $successStatus) . ($s['campaign'] > 0 ? '<span class="tag">Kampagne #' . $s['campaign'] . '</span>' : '');
        $last = UiV2::when($s['last_at'], $now);
        $times = $s['calls'] > 1 && $s['first_at'] !== $s['last_at']
            ? Ui::time($s['first_at'], UiV2::when($s['first_at'], $now)) . '<span aria-hidden="true"> – </span><span class="sr-only"> til </span>'
                . Ui::time($s['last_at'], $last)
            : Ui::time($s['last_at'], $last);
        $agents = self::agentList($s['agents']);
        $count = UiV2::num($s['calls']) . ' opkald, ' . UiV2::num($s['answered']) . ' besvarede';
        $tail = ($success ? ', Succes-lead' : '') . ', ' . UiV2::num($s['calls']) . ' opkald' . ($last !== '' ? ', seneste ' . $last : '')
            . ', ' . self::statusText($s);
        $excerpt = '';
        if ($terms !== [] && $snippet !== null) {
            $excerpt = '<p class="cc-excerpt"><span class="sr-only">Uddrag: </span><strong class="who-n">' . $e(UiV2::when($snippet['started_at'], $now))
                . ' — </strong>' . Ui::snippet($snippet['text'], $snippet['cut_start'], $snippet['cut_end'], $terms) . '</p>';
        }
        return '<li class="call-card lead-card"><div class="cc-main"><span class="av-stack" aria-hidden="true">' . $stack . '</span><div class="cc-who">'
            . '<a class="card-link" href="/lead/' . $id . ($ctx !== '' ? '?' . $e($ctx) : '') . '">Lead #' . $id . '<span class="sr-only">' . $e($tail) . '</span></a>'
            . '<p class="cc-meta">' . ($s['phone'] !== '' ? '<span class="cc-phone">' . $e($s['phone']) . '</span> · ' : '') . $times . '</p>'
            . ($agents !== '' ? '<p class="cc-meta lead-agents-line">' . $e($agents) . '</p>' : '') . '</div>'
            . ($tags !== '' ? '<span class="tags">' . $tags . '</span>' : '') . '</div>'
            . '<div class="cc-side"><span class="lead-stats"><span class="cc-dur">' . $e(Calls::duration($s['talk'])) . '<span class="sr-only"> taletid</span></span>'
            . '<span class="lead-count">' . $count . '</span></span><span class="lead-pills">' . self::pills($s) . '</span></div>'
            . $excerpt . '</li>';
    }

    /** @param Filter $f  design §3: tomme tilstande for leads */
    private static function emptyState(array $f, bool $tabs, ?int $other): string
    {
        $e = Ui::e(...);
        $all = ($f['vis'] ?? '') === 'alle';
        if (($f['status'] ?? '') === Calls::STATUS_QUEUE && !UiCallsV2::filtered(['status' => ''] + $f)) {
            return UiCallsV2::empty('Ingen leads i kø lige nu', 'Nye opkald transcriberes, så snart lyden er hentet.', '');
        }
        if (UiCallsV2::filtered($f)) {
            $actions = '<a class="button secondary" href="' . $e(UiCallsV2::resetUrl($f)) . '">Nulstil filtre</a>';
            if (!$all) {
                $actions .= '<a class="button secondary" href="' . $e(UiCallsV2::listUrl(['vis' => 'alle', 'side' => 1] + $f)) . '">Søg i alle leads</a>';
            }
            $hint = '';
            if ($tabs && $other !== null && $other > 0) {
                $otherF = ['kilde' => 'zendesk', 'agent' => '', 'vis' => '', 'side' => 1] + $f;
                $hint = '<p class="empty-text">Søgningen giver ' . UiCallsV2::countText($other) . ' træf under Zendesk. <a href="'
                    . $e(UiCallsV2::listUrl($otherF)) . '">Se dem</a></p>';
            }
            return UiCallsV2::empty('Ingen leads matcher', 'Prøv en længere periode, færre søgeord eller en anden agent.', $hint . '<div class="actions">' . $actions . '</div>');
        }
        return $all
            ? UiCallsV2::empty('Ingen Adversus-leads endnu', 'Leads med opkald fra Adversus dukker op her inden for 10 minutter.', '')
            : UiCallsV2::empty('Ingen Adversus-leads endnu', 'Leads med succes og leads med opkald, der er valgt til transcription, dukker op her inden for 10 minutter.',
                '<div class="actions"><a class="button secondary" href="/?kilde=adversus&amp;vis=alle">Vis alle leads</a></div>');
    }

    /** @param Filter $f  "Tilbage til opkald": altid Adversus-fanen med listens filtre */
    private static function back(array $f): string
    {
        return '<p class="back"><a href="' . Ui::e(UiCallsV2::listUrl(['kilde' => 'adversus'] + $f)) . '">' . Ui::icon('arrow-left')
            . 'Tilbage til opkald</a></p>';
    }

    /**
     * Siden /lead/uden (design §2.2): F007's opkaldskort for Adversus-opkald uden lead i fanens filter.
     *
     * @param array<string, mixed> $user
     * @param Filter $f
     * @param list<array<string, mixed>> $rows
     * @param array<int, array{text: string, cut_start: bool, cut_end: bool}> $snippets
     * @param array<int, array{segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>, text: string, cut: bool}> $excerpts
     */
    public static function orphans(array $user, string $csrf, array $f, array $rows, int $total, array $snippets, array $excerpts, int $now): string
    {
        $first = ($f['side'] - 1) * Calls::PAGE_SIZE + 1;
        $last = min($total, $f['side'] * Calls::PAGE_SIZE);
        $summary = $total > 0 ? '<p class="count" id="antal">Viser ' . UiV2::num($first) . '–' . UiV2::num($last) . ' af '
            . UiCallsV2::countText($total) . ' opkald</p>' : '';
        $list = $rows === [] ? UiCallsV2::empty('Ingen opkald uden lead', 'Alle Adversus-opkald i filtret er knyttet til et lead.', '')
            : UiCallsV2::cards($rows, $f, null, $snippets, $excerpts, $now);
        $pager = str_replace('href="/?', 'href="/lead/uden?', UiCallsV2::pager($f, $total));
        return Ui::layout('Opkald uden lead · Adversus', '<div class="list-page">' . self::back($f) . '<div class="page-head"><h1>Opkald uden lead</h1></div>'
            . $summary . $list . $pager . '</div>', $user, $csrf, 'opkald');
    }

    /**
     * Lead-siden (design §1.2): hoved med chips og downloads, "Find i alle opkald", forløbet som tidslinje (ældste først,
     * højst Leads::LEAD_PAGE pr. side) med én sektion pr. besvaret opkald og én linje pr. ubesvaret (3+ i træk samlet).
     *
     * @param array<string, mixed> $user
     * @param Summary|null $sum  null = leadet har ingen opkald endnu
     * @param list<array<string, mixed>> $calls  sidens opkald i tidsrækkefølge
     * @param array<int, array{text: string, segments: list<array{start: float, end: float, text: string, speaker?: int, role?: string}>}> $transcripts
     * @param array<int, bool> $onDisk
     * @param Filter $f  listens kontekst
     */
    public static function page(array $user, string $csrf, int $lead, ?string $status, ?array $sum, array $calls, int $side, int $total,
        array $transcripts, array $onDisk, array $f, ?string $flash, ?string $info, string $successStatus, int $now): string
    {
        $e = Ui::e(...);
        $admin = ($user['role'] ?? '') === 'admin';
        $ctx = CallView::query($f, $f['side']);
        $terms = Ui::terms($f['q']);
        $success = $sum !== null && $sum['success'];

        $chips = '<span class="chip-meta chip-source">Adversus</span>' . self::statusTag($status, $success, $successStatus);
        $agents = '';
        $actions = '';
        if ($sum !== null) {
            $range = self::day($sum['first_at']) . ($sum['calls'] > 1 && self::day($sum['first_at']) !== self::day($sum['last_at'])
                ? '<span aria-hidden="true"> – </span><span class="sr-only"> til </span>' . self::day($sum['last_at']) : '');
            $chips .= ($sum['campaign'] > 0 ? '<span class="tag">Kampagne #' . $sum['campaign'] . '</span>' : '')
                . ($sum['phone'] !== '' ? '<span class="chip-meta">' . $e($sum['phone']) . '</span>' : '')
                . '<span class="chip-meta">' . UiV2::num($sum['calls']) . ' opkald · ' . UiV2::num($sum['answered']) . ' besvarede</span>'
                . '<span class="chip-meta">Taletid ' . $e(Calls::duration($sum['talk'])) . '</span>'
                . '<span class="chip-meta">' . $range . '</span>' . self::pills($sum);
            $agents = $sum['agents'] !== [] ? '<p class="lead-agents">Agenter: ' . $e(implode(', ', array_map(
                static fn(string $a): string => $a !== '' ? $a : 'Ukendt agent', $sum['agents']))) . '</p>' : '';
            if ($sum['done'] > 0) {
                $actions .= '<a class="button" href="/lead/' . $lead . '/transcript">' . Ui::icon('download') . 'Download .txt</a>';
            }
            if ($sum['done'] > 0 || $sum['audio'] > 0) {
                $actions .= '<a class="button secondary" href="/lead/' . $lead . '/download" aria-describedby="lead-zip-hjaelp">' . Ui::icon('download')
                    . 'Download .zip</a><span class="sr-only" id="lead-zip-hjaelp">Lyd og transcripts for alle opkald på leadet.</span>';
            }
        }
        $head = '<div class="d-head lead-head"><div class="d-title"><h1>Lead #' . $lead . '</h1><div class="d-chips">' . $chips . '</div>' . $agents . '</div>'
            . ($actions !== '' ? '<div class="actions head-actions">' . $actions . '</div>' : '') . '</div>';

        $offset = ($side - 1) * Leads::LEAD_PAGE;
        $items = '';
        $found = 0;
        $hitCalls = 0;
        $firstHit = null;
        $anyConv = false;
        /** @var list<array{0: int, 1: array<string, mixed>}> $missRun */
        $missRun = [];
        foreach ($calls as $i => $c) {
            $n = $offset + $i + 1;
            $id = (int) $c['id'];
            if (!Leads::answered($c)) {
                $missRun[] = [$n, $c];
                continue;
            }
            $items .= self::misses($missRun, $total);
            $missRun = [];
            $parts = UiCallsV2::parts($c, $transcripts[$id] ?? null, $onDisk[$id] ?? false, null, $terms, $csrf, $ctx, $admin, (string) $id, $n);
            $anyConv = $anyConv || $parts['conversation'];
            if ($parts['found'] > 0) {
                $found += $parts['found'];
                $hitCalls++;
                $firstHit ??= $id;
            }
            $out = (string) $c['direction'] === 'outbound';
            $agent = trim((string) $c['agent_name']);
            [$bClass, $bText] = UiCallsV2::status($c, (string) $c['audio_status'] === 'hentet' ? ($onDisk[$id] ?? false) : null);
            $disp = (string) ($c['disposition'] ?? '');
            $meta = '<span class="dir"><span aria-hidden="true">' . ($out ? '↑ Ud' : '↓ Ind') . '</span><span class="sr-only">' . ($out ? 'Udgående' : 'Indgående')
                . '</span></span> · ' . $e($agent !== '' ? $agent : 'Ukendt agent') . ' · ' . $e(Calls::duration((int) $c['duration_sec']))
                . ($disp !== '' ? ' · <span class="tag">' . $e($disp) . '</span>' : '')
                . ' <span class="badge ' . $bClass . '">' . $e($bText) . '</span>';
            $hitChip = $parts['found'] > 0 ? '<span class="lt-hits">' . $parts['found'] . ' træf</span>' : '';
            $items .= '<li class="lead-item lt-item" id="opkald-' . $id . '"><span class="lt-dot" aria-hidden="true"></span>'
                . '<section class="lead-call lt-call" aria-labelledby="oc-' . $id . '-h" data-num="' . $n . '">'
                . '<div class="lt-head"><h3 id="oc-' . $id . '-h">Opkald ' . $n . ' af ' . $total . ' · ' . $e(self::dateTime((string) $c['started_at'])) . '</h3>'
                . '<span class="lt-head-side">' . $hitChip . '<a class="lt-open" href="/opkald/' . $id . ($ctx !== '' ? '?' . $e($ctx) : '') . '">Åbn opkaldet'
                . Ui::icon('chevron-right', 14) . '</a></span></div>'
                . '<p class="lt-meta">' . $meta . '</p>'
                . '<div class="lt-body' . (str_contains($parts['audio'], 'player-v2') ? ' has-player' : '') . '"><div class="lt-player">' . $parts['audio'] . '</div><div class="lt-conv">' . $parts['body'] . '</div></div>'
                . '</section></li>';
        }
        $items .= self::misses($missRun, $total);

        $find = $anyConv ? '<div class="lead-tools"><div class="find lead-find" data-js-only hidden><label for="lead-find-q" class="sr-only">Find i alle opkald</label>'
            . Ui::icon('search') . '<input type="search" id="lead-find-q" placeholder="Find i alle opkald" autocomplete="off" aria-describedby="lead-find-status">'
            . '<span class="find-status" id="lead-find-status" aria-live="polite"></span></div></div>' : '';
        $hits = '';
        if ($terms !== [] && $anyConv) {
            $hits = $found > 0
                ? '<p class="hits">' . $found . ' fund for «' . $e($f['q']) . '» i ' . $hitCalls . ' opkald · <a href="#fund-' . $firstHit . '-1">Gå til første fund</a></p>'
                : '<p class="hits">Ingen fund for «' . $e($f['q']) . '» i dette lead.</p>';
        }
        $many = $total > Leads::LEAD_PAGE ? '<p class="hint">Leadet har ' . UiV2::num($total) . ' opkald. Siden viser opkald ' . UiV2::num($offset + 1) . '–'
            . UiV2::num(min($total, $offset + Leads::LEAD_PAGE)) . '.</p>' : '';
        $flow = $calls === []
            ? UiCallsV2::empty('Ingen opkald på dette lead endnu.', 'Opkald hentes fra Adversus inden for 10 minutter.', '')
            : '<ol class="lead-timeline">' . $items . '</ol>';
        $html = '<div class="detail-v2 lead-page">' . self::back($f) . Ui::message(null, $flash)
            . ($info !== null && $info !== '' ? Ui::msg('info', '<p>' . $e($info) . '</p>', 'div', 'status') : '')
            . $head . $find . '<section class="lead-flow" id="forloeb" data-total="' . $total . '" aria-labelledby="forloeb-h"><h2 id="forloeb-h">Forløb</h2>' . $hits . $many . $flow
            . '</section>' . self::leadPager($lead, $f, $side, $total) . '</div>';
        return Ui::layout('Lead #' . $lead . ' · Adversus', $html, $user, $csrf, 'opkald');
    }

    /**
     * Ubesvarede opkald i træk: 3 eller flere samles i en lukket gruppe (design §2.5), ellers én linje hver.
     *
     * @param list<array{0: int, 1: array<string, mixed>}> $run  [nummer, opkald]
     */
    private static function misses(array $run, int $total): string
    {
        if ($run === []) {
            return '';
        }
        $out = '';
        if (count($run) >= 3) {
            $out .= '<li class="lead-item lt-item lt-group"><span class="lt-dot lt-dot-miss" aria-hidden="true"></span><details class="lead-miss-group">'
                . '<summary>' . count($run) . ' ubesvarede opkald · ' . Ui::e(self::short((string) $run[0][1]['started_at'])) . ' – '
                . Ui::e(self::short((string) $run[count($run) - 1][1]['started_at'])) . '</summary><ul class="lead-miss-list">';
            foreach ($run as [$n, $c]) {
                $out .= '<li>' . self::missLine($c, $n, $total) . '</li>';
            }
            return $out . '</ul></details></li>';
        }
        foreach ($run as [$n, $c]) {
            $out .= '<li class="lead-item lt-item lead-miss" id="opkald-' . (int) $c['id'] . '"><span class="lt-dot lt-dot-miss" aria-hidden="true"></span>'
                . self::missLine($c, $n, $total) . '</li>';
        }
        return $out;
    }

    /** @param array<string, mixed> $c  én linje for et ubesvaret opkald */
    private static function missLine(array $c, int $n, int $total): string
    {
        $out = (string) $c['direction'] === 'outbound';
        $agent = trim((string) $c['agent_name']);
        $why = self::MISSED[(string) ($c['disposition'] ?? '')] ?? '';
        return '<span class="sr-only">Opkald ' . $n . ' af ' . $total . ': </span><span class="lt-time">' . Ui::e(self::dateTime((string) $c['started_at'])) . '</span>'
            . ' · <span aria-hidden="true">' . ($out ? '↑ Ud' : '↓ Ind') . '</span><span class="sr-only">' . ($out ? 'Udgående' : 'Indgående') . '</span>'
            . ' · ' . Ui::e($agent !== '' ? $agent : 'Ukendt agent') . ' · Ikke besvaret' . ($why !== '' ? ' (' . $why . ')' : '');
    }

    /** "4. nov. 08:02" til en gruppe ubesvarede. */
    private static function short(string $utc): string
    {
        $d = Calls::local($utc, 'n j H:i');
        if ($d === '') {
            return '';
        }
        [$m, $day, $hm] = explode(' ', $d);
        return $day . '. ' . self::MONTHS[(int) $m - 1] . ' ' . $hm;
    }

    /** "3. nov. 2026" */
    private static function day(string $utc): string
    {
        $d = Calls::local($utc, 'Y n j');
        if ($d === '') {
            return '';
        }
        [$y, $m, $day] = explode(' ', $d);
        return $day . '. ' . self::MONTHS[(int) $m - 1] . ' ' . $y;
    }

    /** @param Filter $f  F007's pager, men for /lead/{id}?side=… med listens kontekst bevaret i ctx */
    private static function leadPager(int $lead, array $f, int $side, int $total): string
    {
        $pages = (int) ceil($total / Leads::LEAD_PAGE);
        if ($pages <= 1) {
            return '';
        }
        $url = static function (int $p) use ($lead, $f): string {
            $q = CallView::query($f, $f['side']);
            return '/lead/' . $lead . '?' . ($q !== '' ? $q . '&' : '') . 'lside=' . $p;
        };
        $btn = static function (int $p, bool $enabled, string $label, string $icon, bool $iconFirst) use ($url): string {
            $word = '<span class="pg-word" aria-hidden="true">' . $label . '</span><span class="sr-only pg-sr">' . $label . ' side</span>';
            $inner = $iconFirst ? Ui::icon($icon) . $word : $word . Ui::icon($icon);
            return $enabled ? '<a class="button secondary small" href="' . Ui::e($url($p)) . '">' . $inner . '</a>'
                : '<span class="button secondary small" aria-disabled="true">' . $inner . '</span>';
        };
        return '<nav class="pager" aria-label="Sider">' . $btn($side - 1, $side > 1, 'Forrige', 'chevron-left', true)
            . '<span class="page-info">Side ' . UiV2::num($side) . ' af ' . UiV2::num($pages) . '</span>'
            . $btn($side + 1, $side < $pages, 'Næste', 'chevron-right', false) . '</nav>';
    }
}
