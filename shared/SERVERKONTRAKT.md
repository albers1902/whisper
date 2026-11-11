<!-- FILE_VERSION: 1.1.4 — 2026-11-06. Ejes af Sysadmin-Lead. Leveres til coworker-development
     og checksummes i shared/framework.lock. Redigér den ikke i et projekt: en rettelse her
     skal leveres, ikke laves lokalt, ellers har 40 projekter 40 forskellige sandheder.

     1.1.4 (2026-11-06): §3a trin 5 og 6 er kommandoer (`sitectl-projekt layout` og `ci-bruger`), begge på den grønne liste.
     Beviset for en reload er FPM's linje `ready to handle connections`, ikke MainPID: den skifter ikke ved reload
     (målt på DEV og STABLE 2026-11-06). `sitectl key` er installeret på DEV, men har ikke lagt en nøgle endnu.

     1.1.3 (2026-11-05): §8 har nu en regel for nøglefiler, og `sitectl key` er ført ind som DEV-kommando
     (framework-leads bestilling 2026-11-04). En password-manager er ekstra sikkerhed, intet andet
     (ejeren 2026-11-04). §7: alarmmail fra DEV er besluttet. §1: gul-listen fik undtagelsen for den `open_basedir`, `sitectl key` selv laver.

     1.1.2 (2026-10-30): fire påstande rettet, som blev målt forkerte 2026-10-26
     (sysadmin/aendringer/GENNEMGANG-2026-10-26.md afsnit 3) men stod i 1.1.1:
     `reload` af PHP-FPM er nok (trin 5 og §9 punkt 6), `open_basedir` spærrer ikke
     `shell_exec` (§3), `request_terminate_timeout` beskytter ikke andre sites' puljer (§3),
     og 751/711 på hjemmemappen er målt utilstrækkeligt (§9 punkt 1). Ingen ændring i
     banerne eller den grønne liste.

     1.1.1 (2026-10-30): ejerens backup-beslutning fra 1.1 (2026-10-15, framework-lead) er
     ført ind i §2, §7 og §9 — sysadmin-sidens 1.1 (2026-10-22) havde mistet den, og den gælder
     drift, ikke dev. Nyt afsnit 3a (et nyt projekt på DEV, TEST og STABLE, trin for trin, efter
     kritikerens runde 2026-10-30). Grøn liste ændret mod masterens 1.1: `sitectl new` rettet til
     den form værktøjet tager (`--yes --user`); NYE grønne linjer: `site-backup`, `servercheck --vis`;
     IKKE grønne: `sitectl adopt` (ikke målt mod håndlavede sites), `servercheck --accept` (en
     godkendt afvigelse er en beslutning). CI-brugeren på DEV er besluttet af Albers for alle
     projekter (2026-10-30) og undtaget fra RØD.

     1.1 retter tre påstande fra 1.0 som siden er målt forkerte, og udskiller maskinfakta,
     gennemgangsprocedure og fejlkatalog i egne filer. Dette er IKKE kontrakt 1.2: 1.2
     afventer stadig `sitectl` 1.2, og først der bliver afsnit 3 sandt for et site oprettet
     med `sitectl`. -->
# Serverkontrakten

Den ene fil et projekt skal kende om serverne. Alt hvad sysadmin-siden bygger, lander
**her** — ikke i rollefilen, ikke i en samtale, ikke i en runbook pr. projekt. Så er en
opdatering én fil og én lock-linje, og alle projekter er aktuelle samme dag.

Tre filer ligger ved siden af og hører til kontrakten:

| Fil | Hvad |
|---|---|
| `MASKINER.md` | hvilke maskiner der findes, og hvad der er målt på dem, med dato |
| `GENNEMGANG.md` | hvordan noget efterprøves før det kaldes rigtigt — og hvordan et projekt fejlfinder sit eget 500 |
| `FEJLKATALOG.md` | de fejl der har kostet tid, med reproduktion. **Læs afsnit 1 før du skriver bash til en maskine** |

Maskinerne er Ubuntu 24.04 uden kontrolpanel: Apache 2.4 mpm_event, ingen `mod_php`, én
PHP-FPM-pulje pr. site kørende som sitets egen bruger, certbot.

**Databaseversionen er ikke den samme på de to maskiner:** drift kører MariaDB 10.11.14,
prøvebanen 12.3.3 (målt 2026-10-19). 1.0 skrev "MariaDB 12.3" som om det gjaldt begge. Byg
ikke på 12.3-adfærd i et site der skal i drift.

