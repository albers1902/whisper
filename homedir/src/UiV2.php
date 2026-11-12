<?php
declare(strict_types=1);

namespace Whisper;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Whisper\Transcription\EngineStatus;

/**
 * Det nye udseende (F007, ui-ux/F007_design_v2.md og graphic-design/F007_visual_v2.md) bag flaget F007_kilder, oven på
 * F005_ui: ramme med mørk sidebar (topbar under 900 px), kø-kort, selv-hostede fonte og egne assets (app-v2.css,
 * app-v2.js). Med flaget slukket kaldes intet herfra, og F005's sider og assets er byte for byte som før (AK8).
 * CSP som i dag: intet inline; farver og bredder kun via klasser.
 */
final class UiV2
{
    public const FLAG = 'F007_kilder';

    /** @var array{status: array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int}, engine: array{state: string, since: ?int}}|null */
    private static ?array $queue = null;

    public static function on(): bool
    {
        return Ui::modern() && Flags::enabled(self::FLAG);
    }

    /** Kun tests: glem kø-kortets tal mellem to requests i samme proces. */
    public static function reset(bool $cache = true): void
    {
        self::$queue = null;
        if ($cache) {
            foreach (['queue', 'agents-zendesk', 'agents-adversus'] as $name) {
                @unlink(Paths::varDir() . '/cache/f007-' . $name . '.json');
            }
        }
    }

    /**
     * Rammen: med en bruger og en sektion (opkald|brugere|konto) sidebar + topbar; ellers (login, konsent, fejl) et
     * centreret kort under mærket uden sidebar.
     *
     * @param array<string, mixed>|null $user
     */
    public static function layout(string $title, string $body, ?array $user, string $csrf, string $section = ''): string
    {
        $head = '<!doctype html><html lang="da" class="v2"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Ui::e($title) . ' · Whisper</title>'
            . '<link rel="preload" href="/assets/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>'
            . '<link rel="preload" href="/assets/fonts/inter-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>'
            . '<link rel="stylesheet" href="' . Ui::asset('app-v2.css') . '">'
            . '<script src="' . Ui::asset('app-v2.js') . '" defer></script></head>';
        $live = '<div class="sr-only" role="status" id="live"></div>';
        if ($user === null || $section === '') {
            return $head . '<body class="plain"><a class="skip" href="#indhold">Spring til indhold</a>'
                . '<div class="plain-wrap"><a class="brand brand-plain" href="/">' . self::mark() . '<span>Whisper</span></a>'
                . '<main id="indhold" tabindex="-1">' . $body . '</main></div>' . $live . '</body></html>';
        }
        $admin = ($user['role'] ?? '') === 'admin';
        $item = static fn(string $s, string $href, string $icon, string $label): string => '<li><a href="' . $href . '"'
            . ($s === $section ? ' aria-current="page"' : '') . '>' . Ui::icon($icon, 18) . '<span>' . $label . '</span></a></li>';
        $nav = '<nav class="side-nav" aria-label="Hovedmenu"><ul>' . $item('opkald', '/', 'phone', 'Opkald')
            . ($admin ? $item('brugere', '/brugere', 'users', 'Brugere') : '') . $item('konto', '/konto', 'user-round', 'Konto') . '</ul></nav>';
        $name = (string) ($user['name'] ?? '');
        $who = '<div class="side-user">' . self::avatar($name) . '<div class="side-user-text"><span class="side-user-name" title="'
            . Ui::e($name) . '">' . Ui::e($name) . '</span><span class="side-user-role">' . ($admin ? 'Administrator' : 'Bruger') . '</span></div></div>'
            . '<form method="post" action="/logout" class="side-logout">' . View::csrfField($csrf)
            . '<button type="submit">' . Ui::icon('log-out', 18) . '<span>Log ud</span></button></form>';
        [$card, $compact] = self::queueCard($admin);
        return $head . '<body class="framed"><a class="skip" href="#indhold">Spring til indhold</a><div class="app">'
            . '<header class="topbar"><button type="button" class="menu-btn" aria-expanded="false" aria-controls="sidemenu" data-js-only hidden>'
            . Ui::icon('menu', 20) . str_replace('<svg class="icon icon-20"', '<svg class="icon icon-20 icon-close"', Ui::icon('x', 20))
            . '<span class="sr-only">Menu</span></button>'
            . '<a class="brand" href="/">' . self::mark() . '<span>Whisper</span></a>' . $compact . '</header>'
            . '<aside class="sidebar" id="sidemenu"><a class="brand side-brand" href="/">' . self::mark() . '<span>Whisper</span></a>'
            . $nav . '<div class="side-bottom">' . $card . $who . '</div></aside>'
            . '<main id="indhold" tabindex="-1">' . $body . '</main></div>' . $live . '</body></html>';
    }

