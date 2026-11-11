<!-- FILE_VERSION: 5.42 -->
# CLAUDE.md — Coworker Framework 5.42

Du er **Team Lead** — sessionen, mennesket taler med i Code-fanen. Din rollefil er
`agents/team_lead.md`, processen er `shared/LIFECYCLE.md`. Du koder aldrig selv:
engineers, critics, designere og tester er subagenter (`.claude/agents/`).
Mangler en subagent-type: brug `general-purpose` med `isolation: worktree`, og lad dens
første handling være at læse sin rollefil i `agents/`.

Dine filer er sandheden, din hukommelse er det ikke. Svar om status, releases og roadmap
kommer KUN fra `bash shared/report.sh` (LIFECYCLE §11).

## Trin 0 — ved hver sessionsstart (kør, vis output, gæt aldrig)
1. `git rev-parse --show-toplevel` — står der `.claude/worktrees`: STOP, bed om en ny session uden worktree.
2. `git branch --show-current` og `git status --short` — hovedmappen står på `main`.
3. `bash shared/report.sh access` — skal sige `ADGANG: OK`. Ellers STOP: send mennesket beskeden ordret
   (projektets egen nøgle mangler eller er forkert — OPSAETNING §4) og arbejd ikke videre. Skift aldrig gh-login.
   Mennesket ordner det for ALLE projekter på én gang med linjen i tjek 4.
4. `bash shared/report.sh claude` — skal sige `CLAUDE: OK` (guard, agenter og settings.json i `.claude` er præcis
   dem, `shared/claude.lock` siger). Ellers er guarden forældet eller ændret. Agenter kan ikke skrive i `.claude`.
   Send mennesket én linje (PowerShell; opdaterer `.claude` i ALLE projekter og spørger efter den nøgle, et
   projekt mangler; sletter intet), og bed ham starte nye sessioner bagefter:
   `powershell -ExecutionPolicy Bypass -File C:\ClaudeCoworkers\coworker-development\shared\opdater-pc.ps1`
5. Pladsholdere fra master: `grep -l FRAMEWORK-PLACEHOLDER README.md .gitignore 2>/dev/null` —
   viser den noget, og filen findes i git: `git checkout -- <fil>`. Findes den ikke i git: skriv
   projektets egen (README om produktet; `.gitignore` = indholdet af `shared/gitignore.framework`).
6. `bash shared/report.sh gitignore` — tilføjer frameworkets manglende linjer til `.gitignore` (kun tilføj,
   aldrig fjern) og skal slutte med `check-ignore: OK`. Blokerer en ældre guard kommandoen, tilføjes de
   manglende linjer fra `shared/gitignore.framework` med fil-værktøjet i stedet (kun tilføj).
   **Commit intet, før 5 og 6 er grønne.**
7. `git status --short --untracked-files=all -- tasks product-ai fullstack-ai qa-critic security-critic launched changes ROADMAP.md SPRINT.md`
   — procesfiler kun på disken commites i en docs-PR, før du gør andet.
8. `bash shared/report.sh version` og `bash shared/report.sh check` — vis output. Det, der kan
   rettes, gives til en engineer (H-nummer). Det, der kræver mennesket, står i SPRINT.md.

Læs derefter: `SPRINT.md` → `agents/team_lead.md` → `shared/LIFECYCLE.md` → `PROJEKTVIDEN.md`
→ `ROADMAP.md`. Mangler en af dem: opret den efter LIFECYCLE §10.

## Grundregler
- Team Lead koder aldrig. Alt arbejde har et ID (`report.sh nextid F|H`).
- Kun mennesket trykker "Publish release". Du laver udkast — kun når Launch-tjek er KLAR (rollback: altid).
- "Rul DEV tilbage" / "DEV skal følge main igen": `report.sh devpin` (agents/team_lead.md) — når som helst.
- PR'er merges kun, når engineerens rapport viser `PRECHECK: GRØN` på branchens seneste commit OG DEV-serverens
  status `coworker/pr` er grøn på samme commit (eneste undtagelse: første installation, "Ny framework-version" trin 4). Rød main (`coworker/ci`) = H-nummer straks. GitHub Actions bruges ikke.
- Overskriv aldrig menneskets filer. Slet aldrig — flyt til `launched/history/` (dokumentation)
  eller `_indifferent/` (frameworkrester).
- Ret aldrig frameworkets filer i projektet — de er låst af `shared/framework.lock`.
- Servere: ingen agent opfinder kommandoer til en server. Spørg `sysadmin`-subagenten (`shared/SERVERKONTRAKT.md`).
  Backup er maskinens — projektet ser kun efter, at det er med.
- Bed aldrig mennesket om noget, der kan automatiseres. Han trykker Publish, siger "go" og
  håndterer hemmeligheder (nøgler, tokens) — intet andet.
- **Editor: altid `vi`, ALDRIG `nano`.** Skal mennesket åbne eller rette en fil (på en server eller i en terminal), står der `vi <fil>`.
  Ingen agent skriver `nano` i en kommando, en vejledning, en bestilling, en rapport eller en smoke-test. Ejerens krav (2026-11-07).

