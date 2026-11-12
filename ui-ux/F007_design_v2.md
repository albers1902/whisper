Status: READY_FOR_IMPLEMENTATION

# F007, design v2: nyt udseende og Zendesk/Adversus hver for sig

Status: READY_FOR_IMPLEMENTATION

Kilder:
- `product-ai/F007_v2.md` (bindende) og `tasks/impact-F007.md`.
- Det godkendte redesign: artboardene `v2-Opkald.dc.html` og `v2-Detalje.dc.html` (siden "v2 — moderne"). Ejeren skrev "design er fint" 2026-11-12. Filerne ligger ikke i git, så alt, engineeren skal bruge fra dem, står i dette brief.
- Historik: `ui-ux/F007_design_v1.md` og `graphic-design/F007_visual_v1.md`. Deres regler for kilde i URL, antal på fanerne, beskeder pr. kilde, tomme tilstande og tilbage-link gælder stadig og er gentaget her. Fanernes udseende (understregning) er afløst.
- Kode (main @ 38c7c03): `homedir/src/Ui.php`, `UiCalls.php`, `UiPages.php`, `CallView.php`, `Calls::segments`, `Diarization::label`, `public_html/assets/app.css` og `app.js`.

`DESIGN_LOG.md` og `PRD.md` findes ikke. Rammen er derfor F005-, F004- og F007 v1-briefene. Afvigelser fra dem er markeret **AFVIGELSE**.

---

## 0. Rammer (bindende)

**Flaget slukket (AK8).**
- Med `F007_kilder` slukket er HTML, CSS-effekt og URL'er byte for byte som i dag (F005/F004/H011).
- Der hentes ingen fontfil, og ingen ny klasse får effekt. Nye regler skal derfor ligge, så de ikke rammer siderne, når flaget er slukket: enten i et eget stylesheet, der kun linkes med flaget tændt, eller scopet under en klasse på `<html>`, som kun sættes med flaget tændt. Engineeren vælger.
- `@font-face` må kun stå i den del, der indlæses med flaget tændt.

**Forudsætninger.**
- F007 kræver `F005_ui` tændt. Med F005 slukket bruges det gamle UI uændret, uanset F007.
- Med F007 tændt og `F004_adversus` slukket: ny ramme og nyt udseende, men ingen faner og ingen succes-vælger. Listen viser kun Zendesk-kort.

**Teknik.**
- Siderne renderes på serveren. Intet framework, intet build-trin, intet CDN.
- CSP: `script-src 'self'`, ingen `style=""`, ingen `<style>`, intet inline script. Al JS ligger i `/assets/app.js`.
- Alt skal virke uden JS (progressive enhancement, F005 D1). JS må kun forbedre: afspiller, menu-knap, klik på boble, luk popover med Esc og kopiér.
- Ingen `data:`-URI'er i CSS. Chevrons og ikoner er inline-SVG fra `Ui::icon()` eller pseudo-elementer.
- Fonte: Inter (400, 500, 600, 700) og Inter Tight (600, 700) som `woff2` under `public_html/assets/fonts/`, med `OFL.txt`. `font-display: swap`. Fallback-stak: `system-ui, "Segoe UI", Roboto, Arial, sans-serif`.

**Tilstand i URL'en.**
- Alt ligger i URL'en (D2, D-F4-1, D-F7-1). Der er ingen cookie og ingen session til fane eller filtre.
- Lyst og mørkt tema følger `prefers-color-scheme` som i dag. Der er ingen tema-knap.

---

## 1. Page/screen layout

### 1.1 Ramme for indloggede sider (≥ 900 px)
```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│ ▮▮▮▮ Whisper │  (sidens indhold, padding 32 40 48)                          │
│              │                                                              │
│ ☎ Opkald  ●  │                                                              │
│ 👥 Brugere    │  ← kun admin                                                 │
│ ◯ Konto      │                                                              │
│              │                                                              │
│      …       │                                                              │
│ ┌──────────┐ │                                                              │
│ │TRANSCRIP-│ │                                                              │
│ │TION      │ │                                                              │
│ │1.022 i kø│ │                                                              │
│ │● Kører   │ │                                                              │
│ │18 venter │ │                                                              │
│ │  på lyd  │ │                                                              │
│ └──────────┘ │                                                              │
│ (JA) Jacob A.│                                                              │
│  Administrator│                                                             │
│ [⇥ Log ud]   │                                                              │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

**DOM-rækkefølge:** skip-link "Spring til indhold" → `<aside class="sidebar" id="sidemenu">` → `<main id="indhold" tabindex="-1">` → `#live` (sr-only, `role="status"`, findes i dag).

**Sidebar:**
- Bredde 248 px, fast i venstre side.
- `position: sticky; top: 0; height: 100vh; overflow-y: auto`.
- Baggrund `#0F1218` i begge temaer. Padding 24 px lodret og 16 px vandret.
- Lodret flex med gap 28 px. Kø-kortet og brugerblokken skubbes til bunden (`margin-top: auto`).

**Mærket:**
- Fire lodrette bjælker i 26 × 26 px. Bjælke 1 og 2 i `#7C9CFF`, bjælke 3 og 4 i `#A5B8FF`. Højderne er 8, 18, 12 og 6, bredden 3 og radius 1,5.
- Efter mærket står "Whisper" i Inter Tight 19 px/700, hvid, letter-spacing -0,01em.
- Hele mærket er et link til `/`, og det skal have en synlig fokusring.

**Hovedmenu:**
- `<nav aria-label="Hovedmenu">` med Opkald (`/`), Brugere (`/brugere`, kun admin) og Konto (`/konto`), i den rækkefølge.
- Hvert punkt: ikon 18 px + tekst 14 px, padding 10 × 12, radius 10, gap 12.
- Inaktivt punkt: `#C9CFDA`/500. Hover: baggrund `#171C26`, tekst hvid.
- Aktivt punkt: `aria-current="page"`, baggrund `#1E2430`, hvid tekst, vægt 600.
- Der er ingen tæller ved "Opkald" (se §12, punkt 3).

**Kø-kortet:** se §2.1.

**Brugerblok:**
- Avatar 32 px rund i `#3B5BDB` med hvide initialer (13 px/600).
- Navn 13 px/600 hvid med ellipsis ved overløb og fuldt navn i `title`.
- Under navnet står rollen, 12 px `#9AA4B6`: "Administrator" eller "Bruger".
- Under blokken står knappen "Log ud": fuld bredde, ghost, ikon `log-out`, 14 px `#C9CFDA`, hover-baggrund `#1E2430`. Det er en POST-formular med CSRF som i dag.

**Main:**
- `flex: 1; min-width: 0`. Padding 32 px foroven, 40 px i siderne og 48 px forneden.
- Listen har maks. bredde 1120 px og detaljen 1280 px. Begge står venstrestillet ved sidebaren.

### 1.2 Ramme under 900 px (topbar)
```
┌──────────────────────────────────────────┐
│ [☰]  ▮▮▮▮ Whisper            ● 1.022 i kø │  56 px, #0F1218, sticky top 0
├──────────────────────────────────────────┤
│ (menupanel, når åbent: nav, kø-kort,     │
│  brugerblok, Log ud — skubber indholdet) │
├──────────────────────────────────────────┤
│ indhold, padding 24 (20/16 under 600 px) │
```

**Topbar:**
- Højde 56 px, `position: sticky; top: 0; z-index: 10`, baggrund `#0F1218`.
- Fra venstre: menu-knappen, så mærket.
- Til højre en kompakt kø-tekst: "1.022 i kø", 13 px `#C9CFDA`, tabulære tal. Prikken foran er gul eller rød, når motorstatus ikke er OK (§2.1). Teksten er ikke interaktiv. Er køen 0 og motoren OK, vises kun "Ingen i kø".

**Menu-knap:**
- `<button type="button" aria-expanded="false" aria-controls="sidemenu">`, 44 × 44.
- Ikonet er `menu`, og det skifter til `x`, når menuen er åben. Det tilgængelige navn er fast: "Menu".

**Menupanel:**
- Det er sidebarens indhold (samme element `#sidemenu`), lagt under topbaren i fuld bredde. Det er ikke et overlay, så indholdet skubbes ned, og der er ingen fokusfælde.
- **Med JS:** panelet er skjult som standard, og knappen viser og skjuler det. Esc lukker og sætter fokus tilbage på knappen. Et klik på et menupunkt navigerer (ny side, panelet er lukket).
- **Uden JS:** knappen er skjult. Panelet står altid åbent under topbaren som en kompakt blok med navigationen som vandret række, der ombrydes. Kø-kortet og brugerblokken står nedenunder. Brug mønsteret `html.js` (app.js sætter allerede klassen `js`).

**Indhold:** padding 24 px ved 600–899 px og 20 px lodret/16 px vandret under 600 px.

