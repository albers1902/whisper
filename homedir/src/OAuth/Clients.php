<?php
declare(strict_types=1);

namespace Whisper\OAuth;

use Whisper\DB;
use Whisper\Users;

/**
 * Dynamisk klientregistrering (RFC 7591), kun public clients. Registreringen er åben for alle (Claude registrerer en
 * ny klient ved hver ny forbindelse), så værnet er allowlisten på redirect-URI'er, et loft pr. IP (IPv6 pr. /64), og
 * at klienter uden bevilling ryddes efter 24 timer. Det samlede loft afviser aldrig: det fortrænger de ældste ubrugte
 * klienter, så ingen kan blokere registreringen for andre (QA F1, security S1).
 */
final class Clients
{
    /** Pr. IP i timen. Rummelig: al Claude-trafik kommer fra Anthropics 160.79.104.0/21. */
    public const PER_IP_HOUR = 30;
    /** Klienter uden bevilling i alt; den ældste fortrænges, når loftet nås. */
    public const MAX_UNUSED = 500;
    /** En legitim klient får sin bevilling inden for minutter efter registreringen. */
    public const UNUSED_HOURS = 24;
    /** Bevillinger uden levende tokens slettes så længe efter oprettelsen. */
    public const GRANT_KEEP_DAYS = 30;
    public const MAX_REDIRECTS = 5;
    private const COUNT_UNUSED = 'SELECT COUNT(*) FROM oauth_clients c WHERE NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_pk = c.id)';
    public const UNNAMED = 'Ukendt app';

    /**
     * Validerer en registrering. Returnerer de normaliserede felter eller en RFC 7591 §3.2.2-fejl.
     *
     * @param array<mixed> $meta
     * @return array{ok: true, name: string, redirect_uris: list<string>}|array{ok: false, error: string, description: string}
     */
    public static function validate(array $meta): array
    {
        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > self::MAX_REDIRECTS) {
            return self::fail('invalid_redirect_uri', 'redirect_uris skal være en liste med 1-' . self::MAX_REDIRECTS . ' adresser.');
        }
        $clean = [];
        foreach ($uris as $u) {
            if (!is_string($u) || !OAuth::redirectAllowed($u)) {
                return self::fail('invalid_redirect_uri', 'redirect_uri er ikke tilladt.');
            }
            $clean[] = $u;
        }
        $auth = $meta['token_endpoint_auth_method'] ?? 'none';
        if ($auth !== 'none') {
            return self::fail('invalid_client_metadata', 'Kun token_endpoint_auth_method "none" (public client) understøttes.');
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $key => $allowed) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $v = $meta[$key];
            if (!is_array($v) || !array_is_list($v) || array_diff($v, $allowed) !== []) {
                return self::fail('invalid_client_metadata', $key . ' understøttes ikke.');
            }
        }
        $name = $meta['client_name'] ?? '';
        $name = is_string($name) ? OAuth::cleanName($name) : '';
        return ['ok' => true, 'name' => $name, 'redirect_uris' => array_values(array_unique($clean))];
    }

    /** @return array{ok: false, error: string, description: string} */
    private static function fail(string $error, string $description): array
    {
        return ['ok' => false, 'error' => $error, 'description' => $description];
    }

    /** true, når IP'en (IPv6: dens /64) har registreret PER_IP_HOUR klienter den seneste time. */
    public static function limited(string $ip, int $now): bool
    {
        $n = (int) DB::scalar('SELECT COUNT(*) FROM oauth_clients WHERE created_ip = ? AND created_at >= ?', [OAuth::ipKey($ip), Users::ts($now - 3600)]);
        return $n >= self::PER_IP_HOUR;
    }

    /**
     * Gør plads til én ny klient: ryd op, og er der stadig MAX_UNUSED klienter uden bevilling, slettes de ældste af dem.
     * En klient med en bevilling røres aldrig. Returnerer antallet af fortrængte klienter.
     */
    public static function makeRoom(int $now): int
    {
        $count = (int) DB::scalar(self::COUNT_UNUSED);
        if ($count < self::MAX_UNUSED) {
            return 0;
        }
        self::purge($now);   // altid, når loftet rammes (QA F1): måske er der allerede plads
        $evicted = 0;
        $count = (int) DB::scalar(self::COUNT_UNUSED);
        while ($count - $evicted >= self::MAX_UNUSED && $evicted < 100) {
            $id = DB::scalar('SELECT c.id FROM oauth_clients c WHERE NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_pk = c.id) '
                . 'ORDER BY c.created_at, c.id LIMIT 1');
            if ($id === false) {
                break;
            }
            DB::exec('DELETE FROM oauth_clients WHERE id = ? AND NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_pk = oauth_clients.id)',
                [(int) $id]);
            $evicted++;
        }
        return $evicted;
    }

    /**
     * @param list<string> $redirectUris
     * @return array{id: int, client_id: string}
     */
    public static function create(string $name, array $redirectUris, string $ip, int $now): array
    {
        $clientId = OAuth::random('wci_', 16);
        DB::exec('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at, created_ip) VALUES (?, ?, ?, ?, ?)',
            [$clientId, $name, (string) json_encode($redirectUris, JSON_UNESCAPED_SLASHES), Users::ts($now), OAuth::ipKey($ip)]);
        return ['id' => (int) DB::pdo()->lastInsertId(), 'client_id' => $clientId];
    }

    /**
     * @return array{id: int, client_id: string, name: string, redirect_uris: list<string>}|null
     */
    public static function find(string $clientId): ?array
    {
        if (preg_match('/^wci_[A-Za-z0-9_-]{22}$/', $clientId) !== 1) {
            return null;   // forkert form: intet databaseopslag
        }
        $rows = DB::all('SELECT id, client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($rows === [] || !hash_equals((string) $rows[0]['client_id'], $clientId)) {
            return null;
        }
        $uris = json_decode((string) $rows[0]['redirect_uris'], true);
        $list = [];
        foreach (is_array($uris) ? $uris : [] as $u) {
            if (is_string($u)) {
                $list[] = $u;
            }
        }
        return ['id' => (int) $rows[0]['id'], 'client_id' => $clientId, 'name' => (string) $rows[0]['client_name'], 'redirect_uris' => $list];
    }

    public static function touch(int $id, int $now): void
    {
        DB::exec('UPDATE oauth_clients SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [Users::ts($now), $id, Users::ts($now - 60)]);
    }

    /**
     * Oprydning (kaldes af og til fra /oauth/register og /oauth/token, ingen cron): udløbne koder og tokens,
     * bevillinger uden levende tokens efter 30 dage og klienter, der ikke har fået en bevilling inden for 24 timer.
     */
    public static function purge(int $now): void
    {
        $grantCut = Users::ts($now - self::GRANT_KEEP_DAYS * 86400);
        DB::exec('DELETE FROM oauth_codes WHERE expires_at < ?', [Users::ts($now - 3600)]);
        DB::exec('DELETE FROM oauth_tokens WHERE expires_at < ?', [Users::ts($now - 86400)]);
        DB::exec('DELETE FROM oauth_grants WHERE created_at < ? AND NOT EXISTS '
            . '(SELECT 1 FROM oauth_tokens t WHERE t.grant_id = oauth_grants.id AND t.expires_at > ?)', [$grantCut, Users::ts($now)]);
        DB::exec('DELETE FROM oauth_clients WHERE created_at < ? AND NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_pk = oauth_clients.id)',
            [Users::ts($now - self::UNUSED_HOURS * 3600)]);
        DB::exec('DELETE FROM oauth_rate WHERE window_start < ?', [$now - 3600]);
    }
}