    /**
     * Kø-kortet (UI/UX v2 §2.1) og topbarens kompakte linje. Tallene er fælles for begge kilder (Calls::status); motorens
     * status kommer fra EngineStatus (AK7). Læses én gang pr. request.
     *
     * @return array{0: string, 1: string}
     */
    public static function queueCard(bool $admin, ?int $now = null): array
    {
        $now ??= time();
        try {
            ['status' => $status, 'engine' => $engine] = self::queue($now);
            $n = (int) $status['waiting'];
        } catch (Throwable) {
            // Databasen svarer ikke: ingen tal, aldrig "Ingen i kø" på et gæt (QA F-2)
            $status = ['waiting' => 0, 'audio_waiting' => 0];
            $engine = ['state' => EngineStatus::UNKNOWN, 'since' => null];
            $n = null;
        }
        [$dot, $text, $sr] = self::engineLine($engine, $admin, $now);
        $count = $n === null ? '<p class="q-none">Kø: ukendt</p>'
            : ($n > 0 ? '<p class="q-count"><span class="q-num">' . self::num($n) . '</span> i kø</p>' : '<p class="q-none">Ingen i kø</p>');
        $audio = $n !== null && $n > 0 && (int) $status['audio_waiting'] > 0
            ? '<p class="q-audio">' . self::num((int) $status['audio_waiting']) . ' venter på lyd</p>' : '';
        $card = '<section class="q-card" aria-labelledby="ko-h"><h2 id="ko-h">Transcription</h2>' . $count
            . '<p class="q-engine"><span class="dot dot-' . $dot . '" aria-hidden="true"></span>' . Ui::e($text) . '</p>' . $audio . '</section>';
        $ok = $dot === 'ok';
        $compact = '<p class="top-queue">' . (!$ok ? '<span class="dot dot-' . $dot . '" aria-hidden="true"></span><span class="sr-only">'
            . Ui::e($sr) . ', </span>' : '') . ($n === null ? 'Kø: ukendt' : ($n > 0 ? self::num($n) . ' i kø' : 'Ingen i kø')) . '</p>';
        return [$card, $compact];
    }

    /**
     * Kø-tallene og motorens status, læst én gang pr. request (listen bruger samme status til Zendesk-linjen).
     *
     * @return array{status: array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int}, engine: array{state: string, since: ?int}}
     */
    public static function queue(?int $now = null): array
    {
        $now ??= time();
        if (self::$queue === null) {
            // Tallene caches QUEUE_TTL sekunder i var/cache: kø-tællingen rører alle ventende opkald, og kortet står på
            // alle sider. Højst én tælling i minuttet for hele sitet; motorens status læses frisk (filer + LIMIT 1).
            $status = self::cachedStatus($now);
            self::$queue = ['status' => $status, 'engine' => EngineStatus::read($status['waiting'], Calls::transcribingNow(), $now)];
        }
        return self::$queue;
    }

    public const QUEUE_TTL = 60;
    public const AGENTS_TTL = 300;

    /**
     * Agentlisten pr. fane, cachet AGENTS_TTL sekunder i var/cache (den læser hele kildens indeks, og agenter kommer
     * sjældent til). Kan cachen ikke bruges, hentes listen direkte.
     *
     * @return list<string>
     */
    public static function agents(string $source): array
    {
        try {
            $file = Paths::dir('cache') . '/f007-agents-' . ($source === 'adversus' ? 'adversus' : 'zendesk') . '.json';
            $mtime = is_file($file) ? @filemtime($file) : false;
            if ($mtime !== false && time() - $mtime < self::AGENTS_TTL && time() >= $mtime) {
                $d = json_decode((string) @file_get_contents($file), true);
                if (is_array($d) && array_is_list($d) && count($d) <= 100000) {
                    return array_values(array_filter($d, 'is_string'));
                }
            }
        } catch (Throwable) {
            return Calls::agents($source);
        }
        $list = Calls::agents($source);
        try {
            Paths::writePrivate(Paths::dir('cache') . '/f007-agents-' . ($source === 'adversus' ? 'adversus' : 'zendesk') . '.json',
                (string) json_encode($list));
        } catch (Throwable) {
            // næste visning henter igen
        }
        return $list;
    }
    /** Tal over dette i cachefilen er ikke troværdige og tælles igen (security S3) */
    public const QUEUE_SANE = 10000000;

    /** @return array{last_ok_at: ?string, last_error: ?string, waiting: int, audio_waiting: int} */
    private static function cachedStatus(int $now): array
    {
        // En cache, der ikke kan læses eller skrives (fx uskrivbar var/cache), betyder bare en direkte tælling (QA F-2)
        try {
            $file = Paths::dir('cache') . '/f007-queue.json';
        } catch (Throwable) {
            return Calls::status();
        }
        $mtime = is_file($file) ? @filemtime($file) : false;
        if ($mtime !== false && $now - $mtime < self::QUEUE_TTL && $now >= $mtime) {
            $d = json_decode((string) @file_get_contents($file), true);
            $ok = static fn(mixed $v): bool => is_int($v) && $v >= 0 && $v <= self::QUEUE_SANE;   // security S3
            if (is_array($d) && $ok($d['waiting'] ?? null) && $ok($d['audio_waiting'] ?? null)) {
                return ['last_ok_at' => is_string($d['last_ok_at'] ?? null) ? $d['last_ok_at'] : null,
                    'last_error' => is_string($d['last_error'] ?? null) ? $d['last_error'] : null,
                    'waiting' => $d['waiting'], 'audio_waiting' => $d['audio_waiting']];
            }
        }
        $status = Calls::status();
        try {
            Paths::writePrivate($file, (string) json_encode($status));
        } catch (Throwable) {
            // uden cache tælles der bare igen næste gang
        }
        return $status;
    }

