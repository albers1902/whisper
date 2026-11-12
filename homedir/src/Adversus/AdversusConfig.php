<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use DateTimeImmutable;
use DateTimeZone;

/**
 * [adversus] fra config.ini (aldrig i git). Adversus bruger HTTP Basic auth med en API-bruger
 * (api.adversus.io/swagger/apinew.json: securityDefinitions.basicAuth).
 *
 *   username = <API-brugernavn>
 *   password = <API-adgangskode>
 *   since = 2026-10-01
 *   recording_hosts = …        (valgfri; værter, optagelsens 307-redirect må gå til — hentes uden login)
 *   success_status = success   (valgfri; lead-status for en succesfuld registrering — kun de leads transcriberes)
 */
final class AdversusConfig
{
    /** @param list<string> $recordingHosts */
    public function __construct(
        public readonly string $username,
        public readonly string $password,
        public readonly int $since,
        public readonly array $recordingHosts = [],
        public readonly string $successStatus = 'success',
    ) {
    }

    /**
     * @param array<string, mixed> $s
     * @return self|null null = sektionen mangler (Adversus er ikke sat op)
     * @throws AdversusError ved en ugyldig sektion (beskeden nævner aldrig værdierne)
     */
    public static function fromSection(array $s): ?self
    {
        if ($s === []) {
            return null;
        }
        $str = static fn(string $k): string => is_scalar($s[$k] ?? null) ? trim((string) $s[$k]) : '';
        if ($str('username') === '' || $str('password') === '') {
            throw new AdversusError('[adversus] username eller password mangler');
        }
        // RFC 7617: bruger-id'et i Basic auth må ikke indeholde kolon (det skiller bruger og adgangskode)
        if (str_contains($str('username'), ':')) {
            throw new AdversusError('[adversus] username må ikke indeholde kolon');
        }
        $since = DateTimeImmutable::createFromFormat('!Y-m-d', $str('since'), new DateTimeZone('UTC'));
        if ($since === false || $since->format('Y-m-d') !== $str('since')) {
            throw new AdversusError('[adversus] since mangler eller er ikke ÅÅÅÅ-MM-DD');
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($str('recording_hosts'))) ?: [] as $h) {
            if ($h === '') {
                continue;
            }
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h) !== 1) {
                throw new AdversusError('[adversus] recording_hosts indeholder en ugyldig vært');
            }
            $hosts[] = $h;
        }
        // Værdien sendes i et JSON-filter og vises i output, så kun et kort enum-lignende ord er tilladt
        $success = $str('success_status') === '' ? 'success' : $str('success_status');
        if (preg_match('/^[A-Za-z_-]{1,40}$/D', $success) !== 1) {
            throw new AdversusError('[adversus] success_status er ugyldig (kun bogstaver, _ og -, højst 40 tegn)');
        }
        return new self($str('username'), $str('password'), $since->getTimestamp(), $hosts, $success);
    }

    /** Datoen som ISO 8601 til Adversus' filtre. */
    public function sinceIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->since);
    }
}
