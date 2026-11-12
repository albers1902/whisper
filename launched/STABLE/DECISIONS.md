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
Sysadmin-siden (2026-11-11): ejerens `chown` står ved magt og overlever logrotate, fordi den globale `create`
kopierer den gamle ejer. Det må ikke gentages på STABLE, hvor det går gennem sysadmin-siden.

## #12 — 2026-11-11 — F002: ingen separat ffmpeg (tilføjelse til #3)
Team Lead: faster-whisper afkoder lyd via PyAV, som har FFmpeg-bibliotekerne indbygget (faster-whisper README, F002
v0). Der installeres derfor ingen statisk ffmpeg-binær. Resten af #3 er uændret.

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

## #13 — 2026-11-11 — F002: ruter uden filendelse
Team Lead (svar på F002 v0 spørgsmål 6, QA F002 v1 F-10): `/opkald/{id}/transcript` og `/opkald/download` med
`Content-Disposition` erstatter spec'ens `transcript.txt` og `download.zip`. Grunden er, at Apaches regel om at nægte
`.zip` slår til, så snart der findes en mappe med samme navn. AK6 og AK8 gælder for de nye ruter.

## #14 — 2026-11-11 — F002-S5: OAuth-scope `read` indtil proben har målt
F002-S5 · Accepteret risiko: Zendesk-klienten bruger som standard det brede `read`, fordi Zendesk ikke har et
voice-scope (LOW, security-critic F002_v1). Write-scopes og tomt scope afvises i koden. OAuth-klienten skal have
"allowed scopes" = `read` i Admin Center. Hvis proben viser, at `tickets:read users:read` er nok, indsnævres scopet.
Udløber: 2027-02-09

## #15 — 2026-11-11 — F002-S6: Python-afhængigheder og model-revision ikke hash-låst
F002-S6 · Accepteret risiko: `faster-whisper==1.1.1` er pinnet, men de transitive afhængigheder og modellens
revision er ikke hash-låst (LOW, security-critic F002_v1). Transcription kører med `HF_HUB_OFFLINE=1`. Hash-låsning
laves før STABLE.
Udløber: 2027-02-09
Opdateret 2026-11-11: H003 har låst alle Python-pakker med hashes (`cron/whisper-requirements.txt`, `--require-hashes`)
og modelrevisionen på commit-sha. Det, der er tilbage, står i #18 og #19.

## #18 — 2026-11-11 — H003-S1: ulåst modelsnapshot ryddes ikke ved afvist revision
H003-S1 · Accepteret risiko: `whisper-setup.sh` henter `main` og afviser bagefter, hvis den ikke er den låste commit.
Den ulåste snapshot og `refs/main` bliver liggende, men markøren skrives ikke, så transcription bruger dem ikke (LOW,
security-critic H003_v1). Oprydning ved afvisning laves i et senere H.
Udløber: 2027-02-09

## #19 — 2026-11-11 — H003-S2: eksisterende venv verificeres kun på versioner
H003-S2 · Accepteret risiko: et eksisterende venv sammenlignes med låsen på versioner, ikke filhashes (LOW,
security-critic H003_v1). Det kræver allerede skriveadgang som sitets bruger. Skal det genopbygges, slettes
`~/var/whisper/venv`, og setup køres igen.
Udløber: 2027-02-09

## #16 — 2026-11-11 — F002: retention og sletning af opkald mangler
F002-S4 · Accepteret risiko: der er endnu ingen slettepolitik for optagelser og transcripts, og DEV har ingen
off-site backup (LOW, impact F002, security-critic F002_v1 og v2 S4). Adgangsloggen `logs/adgang.log` findes. Det skal
løses i et eget ID, før `stable = on` for F002.
Udløber: 2027-02-09

## #17 — 2026-11-11 — F002-V2-FLAGHOOK: testhook i Flags::enabled
F002-V2-FLAGHOOK · Accepteret risiko: `WHISPER_TEST=1` plus `WHISPER_TEST_FLAGS` kan tænde et flag via miljøvariabler
(LOW, security-critic F002_v2). Det kræver allerede kodeeksekvering som sitets bruger, og variablen er ikke sat på
serverne. Hooken begrænses til CLI (`PHP_SAPI === 'cli'`) i et senere ID.
Udløber: 2027-02-09