**Og CWP er ikke væk.** Den gamle Rocky/CWP-maskine kører stadig 57 sites på CWP's egen
alt-php 8.2.28. `dnf`, "opret i panelet" og CWP-terminalen gælder **kun** dér, og den
maskine er under afvikling. På de to Ubuntu-maskiner gælder de ikke.

---

## 1. Banerne

### GRØN — kommandoen findes, og den er forhåndsgodkendt

Verificerer sig selv, og skriver sin egen logpost med et S-ID — **undtagen `sitectl db`**, som
hverken er idempotent eller logger (en ny kørsel giver `<bruger>_<navn>2`; kør `sitectl creds`
først og stop hvis basen findes). Ingen
bestilling, ingen ventetid på en beslutning.

```
sitectl db     <domæne> [navn]      database <bruger>_<navn> + DB-bruger, kun localhost
sitectl ssl    <domæne>             certifikat + certbot renew --dry-run som bevis
sitectl php    <domæne> <version>   PHP-version for dette site (ruller selv tilbage)
sitectl new    <domæne> --yes --user <bruger> [--php <version>]
                                    nyt site: bruger, pulje, vhost, mapper, certifikat
sitectl list | check | creds        inventar og diagnose
site-backup                         backup af maskinen, med MANIFEST som bevis
servercheck | servercheck --proces  maskinens tilstand (read-only)
servercheck --vis <emne>            vis baseline for et emne (porte, suid, cron)
server-snapshot                     den fulde opsætning, hemmeligheder maskeret
sitectl --version | servercheck --version    hvilken version maskinen har
sitectl key    <domæne> <navn>      KUN prøvebanen indtil videre: nøglefil til sitet (§8). Albers taster værdien
sitectl-projekt layout [--drift] <domæne> <mappe>...
                                    §3a trin 5: open_basedir med mapperne under /home/<bruger>/.
                                    --drift kræves på en driftsmaskine. Skriver logpost og bevis
sitectl-projekt ci-bruger <domæne>  KUN prøvebanen: §3a trin 6, CI-brugeren <bruger>-ci
```

Dertil: **alt inde i `/home/<egen bruger>/` som egen bruger.** Kode, `cron/`, `logs/`,
`config/`, `public_html/`. Det skal ikke bestilles.

**Hvem kan køre grønt — målt 2026-10-15, uændret.** Værktøjerne er installeret som
`0750 root:root` i `/usr/local/sbin/` og har et hårdt EUID-tjek. Den eneste sudoers-fil
`01-bootstrap.sh` skriver, er en editor-default. **Der findes ingen sudo-vej, og et projekt
kan ikke selv køre en grøn kommando.** Grøn betyder: *kommandoen er den rigtige, og Albers
kører den* — ikke at projektet kan. En sudo-vej pr. site (en `Cmnd_Alias` bundet til sitets
eget domæne, valideret med `visudo -cf`) er specificeret og ikke bygget.

### Du må bede Albers om at køre en grøn kommando — og du skal sende kommandoen med

Et projekt må bede mennesket køre en kommando fra den grønne liste. Det er ikke en
bestilling og kræver ingen runde. To krav, og de er begge hårde:

1. **Kommandoen skal stå ordret i listen ovenfor**, udfyldt med projektets eget domæne og
   egen bruger — intet andet. Du sammensætter ikke en variant, du tilføjer ikke et flag der
   ikke står der, og du klipper ikke to kommandoer sammen til én linje.
2. **Én kommando pr. anmodning**, og du skriver hvad der beviser at den virkede.

Står behovet ikke i listen, er svaret **ikke besluttet**. At noget er afgrænset og
reversibelt gør det ikke godkendt.

**Endnu ikke bygget, altså IKKE grønt:** `sitectl php-ext` (PHP-udvidelse) og
`sitectl reload` (configtest + reload af egen pulje). Indtil de findes, er svaret på dem
**"ikke besluttet"** — og især ikke `systemctl restart php8.X-fpm`, som tager **alle** sites
på den PHP-version ned samtidigt.

### GUL — bestilling, svar i minutter

Rammer hele maskinen, men kan laves om:

en pakke der **ikke** er en PHP-udvidelse · en indstilling i `99-lokal.ini` eller
`99-lokal.cnf` · en ufw-regel · en ny PHP-version på maskinen · en udvidelse af et sites
`open_basedir` (undtagen den `sitectl key` selv laver, §8) · en tjeneste som Mailpit · en cron-fil i `/etc/cron.d/`

### RØD — bestilling, og den koster en runde

Kan ikke laves om, eller bærer sikkerheden:

`/etc/sudoers.d/` · en bruger oprettet eller slettet (**undtaget:** CI-brugeren på DEV, §3a trin 6 —
besluttet af Albers 2026-10-30 for alle projekter) · en nøgle eller et kodeord roteret ·
SSH-konfiguration · DNS · rettigheder på en hjemmemappe · HSTS · noget der rører et andet
sites filer eller database · at slette noget

Her siges prisen højt **før** arbejdet begynder.

### Reglen der gør banerne holdbare

**En gul bestilling der kommer tredje gang, bliver grøn.** Som en `sitectl`-kommando eller
en standard i `01-bootstrap.sh`. Rammer projekterne det samme behov igen og igen, er det et
tegn på at `sitectl new` skulle have sat det fra starten — ikke på at projekterne skal blive
bedre til at bestille.

---

## 2. Hvem ejer hvad

| | Projektets | Serverens |
|---|---|---|
| Kode, `cron/`-scripts, `config/`, `logs/`, `var/`, uploads under `/home/<bruger>/` | ✔ | |
| Brugerens egen crontab (`crontab -e` som egen bruger) | ✔ | |
| CI-værktøjer hentet til egen hjemmemappe | ✔ | |
| `deploy.sh`s rsync ind i `/home/<bruger>/` | ✔ | |
| Appens overvågning: `/health`, release-match, `monitor.sh` | ✔ | |
| At projektet er med i backuppen: `server.sh check` og `/health` læser `logs/backup_last_ok` og `logs/restore_last_ok` | ✔ | |
| Systembruger, gruppe, hjemmemappens **rettigheder** | | ✔ |
| PHP-FPM-pulje, `open_basedir`, FPM-grænser, soklen | | ✔ |
| Vhost, Apache-konfiguration, `AllowOverride` | | ✔ |
| Database**bruger** og grants (databasens *indhold* er projektets) | | ✔ |
| Certifikater og fornyelse | | ✔ |
| Pakker, PHP-versioner, systemtjenester | | ✔ |
| `/etc/` i det hele, inkl. `cron.d`, `sudoers.d`, `cron.deny` | | ✔ |
| Maskinens overvågning, alarmvejen | | ✔ |
| **Backup af alle sites på drift** (dev har ingen off-site backup — Albers 2026-10-19) (`site-backup`: databaser, `public_html`, `private`, `config`, `var`; restic væk fra maskinen; prøve-gendannelse pr. database; beviset skrives i hvert sites `logs/`) | | ✔ |
| Firewallen — også den der ligger **uden for** maskinen (se `MASKINER.md`) | | ✔ |

**Testen når noget er i tvivl:** rammer det kun dit site og kan du genskabe det selv, er
det dit. Rammer det `/etc` eller en anden bruger, er det serverens.

---

## 3. Det et projekt kan regne med fra `sitectl new`

Byg imod dette i stedet for at måle det hver gang.

| | |
|---|---|
| Webrod | `/home/<bruger>/public_html` |
| Logfiler | `/home/<bruger>/logs/` — `access.log`, `error.log`, `php-error.log`, `fpm-slow.log` |
| Pulje | kører **som `<bruger>`**, aldrig `www-data` |
| `open_basedir` | sitets egne mapper, plus `/etc/<bruger>/keys/` hvis `sitectl key` er kørt (§8). PHP's egne filfunktioner kan ikke læse uden for dem — med vilje. **Det er ikke en fuld spærring:** `shell_exec` og andre kald til en shell går udenom (målt 2026-10-26), og det lukkes kun af `disable_functions`, som kontrakten **ikke** lover er sat |
| `request_terminate_timeout` | 150 s. Et hængt kald dræbes, så det ikke binder dit eget sites `pm.max_children`. Puljerne er pr. site; det andre sites deler med dig, er Apaches arbejdere, CPU, RAM og MariaDB-forbindelser — og der er bevidst ingen cgroup-grænse |
| `pm.max_children` | 5 som standard. Brug for mere: gul bestilling |
| `CGIPassAuth On` | **sat** — uden den når `Authorization`-headeren ikke PHP, og Bearer/Basic-auth giver 401 der ser ud som en kodefejl |
| Eksekveres som PHP | `.php`, `.phar`, `.phtml` |
| Nægtes (403) | `.ini .log .sql .bak .inc .sh .conf .env* .yml .yaml .lock .phps .old .orig .save .dist .swp .swo .tmp .gz .tgz .tar .zip .7z .rar .sqlite*`, filer der ender på `~`, og mapperne `.git .svn .hg .bzr` |
| Symlinks | `+SymLinksIfOwnerMatch` — en symlink til noget en anden ejer følges ikke |
| Database | `<bruger>_<navn>`, egen DB-bruger, **kun fra `localhost`**, kun egen base |
| Hjemmemappen | `750 <bruger>:www-data` |

