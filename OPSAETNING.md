<!-- FILE_VERSION: 5.42 -->
# Opsætning — Coworker Framework 5.42 i Claude Desktop

Frameworket kører i **Claude Desktop → fanen Code → Local → projektmappen,
UDEN worktree**. Ikke i Cowork. PC'en og Claude Desktop skal være tændt,
mens agenterne arbejder.

Fra trin 3 har hvert trin én kontrol-kommando. Skriv den i terminalen i
Code-sessionen (**Ctrl+`**). Giver den ikke det forventede svar: stop og
løs det, før du går videre.

Markeringer: **[målt]** = afprøvet i praksis på EnvoyAgent 06-10-2026.
**[dok]** = fra Anthropics/GitHubs dokumentation, endnu ikke afprøvet hos dig.

Overalt hvor der står `<ejer>/<repo>`, skriver du repoets adresse på
github.com, fx `albers1902/envoyagent`. Nyt projekt uden repo: opret det
først på github.com → **New repository** → **Private** → sæt flueben i
**Add a README file** (så får repoet en `main`-branch, som frameworket
bygger videre på) → **Create repository**.

---

## 1. Programmer på PC'en (én gang)

Installér med standardvalg, og genstart derefter Claude Desktop:
- Git for Windows — https://git-scm.com/download/win
- GitHub CLI — https://cli.github.com

(Kontrolleres i trin 3.)

## 2. Kopiér frameworket ind i projektmappen

Kopiér ALT i `C:\ClaudeCoworkers\coworker-development` ind i
projektmappen og vælg **Erstat filerne i destinationen**. Master
indeholder kun frameworkets egne filer, så intet af projektets bliver
overskrevet.

(Kontrolleres i trin 3.)

## 3. Start Team Lead-sessionen — UDEN worktree [målt]

1. Luk alle gamle Code-sessioner for projektet.
2. Fanen **Code** → **+ New session**.
3. Under tekstfeltet: miljø **Local** → **Select folder** → projektmappen.
4. Ved siden af branch-navnet under tekstfeltet er der en **worktree**-
   mulighed. Den skal være **slået FRA**. [dok]
5. Tilstand: **Auto**.
6. Viser Claude en dialog om at stole på mappen (workspace trust) med en
   liste over regler: **godkend**. Det aktiverer reglerne, der lader Team
   Lead merge. [dok]

**Kontrol 3a:** `git rev-parse --show-toplevel` → projektmappen, fx
`C:/ClaudeCoworkers/EnvoyAgent`. Står der `.claude/worktrees` i stien:
luk sessionen, start en ny og sørg for at worktree er FRA.

**Kontrol 3b:** `gh --version` → et versionsnummer (ellers: trin 1).

**Kontrol 3c:** `ls _claude/hooks/guard.ps1` → filen vises (ellers: trin 2).
(Er frameworket allerede installeret, findes `.claude/hooks/guard.ps1` i
stedet — det er også i orden.)

## 4. GitHub-nøgle pr. projekt (én gang per repo)

Hvert projekt har sin EGEN nøgle, der kun kan nå projektets repo. gh's fælles login bruges ikke: det har kun
plads til én nøgle på hele pc'en, og et login i ét projekt tog adgangen fra de andre (econsync 2026-10-14).

**Nøglen:** github.com → dit profilbillede → **Settings** → **Developer settings** → **Personal access tokens** →
**Fine-grained tokens** → **Generate new token** (har du allerede én til repoet, så brug den):
- Navn: `coworker-<repo>` · Udløb: 1 år
- Repository access: **Only select repositories** → kun dette repo
- Permissions → Repository: **Contents** Read and write · **Pull requests** Read and write ·
  **Issues** Read and write · **Commit statuses** Read-only · **Metadata** Read-only. Intet andet — især IKKE Administration.
- **Generate token** → kopiér den. Læg den kun ind med linjen nedenfor. En kopi i din password-manager er ekstra sikkerhed, ikke vejen tilbage: mistes nøglen, laver du en ny. Indsæt den aldrig i chatten.

**Nemmest — alle projekter på én gang:** efter at master er kopieret ind, kør
`powershell -ExecutionPolicy Bypass -File C:\ClaudeCoworkers\coworker-development\shared\opdater-pc.ps1`.
Den opdaterer `.claude` i hvert projekt og spørger kun efter nøglen til de projekter, der mangler én (Enter = spring over).

**Ét projekt ad gangen:** PowerShell i projektmappen (fx `C:\ClaudeCoworkers\econsync`), indsæt hele linjen, og
indsæt nøglen, når den spørger (den vises ikke). Linjen tilføjer kun nøglen til `.claude/settings.local.json`
(git-ignoreret, kun på din pc) — alt andet i filen bliver stående:
```
$s=Read-Host "Indsæt nøglen til DETTE repo" -AsSecureString; $p=[Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)); $f=Join-Path $PWD ".claude/settings.local.json"; $j=$null; if((Test-Path $f) -and (Get-Item $f).Length -gt 0){$j=Get-Content $f -Raw|ConvertFrom-Json}; if(-not $j){$j=[pscustomobject]@{}}; if(-not $j.env){$j|Add-Member env ([pscustomobject]@{}) -Force}; $j.env|Add-Member GH_TOKEN $p -Force; $j.env|Add-Member GIT_CONFIG_COUNT "2" -Force; $j.env|Add-Member GIT_CONFIG_KEY_0 "credential.https://github.com.helper" -Force; $j.env|Add-Member GIT_CONFIG_VALUE_0 "" -Force; $j.env|Add-Member GIT_CONFIG_KEY_1 "credential.https://github.com.helper" -Force; $j.env|Add-Member GIT_CONFIG_VALUE_1 "!gh auth git-credential" -Force; [IO.File]::WriteAllText($f, ($j|ConvertTo-Json -Depth 20), (New-Object Text.UTF8Encoding $false)); "OK: nøglen er sat for $(Split-Path $PWD -Leaf) — start en ny session"
```
Start derefter en ny session i projektet. Claude Code giver nøglen til Team Lead, engineers og critics, og git
bruger den samme nøgle (ikke Windows' gemte login).

**Én gang, når alle projekter har deres nøgle:** `gh auth logout` i en terminal. Så findes der intet fælles login,
som et projekt kan falde tilbage på.

**Kontrol i hvert projekt:** `bash shared/report.sh access` → `ADGANG: OK`. Agenter må aldrig ændre login (guarden
blokerer `gh auth login/switch/logout`). Begrænsning: nøglen beskytter mod uheld (et projekt, der rører et andet
repo), ikke mod en agent, der bevidst læser et andet projekts fil på samme Windows-bruger.

## 5. Repo-indstillinger på github.com (én gang per repo)

Repoet → **Settings → General**:
- **Default branch** = `main`
- Under "Pull Requests": flueben i **Automatically delete head branches**

**Kontrol:**
`gh repo view <ejer>/<repo> --json defaultBranchRef,deleteBranchOnMerge --jq '.defaultBranchRef.name + " " + (.deleteBranchOnMerge|tostring)'`
→ `main true`

(Uden GitHub Pro håndhæver GitHub ikke regler på `main`. Låsen sidder derfor i
deploy, CI og guard-hook'en — se LIFECYCLE.md §1.)

## 6. Første besked til Team Lead

Kopiér og send:

```
Kør CLAUDE.md Trin 0 og vis mig output af hver kommando. Er det en ny
framework-version, så kør "Ny framework-version". Fortæl mig til sidst
præcis hvor projektet står på LIFECYCLE.md §12, og hvad næste skridt er.
```

**Hvis Team Lead siger "`.claude` er installeret — start en ny
session"**: luk sessionen, start en ny præcis som i trin 3 (godkend
dialogen om at stole på mappen, hvis den kommer), og send beskeden
ovenfor igen. Det sker kun første gang.

**Kontrol:** `git status --short --branch` → første linje `## main...origin/main`
og ingen linjer derunder.

