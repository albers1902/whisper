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
 *   sync_since = 2026-11-12    (valgfri; F004: hentningens start, dansk midnat. since er probens og bruges ikke af sync)
 *   lag_minutes = 10           (valgfri; F004: så langt bag nu ligger det seneste optagelsesvindue, 1–120)
 *   filter_tz = Europe/Copenhagen (valgfri; H009: zonen, Adversus læser filtrenes tidscifre i — se filterTime)
 */
final class AdversusConfig
{
    /** F004 (DECISIONS #31): uden sync_since starter hentningen 2026-11-12 00:00 dansk tid */
    public const SYNC_SINCE_DEFAULT = '2026-11-12';
    /**
     * CDR-cursoren (/calls sorteret på start) går kun til nu − lag, så et opkald normalt er logget, når det hentes. Et
     * opkald, der logges senere (fx et langt opkald), hentes af Sync's kontrol pr. time (H013). Kan sættes i config.
     */
    public const LAG_DEFAULT = 600;
    /**
     * Adversus læser cifrene i et tidsfilter som væg-tid i kontoens zone og ignorerer "Z", men svarer i UTC med "Z"
     * (målt med H009-diagnosen på DEV 2026-11-12: /recordings med $gt 15:54:42Z gav upload-tider fra 14:55:04Z).
     * UTC er nødvejen, hvis Adversus en dag respekterer "Z".
     */
    public const FILTER_TZ_DEFAULT = 'Europe/Copenhagen';

    public readonly int $syncSince;
    public readonly int $lag;
    public readonly DateTimeZone $filterTz;

    /** @param list<string> $recordingHosts */
    public function __construct(
        public readonly string $username,
        public readonly string $password,
        public readonly int $since,
        public readonly array $recordingHosts = [],
        public readonly string $successStatus = 'success',
        ?int $syncSince = null,
        ?int $lag = null,
        ?DateTimeZone $filterTz = null,
    ) {
        $this->syncSince = $syncSince ?? (int) self::localMidnight(self::SYNC_SINCE_DEFAULT);
        $this->lag = $lag ?? self::LAG_DEFAULT;
        $this->filterTz = $filterTz ?? new DateTimeZone(self::FILTER_TZ_DEFAULT);
    }

    /**
     * Et tidspunkt til Adversus' filtre: væg-tid i filter_tz med det suffiks ("Z"), som er målt accepteret. Sommertid
     * følger af DateTimeZone. Kun til filtre — svar-tider læses med Sync::epoch().
     */
    public function filterTime(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))->setTimezone($this->filterTz)->format('Y-m-d\TH:i:s') . 'Z';
    }

    /** ÅÅÅÅ-MM-DD som epoch for midnat i Europe/Copenhagen; null ved en ugyldig dato. */
    public static function localMidnight(string $ymd): ?int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('Europe/Copenhagen'));
        return $d === false || $d->format('Y-m-d') !== $ymd ? null : $d->getTimestamp();
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
        $syncSince = null;
        if ($str('sync_since') !== '') {
            $syncSince = self::localMidnight($str('sync_since'));
            if ($syncSince === null) {
                throw new AdversusError('[adversus] sync_since er ikke ÅÅÅÅ-MM-DD');
            }
        }
        $lag = null;
        if ($str('lag_minutes') !== '') {
            if (!ctype_digit($str('lag_minutes')) || (int) $str('lag_minutes') < 1 || (int) $str('lag_minutes') > 120) {
                throw new AdversusError('[adversus] lag_minutes skal være et helt tal fra 1 til 120');
            }
            $lag = (int) $str('lag_minutes') * 60;
        }
        $tz = null;
        if ($str('filter_tz') !== '') {
            try {
                $tz = new DateTimeZone($str('filter_tz'));
            } catch (\Exception) {
                throw new AdversusError('[adversus] filter_tz er ikke en gyldig tidszone (fx Europe/Copenhagen eller UTC)');
            }
        }
        return new self($str('username'), $str('password'), $since->getTimestamp(), $hosts, $success, $syncSince, $lag, $tz);
    }

    /** Datoen som ISO 8601 til Adversus' filtre. */
    public function sinceIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->since);
    }
}
