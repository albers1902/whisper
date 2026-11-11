<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

use DateTimeImmutable;
use DateTimeZone;

/**
 * [zendesk] fra config.ini (aldrig i git). Kun read-scopes: et tomt scope ville give fuld læse- OG skriveadgang
 * (Zendesk: udeladt scope = alt), så det afvises, ligesom ethvert scope, der ikke er "read" eller "<ressource>:read".
 *
 *   subdomain = trygenergidanmarkhelp
 *   client_id = …
 *   client_secret = …
 *   since = 2026-10-01
 *   scope = read                              (valgfri; standard read)
 *   recording_hosts = api.twilio.com          (valgfri; ekstra værter, en optagelse må hentes fra/redirectes til, uden login)
 */
final class ZendeskConfig
{
    /** @param list<string> $recordingHosts */
    public function __construct(
        public readonly string $subdomain,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $scope,
        public readonly int $since,
        public readonly array $recordingHosts = [],
    ) {
    }

    /**
     * @param array<string, mixed> $s
     * @return self|null null = sektionen mangler (Zendesk er ikke sat op)
     * @throws ZendeskError ved en ugyldig sektion (beskeden nævner aldrig værdierne)
     */
    public static function fromSection(array $s): ?self
    {
        if ($s === []) {
            return null;
        }
        $str = static fn(string $k): string => is_scalar($s[$k] ?? null) ? trim((string) $s[$k]) : '';
        $sub = strtolower($str('subdomain'));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $sub) !== 1) {
            throw new ZendeskError('[zendesk] subdomain mangler eller er ugyldig');
        }
        if ($str('client_id') === '' || $str('client_secret') === '') {
            throw new ZendeskError('[zendesk] client_id eller client_secret mangler');
        }
        $scope = array_key_exists('scope', $s) ? $str('scope') : 'read';
        self::checkScope($scope);
        $since = DateTimeImmutable::createFromFormat('!Y-m-d', $str('since'), new DateTimeZone('UTC'));
        if ($since === false) {
            throw new ZendeskError('[zendesk] since mangler eller er ikke ÅÅÅÅ-MM-DD');
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($str('recording_hosts'))) ?: [] as $h) {
            if ($h === '') {
                continue;
            }
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h) !== 1) {
                throw new ZendeskError('[zendesk] recording_hosts indeholder en ugyldig vært');
            }
            $hosts[] = $h;
        }
        return new self($sub, $str('client_id'), $str('client_secret'), $scope, $since->getTimestamp(), $hosts);
    }

    /** @throws ZendeskError */
    public static function checkScope(string $scope): void
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            throw new ZendeskError('[zendesk] scope er tomt — et tomt scope giver skriveadgang og afvises');
        }
        foreach ($parts as $p) {
            if ($p !== 'read' && preg_match('/^[a-z_]+:read$/', $p) !== 1) {
                throw new ZendeskError('[zendesk] scope må kun indeholde read-scopes');
            }
        }
    }

    public function host(): string
    {
        return $this->subdomain . '.zendesk.com';
    }

    public function baseUrl(): string
    {
        return 'https://' . $this->host();
    }

    public function ticketUrl(int $ticketId): string
    {
        return $this->baseUrl() . '/agent/tickets/' . $ticketId;
    }
}