**Bliver merge blokeret** med "Merge Without Review" (så er workspace
trust ikke slået igennem): skift tilstand til **Manual**, bed Team Lead
prøve igen, og vælg **"Yes, and don't ask again"** i dialogen. Skift
tilbage til **Auto**. Det er den ene godkendelse, du giver.
[målt: blokering i Auto uden regel · dok: allow-regler og "don't ask
again" afgør handlingen, før auto-mode vurderer den]

## 7. Bevis at Team Lead ikke kan kode

Send:
```
Test af guard-hook'en (godkendt af mig): forsøg at oprette filen
src/guard-test.txt med Write-værktøjet, og vis mig præcis den fejl,
du får. Lykkes det, så sig det med det samme.
```

**Kontrol:** Team Lead viser fejlen **BLOKERET af framework-guard**.

---

## Hverdagen

- Du taler kun med Team Lead i Code-fanen (Local, uden worktree).
- Du tester i DEV og siger "go", når en feature skal i TEST.
- Du tester i TEST og siger "go", når den skal i STABLE.
- Du udgiver STABLE: github.com → repoet → **Releases** → åbn udkastet
  Team Lead har lavet → **Publish release**. Ingen agent kan udgive.
- Fortryd STABLE: sig "rollback" til Team Lead; han laver et udkast med
  den forrige version, og du trykker **Publish release**.
- Fortryd DEV — når som helst, uden Publish: sig fx "rul DEV tilbage til før F012" eller "vis
  DEV-versioner". Team Lead låser DEV til den version; main og TEST/STABLE røres ikke. "DEV skal følge
  main igen" ophæver låsen. Databasen i DEV rulles ikke tilbage (Team Lead viser, hvilke migrationer
  der bliver stående).
