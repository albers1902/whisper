<?php
declare(strict_types=1);

// F007-AK9: ingen eksterne ressourcer (fonte selv-hostet med OFL-licens), ingen inline style/script, CSP uændret,
// AA-kontrast (4,5:1) for tekstparrene i begge temaer, og CSS'en har det, der holder siden uden vandret scroll ved 320 px.

require_once __DIR__ . '/f007_lib.php';

use Whisper\App;
use Whisper\DB;

f007_setup();
$c = f005_admin();
$id = f002_insert(['started_at' => '2026-11-12 08:00:00']);
f002_audio($id);
f002_transcript($id, 'Hej <script>alert(1)</script> og "citat".', [['start' => 0.0, 'end' => 2.0, 'text' => 'Hej <script>alert(1)</script>.', 'speaker' => 0, 'role' => 'agent']]);
$a = f004_insert(9001, false, 'ikke_valgt', '2026-11-12 09:00:00');
$uid = (int) DB::scalar('SELECT id FROM users LIMIT 1');
$pages = [['/', []], ['/', ['kilde' => 'adversus', 'vis' => 'alle']], ['/', ['q' => 'hej', 'status' => 'faerdig']], ['/opkald/' . $id, ['q' => 'hej']],
    ['/opkald/' . $a, ['kilde' => 'adversus']], ['/konto', []], ['/brugere', []], ['/brugere/' . $uid, []], ['/findes-ikke', []]];
foreach ($pages as [$path, $q]) {
    $r = f007_get($c, $path, $q);
    $ext = preg_match('#(src|href)="(https?:)?//(?!testsub\.zendesk\.com)#', $r->body) === 1;
    F007Check::that('AK9', f005_no_inline($r) && !$ext && $r->header('Content-Security-Policy') === App::csp() && !str_contains($r->body, '<script>alert'),
        "$path " . http_build_query($q) . ': intet inline, intet eksternt, samme CSP, transcript escaped');
}
$login = (new F001Client('10.0.0.6'))->get('/login');
F007Check::that('AK9', f005_no_inline($login), 'login: intet inline');

$assets = __DIR__ . '/../public_html/assets/';
$css = (string) file_get_contents($assets . 'app-v2.css');
$js = (string) file_get_contents($assets . 'app-v2.js');
preg_match_all('/url\(([^)]*)\)/', $css, $m);
F007Check::that('AK9', $m[1] !== [] && array_filter($m[1], static fn(string $u): bool => !str_starts_with($u, '"/assets/fonts/')) === [],
    'CSS henter kun fonte fra /assets/fonts/ (ingen data:, intet eksternt)');
F007Check::that('AK9', !str_contains($js, 'innerHTML') && !str_contains($js, 'eval(') && !str_contains($js, 'http') && !str_contains($js, '.style.'),
    'JS: ingen innerHTML, eval, eksterne kald eller inline style');
$fonts = ['inter-latin-400-normal.woff2', 'inter-latin-500-normal.woff2', 'inter-latin-600-normal.woff2', 'inter-latin-700-normal.woff2',
    'inter-tight-latin-700-normal.woff2'];
$ok = true;
foreach ($fonts as $f) {
    $ok = $ok && is_file($assets . 'fonts/' . $f) && substr((string) file_get_contents($assets . 'fonts/' . $f), 0, 4) === 'wOF2'
        && str_contains($css, '/assets/fonts/' . $f);
}
F007Check::that('AK9', $ok, 'fem woff2-fonte findes og bruges');
// security S1: præcis filerne fra @fontsource/inter og @fontsource/inter-tight 5.3.0 (npm, sha512-integrity verificeret)
$sha = ['inter-latin-400-normal.woff2' => '8909904ab6c872eb994093482a88a28eca2cd95912d7b6fecd72103b0dc07edc',
    'inter-latin-500-normal.woff2' => 'f3779f1efccc4bdcdf9c0a02ab95bf6bd092ed09c48c08cedc725889edd1d19f',
    'inter-latin-600-normal.woff2' => 'f9a06e79cd3a2a20951c0f0e28f66dd0e6d3fda73911d640a2125c8fcb78f21a',
    'inter-latin-700-normal.woff2' => '6f56409fd3d64bb85f7d070bce20749db2d66b6d63cec586cc22d1c761be2491',
    'inter-tight-latin-700-normal.woff2' => '9c4f02f38678f2628292d66c4f7a1a6a06af7ffac4d279359b09074e7d0f1fe0',
    'OFL-Inter.txt' => '3b0a5fca3d17942cde889069889dedbbbd075e9b599968c82a95f4d944e9b345',
    'OFL-Inter-Tight.txt' => '1e1c8b3dc6dfcbd5498a33f17fcc68ff2d39c599f6e8195fbf14601261dbef6a'];