### 1.3 Sider uden sidebar: login, konsent (F006) og fejlsider
```
            ▮▮▮▮ Whisper            (centreret, 48 px over kortet)
   ┌────────────────────────────┐
   │ Log ind                    │   kort: hvid, radius 16, border #E8EBF0,
   │ …formular som i dag…       │   padding 32 (24 under 600 px), maks. 420 px
   └────────────────────────────┘   (konsent og fejl: maks. 520 px)
```
- Siden har baggrunden `#F6F7F9` og samme typografi og farver. Der er ingen sidebar og ingen topbar, også når brugeren er logget ind (konsent og fejlsider).
- Mærket over kortet linker til `/`.
- Indhold, felter, knapper og tekster er som i dag. Kun udseendet ændres.

### 1.4 Opkaldslisten `/` (flag tændt)
```
Opkald                                                     [⤓ Download transcripts (.zip)]
● Hentet fra Zendesk for 8 min. siden                       ← kun den aktive fanes kilde

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 🔍  Søg i alt, der er sagt — fx "opsigelse", "flytning", "faktura"                   │
│     [📅 I dag ▾] [Alle agenter ▾] [Ind og ud ▾]   Nulstil filtre            [ Søg ]   │
└─────────────────────────────────────────────────────────────────────────────────────┘

┌[ Zendesk 1.234 ]─[ Adversus 91 ⚠ ]┐             (kun Adversus:) (● Succes og valgte) ( Alle opkald )
⚠ Nogle opkald kunne ikke hentes.  ▸ Teknisk besked            ← kildens beskeder, kun egen fane

Viser 1–50 af 1.234 opkald
┌─────────────────────────────────────────────────────────────────────────────────────┐
│ (SA)  Sara                    [Zendesk]  Ticket #1290 ↗              3:01  ● Færdig  │
│       ↓ Ind · +45 12 34 89 75 · i dag 12:09                                          │
│ Agent: Tryg Energi, du snakker med Sara. Hvad kan jeg hjælpe med? Kunde: Jeg ringer  │
│ på vegne af min mor — hun har sendt jer en mail om at få aftalen flyttet.           │
└─────────────────────────────────────────────────────────────────────────────────────┘
 … (gap 10 px mellem kort)
                       [← Forrige]   Side 1 af 27   [Næste →]
```

Rækkefølge fra top til bund. Main er en lodret flex med gap 20 px.

1. **Sidehoved.** Til venstre:
   - H1 "Opkald" i Inter Tight 30 px/700, letter-spacing -0,02em.
   - Under den, 4 px nede, kildens statuslinje i 14 px `#5B6475` med prik. Det er `sourceLine` for den aktive kilde med de samme regler som i dag (§2.4).

   Til højre zip-knappen som sekundær knap: ikon `download` og teksten "Download transcripts (.zip)". Den vises kun, når der er mindst ét opkald. Den har `aria-describedby` til hjælpeteksten i punkt 6.
2. **Flash** (`Ui::message`), uændret.
3. **Søgekortet** (§2.2).
4. **Fanerækken.** Kildefanerne står til venstre. Succes-vælgeren står til højre, kun på Adversus. Rækken ombrydes.
5. **Kildens beskeder** som `.msg` (§2.4), kun for den aktive fane. Info-beskeden "Til-datoen er før fra-datoen." står her.
6. **Opsummering:** "Viser 1–50 af 1.234 opkald", 13 px `#5B6475`, `id="antal"`. Over zip-loftet står der efter et " · ": "zip-filen får de 2000 nyeste færdige". Ellers er hjælpeteksten "Alle færdige transcripts i filtret, højst 2000." kun `sr-only` (zip-knappens beskrivelse). Er søgeordet kort, kommer tippet "Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf." på en egen linje under.
7. **Kortlisten** `<ol class="call-list">` (§2.5).
8. **Pager** (§2.6).

### 1.5 Detaljesiden `/opkald/{id}` (≥ 1180 px)
```
← Tilbage til opkald

Sara · indgående opkald                                       [Kopiér tekst] [⤓ Download .txt]
[6. nov. 2026, 12:09] [3:01 min] [Zendesk] [Ticket #1290 ↗] [● Færdig]

┌ Samtale ───────────────────────────────────────┐  ┌ (mørkt afspillerkort) ─────────┐
│ 3 fund for «faktura» · Gå til første fund       │  │ (▶)  ━━━━━━●──────────────    │
│ ┌ AGENT 00:01 ──────────────┐                   │  │ 0:42                    3:01  │
│ │ Tryg Energi, du snakker … │                   │  │ [0,75×][1×][1,25×][1,5×][2×]   │
│ └───────────────────────────┘                   │  │ ⤓ Download lyd                │
│                  ┌ KUNDE 00:03 ───────────────┐ │  └───────────────────────────────┘
│                  │ Hej Sara, jeg hedder …     │ │  ┌ Detaljer (metadata-gitter) ───┐
│                  └────────────────────────────┘ │  │ AGENT        RETNING          │
│                  ┌ 00:10 ─────────────────────┐ │  │ Sara         Indgående        │
│                  │ Hun har sendt jer en mail …│ │  │ KUNDE        VARIGHED  …      │
│                  └────────────────────────────┘ │  └───────────────────────────────┘
│ …                                               │  ┆ Spørg Claude om opkaldet      ┆
└─────────────────────────────────────────────────┘  ┆ …opkald 226…                  ┆
                                                     └┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┘
```

**Venstre kolonne:** "Samtale"-kortet med `flex: 1; min-width: 0`.

**Højre kolonne:**
- Bredde 340 px, `position: sticky; top: 24px`, lodret gap 16 px.
- Indhold i rækkefølge: afspillerkort, metadata-kort og Claude-boks.

**Under 1180 px:** én kolonne. Rækkefølgen er:
1. Tilbage-link og sidehoved.
2. Afspillerkortet. Det er sticky under topbaren (`top: 56px` under 900 px, `top: 0` ved 900–1179 px). Undtagelse: ikke sticky ved `max-height: 600px`.
3. Samtalen.
4. `<details class="meta-details"><summary>Detaljer</summary>` med metadata-gitteret, lukket som standard (det findes i dag).
5. Claude-boksen.

---

## 2. Components

| Komponent | Ny/eksisterende | Konfiguration |
|---|---|---|
| `aside.sidebar` / topbar / menu-knap | **NY** | §1.1–1.2 |
| Kø-kort | **NY** (afløser `.sync-queue` og motorboksen) | §2.1 |
| Søgekort med piller | **NY** (afløser `.filters.card` og chips med flaget tændt) | §2.2 |
| Kildefaner (segmenteret) | **NY form** (afløser `.source-tabs` fra visual v1) | §2.3 |
| Succes-vælger som piller | **NY form** af `.view-switch` | §2.3 |
| `.msg` info/ok/warn/error med `<details>` | EKSISTERENDE, nyt udseende | §2.4 |
| Opkaldskort | **NY** (afløser `table.calls` med flaget tændt) | §2.5 |
| Status-pille | EKSISTERENDE `.badge .s-*`, nyt udseende og én ny tekst | §2.7 |
| Kilde-tag, Succes-lead-tag | EKSISTERENDE `.tag-source` og `.tag-success`, nyt udseende | §2.5 |
| Pager | EKSISTERENDE, nyt udseende | §2.6 |
| Tom-tilstand `.empty` | EKSISTERENDE, nye tekster pr. fane | §3 |
| Chatbobler / én kolonne | **NY** / EKSISTERENDE `ol.transcript` | §2.8 |
| Afspillerkort | **NY** (afløser `.player` med flaget tændt) | §2.9 |
| Metadata-gitter | EKSISTERENDE `dl.meta`, nyt udseende | §2.10 |
| Claude-boks | **NY** | §2.11 |
| Knapper, felter, kort | EKSISTERENDE, nyt udseende | §2.12 |

**Nye ikoner i `Ui::ICONS`** (Lucide, ISC som i dag): `phone`, `users`, `circle-user`, `menu`, `x`, `search`, `calendar`, `chevron-down`, `play`, `pause` og `message-square`.

### 2.1 Kø-kortet (sidebar)

**Kort:** baggrund `#171C26`, radius 12, padding 14, lodret gap 10. Kortet er `<section aria-labelledby="ko-h">`.

**Indhold, oppefra og ned:**
1. **Overskrift:** `<h2 id="ko-h">TRANSCRIPTION</h2>` i 12 px/600, `#9AA4B6`, letter-spacing 0,02em, versaler via CSS (`text-transform`). Den skrevne tekst er "Transcription".
2. **Tal:**
   - Køen over 0: tallet i Inter Tight 24 px/700, hvidt, med tusindtalspunktum ("1.022"). Efter det, med 6 px luft, "i kø" i 13 px `#9AA4B6`.
   - Køen er 0: "Ingen i kø" i 15 px/600, hvid.
3. **Motorlinje:** prik + tekst, 12 px `#C9CFDA`. Status læses fra samme kilde som motoren (`ready-<model>` og seneste aktivitet i transcribe-loggen), aldrig fra en forældet markør (AK7).

   | Motorstatus | Prik | Tekst (admin) | Tekst (bruger) |
   |---|---|---|---|
   | Klar, et opkald transcriberes nu, eller der har været aktivitet inden for 30 min. | grøn | Transcriberer nu | Transcriberer nu |
   | Klar, køen er 0 | grøn | Klar | Klar |
   | Klar, køen er over 0, og der har ikke været aktivitet i 30 min. eller mere | gul | Ingen fremdrift i 45 min. | Ingen fremdrift i 45 min. |
   | Ikke sat op (ingen `ready-<model>`) | rød | Ikke sat op. Kør cron/whisper-setup.sh. | Nye opkald bliver ikke transcriberet lige nu. |

   45 er et eksempel. Tallet er minutter siden seneste aktivitet, med samme afrunding som `Ui::ago` ("for 2 t." bliver til "Ingen fremdrift i 2 t."). 30 min. er samme grænse som `FRESH`. Tærsklen fastsættes i backend, og designet afhænger ikke af den.
