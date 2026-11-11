<!-- FILE_VERSION: 1.1 — 2026-10-30 (.user.ini-påstanden og restart rettet efter
     aendringer/GENNEMGANG-2026-10-26.md). Ejes af Sysadmin-Lead. Leveres til coworker-development
     som shared/MASKINER.md. Redigér den ikke i et projekt: er noget forkert, meld det som
     et fund, så rettes det her og kommer tilbage til alle projekter. -->
# Maskinerne — målt, med dato

Alt herunder er målt på maskinen på den dato der står. Hvad der **ikke** er målt, står i
afsnittet til sidst. Er du i tvivl om et tal, så mål det; en linje her er et øjebliksbillede,
ikke en garanti.

**Der er tre maskiner. Ikke tredive.** Collector-arkitektur, flåderul og centraliseret
konfigurationsstyring er for tidligt. Det der betyder noget er antal sites pr. maskine.

| Rolle | Vært | Navn | IP |
|---|---|---|---|
| Drift | `llm.xanouso.com` | `vmi3572851` | 169.58.51.76 |
| Prøvebane | `dev.xanouso.com` | `vmi3661273` | 84.46.252.242 |
| Afvikles | (gammel CWP) | `vmi2529402` | 5.189.146.156 |

---

## Drift — `vmi3572851` / llm.xanouso.com

Ubuntu 24.04. Apache 2.4 mpm_event, ingen `mod_php`, én PHP-FPM-pulje pr. site kørende som
sitets egen bruger. Bootstrap fuldført (stempel: `/var/lib/sitectl/bootstrap-fuldfoert`).

| | Målt |
|---|---|
| PHP-FPM | 8.2.34, 8.3.35, 8.4.26 (ondrej/php-PPA, `+deb.sury.org+1`) — 2026-10-21 |
| MariaDB | **10.11.14**, EOL 2028-02-16 — 2026-10-19 |
| `collation_server` | `utf8mb4_general_ci` — 2026-10-19 |
| Overvågning | Netdata installeret, tilsluttet Netdata Cloud; data bliver på maskinen — 2026-10-19 |
| Backup | `site-backup` i drift, off-site til `rclone:gdrive:backup-vmi3572851` (restic) — 2026-10-19 |
| Docker / containerd | **fjernet** (var installeret og ubrugt siden dag ét) — 2026-10-19 |
| Sites i drift | `envoyagent`, `econsync`, `storix`, `cloud` |

**MariaDB er 10.11 her, ikke 12.3.** Det er den væsentligste forskel til dev. Opgradering
til 12.3 ligger efter `sitectl` 1.2. Byg ikke på 12.3-adfærd i et site der skal i drift.

**`cloud` (Nextcloud) er ikke startet.**

## Prøvebane — `vmi3661273` / dev.xanouso.com

Ubuntu 24.04, sat op som drift-maskinen, men med nyere MariaDB. **Intet i drift.** Her
prøves alt først.

| | Målt |
|---|---|
| PHP-FPM | 8.2.34, 8.3.35, 8.4.26, **8.5.11** — 2026-10-21 |
| MariaDB | **12.3.3**, EOL 2029-06-12 — 2026-10-19 |
| `collation_server` | `utf8mb4_general_ci` — 2026-10-19 |
| Overvågning | Netdata installeret — 2026-10-19 |

**Hvad dev IKKE har** (menneskets beslutning 2026-10-19, dækket af et snapshot): ingen
cron, ingen kanariefugl, ingen alarmvej, ingen off-site backup. Et site på dev er altså
ikke overvåget. Regn ikke med at nogen får besked, hvis noget går ned her.

MaxScale-repoet er tilføjet og ubrugt; det skal fjernes.

## Afvikles — `vmi2529402` / gammel CWP, 5.189.146.156

Rocky / EL8 med CWP-panel. **Den er ikke væk, og den kører stadig 57 sites.** Primært dev i
dag; sitene flyttes til `dev.xanouso.com`, og maskinen ryddes op og slettes til sidst.
`chatio.xanouso.com` flyttes til drift.

| | Målt 2026-10-21 |
|---|---|
| Hvad der betjener sitene | **alt-php 8.2.28** — CWP's egen, `/opt/alt/php-fpm82/`, kan ikke opdateres med `dnf` |
| Alle 57 vhosts | `proxy:unix:/opt/alt/php-fpm82/usr/var/sockets/<site>.sock` |
| `mod_php` | findes ikke |
| remi's `php82-php-fpm` | kører, men lytter **kun** på `/var/opt/remi/php82/run/php-fpm/www.sock`. Ingen TCP-lytter, intet vhost og ingen CWP-konfiguration peger på den. Står urørt |
| Panelets PHP 7.1 | to hovedprocesser (`cwp-phpfpm`, `cwpsrv-phpfpm`). Panelets porte er lukket til ejeren alene |

Kontraktens gamle formulering "CWP og Rocky er væk" var forkert. `dnf` og CWP-terminalen
gælder stadig **her** — og kun her.

---

## Sikkerhedstilstand, 2026-10-22

