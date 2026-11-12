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

## #31 — 2026-11-12 — F004: kun opkald på leads med succes transcriberes
Ejeren: Adversus har ca. 1,44 mio. opkald og ca. 278.000 optagelser (F004a-probe 2026-11-12). Der transcriberes ikke
alle automatisk.
- Alle Adversus-opkald med optagelse må vises i Whisper (metadata hentes).
- Kun opkald, der hører til et lead med en succesfuld registrering (status "succes" i Adversus), transcriberes
  automatisk, og lyden hentes kun for dem.
- Opkaldslisten viser som standard kun opkald på succes-leads. Filteret kan skiftes til "Alle opkald".
- Andre opkald kan transcriberes manuelt med en knap "Transcribér dette opkald", som henter lyden og sætter
  opkaldet i kø (ejeren 2026-11-12).
- Hentningen starter fra i dag (2026-11-12) og er derefter inkrementel. Der hentes ingen historik bagud nu
  (ejeren 2026-11-12).
Den præcise status-værdi, filtrene i API'et og vejen fra lead til optagelse afklares med probe v2 (F004a v2), før F004
specificeres.

## #32 — 2026-11-12 — Whisper må køre 2 opkald samtidig (ændrer #3)
Ejeren: whisper må køre 2 opkald samtidig i stedet for ét ad gangen (#3). Måling på DEV 2026-11-12: AMD EPYC, 6
vCPU med AVX2 og FMA, men uden AVX-512. Whisper-processen brugte ca. 2,3 kerner med 5 tråde, og maskinen var 39 %
idle. RTF var 1,47 på et opkald på 7:27, fordi tekstgenereringen med beam 1 er sekventiel. Det bygges som 2 processer
med 3 tråde hver, stadig under `nice 19` og `ionice -c3`, så de andre sites får forrang. Det skal kunne slås tilbage
til 1 i config.

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

## #33 — 2026-11-12 — H006: kørselsrammen på 50 min er ikke en garanti
H006 · Accepteret risiko: Resttiden begrænser kun summen for en batch. Batchens gulv (op til 2400 s) og et langt opkalds
egen grænse (max(2 t, 4 × varighed)) kan overskride kørslens ramme på 50 min. Det er samme grænse som før H006, og låsen
forhindrer parallelle motorer. Konsekvensen er kun, at de næste cron-kørsler udskydes (LOW, security-critic H006_v2,
fund 1, rest efter H006-LOW-3 fra v1). H006-LOW-1 og LOW-2 fra v1 er lukket i v2.
Udløber: 2027-02-10

## #34 — 2026-11-12 — H006: undtagelse efter claim frigives uden forbrugt forsøg
H006 · Accepteret risiko: En deterministisk undtagelse efter claim og uden for motoren frigiver opkaldene med refunderet
forsøg. Den logges kun som fast tekst og kan gentages hvert minut, indtil undtagelsen forsvinder (LOW, security-critic
H006_v2, fund 2). Sandsynligheden er lav. Rettes i H007, som alligevel ændrer `runBatch`: undtagelsens klasse og linje
logges i `sidste-fejl.log`, og løkken stopper til næste minut.
Udløber: 2027-02-10

## #35 — 2026-11-12 — H007: låsefilens indhold skrives ikke atomisk
H007 · Accepteret risiko: `Lock::write()` trunkerer før skrivning, så en samtidig læsning kan se en tom fil og ikke skåne
et opkald (LOW, security-critic H007_v1, L1). Vinduet er mikrosekunder og rammer kun, hvor opkaldene er friske, så
stale-reglen ikke kan ramme dem. Rettes ved at skrive før trunkering.
Udløber: 2027-02-10

## #36 — 2026-11-12 — H007: opkald med deterministisk undtagelse blokerer køen
H007 · Accepteret risiko: En undtagelse efter claim refunderer forsøget, og `runBatch` stopper kørslen, så et
poison-pill-opkald forrest i køen gentages hvert minut uden at nå `MAX_ATTEMPTS` (LOW, security-critic H007_v1, L2).
Detaljen står i `sidste-fejl[-n].log` (0600). #34 er lukket af H007 for logning og løkkestop. Denne post er resten.
Rettes ved at lade det første opkald i batchen bruge forsøget ved en undtagelse uden for motoren, senest før STABLE.
Udløber: 2027-02-10

## #37 — 2026-11-12 — F004: down 0005 er destruktiv og efterlader lydfiler
F004 · Accepteret risiko: down-migrationen 0005 sletter alle Adversus-opkald og transcripts, men efterlader lydfilerne
uden række (LOW, security-critic F004_v1–v3, S3). Rollback er flaget `F004_adversus`, ikke down. Efter en bevidst down
slettes lydfilerne manuelt (changes/F004.md).
Udløber: 2027-02-10

## #38 — 2026-11-12 — F004: kundenumre fra Adversus gemmes uden retention
F004 · Accepteret risiko: telefonnummeret gemmes renset for alle hentede Adversus-opkald med optagelse, som for Zendesk
(spec F004: "gemmes, hvis Zendesk gemmer det"). Der er ingen sletning eller retention endnu (LOW, security-critic
F004_v1–v3, S4). Team Leads afgørelse. Ejeren er informeret 2026-11-12 og kan bede om, at det kun gemmes for succes og
valgte opkald (én linje i `Sync::saveCall`). Retention afklares senest før STABLE (jf. #16).
Udløber: 2027-02-10

## #39 — 2026-11-12 — F004: lydværten valideres på navn, ikke IP
F004 · Accepteret risiko: redirects følges kun til værter i `[adversus] recording_hosts`, og værten tjekkes på navn,
ikke opløst IP (LOW, security-critic F004, S6). `recording_hosts` må kun indeholde den konkrete bucket-vært
(`s3.gra.io.cloud.ovh.net`).
Udløber: 2027-02-10

## #40 — 2026-11-12 — F004: transcribe tager Adversus-opkald i kø, når flaget er slukket
F004 · Accepteret risiko: slås `F004_adversus` fra, transcriberer `cron/transcribe.php` stadig Adversus-opkald, der
allerede er i kø med lyd. Intet vises, og intet lækker (LOW, security-critic F004, S7).
Udløber: 2027-02-10

## #41 — 2026-11-12 — F004: loftet på 50 manuelt valgte er ikke atomisk
F004 · Accepteret risiko: loftet tælles og opdateres i to forespørgsler, så samtidige klik kan gå få opkald over 50
(LOW, security-critic F004_v2, N3).
Udløber: 2027-02-10

## #42 — 2026-11-12 — F004: Adversus-agentnavne i strukturerede MCP-felter
F004 · Accepteret risiko: agentnavne fra Adversus er markeret som ikke-betroet tekst i `get_transcript`, men ikke i de
strukturerede felter i `search_calls`, `list_recent_calls` og `call_stats`. Det er samme tillidsniveau som
Zendesk-agenter (LOW, security-critic F004_v2, N4).
Udløber: 2027-02-10

## #43 — 2026-11-12 — F004: et enkelt download kan holde sync-låsen ca. 38 min
F004 · Accepteret risiko: deadline på 8 min tjekkes mellem downloads, og curl-timeout er 1800 s. En langsom lydvært kan
derfor holde låsen i op til ca. 38 min, og sync-kørsler springes over imens (LOW, security-critic F004_v2, N5).
Udløber: 2027-02-10

## #44 — 2026-11-12 — F004: genforsøg på fejlet lyd tæller ikke i loftet på 50
F004 · Accepteret risiko: genforsøg på et opkald med fejlet lyd tjekker ikke loftet på 50, men er begrænset af 1 times
afkøling pr. opkald og 25 downloads pr. kørsel (LOW, security-critic F004_v3, N6).
Udløber: 2027-02-10

## #45 — 2026-11-12 — F004: advarslen om forkastede optagelser afkortes fra enden
F004 · Accepteret risiko: når admin-advarslen om forkastede Adversus-optagelser overstiger 250 tegn, mangler de nyeste
id'er i advarslen. De står i `logs/adversus-sync.log` (LOW, security-critic F004_v3, N7; samme som qa-critic
F004_v3, N-6).
Udløber: 2027-02-10

## #46 — 2026-11-12 — F004: ventelistens gamle grupper kan sulte, og afkølingen forklares ikke
F004 · Accepteret risiko (LOW, qa-critic F004_v3, N-5 og N-7):
- Ved vedvarende mange umatchede optagelser tages nye rækker (færrest forsøg) først. Ældre rækker når så ikke 6 forsøg,
  og ventelisten fyldes til 2000, hvorefter nye forkastes. Det er altid synligt i admin-advarslen og loggen.
- Under afkølingen på 1 time får brugeren først efter klik at vide, at lyden blev prøvet for nylig.
Begge rettes i et senere H-nummer, hvis de ses på DEV.
Udløber: 2027-02-10

## #47 — 2026-11-12 — F006: Whisper tilføjes i Claude som custom connector via OAuth
Ejeren (2026-11-12): MCP skal kunne tilføjes i Claude som custom connector med kun URL'en. Verifikationen sker ved
login i browseren, uden nøgle at kopiere, uden Node.js og uden genstart. Det bygges som OAuth 2.1 med PKCE og dynamisk
klientregistrering på `/mcp` efter MCP-specifikationen (F006), bag flaget `F006_oauth`. F003-nøglerne med `mcp-remote`
bliver som en avanceret mulighed. Ingen ny modtager: transcripts går til Anthropic som efter #20.

## #48 — 2026-11-12 — F006: kun den rigtige connector, ingen mcp-remote-nøgler (ændrer #47)
Ejeren (2026-11-12): "jeg vil kun bruge den rigtige mcp". Med `F006_oauth` tændt viser Konto kun "Forbind Claude"
(connector-URL og Forbundne apps). `/mcp` accepterer kun OAuth-tokens, og F003's `wsp_`-nøgler og mcp-remote-
vejledningen er slået fra. F003-koden bliver liggende, så rollback med flaget fra stadig virker.

## #49 — 2026-11-12 — H008: hængende opkald bag andre refunderes uden forsøg
H008 · Accepteret risiko: et opkald, der hænger som nr. 2 eller senere i en batch, ligner "kørslens tid opbrugt". Det
sættes derfor tilbage uden forsøg og kan spilde en kørsel (op til 50 min) pr. ældre opkald foran det i køen. Det er
afgrænset: som første fil i en batch bruger det altid et forsøg, og efter 3 forsøg opgives det (MEDIUM, security-critic
H008_v1). Kan senere begrænses med en tæller for refunderinger pr. opkald.
Udløber: 2027-02-10

## #50 — 2026-11-12 — H008: små unøjagtigheder i resttids-klassificeringen
H008 · Accepteret risiko (LOW, security-critic H008_v1):
- Køretiden for det igangværende opkald måles efter `finish()` og undervurderes derfor en smule.
- `release()` sætter et tidligere fejlet opkald til ventende, så det mister sin backoff-plads.
- Standard-`rtf_estimate` 1,5 ligger under den målte spidsværdi 2,7. Batches kan stadig løbe tør for tid, men uden at
  opkald mister forsøg. Værdien justeres i config efter DEV-loggen.
Udløber: 2027-02-10

## #51 — 2026-11-12 — H009: diagnosens output kan i sjældne tilfælde indeholde fri tekst
H009 · Accepteret risiko (LOW, security-critic H009_v1, S1–S4):
- S1: `Diagnose::raw()` slipper strenge med kun cifre, +, - og mellemrum igennem, fx et nummer i et felt, der burde være
  et tal.
- S2: `cron/adversus-diagnose.php` fanger ikke Throwable, så en DB- eller programfejl giver PHP's egen fejludskrift.
- S3: den rensede fejltekst fra Adversus (op til 200 tegn) kan indeholde navne eller korte numre.
- S4: der er ingen meddelelse ved `logs/deploying` og ingen kvote på tværs af kørsler.
S1, S2 og S4 rettes i H009 PR 2. Ejeren ser linjerne med "afvist (" og "✘" igennem, før outputtet sendes.
Udløber: 2027-02-10

## #52 — 2026-11-12 — H009-S2: diagnosen fanger ikke Throwable
H009-S2 · Accepteret risiko (LOW, security-critic H009_v1): `cron/adversus-diagnose.php` fanger ikke Throwable. En DB-
eller programfejl giver derfor PHP's egen fejludskrift. Rettes i H009 PR 2. (#51 dækker S1. Fundene står her i hvert
sit afsnit, fordi CI kræver ét afsnit pr. fund.)
Udløber: 2027-02-10

## #53 — 2026-11-12 — H009-S3: renset fejltekst fra Adversus kan indeholde fri tekst
H009-S3 · Accepteret risiko (LOW, security-critic H009_v1): den rensede fejltekst fra Adversus (op til 200 tegn) kan
indeholde navne eller korte numre. Ejeren gennemser linjerne med "afvist (" og "✘", før outputtet sendes.
Udløber: 2027-02-10

## #54 — 2026-11-12 — H009-S4: ingen meddelelse ved deploying og ingen kvote på tværs af kørsler
H009-S4 · Accepteret risiko (LOW, security-critic H009_v1): diagnosen har ingen lås eller meddelelse ved
`logs/deploying` og ingen kvote på tværs af kørsler. Rettes i H009 PR 2 (meddelelse ved deploying).
Udløber: 2027-02-10