4. **Lyd-linje** (kun over 0): "18 venter på lyd", 12 px `#9AA4B6`.

**Ingen fremdriftsbjælke.** Designets bjælke har ikke noget defineret grundlag (se §12).

**Fælles kø.** Tallene er fælles for begge kilder, som `Calls::status()` i dag. Kortet står på alle indloggede sider med sidebar.

**Motorboksen udgår.** Den blå `.msg.info` "Transcription er ikke sat op …" vises ikke længere på listen med flaget tændt. Den røde motorlinje i kortet og den røde prik i topbaren afløser den. Det følger D-F7-5 og spec'en ("Kortet erstatter den samlede status").

**Topbar (under 900 px):** kortets tal og motorprikken gengives kompakt (§1.2). Prikken har en sr-only tekst: "Transcription: ikke sat op" eller "Transcription: ingen fremdrift".

### 2.2 Søgekortet
`<form method="get" action="/" role="search" aria-label="Søg og filtrér opkald">`:
- Hvid, border `#E3E6EC`, radius 14, padding 10 px på tre sider og 18 px til venstre, skygge `0 1px 2px rgba(16,24,40,.04)`.
- Flex med wrap og gap 12.

**Søgefelt:**
- Ikon `search` 20 px `#7A8394` (`aria-hidden`).
- `<label for="q" class="sr-only">Søg i transcripts</label>`.
- `<input type="search" id="q" name="q" maxlength="200">` med `flex: 1 1 320px`, uden ramme, 16 px/500.
- Placeholder: "Søg i alt, der er sagt — fx opsigelse, flytning, faktura".
- `aria-describedby="q-hjaelp"`. `#q-hjaelp` er sr-only: "Alle ord skal indgå. Ord på mindst 3 bogstaver giver det bedste resultat."

**Pillerækken** står efter feltet og ombrydes. Fælles pille: højde 36 px (44 px under 600 px), padding 0 12, radius 9, border `#E3E6EC`, hvid baggrund, 13 px/500 `#3A4252`.
- **Pille sat** (filteret har en værdi): baggrund `#EAF0FF`, border `#B9C8F5`, tekst `#2B44AE`/600. Vægten markerer også tilstanden, så den ikke kun vises med farve.

1. **Dato-pille** `<details class="pill-menu">`:
   - **Summary:** ikon `calendar` + periodens navn + `chevron-down`. Navnet er:
     - "Alle datoer", når hverken fra eller til er sat;
     - "I dag", "Seneste 7 dage" eller "Denne måned", når fra og til matcher et hurtigvalg;
     - ellers "12.11.2026–14.11.2026", "Fra 12.11.2026" eller "Til 14.11.2026".
   - **Panel:** under pillen, `position: absolute`, bredde 280 px, hvid, radius 12, border `#E3E6EC`, `--shadow-2`, padding 12, `z-index: 5`. Under 600 px ligger panelet i flowet (`static`) i fuld bredde under pillerækken.
   - **Panelets indhold:**
     - Hurtigvalgene som links: "Alle datoer", "I dag", "Seneste 7 dage", "Denne måned". De bevarer de andre filtre og `kilde` og nulstiller `side`. Det aktive har `aria-current="true"` og vægt 600.
     - Under dem `Fra` og `Til` som `<input type="date" name="fra|til">` med synlige labels, i den samme formular.
     - Knappen "Brug datoer" (sekundær, `type="submit"`).
   - **JS (KAN):** Esc lukker en åben `details` og sætter fokus på dens `summary`. Et klik udenfor lukker den.
2. **Agent-pille:**
   - Et rigtigt `<select name="agent">` stylet som pille med `<label class="sr-only">Agent</label>`.
   - Første option er "Alle agenter". Listen indeholder kun den aktive kildes agenter (D-F7, `Calls::agents($kilde)`).
   - Chevron via wrapper-`::after` (ingen `data:`-URI). Lange navne afkortes med ellipsis i pillen (maks. 200 px), og den fulde liste står i dropdownen.
3. **Retnings-pille:** `<select name="retning">` med `<label class="sr-only">Retning</label>` og valgene "Ind og ud" (''), "Indgående" og "Udgående".
4. **"Nulstil filtre"** (link, 13 px, `#3B5BDB`). Det vises kun, når et filter er sat, og det bevarer `kilde` og `vis`.
5. **Søg-knap:** primær, "Søg", `type="submit"`, til højre (`margin-left: auto`).

**Skjulte felter:** `kilde=adversus` (kun på Adversus-fanen) og `vis=alle` (kun når den er sat).

**Ingen auto-submit** (D1, WCAG 3.2.2). Ændringer i dato, agent og retning slår igennem, når man trykker "Søg" eller Enter i søgefeltet. Pillen viser den valgte værdi med det samme, fordi det er selve select-feltet.

**Chips udgår med flaget tændt.** Pillerne viser de aktive filtre, og søgeordet står i feltet. **AFVIGELSE** fra F005 (chips "Aktive filtre"), kun med flaget tændt.

### 2.3 Kildefaner og succes-vælger

**Faner** (designets segmenterede form): `<nav aria-label="Kilde"><ul>` med to links i fast rækkefølge, Zendesk og Adversus.
- **Gruppen:** `inline-flex`, gap 4, baggrund `#ECEEF2`, padding 4, radius 12.
- **Link:** `inline-flex`, padding 9 × 16, radius 9, 14 px, min-højde 40 px (44 px under 600 px), `white-space: nowrap`.
- **Inaktiv fane:** transparent, tekst `#4A5263`/500. Hover: baggrund `#E2E5EB`.
- **Aktiv fane:** `aria-current="page"`, baggrund hvid, tekst `#12151C`/600, skygge `0 1px 2px rgba(16,24,40,.08)`.
- **Antal:** `<span class="tab-count">` 6 px efter navnet, 500, tabulære tal, tusindtalspunktum. Farven er `#5B6475` og ikke designets `#7A8394`, som kun giver ca. 3,3:1 på `#ECEEF2`. Efter tallet følger en sr-only tekst " opkald".
- **Problem-markør (BØR, som v1 §2.2 punkt 6):**
  - Den står kun på den inaktive fane, som et ikon på 14 px efter tallet.
  - `circle-alert` i `#A5221B` betyder, at kilden har `last_error` (ikke rate limit). Den vises for alle roller.
  - `triangle-alert` i `#8A4600` betyder Adversus-advarsel. Den vises kun for admin.
  - Fejl vinder, så der er højst ét ikon pr. fane.
  - Ikonet er `aria-hidden` med sr-only tekst: ", hentningen er stoppet" eller ", har en advarsel".

**URL-regler (uændret fra v1 §5):**
- `kilde=adversus` giver Adversus-fanen. Alt andet giver Zendesk, og standarden skrives aldrig i genererede links.
- Fanelinks bærer `fra`, `til`, `retning` og `q`. De dropper `agent`, `side` og `vis`.
- Antallet er det antal, et klik giver: den aktive fanes antal er lig "Viser … af N", og den inaktive fanes antal er COUNT med fanelinkets parametre. Ved 0 står "0", og fanen kan stadig klikkes.

**Succes-vælger (kun Adversus):** `<div role="group" aria-label="Vis">` med to links som piller. De står til højre i fanerækken og ombrydes under fanerne på smalle skærme.
- **Aktiv:** `aria-current="true"`, baggrund `#E8F5EE`, tekst `#146C43`/600. Foran teksten står en `●`, der er `aria-hidden`.
- **Inaktiv:** hvid baggrund, border `#E3E6EC`, tekst `#3A4252`/500.
- **Mål:** radius 999, padding 7 × 12, 13 px, min-højde 36 px (44 px under 600 px).
- **Tekster:** "Succes og valgte" og "Alle opkald".
- Begge links bærer `kilde=adversus` og filtrene og nulstiller `side` (F004, v1 §4). På Zendesk-fanen findes `vis` ikke.
- Gamle `/?vis=alle` åbner Adversus-fanen med "Alle opkald".

**Designets status-piller "Alle / Færdige / I kø" bygges ikke.** Et statusfilter er en ny funktion, og spec'en nævner det ikke (§12).

**`<title>`:** "Opkald · Zendesk" eller "Opkald · Adversus". H1 forbliver "Opkald".

### 2.4 Kildens statuslinje og beskeder

