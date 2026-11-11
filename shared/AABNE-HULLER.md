<!-- FILE_VERSION: 5.42 -->
# Frameworkets åbne huller

Kendte svagheder, der står åbne med vilje, med samme felter som en accepteret risiko i DECISIONS.
`report.sh lint` (køres af vedligeholderen før hver levering) fejler, når en `Udløber:` er passeret eller
mangler. Så skal hullet lukkes, eller beslutningen tages op igen og få en ny dato med en begrundelse.
Projekter skal ikke gøre noget ved listen. Den vedligeholdes kun i master.

## A1 Kode fra en PR har netværksadgang og kan forfalske sit eget resultat
Hvad: CI-brugeren (5.19) kan ikke læse DEV's token eller config. Testkoden kan dog nå netværket (DEV's egen
webapp, internettet) og kan skrive en junit.xml, der siger grøn.
Hvorfor åben: Reelt angreb kræver en ondsindet PR-forfatter; alle forfattere er egne agenter. Netværksisolation
kræver root-opsætning (nftables pr. bruger) på hver server.
Besluttet af: vedligeholderen 2026-10-12 (vurderet som ikke værd at rette nu). Ikke en ejerbeslutning.
Udløber: 2027-01-13

## A2 Backup er ikke append-only
Hvad: Maskinens `site-backup` (5.27) har restic-kodeordet og rclone-adgangen på maskinen og kører `restic forget
--prune`. Root på én maskine kan slette dens backup. Alle maskiner bruger samme Google Drive, og rclone med scope
`drive.file` ser alt, rclone har lagt der — så den kan også slette de andre maskiners backup.
Hvorfor åben: Kræver en destination med object lock, og den koster penge. Ejeren betaler ikke for backup (2026-10-15).
Besluttet af: ejeren (FRAMEWORK-CHANGELOG v5.5 og 2026-10-15).
Udløber: 2027-04-13

## A3 Semgrep er versionslåst, men ikke hash-låst
Hvad: `cron/ci.sh tools` installerer `semgrep==1.97.0` med pip uden `--require-hashes`. gitleaks og PHPStan er
sha256-låst (5.6 og 5.25).
Hvorfor åben: En hash-liste skal dække Semgreps ~40 afhængigheder for serverens præcise Python-version og
platform. En forkert liste stopper CI i alle projekter. Den kan ikke laves og testes herfra (ingen PyPI-adgang).
Skaden begrænses af CI-brugerens isolation (5.19).
Besluttet af: vedligeholderen 2026-10-13 (gennemgang, fund "småt" 3).
Udløber: 2027-01-13

## A4 Frameworket er ikke kørt ende til ende på en rigtig server mod GitHubs rigtige API
Hvad: CI, deploy og monitor er testet med rigtige Linux-brugere, rigtig MariaDB, rigtig git og rigtig PHPStan (Semgrep er en stub),
men GitHub-API'et er en stub. CI-brugerens sudo/cron.deny er ikke prøvet på Ubuntu-maskinerne.
Hvorfor åben: Vedligeholderen har ingen server- eller repo-adgang. Projekterne melder fejl tilbage
(econsync, deskcore, Storix).
Besluttet af: vedligeholderen (FRAMEWORK-CHANGELOG 5.16–5.21, "Ikke verificeret").
Udløber: 2027-01-13

## A5 GitHub håndhæver intet på main
Hvad: Uden GitHub Team/Pro kan en agent teknisk merge en rød PR. Garantien er, at deploy aldrig udruller en
commit uden grøn `coworker/ci`, og at mennesket ser kontrolændringer før Publish.
Hvorfor åben: Kræver et betalt GitHub-abonnement.
Besluttet af: LIFECYCLE §1 (v5.3–5.4). Hvert projekt skriver den i DECISIONS med `Udløber:`.
Udløber: 2027-04-13

## A6 Critics og engineer er samme leverandørs modeller
Hvad: Critics kører på en anden model end engineeren (5.22, `model:` i `_claude/agents/`). Det mindsker fælles
blinde vinkler, men fjerner dem ikke: modellerne har samme oprindelse.
Hvorfor åben: Kun Claude-modeller er tilgængelige i Claude Code.
Besluttet af: vedligeholderen 2026-10-13 (gennemgang, fund 2).
Udløber: 2027-01-13

## A7 To critics kan godkende ud fra modstridende antagelser
Hvad: qa-critic og security-critic ser ikke hinandens reviews. Siger begge APPROVED ud fra modstridende
antagelser om koden, ser ingen det.
Hvorfor åben: En krydsrunde fordobler critic-kørslerne på hver tier 1 og sender uenighed til ejeren, som kun
trykker Publish, siger "go" og håndterer hemmeligheder (CLAUDE.md). En critic, der finder noget, blokerer
allerede. Ikke observeret i projekterne.
Besluttet af: vedligeholderen 2026-10-13 (gennemgang, fund 4). Ikke en ejerbeslutning.
Udløber: 2027-01-13