### PHP2Shell (CVE-2026-109618, CVE-2026-109619, CVSS 9.2)

Rettet opstrøms i PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12, udgivet 2026-10-21.

**Ingen af de tre maskiner er opdateret.** Alle tre har den midlertidige afværgelse
`session.upload_progress.enabled = 0`, skrevet i FPM's scan-mappe (ikke `ini_set()`, som
læses for sent).

**Afværgelsen kan omgås af sitet selv.** Indstillingen er `PHP_INI_PERDIR`, og det er
målt 2026-10-26 (fikstur, ikke på maskinerne): en `.user.ini` i sitets docroot sætter den
tilbage til `1` inden for `user_ini.cache_ttl` (300 s), og ét kald med `PHP_VALUE` som
FastCGI-parameter forgifter arbejderen permanent (6 af 6 kald bagefter). Enhver
site-bruger kan altså genåbne CVE-2026-109619 for sit eget site. Den tidligere tekst her
sagde at `.user.ini` "læses for sent" — det var forkert.

```
drift:   /etc/php/{8.2,8.3,8.4}/fpm/conf.d/98-php2shell.ini
dev:     /etc/php/{8.2,8.3,8.4,8.5}/fpm/conf.d/98-php2shell.ini
CWP:     /opt/alt/php-fpm82/usr/php/php.d/98-php2shell.ini
```

Verificeret 2026-10-21: værdien er `Off` i FPM's egen ini-stak på alle fire/tre versioner
pr. maskine, ingen pulje overskriver den, og hver hovedproces er genstartet efter filen
blev skrevet. (`restart` var mere end nødvendigt — `reload` havde været nok, målt
2026-10-26. Se `GENNEMGANG.md` afsnit 5.)

**Det lukker CVE-2026-109619. Det lukker ikke CVE-2026-109618.** Den sidste kræver
`memory_limit = -1` sammen med en cgroup-grænse på FPM-servicen, og det er bevidst **ikke**
sat: uden en korrekt cgroup-grænse flytter man en fejl på ét site til at maskinen går ned.
Risikoen lukker først med en rigtig opdatering.

Opdateringen afventer at ondrej's PPA på Launchpad bygger de rettede versioner. Målt
2026-10-21 kl. 19.41: `apt-get update` kørte rent, og kandidaten var stadig den gamle.
`packages.sury.org` havde dem; PPA'en ikke. Maskinerne skifter **ikke** repo for at komme
foran — det er besluttet.

Når opdateringen kommer: opgradér, og **fjern afværgelsesfilerne igen**.

### SSH

Port 22 er lukket i **Contabo's egen firewall** foran både drift og prøvebane, så kun
ejerens IP kommer ind. Målt udefra 2026-10-21:

```
169.58.51.76    22 lukket · 80 åben · 443 åben
84.46.252.242   22 lukket · 80 åben · 443 åben
```

**Maskinens egen `ufw status` viser fortsat `22/tcp ALLOW Anywhere`.** Det er ikke en fejl
og skal ikke "rettes": den virkelige regel ligger uden for maskinen og kan ikke ses fra
den. En gennemgang der kun læser `ufw`, konkluderer forkert.

Port 80 skal forblive åben. Certbot fornyer over HTTP-01; lukkes 80 ved kanten, fejler
fornyelsen tavst og opdages ~90 dage senere på certifikatet.

**Åbent:** nøgle-login og `PasswordAuthentication no` er ikke lavet. Adgangskodelogin er
stadig slået til på maskinerne selv. Rækkefølgen, når det tages: nøgle verificeret →
`PasswordAuthentication no` → først derefter er firewall-reglen et ekstra lag og ikke det
eneste.

---

## Registrerede afvigelser

Ikke fejl at rette lokalt. De er kendt, og de står her så ingen bliver overrasket.

| Hvor | Afvigelse |
|---|---|
| `storix` på drift | `request_terminate_timeout = 0` (kontraktens standard er 150 s) og `AllowOverride All` — et flyttet CWP-site |
| `/home/envoydv`, `/home/devdesk` | `751` og `711` i stedet for `750`. Sat af coworker-frameworkets `server.sh setup`, så CI-værktøjerne kan nås |

---

## Det der IKKE er målt

Skriv det ikke om til en konklusion.

- **Om `o+x` på hjemmemapperne er sikkert nok.** Læst, ikke målt.
- **Om remi's FPM på CWP-maskinen kan slukkes.** Den serverer intet målbart, men det er
  afgjort på én måling, og maskinen skal ud alligevel. Står urørt.
- **Angrebshistorik.** Der findes ikke et måneds-overblik. Drift-maskinen er for ny til at
  have en måneds logge; den historik der findes, ligger på CWP-maskinen. Og antallet er
  næsten uden værdi — det interessante er om noget lykkedes (accepterede SSH-logins,
  probe-stier der svarede 200), ikke hvor mange der bankede på.
- **`site-backup`s selvtest** er ikke kørt på en maskine. Den skriver i `/etc/sitectl` og
  opretter brugere. Dev er kandidaten.