$bad = [];
foreach ($sha as $file => $hash) {
    if (hash_file('sha256', $assets . 'fonts/' . $file) !== $hash) {
        $bad[] = $file;
    }
}
$extra = array_diff(array_map('basename', glob($assets . 'fonts/*') ?: []), array_keys($sha));
F007Check::that('AK9', $bad === [] && $extra === [], 'fontfilerne har de verificerede sha256 og der er ingen andre (' . implode(', ', $bad + $extra) . ')');
F007Check::that('AK9', str_contains((string) file_get_contents($assets . 'fonts/OFL-Inter.txt'), 'SIL Open Font License')
    && str_contains((string) file_get_contents($assets . 'fonts/OFL-Inter-Tight.txt'), 'SIL Open Font License'), 'OFL-licensfiler');
// woff2's MIME-type og cache kommer fra serveren, ikke .htaccess: DEV tillader kun rewrite der (H014)

// Kontrast: tokens fra CSS'en, lyst og mørkt
$block = static function (string $css, bool $dark): array {
    $start = $dark ? strpos($css, '@media (prefers-color-scheme: dark)') : strpos($css, ':root {');
    $body = substr($css, (int) $start, 6000);
    $body = substr($body, 0, (int) strpos($body, $dark ? "\n  }\n}" : "\n}"));
    preg_match_all('/--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})/', $body, $mm, PREG_SET_ORDER);
    $t = [];
    foreach ($mm as $x) {
        $t[$x[1]] = $x[2];
    }
    return $t;
};
$light = $block($css, false);
$darkOnly = $block($css, true);
F007Check::that('AK9', count($light) > 60 && count($darkOnly) > 40, 'tokens læst: ' . count($light) . ' lyse, ' . count($darkOnly) . ' mørke');
$dark = $darkOnly + $light;
$pairs = [['text', 'bg'], ['text', 'surface'], ['text-2', 'surface'], ['text-muted', 'bg'], ['text-muted', 'surface'], ['neutral-text', 'track'],
    ['text', 'segment-on'], ['text-muted', 'track'], ['neutral-text', 'surface-2'], ['success-text', 'success-bg'], ['info-text', 'info-bg'],
    ['warning-text', 'warning-bg'], ['danger-text', 'danger-bg'], ['neutral-text', 'neutral-bg'], ['pill-on-text', 'pill-on-bg'],
    ['accent-contrast', 'accent'], ['accent-contrast', 'accent-hover'], ['accent', 'surface'], ['accent', 'bg'], ['agent-ink', 'agent-bg'],
    ['agent-ink', 'agent-bg-hover'], ['kunde-ink', 'kunde-bg'], ['kunde-ink', 'kunde-bg-hover'], ['text-muted', 'agent-bg'], ['text-muted', 'kunde-bg'],
    ['text', 'mark-bg'], ['chrome-text', 'chrome-bg'], ['chrome-strong', 'chrome-hover'], ['chrome-muted', 'chrome-raised'], ['chrome-muted', 'chrome-bg'],
    ['chrome-strong', 'chrome-control'], ['chrome-accent-contrast', 'chrome-accent'], ['danger-contrast', 'danger']];
$low = [];
foreach (['lyst' => $light, 'mørkt' => $dark] as $name => $t) {
    foreach ($pairs as [$fg, $bg]) {
        if (!isset($t[$fg], $t[$bg])) {
            $low[] = "$name: --$fg/--$bg mangler";
            continue;
        }
        $ratio = f007_contrast($t[$fg], $t[$bg]);
        if ($ratio < 4.5) {
            $low[] = sprintf('%s: --%s på --%s %.2f', $name, $fg, $bg, $ratio);
        }
    }
}
foreach (['av-1', 'av-2', 'av-3', 'av-4', 'av-5', 'av-6', 'av-0'] as $av) {
    if (f007_contrast('#FFFFFF', $light[$av] ?? '#FFFFFF') < 4.5) {
        $low[] = "hvid på --$av";
    }
}
F007Check::that('AK9', $low === [], 'AA-kontrast for ' . (count($pairs) * 2 + 7) . ' par' . ($low !== [] ? ': ' . implode('; ', $low) : ''));
F007Check::that('AK9', !str_contains(strtoupper($css), '#7A8394') && !str_contains(strtoupper($css), '#B4561F'), 'designets for svage farver er væk');

// 320 px: ombrydning og én kolonne
F007Check::that('AK9', substr_count($css, 'overflow-wrap: anywhere') >= 3 && str_contains($css, '@media (max-width: 639px)')
    && str_contains($css, '.call-card { grid-template-columns: minmax(0, 1fr);') && str_contains($css, 'min-width: 0'),
    'CSS: lange ord brydes, kortene er én kolonne under 640 px');
F007Check::done();
