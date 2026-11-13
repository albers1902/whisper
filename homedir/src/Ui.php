<?php
declare(strict_types=1);

namespace Whisper;

use DateTimeImmutable;
use DateTimeZone;
use Whisper\Transcription\QueueRules;

/**
 * Det nye UI (F005) bag flaget F005_ui: fælles ramme, ikoner, badges, beskeder og fremhævning af søgeord.
 * Siderne ligger i UiPages (login, konto, brugere, fejl) og UiCalls (liste, detalje). Med flaget slukket bruges
 * View og CallView uændret. CSP'en tillader intet inline: ingen style="", ingen <style>, intet inline script —
 * JS kommer kun fra /assets/app.js, og alt virker uden.
 */
final class Ui
{
    public const FLAG = 'F005_ui';

    public static function modern(): bool
    {
        return Flags::enabled(self::FLAG);
    }

    public static function e(string $s): string
    {
        return View::e($s);
    }

    /**
     * @param array<string, mixed>|null $user
     * @param string $section  opkald|brugere|konto|'' — markerer menupunktet med aria-current
     */
    public static function layout(string $title, string $body, ?array $user, string $csrf, string $section = ''): string
    {
        if (UiV2::on()) {
            return UiV2::layout($title, $body, $user, $csrf, $section);   // F007: ny ramme; flaget slukket er uændret
        }
        $head = '';
        if ($user !== null) {
            $cur = static fn(string $s): string => $s === $section ? ' aria-current="page"' : '';
            $nav = '<a href="/"' . $cur('opkald') . '>Opkald</a>';
            if (($user['role'] ?? '') === 'admin') {
                $nav .= '<a href="/brugere"' . $cur('brugere') . '>Brugere</a>';
            }
            $head = '<nav class="main-nav" aria-label="Hovedmenu">' . $nav . '</nav>'
                . '<div class="user"><span class="who">' . self::e((string) ($user['name'] ?? '')) . '</span>'
                . '<a href="/konto"' . $cur('konto') . '>Konto</a>'
                . '<form method="post" action="/logout" class="inline">' . View::csrfField($csrf)
                . '<button type="submit" class="ghost">' . self::icon('log-out') . 'Log ud</button></form></div>';
        }
        return '<!doctype html><html lang="da"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . ' · Whisper</title>'
            . '<link rel="stylesheet" href="' . self::asset('app.css') . '">'
            . '<script src="' . self::asset('app.js') . '" defer></script></head><body>'
            . '<a class="skip" href="#indhold">Spring til indhold</a>'
            . '<header class="top"><div class="top-inner"><a class="brand" href="/">' . self::brandMark() . '<span>Whisper</span></a>'
            . $head . '</div></header>'
            . '<main id="indhold" tabindex="-1">' . $body . '</main>'
            . '<div class="sr-only" role="status" id="live"></div></body></html>';
    }

    /** /assets/<fil>?v=<ændringstid>, så browseren henter en ny fil efter en udrulning. */
    public static function asset(string $file): string
    {
        $path = __DIR__ . '/../public_html/assets/' . $file;
        $v = is_file($path) ? (string) filemtime($path) : '0';
        return '/assets/' . $file . '?v=' . $v;
    }

    /**
     * En besked med ikon. $html er allerede escaped (kalderen bruger e()).
     *
     * @param 'info'|'ok'|'warn'|'error' $type
     */
    public static function msg(string $type, string $html, string $tag = 'div', ?string $role = null): string
    {
        $icon = ['info' => 'info', 'ok' => 'circle-check', 'warn' => 'triangle-alert', 'error' => 'circle-alert'][$type];
        // $role (kun 'status') lader en info-kvittering efter en handling blive læst op som ok-beskeder (F004 UX Finding 2)
        $role = $role === 'status' ? ' role="status"' : ($type === 'error' ? ' role="alert"' : ($type === 'ok' ? ' role="status"' : ''));
        return '<' . $tag . ' class="msg ' . $type . '"' . $role . '>' . self::icon($icon, 18) . '<div class="msg-body">' . $html . '</div></' . $tag . '>';
    }

    public static function message(?string $error, ?string $ok = null): string
    {
        $out = '';
        if ($error !== null && $error !== '') {
            $out .= self::msg('error', '<p>' . self::e($error) . '</p>');
        }
        if ($ok !== null && $ok !== '') {
            $out .= self::msg('ok', '<p>' . self::e($ok) . '</p>');
        }
        return $out;
    }

