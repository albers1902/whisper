<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F003-testene (ikke selv en test: run.php kører kun test_*.php).
 * MCP-serveren køres direkte (Request ind, Response ud) mod testdatabasen med F001, F002 og F003 tvunget on.
 * Nøgler oprettes i testen med Tokens::create (ingen literaler, der ligner nøgler).
 */

require_once __DIR__ . '/f002_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Mcp\Server;
use Whisper\Mcp\Tokens;
use Whisper\Request;
use Whisper\Response;
use Whisper\Users;

const F003_HOST = 'whisper.test';
const F003_NOW = 1800000000;

final class F003Check
{
    public static int $fails = 0;

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F003-$ak $msg\n";
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

/** Ren database og flag tændt. Returnerer var-mappen (adgangsloggen ligger der). */
function f003_setup(): string
{
    $dir = f002_setup();
    Flags::override(Server::FLAG, true);
    DB::exec('DELETE FROM mcp_rate');
    DB::exec('DELETE FROM mcp_tokens');
    return $dir;
}

/** En indlogget bruger (F001-klient) og dens id. @return array{0: F001Client, 1: int} */
function f003_login(): array
{
    $c = f002_login();
    $id = (int) DB::scalar('SELECT id FROM users WHERE email = ?', ['bruger@f002.test']);
    return [$c, $id];
}

function f003_token(int $userId, string $name = 'Test'): string
{
    return Tokens::create($userId, $name, F003_NOW);
}

final class F003Mcp
{
    public int $now = F003_NOW;
    public Server $server;

    public function __construct(public ?string $token)
    {
        $this->server = new Server(fn(): int => $this->now);
    }

    /** @param array<string, string> $headers  ekstra eller overskrivende headere */
    public function raw(string $method, string $body, array $headers = []): Response
    {
        $h = $headers + ['host' => F003_HOST, 'content-type' => 'application/json'];
        if ($this->token !== null && !isset($h['authorization'])) {
            $h['authorization'] = 'Bearer ' . $this->token;
        }
        return $this->server->handle(new Request($method, '/mcp', [], '10.0.0.9', [], $h, $body));
    }

    /**
     * @param array<mixed>|null $params
     * @param array<string, string> $headers
     */
    public function rpc(string $method, ?array $params = null, array $headers = [], int|string|null $id = 1): Response
    {
        $msg = ['jsonrpc' => '2.0', 'method' => $method];
        if ($id !== null) {
            $msg['id'] = $id;
        }
        if ($params !== null) {
            $msg['params'] = $params;
        }
        return $this->raw('POST', (string) json_encode($msg), $headers + ['mcp-protocol-version' => '2025-11-25']);
    }

    /**
     * Et værktøjskald. Returnerer hele JSON-RPC-svaret som array.
     *
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function tool(string $name, array $args = []): array
    {
        $r = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $args]);
        return f003_json($r);
    }
}

/** @return array<string, mixed> */
function f003_json(Response $r): array
{
    $d = json_decode($r->body, true);
    return is_array($d) ? $d : [];
}

/** Resultatet af et værktøjskald: [isError, tekst, structuredContent]. @return array{0: bool, 1: string, 2: array<string, mixed>|null} */
function f003_result(array $resp): array
{
    $res = is_array($resp['result'] ?? null) ? $resp['result'] : [];
    $text = (string) ($res['content'][0]['text'] ?? '');
    $structured = is_array($res['structuredContent'] ?? null) ? $res['structuredContent'] : null;
    return [($res['isError'] ?? null) === true, $text, $structured];
}

/** Indsætter et færdigt opkald med transcript. @param array<string, mixed> $o */
function f003_call(array $o = [], string $text = 'Hej, jeg ringer om min faktura.'): int
{
    $id = f002_insert($o);
    f002_transcript($id, $text);
    return $id;
}

function f003_ts(): string
{
    return Users::ts(F003_NOW);
}
