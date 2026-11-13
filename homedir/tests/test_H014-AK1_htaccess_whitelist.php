<?php
declare(strict_types=1);

// H014-AK1/AK3: public_html/.htaccess må kun bruge direktiver, som serveren tillader. DEV's AllowOverride tillader kun
// rewrite; et andet direktiv (fx AddType, Header) eller en <...>-blok giver 500 på hele sitet, også /health, og så
// ruller deploy tilbage. php -S ignorerer .htaccess, så kun denne statiske test fanger det før DEV.
// Udvides listen, skal sysadmin først bekræfte, at direktivet er tilladt på alle miljøer.

const H014_WHITELIST = ['RewriteEngine', 'RewriteRule', 'RewriteCond'];

$fails = 0;
$check = static function (string $ak, bool $cond, string $msg) use (&$fails): void {
    echo ($cond ? 'ok:   ' : 'FAIL: ') . "H014-$ak $msg\n";
    if (!$cond) {
        $fails++;
    }
};

$path = __DIR__ . '/../public_html/.htaccess';
$lines = file($path, FILE_IGNORE_NEW_LINES);
$check('AK1', is_array($lines) && $lines !== [], '.htaccess findes og kan læses');

$bad = [];
foreach ($lines ?: [] as $n => $line) {
    $t = trim($line);
    if ($t === '' || str_starts_with($t, '#')) {
        continue;
    }
    $directive = (string) preg_split('/\s+/', $t)[0];
    if (str_starts_with($t, '<') || !in_array($directive, H014_WHITELIST, true)) {
        $bad[] = ($n + 1) . ': ' . $t;
    }
}
$check('AK1', $bad === [], 'kun ' . implode(', ', H014_WHITELIST) . ' og ingen <...>-blokke (' . implode(' | ', $bad) . ')');

$ht = implode("\n", $lines ?: []);
$check('AK3', str_contains($ht, 'RewriteRule ^health/?$ health.php [L]') && str_contains($ht, 'RewriteRule ^ index.php [L]'),
    '/health- og front-controller-reglerne er der stadig');

echo $fails === 0 ? "alle tjek bestået\n" : "$fails tjek fejlede\n";
exit($fails === 0 ? 0 : 1);