    /**
     * Statusbadge efter UX §3.2 — samme kortlægning i liste og detalje. Lyden afgøres først: uden hentet lyd kan
     * intet transcriberes. $audioOnDisk = false (kun detaljen ved det) giver "Lyd mangler".
     *
     * $skip (H012, QueueRules::skipReason) giver det neutrale "For kort" / "Ikke automatisk", når lyden er hentet.
     *
     * @return array{0: string, 1: string} [klasse, tekst]
     */
    public static function status(string $audio, string $transcript, ?bool $audioOnDisk = null, ?string $skip = null): array
    {
        if ($transcript === 'ikke_valgt') {
            return ['s-ikke-valgt', 'Ikke valgt'];   // F004: afgøres før lyden, som aldrig hentes for et fravalgt opkald
        }
        if ($audio === 'venter') {
            return ['s-ventende', 'Venter på lyd'];
        }
        if ($audio === 'fejlet' || ($audio === 'hentet' && $audioOnDisk === false)) {
            return ['s-lyd', 'Lyd mangler'];
        }
        if ($audio !== 'hentet') {
            return ['s-ukendt', 'Ukendt'];
        }
        if ($skip !== null) {
            return ['s-ikke-valgt', QueueRules::label($skip)];
        }
        return [
            'ventende'      => ['s-ventende', 'Venter'],
            'transcriberer' => ['s-transcriberer', 'Transcriberer'],
            'faerdig'       => ['s-faerdig', 'Færdig'],
            'fejlet'        => ['s-fejlet', 'Fejlet'],
        ][$transcript] ?? ['s-ukendt', 'Ukendt'];
    }

    /** @param array<string, mixed> $call */
    public static function badge(array $call, ?bool $audioOnDisk = null): string
    {
        [$class, $text] = self::status((string) ($call['audio_status'] ?? ''), (string) ($call['transcript_status'] ?? ''), $audioOnDisk,
            QueueRules::fromConfig()->skipReason($call, time()));
        return '<span class="badge ' . $class . '">' . self::e($text) . '</span>';
    }

    /**
     * Søgeordene som i Calls::where (ord på mindst 3 tegn, ellers alle), med små bogstaver.
     *
     * @return list<string>
     */
    public static function terms(string $q): array
    {
        return array_values(array_unique(array_map(static fn(string $w): string => mb_strtolower($w), Calls::searchWords($q))));
    }