## A8 Projekternes GitHub-nøgler ligger på samme Windows-bruger
Hvad: Hvert projekt har sin egen nøgle (5.23), og guarden forhindrer agenter i at vise den eller skifte login. En agent,
der bevidst læser et andet projekts `.claude/settings.local.json`, kan dog bruge den nøgle.
Hvorfor åben: Ægte adskillelse kræver en Windows-bruger pr. projekt. Nøglen pr. projekt stopper uheld, som var det,
der skete (econsync 2026-10-14).
Besluttet af: vedligeholderen 2026-10-14. Ikke en ejerbeslutning.
Udløber: 2027-01-14

## A9 Repo-tjekket i `server.sh init` stoler på repoets eget navn
Hvad: `init` sammenligner `CW_REPO` fra linjen med `[deploy] repo` i `coworker.ini` i det klonede repo. Et fremmed repo, der
angiver det samme navn, passerer, og cron-scripts installeres derfra.
Hvorfor åben: Det kræver en forkert eller manipuleret linje. Linjen udfyldes af Team Lead fra projektets egen `coworker.ini`.
Besluttet af: Team Lead 2026-11-04 (U1 go-v3, security LOW 3). Ikke en ejerbeslutning.
Udløber: 2027-02-02

## A10 Gamle deploy.sh og ci.sh fjerner beviset for et lækket token, indtil Publish
Hvad: Indtil ejeren har trykket Publish på 5.38, chmod'er den gamle `deploy.sh` og `ci.sh` på serverne et åbent token til 600
uden markør, og `server.sh` kan så melde ✔. Rotér et token, der kan have været åbent før 5.38, uanset hvad `check` siger.
Hvorfor åben: Kontrolfiler når først serverne efter Publish. Samme hul som før U1, ikke et nyt.
Besluttet af: Team Lead 2026-11-04 (U1 v3, security LOW). Ikke en ejerbeslutning.
Udløber: 2027-02-02

## A11 Fejlet markør i deploy.sh og ci.sh ses kun i loggen
Hvad: Kan `logs/.token-laekket` ikke skrives, lader `deploy.sh` og `ci.sh` tokenet stå åbent og skriver en logline. Ingen
overvågning melder det, før nogen kører `server.sh check`.
Hvorfor åben: Åbent token med bevis er valgt frem for lukket token uden bevis (U1 go-v3 2). En kontrol i `monitor.sh` kan lukke det.
Besluttet af: Team Lead 2026-11-04 (U1 v3, security LOW). Ikke en ejerbeslutning.
Udløber: 2027-02-02

## A12 `o+x` på DEV's hjemmemappe lader andre læse åbne filer med kendt sti
Hvad: `server.sh setup dev` sætter `o+x`, så CI-brugeren kan nå `ci-tools/`. Kontrakt 1.1.2 §9 punkt 1 har målt (fikstur), at en
anden bruger så kan læse en fil med kendt sti, hvis filen selv kan læses af andre. `config/`, `private/`, `logs/`, `ci/` og `.ssh/`
lukkes for andre på DEV; `var/` og appens øvrige mapper gør ikke.
Hvorfor åben: Kun DEV (5.38 ændrer ikke test og stable), og `server.sh` er ikke ændret uden review. Lukning af `var/` og
rettelsen af teksten "ikke se indholdet" hører til 5.39, eller CI-værktøjerne flyttes (kontrakten §9 punkt 2).
Besluttet af: Team Lead 2026-11-04. Ikke en ejerbeslutning.
Udløber: 2027-02-02

## A13 U1 og U2 er ikke kørt på Linux før levering
Hvad: 5.38 er flettet, uden at `selftest-ci.sh` er kørt, og `selftest-deploy.sh` er kun kørt med stubbe på Windows. Det gælder
`server.sh init`, markør-kæden i `deploy.sh` og `ci.sh`, U2's tests, rigtige symlinks og unix-rettigheder. Testene kan selv have fejl.
Hvorfor åben: Der er ingen Linux-maskine med root til test, og WSL er ikke installeret.
Besluttet af: ejeren 2026-11-04 ("Flet uden test").
Udløber: 2027-02-02

## A14 Rød CI på DEV når ingen, når mailen fejler
Hvad: `ci.sh`'s `alert()` ("main er rød", "CI kører ikke") logger og sender mail, men opretter ingen GitHub-sag. DEV har ingen
alarmvej (kontrakten §7), så alarmen ses kun i commit-status, `logs/ci.log` og `report.sh check`. Stemplet sættes før mailen, så der
heller ikke prøves igen samme døgn.
Hvorfor åben: En sag fra `ci.sh` er en ændring i en kontrolfil og hører til en version med test på Linux.
Besluttet af: Team Lead 2026-11-05 (gennemgang af kontrakt 1.1.3, F2). Ikke en ejerbeslutning.
Udløber: 2027-02-03

## A15 Projektets egen backup.sh har sit kodeord i sit eget repository
Hvad: `shared/install/backup.sh` (kun projekter med egen backup fra før 5.27) læser `[backup] password_file`, typisk
`config/restic.pass`, og tager `config/` med i samme repository. Uden en kopi et andet sted kan den backup ikke læses, og en
password-manager er ikke vejen tilbage (ejeren 2026-11-04).
Hvorfor åben: Backup er maskinens fra 5.27. Den gamle backup.sh må køre videre ved siden af, og maskinens site-backup har også `config/`.
Besluttet af: Team Lead 2026-11-05 (fund fra Sysadmin-Lead 2026-11-05). Ikke en ejerbeslutning.
Udløber: 2027-02-03