## #20 — 2026-11-11 — F003: transcripts sendes til Anthropic via Claude Desktop
Ejeren (DECISIONS #1): transcripts må analyseres i Claude Desktop. Med F003 forlader tekst (transcripts,
telefonnumre, agentnavne og metadata) serveren til Anthropic, når en bruger med egen nøgle beder Claude om det. Lyd
forlader stadig aldrig serveren (#2). Anthropic står i `launched/compliance/SUBPROCESSORS.md`. Ejeren udfylder "DPA
indgået" og "overførselsgrundlag", før F003 tændes på TEST eller STABLE.

## #21 — 2026-11-11 — F003: MCP legacy-protokol 2025-11-25 og flagafhængighed
Team Lead (svar på F003 v0, QA F003 v1 TL-1 og TL-2): `/mcp` taler MCP 2025-11-25 (accepterer også 2025-06-18 og
2025-03-26) og svarer 400 på 2026-07-28, så klienter falder tilbage til `initialize`. Klienten er `mcp-remote@0.15.2`
med `--transport http-only` (udgivet 2026-10-20, nyere end rettelsen af CVE-2025-6514 i 0.1.16). `/mcp` kræver F001,
F002 og F003 tændt. Om tilbagefaldet virker, bekræftes med den rigtige Claude Desktop på DEV.

## #22 — 2026-11-11 — F003-S1: indirekte prompt-injection via transcripts
F003-S1 · Accepteret risiko: transcripts er kundetale og returneres uden ramme til Claude (MEDIUM, security-critic
F003_v1, QA F-10). Værktøjerne er read-only. En fast ramme om kundetale og en advarsel på `/konto` laves i det
opfølgende H-nummer.
Udløber: 2027-02-09

## #23 — 2026-11-11 — F003-S2: mcp-remote via npx og nøgle i klartekst hos klienten
F003-S2 · Accepteret risiko: Claude Desktop kører `npx -y mcp-remote@0.15.2`, og nøglen står i klartekst i
`claude_desktop_config.json` på brugerens maskine (LOW, security-critic F003_v1). Versionen er låst og nyere end den
kendte sårbarhed. Nøglen kan tilbagekaldes med ét klik.
Udløber: 2027-02-09

## #24 — 2026-11-11 — F003-S3: nøgler tilbagekaldes ikke ved nulstilling eller skift af adgangskode
F003-S3 · Accepteret risiko: MCP-nøgler overlever en adgangskodenulstilling eller et skift (LOW, security-critic
F003_v1). Deaktivering spærrer dem. Tilbagekald ved nulstilling og skift laves i det opfølgende H-nummer.
Udløber: 2027-02-09

## #25 — 2026-11-11 — F003-S4: ingen begrænsning af uautentificerede forsøg på /mcp, tillid til Host
F003-S4 · Accepteret risiko: 401-svar tælles ikke, og links bygges af `Host` (LOW, security-critic F003_v1). Nøglerne
kan ikke gættes (256 bit). Sysadmin bekræfter, at vhosten ikke har catch-all-alias, før STABLE.
Udløber: 2027-02-09

## #26 — 2026-11-12 — H004-1: afgrænserne om kundetale kan forfalskes
H004-1 · Accepteret risiko: rammen om kundetale (`Tools::FRAME_START`/`FRAME_END`) er en fast tekst, som kundens egen
tale kan indeholde (MEDIUM, security-critic H004_v1, QA H004_v1 fund 1). Rammen er en afbødning af #22, ikke en
teknisk grænse. `/konto` advarer mod at kombinere nøglen med skrivende værktøjer. Neutralisering af afgrænserne i
teksten laves i næste H-nummer.
Udløber: 2027-02-10

## #27 — 2026-11-12 — H004-2: Zendesk-metadata i hovedet af get_transcript er ikke indrammet
H004-2 · Accepteret risiko: agentnavn og andre Zendesk-felter står over rammen uden rensning for linjeskift (LOW,
security-critic H004_v1). Det kræver skriveadgang i Zendesk. Rensning af linjeskift og kontroltegn laves i samme H som
#26.
Udløber: 2027-02-10

## #28 — 2026-11-12 — H004-3: REGEXP_INSTR-dialekt kun testet på MariaDB 10.11
H004-3 · Accepteret risiko: søgeuddragets ordgrænse-regex er kun testet på DEV's MariaDB 10.11 (LOW, security-critic
og QA H004_v1). En dialektforskel giver en SQL-fejl, ikke datatab. Testen H004-AK6 skal køre grøn på databasen for
TEST og STABLE, før de tages i brug.
Udløber: 2027-02-10

## #29 — 2026-11-12 — H005: VAD og beam 1 som standard kan give ufuldstændige transcripts
H005 · Accepteret risiko: med `vad = on` og `beam_size = 1` kan svag eller overlappende tale udelades uden fejl (LOW,
security-critic H005_v1). Det er et bevidst valg for at gøre transcription hurtigere (målt ca. 8 min. pr. opkald før
H005). Ejeren stikprøvekontrollerer opkald efter deploy (PROJEKTVIDEN.md), og rollback sker i config
(`beam_size = 5`, `vad = off`).
Udløber: 2027-02-10

## #30 — 2026-11-12 — H005: advarsler om ugyldig whisper-config vises først ved næste job
H005 · Accepteret risiko: en ugyldig værdi i `[whisper]` giver standarden og én advarsel, som først logges ved næste
opkald i køen (LOW, security-critic H005_v1). Funktionelt sikkert.
Udløber: 2027-02-10

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
