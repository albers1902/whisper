<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F001-testene (ikke selv en test: run.php kører kun test_*.php).
 * Testene kører App direkte (Request ind, Response ud) mod testdatabasen med flaget tvunget on — testmiljøet har
 * env = test, hvor [F001_brugere] er off. Flaget selv testes i F001-AK11.
 */

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\Csrf;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Request;
use Whisper\Response;
use Whisper\Session;
use Whisper\Users;

const F001_PW = 'korrekt-hest-batteri';
const F001_T0 = 1800000000;   // fast starttid, så throttling og timeout kan testes uden at vente

final class F001Client
{
    public Session $session;
    public ArraySessionDriver $driver;
    public App $app;
    public int $now = F001_T0;

    public function __construct(public string $ip = '10.0.0.1')
    {
        $this->driver = new ArraySessionDriver();
        $this->session = new Session($this->driver);
        $this->app = new App(fn(): int => $this->now);
    }

    public function get(string $path): Response
    {
        return $this->app->handle(new Request('GET', $path, [], $this->ip), $this->session);
    }

    /** @param array<string, string> $data */
    public function post(string $path, array $data = [], bool $csrf = true): Response
    {
        if ($csrf) {
            $data[Csrf::FIELD] = Csrf::token($this->session);
        }
        return $this->app->handle(new Request('POST', $path, $data, $this->ip), $this->session);
    }

    public function login(string $email, string $password): Response
    {
        $this->get('/login');
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }
}

function f001_setup(): void
{
    Flags::override(App::FLAG, true);
    DB::exec('DELETE FROM login_attempts');
    DB::exec('DELETE FROM users');
}

function f001_user(string $email, string $role = 'user', bool $mustChange = false, string $password = F001_PW): int
{
    return Users::create($email, ucfirst(strtok($email, '@') ?: 'x'), $role, $password, $mustChange, F001_T0);
}

/** @return array<string, mixed> */
function f001_row(int $id): array
{
    return Users::find($id) ?? [];
}

/** Engangskoden fra siden "Bruger oprettet" / "Adgangskode nulstillet". */
function f001_otp(Response $r): string
{
    return preg_match('/id="engangskode" type="text" readonly value="([^"]+)"/', $r->body, $m) === 1 ? html_entity_decode($m[1]) : '';
}

final class F001Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F001-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
        }
    }

    public static function done(): never
    {
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}
