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

## #8 — 2026-11-11 — F001: sessioner i var/sessions og miljø fra [app] env
Team Lead: F001 gemmer PHP-sessioner i `~/var/sessions` i stedet for `~/sessions`, som spec'en v1 nævnte.
Begrundelsen er, at `var/` er projektets efter kontrakten §2 og står i `open_basedir`, mens ejerskabet af
`~/sessions` er ukendt. Flagene læser miljøet fra `[app] env` i `config.ini` (PROJEKTVIDEN.md). Det besvarer QA F001
v1 TL-1 og TL-2.

## #9 — 2026-11-11 — F001-S4: ingen HSTS fra appen
F001-S4 · Accepteret risiko: appen sætter ingen HSTS-header (LOW, security-critic F001_v1). Session-cookien er altid
`Secure`, og sitet omdirigerer HTTP til HTTPS (sitectl). HSTS er serverens (`sitectl hsts`, SERVERKONTRAKT §1) og
slås til, før STABLE får rigtige data.
Udløber: 2027-02-09

## #10 — 2026-11-11 — F001-S2: throttle-IP forudsætter REMOTE_ADDR uden proxy
F001-S2 · Accepteret risiko: login-throttlingen pr. IP bygger på, at `REMOTE_ADDR` er klientens adresse (MEDIUM,
security-critic F001_v1 og v2). IPv6 er grupperet pr. /64. Der er ingen dokumenteret proxy eller CDN, og port 80 og
443 er åbne direkte (MASKINER.md, sysadmin 2026-11-11). Det måles på DEV efter merge (PROJEKTVIDEN.md) og skal
bekræftes af sysadmin for STABLE, før `stable = on`.
Udløber: 2027-02-09

## #11 — 2026-11-11 — F001-S5: sessionsfiler fra skraldecookies og flash forbrugt af HEAD
F001-S5 · Accepteret risiko: en vilkårlig `whisper_session`-cookie opretter en sessionsfil, der ryddes af GC, og en
HEAD-request kan forbruge en flash-besked (LOW, security-critic F001_v2). Ingen data eksponeres. Det kan rettes i et
senere ID.
Udløber: 2027-02-09

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
