<?php
declare(strict_types=1);

/**
 * Testløber for frameworkets CI (coworker.ini [ci] tests). Køres fra repoets rod:
 *
 *   php homedir/tests/run.php            -> junit.xml i den aktuelle mappe
 *
 * Hvert test_*.php i homedir/tests/ køres i sin egen PHP-proces og er ÉN test-case (`tests::<filnavn>`), så
 * tests/known-failures.txt kan ramme præcis den. Exit 0 = bestået; exit 0 med en linje "SKIP…" = sprunget over
 * (CI tæller det som rødt); alt andet = fejlet.
 *
 * Databasen kommer KUN fra CW_DB_HOST/PORT/NAME/USER/PASS (cron/ci.sh giver en tom testdatabase med alle
 * migrationer). Løberen skriver en test-config i homedir/config/config.ini og sletter den bagefter — og nægter,
 * hvis der allerede ligger en rigtig config.ini (så rammer testene aldrig en server-database).
 * Alle HTTP(S)-proxyvariabler peger på en lukket port, så intet sendes ud af huset.
 */

namespace Whisper\Tests;

const MARKER = '; whisper-testrunner: genereret af homedir/tests/run.php — slettes efter testkørslen';
const DEAD_ENDPOINT = 'http://127.0.0.1:9';
const TIMEOUT_SECONDS = 300;

/** @return list<string> testscripts (fulde stier), sorteret */
function discover(string $testsDir): array
{
    $files = glob($testsDir . '/test_*.php') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function testConfig(): string
{
    $q = static fn(string $k, string $default = ''): string
        => '"' . str_replace(['"', "\n", "\r"], '', (string) (getenv($k) ?: $default)) . '"';
    return implode("\n", [
        MARKER,
        '[database]',
        'host = ' . $q('CW_DB_HOST'),
        'port = ' . $q('CW_DB_PORT', '3306'),
        'name = ' . $q('CW_DB_NAME'),
        'user = ' . $q('CW_DB_USER'),
        'pass = ' . $q('CW_DB_PASS'),
        '',
        '[app]',
        'name = "Whisper test"',
        'env = "test"',
        '',
    ]) . "\n";
}

/** @return array<string, string> miljøet til hvert testscript */
function childEnv(): array
{
    $env = getenv();
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $k) {
        $env[$k] = DEAD_ENDPOINT;
    }
    $env['NO_PROXY'] = '';
    $env['no_proxy'] = '';
    $env['WHISPER_TEST'] = '1';
    return $env;
}

/**
 * @param array<string, string> $env
 * @return array{status: string, output: string, seconds: float}
 */
function runOne(string $file, string $cwd, array $env): array
{
    $outFile = (string) tempnam(sys_get_temp_dir(), 'wst');
    $start = microtime(true);
    $proc = proc_open([PHP_BINARY, $file], [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'a']],
        $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return ['status' => 'failure', 'output' => 'kunne ikke starte PHP', 'seconds' => 0.0];
    }
    fclose($pipes[0]);   // ingen input: et script, der venter på tastatur, får EOF
    $code = -1;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $code = $st['exitcode'];
            break;
        }
        if (microtime(true) - $start > TIMEOUT_SECONDS) {
            proc_terminate($proc, 9);
            $code = -2;
            break;
        }
        usleep(50000);
    }
    proc_close($proc);
    $output = (string) @file_get_contents($outFile);
    @unlink($outFile);
    if (strlen($output) > 65536) {
        $output = "…\n" . substr($output, -65536);
    }
    if ($code === -2) {
        $status = 'failure';
        $output .= "\nTIMEOUT efter " . TIMEOUT_SECONDS . ' s';
    } elseif ($code !== 0) {
        $status = 'failure';
        $output .= "\nexit $code";
    } elseif (preg_match('/^\s*SKIP\b/mi', $output) === 1) {
        $status = 'skipped';
    } else {
        $status = 'passed';
    }
    return ['status' => $status, 'output' => $output, 'seconds' => microtime(true) - $start];
}

