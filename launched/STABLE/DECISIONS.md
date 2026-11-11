# DECISIONS — whisper.xanouso.com

## #1 — 2026-11-11 — Produktets omfang og rækkefølge
Ejeren: Whisper henter opkald via API, transcriberer dem og gør transcripts tilgængelige til visning, afspilning,
download og analyse i Claude Desktop via en MCP-server. Rækkefølgen er: brugerlogin, hvor administratoren opretter
andre brugere, derefter Zendesk Talk, så MCP og til sidst Adversus.

## #2 — 2026-11-11 — Lokal transcription
Ejeren: opkald transcriberes lokalt på serveren, og ingen lyd sendes til en ekstern tjeneste. Motoren er
faster-whisper på CPU (`compute_type="int8"`) med `language="da"`. Standardmodellen er `turbo`, og `large-v3` kan
vælges i config. Små modeller og `.en`-modeller er fravalgt, fordi de ikke giver brugbar dansk.

## #3 — 2026-11-11 — Transcriptionsværktøjer i projektets hjemmemappe
Ejeren: Python-venv med faster-whisper, statisk ffmpeg og modelcache ligger i projektets egen hjemmemappe
(SERVERKONTRAKT §2). Serveren må hente pakker og model fra nettet. Størrelse, CPU og RAM er ikke et krav nu, og
transcription må køre på driftsmaskinen. Jobbet kører som ét job ad gangen fra cron med `nice`/`ionice`.

## #4 — 2026-11-11 — Kun DEV indtil videre
Ejeren: projektet kører kun på DEV (`dev.whisper.xanouso.com`), indtil ejeren beslutter andet. STABLE sættes ikke op
nu. `coworker.ini` har health-URL'en for stable, så den er klar, når det sker.

## #7 — 2026-11-11 — Logfilerne i logs/ ejes af whisperdev på DEV
Ejeren kørte `chown -R whisperdev:whisperdev logs` som root i `/home/whisperdev` på prøvebanen. Det skete, fordi
`sitectl new` havde lagt `error.log`, `access.log` og `fpm-slow.log` som root-ejede filerne, og serverkontrakten ikke
har besluttet, hvem der skal eje dem (sysadmin-subagenten: "ikke besluttet"). Hvis logrotate opretter filerne som
root igen, melder `server.sh check dev` det. Sagen er sendt videre til sysadmin-siden (SPRINT.md).

## #5 — 2026-11-11 — H001: /health uden rate limit eller cache
H001 · Accepteret risiko: `/health` er offentlig og laver en DB-skriveprøve ved hvert kald uden rate limit eller cache.
Samtidige kald kan give falsk `degraded` (LOW, security-critic H001_v1 og v2). Kun driftsforstyrrelse, og der er ingen
persondata. Rettes med rate limit, cache eller unik prøvenøgle i et senere ID, senest før der kommer rigtige data på
STABLE.
Udløber: 2027-02-09

## #6 — 2026-11-11 — H001: ACCESS.md med pladsholdere markeret gennemgået
H001 · Accepteret risiko: rækker i `launched/compliance/ACCESS.md` står som "Gennemgået", selv om konto og datoer er
pladsholdere (LOW, security-critic H001_v1). De udfyldes med rigtige konti og datoer i docs-PR'en efter
serveropsætningen af DEV.
Udløber: 2027-02-09
