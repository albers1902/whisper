<!-- FILE_VERSION: 5.42 -->
# Framework-changelog

## v5.42 — 2026-11-10

Grundlag: fund fra et projekt (2026-11-10). `report.sh secrets` med 5.41 flagger stadig `launched/releases/ctrl-2026.10.16-1.md`
linje 290, så filen kan ikke committes. Ejerens go 2026-11-10. U4 (fælles migrationsløber) rykker til 5.43.

| ID | Fund | Afgørelse |
|---|---|---|
| E9 | `report.sh secrets` gav falsk alarm på ctrl-filer. `ctrl <sha>` skriver en diff af kontrolfilerne. Den gengiver kommentaren `# config/github.token: fine-grained, …` fra `shared/install/ci.sh`, og mønsteret `token: <8+ tegn>` rammer den. Undtagelsen fra 5.36 (E6) gælder kun hele filer, der er byte-identiske med `framework.lock` | **Rettet:** undtagelsen gælder nu også linjer. Et fund ignoreres, hvis linjen står ordret i en låst framework-fil, hvis hash stadig passer med `framework.lock`, enten som den er, eller uden én diff-markør (`+`, `-` eller mellemrum). Det dækker også ctrl-filer, der allerede er genereret. En ny linje scannes stadig. Testet i selftest: låst linje i en ctrl-diff (`+`/`-`/kontekst) giver ingen alarm, og `+token = "ghp_…"` i samme fil findes stadig |

Projekterne: kopiér masteren ind som sædvanlig (ny framework-version). Er en ctrl-fil blokeret, kan den committes, når 5.42 er inde.

## v5.41 — 2026-11-07

Grundlag: ejerens krav 2026-11-07: "jeg gider ikke at der er nogen whatsoever der skriver, jeg skal åbne en fil med nano". Kun tekst.
U4 (fælles migrationsløber) er ikke med; den bliver 5.42 og venter på test på Linux.

| ID | Ændring | Hvor |
|---|---|---|
| K4 | **Editor: altid `vi`, aldrig `nano`.** Skal mennesket åbne eller rette en fil, står der `vi <fil>`. Ingen agent skriver `nano` i en kommando, vejledning, bestilling, rapport eller smoke-test, heller ikke som eksempel eller alternativ | `CLAUDE.md` (Grundregler), `agents/team_lead.md` (Mennesket), `agents/fullstack_engineer.md` (Afslutning), `shared/LIFECYCLE.md` (Aldrig) |

Sysadmin-Lead er bedt om at skrive det samme i kontrakten og sysadmin-rollen (de er hans filer).

## v5.40 — 2026-11-06

Grundlag: Sysadmin-Leads leverance 2026-11-06 (kontrakt 1.1.4), gennemgået af framework-lead (to fund, begge lukket). Ejerens go
2026-11-06. Kun tekst; ingen scripts er ændret. U4 (fælles migrationsløber) er ikke med: den venter på v2 og test på Linux.

| ID | Leverance | Afgørelse |
|---|---|---|
| S27 | `shared/SERVERKONTRAKT.md` 1.1.3 → 1.1.4 (sha256 `633befd7…d07b`), `_claude/agents/sysadmin.md` (`348028e2…a4`), `shared/GENNEMGANG.md` (`ff08203b…5274`) | **Taget ind byte-identisk.** §3a trin 5 er kommandoen `sitectl-projekt layout [--drift] <domæne> <mappe>...`, og trin 6 er `sitectl-projekt ci-bruger <domæne>` (kun prøvebanen). Begge står på den grønne liste. Beviset efter en reload af PHP-FPM er en linje `ready to handle connections` i FPM's log, ikke MainPID (den skifter ikke, målt på DEV og STABLE 2026-11-06) |

Mennesket: `claude.lock` har ny hash for `agents/sysadmin.md`. Kør `opdater-pc.ps1`, når 5.40 er kopieret ind.

### Ikke verificeret
- `sitectl-projekt` er ikke læst eller kørt herfra. Kontrakten siger, at begge kommandoer er målt på maskinerne (`S-20261106-4`, `-8`, `-11`).
- Alt under v5.38's "Ikke verificeret" gælder stadig: intet er kørt på Linux.

## v5.39 — 2026-11-05

Grundlag: Sysadmin-Leads svar på bestillingen om nøglefiler (`til-coworker/TIL-FRAMEWORK-LEAD-2026-11-04.md`), rettet efter
framework-leads gennemgang (`_til-sysadmin/GENNEMGANG-KONTRAKT-1.1.3-2026-11-05.md`, F1–F5 lukket). Ejerens beslutning 2026-11-04,
bekræftet over for framework-lead: **en password-manager er ekstra sikkerhed, intet andet.** Ingen scripts er ændret, kun tekst og én
kommentar.

| ID | Fund | Afgørelse |
|---|---|---|
| S25 | `shared/SERVERKONTRAKT.md` 1.1.2 → 1.1.3 og `_claude/agents/sysadmin.md` | **Taget ind byte-identisk** (sha256 `2b062e7d…da33` og `620768d3…2488`). §8: nøglemateriale er en nøgle, der krypterer eller signerer sitets data, og en app-secret for hele appen. Det ligger i `/etc/<bruger>/keys/<navn>`. Databasekoder, `config/github.token` og API-nøgler i `config.ini` bliver i `config/`. `sitectl key <domæne> <navn>` på prøvebanen, kørt af ejeren. Bevis gennem puljen (`keys: ok` i `/health`). På en driftsmaskine er en nøgle ikke besluttet, til nøglebackuppen er bevist (§9 punkt 7). §7: alarmmail fra DEV er forventet |
| S26 | Stien til nøglefilen | **Afgjort af Sysadmin-Lead:** stien står i projektets egen config, hvor appen læser den. `[keys]` i `config.ini` kun, hvis `server.sh check` skal dække nøglen (ikke bygget) |
| K2 | Password-manageren stod som gendannelsesvej eller godkendt sted | **Rettet:** OPSAETNING §4, `agents/team_lead.md`, LIFECYCLE §7, `DRIFT.md.template`, `INCIDENT.md.template` og kommentaren i `backup.sh`. "Uden for password-manageren" er nu "uden for de godkendte steder (SERVERKONTRAKT §8)" |
| K3 | `/etc/<app>/keys/` | **Rettet** til `/etc/<bruger>/keys/` i LIFECYCLE §8 og `agents/fullstack_engineer.md` |

Åbne huller: A14 (rød CI på DEV laver ingen sag), A15 (`backup.sh` med kodeordet i sit eget repository).
Mennesket: `claude.lock` har ny hash for `agents/sysadmin.md`. Kør `opdater-pc.ps1`, når 5.39 er kopieret ind.

### Ikke verificeret
- `sitectl key` er ikke læst eller kørt herfra. Ifølge kontrakten har den ikke lagt en nøgle endnu; første kørsel er FinanceMCP's på DEV.
- `server.sh check` for `[keys]` er ikke bygget.
- Alt under v5.38's "Ikke verificeret" gælder stadig: intet er kørt på Linux.

## v5.38 — 2026-11-04

Grundlag: FinanceMCP DEV 2026-10-30: et nyt miljø krævede håndarbejde, tokenet endte 664, og `composer audit` var rød på en
lock uden pakker. Dertil sysadmin-Leads leverance 2026-10-30 (kontrakt 1.1.2). Spec, impact, reviews og rapporter:
`_framework/U1/` og `_framework/U2/` (kun i master). **Flettet uden test på Linux efter ejerens beslutning 2026-11-04 (A13).**

| ID | Fund | Afgørelse og test |
|---|---|---|
| U1 | Nyt miljø krævede afskrift af kodeord, en lang export-linje og token i hånden | **Ny:** `server.sh init <miljø>` og én indgangslinje i OPSAETNING: `config/` (700), `config.ini` og token (600) fra `private/db-*.txt`, klon af `repo/`, derefter setup. `host = localhost`, fordi grants er `@'localhost'`. Stable kræver et eksplicit `real_data`. Findes en fil, røres den ikke. Tokenet når aldrig argv. Forkert repo stopper før cron. QA og security APPROVED (v3) |
| U1 | Et token, andre har kunnet læse, blev chmod'et og meldt grønt | **Rettet:** markøren `logs/.token-laekket` (sha256 af det trimmede token) skrives før chmod af `check`, `setup`, `deploy.sh` og `ci.sh`, og ✘ står, til tokenet er roteret. `logs/` røres ikke af udrulning. Rotationsvej i `init`: et andet token erstatter kun et token, der er meldt lækket |
| U1 | `config/` var kun lukket for andre på DEV | **Rettet:** `setup` kører `o-rwx` på alle miljøer. Et kørende test/stable med 755 er `IKKE KLAR` i `check`, til linjen er kørt én gang |
| U2 | `composer audit` rød på en lock uden pakker | **Rettet:** kun et gyldigt JSON-objekt, hvor `packages` og `packages-dev` er tomme lister eller fraværende, springer over. Alt andet afgør composer. QA og security APPROVED |
| S23 | Sysadmin: kontrakt 1.1.2, MASKINER 1.1, GENNEMGANG 1.1, FEJLKATALOG 1.1 | **Taget ind byte-identisk.** `reload` af PHP-FPM er nok (§3a trin 5, §9 punkt 6), beviset er skiftet `MainPID`, `open_basedir` spærrer ikke `shell_exec`, `o+x` er målt utilstrækkeligt |
| S24 | Kontraktens §9 punkt 1 mod `server.sh` | **Vurderet, ikke ændret:** `o+x` sættes kun på DEV. Står som A12 |

Åbne huller: A9–A13. `/_framework/` er føjet til `gitignore.framework`.

### Ikke verificeret
- **`selftest-ci.sh` er ikke kørt** (kræver Linux, root, sudo og mariadbd). Det gælder alle U1- og U2-tests i den og den negative host-kontrol. Testene kan selv have fejl.
- `selftest-deploy.sh` er kun kørt med rsync-emulator og stubbe på Windows (65/67). Rigtige symlinks, unix-rettigheder, `umask` og `mv -T` er ikke målt.
- `server.sh init` er ikke kørt på en maskine. FinanceMCP DEV er allerede sat op og prøver kun Enter og rotation. Et nyt miljø prøves først på næste TEST eller nye projekt.
- At `127.0.0.1` afvises med `skip-name-resolve`, at raw-API'et virker med et token med kun Contents: Read, og `read -rsp` i en rigtig SSH-terminal.
- Sysadmin-Lead har ikke svaret på `_til-sysadmin/BESTILLING-U1-INIT-2026-10-30.md`, så §3a trin 4, 7 og 8 beskriver stadig håndarbejdet. `agents/team_lead.md` siger, at linjen erstatter dem.

## v5.37 — 2026-10-30

Grundlag: sysadmin-Leads leverance 2026-10-30 (`til-coworker/TIL-FRAMEWORK-LEAD-2026-10-30.md`): SERVERKONTRAKT 1.1.1
med tre nye filer, skrevet direkte i master kl. 20:32 efter kritikerens dom "må ikke gå videre" (13 fund,
`sysadmin/aendringer/KRITIK-kontrakt-1.1.1_v1.md`). Ingen ny kritikerrunde — ejeren valgte framework-lead som andet
blik. Gennemgået her: alle 13 fund er rettet i teksten; to nye fund (N1, N2 nedenfor). Sysadmins låse var regnet mod
5.36-filerne og overskrev 5.37-låsene; hans egne hashes stemte, og `report.sh lock` er kørt igen. Svaret på
Storix-punkterne: `til-coworker/SVAR-STORIX-2026-10-30.md` (en fejlplaceret kopi i `_fra-sysadmin/` er flyttet til
`_indifferent/`). Gennemgangen er sendt i `_til-sysadmin/SVAR-OPEN-BASEDIR-2026-10-30.md`.

| ID | Leverance/fund | Afgørelse |
|---|---|---|
| S20 | `shared/SERVERKONTRAKT.md` 1.1 → 1.1.1: nyt §3a, trin 0–9 med bevis pr. trin (nyt projekt på DEV, TEST og STABLE til `SERVER: KLAR`; trin 5 `open_basedir` og trin 6 CI-bruger laves af sysadmin-siden, ikke "ikke besluttet"). Grøn liste: `sitectl new <domæne> --yes --user <bruger> [--php <version>]` (den gamle form døde med "ukendt flag"), nye `site-backup` og `servercheck --vis`; `sitectl adopt` og `servercheck --accept` er IKKE grønne. `sitectl db` er hverken idempotent eller logger. Et projekt må bede Albers køre én grøn kommando ad gangen, ordret, med beviset. CI-brugeren på DEV er undtaget fra RØD (Albers 2026-10-30). Rettet: drift kører MariaDB 10.11.14, prøvebanen 12.3.3; CWP-maskinen kører stadig (57 sites, under afvikling) | **Indført.** Ejerens backup-beslutning (2026-10-15) står i §2, §7 og §9 og gælder drift; dev har ingen off-site backup (Albers 2026-10-19), så `backup_last_ok` mangler forventet på DEV |
| S21 | Nye låste filer ved siden af kontrakten: `shared/MASKINER.md`, `shared/GENNEMGANG.md` (§6: projektet fejlfinder sit eget 500), `shared/FEJLKATALOG.md` (§1 før bash til en maskine, §7 åbne defekter i `sitectl` 1.1) | **Indført** og låst i framework.lock |
| S22 | `_claude/agents/sysadmin.md`: læser MASKINER, GENNEMGANG og FEJLKATALOG; "nyt DEV/TEST/STABLE" → §3a ét trin ad gangen; CI-bruger → trin 6, `config/`/`var/`/`src/` → trin 5, i stedet for "ikke besluttet"; "Albers kører den", samme regel som S20 | **Indført** og låst i claude.lock |
| N1 | §3a trin 7 kræver `/health` efter første deploy fra cron, men cron-linjen for `deploy.sh` lægges først af `server.sh setup` i trin 8 | **Rettet af sysadmin 2026-10-30 20:46** (`til-coworker/TIL-FRAMEWORK-LEAD-N1-2026-10-30.md`), taget ind byte-identisk: trin 7 = klon `~/repo` (bevis `repo/.git`); trin 8 = `server.sh setup`, vent på første deploy, kør igen til `SERVER: KLAR` (bevis `/health` med `sha`, `db_read`, `db_write`) |
| N2 | §3a trin 6 henviser til sudo-reglen i OPSAETNING.md, som Storix d fjernede som ejer-kommando | **Rettet her:** OPSAETNING beskriver reglen (`visudo -cf`, `cron.deny`, `at.deny`) som det sysadmin-siden lægger |
| A1 | Alarmmodtager | **Ejeren 2026-10-30:** `alert_email = alarms@albers.dk` — OPSAETNING og §3a trin 4 |
| Storix a | `sitectl db` laver `utf8mb4_general_ci` | **Sysadmin:** bekræftet i kilden, rettes i `sitectl` 1.2. Indtil da: tabeller oprettes med `COLLATE utf8mb4_unicode_ci` (kontrakten §3/§3a) |
| Storix b | Read-only DB-bruger pr. site | **Besluttet af ejeren 2026-10-30:** `sitectl db <domæne> <navn> --readonly` i `sitectl` 1.2 → `<bruger>_ro`, kun SELECT, udleveres af `sitectl creds`. Ikke grøn, før 1.2 er leveret. Projekterne laver den aldrig selv |
| Storix c | Nye flag på den grønne liste | **Allerede reglen** (kontrakten §1): et flag kommer på listen i samme levering som den `sitectl`, der har det |
| Storix d | CI-brugeren og sudo-reglen | **Besluttet:** `server.sh`-tjekket (`sudo -n -u <p>-ci`) ændres ikke. Reglen laves af sysadmin-siden (§3a trin 6). **Rettet her:** OPSAETNING.md, CLAUDE.md ("Ny framework-version" trin 5) og `server.sh check`'s ✘-linje bad ejeren skrive i `/etc/sudoers.d/` uden `visudo -cf` — en tastefejl lukker sudo på hele maskinen. Nu: "laves af sysadmin-siden (SERVERKONTRAKT §3a trin 6)". selftest-ci forventer den nye tekst |
| Storix e | `--no-ssl` ignoreres | **Sysadmin (kilde, ikke målt):** dialogen uden `--yes` foreslår "ja". Rettes i 1.2. Indtil da: `sitectl new` altid med `--yes` (§3a) |
| Storix f | `open_basedir` pr. pulje | **Afgjort i §3a trin 5:** mapperne i repoets `homedir/` + `config/`, `logs/`, `var/`. Passer med projekterne (`deploy.sh` spejler HVER topmappe under `homedir/`: `src/` i fem projekter, `private/`, `templates/`, `lang/`, `connectors/`, `data/`, `bin/`, `mytools-sdk/`). Forslaget "hele hjemmemappen" er **afvist af sysadmin**: PHP kunne så skrive i `~/.ssh/authorized_keys`. En ny topmappe i `homedir/` hører under trin 5, ikke en ny beslutning |
| Storix g | Testmail-kommando | **Afventer ejeren.** Forslag: `sitectl mailtest <domæne>`. Indtil da en måling: `msmtp --serverinfo`, `tail /var/log/msmtp.log` (§7) |