/** @param list<array{name: string, status: string, output: string, seconds: float}> $cases */
function junit(array $cases): string
{
    $x = new \XMLWriter();
    $x->openMemory();
    $x->setIndent(true);
    $x->startDocument('1.0', 'UTF-8');
    $x->startElement('testsuites');
    $x->startElement('testsuite');
    $x->writeAttribute('name', 'whisper');
    $x->writeAttribute('tests', (string) count($cases));
    $x->writeAttribute('failures', (string) count(array_filter($cases, fn($c) => $c['status'] === 'failure')));
    $x->writeAttribute('skipped', (string) count(array_filter($cases, fn($c) => $c['status'] === 'skipped')));
    $x->writeAttribute('time', sprintf('%.3f', array_sum(array_column($cases, 'seconds'))));
    foreach ($cases as $c) {
        $x->startElement('testcase');
        $x->writeAttribute('classname', 'tests');
        $x->writeAttribute('name', $c['name']);
        $x->writeAttribute('time', sprintf('%.3f', $c['seconds']));
        $clean = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '?',
            mb_convert_encoding($c['output'], 'UTF-8', 'UTF-8'));
        if ($c['status'] === 'failure') {
            $x->startElement('failure');
            $x->writeAttribute('message', 'testscriptet fejlede');
            $x->text($clean);
            $x->endElement();
        } elseif ($c['status'] === 'skipped') {
            $x->startElement('skipped');
            $x->writeAttribute('message', 'testscriptet sprang over');
            $x->endElement();
        }
        $x->writeElement('system-out', $clean);
        $x->endElement();
    }
    $x->endElement();
    $x->endElement();
    $x->endDocument();
    return $x->outputMemory();
}

/**
 * Lægger test-configen i <home>/config/config.ini — den sti, appen altid læser (src/Config.php).
 * Fejler hårdt i stedet for at lade testene køre uden config.
 *
 * @return array{ok: bool, path: string, error: string}
 */
function prepareConfig(string $home): array
{
    $cfgPath = $home . '/config/config.ini';
    $res = ['ok' => false, 'path' => $cfgPath, 'error' => ''];
    if (is_file($cfgPath) && !str_starts_with((string) file_get_contents($cfgPath), MARKER)) {
        $res['error'] = 'homedir/config/config.ini findes allerede (rigtig config) — testløberen nægter';
        return $res;
    }
    $content = testConfig();
    if (@file_put_contents($cfgPath, $content) !== strlen($content)) {
        $res['error'] = "kunne ikke skrive test-configen til $cfgPath";
        return $res;
    }
    $res['ok'] = true;
    return $res;
}

/** Sletter kun testløberens egen config — en rigtig config røres aldrig. */
function cleanupConfig(string $cfgPath): void
{
    if (is_file($cfgPath) && str_starts_with((string) file_get_contents($cfgPath), MARKER)) {
        unlink($cfgPath);
    }
}

function main(): int
{
    $home = dirname(__DIR__);
    $junitPath = getcwd() . '/junit.xml';

    foreach (['CW_DB_HOST', 'CW_DB_NAME', 'CW_DB_USER'] as $k) {
        if ((string) getenv($k) === '') {
            fwrite(STDERR, "run.php: $k er ikke sat — test, der bruger databasen, vil fejle.\n");
        }
    }
    $prep = prepareConfig($home);
    if (!$prep['ok']) {
        fwrite(STDERR, "run.php: {$prep['error']}\n");
        file_put_contents($junitPath, junit([['name' => 'runner_config', 'status' => 'failure',
            'output' => $prep['error'], 'seconds' => 0.0]]));
        return 1;
    }
    register_shutdown_function(static function () use ($prep): void {
        cleanupConfig($prep['path']);
    });

    $cases = [];
    $env = childEnv();
    foreach (discover(__DIR__) as $file) {
        $name = basename($file, '.php');
        $r = runOne($file, $home, $env);
        printf("%-8s %s (%.1f s)\n", strtoupper($r['status']), $name, $r['seconds']);
        if ($r['status'] !== 'passed') {
            echo preg_replace('/^/m', '    ', rtrim(substr($r['output'], -2000))), "\n";
        }
        $cases[] = ['name' => $name] + $r;
    }
    file_put_contents($junitPath, junit($cases));
    $bad = count(array_filter($cases, fn($c) => $c['status'] !== 'passed'));
    printf("%d tests, %d ikke bestået — %s\n", count($cases), $bad, $junitPath);
    return ($cases !== [] && $bad === 0) ? 0 : 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