    /**
     * @param array{state: string, since: ?int} $engine
     * @return array{0: string, 1: string, 2: string} [prik, tekst, sr-tekst til topbaren]
     */
    public static function engineLine(array $engine, bool $admin, int $now): array
    {
        return match ($engine['state']) {
            EngineStatus::BUSY    => ['ok', 'Transcriberer nu', ''],
            EngineStatus::READY   => ['ok', 'Klar', ''],
            EngineStatus::STALLED => ['warn', 'Ingen fremdrift' . ($engine['since'] !== null ? ' i ' . self::span($now - $engine['since']) : ''),
                'Transcription: ingen fremdrift'],
            EngineStatus::SETUP   => ['warn', 'Opsætning i gang', 'Transcription: opsætning i gang'],
            EngineStatus::MISSING => ['error', $admin ? 'Ikke sat op. Kør cron/whisper-setup.sh.' : 'Nye opkald bliver ikke transcriberet lige nu.',
                'Transcription: ikke sat op'],
            default               => ['none', 'Ukendt status', 'Transcription: ukendt status'],
        };
    }

    /** "45 min." / "2 t." / "3 dage" — samme afrunding som Ui::ago. */
    public static function span(int $s): string
    {
        $s = max(0, $s);
        if ($s < 3600) {
            return max(1, intdiv($s, 60)) . ' min.';
        }
        return $s < 48 * 3600 ? intdiv($s, 3600) . ' t.' : intdiv($s, 86400) . ' dage';
    }

    /** Tusindtal med punktum: 1.022 */
    public static function num(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /** Avatar med initialer på en af seks faste farver (valgt ud fra navnet), eller neutral for en ukendt agent. */
    public static function avatar(string $name, string $extra = ''): string
    {
        $name = trim($name);
        $cls = 'avatar' . ($extra !== '' ? ' ' . $extra : '');
        if ($name === '' || preg_match('/^Bruger #[0-9]+$/u', $name) === 1) {
            return '<span class="' . $cls . ' av-0" aria-hidden="true">' . Ui::icon('circle-user', 18) . '</span>';
        }
        $words = preg_split('/\s+/u', $name) ?: [$name];
        $first = (string) $words[0];
        $ini = count($words) > 1 ? mb_substr($first, 0, 1) . mb_substr((string) end($words), 0, 1) : mb_substr($first, 0, 2);
        return '<span class="' . $cls . ' av-' . (crc32($name) % 6 + 1) . '" aria-hidden="true">' . Ui::e(mb_strtoupper($ini)) . '</span>';
    }

    /** Mærket: fire bjælker i to toner (visual v2, Brand). Farverne fra klasser, ikke fill-attributter. */
    public static function mark(): string
    {
        $bars = '';
        foreach ([[2, 9, 8, 1], [8, 4, 18, 1], [14, 7, 12, 2], [20, 10, 6, 2]] as [$x, $y, $h, $tone]) {
            $bars .= '<rect class="logo-' . $tone . '" x="' . $x . '" y="' . $y . '" width="3" height="' . $h . '" rx="1.5"/>';
        }
        return '<svg class="brand-mark" viewBox="0 0 26 26" width="26" height="26" aria-hidden="true" focusable="false">' . $bars . '</svg>';
    }

    private const MONTHS = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];

    /** "i dag 12:09" · "i går 14:30" · "6. nov. 12:09" · "6. nov. 2025 12:09" (dansk tid). $utc fra databasen. */
    public static function when(mixed $utc, int $now): string
    {
        if (!is_string($utc)) {
            return '';
        }
        $tz = new DateTimeZone(Calls::TZ);
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if ($d === false) {
            return '';
        }
        $d = $d->setTimezone($tz);
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $hm = $d->format('H:i');
        if ($d->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'i dag ' . $hm;
        }
        if ($d->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) {
            return 'i går ' . $hm;
        }
        $day = $d->format('j') . '. ' . self::MONTHS[(int) $d->format('n') - 1];
        return $day . ($d->format('Y') === $today->format('Y') ? '' : ' ' . $d->format('Y')) . ' ' . $hm;
    }

    /** "6. nov. 2026, 12:09" til detaljens dato-chip. */
    public static function longDate(mixed $utc): string
    {
        $d = is_string($utc) ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC')) : false;
        if ($d === false) {
            return '';
        }
        $d = $d->setTimezone(new DateTimeZone(Calls::TZ));
        return $d->format('j') . '. ' . self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y') . ', ' . $d->format('H:i');
    }
}