**Statuslinje** under H1:
- Den bruger `sourceLine` for den aktive kilde med de samme regler og tekster som i dag: "Hentet fra Zendesk for 8 min. siden", "Sidst hentet fra …", "Endnu ikke hentet fra …", "Hentningen fra … er stoppet" og "… bad os vente. Hentningen fortsætter ved næste kørsel.".
- Prik 8 px: grøn `#1F9D5B`, gul `#C27C00` eller rød `#C0362C`. Den er `aria-hidden`, og teksten bærer betydningen.
- Adversus uden opsætning giver en grå prik og "Adversus er ikke sat op".
- Linjen har ingen "Adversus … · Zendesk …"-sammenkædning som i designet. Spec'en og AK4 kræver kun egen kilde.

**Beskeder:** tabellen fra v1 §2.2 gælder uændret, og beskederne står kun på deres egen fane:

| Situation | Fane | Admin | Bruger |
|---|---|---|---|
| Zendesk ikke sat op | Zendesk | info: "Zendesk er ikke sat op. Opkald hentes, når `[zendesk]` er udfyldt i config.ini." | info: "Opkald hentes ikke fra Zendesk endnu. Kontakt en administrator, hvis du savner opkald." |
| Zendesk-config ugyldig | Zendesk | fejl: "Zendesk-opsætningen i config.ini er ugyldig." | info (som rækken over) |
| Zendesk-sync stoppet | Zendesk | fejl: "Seneste hentning fra Zendesk stoppede." + "Teknisk besked" | kun rød prik |
| Adversus ikke sat op | Adversus | info: "Adversus er ikke sat op. Opkald hentes, når `[adversus]` er udfyldt i config.ini." | info: "Opkald hentes ikke fra Adversus endnu. Kontakt en administrator, hvis du savner opkald." |
| Adversus-config ugyldig | Adversus | fejl: "Adversus-opsætningen i config.ini er ugyldig." | info (som rækken over) |
| Adversus-sync stoppet | Adversus | fejl: "Seneste hentning fra Adversus stoppede." + "Teknisk besked" | kun rød prik |
| Adversus-advarsel (H009) | Adversus | warn: "Nogle opkald kunne ikke hentes." + "Teknisk besked" | intet |
| Rate limit | egen fane | kun statuslinjen | kun statuslinjen |

- Advarslen forsvinder, når H009-genopretningen har kørt (backend), og ellers efter `WARN_TTL`.
- "Teknisk besked" er altid en lukket `<details>`.

**`.msg`, nyt udseende:**
- Radius 10, padding 10 × 14, 14 px, ikon 16 px, gap 10.
- Farver: warn `#FFF6E5`/`#7A4B00`, info `#EAF0FF`/`#2B44AE`, fejl `#FDECEA`/`#A5221B` og ok `#E8F5EE`/`#146C43`.
- Rollerne er som i dag (`role="alert"` ved fejl og `role="status"` ved ok).

### 2.5 Opkaldskort

Hvert opkald er `<li class="call-card">` i `<ol class="call-list" aria-labelledby="antal">`.

**Kortets form:**
- Hvid, border `#E8EBF0`, radius 14, padding 16 × 18, skygge `0 1px 2px rgba(16,24,40,.04)`.
- Grid: `minmax(0,1fr) auto`, gap 10 lodret og 20 vandret.
- Hover: border `#C9CFDA` og `--shadow-2`.

**Klik og link (stretched link):**
- Hele kortet er klikbart, men der er ét link: agentens navn `<a class="card-link" href="/opkald/{id}?{ctx}">`, hvis `::after` dækker kortet.
- Ticket-linket ligger over med `position: relative; z-index: 1`, som i dag.
- Wrap ikke hele kortet i `<a>`. Det giver lange, uklare linknavne og forbyder ticket-linket.
- Fokus: når `.card-link` har `:focus-visible`, får **hele kortet** fokusringen (2 px accent, offset 2). Det kan fx laves ved at tegne ringen på `::after`.

**Venstre celle** (flex, wrap, gap 10 × 14):
1. **Avatar:**
   - 36 px rund med initialer i 13 px/600, hvid, `aria-hidden="true"`.
   - Initialer: to ord eller flere giver første bogstav i første og sidste ord ("Maria Christensen" bliver "MC"). Ét ord giver de to første bogstaver ("Sara" bliver "SA").
   - Agenter uden navn eller med "Bruger #123" får en neutral avatar: baggrund `#EEF1F6` med ikonet `circle-user` i `#4A5263`.
   - Farven er klassen `.av-1` til `.av-6`, valgt deterministisk ud fra agentnavnet (fx crc32 % 6), så samme agent altid har samme farve.
   - Paletten skal give hvid tekst ≥ 4,5:1. Designets `#0E9F6E`, `#0891B2` og `#DB2777` består ikke. Forslag: `#3B5BDB`, `#047857`, `#C2410C`, `#6D28D9`, `#0E7490`, `#BE185D`. Graphic-design bekræfter værdierne.
2. **Navn og meta**, lodret, `min-width: 0`:
   - Agentnavn (linket) i 15 px/600 `#12151C` med ellipsis og fuldt navn i `title`.
   - Meta-linje 13 px `#5B6475`: `↓ Ind` / `↑ Ud` (eller "—" ved ukendt retning) · kunde · tid.
   - Kunden er telefonnummeret som i dag, ikke maskeret. Mangler det på Adversus, udelades leddet.
   - Tiden er `<time>` med ISO og fuld dansk tid i `title`: "i dag 12:09", "i går 14:30", "6. nov. 12:09" (samme år) eller "6. nov. 2025 12:09".
   - Retningspilen er `aria-hidden` og har sr-only "Indgående" eller "Udgående".
3. **Tags** (6 px radius, padding 3 × 8, 12 px/600):
   - Kilde-tag: baggrund `#F1F3F6`, tekst `#4A5263`, "Zendesk" eller "Adversus".
   - **Zendesk:** "Ticket #1290 ↗" som link med `rel="noopener noreferrer" target="_blank"` og sr-only "(åbner Zendesk i en ny fane)", som `Ui::ticket`. Det er et tag-lignende link: baggrund `#EAF0FF`, tekst `#2B44AE`. Uden ticket udelades det.
   - **Adversus:** "Kampagne #12" og "Lead #12345" som neutrale tags (baggrund `#F1F3F6`). Hver udelades uden værdi. Har opkaldet `is_success = 1`, kommer "Succes-lead" (baggrund `#E8F5EE`, tekst `#146C43`, ikon `trophy` 12 px). Det vises i begge visninger (D-F7-8).

**Højre celle** (flex, gap 14, `justify-self: end`):
- Varighed "3:01" i 14 px/500 `#3A4252` med tabulære tal og sr-only "Varighed".
- Status-pille (§2.7).

**Uddrag** (`grid-column: 1 / -1`), 14 px, linjehøjde 1,55, `#3A4252`. Det klippes til **to linjer** med `-webkit-line-clamp: 2` og `line-clamp: 2` i CSS-filen. Der står sr-only "Uddrag: " foran.
- **Med søgning (`q`):** det eksisterende søgeuddrag (`Ui::snippet`) med `<mark>`. Det vinder over samtale-uddraget, fordi det viser, hvorfor kortet er et træf.
- **Med talere (H011):** samtalens begyndelse i rækkefølge, højst ca. 200 tegn. Ved hvert talerskift står etiketten:
  - `<strong class="who-agent">Agent:</strong>` i `#2B44AE`/600;
  - `<strong class="who-kunde">Kunde:</strong>` i `#B4561F`/600;
  - "Taler 1:" og "Taler 2:" i `#3A4252`/600, når rollen er ukendt (`Diarization::label`).

  Etiketten er tekst og ikke kun farve.
- **Uden talere:** de første ord af transcriptet (ca. 200 tegn) uden etiketter. Klippes der, ender teksten med "…".
- **Intet færdigt transcript:** ingen uddragslinje. Status-pillen siger hvorfor.

**`aria-label` bruges ikke på kortet.** Linkets navn er agentens navn plus en sr-only hale: ", indgående opkald, i dag kl. 12:09, Færdig". Så kan en skærmlæsers linkliste skelne kortene.

### 2.6 Pager
`<nav aria-label="Sider">`, centreret, gap 8.
- "← Forrige" og "Næste →" er sekundære knapper (hvid, border `#E3E6EC`, radius 9, padding 8 × 12, ikon `chevron-left`/`chevron-right`).
- Imellem står "Side 1 af 27" i 14 px `#5B6475`.
- Første og sidste side: knappen er en `<span aria-disabled="true">` med 50 % opacitet, som i dag.
- Links bærer `kilde`, `vis` og filtrene. Pageren vises ikke, når der kun er én side.

### 2.7 Status-pille
Pille: radius 999, padding 4 × 10, 12 px/600, `white-space: nowrap`, med en 6 px prik (`::before`, `currentColor`) foran.

| Tilstand | Tekst | Baggrund / tekst |
|---|---|---|
| `faerdig` | Færdig | `#E8F5EE` / `#146C43` |
| `transcriberer` | Transcriberer | `#EAF0FF` / `#2B44AE`, prikken pulserer (slås fra med `prefers-reduced-motion`) |
| `ventende` | **I kø** | `#EEF1F6` / `#4A5263` |
| lyd venter | Venter på lyd | `#FFF6E5` / `#7A4B00` |
| lyd fejlet/mangler | Lyd mangler | `#FFF6E5` / `#7A4B00` |
| `fejlet` | Fejlet | `#FDECEA` / `#A5221B` |
| `ikke_valgt` | Ikke valgt | transparent, 1 px border `#7B8495`, tekst `#5B6475`, hul prik |
| ukendt | Ukendt | `#EEF1F6` / `#4A5263` |

