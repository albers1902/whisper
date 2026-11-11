<!-- FILE_VERSION: 5.42 -->
# LIFECYCLE — Coworker Framework 5.42

Én regel over alle andre: **det, der kan håndhæves af en maskine, håndhæves af en
maskine.** Teksten her forklarer hvorfor og i hvilken rækkefølge. Kontrollerne
sidder i `shared/report.sh` (CI), `deploy.sh` (server), `guard.ps1` (PC) og i
menneskets "Publish release". En regel, der kun står her, er en hensigt — ikke en kontrol.

## §1 Hvem gør hvad, og hvad låser det

| Hvem | Gør | Låst af |
|---|---|---|
| Mennesket | Siger "go", trykker **Publish release** | Kun mennesket kan udgive (guard blokerer agenter) |
| Team Lead | Planlægger, skriver spec/impact, merger, laver release-udkast | guard: koder aldrig, pusher aldrig til `main`, udgiver aldrig |
| Engineer | Koder i egen worktree og branch | CI (`report.sh ci`), aldrig merge |
| Critics (QA, security) | Review ved tier 1 (begge) og tier 2 (én), gemt ordret; kører på en anden model end engineeren | CI: `Status: APPROVED`, `Branch: <PR-branchen> @ <sha>`, accepterede sikkerhedsfund i DECISIONS |
| Tester | Browser-test efter deploy til DEV/TEST | — |
| Server | Henter selv: kun grøn CI, `real_data` kun releases | `deploy.sh` |

Uden GitHub Pro håndhæver GitHub intet på `main`. Derfor er garantien: **intet når
en server, uden at CI på DEV-serveren (`cron/ci.sh`, status `coworker/ci`) er grøn på præcis den commit på `main`** — og den
kørsel kører alle frameworkets kontroller igen og er rød ved direkte push uden PR (deploy.sh,
§9). **Intet når rigtige kunder uden menneskets Publish** (release-notes viser alle
kontrolændringer først). **Ingen kontrolændring når en server uden menneskets Publish**:
CI, overvågning og deploy er frameworkets låste filer (`shared/install/`, installeres
uændret med `report.sh install`); projektets værdier står i `coworker.ini`. Ændres en kontrolfil
(`CTRL_FILES` i report.sh), venter DEV og TEST, til ejeren har trykket Publish på en release eller
en pre-release "Kontrol: …" (`report.sh ctrl <sha>`), der indeholder den.

Ejerens beslutning: agenterne bruger hans GitHub-login, og der købes ikke GitHub Pro. Derfor
håndhæves alt ovenfor af CI og deploy.sh, ikke af GitHub.

## §2 Kørsel og layout
- Claude Desktop → Code → Local → projektmappen, UDEN worktree. Højst 4 engineers ad gangen, i baggrunden.
- Projektmappen er git-repoet. Appen ligger i `homedir/` (= `/home/<projekt>/` på serveren). Kun `homedir/` kommer på serveren.
- Frameworkets filer (CLAUDE.md, OPSAETNING.md, `agents/`, `shared/`, README-filerne i procesmapperne) er låst af `shared/framework.lock`. De ændres kun i master og kopieres ind; CI afviser lokale rettelser.