### Fire fælder — tre af dem er målte defekter, ikke adfærd

- **`.gz` nægtes.** Forkomprimerede assets (`app.js.gz`) bliver 403. Bruger sitet dem: gul
  bestilling.
- **Collation.** 1.0 skrev at MariaDB 12.3 defaulter til `uca1400`. **Det er målt forkert:**
  `collation_server` er `utf8mb4_general_ci` på begge maskiner. Den rigtige fælde er en
  anden — `sitectl db` opretter i dag med `general_ci`, mens appens egne tabeller forventer
  `unicode_ci`, og coworker-frameworkets CI opretter uden `COLLATE` (målt: 11 tabeller i
  `devdesk_ci`). **Opret altid eksplicit med `COLLATE utf8mb4_unicode_ci`, i begge ender**,
  ellers vælter en JOIN med `Illegal mix of collations`.
- **`config/` findes ikke efter `sitectl new`,** og `open_basedir` indeholder i dag hverken
  `config/`, `logs/`, `src/` eller `vendor/`. Målt til at knække coworker-frameworkets eget
  layout. Rettes i `sitectl` 1.2. **Indtil da er tabellen ovenfor ikke sand for et nyt
  site** — se `FEJLKATALOG.md` afsnit 7.
- **`sitectl check <domæne>` sluger tavst domænet.** `check` tager kun `--quiet`. Du får
  maskinens svar, ikke sitets, og intet siger det. Rettes i 1.2.

Registrerede afvigelser fra tabellen (ikke fejl at rette lokalt) står i `MASKINER.md`.

---

## 3a. Et nyt projekt: DEV, TEST og STABLE — fra intet til `SERVER: KLAR`

Det samme forløb for alle tre miljøer. Forskellen er hvilken maskine, hvilket brugernavn,
og at to trin kun findes på DEV. **DEV laves først**: det er dér forløbet bevises for
projektet, før det samme gøres på drift.

| Miljø | Maskine | Brugernavn (afsnit 4) | Eksempel |
|---|---|---|---|
| DEV | prøvebanen, `dev.xanouso.com` | produkt + `dev` | `legaldev` |
| TEST | efter Albers' valg (`MASKINER.md`) | produkt + `test` | `legaltest` |
| STABLE | drift, `llm.xanouso.com` | produkt | `legal` |

Hvert trin har et bevis, og næste trin begynder først når beviset er der. Albers kører
serverkommandoerne; projektets agent guider ét trin ad gangen og læser beviset.