- **"Venter" bliver til "I kø"** med flaget tændt (designets ord, og det matcher kø-kortet). Det er den eneste ændrede statustekst.
- Afbildningen er den samme i liste og detalje (`Ui::status`).
- Spec'ens "I gang" og "Ingen lyd" er tolket som "Transcriberer" og "Venter på lyd"/"Lyd mangler". De eksisterende tekster skelner to forskellige lydtilstande og bevares (§12).

### 2.8 Samtalen (detalje)
Kortet "Samtale": hvid, border `#E8EBF0`, radius 16, padding 24 (16 under 600 px), lodret gap 12.
- Kortet er `<section aria-labelledby="transcript-h">` med `<h2 id="transcript-h">Samtale</h2>` i 17 px/700.
- Ved søgning står søgelinjen fra i dag under H2: "3 fund for «faktura» · Gå til første fund" eller "Ingen fund for «faktura» i dette transcript.".
- **Designets felt "Find i samtalen" bygges ikke.** Det er en ny søgefunktion, og den er uden for scope (§12).

**Valg af visning:**
- Har mindst ét segment en taler (`Diarization::label` er ikke null), vises **chatbobler**.
- Ellers vises **én kolonne**: H011 er slukket, talerne er ikke beregnet endnu, eller segmenterne mangler.

**Chatbobler:** `<ol class="transcript chat" id="transcript">`, ét `<li>` pr. segment.
- **Agent:** venstrestillet. Boblen har baggrund `#F1F4FF` og radius 4 14 14 14 (spids øverst til venstre). Etiketten "AGENT" er 12 px/700 `#2B44AE`, letter-spacing 0,02em.
- **Kunde:** højrestillet. Boblen har baggrund `#FFF4EC` og radius 14 4 14 14. Etiketten "KUNDE" er 12 px/700 `#B4561F`.
- **Taler N** (rolle ukendt): Taler 1, 3 og 5 står til venstre, Taler 2 og 4 til højre. Boblen har baggrund `#F1F3F6`, og etiketten "TALER 1" står i `#3A4252`. Der bruges ikke agent- eller kundefarver, for de ville påstå en rolle.
- **Segment uden taler** i et transcript med talere: samme side som det forrige segment, uden etiket.
- **Boblen:** maks. 78 % af kortets bredde (88 % under 600 px), padding 12 × 14.
- **Boblens top:** etiket + tidsstempel, gap 8, 4 px over teksten.
- **Boblens tekst:** 15 px, linjehøjde 1,6, `#12151C`. `<mark>` fremhæver søgeord. Tekst skal kunne markeres.
- **Etiketten er synlig ved talerskift.** Ved flere bobler i træk fra samme taler er etiketten sr-only, og afstanden er 4 px i stedet for 12 px. Skærmlæseren hører altid, hvem der taler.
- **Tidsstempel:** `<button type="button" class="ts" data-start="12.40" aria-label="Afspil fra 00:12">00:12</button>` i 12 px, tabulære tal, `#5B6475`. Designets `#7A8394` består ikke AA på boblerne. Uden afspillelig lyd er det en `<span>` (som i dag).
- **Klik på en boble (AK6):**
  - Med JS spoler et klik hvor som helst i boblen afspilleren til `data-start` og afspiller.
  - Har brugeren markeret tekst (selection er ikke tom), sker der intet.
  - Tastaturbrugere bruger tidsstempel-knappen.
  - Musemarkøren er `pointer` over boblen, kun når lyden kan afspilles.
- **Aktuel boble** (afspilleren står i dens tidsrum): 2 px indvendig ring i `#3B5BDB` (`box-shadow: inset`) og klassen `is-current` (findes i app.js). Siden ruller ikke automatisk.

**Én kolonne:** `<ol class="transcript">` som i dag, i nyt udseende.
- Grid `64px 1fr`. Tidsstempel-knappen står til venstre og teksten til højre, 15 px/1,6. Linjer adskilles med 1 px `#F1F3F6`.
- Uden segmenter: `transcript-plain` med afsnit som i dag.
- Under 600 px står tidsstemplet over teksten.

**Kopiér tekst:** resultatet er linje for linje i samme format som .txt (`Calls::segmentLine`): "[00:12] Agent: tekst" med taler, "[00:12] tekst" uden. Det gælder i begge visninger.

### 2.9 Afspillerkort
Mørkt kort i begge temaer: baggrund `#0F1218`, hvid tekst, radius 16, padding 20, lodret gap 14. `<section aria-label="Optagelse">`.

**Med JS og afspillelig lyd:**
- **Række 1:**
  - Play/pause-knap: 44 × 44 rund, baggrund `#7C9CFF`, ikon i `#0F1218` 18 px. Den har `aria-label="Afspil"` eller `"Pause"` og skifter med tilstanden (ikke `aria-pressed`).
  - Spolelinje: `<input type="range" min="0" max="{varighed i sek.}" step="1" aria-label="Spol" aria-valuetext="0:42 af 3:01">` med `flex: 1`.
  - Linjens udseende: spor 6 px `#3A4252`, afspillet del `#7C9CFF`, knop 14 px hvid.
  - Piletaster spoler 5 sek., og Page Up/Down spoler 30 sek. (`step` og JS).
- **Række 2:** "0:42" til venstre og "3:01" til højre, 13 px `#9AA4B6`, tabulære tal.
- **Række 3:** `<div role="group" aria-label="Afspilningshastighed">` med fem knapper "0,75×", "1×", "1,25×", "1,5×" og "2×" (`aria-pressed`, `data-rate`; 0,75 er ny).
  - Aktiv knap: baggrund `#262D3B`, hvid/600.
  - Inaktive knapper: transparent, `#9AA4B6`.
  - Padding 5 × 10, radius 8, min-højde 32 px (44 px under 600 px).