## §3 Arbejdsgang per ID (F = feature, H = hotfix)
1. **ID:** `bash shared/report.sh nextid F` (aldrig gæt, aldrig genbrug).
2. **Plan** (før engineeren starter): spec `product-ai/<ID>_v1.md` med acceptkriterier (`- AK1: …`) og impact
   `tasks/impact-<ID>.md` med linjen `Tier: 1|2|3` som FØRSTE commit på arbejdsbranchen (agents/team_lead.md).
   ROADMAP-linjen i en docs-PR (flere ID'er ad gangen), merget før arbejdsbranchen.
3. **Engineer** på `feature/<ID>-slug` / `hotfix/<ID>-slug`: tier 1–2 først `fullstack-ai/<ID>_v0.md`
   (System understanding, branchens første commit, mindst to filkilder) → Team Lead læser → "go". Derefter
   kode, tests der nævner `<ID>-AKn` (hotfix: en test, der genskaber fejlen, eller `Ingen test: <grund>` i
   fragmentet), `changes/<ID>.md`, flag i `homedir/config/features.ini` (`dev=on, test=off, stable=off`).
   Push + PR (aldrig draft) → DEV tester koden med rigtig database → `report.sh kode` = `KODE: GRØN`, FØR critics (5.35).
4. **Review-docs-PR:** engineer-rapport `fullstack-ai/<ID>_vN.md` (+ critic-reviews: tier 1 begge, tier 2 én,
   ordret som critic svarede, med `Branch: <branch> @ <sha>` og `Status:` øverst — CI afviser, hvis
   koden er ændret efter den reviewede commit). Team Lead ændrer aldrig et
   verdict; en betinget godkendelse kræver en ny critic-kørsel.
   Før hver commit: `bash shared/report.sh secrets` (tom = OK).
5. **Merge:** `report.sh behov-opdatering <branch>` (kun ved `OPDATÉR`: `gh pr update-branch`) → engineerens `report.sh precheck` GRØN på seneste commit → DEV-serverens
   status `coworker/pr` grøn på samme commit (agents/team_lead.md) → merge:
   `gh pr merge <branch> --squash --subject "<ID>: … (#<nr>)"`. DEV henter selv. Tester i baggrunden.
6. **TEST** (menneskets "go"): engineer sætter `test = on` → `git tag -f test origin/main` + `git push --force origin refs/tags/test`.
7. **STABLE** (menneskets "go"): engineer sætter `stable = on` → flyt `test` dertil →
   `bash shared/report.sh notes v<ÅÅÅÅ.MM.DD>-<n> <sha>`. Står der **Launch-tjek: KLAR**:
   `gh release create v… --draft --target <sha> --title "Release <dato>" --notes-file launched/releases/v….md`
   og send mennesket én linje + linket. **IKKE KLAR** = intet udkast; ret det, filen siger.
8. **Docs-PR** samme dag med eksplicitte stier (`git add ROADMAP.md SPRINT.md launched/ tasks/ …` — aldrig `git add -A`).

CI håndhæver punkt 2–4 på `main` efter merge (og engineerens `precheck` kører de samme kontroller før): mangler spec, impact, rapport,
fragment (`Kunde:`/`Support:` for F), AK-dækning, ROADMAP-linje eller (tier 1) critic-review for
netop denne branch, er kørslen rød — og deploy.sh ruller den ikke ud.
ID-grammatik overalt: `F005`, `H012`, `F061a` (`[FH][0-9]{3,}[a-z]?`). Flag-sektion `[<fuldt ID>_slug]`.

**Nødændring** (kun ved en I-hændelse, §7): fragmentet har linjen `Nød: I0NN`; critics køres efter
merge, senest 24 timer efter, og gemmes som for tier 1. Alle andre kontroller gælder uændret.

## §4 Tier — bestemt af filstier
- **Tier 1:** migrationer, workflows, auth, betaling, kryptering, hemmeligheder, delte kontrakter, og
  enhver ny eller ændret **ekstern modtager af kundedata** (API, LLM, mail, webhook) — impact har da
  afsnittet "Persondata og eksterne modtagere", og `launched/compliance/SUBPROCESSORS.md` opdateres.
  CI tvinger tier 1 ved: `homedir/migrations/`, `.github/workflows/`, `homedir/cron/deploy.sh`,
  `ci.sh`, `monitor.sh`, `deploy-keep.txt`, `backup.sh`, `known-failures.txt`, `phpstan-baseline.neon`, `.semgrepignore`,
  `.gitleaksignore`, slettede filer under `tests/`, og nye `nosemgrep`/`phpstan-ignore`/`gitleaks:allow`.
- **Tier 2:** alt andet med logik. Spec + impact + System understanding (v0) + rapport + én critic.
- **Tier 3:** kun CSS, tekst, skabeloner. Impact på én linje (`Tier: 3 — …`), ingen spec, ingen critic.
- Nødvejene er uændret hurtige: `stable = off` (kun flag) kræver intet af ovenstående, og en
  `hotfix/H###-revert-…` kræver hverken v0 eller regressionstest.

## §5 Feature flags
- `[F005_kort_slug]` i `homedir/config/features.ini`, højst én sektion per ID, `dev/test/stable = on|off`.
  Første linje i sektionen: `; ejer: <ID> · oprettet: ÅÅÅÅ-MM-DD · fjernes senest: ÅÅÅÅ-MM`.
- Al ny synlig adfærd bag flag. Rene fejlrettelser (H) må gå uden.
- En sektion fjernes kun, når den var `stable = on` i seneste udgivne release, eller ID'et står under `## Droppet` (`report.sh check` fanger brud; release-notes viser `BRUD`).

## §6 Migrationer
- Navn `NNNN_vX.Y.Z_beskrivelse.sql`, immutable efter kørsel, idempotente.
- Hver ny fil starter med `-- reversibel: ja/nej`, `-- destruktiv: ja/nej`, `-- idempotent: ja/nej`.
  `reversibel: ja` kræver `homedir/migrations/down/<samme navn>`. CI kører alle fra tom base, de nye
  igen, og down/up.
- Identifikatorer, som nyere MariaDB har reserveret (fx `to_date`, `conversion`, `current_path`, `vector`), står i
  backticks i nye migrationer — CI afviser dem ellers (5.36). DEV kører 10.11, så de fejler ellers først på 12.3.
- **Baseline** (projekter med migrationer fra før frameworket): `coworker.ini` `[migrations] baseline = NNNN`
  = højeste nummer, der var kørt i produktion, da frameworket kom ind. De filer ændres aldrig og har ingen
  header; CI kører dem fra tom base, men ikke igen og ikke down/up. Sættes i install-hotfixen (tier 1 —
  coworker.ini er kontrolfil). CI afviser: baseline over den højeste migration i repoet; baseline, der
  dækker en migration, som ikke lå på main (undtagen ved første import, hvor main ingen migrationer havde);
  og enhver ny migration med nummer ≤ baseline. Kan en gammel migration ikke køre på en tom base
  (fx afhænger den af data), er CI rød, og filen må stadig ikke ændres — det løses i frameworket, ikke i projektet.
- **Expand** (tilføj) må gå i STABLE. **Contract** (fjern/omdøb) får eget ID og merges først, når
  expand-releasen er afløst. **Destruktiv** afvises af CI uden maskinbevist restore ≤ 90 dage
  (STABLE `/health`: `restore_last_ok`).

## §7 Releases, rollback og hændelser
- **Sandhed:** git + GitHub > `changes/` > `launched/releases/` > ROADMAP. Dato = `published_at`, aldrig tag-navnet.
- **Releaset:** flag-ID'er når `stable` går til `on`; ID'er uden flag når deres commits er i en udgivet release.
  Reglerne er kodet i `report.sh` — Team Lead regner aldrig selv.
- `launched/releases/<tag>.md` skrives én gang af `report.sh notes` og ændres aldrig (kun linjen
  `Status: kasseret <dato>`). Den indeholder: tændt/slukket, hotfixes, migrationer, kontrolændringer,
  kundetekst, rollback-vej og launch-tjek.
- **Rollback:** én feature → `stable = off` i ny release. Efter flag-oprydning → nyt H `H0NN: revert F005`
  (commits fra `report.sh id`). Hele STABLE → `report.sh restore <tag>` giver planen (kode, flag,
  down-migrationer nyeste først, hvad kun backup kan redde, eksterne sideeffekter). Rollback-udkast laves
  altid, også ved Launch-tjek IKKE KLAR.
- **DEV-rollback** (når som helst, uden Publish): `report.sh devpin <sha>|før <ID>` låser DEV til en tidligere
  main-commit med grøn CI (tagget `dev`); `devpin off` = følg main igen. deploy.sh tager den; kontrolfiler,
  der har kørt på DEV før, kræver ikke Publish. Databasen rulles ikke tilbage.
- **Hændelser:** deploy.sh og backup.sh skriver `logs/incidents.log`; `/health` viser `last_incident`;
  monitor opretter en GitHub-sag med label `incident`. Rollback-release eller `stable = off` pga. fejl
  = H-nummer + `final-decisions/H0NN.md` (tidslinje, årsag, forebyggelse).
- **Incident** (`bash shared/report.sh nextid I` → `incidents/I0NN.md` efter
  `shared/templates/INCIDENT.md.template`): hver hændelse med alvor S1–S2, hvert sikkerhedsfund
  HIGH+ der har ramt et miljø med kundedata (også testkunder), og hver hemmelighed, der har været
  uden for de godkendte steder (`shared/SERVERKONTRAKT.md` §8). Filen har altid en vurdering af persondatabrud (GDPR art. 33,
  72 timer). `report.sh fixes` lister dem.

## §8 Server, deploy og drift
- **Deploy:** `shared/install/deploy.sh` → `homedir/cron/deploy.sh` (uændret). Rækkefølge: hent mål →
  status `coworker/ci` = success på præcis SHA (sat af `cron/ci.sh`)? → test/stable: på `main`? → kontrolfiler ændret?
  så kun hvis ejeren har udgivet dem (release eller "Kontrol"-pre-release) → lås (`flock` i scriptet, aldrig i
  cron-linjen) → workers pause (`logs/deploying`; alle cron-scripts starter med
  `[ -e logs/deploying ] && exit 0`) → migrationer → skift filer (`rsync --delay-updates`) → health
  (ny SHA + `db_read` + `db_write`) → ved fejl: forrige SHA (første gang: indholdet før), hændelse og
  mail (samme besked højst én gang pr. døgn). GitHub nede eller rød CI i 60+ min = alarm.
  rsync `-rlt`: ejer, gruppe og rettigheder på serveren røres ikke. `config/config.ini`,
  `config/restic.pass`, `public_html/uploads/`, `logs/` og `var/` røres aldrig.
  Cron: `*/2 * * * * /home/<p>/cron/deploy.sh <mode> >> /home/<p>/logs/deploy.log 2>&1`.
- **Overgang fra et projekts eget deploy.sh:** det gamle script ruller frameworkets deploy.sh ud ved første
  grønne main-commit, og det nye nægter alt, til serveren har `alert_email`, `real_data`, et læse-token og `/health` med `sha`/`db_read`/`db_write`. Derfor skal det være på plads FØR install-PR'en merges.
- **Token:** `config/github.token` (chmod 600), aldrig på kommandolinjen. Før nye migrationer gemmer deploy.sh
  databasen i `logs/predeploy-db.sql.gz` (chmod 600) — med projektets egen backup.sh, hvis den findes (5.27).
  Dumpet slettes efter en vellykket udrulning og ligger højst 7 døgn efter en fejlet. Uden `[database] name` (og
  uden backup.sh) afvises nye migrationer med alarm (5.36). Valgfri `[deploy] chmod` i coworker.ini.
- **Rigtige data** (kunder uden samtykke til testversion) = produktion: `real_data = true`, kun releases.
  Alle miljøer har linjen eksplicit (`true` eller `false`) — mangler den, nægter deploy.sh.
  Testkunder med samtykke står i DRIFT.md (virksomhed, kontakt, dato, dokument, hvad er accepteret).
- **`/health`** (JSON): `sha`, `status` (ok|degraded), `db_read`, `db_write`, `backup_last_ok`,
  `restore_last_ok`, `restore_rto_min`, `last_incident` (fra `logs/`-filerne).
- **Backup er maskinens (5.27).** `site-backup` på hver maskine tager alle sites hver nat: databaserne
  (`[database] name` i config.ini + `<bruger>_*`), `public_html`, `private`, `config`, `var` — krypteret væk fra
  maskinen med restic. Hver database prøve-gendannes mindst hver 7. dag: alle tabeller, ingen tom tabel der har
  rækker i drift, ≥ 90 % af rækkerne, og config.ini skal kunne hentes ud af arkivet. Beviset skrives i
  projektets `logs/backup_last_ok` og `logs/restore_last_ok` — kun når alt for sitet er sikret og pushet.
  Projektet sætter intet op: `server.sh check` og `/health` viser, om det er med. Ejes af sysadmin
  (`shared/SERVERKONTRAKT.md` §2). Projekter med egen `backup.sh` fra før 5.27 må lade den køre.
  Ligger backup helt uden for frameworket (fx hostingens snapshot): `[backup] external = <hvad>` i coworker.ini
  (kontrolfil — kræver Publish). Så kræves intet backup-/restore-bevis, men CI og noter viser "backup: ekstern".
- **Nøglemateriale** (fx nøglen, der krypterer kunders tokens) ligger aldrig i `config/`: backuppen lægger `config/`
  i samme arkiv som databasedumpet, og dump + nøgle i ét arkiv = alle kunders hemmeligheder. Nøgler hører i
  `/etc/<bruger>/keys/` (lægges af ejeren med `sitectl key`, `shared/SERVERKONTRAKT.md` §8; kun på prøvebanen indtil videre). Databasekoder og API-nøgler i `config.ini` er i orden (5.28).
- **Overvågning:** `shared/install/monitor.sh` → `homedir/cron/monitor.sh` (uændret), hver time fra DEV's
  hjemmemappe — SKAL køre på en anden maskine end STABLE (står de sammen: også fra en anden server). Problem =
  mail + GitHub-sag `incident`. Hver kørsel sætter livstegnet `coworker/monitor` på STABLE's commit; STABLE's
  deploy.sh mailer, hvis det er over 13 timer gammelt — de to vogter hinanden.
- **Ingen GitHub Actions** (koster penge): CI og overvågning kører på DEV-serveren. GitHub bruges til kode,
  branches, PR'er, releases og commit-status — alt gratis. Workflows i `.github/workflows/` afvises af CI.
- **`coworker.ini`** (repoets rod, kontrolfil): `[ci]` php, tests, analyse · `[deploy]` repo, php,
  migrate · `[health]` dev, test, stable · `[migrations]` baseline · `[backup]` external. Skabelon: `shared/templates/coworker.ini.template`.
- `launched/DRIFT.md` beskriver opsætningen; beviset kommer fra `/health` og monitor.
- Én gang per hjemmemappe (dev, test, stable) kører ejeren linjen i OPSAETNING.md "Serveropsætning":
  `server.sh init <mode>` første gang (skriver config/, config.ini og token fra sitectl's filer, kloner repo/),
  `setup <mode>` siden (init gør det, når alt findes): tjekker alt, lægger deploy.sh (dev: også ci.sh, monitor.sh)
  i `cron/` og tilføjer cron-linjer. Token i `config/github.token`: Contents: Read · Commit statuses: Read and write ·
  Pull requests: Read and write · Issues: Read and write. DEV: `[ci_database]` i config.ini (navn `*_ci`).

## §9 CI
`shared/install/ci.sh` → `homedir/cron/ci.sh` (uændret), cron hvert minut i DEV's hjemmemappe, én ad gangen,
lav prioritet. Koden, der testes, kører som CI-brugeren `<dev-bruger>-ci` (kan ikke læse DEV's config/token;
mangler den, eller kan den læse dem, testes intet — status rød). Tester ny main-commit (status `coworker/ci` — kun den godkender deploy) og derefter hver ny
commit på en åben PR (status `coworker/pr`; draft-PR'er og forks springes over). Indhold: gitleaks, PHP lint,
PHPStan level ≥ 5 med baseline, Semgrep (SQL/XSS), `composer audit`, migrationer på en tom testdatabase
(`[ci_database]`, slettes hver gang) to gange + down/up, tests afstemt på testløberens egen JUnit-rapport
(0 tests, nedbrud eller sprunget over = rød) og `report.sh ci`. Testene får databasen i `CW_DB_HOST`,
`CW_DB_PORT`, `CW_DB_NAME`, `CW_DB_USER`, `CW_DB_PASS`. Kun dokumenter: kun gitleaks og report.sh ci. Et træ,
der allerede var grønt (PR'en lige før squash-merge), testes ikke tungt igen. Rød = kommentar på PR'en med
loggens sidste linjer; rød main = også mail. CI henter også `refs/pull/*/head`, så critic-reviewets
`@ <sha>` findes, selv når GitHub har slettet PR-branchen ved merge. En commit testes igen med
`cron/ci.sh retest <sha>` på DEV-serveren (fx efter en framework-rettelse); ellers testes næste merge. Acceptkriterier tæller kun for tests, der kørte og bestod.
Kendte røde står per test-case i `tests/known-failures.txt` (`Klasse::metode | H### | ÅÅÅÅ-MM-DD`,
nye linjer højst 30 dage frem); udløbne gør CI rød.

## §10 Dokumenter
| Fil | Indhold | Regel |
|---|---|---|
| `ROADMAP.md` | `## Nu`, `## Næste`, `## Senere`, `## Backlog (ikke planlagt)`, `## Released`, `## Droppet`, evt. `## Før frameworket` (bygget og kørende, aldrig releaset) | Linje `- F012: Titel — plan: ÅÅÅÅ-MM · release: R2 — P1` eller tabelrække `\| F012 \| Titel \| plan: ÅÅÅÅ-MM \|`. `release: <navn>` (5.31) samler features, der skal på STABLE sammen; uden den er releasen planens måned (`report.sh launchplan`). `plan:` ændres aldrig, kun `· replan <dato>: <måned> (årsag)`. Flyt = kopiér linjen først, fjern den gamle i et separat trin. Flyt til Released når `report.sh check` siger det |
| `SPRINT.md` | Projekt, infrastruktur (målt), denne sprint, næste op | Ingen tal i hånden — `report.sh status` |
| `changes/<ID>.md` | `# F005: Titel`, `Type:` (feature, fejl, hotfix, gæld, sikkerhed; hotfix + `Hvorfor nu:`), Hvad, Hvorfor, Flag, Migrationer, Rollback, `Kunde:`, `Support:` | Skrives af engineer i samme PR, ændres aldrig. `Type:` kræves af CI (5.28) |
| Spec `product-ai/<ID>_vN.md` | Krav + acceptkriterier `- AK1: …` | Ny version = ny fil |
| `launched/STABLE/DECISIONS.md` | `## #N — dato — titel` | Menneskets chat-beslutninger skrives samme session. Erstattes med `Erstattet af #M`. "Accepteret risiko" har altid `Udløber: ÅÅÅÅ-MM-DD`, højst 90 dage frem (check) |
| `incidents/I0NN.md` | Efter `INCIDENT.md.template` | Pr. hændelse (§7) |
| `launched/compliance/ACCESS.md` | Adgangsregister: system, konto, rolle, ejer, oprettet, rotation, gennemgået | Hver række gennemgået ≤ 90 dage (check). Skabelon: `ACCESS.md.template` |
| `launched/compliance/SUBPROCESSORS.md` | Eksterne modtagere af kundedata, formål, data, fra-dato | Opdateres i samme PR som en ny modtager (tier 1) |
| `launched/STABLE/DEPLOYMENT.md` | Server og stier (health-URL'er står i `coworker.ini`) | Kontrolfil |
| `LEARNINGS.md` | `ÅÅÅÅ-MM-DD \| ID \| observation` | Append-only, loft 300 linjer: de ældste kopieres til `launched/learnings/ÅÅÅÅ-QN.md` og fjernes derefter, til filen er under loftet |
| `PROJEKTVIDEN.md` | Målt viden om kodebasen | Kurateret |
| `README.md` (rod) | Produktet: hvad, hvem, hvordan det køres | Aldrig frameworkets |
Andre statusfiler i roden er forbudte (`launched/history/` til gamle). Procesfiler, der kun ligger
på disken, findes ikke — commit dem (Trin 0 tjek 7).

## §11 Rapporter — svar til mennesket kommer herfra
| Spørgsmål | Kommando |
|---|---|
| Hvad kører hvor? Næste ledige numre? | `bash shared/report.sh status` |
| Hvad releasede vi i en periode? | `bash shared/report.sh month 2026-10` / `month 2026-07..2026-09` |
| Alle releases | `bash shared/report.sh releases` |
| Roadmap 6 mdr + backlog uden plan | `bash shared/report.sh roadmap` |
| Køreplan: planlagte releases til STABLE, deres features, hvad der er klar, og hvad der mangler | `bash shared/report.sh launchplan` |
| Hotfixes og hændelser i en periode | `bash shared/report.sh fixes 2026-07..2026-10` |
| Arbejdets art: antal feature/fejl/hotfix/gæld/sikkerhed pr. måned | `bash shared/report.sh fordeling 2026-07..2026-10` |
| Tid og tokens pr. ID, type og agent (Claude Codes logs på denne pc) | `bash shared/report.sh forbrug 2026-10` |
| Hvor går tiden og tokens? (kommandoer, lange sessioner, prechecks, genlæste filer, critic-runder) | `bash shared/report.sh spild 2026-10` |
| Alt om ét ID: sporet spec → impact → rapport → reviews → fragment → test → release | `bash shared/report.sh id F005` |
| Er der hemmeligheder i det, jeg vil committe? | `bash shared/report.sh secrets` |
| Ejerens godkendelse af en kontrolændring (udkast) | `bash shared/report.sh ctrl <sha>` |
| Tilbage til release X inkl. database | `bash shared/report.sh restore <tag>` |
| Er alt i orden? | `bash shared/report.sh check` (tomt = OK) |
| Frameworkets version | `bash shared/report.sh version` |
Svaret gengives, ikke omformuleres.

## §12 Onboarding af et projekt (§0-gaten)
Ingen F-features, før disse er målt grønne (`report.sh check` tomt for dem):
1. Git, trunk-based (`main` + korte branches), GitHub-repo.
2. `bash shared/report.sh install` + `coworker.ini` udfyldt; CI (DEV-serveren) grøn på PR og main.
3. `server.sh init <miljø>` (første gang) / `setup` (siden) = `SERVER: KLAR` på hvert miljø (deploy.sh i cron, health med alle felter; dev: ci.sh).
4. Med i maskinens backup: `server.sh check stable` viser `✔ med i serverens backup` (eller `[backup] external`).
5. cron/monitor.sh kører fra en anden maskine end STABLE (livstegn `coworker/monitor`).
6. DRIFT.md (med samtykke-tabel), `launched/compliance/ACCESS.md`.
7. `alert_email` og `real_data` (true/false) i config.ini på alle miljøer.
H-hotfixes må køre imens — via PR og deploy.sh, aldrig WinSCP.

## Aldrig
Kode skrevet af Team Lead · push til `main` · udgivelse af en agent · merge ved rød CI ·
`git add -A` i docs-PR'er · hemmeligheder i git · ændring af en kørt migration · rettelser i
framework-filer i et projekt · overskrivning af menneskets filer · tal skrevet i hånden ·
status fra hukommelsen · historik i kodekommentarer · `nano` i en anvisning til mennesket (altid `vi`).