| # | Trin | Miljø | Hvem | Beviset |
|---|---|---|---|---|
| 0 | **Navnet er frit:** `getent passwd <bruger>` skal svare **intet**. `sitectl new` overtager i dag en systembruger der findes i forvejen (fx `backup`, `mail`) — det rettes i 1.2. Svarer den noget: stop, vælg et andet navn | alle | Albers | tomt svar |
| 1 | **Site:** `sitectl new <domæne> --yes --user <bruger> --php <version>`. Giver bruger, pulje, vhost, `public_html`, `logs`, `private` og certifikat. DNS skal pege på maskinen **før**. Fejler certifikatet, findes sitet uden logpost: næste kommando er `sitectl ssl <domæne>`, ikke `new` igen. Findes sitet allerede og er lavet med `sitectl`: spring over. Lavet i hånden: bestilling til sysadmin-siden | alle | Albers | `sitectl list` viser sitet, brugeren og certifikatet |
| 2 | **Appens database:** først `sitectl creds <domæne>` — findes basen, stop her. Ellers `sitectl db <domæne> <navn>` → `<bruger>_<navn>` | alle | Albers | `sitectl creds <domæne>` viser basen |
| 3 | **CI-databasen:** samme, med `sitectl db <domæne> ci` → `<bruger>_ci` (navnet SKAL ende på `_ci`; en anden kørsel giver `_ci2`, som `server.sh` afviser) | kun DEV | Albers | `sitectl creds <domæne>` viser basen |
| 4 | **`config/` og `config/config.ini`** som projektets egen bruger (`sitectl new` opretter ikke `config/` endnu). Databaseværdierne står i `private/db-<base>.txt` (600, projektets bruger) og kopieres derfra — **ikke** via `sitectl creds --vis-kodeord`, som lægger kodeordet i et output. `[database]`, på DEV `[ci_database]`, `real_data`, `alert_email = alarms@albers.dk` og `config/github.token` efter `OPSAETNING.md` | alle | Albers | filerne findes, `chmod 600` |
| 5 | **`open_basedir`** udvides med `config/`, `logs/`, `var/` og de kodemapper appen har uden for `public_html` (mapperne i repoets `homedir/`, typisk `src/` og `vendor/`). Indtil `sitectl` 1.2 sætter det i trin 1. `php<v>-fpm` **genindlæses** (`reload`, ikke `restart`, §9 punkt 6) — ingen forbindelser tabes, så det rammer ikke de andre sites på den PHP-version | alle | sysadmin-siden | Sysadmin: `php-fpm<v> -t` grøn, og en linje `ready to handle connections` i FPM's log (`/var/log/php<v>-fpm.log`) efter reloaden, i en logpost. **Ikke** MainPID: den skifter ikke ved reload (målt på DEV og STABLE 2026-11-06). **Ikke** `ActiveEnterTimestamp`. **Projektet:** `/health` svarer, og `logs/php-error.log` har ingen `open_basedir restriction` efter det |
| 6 | **CI-brugeren `<bruger>-ci`** med sudo-reglen fra `OPSAETNING.md` (valideret med `visudo -cf` før den lægges på plads), `cron.deny` og `at.deny`. **Besluttet af Albers 2026-10-30 for alle DEV.** Indtil `sitectl ci-bruger` gør det | kun DEV | sysadmin-siden | logpost; trin 8 viser ✔ ved CI-isolationen |
| 7 | **Repoet:** klones til `~/repo` som projektets bruger (`OPSAETNING.md`, Serveropsætning; Team Lead giver kommandoen). Kører cron-tjenesten ikke på maskinen (`systemctl is-active cron`), er det en bestilling | alle | Albers / Team Lead | `repo/.git` findes |
| 8 | **Projektets opsætning og første deploy:** `server.sh setup dev\|test\|stable` som projektets bruger. Den lægger cron-linjerne, også `deploy.sh`. Vent på første deploy (`logs/deploy.log`), og kør `server.sh setup` igen, til der står `SERVER: KLAR` — sådan står det i `OPSAETNING.md`. Den sætter `o+x` på hjemmemappen — en registreret afvigelse, §9 punkt 1. Siger den at en fil i `logs/` ejes af root: ejerskab er serverens — bestilling, ret det ikke selv | alle | Albers | `/health` svarer med `sha`, `db_read` og `db_write`, og `SERVER: KLAR (<miljø>)` |
| 9 | **Accepttesten** (afsnit 6). Er `servercheck` rød af en grund der ikke er projektets, er det sysadmin-sidens — **ikke** en `--accept` projektet beder om | alle | Albers | `servercheck` → `samlet: OK` |

**Trin 5 er en kommando fra 2026-11-06:** `sitectl-projekt layout [--drift] <domæne> <mappe>...`, målt på DEV og STABLE (`S-20261106-4`, `-8`, `-11`). Projektets agent beder Albers køre den med sitets domæne og mapperne uden for `public_html`, og læser beviset. Trin 6 laves med `sitectl-projekt ci-bruger <domæne>`, kun på prøvebanen.

**Trin 5 og 6 er kendte trin — ikke "ikke besluttet".** Projektets agent siger til Albers:
*"trin 5 (og på DEV trin 6) i kontraktens afsnit 3a laves af sysadmin-siden"*, med sitets
domæne, bruger og de mapper appen bruger uden for `public_html`. Sysadmin-siden laver dem med
en logpost pr. trin og melder ID'erne tilbage. **Et ID er ikke beviset:** agenten går først
videre når projektets egen måling i trin 5 er grøn. Agenten skriver ikke selv kommandoerne.