- **Række 4:** linket "Download lyd" (`/opkald/{id}/lyd`, `download`), 13 px `#A5B8FF`, ikon `download`. Det bevarer muligheden for at hente lyden, som i dag sker via browserens egen afspiller.
- **Bølgeform:** ingen (spec'en tillader en almindelig fremdriftslinje). Der hentes ikke lyd for at tegne den.

**Uden JS:**
- Kortet viser `<audio id="afspiller" controls preload="metadata">` i fuld bredde, med `color-scheme: dark` på kortet, og linket "Download lyd".
- Hastighed og den egne spolelinje er skjult (`data-js-only`, som i dag).
- Det native `<audio>` er altid i DOM'en, og JS skjuler dets `controls` og viser de egne kontroller.

**Lyd-tilstande** (i kortets plads, som lyst `.msg`-kort i stedet for det mørke kort; teksterne er som i dag):
- **Ikke valgt (Adversus):** info "Lyden er ikke hentet. Opkaldet er ikke på et succes-lead."
- **Venter:** info "Lyden er ikke hentet fra {Zendesk|Adversus} endnu. Det sker normalt inden for 10 minutter."
- **Fejlet:** fejl "Lyden kunne ikke hentes fra {kilde} (N forsøg)." + "Teknisk besked". For Zendesk med ticket står desuden "Optagelsen kan måske afspilles direkte i ticket #N.".
- **Mangler på disk:** fejl "Lydfilen mangler på serveren. Kontakt en administrator."

**Afspilningsfejl i browseren** (`error`-hændelse, eller `play()` afvises):
- Kortet viser 13 px `#FFA098` "Lyden kunne ikke afspilles.".
- Play, spolelinje og hastighed bliver `disabled`, og tidsstemplerne bliver inaktive (som app.js i dag).
- `#live` siger det samme.

**Indlæsning:** før metadata er hentet, står der "–:––" i stedet for varigheden. Afspiller man, før lyden er klar, viser tidsfeltet "Indlæser …", og knappen forbliver "Pause".

### 2.10 Metadata-gitter
Kort: hvid, border `#E8EBF0`, radius 16, padding 20.
- `dl.meta` som grid med to kolonner (`repeat(2, minmax(0,1fr))`), gap 16.
- `dt` er 12 px/600 `#5B6475` med versaler via CSS. `dd` er 15 px/600 `#12151C`, 2 px under.

**Felter som i dag (F004):**
- **Zendesk:** Dato og tid · Kilde · Retning · Kunde · Agent · Varighed · Ticket · Status.
- **Adversus:** Dato og tid · Kilde · Retning · Kunde (kun med værdi) · Agent · Varighed · Kampagne (kun med værdi) · Lead (kun med værdi, "#12345 · Succes-lead") · Status.

**Designets "Taletid agent/kunde %" kommer ikke med.** Det er et nyt felt, og spec'en siger "som i dag" (§12).

### 2.11 Claude-boks
Den vises kun, når connectoren findes (`F003_mcp` tændt).
- Kort: hvid, 1 px **stiplet** border `#C9CFDA`, radius 16, padding 18, 14 px `#5B6475`, linjehøjde 1,5. `<aside aria-labelledby="claude-boks-h">`.
- Overskrift: `<h2 id="claude-boks-h">Spørg Claude om dette opkald</h2>`, 14 px/600 `#12151C`, ikon `message-square` 16 px.
- Tekst: "Brug Whisper i Claude, og skriv fx: Opsummér opkald {id} og find aftaler." Sætningen fra "Opsummér" er `<q>` eller `<code>`, så den kan kopieres.
- Link: "Sådan forbinder du Claude" til `/konto#claude-h`.
- Ingen ny funktion.

### 2.12 Knapper, felter og kort (alle sider)
- **Primær knap:** baggrund `#3B5BDB`, hover `#2B44AE`, hvid tekst 14 px/600, radius 10, padding 10 × 16, min-højde 40 px (44 px under 600 px).
- **Sekundær knap:** hvid, border `#DCE0E8`, tekst `#12151C`/600, hover `#F6F7F9`.
- **Fare-knapper:** som i dag med samme radius.
- **Felter:**
  - Label 13 px/600 `#3A4252` over feltet med 6 px luft.
  - Input og select: højde 44 px, radius 10, border `#DCE0E8`, baggrund hvid, 15 px.
  - Hjælpetekst 13 px `#5B6475`. Fokus er den globale ring.
- **Kort** (Konto, Brugere, formularer): hvid, border `#E8EBF0`, radius 16, padding 24 (16 under 600 px), skygge `0 1px 2px rgba(16,24,40,.04)`.
- **Overskrifter:**
  - H1 i Inter Tight 30 px/700 (28 px på detaljen, 24 px under 600 px).
  - H2 i 17 px/700 Inter.
  - Brødtekst i Inter 14–15 px/400–500.
- **Links:** `#3B5BDB`, hover `#2B44AE`.

### 2.13 Konto og Brugere (ingen ændret funktion)
- **Konto:** H1 "Konto". Under den lodrette kort med maks. 760 px og gap 16: "Adgangskode", "Forbind Claude" (F006) og "Claude Desktop (MCP)", når de findes. Indholdet er som i dag. Kodeblokke og hemmeligheder har `#F6F7F9`-baggrund, radius 10 og mono.
- **Brugere:**
  - Sidehoved med H1 "Brugere" til venstre og den primære knap "Opret bruger" til højre.
  - Under det en tabel i et kort: header 12 px/600 `#5B6475` med versaler, rækker 14 px med 1 px `#F1F3F6` mellem.
  - Under 600 px bliver rækkerne til kort som i dag (`data-label`).
- **Opret bruger, Rediger bruger, Bekræft og Engangskode:** smalle kort (maks. 480 px) i rammen. Tilbage-linket "Til listen" står som i dag.

---

## 3. States

### Listen
- **Standard:** layout §1.4.
- **Indlæsning:** ingen, fordi siden renderes på serveren. "Transcriberer"-prikken pulserer.
- **Tom Zendesk uden filtre:** H2 "Ingen Zendesk-opkald endnu". Tekst "Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter.".
- **Tom Adversus, "Succes og valgte", uden filtre:** H2 "Ingen Adversus-opkald endnu". Tekst "Opkald på succes-leads og opkald, der er valgt til transcription, dukker op her inden for 10 minutter.". Handling: sekundær knap "Vis alle opkald" (`/?kilde=adversus&vis=alle`).
- **Tom Adversus, "Alle opkald", uden filtre:** H2 "Ingen Adversus-opkald endnu". Tekst "Opkald med optagelse fra Adversus dukker op her inden for 10 minutter.".
- **Tom med filtre:** H2 "Ingen opkald matcher". Tekst "Prøv en længere periode, færre søgeord eller en anden agent.". Handling: "Nulstil filtre" (bevarer `kilde` og `vis`).
  - På Adversus "Succes og valgte" kommer desuden "Søg i alle opkald".
  - BØR: har den anden fane træf, står der desuden "Søgningen giver N træf under Adversus." (eller "… under Zendesk.") med linket "Se dem".
- **Tom-kortets form:** hvidt kort, radius 16, padding 40, centreret, ikon `inbox` 24 px i en cirkel på 48 px (`#F1F3F6`).
- **Ikke sat op:** tom-tilstanden plus info-boksen fra §2.4.
- **Fejl:** kildebeskederne (§2.4), kun på egen fane. Markøren står på den anden fane. Motorfejl står i kø-kortet.
- **Delvise data:**
  - Kunde mangler: leddet udelades på kortet.
  - Ukendt retning: "—".
  - Agent uden navn: "Bruger #123" med neutral avatar.
  - Ingen ticket, kampagne eller lead: tagget udelades.
  - Intet transcript: intet uddrag.
- **Disabled:** kun Forrige og Næste ved første og sidste side. Faner og piller er aldrig disabled.
- **Mange data:** pager på 50 pr. side og zip-loft på 2000 pr. fane, som i dag. Fanetal som "12.345" udvider fanen.

### Detaljen
- **Transcript-tilstande:** teksterne er som i dag og står i Samtale-kortet: "Transcriptet laves, når lyden er hentet.", "Transcriptet er på vej …", "Transcriptet laves lige nu …", fejlet + "Teknisk besked", `TRANSCRIPT_MISSING` og "Der blev ikke fundet tale i optagelsen.".
- **"Transcribér dette opkald":** F004-formularen står i Samtale-kortet som i dag, med `data-busy="Sætter i kø …"`.
- **Handlinger i sidehovedet:** "Kopiér tekst" og "Download .txt" vises kun, når der er et færdigt transcript med tale. "Kopiér tekst" er skjult uden JS (som i dag). Efter kopiering skifter knappen til "Kopieret ✓" i 2 sek., og `#live` siger "Kopieret til udklipsholderen.".
- **Flash efter handling** ("sat i kø" og idempotent info): står under tilbage-linket som i dag.
- **Talere:** ingen talere giver én kolonne. Kun ukendte roller giver Taler N-bobler.

### Ramme
- **Kø-kort:** se tabellen i §2.1.
- **Kan status ikke læses** (fx fejl i loggen): kortet viser "Ukendt status" med grå prik. Der vises aldrig "Ikke sat op" på et gæt (AK7).
- **Menu:** lukket som standard med JS. Uden JS er den altid synlig (§1.2).

---

## 4. User flow
1. **Zendesk-hverdag:** `/` viser Zendesk-fanen. Brugeren søger "faktura" og trykker Enter, ser træf med fremhævet uddrag og klikker på et kort. Detaljen viser chatbobler og "3 fund". Klik på en boble afspiller fra det sted. "Tilbage til opkald" fører tilbage til samme fane, søgning og side.
2. **Adversus:** brugeren klikker "Adversus 91" (eller bogmærket `/?kilde=adversus`) og ser "Succes og valgte". Fra et kort går han til detaljen og tilbage igen og står stadig i Adversus med samme visning.
3. **Manuel transcription:** Adversus → "Alle opkald" → kort med "Ikke valgt" → detalje → "Transcribér dette opkald". Efter PRG kommer han tilbage til detaljen med flash, og ctx bærer `kilde` og `vis`.
4. **Søg på tværs:** brugeren søger på Zendesk, og fanen Adversus viser "37". Et klik giver samme søgning og periode i Adversus, og agentfilteret er nulstillet.
5. **Admin ser et problem:** Adversus-fanen har en markør. Et klik viser rød prik og boksen "Seneste hentning fra Adversus stoppede." med "Teknisk besked".
6. **Motoren står stille:** kø-kortet viser "Ingen fremdrift i 45 min." med gul prik, og på mobil står en gul prik i topbaren. Menuen viser hele kortet.

**Fejlveje:**
- Ugyldig `kilde` giver Zendesk uden fejl.
- Ugyldigt CSRF følger det eksisterende mønster.
- Lyden fejler i browseren: §2.9.

**Tilbage og genindlæs** er ufarlige, fordi alt er GET med tilstand i URL'en. Den eneste POST, "Transcribér", bruger PRG. bfcache-genopretningen i app.js gælder også, og menuen åbnes lukket igen.

**Tilbage-link:** fanen tages fra **opkaldets egen kilde**, ikke fra ctx (D-F7-9). For Zendesk fjernes `vis`.

---

## 5. Form design
- **Søgekortet:**
  - Felterne står i rækkefølgen søgning, dato, agent, retning og Søg. Intet felt er påkrævet.
  - Der er ingen validering ud over i dag. "Til-datoen er før fra-datoen." er info under fanerækken efter afsendelse.
  - "Søg" er altid aktiv.
- **Datopanelet:** Fra og Til har synlige labels og bliver i samme `<form>`. "Brug datoer" sender hele formularen.
- **Konto, Brugere og login:** felter, rækkefølge, validering og fejlplacering er uændrede (fejlboks øverst i kortet via `Ui::message`). Kun udseendet ændres (§2.12).

---

## 6. Responsive behavior

| Bredde | Ramme | Liste | Detalje |
|---|---|---|---|
| ≥ 1180 | sidebar 248 | som §1.4 | to kolonner (§1.5) |
| 900–1179 | sidebar 248 | som §1.4 | én kolonne, afspilleren sticky `top: 0`, metadata i "Detaljer" |
| 600–899 | topbar 56 + menupanel | søgefeltet fylder første række, pillerne ombrydes | én kolonne, afspilleren sticky `top: 56px` |
| < 600 | topbar | se nedenfor | se nedenfor |

**Liste under 600 px:**
- H1 er 24 px. Statuslinjen står under H1. Zip-knappen står under linjen i fuld bredde med teksten "Download .zip".
- Søgekortet: søgefeltet fylder en hel række. Pillerne står på næste række, ombrudt, hver 44 px høj. "Søg" står i fuld bredde nederst. Datopanelet ligger i flowet.
- Fanerne fylder hele bredden som to lige brede halvdele, 44 px høje. Navn og tal står på én linje (`nowrap`), og padding er 8. Ved 320 px er "Adversus 12.345" + markør ca. 135 px mod en halvdel på ca. 140 px, så det kan lige være der.
- Succes-vælgeren står på en egen række under fanerne med to lige brede piller.
- Kort:
  - Padding 14.
  - Avatar 32 px.
  - Højre celle (varighed og status) rykker til en egen række under meta-linjen, venstrestillet.
  - Tags ombrydes.
  - Uddraget er stadig to linjer.
- Pageren: "Forrige", "Side x af y" og "Næste" på én linje med 44 px-knapper. Ved 320 px skjules ordene, så kun pilene står tilbage, og de bærer sr-only "Forrige side" og "Næste side".

**Detalje under 600 px:**
- H1 er 22 px.
- Chips ombrydes.
- "Kopiér tekst" og "Download .txt" står i fuld bredde under hinanden ("Download .txt" er allerede den smalle tekst).
- Boblerne er maks. 88 % brede.
- Afspilleren er kompakt: række 1 (play, spolelinje) og række 3 (hastighed) står, tiderne flytter til højre for spolelinjen ("0:42 / 3:01"), og "Download lyd" flytter ned under samtalen. Ikke sticky ved `max-height: 600px`.

**Generelt:**
- **Ingen vandret scroll ved 320 px** (AK9). Lange ord, URL'er og telefonnumre i transcripts brydes (`overflow-wrap: anywhere` i bobler og uddrag).
- Mål: 44 × 44 px under 600 px for alle links og knapper (som i dag). Ellers mindst 24 × 24.
- Ingen swipe-bevægelser.

---

## 7. Copy / microcopy (dansk; appen har ingen sprogfil)

| Sted | Tekst |
|---|---|
| Skip-link | Spring til indhold |
| Hovedmenu (aria-label) | Hovedmenu |
| Menupunkter | Opkald · Brugere · Konto |
| Menu-knap | Menu |
| Log ud | Log ud |
| Roller | Administrator · Bruger |
| Kø-kort | Transcription · {N} i kø · Ingen i kø · {N} venter på lyd |
| Motorlinje | Transcriberer nu · Klar · Ingen fremdrift i {tid} · Ikke sat op. Kør cron/whisper-setup.sh. (admin) · Nye opkald bliver ikke transcriberet lige nu. (bruger) · Ukendt status |
| Topbar, sr-only | Transcription: ikke sat op · Transcription: ingen fremdrift |
| H1 liste | Opkald |
| `<title>` liste | Opkald · Zendesk / Opkald · Adversus |
| Zip | Download transcripts (.zip) · (< 600 px) Download .zip |
| Zip-hjælp | Alle færdige transcripts i filtret, højst 2000. / zip-filen får de 2000 nyeste færdige |
| Søg, label (sr) | Søg i transcripts |
| Søg, placeholder | Søg i alt, der er sagt — fx opsigelse, flytning, faktura |
| Søg, hjælp (sr) | Alle ord skal indgå. Ord på mindst 3 bogstaver giver det bedste resultat. |
| Dato-pille | Alle datoer · I dag · Seneste 7 dage · Denne måned · {dd.mm.åååå}–{dd.mm.åååå} · Fra {dato} · Til {dato} |
| Datopanel | Fra · Til · Brug datoer |
| Agent-pille | Alle agenter (label: Agent) |
| Retnings-pille | Ind og ud · Indgående · Udgående (label: Retning) |
| Nulstil | Nulstil filtre |
| Søg-knap | Søg |
| Faner | Zendesk · Adversus (+ sr " opkald") |
| Fanemarkør (sr) | , hentningen er stoppet · , har en advarsel |
| Succes-vælger | Succes og valgte · Alle opkald (gruppe-label: Vis) |
| Opsummering | Viser {a}–{b} af {N} opkald |
| Tip | Tip: Ord på 1–2 bogstaver giver ofte for mange eller ingen træf. |
| Kort, retning | ↓ Ind · ↑ Ud (sr: Indgående / Udgående) |
| Kort, tid | i dag 12:09 · i går 14:30 · 6. nov. 12:09 · 6. nov. 2025 12:09 |
| Kort, tags | Zendesk · Adversus · Ticket #{n} · Kampagne #{n} · Lead #{n} · Succes-lead |
| Kort, uddrag | Agent: · Kunde: · Taler {n}: (sr-præfiks "Uddrag: ") |
| Status | Færdig · Transcriberer · I kø · Venter på lyd · Lyd mangler · Fejlet · Ikke valgt · Ukendt |
| Pager | Forrige · Næste · Side {x} af {y} (sr ved 320 px: Forrige side / Næste side) |
| Tomme tilstande | se §3 |
| Detalje, tilbage | Tilbage til opkald |
| Detalje, H1 | {Agent} · indgående opkald / {Agent} · udgående opkald / {Agent} · opkald |
| Detalje, `<title>` | Indgående opkald · 06.11.2026 kl. 12:09 (som i dag) |
| Detalje, chips | 6. nov. 2026, 12:09 · 3:01 min · {Zendesk/Adversus} · Ticket #{n} ↗ · Succes-lead · {status} |
| Detalje, handlinger | Kopiér tekst · Kopieret ✓ · Download .txt |
| Samtale | Samtale · AGENT · KUNDE · TALER {n} · {n} fund for «{q}» · Gå til første fund · Ingen fund for «{q}» i dette transcript. |
| Tidsstempel (aria) | Afspil fra {mm:ss} |
| Afspiller | Optagelse (sektion) · Afspil · Pause · Spol · Afspilningshastighed · 0,75× · 1× · 1,25× · 1,5× · 2× · Download lyd · Indlæser … · Lyden kunne ikke afspilles. |
| Metadata | Detaljer · Dato og tid · Kilde · Retning · Kunde · Agent · Varighed · Ticket · Kampagne · Lead · Status |
| Claude-boks | Spørg Claude om dette opkald · Brug Whisper i Claude, og skriv fx: Opsummér opkald {id} og find aftaler. · Sådan forbinder du Claude |
| Lyd- og transcript-tilstande, kildebeskeder | uændrede tekster (§2.4, §2.9, §3) |

---

## 8. Accessibility (WCAG 2.2 AA)

**Fokusrækkefølge, liste:**
1. Skip-link.
2. Menu-knap (under 900 px).
3. Mærke og hovedmenu.
4. Log ud.
5. Zip.
6. Søgefelt.
7. Dato-pille, agent-pille, retnings-pille, Nulstil og Søg.
8. Faner og succes-vælger.
9. "Teknisk besked".
10. Kort: agentlink, så ticket-link.
11. Pager.

Kø-kortet indeholder ingen fokuserbare elementer.

**Fokusrækkefølge, detalje:**
1. Tilbage.
2. Ticket-chip.
3. Kopiér og Download .txt.
4. Ved ≥ 1180 px: samtalen ("Gå til første fund", "Transcribér", tidsstempler) og derefter afspiller (play, spol, hastighed, download lyd), "Detaljer" og Claude-link. Ved < 1180 px kommer afspilleren først, så samtalen. DOM-rækkefølgen er den samme som den visuelle: afspiller-asiden står efter samtalen i DOM'en på bred skærm og før den på smal. Hvis det ikke kan lade sig gøre uden dobbelt markup, skal afspilleren stå før samtalen i DOM'en i begge tilfælde, og den visuelle placering til højre ændrer ikke rækkefølgen. Ingen `order`, der bytter fokusrækkefølgen.

**Landmarks:** `nav` "Hovedmenu", `main`, `nav` "Kilde", `search` (formularen), `nav` "Sider", `section` "Optagelse" og `section` "Samtale". Kø-kortet er en `section` med H2.

**Overskrifter:** H1 pr. side. Kø-kortet har H2 "Transcription". Detaljen har H2 "Samtale" og "Spørg Claude om dette opkald". Kortene i listen er ikke overskrifter.

**Faner** er links med `aria-current="page"`, ikke ARIA-tabs (D-F7-2). Den aktive fane skelnes ved vægt (600 mod 500) og hvid flade, ikke kun farve.

**Piller:**
- Selects og `details` er native elementer med sr-labels.
- Tilstanden "sat" skelnes ved vægt og farve.
- Dato-pillens summary-tekst er den aktuelle periode, fx "Periode: I dag", hvor "Periode: " er sr-only.

**Menu-knap:** `aria-expanded` og `aria-controls`. Esc lukker, og fokus går tilbage til knappen.

**Afspiller:**
- Play/pause skifter `aria-label`.
- Spolelinjen er en native `range` med `aria-valuetext` "0:42 af 3:01", som opdateres højst én gang i sekundet.
- Hastighedsknapperne har `aria-pressed`.
- Fejl meldes i `#live`.
- Der er ingen autoplay.

**Bobler:** etiketten (Agent, Kunde eller Taler N) er altid i tekst, synlig eller sr-only. Farve og side er ekstra hjælp. Tidsstemplet er en knap, så hver boble kan nås med tastaturet.

**Kontrast:** alle tekstpar ≥ 4,5:1 og ikoner og ikke-tekst ≥ 3:1, i begge temaer. Kendte rettelser i forhold til designet:
- Tal på faner og tidsstempler bruger `#5B6475` i stedet for `#7A8394`.
- Avatarpaletten skal mørkes (§2.5).
- Prikker er `aria-hidden` med tekst ved siden af.

**Fokusring:**
- Globalt 2 px `#3B5BDB`, offset 2.
- I sidebaren, topbaren og afspillerkortet (mørk baggrund) er ringen 2 px `#A5B8FF`.
- På opkaldskortet omslutter ringen hele kortet.

**Bevægelse:** `prefers-reduced-motion` slår pulsen og overgangene fra (findes).

**Zoom og reflow:** 400 % zoom og 320 px giver én kolonne uden vandret scroll (1.4.10). Tekstafstand kan øges uden afklipning, undtagen det bevidste uddrag på to linjer, hvor hele teksten ligger på detaljen.

**Ingen tastaturgenveje.**

---

## 9. Design decisions
- **D-F7v2-1 Ny ramme med mørk sidebar og topbar under 900 px.** Det er det godkendte design. Panelet skubber indholdet ned i stedet for at lægge sig over det, så der er ingen fokusfælde. Uden JS er menuen altid synlig.
- **D-F7v2-2 Kø og motor flytter til et kort i rammen.** Det er fælles for begge kilder og hører derfor ikke til en fane. AFVIGELSE fra F005 §3.3 og v1 D-F7-5: motorboksen bliver hverken til en boks eller en linje på listen, men til en linje i kø-kortet.
- **D-F7v2-3 Faner som segmenteret kontrol.** AFVIGELSE fra graphic-design F007 v1 (understregede faner). Det er ejerens godkendte design. Niveauerne skelnes stadig på form: fanerne er en grå skinne med en hvid aktiv flade, succes-vælgeren er grønne og hvide piller, og filtrene er pille-felter i søgekortet.
- **D-F7v2-4 Kort i stedet for tabel.** Det er designet. Kortet bærer uddraget og kildens felter uden en tom kolonne.
- **D-F7v2-5 Stretched link på kortet.** Det giver ét meningsfuldt link pr. kort, og ticket-linket kan stadig klikkes.
- **D-F7v2-6 Piller er native `select`/`details` uden auto-submit.** De virker uden JS og overholder WCAG 3.2.2. Chips udgår, fordi pillerne viser de aktive filtre.
- **D-F7v2-7 Søgeuddraget vinder over samtale-uddraget, når der søges.** Det viser, hvorfor kortet er et træf.
- **D-F7v2-8 Bobler pr. segment med tidsstempel-knap.** Tastaturbrugere spoler via knappen, musebrugere via boblen, og tekst kan stadig markeres.
- **D-F7v2-9 Taler N i neutrale farver.** Agent- og kundefarver bruges kun, når rollen er kendt.
- **D-F7v2-10 "Venter" bliver "I kø".** Det er designets ord, og det matcher kø-kortet. De øvrige statustekster er uændrede.
- **D-F7v2-11 "Download lyd"-link.** Den egne afspiller fjerner browserens menu, så muligheden bevares eksplicit. Det er ingen ny funktion.
- Gælder fortsat fra v1: D-F7-1 (kilde i URL), D-F7-2 (links frem for ARIA-tabs), D-F7-4 (beskeder på egen fane med markør), D-F7-6 (antal = klikresultat), D-F7-7 (fanelinks bevarer periode, retning og søgning), D-F7-8 (Succes-lead i begge visninger) og D-F7-9 (tilbage-link fra opkaldets kilde).

## Nye design-beslutninger i dette brief

### App-ramme med sidebar og statuskort
- **Hvad:** mørk sidebar på 248 px med hovedmenu, et fælles driftsstatus-kort og brugerblok. Under 900 px bliver den til en topbar med et menupanel, der skubber indholdet.
- **Hvorfor:** ejerens godkendte redesign. Fælles status har en fast plads uden at fylde på siderne.
- **Gælder for:** alle indloggede sider fremover.
- **Anbefaling:** Vedtag som standard? JA

### Liste som kort med stretched link
- **Hvad:** rækker med flere felter og uddrag vises som kort med ét link (titlen), hvis klikflade dækker kortet. Sekundære links ligger over.
- **Hvorfor:** læsbart, mobilvenligt og et klart linknavn.
- **Gælder for:** fremtidige lister med uddrag.
- **Anbefaling:** Vedtag som standard? JA

### Filtre som pille-felter uden auto-submit
- **Hvad:** native `select`/`details` stylet som piller i søgekortet. De anvendes med "Søg", og pillen viser sin værdi.
- **Hvorfor:** virker uden JS og overholder WCAG 3.2.2.
- **Gælder for:** alle filtrerede lister.
- **Anbefaling:** Vedtag som standard? JA

### Samtale som bobler pr. taler
- **Hvad:** Agent til venstre og Kunde til højre med etiket i tekst. Ukendte roller får neutrale farver. Fallback er én kolonne.
- **Hvorfor:** hurtigere at læse, hvem der sagde hvad (H011).
- **Gælder for:** alle visninger af transcripts med talere.
- **Anbefaling:** Vedtag som standard? JA

---

## 10. Til Team Lead
1. **Elementer fra designet, der IKKE bygges.** De er nye funktioner, og spec'en nævner dem ikke. Ejeren kan bede om dem i et nyt F-nummer:
   - "Find i samtalen" (ny søgning, uden for scope);
   - statusfiltrene "Alle / Færdige / I kø";
   - "Taletid agent/kunde %";
   - tæller ved "Opkald" i menuen;
   - menupunktet "Forbind Claude" (det ligger under Konto, og spec'ens menu er Opkald, Konto, Brugere og Log ud);
   - maskerede telefonnumre;
   - bølgeform;
   - fremdriftsbjælke i kø-kortet.
2. **Statustekster:** spec'ens "I gang" og "Ingen lyd" er tolket som de eksisterende "Transcriberer", "Venter på lyd" og "Lyd mangler". Kun "Venter" bliver "I kø" (D-F7v2-10). Ret mig, hvis ejeren vil have spec'ens ord.
3. **graphic-design bør lave `graphic-design/F007_visual_v2.md`.** Designet findes kun i lyst tema. Der mangler:
   - mørkt tema for main, kort, bobler, piller og tags (sidebaren og afspilleren er mørke i begge; i mørkt tema skal sidebaren adskilles fra `--bg` med 1 px border);
   - endelig avatarpalet (AA med hvid tekst);
   - kontrolberegning af alle par;
   - fokusringen på mørke flader;
   - fontfilernes vægte og subset.

   Værdierne her er designets lyse værdier, rettet, hvor de ikke bestod AA.
4. **Ydelse (engineering, ikke design):**
   - Kø-kortet står på alle sider og kræver status pr. sidevisning.
   - Uddrag med talere kræver de første segmenter for 50 opkald.
   - Den inaktive fanes antal kræver én ekstra COUNT, med FULLTEXT ved søgning. Fallback fra v1 §13.3 gælder: hvis tallet er for dyrt, intet tal på den inaktive fane, mens `q` er sat, og aldrig et ufiltreret tal.
5. **Flaget slukket skal være byte-identisk.** Nye CSS-regler og `@font-face` må ikke påvirke F005-siderne (§0). Det bør testes med en snapshot-sammenligning.
6. **"Sidst valgte fane"** er stadig tolket som "den i URL'en, ellers Zendesk" uden cookie (v1 §13.2).
7. **Ny JS i app.js:** menu-knap, afspiller (play/pause, range, hastighed inkl. 0,75, fejl), klik på boble, Esc og klik udenfor på `details` (KAN) og kopiér i .txt-format. Ingen inline handlers, kun data-attributter. Det er relevant for security-critic.
8. **MCP:** ingen ændring i UI'et.

Relevante filer:
- `C:\ClaudeCoworkers\whisper.xanouso.com\product-ai\F007_v2.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\tasks\impact-F007.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\ui-ux\F007_design_v1.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\graphic-design\F007_visual_v1.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\Ui.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\UiCalls.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\UiPages.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\Calls.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\Transcription\Diarization.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app.css`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app.js`
- Designkilde (ikke i git): `C:\Users\Albers\AppData\Local\Temp\claude\a531192545503e18\ea325110-936a-4af6-9b77-461f66b7f58a\scratchpad\design\project\v2-Opkald.dc.html` og `C:\Users\Albers\AppData\Local\Temp\claude\a531192545503e18\ea325110-936a-4af6-9b77-461f66b7f58a\scratchpad\design\project\v2-Detalje.dc.html`