# F005 — Whisper UI, hele appen, design v1
# Status: READY_FOR_IMPLEMENTATION

Kilder: `homedir/src/View.php`, `CallView.php`, `CallPages.php`, `App.php`, `Calls.php`, `public_html/assets/app.css`,
`product-ai/F002_v1.md` og `origin/feature/F003-mcp:product-ai/F003_v1.md`. `DESIGN_LOG.md` og `PRD.md` findes ikke,
så alle mønstre er nye (afsnit 9). Visuelle tokens og komponenter: `graphic-design/F005_visual_v1.md` (gælder ved
konflikt om farver og mål). Visuel reference: Claude Design-lærredet "Whisper UI 2026"
(https://claude.ai/artifact/RPsUbpGfccGjjkYtWo9cbJ).

## 0. Rammer (bindende for engineeren)
- **CSP:** `default-src 'self'` og `style-src 'self'`.
  - Ingen `style="…"`-attributter, ingen `<style>`, ingen inline `<script>` og ingen `onclick`.
  - JS kun som `/assets/app.js`, indlæst med `<script src="/assets/app.js" defer></script>` i `<head>` i `View::layout`. Det er tilladt, fordi `script-src` falder tilbage til `'self'`.
  - JS må gerne sætte `el.style` og klasser via CSSOM.
- **Progressive enhancement:** alt virker uden JS. JS-knapper renderes med `hidden`, og `app.js` fjerner `hidden`. Tidsstempler renderes som `disabled` knapper, og `app.js` aktiverer dem. Uden JS er der aldrig døde knapper.
- **Filer:** én CSS-fil (`app.css`) og én JS-fil (`app.js`, mål under 150 linjer, ingen afhængigheder).
- **Afspiller:** `<audio controls preload="metadata">` bevares.
- **Sprog:** al tekst er dansk. Fagordene "transcript" og "transcription" beholdes.

## 1. De vigtigste UX-problemer i dag, side for side

### Fælles ramme (header og layout)
1. Navigationen viser ikke, hvilken side man er på (ingen `aria-current`, ingen visuel markering).
2. "Log ud" står midt i menuen og brugerens navn står efter den. Konto, navn og Log ud hører sammen til højre.
3. Der er intet skip-link ("Spring til indhold").
4. `main` er højst 960px bred. Det er for smalt til en tabel med 7 kolonner og umuligt til afspiller og transcript side om side.
5. Der er ingen synlig fokusstil, og knapper, link-knapper og pager har små klikflader.
6. Kontrast: `--muted` `#6b7280` på `--bg` giver ca. 4,49:1 (fejler 1.4.3), og feltkant `#dfe3ea` på hvid ca. 1,3:1 (fejler 1.4.11).

### Opkaldslisten (`/`)
1. Rækkefølgen er forkert: systembeskeder, statuslinje og hjælpetekst kommer før søgning og liste.
2. Søgning er ét felt blandt fem, selvom fritekst er den vigtigste handling. Hjælpeteksten er lang og står altid fremme.
3. Kun datoen er et link. Rækken er ikke klikbar, og der er ingen hover på rækken.
4. Søgeresultater viser ikke, hvorfor de matcher (intet uddrag, ingen fremhævning).
5. Badges: "Lyd mangler" vises for lyd, der blot ikke er hentet endnu, og lyder som en fejl. Den har samme farve som "Venter". Kolonnen hedder "Transcription", men badget viser også lydstatus.
6. "Download alle (.zip)" står ved H1 og er tvetydig: det er de færdige i filtret, højst 2000.
7. Ingen aktive filtre er synlige som chips, og man kan ikke fjerne ét filter ad gangen.
8. Systembeskeder til alle brugere er tekniske (config.ini, whisper-setup.sh). Det er en sag for administratorer.
9. Statuslinjen viser kun absolut tid, og man kan ikke se, om hentningen er sund.
10. Antallet står løsrevet i stedet for "Viser 1–50 af 123". Pageren er ikke en `nav`.
11. Varighed er ikke højrestillet eller i tabulære tal.
12. Ticket-linket åbner en ny fane uden at sige det.

### Opkaldsdetalje (`/opkald/{id}`)
1. "← Alle opkald" mister filtre, søgning og side. Det er det største irritationsmoment i hverdagen.
2. Afspilleren ruller væk, når man læser et langt transcript.
3. Tidsstempler kan ikke klikkes, og søgeordet er ikke fremhævet.
4. H1 er svag ("Opkald 11.11.2026 14:32" siger ikke retning, kunde eller agent).
5. Metadata er en lang `dl`, og transcription-status står som ren tekst i stedet for badge.
6. Tilstande uden lyd eller med fejl er uklare: rå tekniske fejltekster vises direkte, og transcript-delen siger "Venter på transcription", selvom intet kan ske før lyden er der.
7. Transcriptet er en række `<p>` med `[mm:ss]` i kantede parenteser og lange linjer.
8. Der er ingen "Kopiér tekst" til at indsætte i en Zendesk-ticket.

### Login (`/login`)
Fungerer. Der mangler en kort forklaring af produktet og en hjælpelinje: "Glemt adgangskode? Kontakt en administrator."

### Konto (`/konto`)
Kortet er 480px og for smalt til F003's nøgleliste og JSON-konfiguration. Navn og e-mail står som løs tekst. Siden har ingen sektioner.

### Brugere
Den separate "Rediger"-kolonne er støj (navnet bør være linket). Status er ren tekst, og deaktiverede rækker skiller sig kun svagt ud. Siden med engangskoden har ingen "Kopiér"-knap.

### Fejlsider
"Til forsiden" bør hedde "Til opkald", når man er logget ind.

## 2. Informationsarkitektur og navigation
```
Whisper (logo → /)
├── Opkald            /                 (alle roller)
│   └── Opkald        /opkald/{id}?<listens filtre>
├── Brugere           /brugere          (kun admin)
│   ├── Opret bruger  /brugere/ny
│   └── Bruger        /brugere/{id}  (+ /nulstil, /deaktiver)
└── [brugermenu højre] Navn · Konto · Log ud
    └── Konto         /konto  (Adgangskode | Claude Desktop (MCP) — F003)
```
- **Header:** 56px. Skip-link "Spring til indhold" (skjult indtil fokus). Venstre: brand og `nav aria-label="Hovedmenu"` (Opkald, Brugere). Højre: `div.user` med navn (muted), Konto og Log ud-formularen.
- **Aktivt punkt:** `aria-current="page"` og visuel markering. Detaljesider markerer forælderen (`/opkald/*` → Opkald, `/brugere/*` → Brugere). `View::layout` får parameteren `string $section` (`opkald|brugere|konto|''`).
- **Under 640px** brydes headeren i to linjer. Ingen hamburger.
- **Indholdsbredder:** liste og detalje `main.wide` max 1280px; konto og brugerredigering `.card.medium` max 760px; login, opret bruger, bekræft og fejl `.card.narrow` max 480px.
- **Bevar konteksten:** listens query (`fra, til, retning, agent, q, side`) føjes til alle links til detaljesiden. Detaljesiden læser dem med `Calls::filter()` og bygger tilbage-linket `/?<query>`. Ingen session, ingen JS.

## 3. Side for side

### 3.1 Visuelt grundlag
Tokens, knapper, felter og fokus følger `graphic-design/F005_visual_v1.md`. Desuden:
- `button.link` og pager-links får padding, så klikfladen er mindst 24×24 (WCAG 2.5.8).
- Tabeller bruger `tabular-nums`, og varighed er højrestillet.
- Respektér `prefers-reduced-motion: reduce`.

### 3.2 Statusbadges (ét system i liste og detalje)
Teksten bærer altid betydningen. Hvert badge har en prik før teksten.

| Datatilstand | Tekst | Stil |
|---|---|---|
| `audio_status = venter` | Venter på lyd | neutral |
| `audio_status = fejlet`, eller lyd hentet men filen mangler (kun detalje) | Lyd mangler | fejl |
| lyd hentet, `ventende` | Venter | neutral |
| lyd hentet, `transcriberer` | Transcriberer | info, prikken pulserer (ikke ved reduced-motion) |
| lyd hentet, `faerdig` | Færdig | ok |
| lyd hentet, `fejlet` | Fejlet | fejl |
| ukendt værdi | Ukendt | neutral |

I listen afgøres badget som i dag i `CallView::badge` (lyd først). `Calls::statusName` får fallback "Ukendt". Klassenavnene følger det visuelle brief (`s-…`); kortlæg dem til tabellen her.

### 3.3 Opkaldslisten `/`
```
Opkald                                        ● Hentet fra Zendesk for 4 min. siden
                                                3 venter på transcription
┌ Søg i transcripts ─────────────────────────────────────────────────────────┐
│ [fx faktura flytning___________________________________]  [Søg]            │
│ Alle ord skal indgå. Ord på mindst 3 bogstaver giver det bedste resultat.  │
│ Fra [..]  Til [..]  Retning [Begge ▾]  Agent [Alle agenter ▾]              │
│ Hurtigt: I dag · Seneste 7 dage · Denne måned                Nulstil filtre│
└────────────────────────────────────────────────────────────────────────────┘
Aktive filtre:  [Søgning: faktura ×] [Agent: … ×] [Fra 01.11.2026 ×]
Viser 1–50 af 123 opkald                 [⤓ Download færdige transcripts (.zip)]
Tabel: Dato og tid · Retning · Kunde · Agent · Varighed · Ticket · Status
(ved søgning: uddrag med <mark> under hver række)
                      ← Forrige   Side 2 af 3   Næste →
```
**Systemstatus (øverst til højre):**
- Grøn prik og "Hentet fra Zendesk for N min. siden", når `last_ok_at` er under 30 min. gammel; gul "Sidst hentet fra Zendesk for 2 t. siden", når ældre; rød "Hentningen fra Zendesk er stoppet", når `last_error` er sat og ikke er rate limit.
- Tiden i `<time datetime="ISO" title="11.11.2026 14:30">`, beregnet på serveren.
- Linje 2 kun når tallet er over 0: "N venter på transcription · N venter på lyd". Prikken er `aria-hidden`.

**Systembeskeder, rollestyret:**

| Situation | Admin ser | Bruger ser |
|---|---|---|
| Zendesk ikke sat op | info: "Zendesk er ikke sat op. Opkald hentes, når `[zendesk]` er udfyldt i config.ini." | info: "Opkald hentes ikke fra Zendesk endnu. Kontakt en administrator, hvis du savner opkald." |
| config ugyldig | fejl: "Zendesk-opsætningen i config.ini er ugyldig." | samme tekst som rækken over |
| motor ikke klar | info: "Transcription er ikke sat op endnu (kør cron/whisper-setup.sh)." | info: "Nye opkald bliver ikke transcriberet lige nu. Lyden kan stadig afspilles." |
| sync-fejl | fejl: "Seneste hentning fra Zendesk stoppede." + `<details><summary>Teknisk besked</summary>…</details>` | kun den røde prik i statuslinjen |
| rate limit | intet banner, kun statuslinjen ("Zendesk bad os vente. Hentningen fortsætter ved næste kørsel.") | samme |

**Filtre og søgning:**
- `form.filters` med GET til `/` i et kort. Søgefelt `type="search"`, `name="q"`, label "Søg i transcripts", placeholder "fx faktura flytning", hjælpetekst via `aria-describedby`.
- "Søg" er formularens eneste submit (afløser "Filtrér").
- Hurtigvalg "I dag", "Seneste 7 dage" og "Denne måned" som links, der bevarer de andre filtre. Aktivt hurtigvalg har `aria-current="true"`. Datoer i Europe/Copenhagen.
- "Nulstil filtre" vises kun, når et filter er aktivt.
- Aktive filtre som chips: `ul aria-label="Aktive filtre"`, hver chip et link til samme query uden det filter. Synlig tekst "Søgning: faktura ×", skjult "Fjern filter: Søgning faktura".
- Til < Fra: tomt resultat som i dag, plus info "Til-datoen er før fra-datoen."

**Tabel:**
1. Dato og tid: `dd.mm.åååå` og `tt:mm` på to linjer, tid muted.
2. Retning: "↓ Ind"/"↑ Ud". Pilen `aria-hidden`; fuld tekst i `title` og som skjult tekst.
3. Kunde (telefonnummer).
4. Agent.
5. Varighed højrestillet `m:ss`.
6. Ticket "#48213 ↗" med skjult "(åbner Zendesk i en ny fane)"; "—" uden ticket.
7. Status: badge.
- Hele rækken klikbar uden JS (stretched link `.row-link::after { inset: 0 }`, `tr { position: relative }`; ticket-linket `position: relative; z-index: 1`). Hover-baggrund og `tr:focus-within`-outline.
- `thead th { position: sticky; top: 0 }`.
- Søgeuddrag (bør): med `q` sat en ekstra linje `td colspan=7 .snippet` (muted, maks 2 linjer), ca. 160 tegn omkring første træf med `<mark>` (algoritmen i 3.4). Kræver én JOIN på transcript-teksten for de 50 rækker.
- "Viser 1–50 af 123 opkald"; nul rækker giver tomtilstanden.
- Pager `<nav aria-label="Sider">` med "← Forrige", "Side 2 af 3", "Næste →" som sekundære knapper.
- Zip: sekundær knap til højre for antallet, "Download færdige transcripts (.zip)", hjælpetekst "Alle færdige transcripts i filtret, højst 2000." Ved total > 2000: "Zip-filen får de 2000 nyeste færdige." Skjules ved total = 0.

**Tomme tilstande:**
- Ingen opkald: H2 "Ingen opkald endnu", tekst "Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter."
- Filter uden resultater: H2 "Ingen opkald matcher", tekst "Prøv en længere periode, færre søgeord eller en anden agent." og knap "Nulstil filtre".
- Kun korte søgeord: "Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf."

### 3.4 Opkaldsdetalje `/opkald/{id}`
**Layout ≥ 1100px:** `grid-template-columns: minmax(320px, 400px) 1fr; gap: 24px`. Venstre kolonne (afspiller + `dl.meta`) er `position: sticky; top: 16px; align-self: start`.

**Hoved:** H1 "Indgående opkald · 11.11.2026 kl. 14:32" (samme tekst i `<title>`), undertitel med kunde, agent, varighed og ticket (muted), statusbadge til højre.

**Transcript-segmenter:** `<ol class="transcript">`, én `<li>` pr. segment, grid `4.5rem 1fr`:
```html
<li id="t-<sekund>"><button type="button" class="ts" data-start="7.40" disabled
     aria-label="Afspil fra 00:07">00:07</button><p>Ja hej, jeg ringer om min <mark>faktura</mark> …</p></li>
```
- `.ts:disabled` ser ud som muted tekst. `app.js` fjerner `disabled`, men kun hvis `#afspiller` findes og ikke har fejl. Uden afspillelig lyd renderer serveren `<span class="ts">`.
- Tekst `max-width: 72ch`, `line-height: 1.65`, 6px mellem segmenter. Uden segmenter vises den samlede tekst i afsnit.

**Fremhævning af søgeord (server-side, også i listens uddrag):**
1. Søgeord som i `Calls::where`: ord via `/[\p{L}\p{N}]+/u`; bruges ord på mindst 3 tegn, bruges kun dem.
2. Hvert segments RÅ tekst splittes med `preg_split('/([\p{L}\p{N}]+)/u', …, PREG_SPLIT_DELIM_CAPTURE)`.
3. Et ord-token, hvis `mb_strtolower` begynder med et søgeord, bliver `<mark>` + `e(token)` + `</mark>`; alle andre `e(token)`.
4. Søg aldrig i allerede escaped HTML.
5. Første `<mark>` får `id="fund-1"`. Over transcriptet: "N fund for «q»" og linket "Gå til første fund" (`#fund-1`).
6. Ingen træf: "Ingen fund for «q» i dette transcript."

**Handlinger:** "Kopiér tekst" (sekundær, `hidden` indtil JS; kopierer tekst med tidsstempler som .txt, uden hoved-blok) og "Download transcript (.txt)" ("Download .txt" under 480px).

**Tilstande:**

| Lyd | Transcript | Venstre (lyd) | Højre (transcript) |
|---|---|---|---|
| hentet, fil findes | færdig | afspiller | segmenter med klikbare stempler |
| hentet, fil findes | ventende | afspiller | info: "Transcriptet er på vej. Opkald transcriberes ét ad gangen. Genindlæs siden om lidt." |
| hentet, fil findes | transcriberer | afspiller | info: "Transcriptet laves lige nu. Genindlæs siden om et par minutter." |
| hentet, fil findes | fejlet | afspiller | fejl: "Transcriptet kunne ikke laves." + `<details>` med `transcript_error`. Admin ser også: "En administrator kan sende opkaldet tilbage i køen." |
| hentet, fil mangler | færdig | fejl: "Lydfilen mangler på serveren. Kontakt en administrator." | segmenter, tidsstempler som tekst |
| venter | (alle) | info: "Lyden er ikke hentet fra Zendesk endnu. Det sker normalt inden for 10 minutter." | neutral: "Transcriptet laves, når lyden er hentet." |
| fejlet | (alle) | fejl: "Lyden kunne ikke hentes fra Zendesk (N forsøg)." + `<details>` med `audio_error`; med ticket også "Optagelsen kan måske afspilles direkte i ticket #N ↗." | neutral: "Der kan ikke laves et transcript uden lyd." |
| hentet | færdig, tom tekst | afspiller | info: "Der blev ikke fundet tale i optagelsen." |

- Opkald findes ikke: 404 "Opkaldet findes ikke." med linket "Til opkald" (bevar query).
- Tilbage-link: "← Tilbage til opkald" til `/?<query>`, ellers "← Alle opkald".

**Responsivt:** 700–1099px: én kolonne med en sticky `.player-bar` (afspiller + kompakt metadata) øverst og `dl.meta` i `<details><summary>Detaljer</summary>` under transcriptet. Under 700px: samme, og tidsstemplerne står over teksten.

### 3.5 Login `/login`
Smalt kort, 15vh top-margin. Over H1: "Whisper" med undertitlen "Opkald og transcripts fra kundeservice". Knappen "Log ind" i fuld bredde. Under formularen: "Glemt adgangskode? Kontakt en administrator." Fejl som i dag med `role="alert"` og fokus på e-mail.

### 3.6 Konto `/konto` (inkl. F003)
`.card.medium`. H1 "Konto", `dl` med Navn, E-mail og Rolle. Sektion 1 `<section aria-labelledby>`: H2 "Adgangskode" med den eksisterende formular og knappen "Skift adgangskode". Tvunget skift: info-banneret øverst. Sektion 2 (kun med `F003_mcp`): H2 "Claude Desktop (MCP)".
```
Forbind Claude Desktop til Whisper, så Claude kan søge i og læse transcripts.
Claude får kun læseadgang. Nøglen er personlig – del den ikke.
Dine nøgler (2 af 5)
Navn | Nøgle (wsp_a1B2… mono) | Oprettet | Sidst brugt (relativ / "Aldrig") | [Tilbagekald]
Opret ny nøgle: Navn på nøglen [____] [Opret nøgle]
Fx navnet på computeren, så du kan kende nøglen igen.
```
- Ingen nøgler: "Du har ingen nøgler endnu." 5 aktive: formularen erstattes af "Du har 5 aktive nøgler, som er det højeste antal. Tilbagekald en nøgle for at oprette en ny."
- Tilbagekald: én `form` pr. række (POST, CSRF), `.button.danger-secondary`, `aria-label="Tilbagekald nøglen «navn»"`, ingen bekræftelsesside (D7). Flash: "Nøglen «navn» er tilbagekaldt. Claude Desktop med den nøgle har ikke længere adgang."

**Efter oprettelse** (vises én gang, `no-store`, i et ok-kort øverst i sektion 2):
```
✓ Nøglen «Claude Desktop – laptop» er oprettet
Kopiér nøglen nu. Den vises kun denne ene gang.
[wsp_… (readonly, monospace)]  [Kopiér nøgle]
Sådan forbinder du Claude Desktop
1. Installér Node.js (LTS) fra nodejs.org, hvis du ikke har det.
2. Åbn Claude Desktop → Indstillinger → Udvikler → Rediger konfiguration.
   Windows: %APPDATA%\Claude\claude_desktop_config.json
   macOS:   ~/Library/Application Support/Claude/claude_desktop_config.json
3. Indsæt blokken herunder. Har filen allerede "mcpServers", så tilføj kun "whisper"-delen.  [Kopiér konfiguration]
4. Genstart Claude Desktop helt (luk den også i proceslinjen).
5. Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning."
```
- `<pre><code tabindex="0" aria-label="Konfiguration til Claude Desktop">`. Menupunkternes navne og `mcp-remote`-versionen tjekkes af engineeren.
- Fejl: tomt navn "Skriv et navn til nøglen.", over 80 tegn "Navnet må højst være 80 tegn.", i `.msg.error` over formularen, og feltet får `aria-invalid="true"` og `aria-describedby`.

### 3.7 Brugere
- Liste: Navn (link, row-link-mønstret), E-mail, Rolle, Status (badge "Aktiv" ok, "Deaktiveret" neutral), Sidste login. "Rediger"-kolonnen fjernes. Deaktiverede rækker i `--muted`. "Opret bruger" primær knap til højre for H1.
- Rediger: `.card.medium`, H1 navnet, undertitel "e-mail · status-badge". Formular "Oplysninger" (Navn, Rolle, "Gem ændringer"). Sektion "Adgang" med "Nulstil adgangskode …" (sekundær) og "Deaktiver bruger …" (`danger-secondary`), og "Sidste login" muted.
- Engangskode-siden: readonly felt og "Kopiér" (JS, `hidden` uden JS) på samme linje. Primær knap "Færdig – til brugeren", sekundært link "Til listen".
- Bekræftelsessider: uændret mønster; Annuller er en sekundær knap.

### 3.8 Fejlsider
Linket hedder "Til opkald", når man er logget ind, og "Til login", når man ikke er.

### 3.9 Liste under 640px
Tabellen bliver til kort med `td[data-label]::before`. Dato og status i kortets første linje. Filterkortet stables. Touch-mål mindst 44px.

## 4. `app.js` (bundet til dataattributter, ingen inline)
1. **Klik på tidsstempel → spol og afspil (skal).** Delegeret `click` på `.ts[data-start]`. Første linje: `document.documentElement.classList.add('js')`; derefter fjernes `disabled` fra `.ts` og `hidden` fra `[data-js-only]`. Ved `audio.error` forbliver stemplerne tekst.
2. **Kopiér-knapper (skal for engangskode og MCP, bør for transcript).** `button[data-copy="#id"]` med `navigator.clipboard.writeText`; fallback `select()` og "Tryk Ctrl+C for at kopiere". Knapteksten "Kopieret ✓" i 2 s. Fælles `<div class="sr-only" role="status" id="live">` får "Kopieret til udklipsholderen."
3. **Markér den aktuelle linje under afspilning (bør).** `timeupdate` sætter `.is-current`. Ingen automatisk rulning.
4. **Afspilningshastighed (kan).** "Hastighed: 1× 1,25× 1,5× 2×" (`aria-pressed`) sætter `playbackRate`.

Intet andet: ingen auto-refresh, ingen auto-submit og ingen tastaturgenveje.

## 5. Mikrotekster
Alle tekster i afsnit 3 er de endelige. Appen har ingen sprogfil, og der skal ikke indføres en i18n-motor.

## 6. Tilgængelighed (WCAG 2.2 AA)
- Fokusrækkefølge: skip-link → hovedmenu → brugermenu → H1 → filtre (søgning først) → chips → zip → tabel → pager; detaljen: tilbage-link → afspiller → hastighed → transcript-handlinger → segmenter.
- Hver `nav` har `aria-label`. Badges er tekst. Ikoner og pile er `aria-hidden` med skjult tekst. `<table>` har `<caption class="sr-only">`.
- Én `role="status"` i layoutet til kopiering. Flash beholder `role="status"`, fejl `role="alert"`.
- Mindst 24×24 overalt og 44px på touch.
- Sticky header og afspiller må ikke dække fokus (`scroll-padding-top`).
- Ingen tilstand vises kun med farve.

## 7. Brugerflows og kanttilfælde
- Hovedflow: søg → chips og uddrag → klik på række → detalje med fremhævning og "Gå til første fund" → klik på stempel → kopiér eller download → "← Tilbage til opkald" til samme side og filtre.
- Alt ligger i URL'en (GET). Engangskode og MCP-nøgle vises kun i POST-svaret.
- Lange navne afkortes med `text-overflow: ellipsis` og `title`. Transcripts over 60 min. har `t:mm:ss`.

## 8. Prioriteret liste
**SKAL:**
1. Tilbage-link med bevarede filtre.
2. Detalje: afspiller og transcript side om side, sticky afspiller, segmentliste med grid.
3. Klikbare tidsstempler med `app.js` og `disabled`-fallback.
4. Søgeord fremhævet på detaljen, "N fund" og "Gå til første fund".
5. Statusbadges efter 3.2.
6. Kontrast og fokus.
7. Tilstandstekster på detaljen, tekniske fejl i `<details>`.
8. Rollestyrede systembeskeder.
9. Kopiér-knapper på engangskode og MCP-nøgle og konfiguration.
10. Header: `aria-current`, skip-link, brugermenu til højre, bredere `main`.

**BØR:**
11. Filterkort med søgning først og "Søg".
12. Aktive filtre som chips; "Nulstil filtre" kun når aktivt.
13. Klikbar række, hover, sticky tabelhoved, højrestillet varighed.
14. Statuslinje med relativ tid og sundhedsprik; "Viser 1–50 af N"; pager som `nav`.
15. Søgeuddrag i listen (kræver JOIN).
16. "Kopiér tekst" på transcriptet og markering af aktuel linje.
17. Konto i `.card.medium` med sektioner (F003-sektionen efter 3.6).
18. Zip-knap flyttet og omdøbt med hjælpetekst.
19. Mobilkort med `data-label`.

**KAN:**
20. Hurtigvalg for periode.
21. Afspilningshastighed.
22. Brugerlisten med navn som link og statusbadge; engangskodesiden med "Færdig".
23. Login-undertitel og hjælpelinje; fejlsider "Til opkald".
24. Forrige og næste opkald fra detaljesiden.
25. Mørk tilstand (se det visuelle brief).
26. Cache-busting `?v=<version>` på `app.css` og `app.js`.

## 9. Nye design-beslutninger (alle anbefalet: JA)
- **D1** Progressive enhancement med `hidden`/`disabled` og `app.js`; ingen inline JS.
- **D2** Listens kontekst bæres i URL'en til detaljesider (også F004).
- **D3** Ét badge-system med tekst og prik.
- **D4** Tekniske fejl i `<details>`; opsætningsbeskeder kun til admin.
- **D5** Tre indholdsbredder (480, 760, 1280).
- **D6** Server-side fremhævning med tokenisering før escaping.
- **D7** MCP-nøgler tilbagekaldes med direkte POST uden bekræftelsesside.

## 10. Til Team Lead
- Udenfor scope: søgning på telefonnummer og download af lyd.
- Status `ingen` står i spec'ens ENUM, men findes hverken i migration eller kode; badget har "Ukendt"-fallback.
- F003's `/konto`-sektion bør bygges efter 3.6 nu.