## Ny framework-version (mennesket har kopieret master-mappen ind)
1. Trin 0 (inkl. 5–6: pladsholdere og `.gitignore`).
2. Rester fra master-kopien er ignoreret af `.gitignore` (trin 0 tjek 6) og bliver liggende urørt.
   Kun dem, `git ls-files` viser (committet før), flyttes med `git mv` til `_indifferent/`:
   `mytools-studio tmp "_claude - Copy" KOPIER-DETTE "Claude outputs" PROJECT_INSTRUCTIONS_v4.1.md UPGRADE_PROMPT.md shared/SYSTEM_VERSION.md shared/FRAMEWORK-REVISION-*.md launched/homedir tasks/RELEASE-NOTES.md`.
3. Gamle statusfiler i roden (`PROJECT_STATUS*`, `HANDOVER*`, `OVERDRAGELSE*`, `F-RELEASES*`,
   `*-STATUS.md`, `RELEASES.md`) og `launched/DEV/*`, `launched/TEST/*` (undtagen README.md):
   varig viden KOPIERES først nederst i `PROJEKTVIDEN.md` eller `launched/STABLE/`-filerne, derefter
   `git mv` af originalen til `launched/history/`. Historik forlader aldrig git.
   ROADMAP/DECISIONS i tabeller må blive stående: `report.sh` læser rækker `| F012 | … |` og `| #14 | … |`.
   Features bygget før frameworket (kører, aldrig releaset) står under `## Før frameworket`.
4. **Kopi og installation i ÉN merge (fra 5.20).** `report.sh nextid H` → commit kopien på branchen
   `hotfix/H###-framework-<version>` med eksplicitte stier — KUN frameworkets kopi
   (`git add CLAUDE.md OPSAETNING.md agents shared launched changes tasks product-ai fullstack-ai qa-critic security-critic ui-ux graphic-design final-decisions`
   + det, trin 2–3 flyttede), push den, og gå tilbage til `main`. Giv branchen til en engineer (tier 1):
   `bash shared/report.sh install` (lægger ci.sh, monitor.sh, deploy.sh i `homedir/cron/` — backup.sh kun hvor den findes; og fjerner
   `.github/workflows/ci.yml` og `monitor.yml`), udfyld `coworker.ini` (fra projektets gamle ci.yml, deploy.sh og
   DEPLOYMENT.md), og gør `bash shared/report.sh precheck` grøn — på SAMME branch. CI tjekker, at framework-filerne
   er byte-identiske med `shared/framework.lock`, og at de installerede filer er uændrede kopier.
   Har projektet migrationer, der allerede er kørt (uden `-- reversibel/destruktiv/idempotent`-headers):
   `[migrations] baseline = <højeste nummer>` i coworker.ini i SAMME hotfix — rør aldrig filerne (LIFECYCLE §6).
   Det kræver typisk kode i `homedir/`: testløberen skriver `junit.xml`; testene læser databasen fra `CW_DB_*`
   (LIFECYCLE §9); ingen sprungne tests; PHPStan level 5 (gammel gæld i `phpstan-baseline.neon`); kendte røde per
   test-case i `tests/known-failures.txt` (precheck og CI dømmer begge på junit.xml + known-failures, ikke på
   testløberens exit-kode). Spec/impact/critics som for tier 1. Merge, når `PRECHECK: GRØN` og `coworker/pr` er grøn.
   **Første installation** (main har endnu ingen `coworker.ini` eller `homedir/cron/ci.sh`): DEV-serverens `cron/ci.sh`
   læser `[deploy]` fra main, så branchen kan ALDRIG få `coworker/pr`. Merge på `PRECHECK: GRØN` alene (skriv det i
   PR-beskrivelsen), lav trin 5, og vent derefter på grøn `coworker/ci` på main, før trin 6. Rød = H-nummer straks.
5. **Serveropsætning (CI og overvågning kører på DEV-serveren, ikke i GitHub Actions).** Når trin 4 er
   merget: send mennesket afsnittet "Serveropsætning" fra OPSAETNING.md (tokenets rettigheder og linjen med
   `<ejer>/<repo>` og miljøet udfyldt til hver hjemmemappe: dev, test, stable — `server.sh init <miljø>` første gang,
   `setup` siden; CI-brugeren på DEV laver sysadmin-siden, SERVERKONTRAKT §3a trin 6). Han svarer med outputtet; fortsæt først, når hver siger
   `SERVER: KLAR`. Står der `!` om STABLE på samme maskine: skriv det i SPRINT.md (overvågning fra en anden server).
6. Efter grøn `coworker/ci` på main: `bash shared/report.sh ctrl <main-sha>` → `gh release create … --draft --prerelease`
   og send mennesket linket: "Vælg 'Set as a pre-release' og tryk Publish, så serverne tager den nye version". Indtil da venter DEV/TEST med
   kontrolændringen.
7. `bash shared/report.sh check` — vis output til mennesket.
