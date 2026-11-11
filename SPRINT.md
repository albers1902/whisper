# SPRINT — whisper.xanouso.com

## Projekt
Hent opkald fra Zendesk Talk (først) og Adversus (bagefter), transcribér dem lokalt på serveren, vis dem i en liste
med afspilning og download af transcripts, med brugerlogin (administrator opretter brugere) og en MCP-server, så
Claude Desktop kan analysere transcripts.

## Infrastruktur
Status: `bash shared/report.sh status` og `bash shared/report.sh check`. Ingen tal i hånden.

## Denne sprint
- H001 er merget (#1, 2452e31).
- F001 (brugere og login, PR #4): begge critics har godkendt v2 (d72ba88). Efter merge skal ejeren gøre tre ting på
  DEV som `whisperdev`:
  1. Skriv `[app] env = dev` i `~/config/config.ini` (PROJEKTVIDEN.md). Ellers svarer F001 404.
  2. Opret den første admin med `cron/opret-admin.php`.
  3. Kør proxy-målingen for F001-S2 (PROJEKTVIDEN.md, DECISIONS #10).
  Bagefter: tester mod DEV.
- LIFECYCLE §12-gaten: serveropsætning af DEV efter merge af H001. STABLE venter (ejeren 2026-11-11).
  - DEV er sat op 2026-11-11 (§3a trin 0–8, S-20261111-1 til -4). Kontrol-pre-release `ctrl-2026.11.11-1` er udgivet,
    og `/health` på DEV svarer `ok` uden redirect, så QA H001 v1 fund 4 er afklaret.
  - Mangler: DRIFT.md og ACCESS.md skal udfyldes med rigtige konti og datoer (DECISIONS #6), og backup-beviset kommer
    efter første nat.
- Ejerskab af logfilerne i `logs/`: ejeren kørte `chown -R whisperdev:whisperdev logs` som root (DECISIONS #7). Til
  sysadmin-siden: `sitectl new` lægger root-ejede Apache- og FPM-logfiler i `logs/`, og det er ikke besluttet i
  kontrakten. Hold øje med, om logrotate laver dem root-ejede igen (`server.sh check dev`).
- Senere H (ikke blokerende): `/health` rate limit/cache (DECISIONS #5); `migrate.php --status` uden lås (QA H001 v2 A).

## Besluttet (ejeren 2026-11-11, skrives i DECISIONS.md i første docs-PR)
- Lokal transcription. Ingen lyd forlader serveren.
- faster-whisper på CPU (`compute_type="int8"`), `language="da"`. Standardmodel er `turbo`, og `large-v3` kan vælges i
  config. Små modeller og `.en`-modeller er fravalgt.
- Alt ligger i projektets egen hjemmemappe (kontrakt §2): Python-venv med faster-whisper, statisk ffmpeg-binær og
  modelcache under `var/`. Serveren må hente pakker og model fra nettet (PyPI, Hugging Face).
- Størrelse, CPU og RAM er ikke et krav nu. Jobbet kører alligevel som ét job ad gangen med `nice`/`ionice` fra cron
  (ikke FPM), så det belaster de andre sites på maskinen mindst muligt.
- Transcription må gerne køre på driftsmaskinen.
- **Kun DEV indtil videre.** STABLE sættes ikke op nu. Der arbejdes og testes på `dev.whisper.xanouso.com`, indtil
  ejeren beslutter andet.

## Næste op (ROADMAP.md)
1. F002 Zendesk Talk: liste over tickets med opkald, afspilning, transcription og download.
2. F003 MCP-server til Claude Desktop (søg i og læs transcripts).
3. F004 Adversus: samme flow.
