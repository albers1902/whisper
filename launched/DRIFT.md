<!-- FILE_VERSION: 5.42 — fra shared/templates/DRIFT.md.template (H001). -->
# Drift — Whisper

Beskriver HVORDAN drift er sat op. BEVISET (at backup, restore og overvågning
faktisk virker) står aldrig her — det kommer fra maskiner: STABLE `/health`
(`backup_last_ok`, `restore_last_ok`, `restore_rto_min`, `last_incident`) og
`cron/monitor.sh` (livstegn `coworker/monitor`). `bash shared/report.sh check` viser, hvad der mangler.

## Miljøer og data
Har miljøet data fra kunder, der ikke har sagt ja til en testversion, eller skriver
det i deres systemer? Ja = produktion: `real_data = true` i config.ini, og det følger
KUN udgivne releases (deploy.sh nægter andet).

Whisper har kun DEV og STABLE (intet TEST) — samme model som chatio. `coworker.ini [health]` har ingen `test`.

| Miljø | Rigtige data? | Følger | Health-URL (også i DEPLOYMENT.md) |
|---|---|---|---|
| DEV | nej | main | https://dev.whisper.xanouso.com/health |
| TEST | — (findes ikke) | — | — |
| STABLE | ja | udgivne releases | https://whisper.xanouso.com/health |

Server, Linux-brugere og database pr. miljø: udfyldes ved serveropsætning (sysadmin, `shared/SERVERKONTRAKT.md`).

### Testkunder med samtykke (påkrævet, når et miljø med "nej" skriver i kunders systemer)
Ingen endnu — projektet har ingen kunder og ingen produktfunktionalitet (H001).

| Virksomhed | Kontakt | Dato | Dokument | Accepteret (fx "skrivning i e-conomic") |
|---|---|---|---|---|
| — | — | — | — | — |

## Backup og restore
- Værktøj: maskinens `site-backup` (sysadmin; `shared/SERVERKONTRAKT.md` §2) — databaser, `public_html`, `config`,
  `var`, krypteret væk fra maskinen med restic. Projektet sætter intet op.
- Backup: hver nat. Restore-prøve: hver database mindst hver 7. dag (målt RTO i `logs/restore_last_ok`).
- Før migrationer: `logs/predeploy-db.sql.gz` (deploy.sh, kun den seneste).
- Maskinens restic-kodeord ligger hos ejeren (uden det kan backuppen ikke læses).

## Overvågning og alarm
- `cron/monitor.sh` hver time fra DEV's hjemmemappe (IKKE STABLE's maskine): /health, STABLE = seneste release, backup ≤ 1 døgn, restore ≤ 35 dage.
  Står DEV og STABLE på samme maskine: overvågning også fra en anden server — udfyldes ved serveropsætning.
- CI: `cron/ci.sh` hvert minut i DEV's hjemmemappe, testdatabase: udfyldes ved serveropsætning (navn `*_ci`, `[ci_database]` i DEV's config.ini).
- Fejl = GitHub-sag med label `incident` + mail til ejeren. `alert_email` i config.ini på alle miljøer.

## Rollback per lag
- Kode: rollback-release (`bash shared/report.sh restore <tag>` giver planen)
- Databaseskema: down-migrationer (`homedir/migrations/down/`); ikke-reversible kræver restore fra backup
- Data: restore fra maskinens backup — spørg sysadmin-subagenten (det kræver root); før en migration: `logs/predeploy-db.sql.gz`
- Config: fra maskinens backup (`config/` er med i site-backup). Nøglefiler i `/etc/<bruger>/keys/`: ikke i backup endnu (`shared/SERVERKONTRAKT.md` §9 punkt 7). En password-manager er ekstra sikkerhed, ikke vejen tilbage
- Feature flags: `stable = off` i en ny release
- Server: nyt site med `sitectl new` (sysadmin, SERVERKONTRAKT), `repo/`-klon, config fra maskinens backup, cron fra denne fil

## Eksterne systemer — kan IKKE rulles tilbage
- Ingen endnu (H001 har ingen integrationer). Tilføjes, når en F-nummer-feature skriver eller sender noget ud af huset.
