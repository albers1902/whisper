# SPRINT — whisper.xanouso.com

## Projekt
Hent opkald fra Zendesk Talk (først) og Adversus (bagefter), transcribér dem lokalt på serveren, vis dem i en liste
med afspilning og download af transcripts, med brugerlogin (administrator opretter brugere) og en MCP-server, så
Claude Desktop kan analysere transcripts.

## Infrastruktur
Status: `bash shared/report.sh status` og `bash shared/report.sh check`. Ingen tal i hånden.

## Denne sprint
- H001 er merget (#1, 2452e31).
- F001 (brugere og login) er merget (#4, 67e1e8b) og kører på DEV. Ejeren er logget ind som admin, `env = dev` er sat,
  og proxy-målingen er OK (PROJEKTVIDEN.md).
- F002 (Zendesk, PR #6): begge critics har godkendt v2 (9003841). QA N-1 er afklaret: 0003 er ikke kørt i nogen
  miljødatabase, fordi DEV kun udruller main, og CI-basen genskabes. Efter merge skal ejeren lave sine trin på DEV
  (config, probe, setup og cron, se PROJEKTVIDEN.md).
- LIFECYCLE §12-gaten: serveropsætning af DEV efter merge af H001. STABLE venter (ejeren 2026-11-11).
  - DEV er sat op 2026-11-11 (§3a trin 0–8, S-20261111-1 til -4). Kontrol-pre-release `ctrl-2026.11.11-1` er udgivet,
    og `/health` på DEV svarer `ok` uden redirect, så QA H001 v1 fund 4 er afklaret.
  - `SERVER: KLAR (dev)` 2026-11-11 efter ejerens chown af `logs/` og `mkdir ~/var`.
  - Mangler: DRIFT.md og ACCESS.md skal udfyldes med rigtige konti og datoer (DECISIONS #6), og backup-beviset kommer
    efter første nat. Står `!` om backup der stadig 2026-11-12, skal sysadmin-subagenten spørges.
- Ejerskab af logfilerne i `logs/` er afklaret (sysadmin-siden 2026-11-11). Ejerens `chown` på DEV står ved magt og
  overlever logrotate, fordi den globale `create` kopierer den gamle ejer. **Det må ikke gentages på STABLE**, hvor
  det går gennem sysadmin-siden (DECISIONS #7).
- **H013 må ikke på STABLE uden F007** (QA H013 v1 M3). H013 gemmer alle Adversus-CDR'er, og listens indekser
  kommer med F007's migration 0010. Retention for telefonnumre (DECISIONS #79) skal afklares med ejeren før STABLE.
- Senere H (ikke blokerende):
  - H013 N1 og S5: gem opkald med ukendt retning, så kontrollerne ikke genhenter (QA H013 v2 N1, DECISIONS #82)
  - `/health` rate limit/cache (DECISIONS #5)
  - `migrate.php --status` uden lås (QA H001 v2 A)
  - fejl i agent-opslag fjerner opkaldet, og poison-opkald logges ofte (QA F002 v2 N-2 og N-3)
  - flag-hook kun i CLI (DECISIONS #17)
  - retention og sletning af opkald før STABLE (DECISIONS #16)
- **2027-03-29 (dagen efter sommertidens start):** ejeren kører `php ~/cron/adversus-diagnose.php` og sender
  `KONKLUSION recordings-filter`. Viser den forskydning, sættes `[adversus] filter_tz = +01:00` (DECISIONS #58).
  Inden 2027-02-10 forsøges en måling mod en sommerdato.

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
1. F003 MCP-server til Claude Desktop (søg i og læs transcripts).
2. F004 Adversus: samme flow.