    /**
     * Fremhævning (UX §3.4, D6): den RÅ tekst deles i ord og mellemrum FØR escaping. Hvert token escapes for sig,
     * og kun ord-tokens, der begynder med et søgeord, pakkes i <mark>. Der søges aldrig i escaped HTML, så
     * &amp; og <script> i et transcript aldrig bliver til markup. $count tæller fund på tværs af kald; det første
     * får id="fund-1", når $anchor er sat.
     *
     * @param list<string> $terms  fra terms()
     */
    public static function highlight(string $raw, array $terms, int &$count, bool $anchor = false, string $prefix = 'fund-'): string
    {
        $parts = $terms === [] ? false : preg_split('/([\p{L}\p{N}]+)/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::e($raw);   // ingen søgning, eller ugyldig UTF-8 (e() erstatter de ugyldige bytes)
        }
        $out = '';
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1 && self::matches($p, $terms)) {
                $count++;
                $out .= '<mark' . ($anchor && $count === 1 ? ' id="' . self::e($prefix) . '1"' : '') . '>' . self::e($p) . '</mark>';
            } else {
                $out .= self::e($p);
            }
        }
        return $out;
    }

    /** @param list<string> $terms */
    private static function matches(string $word, array $terms): bool
    {
        $w = mb_strtolower($word);
        foreach ($terms as $t) {
            if (str_starts_with($w, $t)) {
                return true;
            }
        }
        return false;
    }

    public const SNIPPET_MAX = 160;

    /**
     * Søgeuddrag til listen (BØR 15): højst SNIPPET_MAX tegn (inkl. "…") omkring første fund i $window, som SQL
     * allerede har skåret til (Calls::snippets). Klippes ved mellemrum, fremhæves og escapes som highlight().
     *
     * @param list<string> $terms
     */
    public static function snippet(string $window, bool $cutStart, bool $cutEnd, array $terms): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $window));
        if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $pos = 0;
        $len = 0;
        $offset = 0;
        $parts = $terms === [] ? false : preg_split('/([\p{L}\p{N}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts === false ? [] : $parts as $i => $p) {
            if ($i % 2 === 1 && self::matches($p, $terms)) {
                $pos = $offset;
                $len = mb_strlen($p);
                break;
            }
            $offset += mb_strlen($p);
        }
        $total = mb_strlen($text);
        $room = self::SNIPPET_MAX - 2;   // plads til "…" i begge ender
        $start = max(0, min($pos - 50, $total - $room));
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            if ($space !== false && $space < $pos) {
                $start = $space + 1;
            }
        }
        $piece = mb_substr($text, $start, $room);
        $end = $start + mb_strlen($piece);
        if ($end < $total) {
            $space = mb_strrpos($piece, ' ');
            if ($space !== false && $start + $space >= $pos + $len) {
                $piece = mb_substr($piece, 0, $space);
            }
        }
        $count = 0;
        return ($start > 0 || $cutStart ? '…' : '') . self::highlight($piece, $terms, $count)
            . ($start + mb_strlen($piece) < $total || $cutEnd ? '…' : '');
    }

    /** "for 4 min. siden" / "for 2 t. siden" / "for 3 dage siden" fra et tidspunkt i sekunder. */
    public static function ago(int $then, int $now): string
    {
        $s = max(0, $now - $then);
        if ($s < 60) {
            return 'for under 1 min. siden';
        }
        if ($s < 3600) {
            return 'for ' . intdiv($s, 60) . ' min. siden';
        }
        if ($s < 48 * 3600) {
            return 'for ' . intdiv($s, 3600) . ' t. siden';
        }
        return 'for ' . intdiv($s, 86400) . ' dage siden';
    }

    /** <time> med ISO-tid og dansk tid i title. $utc er databasens UTC-DATETIME. */
    public static function time(string $utc, string $text): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if ($d === false) {
            return self::e($text);
        }
        return '<time datetime="' . $d->format('Y-m-d\TH:i:s\Z') . '" title="' . self::e(Calls::local($utc)) . '">' . self::e($text) . '</time>';
    }

    /** Ticket-link til Zendesk, der siger, at det åbner en ny fane. */
    public static function ticket(int $ticket, ?string $subdomain): string
    {
        if ($ticket <= 0) {
            return '—';
        }
        if ($subdomain === null) {
            return '#' . $ticket;
        }
        return '<a class="ticket" href="' . self::e('https://' . $subdomain . '.zendesk.com/agent/tickets/' . $ticket)
            . '" rel="noopener noreferrer" target="_blank">#' . $ticket . self::icon('external-link', 14)
            . '<span class="sr-only"> (åbner Zendesk i en ny fane)</span></a>';
    }

    /** Kopiér-knap (UX §4.2): skjult uden JS, app.js viser den. */
    public static function copyButton(string $target, string $label, string $extraClass = ''): string
    {
        return '<button type="button" class="button secondary' . ($extraClass !== '' ? ' ' . $extraClass : '') . '" data-copy="#'
            . self::e($target) . '" hidden>' . self::icon('copy') . '<span class="label">' . self::e($label) . '</span></button>';
    }

    /*
     * Ikoner: path-data fra Lucide (https://lucide.dev), ISC-licens:
     * Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of Feather (MIT). All other
     * copyright (c) for Lucide are held by Lucide Contributors 2022. Permission to use, copy, modify, and/or distribute
     * this software for any purpose with or without fee is hereby granted, provided that the above copyright notice and
     * this permission notice appear in all copies. THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL
     * WARRANTIES WITH REGARD TO THIS SOFTWARE.
     * Farve via stroke="currentColor" (attribut, ikke style — CSP).
     */
    private const ICONS = [
        'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'copy'           => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'check'          => '<path d="M20 6 9 17l-5-5"/>',
        'external-link'  => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'info'           => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'circle-check'   => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'circle-alert'   => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
        'chevron-left'   => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
        'headphones'     => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
        'log-out'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'key-round'      => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        // F007 (graphic-design/F007_visual_v2.md, Iconography)
        'phone'          => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-round'     => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'circle-user'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>',
        'menu'           => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'calendar'       => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'chevron-down'   => '<path d="m6 9 6 6 6-6"/>',
        'play'           => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'pause'          => '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
        'message-square' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'arrow-left'     => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'trophy'         => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    ];

    /** Kilde-etiket (F004, visual brief: neutral .tag, ens for alle kilder). */
    public static function sourceTag(string $source): string
    {
        return '<span class="tag tag-source">' . self::e(Calls::sourceName($source)) . '</span>';
    }

    /** "Succes-lead"-etiket (F004): outline, trofæ, ingen prik — en egenskab, ikke en status. */
    public static function successTag(): string
    {
        return '<span class="tag tag-success">' . self::icon('trophy', 14) . 'Succes-lead</span>';
    }

    public static function icon(string $name, int $size = 16): string
    {
        return '<svg class="icon icon-' . $size . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size
            . '" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . (self::ICONS[$name] ?? '') . '</svg>';
    }

    /** Mærket: fem bølgebjælker (visuelt brief §4), ikke Lucide. */
    private static function brandMark(): string
    {
        $bars = '';
        foreach ([[1, 7, 6], [5, 4, 12], [9, 2, 16], [13, 5, 10], [17, 8, 4]] as [$x, $y, $h]) {
            $bars .= '<rect x="' . $x . '" y="' . $y . '" width="2" height="' . $h . '" rx="1"/>';
        }
        return '<svg class="brand-mark" viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false">'
            . $bars . '</svg>';
    }
}
