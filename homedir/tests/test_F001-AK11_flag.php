<?php
declare(strict_types=1);

// F001-AK11: med [F001_brugere] slukket for miljøet svarer /, /login, /brugere og /konto 404; /health er uændret.

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/f001_lib.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Health;
use Whisper\Request;
use Whisper\Session;

Flags::override(App::FLAG, null);   // ingen tvang: testmiljøets egen config (env = test) og features.ini

F001Check::that('AK11', Flags::env() === 'test', 'testmiljøet har env = test (fik "' . Flags::env() . '")');
F001Check::that('AK11', Flags::enabledIn(App::FLAG, 'dev') === true, 'features.ini: dev = on');
F001Check::that('AK11', Flags::enabledIn(App::FLAG, 'test') === false, 'features.ini: test = off');
F001Check::that('AK11', Flags::enabledIn(App::FLAG, 'stable') === false, 'features.ini: stable = off');
F001Check::that('AK11', Flags::enabledIn(App::FLAG, '') === false, 'ukendt miljø: off');
F001Check::that('AK11', Flags::enabledIn('F999_findes_ikke', 'dev') === false, 'ukendt flag: off');
foreach (['development' => 'dev', 'production' => 'stable', ' DEV ' => 'dev', 'Stable' => 'stable', 'test' => 'test', '' => '', 'prod' => ''] as $in => $want) {
    F001Check::that('AK11', Flags::normalizeEnv($in) === $want, "env \"$in\" → \"$want\"");
}
F001Check::that('AK11', Flags::enabled(App::FLAG) === false, 'flaget er slukket i testmiljøet');

$s = new Session(new ArraySessionDriver());
$app = new App();
foreach ([['GET', '/'], ['GET', '/login'], ['POST', '/login'], ['GET', '/brugere'], ['GET', '/konto'], ['GET', '/brugere/ny'], ['POST', '/logout']] as [$m, $p]) {
    $r = $app->handle(new Request($m, $p, ['email' => 'a@b.dk', 'password' => 'x'], '10.0.0.1'), $s);
    F001Check::that('AK11', $r->status === 404, "flag off: $m $p → 404 (fik {$r->status})");
}
F001Check::that('AK11', $s->all() === [], 'flag off: sessionen røres ikke');

// Med flaget tændt svarer de samme ruter (så 404 ovenfor skyldes flaget)
Flags::override(App::FLAG, true);
F001Check::that('AK11', $app->handle(new Request('GET', '/login'), $s)->status === 200, 'flag on: GET /login → 200');
Flags::override(App::FLAG, false);

$rep = Health::report(sys_get_temp_dir(), static fn(): PDO => DB::pdo());
F001Check::that('AK11', $rep['status'] === 'ok', '/health-rapporten er ok med flaget slukket');
$src = (string) file_get_contents(__DIR__ . '/../public_html/index.php');
F001Check::that('AK11', strpos($src, 'Flags::enabled(App::FLAG)') < strpos($src, 'NativeSessionDriver::start'), 'index.php: flaget tjekkes før sessionen startes');

F001Check::done();