### Ikke verificeret
- Storix d: selftest-ci kræver root og en Linux-maskine — ikke kørt her. Ændringen er kun tekst i en ✘-linje.
- S20–S22: ingen ny kritikerrunde efter rettelserne; kun læst mod kritikken og mod server.sh/deploy.sh/OPSAETNING her.
  Intet kørt mod en maskine. Prøven er EconomicAPIConnector gennem §3a (DEV først).
- N1-rettelsen er kun læst mod server.sh og OPSAETNING her; kontraktens FILE_VERSION står stadig 1.1.1.
- Storix g venter på ejeren.

## v5.36 — 2026-10-28

Grundlag: econsync (TrygEnergi) om 5.33, 2026-10-28, 8 fund i låste filer. Fund 2d, 4 (curl) og 7 var allerede rettet
i 5.34/5.35; resten er rettet her. Alle nye tests fejler med 5.35-filerne (kørt).

| ID | Fund | Afgørelse og test |
|---|---|---|
| E1 | config.ini læses forskelligt: deploy.sh fjernede kun `"…"` om `[database]`, ci.sh slet ingen anførselstegn | **Rettet:** én funktion `cwini()`, byte-identisk i deploy.sh, ci.sh, monitor.sh, server.sh, backup.sh og report.sh (også coworker.ini). `"…"` og `'…'` fjernes; i en citeret værdi er `;` og `#` almindelige tegn; uciteret er ` ;…` en kommentar. backup.sh læste før kun til næste `=`. Testet (selftest: alle seks kopier ens + `'navn'`, `"…"`, `#`, ` ;`; selftest-ci: hele CI-kørslen med `name = 'p_ci'`, `pass = "…"` og kommentar — 35 røde med 5.35; selftest-deploy: dump med `name = 'cwdeploytest'`) |
| E2a | Nye migrationer uden `[database] name` og uden backup.sh: migration uden dump og uden besked | **Rettet:** afvist med alarm ("intet dump er muligt … intet ændret"), som et fejlet dump. Ret config.ini og kør `cron/deploy.sh <miljø> retry`. Testet (selftest-deploy) |
| E2b | `logs/predeploy-db.sql.gz` (fuld kopi af databasen, på STABLE kundedata) blev liggende | **Rettet:** slettes efter `DEPLOY OK`. Efter en fejlet udrulning bliver det liggende til undersøgelse, højst 7 døgn. Testet (selftest-deploy: fejlet → dump findes; retry OK → væk; 8 døgn gammelt → væk) |
| E2c | Kodeordet stod uciteret i `.predeploy.cnf` (`#`, `\`, `" ;"` afkortede det); filen kunne blive liggende | **Rettet:** alle værdier skrives med `cnfq()` som `"…"` med `\` og `"` escaped (også backup.sh's login-filer). Filen slettes i EXIT-trap og ved næste start (efter kill -9). Testet mod rigtig MariaDB med kodeordet `p#a\b"c ;d` |
| E2d | `logs/predeploy-db.err` med for brede rettigheder | **Allerede rettet i 5.34 (S7):** filen oprettes 600, før dumpet kører. Testen findes (selftest-deploy) |
| E3 | MariaDB 12.3 har nye reserverede ord (`to_date`) — tom base fejler med 1064, 10.11 og SQLite siger intet | **Ny framework-kontrol:** nye migrationer (også down/) med et uciteret ord, der er reserveret EFTER 10.11: `to_date`, `conversion`, `current_path` (12.3), `vector` (11.7), `sql_after_gtids`, `sql_before_gtids` (11.3). Strenge, kommentarer, backticks, `x.ord` og funktionskald `ord(` tæller ikke. Kilde: MariaDBs liste over reserverede ord + MDEV-40693. Testet (selftest) |
| E4 | Guarden blokerede download af den låste phpstan.phar | **curl rettet i 5.34 (S13).** Nu også: `gh release download` er læsning for engineers, og `bash shared/report.sh phpstan` henter CI's phar (version og sha256 læses fra ci.sh) til en fast sti `~/.cache/coworker/phpstan-<v>/` og kører den som CI (level 5, baseline/config). `phpstan path` viser kun stien. Testet (selftest-guard). `report.sh phpstan` er kun kørt her på Linux, ikke på Windows |
| E5 | PR-kommentaren viste kun loggens sidste 80 linjer — PHPStans fejl manglede | **Rettet:** kommentaren viser hvert RØDT trins eget afsnit (højst 150 linjer pr. trin, 60 kB i alt); intet rødt afsnit → loggens hale som før. Testet (selftest-ci: rigtig PHPStan-fejl står i kommentaren, grønne trin gør ikke) |
| E6 | `report.sh secrets` meldte falsk alarm på låste filer | **Rettet:** filer, der er byte-identiske med en hash i framework.lock (også de installerede kopier i `homedir/cron/`), springes over. En ændret kopi scannes. Testet (selftest) |
| E7 | precheck rød på en framework-branch, før review-docs er lagt på | **Rettet i 5.35 (P3):** manglende rapport/reviews er `VENTER (kommer efter critics)`, ikke rød. Nu også i fullstack_engineer.md: VENTER er forventet, kun `CI:`-linjer er kodefejl |
| E8 | Team Lead låste sig ude med `cd` ind i en worktree | **Rettet:** guarden tillader et bart `cd`/`Set-Location` til en sti UDEN for `.claude/worktrees` (relativ, absolut, Git Bash). `cd` til en anden worktree eller `cd … && …` er stadig blokeret. team_lead.md: brug `git -C <sti>` eller en subshell. Testet (selftest-guard) |

### Ikke verificeret
- E3: kun ordene ovenfor; listen skal følges, når DEV-serverne opgraderes. Gamle migrationer (under baseline eller allerede på main) tjekkes ikke — rør dem aldrig (§6).
- E4: `report.sh phpstan` i Git Bash på Windows (curl, sha256sum og `~/.cache` findes der, men er ikke kørt).
- E1: en værdi, der selv starter med et anførselstegn uden at være citeret, læses nu anderledes. Ingen kendte tilfælde.
- E2a: et projekt, der har migrationer, men læser databasen fra en anden fil end config.ini, bliver nu stoppet ved næste migration, til `[database]` er udfyldt.

## v5.35 — 2026-10-26

Grundlag: ejeren 2026-10-26: "processen er ekstremt langsom". To forslag fra projekterne: database til engineerne, så
databasetestene kører før gennemgangene, og CI på DEV før QA og sikkerhed.

Årsagen i frameworket: critics kørte FØR DEV havde testet koden (team_lead.md: critics i trin 1, `coworker/pr` i trin 4).
En rød test eller migration efter reviewet betød ny kode, og så var reviewet ugyldigt (CI sammenligner patch-id): en
ekstra runde med begge critics for hver fejl, DEV fandt.

| ID | Forslag/fund | Afgørelse og test |
|---|---|---|
| P1 | CI på DEV før gennemgang | **Indført.** `cron/ci.sh` sætter en ny status `coworker/kode` på hver PR-commit: alle trin (tests med rigtig database, migrationer, lint, PHPStan, Semgrep, audit, gitleaks) undtagen review-kravene. Engineeren pusher og åbner PR'en, når koden er færdig, og melder først klar på `report.sh kode` = `KODE: GRØN`. Team Lead starter critics først der. Når rapport og reviews (kun .md) lægges ovenpå, genbruges de tunge trin. Kun review-krav røde = ingen PR-kommentar. Testet (selftest-ci: kode-commit uden rapport → `coworker/kode` grøn, `coworker/pr` rød, ingen kommentar; rapport ovenpå → tunge trin genbrugt, `coworker/pr` grøn; rød test → `coworker/kode` rød) og selftest (`report.sh kode` grøn/rød/venter) |
| P2 | Database lokalt hos engineerne | **Afvist som lokal database, indført som "database på DEV".** Pc'erne er Windows uden MariaDB; en lokal base skal installeres og holdes i takt med serverens version på hver pc. DEV har allerede en isoleret testdatabase pr. projekt, og P1 giver engineeren den før gennemgang. `PRECHECK_TESTS=1` findes stadig for den, der har en database |
| P3 | (Storix S8, 5.34) precheck altid RØD på engineerens commit | **Rettet — det var samme årsag.** precheck kørte review-kravene, før reviews kunne findes. I precheck er manglende rapport og critic-reviews nu en linje `VENTER (kommer efter critics)`, ikke rød. `coworker/pr` og main kræver dem uændret. Testet (selftest: precheck GRØN med VENTER; `report.sh ci` stadig rød) |

### Ikke verificeret
- Hvor mange runder P1 sparer, er ikke målt. `report.sh spild <måned>` i et projekt viser critic-runder og precheck-kørsler før og efter.
- Ventetiden på DEV (kø + tunge trin, typisk minutter) er ikke målt på de rigtige servere.
- At critics ikke startes før `KODE: GRØN`, er en regel for Team Lead, ikke håndhævet af CI.

## v5.34 — 2026-10-22

Grundlag: Storix' rapport om 5.33 (2026-10-22), 19 punkter. Framework-punkterne er rettet her; serverpunkterne er
sendt til sysadmin (`_til-sysadmin/STORIX-FUND-2026-10-22.md`).

| ID | Fund | Afgørelse og test |
|---|---|---|
| S1 | Første udrulning på STABLE tog `[deploy] php`/`migrate` fra uudgivet main | **Rettet** i deploy.sh: findes `logs/deploy.current` ikke, læses coworker.ini igen fra målets egen commit (releasen), før noget køres. main bruges kun til at finde målet. Testet (selftest-deploy): main har en php-sti, der ikke findes; STABLE udruller releasen med releasens php. Testen fejler med 5.33 |
| S2 | Første kørsel på en gammel klon læste coworker.ini fra en forældet origin/main | **Rettet:** uden `deploy.current` hentes origin/main, før coworker.ini læses. Testet sammen med S1 (klonens origin/main peger på en commit uden coworker.ini) |
| S3 | `server.sh` så kun ejerskab ét niveau ned i config/cron/logs | **Rettet:** rekursivt, og også de kodemapper, deploy.sh skriver i (`homedir/<mappe>/`), undtagen `public_html/uploads`. Beskeden peger på sysadmin, ikke på en root-kommando. Testet (selftest-ci): root-ejet fil i `cron/dyb/` findes |
| S4 | `server.sh check dev` testede ikke, at tokenet kan skrive commit-status | **Rettet:** DEV sætter også ved `check` én harmløs status `coworker/setup` på main. Testet (selftest-ci) |
| S5 | `server.sh` foreslog `su - <projekt>` (TIOCSTI) | **Rettet:** "SSH som projektets bruger, eller som root `su -P -l <projekt>`". Storix har målt `su -P` findes og `dev.tty.legacy_tiocsti = 0`; jeg har ikke målt det |
| S6 | Dump UDEN views stod kun som ADVARSEL i loggen | **Rettet** i deploy.sh og backup.sh: det er nu en alarm (incidents.log → /health → monitor opretter en sag, plus mail). Testet (selftest-deploy) |
| S7 | `logs/predeploy-db.err` blev skrevet uden umask 077 | **Rettet:** oprettes 600, før dumpet kører. Testet (selftest-deploy). Fejler med 5.33 |
| S8 | precheck på en framework-branch altid RØD på engineerens commit | **Ikke ændret.** Jeg kan ikke genskabe det fra koden alene: framework-hotfixen kræver de samme critic-reviews (`Branch: … @ <sha>`) og engineer-rapport som al tier 1, og selftestens framework-hotfix bliver grøn. Send de røde linjer fra precheck-outputtet, så rettes det |
| S9 | claude.lock skifter ved hver version, fordi guard.ps1's første linje har versionsnummeret | **Rettet:** guardens header har ikke længere et versionsnummer. Bekræftet ved diff af leverancerne: guard.ps1 5.28→5.33 afviger KUN i linje 1 (versionsnummeret). `.claude/agents/*.md` har stadig `FILE_VERSION` og skifter derfor hash ved hver version — det er bevidst: claude.lock skal følge den version, der er kopieret ind |
| S10 | `${DOWNED[@]}` fejler med `set -u` i bash < 4.4, når arrayet er tomt | **Rettet** i ci.sh: `${DOWNED[@]+"${DOWNED[@]}"}`. Ikke testet på bash < 4.4 (Ubuntu 24.04 har 5.2) |
| S11 | Et security-review med flere MEDIUM/LOW-fund blev godkendt af ét DECISIONS-afsnit | **Rettet:** antallet af afsnit med ID, "Accepteret risiko" og "Udløber" skal være mindst antallet af fund. Testet (selftest): to fund + ét afsnit = rød, to afsnit = grøn |
| S12 | `access` godkendte en nøgle uden Contents: write (H042) | **Allerede rettet i 5.25** (`git push --dry-run` mod en ikke-eksisterende branch beviser skriveadgang). Kører Storix 5.25+, og ser det stadig sådan ud, er det en ny fejl — send outputtet |
| S13 | Guarden blokerede download af PHPStan-phar (`curl -fsSL …/releases/download/…`): `-f` blev læst som gh's `--field` | **Rettet:** gh api og curl dømmes hver for sig. curl blokeres kun med sine egne skrive-flag (`-X`, `-d`, `-F`, `-T`, `--data`, `--form`, `--upload-file`, `--json`, store/små bogstaver skelnes). Testet (selftest-guard): phar-download tilladt; `curl -X POST`, `curl --data`, `gh api -f` mod releases blokeret |
| S16 | Kun `mariadb-dump`; ældre maskiner har kun `mysqldump` | **Rettet** i deploy.sh og backup.sh (som ci.sh allerede gjorde) |
| S18 | `server.sh` meldte `migrations/0108_…cursor_token.sql` som hemmelighed | **Rettet:** `*token*` tæller ikke for .sql/.php/.md/.js/.ts/.html/.twig. `*.token`, `github.token`, .ini/.env/.key/.pem/.pass tæller stadig. Testet (selftest-ci) |
| S15 | Undtagelse i LIFECYCLE §6 (Storix' DECISIONS #74) | **Afventer:** jeg har ikke teksten i DECISIONS #74. Send afsnittet |
| S10–S19 (server) | `sitectl db` unicode_ci, read-only DB-bruger, nye sitectl-flag på den grønne liste, CI-bruger/sudoers, `--no-ssl`/WANT_SSL, open_basedir, testmail | **Sysadmins** (SERVERKONTRAKT). Sendt til `_til-sysadmin`. Projekterne bruger kun kontraktens grønne kommandoer, til han svarer |

### Ikke verificeret
- S1/S2 er kun testet med lokale stubs for GitHub; ikke på en rigtig maskine.
- S3: en meget stor `vendor/` gør `server.sh check` langsommere (rekursiv `find`). Ikke målt.
- S11: tællingen bygger på linjerne `Severity: MEDIUM|LOW` eller `# Severity` + næste linje i reviewet. Et review, der nævner sværhedsgraden i en tabel, tælles ikke.

## v5.33 — 2026-10-20

Grundlag: Storix' fund 2026-10-20: deploy.sh dumper databasen før migrationer. Har databasen et ugyldigt objekt fra
en ældre migration (Storix: viewet `subscriptions`, fejl 1356 i mariadb-dump), blokerer dumpet også den migration,
der retter det — miljøet kan aldrig komme videre af sig selv.

| ID | Fund | Afgørelse og test |
|---|---|---|
| V1 | Dumpet før migration fejler på et ugyldigt view og stopper den migration, der retter det | **Rettet** i deploy.sh og backup.sh (som deploy kalder, når projektet har sin egen): fejler det fulde dump, prøves igen med alle tabeller og uden views (`--ignore-table` pr. view). Data ligger i tabellerne; views genskabes af migrationerne. Det står som ADVARSEL i deploy-loggen med views og dumpets fejltekst. Fejler også andet forsøg, stopper deploy som før — nu med fejlteksten i alarmen. Testet (selftest-deploy, rigtig MariaDB): view gjort ugyldigt → deploy kører, dumpet har tabellernes data. Testen fejler med 5.32 |
| V2 | Ingen kontrol af, at en ren installation kan læses og dumpes (Storix havde lavet sin egen) | **Ny framework-kontrol i CI for alle projekter:** efter alle migrationer på den tomme testdatabase skal hvert view kunne læses, og `mariadb-dump` af hele basen skal lykkes — samme dump, som deploy tager. Testet (selftest-ci): en migration, der efterlader et ugyldigt view, er rød. Testen fejler med 5.32. Storix' egen test kan blive stående |

### Ikke verificeret
- Maskinens `site-backup` (sysadmins) melder FEJL for en base med et ugyldigt view. Det er rigtigt — det er en fejl i projektets database — men sysadmin er orienteret, så alarmen ikke tolkes som en backupfejl.
- Fejl 1356 er kun genskabt med et view, hvis kolonne er fjernet. Andre ugyldige objekter (fx en trigger på en slettet tabel) giver andre fejlkoder og er ikke testet.

## v5.32 — 2026-10-20

Grundlag: fund fra et projekts Team Lead (videregivet af ejeren 2026-10-20): down/up-løkken i `ci.sh` lader den
ældste nye migration få det sidste ord.

| ID | Fund | Afgørelse og test |
|---|---|---|
| M1 | Løkken kørte down+up for én migration ad gangen, nyeste først. Fjernede den ældres down en tabel, som en nyere migration havde ændret, genskabte den ældres up tabellen uden den nyere ændring — testene kørte på et forkert skema | **Rettet, anderledes end foreslået:** forslaget var en ekstra up-runde bagefter. I stedet: alle downs fra nyeste til ældste, derefter alle ups fra ældste til nyeste — samme rækkefølge som en rigtig tilbagerulning og genudrulning, så hele kæden testes, ikke kun hver migration for sig. Stopper ved den første nye migration uden down-fil. Testet (selftest-ci, rigtig MariaDB): 0003 opretter `t3`, 0004 tilføjer kolonne `b`, 0003's down dropper `t3` → kolonnen findes efter CI. Testen fejler med 5.31's ci.sh |

### Ikke verificeret
- En kæde, hvor en ældre migrations down kun virker, når en nyere migrations down har kørt først, har altid været forudsat; nu testes den. Projekter med sådanne downs i forkert stand bliver røde i CI ved næste migration — det er en rigtig fejl, ikke en falsk alarm.

## v5.31 — 2026-10-20

Grundlag: en Team Lead svarede ejeren "der findes ikke én samlet launchplan". Ejeren: der burde ligge en klar
køreplan for, hvad der er planlagt nu — features samlet i forventede releases (det, der går på STABLE samlet).

| ID | Fund | Afgørelse og test |
|---|---|---|
| L1 | Frameworket kendte kun den enkelte feature (`plan:`) og den enkelte release, når den laves — ikke en planlagt release | **Ny:** `release: <navn>` på ROADMAP-linjen samler features til én release (fx projektets fase `R2`); uden den er releasen planens måned. `report.sh launchplan` viser hver planlagt release i rækkefølge med forventet måned, hver feature med målt status (ikke startet / i gang / på main / på TEST / på STABLE, flag, OVERSKREDET), hvad der mangler (Kunde/Support-tekst, acceptkriterier uden test) og de fælles forudsætninger (CI på main, TEST, STABLE, backup/restore). Team Lead svarer på "launchplan" med den. Testet (selftest) |
| L2 | `report.sh` døde tavst (exit 128, intet output), når en release-tag ikke fandtes lokalt — sysadmins fejltype (`x=$(…)` under `set -e`) i frameworkets ældre kode, fundet af L1's test | **Rettet** i `warm()`. Rammte `roadmap`, `launchplan`, `month`, `releases`, `check` og `notes` |

### Ikke verificeret
- `launchplan` er ikke kørt på et projekt med rigtige releases. "På TEST" kræver, at tagget `test` kan hentes fra GitHub.

## v5.30 — 2026-10-19

Grundlag: sysadmin-Leads `FUND-site-backup-2026-10-18.md` (version 2.0, 2026-10-19). Maskinens backup kører nu i
drift på stable (7 sites, 10 databaser, off-site til Google Drive, prøve-gendannelse ok). Sysadmin fandt to ting
i frameworket og én fejltype, der også gælder frameworkets scripts.

| ID | Fund | Afgørelse og test |
|---|---|---|
| U1 | `server.sh` rådede `root: dnf install python3` — Rocky; maskinerne er Ubuntu 24.04 | **Rettet:** "maskinens sag: spørg sysadmin-subagenten". Samme oprydning i OPSAETNING (CWP-terminal, "opret i panelet", dnf), backup.sh (apt), ACCESS/DRIFT-skabelonerne og AABNE-HULLER A4 |
| U2 | Testdatabasen til CI blev oprettet "i panelet" | **Rettet:** `sitectl db <DEV-domæne> ci` (kontraktens grønne kommando) og værdierne fra `sitectl creds`. `server.sh` siger det samme, når testdatabasen mangler |
| U3 | `x="$(kommando)"` under `set -e` dør uden et ord, når kommandoen fejler (sysadmins fejltype) | **Rettet i 5.28/5.29's nye kode:** en ulæselig logfil stopper ikke længere `forbrug`/`spild` tavst — de kører videre, og awk's fejl står i outputtet. Testet med en log uden læseret |
| U4 | Navnesammenfald `bootstrap` | **Intet at gøre:** sysadmin omdøber sin fil til `01-serveropsaetning.sh`. Frameworket har ingen `bootstrap.sh` |

### Ikke verificeret
- Resten af report.sh, ci.sh, deploy.sh og monitor.sh er ikke gennemgået for fejltypen i U3. De har haft deres egne selvtests i flere versioner, men en selvtest finder kun det, den prøver.

## v5.29 — 2026-10-19

Grundlag: ejeren vil kunne spørge Team Lead, hvorfor frameworket tager lang tid, og hvor der spildes tokens — og få
et kort svar fra projektets filer, ikke en rapport. Det samme gælder alt, han spurgte om 2026-10-19 (de syv
dokumentationsgrupper).

| ID | Fund | Afgørelse og test |
|---|---|---|
| S1 | Ingen måde at se, hvor tiden og tokens går | **Ny:** `report.sh spild [periode]` fra Claude Codes logs + git: (1) tid pr. kommando/værktøj (fra kald til svar, også ventetid som `sleep` og subagenter), (2) de længste sessioner med største kontekst og tokens ind, (3) prechecks pr. ID (3+), (4) filer læst 3+ gange af samme agent, (5) critic-runder pr. ID (v2+). Testet (selftest) med syntetiske logs inkl. Windows-sti i en worktree; kørt mod vedligeholderens egne rigtige logs (viste én session på 149 timer med op til 777 k kontekst pr. svar) |
| S2 | Team Lead skal kunne svare på ejerens spørgsmål uden at lave dokumenter | **Ny tabel** i `agents/team_lead.md`: spørgsmål → kommando eller fil for roadmap, arbejdets art, tid, spild, ændringer og godkendelser, release, beslutninger, nedbrud, system, drift og sikkerhed. Svar kort i chatten; findes noget ikke: "findes ikke i projektet". Ingen rapport, medmindre han beder om det |
| S3 | `forbrug` og `spild` skal finde de samme logs | Fælles `log_files` i report.sh |

### Ikke verificeret
- `spild` er ikke kørt på ejerens pc. Kommandokategorierne er gættet ud fra kommandoens første ord; en kommando, der starter anderledes på Windows, kan få sin egen række.
- Tid for et værktøjskald inkluderer ventetid på godkendelse fra mennesket, hvis kaldet krævede det.

## v5.28 — 2026-10-19

Grundlag: ejerens krav (2026-10-19): en udviklingsafdeling skal kunne svare på hvad, hvorfor, hvornår, af hvem og
hvad gør vi, når det går galt — herunder fordelingen af tid på feature, fejl og hotfix, og teknisk gæld og
sikkerhed som egne kategorier. Dertil EnvoySubscriptions' Team Lead om pre-release-valget og sysadmin-Leads svar
2026-10-16 (S5, §6a).

| ID | Fund | Afgørelse og test |
|---|---|---|
| A1 | Fejl og hotfix hed begge H; gæld og sikkerhed fandtes ikke som kategori | **Indført:** `Type: feature \| fejl \| hotfix \| gæld \| sikkerhed` i hvert nyt `changes/<ID>.md`, krævet af CI. `Type: hotfix` (kritisk på STABLE, kan ikke vente) kræver `Hvorfor nu: …`. Gamle ID'er uden Type tælles ud fra bogstavet (F = feature, H = fejl — ikke hotfix). Testet (selftest): uden Type afvist, hotfix uden "Hvorfor nu" afvist, med godtaget |
| A2 | Ingen fordeling af arbejdet pr. måned | **Ny:** `report.sh fordeling <periode>` — antal færdige ID'er pr. måned pr. type + andel hotfix. Testet |
| A3 | Ingen måling af tid | **Ny:** `report.sh forbrug [periode]` læser Claude Codes egne sessionslogs på pc'en (`~/.claude/projects/<projektmappe>` + worktree-mapper): aktiv tid (pauser under 10 min) og tokens pr. ID, type og agent. Hvert modelsvar tælles én gang (`requestId`; Claude Code gentager forbruget på 1–3 linjer pr. svar). ID fra branchen; critics/tester på main fra opgavebeskrivelsen i subagentens `.meta.json`; resten står som "Styring/uden ID". Testet (selftest) med syntetiske logs i det format, ejerens Storix-logs viste 2026-10-19 (Claude Code 2.1.295), og kørt mod vedligeholderens egne rigtige logs |
| R1 | Et kontroludkast udgivet uden "Set as a pre-release" blev mål for STABLE (`ctrl-2026.10.09-3`) | **Rettet:** deploy.sh, monitor.sh og report.sh behandler tags, der begynder med `ctrl-`, som kontrol uanset valget i GitHub. Testet (selftest-deploy): `ctrl-3` udgivet som almindelig release efter en kunde-release → STABLE kører kunde-releasen. Testen fejler med 5.27's deploy.sh |
| R2 | GitHubs tre valg (None, pre-release, latest) var uklare | Team Leads besked siger kun "Vælg 'Set as a pre-release' og tryk Publish". Frameworket bruger ikke "latest" |
| G1 | `_claude\` er ikke låst — git-ignoreret, så CI kan ikke se den (sysadmin S5) | **Rettet:** `report.sh lock` skriver `shared/claude.lock` (sha256 af guard, agenter, settings.json), selv låst af framework.lock. `opdater-pc.ps1` kopierer ikke, når en fil afviger eller er ukendt. Trin 0 tjek 4 = `report.sh claude`. Testet (selftest-guard, pwsh 7) |
| K1 | Nøglemateriale i samme backup som databasedumpet (sysadmin §6a) | **Regel** i LIFECYCLE og fullstack_engineer.md: krypteringsnøgler aldrig i `config/`, men i `/etc/<app>/keys/` |

### Ikke verificeret
- `report.sh forbrug` er ikke kørt på ejerens pc (Git Bash på Windows). Logformatet er bekræftet ud fra feltnavne og branchnavne i Storix' logs, ikke ud fra hele filer.
- Aktiv tid er et skøn: tid mellem to modelsvar under 10 min. Tid, hvor mennesket læser uden at skrive, og lange værktøjskørsler over 10 min tæller ikke.
- Tid fra andre pc'er og fra Claude-chat uden for Claude Code er ikke med.
- `opdater-pc.ps1` er testet med pwsh 7 på Linux, ikke med Windows PowerShell 5.1.
- Sysadmins fulde backup er ikke med; den kommer i en senere version, når den er målt på stable.

## v5.27 — 2026-10-15

Grundlag: ejerens beslutning 2026-10-15: "én fælles restic, som sætter backup op for alle sites den kan se — hvert
projekt skal blot sikre sig, at det er en del af backuppen", og ingen betalt backup-destination. Dertil
sysadmin-Leads leverance (`til-coworker/`: `SERVERKONTRAKT.md`, sysadmin-stub, fire ønsker til guard/lås/Team Lead).

| ID | Fund / ønske | Afgørelse og test |
|---|---|---|
| B1 | Backup pr. projekt (rclone-login, restic.pass, `[backup]`, cron i hver hjemmemappe) | **Ændret:** backup er maskinens. `report.sh install` lægger ikke længere `backup.sh` i et nyt projekt (opdaterer den kun, hvor den findes). Testet (selftest): nyt projekt uden, eksisterende opdateres |
| B2 | Maskinens `site-backup` lå kun på samme disk, tog ikke `config/` (config.ini) med, prøvede én database om ugen og gav projektet intet bevis | **Rettelse til sysadmin** i `_til-sysadmin/` (sysadmin ejer og installerer den): `config`+`var` i arkivet; prøve-gendannelse af hver database mindst hver 7. dag med rækketal (som backup.sh); restic væk fra maskinen (ét repository og ét kodeord pr. maskine, Google Drive med `drive.file`); bevis i hvert sites `logs/backup_last_ok`/`restore_last_ok`, skrevet som site-brugeren, kun når sitets filer og alle dets databaser er sikret og pushet. Testet: `selftest-site-backup.sh` 24/24 mod rigtig MariaDB, brugere og restic (uden off-site → IKKE-OK og intet bevis; database mangler → navngivet; dump uden rækker → prøven fejler; symlink i `logs/` følges ikke) |
| B3 | Projektet skal kunne se, at det er med | **Tilføjet:** `server.sh check` viser `✔ med i serverens backup` / `!` (ikke ✘ — et nyt miljø er først med efter en nat). `check`/monitor peger på sysadmin. Testet (selftest-ci): intet, frisk og gammelt bevis |
| B4 | Uden backup.sh tog deploy ingen kopi før en migration | **Tilføjet:** deploy.sh dumper databasen som projektets bruger til `logs/predeploy-db.sql.gz` (600) før nye migrationer; fejler dumpet, skiftes intet. Testet (selftest-deploy, rigtig MariaDB) |
| S1 | Sysadmin-rolle: stub + kontrakt | **Indført:** `_claude/agents/sysadmin.md` (Read/Grep/Glob, ingen Bash) og `shared/SERVERKONTRAKT.md` (1.1: §2/§7/§9 rettet efter backup-beslutningen). Team Lead og CLAUDE.md: serverbehov → sysadmin; "ikke besluttet" = blokeret |
| S2 | `sysadmin` på guardens read-only-liste | **Indført.** Testet (selftest-guard) |
| S3 | `fullstack-engineer` på read-only-listen | **Afvist:** engineers skal skrive kode. Serverkommandoer stoppes af S4 for alle |
| S4 | Blokér root-kommandoer, match mod den rå kommando | **Indført, strammere end foreslået:** kun når `ssh`/`sudo`/`su` er verbet (en commit-besked med "chown" er fri). Undtagelsen for `sitectl` er udeladt — ingen af kontraktens sitectl-kommandoer rammer mønstrene, og undtagelsen ville lade `ssh h 'sitectl x; useradd y'` slippe igennem. Testet: `ssh root@h 'useradd x'`, `sudo apt install`, GRANT og systemctl via ssh blokeres; læsning via ssh og commit-besked tilladt |
| S5 | Lås `_claude/hooks/guard.ps1` og `_claude/agents/*.md` i framework.lock | **Afvist:** `_claude/` er git-ignoreret i projekterne (`/_claude/`), så CI kan aldrig se den. Guardens aktualitet på pc'en tjekkes af Trin 0 tjek 4 (`diff -rq _claude .claude`) og `opdater-pc.ps1`. `SERVERKONTRAKT.md` ligger i `shared/` og er låst |

### Ikke verificeret
- `site-backup`-rettelsen er ikke kørt på en rigtig maskine, og restic er ikke prøvet mod Google Drive (kun et lokalt repository). Indtil sysadmin har installeret den og sat `OFFSITE_REPOSITORY` op, har projekter uden egen backup.sh intet bevis — `server.sh check` og `check` siger det.
- `servercheck` er læst, ikke kørt: de nye MANIFEST-linjer (`offsite`, `bevis`) skulle ignoreres (præfiksmatch), og flere `restoretest`-linjer pr. nat læses som den sidste.

## v5.26 — 2026-10-15

Grundlag: Storix-fejlrapport (5.25) punkt 1–8, inkl. målingen på H023: under 1 times arbejde, resten ventetid; precheck
kørt 4 gange á 6–11 min (altid rød — ingen database på Windows); DEV-CI flere gange, bl.a. efter unødvendige opdateringer.

| ID | Fund | Afgørelse og test |
|---|---|---|
| T1 | KRITISK: `composer audit` altid rød i CI — `COMPOSER=<sti til composer>` stod i CI-brugerens miljø, og composer læser den som navnet på composer.json | **Rettet.** Variablen hedder `CW_COMPOSER`, og audit kører med `env -u COMPOSER`. Testet (selftest-ci): en composer-stub, der fejler som JsonLint, hvis `COMPOSER` står i miljøet, kaldes som `php composer audit` under CI-brugeren — ingen JsonLint |
| T2 | Audit skelner ikke fund fra "kunne ikke køre" | **Rettet.** `--format=json`: kan svaret ikke læses → `RØD: … — kunne ikke køre (ikke en sårbarhed)` + composers fejltekst; sårbarheder navngives (`SÅRBARHED: pakke ID titel`) → `— sårbarhed fundet`; forladte pakker vises, men er ikke røde. Testet: alle tre udfald |
| T3 | Composer tjekkes kun som DEV-brugeren | **Rettet** i `server.sh` og i CI's isolationstjek: CI-brugeren skal kunne læse composer (`readlink -f`) |
| T4 | Ingen test af composer audit | **Rettet:** fire tjek i selftest-ci (ovenfor) |
| T5a | DECISIONS-krav dukker først op i CI | **Rettet:** security-critic slutter et godkendt review med fund med `# KRÆVER FØR MERGE`; team_lead.md: DECISIONS-posterne i samme review-docs-commit |
| T5b | known-failures og scripts, der er én case | **Dokumenteret** i fullstack_engineer.md (læg betingede tests i egen fil/case) |
| T6a | Frisk merge kaldt "direkte push" | **Rettet:** main-commit under 15 min uden PR-link hos GitHub venter til næste kørsel. Testet |
| T6b | deploy.sh læser coworker.ini før fetch | **Afvist:** den læses med vilje fra den UDRULLEDE commit (kontrolgaten — en ny commit kan ikke ændre deploy-indstillinger uden Publish). Kun allerførste kørsel bruger origin/main |
| T6c | Filmode på `shared/install/*.sh` | **Ikke en fejl:** de køres med `bash`, og de installerede kopier i `homedir/cron/` får x-bit (5.21) |
| T6d | server.sh: ejerskab og "Commit statuses"-rettighed | Rettighed: findes (setup skriver en prøvestatus). **Ejerskab tilføjet:** `config/`, `cron/`, `logs/` skal ejes af projektets bruger |
| T6e | `check` melder en pre-release som slettet | **Rettet:** udgivne pre-releases tælles med |
| T7a | Grøn status følger ikke med docs-commits | **Rettet:** CI genbruger de tunge trin, når koden (alle filer undtagen `*.md`) er den samme som i en grøn kørsel. Testet: docs-commit oven på grøn PR = ingen ny tung kørsel, grøn |
| T7b | `gh pr update-branch` hver gang | **Rettet:** `report.sh behov-opdatering <branch>` siger kun `OPDATÉR` ved konflikt eller ændret kode/tests/kontrolfiler på main. Testet: docs, kode, konflikt |
| T7c | Alle krav på én gang | Se T5a |
| T7d | Planen som første commit på arbejdsbranchen | **Rettet for spec + impact.** **Afvist for ROADMAP-linjen:** parallelle branches, der alle skriver i ROADMAP, giver merge-konflikter (afvist af samme grund i 5.2) — linjer for flere ID'er samles i én docs-PR |
| T7e | Test på en rigtig server før udgivelse | **Kan ikke gøres herfra** (vedligeholderen har ingen server). Står i AABNE-HULLER A4. Forslag til ejeren: ét kanariprojekt får hver ny version først |
| T8 | Precheck på Windows altid rød, 6–11 min | **Rettet:** precheck kører kun de hurtige kontroller (adgang, framework-kontroller, lint af ÆNDREDE php-filer) og skriver `Tests køres på DEV-serveren (status coworker/pr)`. Lokale tests kun med `PRECHECK_TESTS=1`. **Afvist:** WSL + Docker + lokal MariaDB på hver pc — et ekstra driftsmiljø at holde i trit med DEV's versioner; DEV afgør alligevel |

### Ikke verificeret
- Rigtig composer mod packagist (ingen adgang herfra): selve JSON-formatet fra `composer audit --format=json` er fra composers dokumentation, ikke kørt med rigtige sårbarheder.
- `git merge-tree --write-tree` kræver git ≥ 2.38 på ejerens pc (testet med 2.43); ældre git giver `OPDATÉR` (sikker side).
- Tidsbesparelsen er ikke målt; Storix' næste ID viser den.

## v5.25 — 2026-10-15

Grundlag: Storix, installation af 5.24 (2026-10-15) — fire fund, heraf security-critic H042 fund 1–3.

| ID | Fund | Afgørelse |
|---|---|---|
| S1 | `report.sh access` gav falsk `ADGANG: OK`: `gh api … .permissions.push` viser kontoens rolle (admin), ikke den fine-grained nøgles. Nøglen manglede Contents: write | **Rettet.** Skriveadgang prøves af git selv: `git push --dry-run origin HEAD:refs/heads/cw-adgangsprobe` (receive-pack kræver skriveret; sender og opretter intet). gh-tjekket er kun "svarer" |
| S2 | MEDIUM: `[ci] phpstan` fra den TESTEDE commit blev brugt som sti og composer-version af DEV-brugeren (`composer require` uden `--no-scripts`) → kode som DEV-brugeren | **Rettet, grundigere end foreslået.** PHPStan er nu phar fra GitHub-releasen, sha256-låst i ci.sh (1.12.7) — ingen composer, ingen scripts, ingen sti fra koden. En anden `[ci] phpstan` i den testede commit giver rødt trin med besked. Lukker også A3 for PHPStan |
| S3 | LOW: isolation på filnavne, ikke rettigheder; ingen kontrol af databaserettigheder | **Rettet:** `server.sh setup` lukker `config/`, `private/`, `logs/`, `ci/`, `.ssh/` for "andre" på mappeniveau; `server.sh` tjekker `SHOW GRANTS` (intet på `*.*`, intet på DEV's database). **Afvist:** 711 på hjemmemappen + 700 på alle undermapper — `public_html/` skal kunne læses af webserveren. Netværk: uændret (AABNE-HULLER A1) |
| S4 | deploy.sh skriver `config/deployed_sha.php` 644 ved hver udrulning | **Bekræftet.** Uden betydning efter S3 (mappen er lukket). Skrives nu med `umask 027` |

**Projekterne:** kør serveropsætningens linje igen på DEV (`setup dev`) — den lukker mapperne og henter PHPStan-phar'en.
En `[ci] phpstan`-linje i coworker.ini skal fjernes (eller være 1.12.7).

Testet: selftest 147 (access: push-url uden skriveret → "git kan ikke skrive"), selftest-ci 83 med rigtige Linux-brugere,
rigtig MariaDB og nu RIGTIG PHPStan 1.12.7 (sha256-tjekket): PR med `[ci] phpstan = ../../ci/ond` → rødt trin, intet
oprettet; setup lukker `private/` også med en 644-fil derinde; verdenslæsbar fil i `config/` er utilgængelig; testbruger med
SELECT på DEV's database afvises. Uden rettelserne fejler de nye tjek.

### Ikke verificeret
- `git push --dry-run` mod GitHub med en nøgle uden Contents: write (afvisningen er GitHubs dokumenterede receive-pack-adfærd; testet med en lokal push-url).
- At webserveren på CWP/Ubuntu ikke læser `config/` eller `logs/` direkte (PHP kører som projektets bruger; `config.ini` er allerede 600).

## v5.24 — 2026-10-14

Grundlag: econsync (5.23) — Trin 0 tjek 4 fejlede (`.claude` 5.22, `_claude` 5.23), og nøglen pr. projekt skulle sættes
projekt for projekt. Ejeren: "Hvordan gør vi det her nemt?"

| Ændring | Problem |
|---|---|
| `shared/opdater-pc.ps1`: én kommando for hele pc'en. For hvert projekt i `C:\ClaudeCoworkers` (har `agents/team_lead.md` og `_claude`): kopierer `_claude` → `.claude` (sletter intet) og spørger KUN efter nøglen, hvis projektet ikke har sin egen (Enter = spring over; resten af `settings.local.json` bevares). Slutter med, om alle har en nøgle, og først da `gh auth logout` | Kopi-linje + nøgle-linje pr. projekt |
| Trin 0 tjek 3 og 4 sender mennesket den ene kommando | — |

Testet (pwsh 7, rigtig terminal via pty): to projekter + master + en mappe uden framework — master og fremmed mappe springes
over; projekt med nøgle får `.claude` opdateret og `settings.local.json` urørt; projekt uden nøgle får nøglen (skjult
input) og helperen; Enter springer over og meldes som MANGLER. Alle fire selftests.

### Ikke verificeret
- Windows PowerShell 5.1 og rigtige Windows-stier (`C:\ClaudeCoworkers`); skriptet er ASCII for at undgå tegnsætsfejl i 5.1.

## v5.23 — 2026-10-14

Grundlag: econsync 2026-10-14 — GitHub-adgang forsvandt midt i arbejdet (403 på push, 404 på `gh api`). Ejeren har én
nøgle pr. repo, men gh har kun plads til ét login på hele pc'en. Claude Code-logs viser `gh auth login/switch/…` fra
sessioner i EnvoyAgent, EnvoyLawyer, EnvoySubscriptions og TrygEnergi-Economics: den sidste, der loggede ind, tog
adgangen fra de andre. Ejerens krav: projekterne må ikke kunne skade hinanden.

| Ændring | Problem |
|---|---|
| Nøgle pr. projekt: `GH_TOKEN` + git-credential-helper i `.claude/settings.local.json` (`env`), sat med én afprøvet PowerShell-linje (OPSAETNING §4), der kun tilføjer — resten af filen bliver stående. gh's fælles login fjernes (`gh auth logout`) | Ét fælles login for alle projekter |
| `report.sh access`: GH_TOKEN sat, git bruger den (`credential.https://github.com.helper` = gh), `git ls-remote origin`, `gh api repos/<repo> .permissions.push` = true → `ADGANG: OK`; ellers en præcis besked. Trin 0 tjek 3 bruger den og STOPPER | Trin 0 så kun, at gh var logget ind — ikke at det var DETTE repos nøgle |
| `precheck` tjekker adgang først: `PRECHECK: RØD — ingen GitHub-adgang, ikke en kodefejl`; `ci` siger det samme, når `git fetch` fejler | 403/404 lignede en kodefejl |
| Guard: ingen agent kører `gh auth login/logout/switch/refresh/setup-git`, nævner `GH_TOKEN` eller dumper miljøet (`env`, `printenv`) | Et projekt kunne skifte login for alle; nøglen må ikke ende i en log |

Testet: git-credential-helperen fra miljøet vinder over et globalt gemt login (rigtig git + gh 2.62.0: projektets nøgle
leveres, ikke den fælles). PowerShell-linjen: ny fil, tom fil, fil med andre nøgler og fil med anden `env` — alt andet
bevares (pwsh 7). selftest 146 (access: uden nøgle, git uden projektets helper, origin uopnåelig; precheck og ci),
selftest-guard 27.

### Ikke verificeret
- Windows PowerShell 5.1 (testet med pwsh 7 på Linux) og Windows' Git Credential Manager.
- At Claude Code giver `env` fra `settings.local.json` videre til subagenternes Bash (dokumenteret adfærd for settings, ikke kørt her).
- `gh api … .permissions.push` mod GitHubs rigtige API.

## v5.22 — 2026-10-13

Grundlag: ekstern gennemgang af hele frameworket (9 fund + 4 små). Hvert fund er læst mod koden og udfordret.

| ID | Fund | Afgørelse |
|---|---|---|
| G1 | Tier 2 har ingen critic | **Rettet.** Tier 2 kræver én critic (security-critic ved input/data/adgang/ekstern modtager, ellers qa-critic) med samme kontroller som tier 1: APPROVED, `Branch: … @ <sha>`, patch-id. Den foreslåede undtagelse (tier 2 uden `homedir/`) findes allerede: uden kodeændring kræves ingen tier |
| G2 | Samme model skriver og godkender | **Rettet.** `_claude/agents/`: engineer `model: opus`; qa-critic, security-critic og tester `model: sonnet`. Mindsker fælles blinde vinkler, fjerner dem ikke (samme leverandør → AABNE-HULLER A6) |
| G3 | System understanding håndhæves ikke og kommer efter koden | **Rettet.** Tier 1–2: `fullstack-ai/<ID>_v0.md` som branchens første commit (CI: findes, overskrift, mindst to filstier der findes, committet før kode i `homedir/`). Engineeren stopper; Team Lead læser og siger "go". Rollefil og skabelon siger det samme. Undtaget: `-revert-` og `-framework-`. **Afvist:** critic på v0 — de to critics læser v0 sammen med diffen ved tier 1; en ekstra runde før kode koster mere end den fanger |
| G4 | Critics ankres af engineerens fortælling; ser aldrig hinanden | **Delvist.** Critics får kun rådata (spec, impact, branch, SHA) og læser v0/rapporten SIDST, kun for at finde påstande diffen ikke bærer. **Afvist:** krydsrunde mellem critics — fordobler critic-kørslerne på hver tier 1 og sender uenighed til ejeren, som kun trykker Publish/"go"/hemmeligheder. Står i AABNE-HULLER A7 |
| G5 | Hotfix uden regressionstest | **Rettet.** Hotfix (tier 1–2) med kodeændring skal tilføje/ændre en fil i `tests/` eller `homedir/tests/`, ellers `Ingen test: <grund>` i fragmentet. Revert og `stable = off` er uændret hurtige |
| G6 | Frameworkets egne huller har ingen frist; ingen måler critics | **Rettet.** `shared/AABNE-HULLER.md` (A1–A7) med `Udløber:`; `report.sh lint` fejler ved udløb (vedligeholderen — projekter kan ikke lukke frameworkets huller, så det er ikke i `check`). `report.sh check`: en critic med ≥ 10 reviews på 90 dage og ingen CHANGES_REQUIRED nævnes |
| G7 | Uopnåelig /health ligner en backup-fejl | **Rettet.** `check` og Launch-tjek siger "svarer ikke herfra — netværk/VPN/firewall, eller STABLE er nede". Launch-tjek blokerer stadig |
| G8 | Accepterede critic-fund forsvinder | **Rettet, smallere.** Security-critic APPROVED med MEDIUM/LOW → CI kræver en DECISIONS-sektion med ID, "Accepteret risiko" og `Udløber:`. QA-fund er ikke risikoaccepter og er ikke omfattet |
| G9 | Critics kan skrive via Bash | **Rettet.** guard.ps1: qa-critic, security-critic, tester og designere (`agent_type`) må ikke skrive (Write/Edit, `>`, tee, `sed -i`, cmdlets, cp/mv, inline-scripts, scripts) eller ændre git (checkout, commit, reset …). Engineers uændret. Afløser afslaget i v5.4 (kræver ny `.claude/` — leveres nu via Trin 0 tjek 4) |
| G10 | Rollefiler henviser til udgået WORKFLOW.md | **Rettet** + lint-mønster |
| G11 | Designere reviewer ukommitteret kode i worktree | **Rettet.** `git diff origin/main...origin/<branch>`, `Branch: … @ <SHA>`; designer-stubbe får Bash (read-only via guarden) |
| G12 | Semgrep/PHPStan ikke hash-låst | **Udskudt** (AABNE-HULLER A3). PHPStan-phar'ens sha256 er målt; Semgreps hash-liste kan ikke laves for serverens Python herfra, og en forkert liste stopper al CI |
| G13 | Fejlet deploy-SHA har ingen udvej | **Rettet.** `~/cron/deploy.sh <miljø> retry` + RUNBOOK-skabelonen. Ingen automatisk genforsøg: en reel fejl ville rulle ud og tilbage igen |

**Projekterne mærker:** tier 2 kræver nu én critic; tier 1–2 kræver v0 før koden; hotfix kræver en test. Engineeren
stopper efter v0 og venter på "go". Opdatér `.claude/` (Trin 0 tjek 4 — mennesket kører én kopi-linje), ellers
gælder modellerne og guard-reglerne ikke.

Testet: selftest 139, selftest-deploy 48, selftest-ci 77 (rigtige Linux-brugere), selftest-guard 21 — nye tjek for hvert
fund, bl.a. v0 efter koden og uden kilder afvises, tier 2 uden critic er rød, hotfix uden test er rød og grøn med
`Ingen test:`, LOW-fund uden DECISIONS er rødt, revert er undtaget, critics blokeres for redirect/`sed -i`/checkout/Write
men kan læse diffen, engineer kan stadig skrive, /health-besked, critic-statistik, deploy retry.

### Ikke verificeret
- At Claude Code sender `agent_type` med navnet fra `.claude/agents/` (dokumenteret, ikke kørt i Claude Code her —
  guard-testen giver feltet selv). Mangler feltet, er critics som før ikke omfattet.
- At `model: opus` / `model: sonnet` er tilgængelige på ejerens konto; at Sonnet som critic finder det, Opus overser.
- At engineeren rent faktisk stopper efter v0: CI beviser rækkefølgen af commits, ikke at Team Lead læste den.

## v5.21 — 2026-10-13

Grundlag: deskcore (Rocky 8, CWP, brugeren `devdesk`), målt 2026-10-13.

| Ændring | Problem |
|---|---|
| PHPStan i `cron/ci.sh` får en genereret config (`~/phpstan-ci.neon` hos CI-brugeren) med `tmpDir` i CI-brugerens egen mappe, som tømmes før og efter hvert job; projektets `phpstan-baseline.neon` inkluderes | Standarden `/tmp/phpstan` deles af alle brugere: ejes af det første projekt, de andre fejler (`Unable to create file '/tmp/phpstan/cache/…'`). En fælles skrivbar mappe ville lade et projekt plante PHP-kode i et andets cache |
| `report.sh install` sætter execute-bit i git (`git update-index --chmod=+x`) | På Windows (`core.filemode=false`) blev filerne committet som 644, og deploy.sh's `rsync --executability` fjernede x-bit på serveren |
| `report.sh ci`/precheck: installeret `homedir/cron/*.sh` uden 100755 i git = rød, hvis branchen rører filen; ellers navngivet `ADVARSEL (install)` | Ingen kontrol af filmode |
| `deploy.sh` sætter `u+x` på `cron/deploy.sh`, `ci.sh`, `monitor.sh`, `backup.sh` efter hver udrulning; `server.sh` tjekker (og ved setup retter) x-bit | Servere med allerede mistet x-bit, og `[deploy] chmod` uden x |
| CLAUDE.md trin 4 + Grundregler + team_lead: ved FØRSTE installation (main uden `coworker.ini`/`homedir/cron/ci.sh`) kan branchen aldrig få `coworker/pr` (cron/ci.sh læser `[deploy]` fra main) — merge på `PRECHECK: GRØN`, serveropsætning, derefter grøn `coworker/ci` før Publish. Erstatter 5.20's undtagelse med en separat docs-PR | Install-hotfixen kunne ikke opfylde merge-reglen |

Testet: `selftest.sh` 122 (install med `core.filemode=false` giver 100755 i git; branch, der fjerner x-bit, er rød;
urørt fil uden x-bit er en advarsel), `selftest-deploy.sh` (cron-scripts uden x-bit er eksekverbare efter udrulning),
`selftest-ci.sh` med rigtige Linux-brugere (PHPStan kaldes med `-c` og `tmpDir` i CI-brugerens hjemmemappe, ikke `/tmp`).
Uden rettelserne fejler de nye tjek.

### Ikke verificeret
- PHPStan er en stub i selftest-ci (ingen adgang til packagist herfra): at rigtig PHPStan 1.12 accepterer den genererede
  config og skriver sin cache dér, er ikke kørt. Ikke kørt på deskcore.
- Ikke kørt på Windows: `git update-index --chmod=+x` er testet med `core.filemode=false` på Linux.

## v5.20 — 2026-10-13

Grundlag: econsync H012 (5.19) — en ny framework-version kunne ikke installeres uden en rød merge. Kopien skulle alene
på `docs/framework-<version>` (installerede filer forbudt dér), og installationen kom bagefter i en hotfix. Projekter med
egne byte-identitetstests (`homedir/cron/*` == `shared/install/*`) blev røde på docs-PR'en, og precheck dømte på
testløberens exit-kode, mens CI dømmer på junit.xml + known-failures — så known-failures kunne ikke redde den.

| Ændring | Problem |
|---|---|
| Framework-kopien (byte-identisk med `shared/framework.lock`) er tilladt på `hotfix/H###-framework-<version>` (tier 1) sammen med `report.sh install`-output, `coworker.ini` og baseline — ÉN merge | Kopi og installation kunne ikke gå ind samtidig |
| Uændret: framework-filer, der afviger fra låsen, er røde; framework-filer på enhver anden branch er røde; installerede filer skal være uændrede kopier af `shared/install/*` | — |
| `report.sh precheck` dømmer tests præcis som `cron/ci.sh`: junit.xml + `tests/known-failures.txt` (samme PHP-kode; selftest kræver, at de er ens) — ikke testløberens exit-kode | Precheck og CI kunne give forskellig dom |
| CLAUDE.md "Ny framework-version" trin 4–6: én branch, derefter serveropsætning og Publish. Første gang på 5.16+ (intet `homedir/cron/ci.sh` på main): den gamle rækkefølge | — |
| `/junit.xml` i `shared/gitignore.framework` (precheck skriver den i worktree'en) | — |

Testet (`selftest.sh`, 117 tjek, bl.a.): et projekt med egen byte-identitetstest og en altid fejlende testløber —
kopien alene på `docs/framework-*` gør testen rød (fejlen gengivet); kopi + install i én `hotfix/H022-framework-5.99`
er GRØN i både `report.sh ci` og precheck; en afvigende kopi, framework-filer på `hotfix/H023-andet` og en ændret
installeret fil er stadig røde; precheck er grøn, når testløberen fejler, men kun kendte røde står i junit.xml.

### Ikke verificeret
- Ikke kørt i econsync eller på en rigtig DEV-server: `coworker/pr` for den samlede branch kommer fra projektets nuværende
  `cron/ci.sh`, som kører branchens egen `report.sh ci` — det er testet i selftest, ikke på serveren.
- Precheck kræver lokal PHP til test-dommen (som før); uden PHP køres tests ikke lokalt.

## v5.19 — 2026-10-12

Grundlag: kendt svaghed fra 5.16 — koden, der testes, kørte som DEV-brugeren og kunne læse DEV's config og server-tokenet
(og dermed i princippet melde en vilkårlig commit grøn). Ejerens beslutning: separat CI-bruger.

| Ændring | Problem |
|---|---|
| Al kode fra den testede commit (tests, migrationer, PHPStan, Semgrep, composer audit, report.sh ci) kører som CI-brugeren `<dev-bruger>-ci` via `sudo -n -u`. DEV-brugeren henter koden, overfører den som git-bundle, og skriver selv status/kommentarer | PR-kode kunne læse token og config |
| Før hvert job: CI-brugeren må ikke kunne læse noget i `config/`, `private/`, `.my.cnf`, `.git-credentials`, `.ssh/`; ellers testes intet (status rød én gang + mail) | Isolation skal håndhæves, ikke antages |
| Før og efter hvert job dræbes alle CI-brugerens processer, og dens hjemmemappe tømmes; runneren ligger i `ci-tools/` (DEV ejer, CI kan kun læse); `GIT_CONFIG_GLOBAL=/dev/null`, `TMPDIR` i den tømte mappe | Én PR må ikke kunne påvirke et senere job |
| Output pr. trin højst 20 MB i DEV's log; `logs/` er 700 | En PR kunne fylde DEV's disk |
| Testdatabasens adgang gives som `my.cnf` (600) hos CI-brugeren — aldrig på en kommandolinje | — |
| `server.sh` (dev): CI-bruger findes, sudo-regel, CI-brugeren kan ikke læse DEV's hemmeligheder (findes som DEV, prøves læst som CI), ikke cron/at for CI-brugeren, kan nå `ci-tools/` (sætter `o+x` på hjemmemappen ved setup), kodeord uden `"`/`\` — viser root-kommandoerne ved ✘ | Én kommando fortæller, hvad der mangler |
| OPSAETNING: CI-brugeren oprettes med tre linjer som root (useradd, sudo-regel, cron.deny/at.deny) | — |

Fagligt review (subagent, Linux-sikkerhed) før levering fandt 4 fejl i første udkast; alle rettet: vedvarende processer/filer
hos CI-brugeren mellem jobs, ubegrænset log, for snævert læse-tjek, `"` i DB-kodeord.

Testet: `selftest-ci.sh` kører nu med RIGTIGE Linux-brugere (DEV, CI, sudo-regel, cron.deny) mod rigtig MariaDB — 74 tjek, bl.a.:
testene kører som CI-brugeren og kan ikke læse token, config.ini eller private/; uden sudo-regel eller med læsbar config.ini testes
intet (rød + mail), og efter rettelse testes PR'en; en efterladt fil og proces hos CI-brugeren er væk ved næste job; server.sh
finder læsbare hemmeligheder og manglende sudo-/cron-regler. Testen fejler, når isolationen fjernes.

### Ikke verificeret
- Ikke kørt på en CWP-server: om CWP's brugere, sudo, cron.deny og hjemmemappernes rettigheder opfører sig som på Debian i testen.
- Kode fra en PR har stadig fuld netværksadgang (fx til DEV's egen webapp og internettet), og den kan forfalske sit EGET resultat.
- Er der hemmeligheder i filer uden for de kendte navne/mapper og med læserettighed for alle, finder tjekket dem ikke.

## v5.18 — 2026-10-12

Grundlag: econsync H007 (2026-10-12): `coworker/ci` = failure på main med "den reviewede commit <sha> findes ikke i repoet".
Repoet har "Automatically delete head branches" slået til; efter squash-merge var PR-branchen væk, og `cron/ci.sh`
hentede kun `refs/heads/*` (med `--prune`).

| Ændring | Målt problem |
|---|---|
| `cron/ci.sh` henter også `refs/pull/*/head` — PR'ernes commits findes på GitHub, uanset om branchen er slettet | Tier 1-merges blev røde på main i repos med automatisk sletning af branches |
| `cron/ci.sh retest <sha>` får en commit testet igen (resultatet glemmes) | En rød main-commit blev aldrig testet igen efter en rettelse (`ci/done`) |
| `server.sh` viser `!` (advarsel), når repoet sletter branches ved merge — ikke `✘`: med rettelsen ovenfor er indstillingen ufarlig for CI | Synlighed |
| Fejlteksten i report.sh nævner slettede branches | — |

Testet i selftest-ci (tier 1 med migration, critic-reviews, squash-merge, branchen slettet på origin og lokalt,
PR-commit kun som `refs/pull/14/head`): grøn med 5.18, rød med præcis den rapporterede fejl uden rettelsen. `retest` testet.

### Til econsync
Næste merge til main testes korrekt efter opdateringen. Den røde main-commit fra H007 kan testes igen med
`~/cron/ci.sh retest <sha>` i DEV's hjemmemappe — eller blive stående (DEV tager altid main's nyeste grønne commit).

### Ikke verificeret
- Ikke kørt mod GitHubs rigtige `refs/pull/*`-refs (kun et lokalt repo, hvor refs'ene er skubbet manuelt). `server.sh`-advarslen er ikke kørt mod GitHubs API.

## v5.17 — 2026-10-11

Grundlag: fund fra econsync (2026-10-10, rapporteret mod 5.13 — fejlene findes også i 5.16).

| Ændring | Målt problem |
|---|---|
| `fragment_field` stopper selv ved første værdi (ingen `| head` uden for løkken) | `report.sh notes` stoppede deterministisk efter "## Til kunderne", når et ID havde både `changes/F001.md` og `changes/F001-2.md` (SIGPIPE under pipefail) — ingen release-udkast muligt |
| Samme mønster rettet i report.sh: titel fra fragment, health-URL, JSON-felt, tier-linje, incident-titel, `devpin før <ID>` (git log), og kontroludkastets diff (`| head -400`); backup.sh (`restic snapshots`). `| head` efter en ubegrænset kilde er erstattet af `sed -n …p`, der læser alt | Samme fejlklasse; `devpin før` og `ctrl` kunne stoppe på store repos |
| `export MSYS2_ARG_CONV_EXCL='*'` øverst i report.sh | Git Bash på Windows omskrev `origin/main:.github/…` til en Windows-sti → falsk "mangler på main" |
| Undertrykkelses-ordene (nosemgrep, phpstan-ignore, gitleaks:allow, …) tæller kun uden for `*.md` | En docs-PR med et review, der citerede ordene, blev rød ("branchen skal hedde feature/…") |
| deploy.sh: første udrulnings snapshot (`logs/predeploy`) udelader alt i gulvet og `deploy-keep.txt` (ud over config.ini, github.token, restic.pass); tilbagerulning herfra sletter ikke det, der ikke blev kopieret | Hemmeligheder og kundefiler (fx `private/`) blev kopieret til `logs/` |

Testet i selftest (to fragmenter med en stor fil nr. 2 — testen fejler med 5.16-koden; fragment_field direkte; citat i .md
vs. undertrykkelse i kode) og selftest-deploy (kundefiler i `uploads/` kopieres ikke til predeploy og overlever tilbagerulning).

### Noteret, ikke ændret
- Ejerens ønske: backup skal ikke være en del af frameworket; `[backup] external` bør være standard. Tages op senere.

### Ikke verificeret
- MSYS-rettelsen er ikke kørt i Git Bash på Windows (kun Linux, hvor den ingen effekt har).

## v5.16 — 2026-10-10

Grundlag: GitHub Actions koster for meget (ejeren: "10 dollars ryger på ingen tid"). Ejerens beslutning: CI og
overvågning flyttes til DEV-serveren. GitHub bruges kun til det gratis: kode, branches, PR'er, releases, commit-status.

| Ændring | Problem |
|---|---|
| `shared/install/ci.sh` → `homedir/cron/ci.sh` (cron hvert minut i DEV's hjemmemappe): tester ny main-commit (status `coworker/ci`) og hver ny commit på åbne PR'er (status `coworker/pr`). Samme indhold som den gamle ci.yml. Rød = kommentar på PR'en med loggens sidste linjer; rød main = også mail | Actions-minutter |
| Testdatabase fra `config.ini [ci_database]`; navnet SKAL ende på `_ci` (slettes hver gang) — ellers rød uden DROP. Testene får `CW_DB_*` | En forkert konfiguration må aldrig kunne slette DEV's egen database |
| Et træ, der allerede var grønt (PR'en lige før squash-merge), testes ikke tungt igen på main. Draft-PR'er og forks springes over | Dobbelt arbejde på serveren |
| `shared/install/monitor.sh` → `homedir/cron/monitor.sh` (hver time): samme tjek som monitor.yml; mail + GitHub-sag; livstegn `coworker/monitor` på STABLE's commit. STABLE's deploy.sh mailer efter 13 timer uden livstegn | monitor.yml brugte Actions |
| deploy.sh godkender på status `coworker/ci`. Overgang: uden status tæller en grøn kørsel af den gamle ci.yml. deploy.sh nedgraderes aldrig til en version uden ci.sh, og cron/ci.sh og cron/monitor.sh slettes ikke af en ældre commit | Overgangen må ikke kunne låse serveren |
| `shared/install/server.sh check|setup dev|test|stable|monitor` (køres af ejeren én gang pr. hjemmemappe): tjekker config, token (også skrivning af status), /health-felter, PHP, composer, python3, testdatabasen og om STABLE står på samme maskine; lægger driftsfilerne i `cron/` fra main, henter værktøjerne og tilføjer manglende cron-linjer (fjerner aldrig) | Én kommando i stedet for manuelle trin |
| `report.sh install` lægger ci.sh og monitor.sh i `homedir/cron/` og fjerner `.github/workflows/ci.yml` og `monitor.yml`. CI afviser enhver tilføjet eller ændret workflow; urørte gamle workflows er `ADVARSEL (install)` | — |
| `report.sh check`, launch-tjek og `devpin` læser `coworker/ci`; check melder, hvis main ingen status har (CI kører ikke) eller er rød | — |
| Agenternes token behøver ikke længere Workflows/Actions — kun Commit statuses: Read | Mindre adgang |
| `shared/install/ci.yml` og `monitor.yml` er nu kun en "UDGÅET"-note | Master kan ikke slette filer |

Fagligt review (subagent, DevOps/sikkerhed) fandt 11 fejl før levering; rettet: cron-PATH uden /usr/local/bin
(composer ikke fundet), tabt status ved GitHub-fejl (gemmes nu og skrives igen), månedlig kontekst for livstegnet
(GitHubs grænse på 1000 statusser pr. commit), GitHub-fejl gav falsk "direkte push", testprocesser kunne holde
låsen, tokenet lå i testenes miljø, SIGPIPE i omfangs-tjekket, PR-commit skubbet efter fetch, mellemliggende
main-commits fik ingen status, `server.sh setup` kunne melde værktøjer hentet uden at have gjort det, Windows-
linjeskift i config.ini skjulte sektioner (også i deploy.sh og backup.sh).

Testet: `shared/selftest-ci.sh` (ny, 55 tjek) mod en RIGTIG MariaDB og et lokalt repo med en stub af GitHub-API'et:
main og PR-statusser, rød/grøn, PR-kommentar, mail, genbrug af grønt træ, docs-only, fork springes over,
`_ci`-beskyttelse (DEV's database urørt), ingen databasekode i log eller GitHub-kald, monitor.sh (livstegn, mail,
sag, ingen dubletter), server.sh (check/setup, cron-linjer bevares og dubleres ikke). `selftest-deploy.sh`: status-
godkendelse, overgang via gammel ci.yml, beskyttelse af cron-filer, STABLE's vagt over livstegnet. gitleaks-download
med checksum og Semgrep 1.97.0-installation i venv er kørt for rigtigt herfra (Python 3.11).

### Ikke verificeret
- Intet er kørt på en rigtig server eller mod GitHubs rigtige API. Statusser, PR-kommentarer og sager er kun prøvet mod en stub.
- PHPStan (packagist) og Semgrep-scanningen (regler fra semgrep.dev) kan ikke hentes herfra — kun stubbe i testen. Om de virker på serveren, viser `server.sh setup dev` (henter værktøjerne) og første kørsel.
- Rocky Linux' python3-version er ikke prøvet med Semgrep 1.97.0.
- Belastning (CPU/RAM) på DEV-serveren er ikke målt.
- Kendt svaghed: koden fra en PR kører som DEV's bruger og kan læse DEV's `config/` (også server-tokenet, som kan skrive commit-status — og dermed i princippet melde en commit grøn). I GitHub Actions kørte den isoleret. Tokenet er fjernet fra testenes miljø, men filen kan læses. Fuld løsning: en separat Linux-bruger til CI. STABLE tager stadig kun releases, ejeren udgiver.
- Projekter skal lade testene læse databasen fra `CW_DB_*` — ellers rammer de ikke testdatabasen.

## v5.15 — 2026-10-09

Grundlag: Storix (5.13), install-hotfix H017, PR albers1902/storix#4.

| Ændring | Målt problem |
|---|---|
| `coworker.ini` `[migrations] baseline = NNNN`: migrationer ≤ baseline (kørt før frameworket) undtages fra header-tjekket i `report.sh ci`/`precheck` og fra "kør de nye igen" og down/up i `shared/install/ci.yml`. De køres stadig fra tom base | 0001–0116 kom ind i git med appen i install-hotfixen og gav 348 header-fejl; filerne må ikke ændres (LIFECYCLE §6, migrate.py-checksum) |
| CI afviser: baseline, der ikke er et tal; baseline over den højeste migration i repoet; baseline, der dækker en migration, som ikke lå på main (undtagen første import, hvor main ingen migrationer havde); enhver ny migration med nummer ≤ baseline. coworker.ini er kontrolfil → tier 1 og Publish | Baseline må ikke kunne bruges til at snige nye migrationer uden om tjekket |
| Undertrykkelses-grep'et (nosemgrep, phpstan-ignore, gitleaks:allow, markTestSkipped, @codeCoverageIgnore) springer frameworkets egne filer over (stierne i `shared/framework.lock` + selve låsen). De er låst til masterens indhold, så undtagelsen åbner ikke for noget | Første framework-kopi gav rød precheck: frameworkets dokumentation nævner ordene |
| LIFECYCLE §6, CLAUDE.md "Ny framework-version" trin 5 og coworker.ini-skabelonen beskriver baseline | — |

Testet i selftest: første kopi (testen fejler med 5.14-koden), import med og uden baseline, baseline for høj, ny migration under baseline, hævning over en ny migration, ikke-numerisk baseline. ci.yml-trinnet "Migrationer" er kørt lokalt med en stub-database for baseline tom/0002/0117/ugyldig.

### Ikke verificeret
- ci.yml er ikke kørt på GitHub. Om Storix' gamle migrationer kan køre på en tom MariaDB (`mysql < fil`, ikke migrate.py), er ikke prøvet. Kan de ikke, er CI rød, og filerne må stadig ikke ændres.

## v5.14 — 2026-10-09

Grundlag: ejeren skal altid kunne rulle DEV tilbage til en tidligere version (ting, der ikke er i STABLE endnu).

| Ændring | Problem |
|---|---|
| `report.sh devlog` viser DEV-versioner (main-commits, nyeste først), hvad DEV kører, og om den er låst | Der var ingen liste at vælge fra |
| `report.sh devpin <sha>` / `devpin før <ID>` låser DEV til en tidligere main-commit med grøn CI (tagget `dev`); `devpin off` = følg main igen. Viser hvad der forsvinder fra DEV og hvilke migrationer der bliver stående | DEV kunne kun "rulles tilbage" med flag eller en revert-PR |
| deploy.sh (DEV): følger tagget `dev`, hvis det findes, ellers main. Afviser alt uden for main. Kontrolfiler, der allerede har kørt på DEV (`logs/deploy.history`), kræver ikke Publish ved rollback | Rollback over en kontrolændring ville ellers vente på Publish |
| Rollback-udkast til STABLE laves altid, også ved Launch-tjek IKKE KLAR | Reglen blokerede rollback, når man har mest brug for den |
| status og check viser, når DEV er låst | En glemt lås ville skjule nye merges for DEV |

Testet i selftest (git-repo + stub af GitHub) og selftest-deploy (rollback til v4 over en kontrolændring, til v2, afvisning uden for main, og tilbage til main).

### Ikke verificeret
- Ikke kørt mod en rigtig server eller GitHub. Virker først på en server, når 5.14's deploy.sh er installeret (framework-hotfix + Publish); den gamle deploy.sh ignorerer tagget `dev`.
- `logs/deploy.history` begynder først at blive skrevet med 5.14; rollback over en kontrolændring til en version, der kørte før det, venter på Publish.
- Databasen i DEV rulles ikke tilbage. Gammel kode på et nyere skema virker kun, når migrationerne er bagudkompatible (expand-reglen i LIFECYCLE §6).

## v5.13 — 2026-10-08

Grundlag: EnvoyAgent på 5.12, `launched/framework-rettelser.md` punkt 8–9 (målt 2026-10-08).

| Ændring | Målt problem |
|---|---|
| «afviger fra shared/install» er nu `ADVARSEL (install): …` (exit 0) i precheck og CI, når branchen ikke selv rører den installerede fil. Rød kun, når branchen ændrer eller sletter en installeret fil, så den afviger fra `shared/install/`, og når en `docs/framework-*`-branch rører den | (8) Cirkel: alle kode-PR'er røde, til install-hotfixen er merget; install-hotfixen ventede på en anden kode-hotfix. Serverne tager kontrolfiler først ind efter Publish, så advarslen åbner ikke for noget. Testet i selftest |
| ci.yml har `workflow_dispatch`: Team Lead kører én fuld branch-kørsel pr. kode-PR lige før merge (`gh workflow run ci.yml --ref <branch>`); merge kun på grøn kørsel af branchens seneste commit. Docs-PR'er: ingen | (9) Windows-maskinen har hverken PHP eller MariaDB; uden PR-kørsler blev intet testet før main |
| CI på main springer de tunge trin over, når main-commit'ens træ er identisk med en grøn branch-kørsel, og merget ikke ændrer kontrolfiler (`report.sh scope`) | Uden dette ville hver kode-PR koste to fulde kørsler. Testet i selftest med en stub af GitHub-API'et |
| Database-trinet venter nu på den rigtige server over TCP fra runneren (`mysql -h127.0.0.1 -uroot -pci -e 'SELECT 1' ci`) i stedet for `docker exec … mariadb-admin ping` | EnvoySubscriptions run 37781629716: ping svarede, mens entrypointets init-server (uden netværk) kørte; Migrationer fik ERROR 2013, tests "MySQL server has gone away". Init-serveren lytter ikke på TCP, og databasen `ci` findes først bagefter, så forbindelsen lykkes først mod den rigtige server |
| deploy.sh uændret: godkender stadig KUN en grøn push-kørsel på main | — |

### Ikke verificeret
- ci.yml er ikke kørt på GitHub. Hverken `workflow_dispatch`, `concurrency` eller API-kaldet `actions/workflows/ci.yml/runs?event=workflow_dispatch&head_sha=…` er prøvet mod GitHub; kun mod en stub.
- Før framework-hotfixen er merget, har `main` den gamle ci.yml uden `workflow_dispatch`; da kan `gh workflow run` blive afvist. Så merges på PRECHECK alene (agents/team_lead.md).
- Minutforbruget pr. branch-kørsel er ikke målt.
- Database-rettelsen er IKKE bevist på en runner: Actions-kvoten er brugt op, og herfra kan mariadb:10.11 ikke hentes (Docker Hub er blokeret i mit miljø). Første kode-merge efter framework-hotfixen er beviset — eller dens branch-kørsel.

## v5.12 — 2026-10-08

Grundlag: kontoens 2.000 Actions-minutter blev brugt på ét døgn. Halvering (5.11) er ikke nok.

| Ændring | Målt problem |
|---|---|
| ci.yml kører KUN ved push til `main` — én kørsel pr. merge. PR'er kører ingen Actions | Hver push til en PR kørte hele CI; mange rettelser pr. PR |
| `report.sh precheck`: engineeren kører samme framework-kontroller + PHP lint + tests i sin worktree (gratis); rapporten viser `PRECHECK: GRØN`; Team Lead merger kun da | PR-kontrollen flyttes fra GitHub til den lokale maskine. Testet i selftest |
| `report.sh scope`: tunge trin (PHP, PHPStan, Semgrep, database, migrationer, tests) kører kun, når kode, tests eller kontrolfiler er ændret; merges med kun dokumenter kører framework-kontroller + gitleaks | De fleste merges er plan-/review-/docs-PR'er. Testet i selftest |
| Semgrep caches mellem kørsler; databasen startes kun, når der testes | Semgrep-installation kostede ~1 minut pr. kørsel. Lokalt målt: genbrug 2 s |
| Monitor (5.11): hver 6. time | — |

### Pris for besparelsen (sandt)
- En fejl i en PR opdages først på `main` efter merge (eller lokalt af precheck). Deploy-gaten holder stadig: rød main rulles aldrig ud. Rød main = H-nummer straks.
- precheck kører på engineerens maskine og er selvrapporteret; den maskinhåndhævede kontrol er CI på main + deploy-gaten.

### Ikke verificeret
- Hele ci.yml er ikke kørt på GitHub. Det faktiske minutforbrug pr. kørsel er ikke målt.

## v5.11 — 2026-10-08

Grundlag: kontoen albers1902 brugte 100 % af de 2.000 inkluderede Actions-minutter.

| Ændring | Målt problem |
|---|---|
| ci.yml: push kører kun på `main` (PR-kørslen tester branchen); en ny push til samme PR afbryder den forrige kørsel | Hver push til en feature-branch kørte hele CI to gange (push + PR) |
| monitor.yml: hver 6. time i stedet for hver 2. (≈120 i stedet for ≈360 minutter/md. pr. repo); STABLE-serverens vagt mailer efter 13 timer uden monitor-kørsel | Overvågningen kørte i alle repos med frameworket |

### Ikke verificeret
- Hvor minutterne faktisk gik hen (pr. repo og workflow) — vedligeholderen har ikke adgang til kontoens forbrug.
- `concurrency` og trigger-ændringen er YAML-tjekket, ikke kørt på GitHub.

## v5.10 — 2026-10-08

Grundlag: ejerens beslutning (EnvoySubscriptions): backup kan være en driftsdisciplin uden for frameworket.

| Ændring | Målt problem |
|---|---|
| `[backup] external = <tekst>` i coworker.ini (kontrolfil — kræver Publish). Når den er sat, kræver launch-tjek, `check`, `monitor` og destruktive migrationer ikke backup-/restore-bevis; release-noterne viser "Backup: ekstern (<tekst>) — ikke maskinbevist", og CI skriver det ved destruktive migrationer | Et projekt med hostingens egen backup kunne aldrig få sin første release KLAR. Testet i selftest |

## v5.9 — 2026-10-08

Grundlag: EnvoySubscriptions, første udrulning med 5.8.

| Ændring | Målt problem |
|---|---|
| deploy.sh: `config/github.token` i gulvet (røres aldrig af rsync) og udeladt fra predeploy-kopien; `**/config/github.token` i gitignore.framework | Har repoet `homedir/config/`, slettede `--delete-after` tokenet ved første vellykkede udrulning, og serveren stod stille. Genskabt i selftest-deploy (fejler uden rettelsen, grøn med) |
| launch_check: første release (ingen tidligere release) og `/health` svarer ikke → godtager `launched/STABLE/foerste-release-bevis.txt` (output af serverens `logs/backup_last_ok`/`restore_last_ok`); release-filen siger tydeligt, at beviset er indsat af mennesket | Et system i drift kunne aldrig få sin første release KLAR: /health kommer først med udrulningen. Testet i selftest |
| backup.sh-hovedet og OPSAETNING: `[database]` host/name/user/pass; OPSAETNING: rækkefølgen for retrofit (backup → restore-test → bevisfil → første release → deploy-cron) | Udokumenteret |

### Ikke verificeret
- Ikke kørt på en rigtig server. selftest-deploy kører deploy.sh mod et lokalt repo med stubs.

## v5.8 — 2026-10-08

Grundlag: EnvoySubscriptions, GitHub Actions run 37695517493. Projektet melder alle andre trin i 5.6/5.7-ci.yml grønne på en rigtig runner.

| Ændring | Målt problem |
|---|---|
| ci.yml: efter `pipx install semgrep==1.97.0` kommer `pipx inject --force semgrep "setuptools<81"` og `semgrep --version` | semgrep crashede ved opstart: `No module named 'pkg_resources'`. Genskabt her med Python 3.12 + pipx: venv'et får setuptools 84 (uden pkg_resources); `--force` er nødvendig, ellers springer pipx over. Med rettelsen: semgrep starter, scanner og giver exit 1 ved fund |

### Ikke verificeret
- Kørsel på en rigtig ubuntu-24.04-runner. Registry-reglerne (`p/php`, `p/secrets`) kunne ikke hentes herfra (netværket blokerer semgrep.dev); scanningen er bevist med en lokal regel.

## v5.7 — 2026-10-07

Grundlag: rapport fra Team Lead i EnvoyAgent (A–D, 1–9). A, B (mysql), 1 og 4 var allerede rettet i 5.6.

| Ændring | Målt problem |
|---|---|
| ci.yml og monitor.yml kører på `ubuntu-24.04` (låst) | ubuntu-latest skifter version uden varsel |
| deploy.sh: tokenet gives via miljø (git) og stdin (curl), aldrig som argument; `config/github.token` (chmod 600, rettes automatisk) foretrækkes frem for config.ini | Tokenet kunne ses i `ps`; config.ini kan læses af webserverens gruppe. Testet: token ikke i curl-argumenter, sendes via stdin |
| deploy.sh: SHA fra GitHub/git valideres (40 hex); valgfri `[deploy] chmod`; database-backup før nye migrationer, når backup.sh og `[backup]` findes | Rapporteret (C) |
| CLAUDE.md trin 5 + LIFECYCLE §8: serverens config og /health skal være på plads FØR install-PR'en merges; trin 5 er en engineers tier 1-opgave med liste over typiske tilpasninger | Det gamle deploy.sh rullede det nye ud, som så stoppede DEV (D, 2) |
| Trin 0 tjek 4: `diff -rq _claude .claude`; ved forskel får mennesket én afprøvet kopi-linje | En forældet guard blokerede frameworkets egne regler (3). Rodårsag: master-mappens `.claude` er gammel og kan ikke skrives af vedligeholderen — hver kopi af master lægger den gamle guard ind igen |
| Guard: engineers må læse tags/releases (`git tag -l`, `gh release list/view`, `git ls-remote --tags`); push-reglerne ser kun selve push-kommandoen, ikke en commit-besked | "v1" i en commit-besked blev blokeret som release-tag (8). Testet: `selftest-guard.sh` 12/12 |
| `.gitignore`-kontrollen sammenligner mængden af linjer før/efter | En flyttet linje blev afvist som fjernet (6). Testet i selftest |
| Skabeloner: SUBPROCESSORS, SECURITY-OVERVIEW, RUNBOOK, ROPA, DPIA, THREAT-MODEL, ARCHITECTURE (C4 1–2) | Manglede (9) |

### Afvist eller udskudt
- 5 (check tavs): allerede højlydt siden 5.4 ("changes/README.md er ikke på main — tjek 2 og 6 … er slået fra").
- 7 (`report.sh deploys`): deploy-historikken ligger kun på serveren; serverens token er læse-only og kan ikke skrive den til GitHub. Udskudt.
- Genererede dokumenter i CI (skema, ruter, licenser): udskudt.
- Kørsel af ci.yml på en rigtig GitHub-runner før frigivelse: vedligeholderen har ingen repo-adgang. Projektet melder en grøn kørsel med A+B rettet (ikke set af vedligeholderen); resten af 5.6/5.7-ændringerne i ci.yml er ikke kørt på en runner.

## v5.6 — 2026-10-07

Grundlag: fejlrapport fra EnvoySubscriptions (GitHub Actions run 37685356824). Alle fund var korrekte.

| Ændring | Målt problem |
|---|---|
| ci.yml: gitleaks 8.21.2 med sha256-tjek (officiel checksum) — `gitleaks git` findes først fra 8.19 | **Blokerede CI i alle projekter**: `unknown command "git"` med 8.18.4. Verificeret lokalt med begge versioner |
| selftest.sh bygger sin falske token ved kørsel | gitleaks fandt den (github-pat + generic-api-key) i hele historikken. Verificeret: 0 fund nu |
| `shared/gitleaks.toml` (låst): standardregler + undtagelse kun for `shared/selftest.sh`; ci.yml bruger den | Projekter, der har committet 5.5's selftest.sh, har tokenen i historikken for altid. Verificeret: den gamle fil ignoreres, en rigtig token i en anden fil fanges stadig |
| ci.yml: alle trin kører, selv om et tidligere fejler (`!cancelled()`) | Ét rødt trin skjulte PHPStan, Semgrep, migrationer og tests |
| ci.yml: `mysql`-klienten (runnerens) i stedet for `mariadb`; `pipx install semgrep==1.97.0` (PEP 668); PHPStan låst (`[ci] phpstan`, standard 1.12.7) | Rapporteret som sandsynlige fejl |
| Tests matcher known-failures på `classname` (ellers `class`) | Projekt-egne testløbere skriver kun classname. Verificeret med en JUnit-fil |
| Opgradering i to trin: `docs/framework-<v>` (kun frameworkets kopi; CI kræver ikke installerede filer dér) + tier 1-hotfix, hvor en engineer kører `report.sh install` og udfylder coworker.ini | CLAUDE.md lagde kontrolfiler på docs-branchen, som CI ikke tillader; en ældre guard blokerer Team Lead i coworker.ini |

### Ikke verificeret
- Installerede ci.yml er ikke kørt på en rigtig GitHub-runner af vedligeholderen (ingen adgang til et repo herfra).
  Første rigtige kørsel er projektets tier 1-hotfix. Lokalt testet: gitleaks-kommandoen, checksum, JUnit-afstemningen, YAML.

## v5.5 — 2026-10-07

Grundlag: de tre ekspertreviews af 5.4 (VP Eng/SRE, Release & Docs, CISO) og ejerens beslutninger:
agenterne bruger hans GitHub-login, ingen GitHub Pro, og **ingen agent må rette kontrolfilerne**.

| Ændring | Målt problem (review af 5.4) |
|---|---|
| CI, overvågning, deploy og backup er frameworkets låste filer i `shared/install/`, installeret uændret med `report.sh install`; CI afviser afvigelser og andre workflows. Projektværdier i `coworker.ini` | Kontrollerne lå i filer, agenterne selv kunne rette, og kørte fra agentens egen commit |
| deploy.sh: en ændret kontrolfil (CI, deploy, backup, report.sh, lock, coworker.ini, test-/scan-konfiguration, .gitignore) når ingen server, før ejeren har trykket Publish på en release eller en "Kontrol"-pre-release (`report.sh ctrl <sha>`) | Svækket CI kunne nå DEV/TEST uden et menneske; deploy.sh kunne deploye sig selv |
| deploy.sh godkender kun grøn CI på `main` (`branch=main`) | En grøn kørsel på en feature-branch talte |
| Critic-review skal have `Branch: <branch> @ <sha>`; CI afviser, hvis koden er ændret efter den reviewede commit (patch-id) | Et review af commit 1 godkendte også commit 5 |
| Acceptkriterier tæller kun for tests, der kørte og bestod (junit.xml); sprunget-over test = rød; `markTestSkipped` tvinger tier 1 | En kommentar med `F005-AK1` dækkede et AK |
| Immutabelt spor håndhæves: specs, rapporter, reviews, fragmenter, release-filer slettes/ændres aldrig; incidents og DECISIONS kun tilføjelser | "Ændres aldrig" var kun tekst |
| Restore-test: snapshot < 26 t, alle tabeller, ingen tom tabel med rækker i drift, ≥ 90 % af rækkerne, config.ini kan gendannes | Beviste kun "mindst én tabel" |
| STABLE-serveren mailer, hvis monitor.yml ikke har kørt i 6 timer | Ingen overvågede overvågningen |
| `check`: åben incident-sag uden I-fil efter et døgn; slettet udgivet release = incident (aldrig "kasseret"); coworker.ini udfyldt | Incident-spor hang ikke sammen; vej til at forfalske historik |
| `selftest-deploy.sh` (17 tjek: snapshot, rollback, rød CI, migrationsfejl, kontrol-gate, godkendelse, efterladt pause, real_data, dedup); selftest 54 tjek | deploy.sh havde 0 automatiske tests |

### Accepteret af ejeren
- Agenterne bruger ejerens GitHub-login (ingen aktør-adskillelse i git). Ingen GitHub Pro.
- Backup på Google Drive er ikke append-only (restic-nøglen på serveren kan slette). Næste skridt, hvis ønsket: en backup-destination med object lock.

## v5.4 — 2026-10-07

Grundlag: to målinger fra projekterne (EnvoySubscriptions: CI-fejl + critics' fund i deploy;
EnvoyAgent: uafhængig dokumentationsevaluering E01–E21), vurderet punkt for punkt af en SRE- og
en CI/governance-agent. Taget med: alt, der er en målt fejl eller kan håndhæves af en maskine.

| Ændring | Målt problem |
|---|---|
| `report.sh`: alle `… \| grep -q` erstattet af `gq` (læser til ende) | **Blokerede alle PR'er** i repoer over 64 KB: `grep -q` + pipefail = SIGPIPE = falsk "mangler engineer-rapport" (EnvoySubscriptions PR #8). ROADMAP på 85 KB ramte også |
| CI kører `report.sh ci` igen ved push til `main` (`BASE_SHA`, branch fra den mergede PR); direkte push uden PR = rød | Kontrollerne kørte kun på PR; deploy godkendte push-kørslen, der intet tjekkede (E01) |
| deploy.sh og launch-tjek godkender kun `.github/workflows/ci.yml`, `event=push`, `success` på præcis SHA; test/stable kun commits på `main` | Enhver workflow ved navn "CI", også PR-kørsler, talte som grøn |
| deploy.sh: lås i scriptet (`flock`), efterladt `logs/deploying` ryddes med alarm, alarm-dedup (1/døgn), GitHub/CI-stop i 60+ min = alarm, `real_data` skal være true/false, `rsync -rlt` (rører ikke rettigheder), gulv af beskyttede stier, snapshot før første udrulning, fejl midt i filskift = rollback, migrationskommando udfyldes (`<…>` = nægter + alarm) | Critics' fund i H011: alarmstorm, workers på pause for evigt, fail-open, rettigheder ændret, ingen vej tilbage ved første deploy, ikke-eksisterende `--with-transaction` |
| CI-skabelon: `shell: bash` (pipefail), tests afstemt på JUnit (0 tests/nedbrud = rød), kommentar om `persist-credentials` | Actions' standard-shell har ikke pipefail; en forsvundet testfil gav grøn CI |
| Tier 1 tvinges også af known-failures, baseline, semgrep/gitleaks-ignore, deploy/backup-scripts, slettede tests og nye `nosemgrep`/`phpstan-ignore`; nye known-failures højst 30 dage frem | Det, der afgør hvad CI tolererer, kunne ændres i tier 2 |
| Critic-review skal have `Branch: <PR-branchen>` og gemmes ordret; Team Lead ændrer aldrig et verdict | En PR kunne godkendes på en anden PR's review; reviews var opsummeringer (E04) |
| ID-grammatik `[FH][0-9]{3,}[a-z]?` overalt (branch, commits, flag, roadmap) | F061a, H164a var usynlige for scriptet (E02) |
| `report.sh roadmap/check` læser tabelrækker `\| F012 \| … \|` og `## Før frameworket`; DECISIONS-henvisninger accepterer `\| #14 \|` | 108 F-ID'er i tabeller gav tomt roadmap-svar (E16, Tillæg B) |
| `report.sh id` viser sporet: impact → spec → rapport → reviews (verdict + branch) → fragment → DEV-test | Ingen sporbarhedsmatrix (E08) |
| Incident: `nextid I`, `incidents/I0NN.md` efter `INCIDENT.md.template` (S1–S4, GDPR art. 33-vurdering, kundebesked, rotation); `fixes` lister dem | Intet incident-begreb; to sikkerhedshændelser uden spor (E06) |
| `report.sh secrets` — Team Lead scanner før hver commit | Guarden forhindrede Team Lead i selv at tjekke for hemmeligheder (Tillæg B) |
| `check`: CI uden framework-kontroller, changes/README mangler (højlydt), accepteret risiko uden/udløbet `Udløber:`, adgangsregister ældre end 90 dage, flag efter `fjernes senest` | E01, modsigelse 12, E13, E19 |
| `ACCESS.md`-skabelon; samtykke-tabel i DRIFT; tier 1-kriterium "ny ekstern modtager af kundedata" + `SUBPROCESSORS.md`; nødændring med I-nummer | E07, E12, E13, E14 |
| `.gitignore`: forankrede mønstre, rester fra master-kopien ignoreres, `#erstattet:`-linjer må fjernes; Trin 0 tilføjer altid manglende linjer | `logs/` ramte `src/Logs/` på Windows; rester kom tilbage ved hver kopiering |
| LEARNINGS-loft efter størrelse; flyt = kopiér først, fjern bagefter; engineer-statusser, der krævede redigering af immutable filer, fjernet | Modsigelser i Tillæg B og E15 |
| selftest: 51 tjek (stort træ, push-tilstand, F061a, tier 1-branch, known-failures-loft, `#erstattet`, tabel-roadmap, secrets); deploy.sh stub-testet (grøn, rød CI, health-fejl → rollback, første deploy → snapshot, efterladt lås, real_data, lås, dedup) | — |

### Afvist eller udskudt (med grund)
- Maskinbruger + menneskelig approval på tier 1 (E03): kræver GitHub Team. Står som accepteret risiko
  i LIFECYCLE §1 med afbødning; hvert projekt skriver den i DECISIONS med `Udløber:`.
- Critics skriver selv deres fil + guard-ændringer (E04, E18, E20): kræver ny `.claude/`, som ikke kan
  leveres via kopien. Erstattet af ordret gemning + `Branch:`-krav i CI.
- YAML front-matter i alle procesfiler (E02): for stor omlægning af eksisterende spor; linjeformaterne
  `Tier:`, `Status:`, `Branch:` håndhæves i stedet.
- Genererede schema/ruter/SBOM, ARCHITECTURE.md, ROPA/DPIA/THREAT-MODEL, ekstern pen-test (E10–E12):
  bølge 3 — før første betalende STABLE-kunde.
- Afvis andre workflows med navnet CI (B3): unødvendig, når deploy kun godkender stien `ci.yml`.

## v5.3 — 2026-10-07

Grundlag: tre uafhængige enterprise-reviews (VP Engineering/SRE, Release & Docs, CISO/AI-governance)
efter hændelsen, hvor en kopi af master overskrev EnvoyAgents `.gitignore` (PR #30).
Princip: regler flyttes fra tekst til maskiner. Regelbogen er skåret fra 1.292 til ~190 linjer.

| Ændring | Målt problem |
|---|---|
| `shared/framework.lock` + `VERSION`; CI afviser ændrede framework-filer, medmindre de er en uændret kopi på `docs/framework-<version>` | Agenter kunne rette frameworkets regler i et projekt; ingen versionsstyring |
| Pladsholdere for `README.md`/`.gitignore` i master; CI afviser at committe dem; Trin 0 punkt 5 henter projektets egne | Katastrofen 07-10: kopi overskrev projektets `.gitignore` |
| `.gitattributes` kun tilføjelser (som `.gitignore`) | Samme risiko |
| CI-skabelon: gitleaks (hele historikken), Semgrep (SQL/XSS), composer audit, PHPStan ≥ 5, test-trin der fejler indtil det er tilpasset; CI rød mens `TILPAS` står i en workflow | CI var grøn uden en eneste test; ingen secret scanning |
| Acceptkriterier `AKn` i spec; CI kræver tests, der nævner `<ID>-AKn` | Ingen sporbarhed krav → test |
| `Kunde:` og `Support:` i fragmentet (CI); kundesektion i release-noterne | Ingen kundevendt launch-dokumentation |
| Launch-tjek i `report.sh notes`: CI grøn på target, kundetekst, AK-dækning, monitor findes, backup ≤ 1 døgn, restore ≤ 90 dage; IKKE KLAR = intet udkast | Ingen launch readiness |
| Kontrolændringer (CI, deploy, backup, .gitignore, report.sh) listes i release-noterne før Publish | Uden Pro kan en PR ændre sine egne kontroller; mennesket skal se det før produktion |
| `backup.sh`-skabelon: krypteret (restic) uden for serveren, månedlig automatisk restore-test med målt tid; bevis i `/health` | Backup på samme server; restore-bevis var en dato i markdown |
| Destruktiv migration kræver maskinbevist restore (STABLE `/health`) i stedet for en dato i DRIFT.md | Beviset kunne skrives af en agent |
| `monitor.yml` (GitHub Actions hver 2. time): health, STABLE = seneste release, backup/restore-alder, hændelser → GitHub-sag + mail | Ingen ekstern overvågning; alarmer kun lokalt |
| `logs/incidents.log` fra deploy og backup → `/health` → monitor | Hændelser blev kun til en mail |
| Begrænset GitHub-nøgle (fine-grained, uden Administration) i stedet for ejerens fulde login | Agenter havde admin på alle repos |
| Merge kun efter `gh pr checks --watch`; docs-PR'er med eksplicitte stier, aldrig `git add -A` | `git add -A` committede den overskrevne `.gitignore` |
| Critics er vejledende; blokerende kontroller er maskintjek | Samme model skrev og godkendte |
| `report.sh lint` og `shared/selftest.sh` (35 tjek) køres af vedligeholderen før hver levering | Frameworket modsagde sig selv; ingen test af frameworket |
| LIFECYCLE omskrevet (~190 linjer), CLAUDE.md og rollefiler skåret; "Læst: x.y"-ritual og WORKFLOW.md fjernet | 2.300+ linjer regler, som en sprogmodel ikke kan holde |

### Kendte rester (ikke lukket uden GitHub Pro)
- GitHub håndhæver intet på `main`. En agent kan teknisk merge en rød PR; garantien er, at deploy
  aldrig udruller en commit uden grøn CI, og at mennesket ser alle kontrolændringer før Publish.
- `.claude/` (guard-hook) kan kun opdateres af mennesket; 5.3 kræver ingen ændring dér.

## v5.2 — 2026-10-07

Grundlag: ejerens krav (alt dokumenteret, alt kan rulles tilbage, svar
fra git — aldrig hukommelse), en ekstern enterprise-review af EnvoyAgent
og en adversarial gennemgang med en anden agent. Princip: en regel, der
kan håndhæves af CI, script eller deploy, håndhæves dér.

| Ændring | Målt problem den løser |
|---|---|
| `shared/report.sh` (status, month, releases, roadmap, fixes, id, restore, check, notes, nextid, ci) | Svar om releases/roadmap kom fra hukommelse og håndskrevne registre; ROADMAP, F-RELEASES, PROJECT_STATUS m.fl. gav tre forskellige tal |
| "Released" = udgivet GitHub-release, dato = `published_at`; flag- og commit-regel; rollback genkendes på git | 0 tags, 0 releases, alt hed `v0.9.0-dev`; "Shipped" betød "kører på DEV" |
| Status-ord Merged / Deployet / Released; `## Shipped` → `## Released` | "Shipped" blandede tre forskellige tilstande |
| `launched/releases/<tag>.md`, skrevet af scriptet, aldrig ændret | `tasks/RELEASE-NOTES.md` blev overskrevet; 3× CHANGELOG var håndskrevne og gled fra hinanden |
| `report.sh releases`: version, dato, SHA, migration, ID'er, rollback-mål | Ingen kunne svare på "hvilken version kører i drift" |
| `changes/<ID>.md` i samme PR som koden (CI) | Dokumentation fulgte ikke koden; 14 merges på én dag uden docs |
| Procesfiler (impact, spec, engineer-rapport, critics APPROVED) skal være i git før merge (CI) | 94 procesfiler fandtes kun på ejerens disk; en reviewer konkluderede, at reviews aldrig var lavet |
| Trin 0 tjek 7 + `report.sh check`: uncommittede procesfiler | SPRINT.md i git sagde "deploy ikke sat op", den lokale sagde det modsatte |
| `gh pr merge --squash --subject "<PR-titel>"` | GitHub bruger commit-beskeden ved én-commit-PR'er — ID'et kunne forsvinde fra `main` |
| Næste ID via `report.sh nextid` (main, ROADMAP, branches, fragmenter, flag); `F[0-9]{3,}`; `## Droppet` | Fire modstridende "næste ledige"-linjer i SPRINT.md |
| SPRINT.md uden håndskrevne tal — `report.sh status` | Samme |
| ROADMAP: Nu/Næste/Senere med `plan:` (aldrig ændret, kun `replan`), Backlog uden plan, Released, Droppet; faktisk dato genereres | ROADMAP havde faser (R1–R4) uden tidsakse og kunne ikke besvare "næste halve år" |
| Flag-invariant: sektion fjernes kun hvis `stable = on` i seneste release eller ID'et er Droppet | Kode kunne nå STABLE uden godkendelse via flag-oprydning |
| §0 punkt 6: rigtige data = produktion; `real_data = true` → deploy kun fra udgivne releases | EnvoyAgents DEV skriver i en rigtig kundes e-conomic og deployede hvert 2. minut uden menneskelig godkendelse |
| Låsen flyttet til deploy + CI (beskrevet i §0) | Uden GitHub Pro kan `main` ikke låses |
| Deploy-skabelon: workers pause, migrationer før kodeskift, `rsync --delay-updates --checksum`, health med SHA + DB-læsning + DB-skrivning, mail ved fejl | `rsync --delete` direkte i serverede mapper; ny kode mod gammelt skema; "DEPLOY OK" mens manglende grants fik alle skrivninger til at fejle |
| `launched/DRIFT.md` (skabelon) med dato på backup, restore + RTO, vault, uptime, alarm, rollback per lag, eksterne sideeffekter | Backup på samme server, ingen offsite, alarm-mail tom, rollback-doc beskrev WinSCP |
| Migration-headers (reversibel/destruktiv/idempotent) + down-filer (CI) | Rollback-doc påstod "kun tilføjelser"; 12 filer havde MODIFY/DROP INDEX |
| Destruktiv migration kræver restore-test ≤ 90 dage (CI) | Ingen kobling mellem destruktive ændringer og en bevist restore |
| CI-skabelon: migrationer to gange fra tom base + down/up | 9 af 89 migrationer kunne ikke genkøres; halvt kørte filer blev ikke opdaget |
| `report.sh restore <tag>`: kode, flag, down-migrationer nyeste først, hvad kræver restore, eksterne sideeffekter | "Kan vi komme tilbage inklusive databasen?" havde intet dokumenteret svar |
| Kendte røde tests per test-case med H-ejer og udløb (CI) | Listen matchede hele testfiler — nye fejl i kendte røde filer var usynlige |
| PHPStan mindst level 5 med baseline | Level 0 tjekker reelt ikke typer |
| DECISIONS nummereret `## #N`; ejerens chat-beslutninger skrives samme session; `DECISIONS #N`-henvisninger tjekkes (CI) | PR'er henviste til DECISIONS 8–12, som ikke fandtes; #1 sagde PHP 8.2, faktisk 8.4 |
| Kun ROADMAP.md og SPRINT.md bærer status; øvrige statusfiler → `launched/history/` (i git) | Seks statusfiler i roden, aldrig opdateret |
| `README.md` i roden beskriver produktet; frameworkets README er `shared/README-framework.md` | README beskrev frameworket, ikke EnvoyAgent |
| LEARNINGS-loft 300 linjer → `launched/learnings/ÅÅÅÅ-QN.md` | LEARNINGS.md på 1.243 linjer |
| Engineer: kommentarer forklarer hvorfor — historik i commit/fragment | Hotfix-historik i koden var gledet fra koden; 37 % af `src/` var kommentarer |
| Rollback-regler (flag → revert-H → rollback-release) og incidents | Manglede helt |
| `report.sh fixes <periode>`: hotfixes, status og incidents | "Hvilke hotfixes har vi måttet lave?" havde intet svar |
| guard.ps1: Team Lead skriver ingen scripts og kører kun `bash shared/report.sh` | Hul: skriv et script i `shared/` og kør det |
| WORKFLOW: browser-test = `tester` i baggrunden | Modsagde LIFECYCLE |
| `launched/DEV/` og `TEST/` udgår (indhold flettes i STABLE, rest i `launched/history/`) | Tilstanden kan beregnes; filerne gled fra virkeligheden |

### Afvist eller ændret — med begrundelse

| Forslag | Afgørelse |
|---|---|
| Semver-tags | Afvist. Ingen eksterne API-forbrugere (MyTools-konstitution); dato-tags + `published_at` er entydige, og deploy/guard bygger på `v*`. |
| `RELEASES.md` som håndskrevet fil | Erstattet af `report.sh releases` + immutable `launched/releases/<tag>.md` — et håndskrevet register glider. |
| Kode-PR skal også ændre ROADMAP.md og SPRINT.md | Afvist: fire parallelle engineers ville få merge-konflikter. Erstattet af procesfiler før merge, ROADMAP-linje og fragment (CI). |
| CI-tjek af PR-titel og "APPROVED"-link i PR-tekst | Afvist: Team Lead skriver titlen selv; beviset er review-filen, som CI læser. |
| Release-mapper + symlink-skift | Erstattet af `rsync --delay-updates`: på CWP peger Apache på `public_html`, og PHP's realpath-cache kan servere gamle filer efter et symlink-skift. |
| `final-decisions/<ID>.md` for alle tiers | Afvist: ID → release udledes af git. Kun TIER 1 og incidents. |
| DECISIONS som append-only ADR | Delvist: nummereret og "Erstattet af #N", ikke append-only — filen skal vise det gældende. |
| `faktisk:` skrevet i ROADMAP | Afvist: genereres af `report.sh roadmap`. |
| Incident ved enhver health-overskridelse | Afvist (støj). Kun rollback-release eller `stable = off` pga. fejl. |
| DORA-tal, porteføljerapport, maskinbruger | Udskudt: tjener ikke kravene nu. |

### Test (eksempelrepo med 5 releases inkl. rollback og et slettet udkast)
- `report.sh`: alle kommandoer kørt; gh-stub sender CRLF som `gh.exe` på Windows.
- `report.sh ci`: 9 scenarier (mangler fragment/impact/spec/rapport/critics, tier 1–3, DECISIONS-henvisning, migration-header/down-fil, destruktiv uden frisk restore, kendte røde udløbet/forkert format, ren flag-PR).
- `deploy.sh.template`: deploy, real_data-afvisning, health-fejl → rollback + mail, samme fejlede SHA prøves ikke igen, migration-fejl → koden skiftes ikke. `.ssh`, `config.ini` og uploads røres ikke.
- `guard.ps1`: 23/23 i PowerShell 7.