- Udover kontrol-kommandoerne og den engangs serveropsætning nedenfor
  skriver du aldrig git-kommandoer.

## Serveren (én gang per miljø — Team Lead guider dig)

Kræver en terminal på serveren som projektets bruger (SSH). Maskinerne er Ubuntu 24.04 uden kontrolpanel;
alt uden for projektets hjemmemappe er sysadmins (`shared/SERVERKONTRAKT.md`).
Team Lead giver dig de præcise kommandoer (LIFECYCLE.md §8, `shared/install/`).

- Server-token (fine-grained, kun dette repo): **Contents** Read · **Commit statuses** Read and write ·
  **Pull requests** Read and write · **Issues** Read and write · **Metadata** Read. Du indtaster det i linjen
  nedenfor; `server.sh init` lægger det i `config/github.token` (kun tokenet, chmod 600) — aldrig i git, aldrig i
  config.ini, aldrig på en kommandolinje. Samme token i alle projektets hjemmemapper er i orden.
- `config/config.ini` skriver `server.sh init` (nedenfor): `alert_email = alarms@albers.dk` (ejerens alarmmodtager,
  2026-10-30), `real_data = false` på dev og test — på stable spørger den, og du svarer `true` eller `false`
  (true = rigtige kundedata: følger kun releases, du selv har udgivet). Mangler linjen, nægter serveren at opdatere.
- Backup: intet pr. projekt. Maskinens `site-backup` tager alle sites (sat op af sysadmin én gang pr. maskine).
  `config/config.ini` skal have `[database]` med appens `name`, så backuppen finder databasen.
  `server.sh check` viser `✔ med i serverens backup` dagen efter.