**STABLE er en maskine i drift.** Trin 1 genindlæser Apache og PHP-FPM for hele maskinen
(under `sitectl`'s lås). Det er grunden til at DEV laves først, og at Albers vælger
tidspunktet. HSTS er ikke en del af forløbet (`sitectl hsts`, afsnit 1).

**Databaseversionen er forskellig:** STABLE kører MariaDB 10.11, DEV 12.3. Tabellerne
oprettes med `COLLATE utf8mb4_unicode_ci` i alle baser (afsnit 3, fælderne).

Når `sitectl` 1.2 og `sitectl ci-bruger` findes, forsvinder trin 5 og 6 som trin for
sysadmin-siden og bliver kommandoer på den grønne liste. Forløbet bliver det samme.

---

## 4. Navne

| | Maks | |
|---|---|---|
| Linux-brugernavn | 32 | — men **`sitectl` tillader 16** (`^[a-z][a-z0-9_]{1,15}$`), og det er den der binder |
| MariaDB-brugernavn (10.0+) | 80 | |
| MariaDB-databasenavn | 64 | `<bruger>_<navn>` skal under 64 |
| MySQL 8, hvis et projekt flytter til managed MySQL | 32 | |

**Brug 12 tegn. Kun små bogstaver og cifre. Ingen bindestreg, intet punktum** — bindestreg
er lovlig i Linux men kræver backticks i SQL, og så vælter den et sted hvor ingen leder.

**Afkort aldrig det ord der skelner.** `envoydv` er ubrugeligt, fordi "envoy" er familien og
ikke produktet. Skriv produkt + miljø: `legaldev`, `legaltest`, `legal` · `storixdev`,
`storix` · `subdev`, `sub`.

---

## 5. Felterne overvågningen læser

`/etc/sitectl/sites/<domæne>.conf`. Et projekt må **læse** dem; kun `sitectl` skriver dem.

| Felt | Hvad det udløser |
|---|---|
| `WANT_SSL` / `SSL_OK` | `WANT_SSL=1` + `SSL_OK≠1` → **alarm**: sitet skulle have et certifikat |
| `CRON_FIL` | er feltet sat og filen mangler → **alarm**. Har sitet cron-jobs: få feltet sat |
| `ALLOW_OVERRIDE=All` | registreret afvigelse (et flyttet CWP-site), synlig i rapporten |
| `SITECTL_VERSION` | mangler eller `1` → gammel skabelon, `sitectl haerd` mangler |
| `HSTS` | `1` → http-fallback til en anden maskine er ikke længere mulig |

**Har sitet cron-jobs, så giv hvert job en hjertebanken:**
`touch /home/<bruger>/var/heartbeat/<jobnavn>`. Overvågningen advarer når et stempel bliver
for gammelt. Det er det ene tjek der fanger "cron kører ikke" uanset årsag — forkert PATH,
manglende binær, fuld disk, `cron.deny`. Uden den kan et job holde op med at køre i fem dage
uden at nogen ser det: der står ikke noget forkert nogen steder, der står bare ikke noget.

**Et site på prøvebanen er ikke overvåget.** Dev har ingen cron, ingen kanariefugl og ingen
alarmvej (`MASKINER.md`). Regn ikke med at nogen får besked.

---

## 6. Accepttesten

Et miljø er klart når **begge** er grønne:

```
bash <(…) server.sh check <miljø>      →  SERVER: KLAR (<miljø>)      (projektets)
servercheck                            →  samlet: OK                  (maskinens)
```

Projektets egen check er accepttesten på en leverance fra sysadmin-siden. Der opfindes ikke
en ny.

---

## 7. Symptom → hvad det er

Den fulde fejlfindingsrækkefølge, inklusive hvilken logfil der læses først, står i
`GENNEMGANG.md` afsnit 6. Kortformen:

| Symptom | Hvad det næsten altid er |
|---|---|
| 500 på alle ruter, lige efter et nyt site | `open_basedir` — se `logs/php-error.log` |
| 500 og `not allowed here` i `error.log` | `.htaccess` bruger et direktiv `AllowOverride` ikke giver |
| 401 på Bearer/Basic, koden er rigtig | `CGIPassAuth` mangler i vhosten |
| 503 på HTTPS, 200 på HTTP | FPM-soklen i `-le-ssl.conf` peger forkert → `sitectl php` |
| `.php` downloades som tekst | vhosten mangler handleren — sitet er lavet i hånden |
| Appen kan ikke sende mail | `msmtp --serverinfo`, `tail /var/log/msmtp.log` |
| `sendmail: account default not found` på DEV | **Forventet.** Dev har ingen alarmvej (Albers 2026-10-19). `monitor.sh` opretter en GitHub-sag; rød CI ses i commit-status og `report.sh check`. Kør ikke `04-mail.sh`: den overskriver maskinens fælles mailopsætning. Om mail virker for et site på drift, er **ikke besluttet**: der findes ingen testmail-kommando endnu |
| Cron-jobbet kører ikke | hjertebankens alder, `grep CRON /var/log/syslog` |
| Oppe i går, nede i nat, oppe nu | `servercheck` (OOM og filsystemfejl står der) · `sar -q` · `git -C /etc log` |
| `logs/backup_last_ok` mangler eller er gammel | **På DEV: forventet** — dev har ingen backup-bevis (Albers 2026-10-19). På drift: sitet er ikke med i `site-backup`s bevis. Måling: `cat logs/backup_last_ok logs/restore_last_ok`; årsagen ses kun i maskinens MANIFEST (root) → **ikke besluttet** for projektet |
| En indstilling slog ikke igennem | hovedprocessen har ikke genlæst konfigurationen efter filen blev skrevet — `GENNEMGANG.md` afsnit 2, trin 3 |

---

## 8. Hemmeligheder

- Aldrig i en bestilling, et issue, en logpost eller et output der skal videre.
- Skal der leveres et kodeord: bestillingen skriver **hvad** der mangler. Mennesket leverer
  værdien direkte.
- **En hemmelighed der har været i et output eller en chat, ER lækket og skal roteres.**
  Derfor viser `sitectl creds` som standard ikke kodeordet; `--vis-kodeord` kræver et flag
  og siger det højt.
- **Findes kodeordet i kilden, flyttes kodeordet — uændret.** At generere et nyt er
  menneskets valg. En ny hemmelighed hvor der allerede findes en, er et hårdt stop. Det er
  sket, og det kostede en aften.
- Et token må ikke stå på en kommandolinje: `/proc/<pid>/cmdline` er verdenslæsbar, og alle
  site-brugere har en shell. Brug en fil med 600 og en credential-helper.
- **En password-manager er ekstra sikkerhed, intet andet** (ejeren 2026-11-04). Intet må afhænge af den.
- **Nøglefiler.** Nøglemateriale er en nøgle, der krypterer eller signerer sitets data (fx `secret_key`), og
  en app-secret, der gælder hele sitets app hos en anden tjeneste (fx et e-conomic App Secret Token). Det
  ligger i `/etc/<bruger>/keys/<navn>`, **aldrig** i `/home/<bruger>/`, hvor en upload kan overskrive det.
  **Ikke nøglemateriale:** databasekoder, `config/github.token` og API-nøgler i `config/config.ini`. De
  bliver i `config/` med `600` (§3a trin 4).
  `<bruger>` er sitets Linux-bruger, så DEV, TEST og STABLE hver har sin egen mappe og sin egen nøgle.
  - **Kommandoen:** `sitectl key <domæne> <navn>`, kørt af Albers. Projektet beder om den med domæne og
    navn. Værdien tastes skjult og går aldrig gennem en bestilling, et issue eller en chat. Kommandoen
    sætter mappen til `0750 root:<bruger>` og filen til `0640 root:<bruger>`, tilføjer stien til sitets
    `open_basedir`, genindlæser puljen med `reload`, og holder `/*/keys/` ude af `/etc/.git`.
  - **Filen** indeholder præcis værdien, uden afsluttende linjeskift, og kun én linje. Flerlinjede og
    binære nøgler kan kommandoen ikke tage: **ikke besluttet**.
  - **En anden kørsel ændrer intet.** Er værdien forkert, lægges den under et nyt navn, og config rettes.
    At erstatte en nøgle er en rotation og RØD.
  - **Stien** står i projektets egen config, hvor appen læser den. Værdien gør ikke.
  - **Bevis:** kommandoen beviser filen, rettighederne og `open_basedir`-linjen. At appen kan læse filen,
    beviser kun en request gennem puljen, fx `keys: ok` i `/health`. En tom `php-error.log` er intet
    bevis, og CLI-PHP går uden om puljens `open_basedir`.
  - **Reglen følger maskinen, ikke miljøet.** Kommandoen er kun installeret på prøvebanen og nægter på en
    driftsmaskine, indtil nøglebackuppen er bevist (§9). Indtil da er en nøgle på en driftsmaskine **ikke besluttet**,
    uanset om miljøet hedder TEST eller STABLE.
  - `site-backup` udelader `*.key *.pem *.p12 *.pfx *.jks` under et sites `config/` og nævner hver udeladt
    fil ved navn i MANIFEST, fordi en tavs udeladelse er værre end ingen. En nøgle under et andet navn eller i
    `private/` er ikke dækket af det, og den skal flyttes.

---

## 9. Hvad der er åbent — og derfor ikke må antages

Ærligt, så ingen bygger på en halv beslutning.

| # | Åbent | Indtil da |
|---|---|---|
| 1 | **Rettigheder på hjemmemappen.** `sitectl` sætter 750; `server.sh setup` sætter `o+x` (målt: `/home/envoydv` er 751, `/home/devdesk` 711). **Målt 2026-10-26 (fikstur, ikke på maskinerne): det er ikke nok.** Mod en 711-mappe nægtes `ls`, men en anden bruger kan `cat` en fil med kendt sti, fx `config/config.ini`, hvis filen selv kan læses af andre. `o+x` spærrer listning, ikke læsning med kendt sti — og stierne er standardiseret af §3. Med 750 nægtes begge | Behandl `o+x` som en registreret afvigelse, ikke som normalen. Ret den ikke selv. Hold `config/` og alt med hemmeligheder på `600`/`700` — så hjælper den kendte sti ikke |
| 2 | **Hvem ejer CI-værktøjerne.** Ligger de i hjemmemappen, kræver de `o+x`. Lægges de i `/opt/coworker-ci/<projekt>/`, kan hjemmemappen blive 750 | Lad dem ligge hvor de ligger |
| 3 | ~~Backup-beviset~~ — **afgjort af ejeren 2026-10-15:** én backup pr. driftsmaskine (dev er undtaget, 2026-10-19) (`site-backup`, også væk fra maskinen med restic), der tager alle sites. Projektet sætter ingen backup op; det ser kun beviset i sine `logs/`. Projekter, der allerede har `backup.sh`, må lade den køre — dobbelt, men harmløst | — |
| 4 | **`sitectl` 1.2.** `config/` oprettes og kommer i `open_basedir`, `unicode_ci`, `check` nægter ukendte argumenter. Først derefter er afsnit 3 sandt for et nyt site, og først derefter leveres kontrakt 1.2 | Følg noterne i afsnit 3 |
| 5 | **PHP er ikke opdateret mod PHP2Shell** (målt 2026-10-21 — mål igen) på nogen af de tre maskiner — kun afværget. Detaljer og hvad der mangler: `MASKINER.md` | Regn ikke maskinerne som patchede |
| 6 | ~~Hvad `systemctl reload php-fpm` genindlæser~~ — **målt 2026-10-26:** `reload` (USR2) får hovedprocessen til at genlæse hele konfigurationen, også `conf.d` og puljefilen, uden at tabe forbindelser. `restart` tager alle sites på versionen ned og bruges kun efter en ny PHP-binær (om reload tager den, er ikke målt) | Bevis efter en ændring: spørg puljen, eller find `ready to handle connections` i FPM's log efter reloaden — `GENNEMGANG.md` afsnit 2, trin 3. Ikke MainPID og ikke `ActiveEnterTimestamp` |
| 7 | **Backup af nøglefiler.** `/etc/<bruger>/keys/` er ikke i `site-backup`. En backup af hele maskinen i to dele er besluttet af ejeren (2026-11-04), men ikke bygget færdig. Password-manageren er ikke gendannelsesvejen | Ingen nøgle på en driftsmaskine. Se §8 |

**Og den vigtigste:** hverken denne kontrakt eller værktøjerne bag den er kørt ende til ende
på alle maskiner. Tre påstande i 1.0 blev målt forkerte inden for en uge
(`FEJLKATALOG.md` afsnit 6). Rammer du noget der ikke stemmer med kontrakten: **meld det som
et fund**, med reproduktion efter formen i `GENNEMGANG.md` afsnit 3. Det er ikke en fejl i
dit projekt, og du skal ikke lappe det lokalt.