### Serveropsætning (CI og overvågning kører på serveren — ingen GitHub Actions)
Én gang pr. hjemmemappe (dev, test og stable), efter at Team Lead har merget den nye framework-kopi. Rækkefølgen for et
nyt miljø er `shared/SERVERKONTRAKT.md` §3a; Team Lead guider dig ét trin ad gangen.
1. **Før linjen (som root, kontraktens grønne kommandoer):** `sitectl new …` og `sitectl db <domæne> <navn>` — og på
   DEV også `sitectl db <DEV-domæne> ci` (testdatabasen `<bruger>_ci`, som KUN dens egen bruger har adgang til; den
   slettes og genskabes ved hver testkørsel). Værdierne skriver du ikke af: `server.sh init` læser dem fra
   `private/db-*.txt`, som `sitectl db` lægger dér.
   Mangler `python3-venv` (Semgrep): maskinens sag — spørg sysadmin (Ubuntu-maskinerne har den fra opsætningen).
   **CI-bruger** (koden, der testes, kører som den — uden adgang til DEV's config og token): **laves af
   sysadmin-siden** (SERVERKONTRAKT §3a trin 6) med DEV's brugernavn (fx `storixdv`). Skriv ikke selv i
   `/etc/sudoers.d/` — en tastefejl dér gør `sudo` ubrugelig på hele maskinen. Reglen, sysadmin-siden lægger
   (valideret med `visudo -cf` før den lægges på plads), er `<p> ALL=(<p>-ci) NOPASSWD: ALL`, plus `<p>-ci` i
   `/etc/cron.deny` og `/etc/at.deny`. Sysadmin-siden melder en logpost; `server.sh check dev` viser
   `✔ CI-bruger <p>-ci`, når den er der.
2. **I hver hjemmemappe** (som projektets bruger): indsæt linjen, Team Lead giver dig — den her med `<ejer>/<repo>`
   og miljøet (`dev`, `test` eller `stable`) udfyldt — og indtast tokenet (det vises ikke). Findes
   `config/github.token` allerede, trykker du bare Enter — så bruges filens token:
   ```
   cd ~ && read -rsp 'GitHub-token (Enter = brug config/github.token): ' CW_TOKEN && echo && { [ -n "$CW_TOKEN" ] || CW_TOKEN=$(tr -d ' \r\n' 2>/dev/null < config/github.token) && [ -n "$CW_TOKEN" ] || { echo '✘ intet token — config/github.token findes ikke (eller er tom): kør linjen igen, og indtast tokenet. Intet er skrevet'; false; }; } && export CW_TOKEN CW_REPO=<ejer>/<repo> && S=$(printf 'header = "Authorization: Bearer %s"\nheader = "Accept: application/vnd.github.raw"\n' "$CW_TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$CW_REPO/contents/shared/install/server.sh?ref=main") && bash <(printf '%s\n' "$S") init <miljø>; unset CW_TOKEN S
   ```
   Den henter `server.sh` fra `main` og kører `init`: laver det, der mangler (`config/` 700, `config/config.ini` og
   `config/github.token` 600, `~/repo`) og rører aldrig en fil, der findes. Derefter tjekker den alt (✔/✘), lukker
   `config/` for andre, lægger driftsfilerne i `cron/`, henter CI-værktøjerne og tilføjer de manglende cron-linjer
   (dine egne linjer røres aldrig). Send Team Lead outputtet. Første gang ender den normalt i `IKKE KLAR` på
   `/health`: vent på første deploy fra cron (et par minutter), og kør samme linje igen, til der står `SERVER: KLAR`.
   Samme linje bruges senere, når Team Lead beder dig køre opsætningen igen (Enter = det token, der ligger).
   - Siger den `roter tokenet`: tokenet har kunnet læses af andre brugere på maskinen og er lækket (SERVERKONTRAKT
     §8). Den bliver ved med at sige det (`logs/.token-laekket`), til tokenet er udskiftet: lav et nyt token på
     GitHub (samme rettigheder), slet det gamle dér, og kør samme linje med det nye — `init` erstatter filen.
     Et andet token end filens erstatter kun et token, der er meldt lækket.
   - Siger den `repo/ er ikke <ejer>/<repo>`: linjen og klonen passer ikke sammen — intet er lagt i cron. Flyt
     `repo/` væk (aldrig slet), og bed Team Lead om linjen igen.
   - Siger den `main har ingen coworker.ini endnu`: framework-kopien er ikke merget. Lad `repo/` ligge, og kør samme
     linje igen, når Team Lead siger til.
   Skal en bestemt commit testes igen (Team Lead siger hvilken): `~/cron/ci.sh retest <sha>` i DEV's hjemmemappe.
3. Står der `!` om, at STABLE står på samme maskine som DEV: så ser ingen, hvis maskinen går ned. Ekstra vagt
   fra en anden server: lav dér en bruger, og kør linjen fra trin 2 i dens hjemmemappe med `init monitor` (samme
   token; den skriver kun `alert_email` i config.ini og lægger kun `monitor.sh` i cron).

- **Et system, der allerede kører (retrofit) — rækkefølgen:** 1)–2) `server.sh check stable` viser `✔ med i
  serverens backup` med en restore-prøve (maskinens site-backup, en nat); 3) læg output af
  `for f in backup_last_ok restore_last_ok; do echo "$f $(cat logs/$f)"; done` i
  `launched/STABLE/foerste-release-bevis.txt` (Team Lead committer den); 4) første release (Publish);
  5) deploy-cron for stable. Fra da af kommer beviset fra `/health`.
