# F007 — Visuelt design-brief: nyt udseende (ramme, liste og detalje), v2
# Status: READY_FOR_IMPLEMENTATION

Jeg har læst følgende i hovedmappen (main @ 38c7c03; `app.css` og `Ui.php` er identiske med main):
- `agents/graphic_designer.md` og `product-ai/F007_v2.md` (bindende).
- Begge godkendte artboards, `v2-Opkald.dc.html` og `v2-Detalje.dc.html` fra Team Leads scratchpad. De ligger ikke i git, så alle deres værdier er gengivet her.
- `homedir/public_html/assets/app.css`, `homedir/src/Ui.php` (`ICONS`, `icon()`, `status()`, `brandMark()`), CSP'en i `homedir/src/App.php:579`, `graphic-design/F007_visual_v1.md` og `graphic-design/F005_visual_v1.md`.

`DESIGN_LOG.md` og `PRD.md` findes ikke, så de vedtagne valg i F005-, F004- og F007 v1-briefene er rammen. `ui-ux/F007_design_v2.md` findes endnu ikke. Hvilke elementer der findes, og hvordan de opfører sig, bestemmer UI/UX v2. Briefet her bestemmer, hvordan de ser ud.

**Grundlag:**
- Redesignet afløser F005's udseende og F007 v1's understregede faner, når flaget er tændt. v1-briefene står som historik.
- Alle hex-værdier herunder er de bindende. Hvor jeg afviger fra artboardet, er det markeret **AFVIGELSE FRA DESIGN**, og grunden står ved siden af (som regel AA-kontrast).
- To ting går imod vedtagne F005-beslutninger og er markeret **AFVIGELSE FRA DESIGN_LOG/F005**: webfont i stedet for systemfont, og et nyt logomærke.

**Rammer, der gælder hele briefet:**
- **CSP:** ingen `style=""` noget sted, heller ikke på SVG. Farver kommer fra klasser og `currentColor`. Ingen `data:`-URI'er. Fonte og CSS hentes kun fra `'self'`: der er ingen `font-src`, så `default-src 'self'` gælder.
  - Avatarfarver, fremdriftslinjer og bredder sættes derfor med klasser eller indbyggede elementer (`<input type="range">`, `<progress>`) og aldrig med inline-bredde.
  - JS må gerne sætte `element.value`. Bruger engineeren `el.style.x` (CSSOM), blokerer CSP det ikke, men det er ikke nødvendigt nogen steder i dette brief.
- **Flaget slukket (AK8):** alle sider skal se ud præcis som før F007. Jeg anbefaler, at v2 får sit eget stylesheet (fx `assets/app-v2.css`), som kun linkes, når `F007_kilder` er tændt, og at `@font-face` kun står i det. Så er CSS-effekten med flaget slukket byte-identisk. Arkitekturen er engineerens valg, men kravet er: med flaget slukket må ingen font hentes og intet token ændres.

---

# Fonte (selv-hostet)

**AFVIGELSE FRA DESIGN_LOG/F005:** F005 vedtog "System-fontstak, ingen webfont". Spec v2 kræver Inter og Inter Tight, og ejeren har godkendt designet. F005-beslutningen gælder fortsat, når flaget er slukket.

| Familie | Vægt | Bruges til | Fil (i `homedir/public_html/assets/fonts/`) |
|---|---|---|---|
| Inter | 400 | brødtekst, boblers tekst, metalinjer | `inter-latin-400-normal.woff2` |
| Inter | 500 | felter, inaktive faner og menupunkter, varighed, filterpiller | `inter-latin-500-normal.woff2` |
| Inter | 600 | knapper, navne, piller, tags, labels, aktive faner | `inter-latin-600-normal.woff2` |
| Inter | 700 | bobleroller (AGENT/KUNDE), h3 hvis nødvendigt | `inter-latin-700-normal.woff2` |
| Inter Tight | 700 | h1, h2, logonavn og kø-tal | `inter-tight-latin-700-normal.woff2` |

**Hentning og placering:**
- Filerne tages fra npm-pakkerne `@fontsource/inter` og `@fontsource/inter-tight` (version 5.x, mappen `files/`) og committes i repoet. De må aldrig hentes fra Google Fonts eller et CDN under kørsel.
- Inter Tight 600 (som artboardet linker) droppes. Alle overskrifter er 700, så det sparer en fil.
- I alt er det 5 filer på ca. 110–125 KB.
- Kun subsettet `latin`. Det dækker æ, ø, å, é, ü, —, “ ”, •, ×, ↑ og ↓. Andre tegn (fx ł eller ş i kundenavne) falder tilbage på systemfonten via `unicode-range`. Det er acceptabelt.
- `unicode-range` kopieres ordret fra pakkens `index.css` til hver `@font-face`.
- **Licens:** SIL Open Font License 1.1. Pakkernes `LICENSE` kopieres ordret til `assets/fonts/OFL-Inter.txt` og `assets/fonts/OFL-Inter-Tight.txt` (copyright "The Inter Project Authors"). Filerne må ikke omdøbes til et andet familienavn.

**`@font-face` for hver fil:**
- `font-family: "Inter"` eller `"Inter Tight"`, `font-style: normal`, `font-weight: <vægt>`, `font-display: swap`.
- `src: url("/assets/fonts/<fil>") format("woff2")` og pakkens `unicode-range`.

**Preload:**
- Kun Inter 400 og 600, i `<head>` på sider med flaget tændt.
- `<link rel="preload" href="/assets/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>`. `crossorigin` skal med, også på samme origin.
- Engineeren tjekker, at serveren sender `Content-Type: font/woff2` og lang cache.

**Fontstakke (tokens):**
- `--font-sans: "Inter", "Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;`
- `--font-display: "Inter Tight", "Inter", "Segoe UI Variable Display", "Segoe UI", system-ui, sans-serif;`
- `--font-mono` er uændret, men bruges ikke længere til tidsstempler (se Typografi).

---

# Color usage

## Token-sæt v2

Lyst tema står i `:root`, mørkt i `@media (prefers-color-scheme: dark) { :root { … } }`, som i dag. `color-scheme: light dark` og `accent-color: var(--accent)` bevares. Navnene genbruger de eksisterende, hvor betydningen er den samme, så fælles komponenter (`.msg`, `.badge`, knapper) følger med.

### Flader, kanter og tekst
| Token | Lyst | Mørkt | Bruges til |
|---|---|---|---|
| `--bg` | #F6F7F9 | #0E1117 | sidebaggrund |
| `--surface` | #FFFFFF | #161A22 | kort, søgelinje, paneler, sekundær knap, inaktiv filterpille |
| `--surface-2` | #F1F3F6 | #1D222C | kilde-tag, sat filterknap, hover på rækker, readonly-felt |
| `--track` | #ECEEF2 | #1A1F28 | sporet bag de segmenterede faner |
| `--segment-on` | #FFFFFF | #2A3140 | den aktive fane |
| `--border` | #E8EBF0 | #262C38 | kort og paneler |
| `--border-control` | #DCE0E8 | #333B4A | knapper, filterpiller, meta-chips, pager, hover på kort |
| `--border-dashed` | #C9CFDA | #424B5C | "Spørg Claude"-kortet og tom tilstand (stiplet) |
| `--border-input` | #7B8495 | #6A7386 | kant på rigtige tekstfelter (Konto, Brugere, login) |
| `--input-bg` | #FFFFFF | #0E1117 | tekstfelter |
| `--text` | #12151C | #E6E9EF | overskrifter, navne, brødtekst |
| `--text-2` | #3A4252 | #C3C9D4 | uddrag, varighed, tekst i filterpiller og meta-chips |
| `--text-muted` | #5B6475 | #9AA3B2 | statuslinjer, metalinjer, labels, tidsstempler, antal, placeholder |
| `--neutral-text` | #4A5263 | #B4BCC9 | kilde-tag, pillen "Venter", inaktiv fane |
| `--neutral-bg` | #F1F3F6 | #242A35 | pillen "Venter"/"Ukendt" |

### Accent og status
| Token | Lyst | Mørkt |
|---|---|---|
| `--accent` (primærknap, links, fokus) | #3B5BDB | #7EA3FF |
| `--accent-hover` | #2B44AE | #A3BDFF |
| `--accent-contrast` (tekst på primærknap) | #FFFFFF | #0B1222 |
| `--accent-subtle` | #EAF0FF | #18233D |
| `--accent-ink` (accent som tekst på lys flade, `--nav-active`) | #2B44AE | #A3BDFF |
| `--focus` | #3B5BDB | #7EA3FF |
| `--success-text` / `--success-bg` | #146C43 / #E8F5EE | #7DD8A3 / #11261A |
| `--info-text` / `--info-bg` | #2B44AE / #EAF0FF | #A3BDFF / #18233D |
| `--warning-text` / `--warning-bg` | #7A4B00 / #FFF6E5 | #F5BF6B / #2B2111 |
| `--danger-text` / `--danger-bg` | #A5221B / #FDECEA | #FFA098 / #2D1715 |
| `--danger` / `--danger-hover` / `--danger-contrast` | #B42318 / #962016 / #FFFFFF | #FF8A80 / #FFA098 / #1A0A09 |
| `--pill-on-bg` / `--pill-on-text` (aktiv filterpille) | #12151C / #FFFFFF | #E6E9EF / #0E1117 |
| `--mark-bg` (søgefund) | #FDE68A | #5C4A12 |

`--success-border`, `--info-border`, `--warning-border` og `--danger-border` beholder deres nuværende værdier. De bruges kun af `.danger-secondary`.

### Talere (chatbobler og uddrag)
| Token | Lyst | Mørkt |
|---|---|---|
| `--agent-bg` / `--agent-bg-hover` | #F1F4FF / #E6ECFF | #172036 / #1C2742 |
| `--agent-ink` (AGENT-label, "Agent:" i uddrag, ring) | #2B44AE | #A3BDFF |
| `--kunde-bg` / `--kunde-bg-hover` | #FFF4EC / #FFEBDC | #2A1D13 / #33241A |
| `--kunde-ink` (KUNDE-label, "Kunde:" i uddrag, ring) | #A34A14 | #F2A877 |

### Chrome (mørk sidebar, topbar, menupanel og afspiller-kort)
| Token | Lyst tema | Mørkt tema |
|---|---|---|
| `--chrome-bg` | #0F1218 | #07090D |
| `--chrome-raised` (kø-kort) | #171C26 | #12161E |
| `--chrome-hover` (aktivt og hover menupunkt) | #1E2430 | #1A1F29 |
| `--chrome-control` (aktiv hastighed, kø-spor) | #262D3B | #262D3B |
| `--chrome-text` | #C9CFDA | #C9CFDA |
| `--chrome-muted` | #9AA4B6 | #9AA4B6 |
| `--chrome-strong` | #FFFFFF | #FFFFFF |
| `--chrome-accent` (play, spolelinje, fokus i chrome) | #7C9CFF | #7C9CFF |
| `--chrome-accent-contrast` (ikon på play) | #0F1218 | #07090D |
| `--chrome-seek-track` (spolelinje, ikke afspillet) | #5A6378 | #5A6378 |
| `--chrome-border` | transparent | #1F2430 |
| `--chrome-ok` / `--chrome-warn` / `--chrome-error` (motorprik) | #5FD39A / #F5BF6B / #FF8A80 | samme |
| `--logo-1` / `--logo-2` (mærkets bjælker i chrome) | #7C9CFF / #A5B8FF | samme |

**Mørkt tema:** sidebaren og afspilleren ligger tæt på sidens `--bg`. De får derfor `border-right` (sidebar) og `border` (afspiller) på 1 px `--chrome-border`. Kanten er dekorativ (1,22:1). Det, der står i sidebaren, gør den genkendelig.

### Avatarer (initialer på farve, ens i begge temaer)
- `--av-1` #3B5BDB, `--av-2` #0E7A55, `--av-3` #C2410C, `--av-4` #7C3AED, `--av-5` #0E7490, `--av-6` #BE185D.
- `--av-0` #5B6475 bruges, når agenten er ukendt.
- Teksten er #FFFFFF.
- Farven vælges på serveren ud fra agentnavnet (fx `crc32(navn) % 6 + 1`), så samme agent altid har samme farve. Den sættes som klassen `.av-1` … `.av-6`, aldrig inline.

**AFVIGELSE FRA DESIGN (avatarer):** #0E9F6E, #0891B2 og #DB2777 fra artboardet gav 3,39, 3,68 og 4,60 med hvide 13 px-initialer. De er gjort mørkere til #0E7A55, #0E7490 og #BE185D, så de består 4,5:1. Farvetonerne er de samme.

## Brug pr. element
- **Primær handling** ("Søg", "Download .txt"): `--accent`-fyld og `--accent-contrast`-tekst. Højst én primærknap pr. område.
- **Sekundær handling** ("Download transcripts", "Kopiér tekst", "Download lyd", pager): `--surface`-fyld, 1 px `--border-control` og `--text`-tekst.
- **Links:** `--accent`, hover `--accent-hover`.
- **Filterpiller:** inaktiv har `--surface`, 1 px `--border-control` og `--text-2`. Aktiv har `--pill-on-bg` og `--pill-on-text`.
  - Undtagelse: Adversus' "Succes og valgte" har som aktiv `--success-bg`/`--success-text` og en prik på 6 px i `currentColor`. Succes har sin farve overalt.
- **Status-piller** (eksisterende `.s-*`-klasser, nye værdier):

  | Status | Klasse | Farver |
  |---|---|---|
  | Færdig | `.s-faerdig` | success |
  | I gang (Transcriberer) | `.s-transcriberer` | info, prikken pulserer |
  | Venter | `.s-ventende` | neutral |
  | Ukendt | `.s-ukendt` | neutral |
  | Ingen lyd / Venter på lyd / Lyd mangler | `.s-lyd` | warning |
  | Fejlet | `.s-fejlet` | danger |
  | Ikke valgt | `.s-ikke-valgt` | hul: transparent, 1 px `--border-input` indvendigt, `--text-muted` og en hul prik |

  Ordlyden bestemmer UI/UX. Farven følger klassen.
- **Kilde-tag:** `--surface-2`-fyld og `--neutral-text`. Det er ens for Zendesk og Adversus, uden logo og uden farvekode pr. kilde (F004-princippet holder).
- **Succes-lead-tag:** `--success-bg`-fyld og `--success-text`. Det er et kantet tag, ikke en pille. Ikonet `trophy` på 14 px bevares fra F004, så succes ikke kun bæres af farve.
- **Advarsler/beskeder (`.msg`):** fladen er `--*-bg`, og tekst og ikon er `--*-text` (warn, error, ok, info). Links i boksen har samme farve, 600 og understregning. Boksen har ingen kant (som artboardet).
- **Fokus:**
  - Overalt bruges den globale ring 2 px `--focus` med offset 2 px.
  - Inde i chrome (sidebar, topbar, menupanel og afspiller) er ringens farve `--chrome-accent`. `--focus` (#3B5BDB) giver kun 3,31:1 mod `--chrome-bg` og er for svag.

## WCAG-kontrast (AA), alle tekst/baggrund-par
Kontrasten er beregnet med WCAG 2.x-formlen (relativ luminans). Krav: 4,5:1 for tekst, 3:1 for ikoner, ringe og kontrolkanter (1.4.11).

| Par | Tokens | Lyst | Kontrast | Mørkt | Kontrast |
|---|---|---|---|---|---|
| Brødtekst, titler | `--text` på `--bg` | #12151C på #F6F7F9 | **17.04** | #E6E9EF på #0E1117 | **15.54** |
| Brødtekst på kort | `--text` på `--surface` | #12151C på #FFFFFF | **18.26** | #E6E9EF på #161A22 | **14.33** |
| Sekundær tekst (uddrag, varighed, filterknap) | `--text-2` på `--surface` | #3A4252 på #FFFFFF | **10.09** | #C3C9D4 på #161A22 | **10.48** |
| Sekundær tekst på sat filter | `--text-2` på `--surface-2` | #3A4252 på #F1F3F6 | **9.08** | #C3C9D4 på #1D222C | **9.58** |
| Dæmpet tekst på siden (statuslinje, opsummering, pager) | `--text-muted` på `--bg` | #5B6475 på #F6F7F9 | **5.56** | #9AA3B2 på #0E1117 | **7.43** |
| Dæmpet tekst på kort (metalinje, labels, placeholder) | `--text-muted` på `--surface` | #5B6475 på #FFFFFF | **5.96** | #9AA3B2 på #161A22 | **6.85** |
| Fane: inaktivt navn | `--neutral-text` på `--track` | #4A5263 på #ECEEF2 | **6.75** | #B4BCC9 på #1A1F28 | **8.64** |
| Fane: aktivt navn | `--text` på `--segment-on` | #12151C på #FFFFFF | **18.26** | #E6E9EF på #2A3140 | **10.71** |
| Fane: antal (inaktiv) | `--text-muted` på `--track` | #5B6475 på #ECEEF2 | **5.13** | #9AA3B2 på #1A1F28 | **6.50** |
| Fane: antal (aktiv) | `--text-muted` på `--segment-on` | #5B6475 på #FFFFFF | **5.96** | #9AA3B2 på #2A3140 | **5.12** |
| Fane: markør, fejl | `--danger-text` på `--track` | #A5221B på #ECEEF2 | **6.36** | #FFA098 på #1A1F28 | **8.45** |
| Fane: markør, advarsel | `--warning-text` på `--track` | #7A4B00 på #ECEEF2 | **6.38** | #F5BF6B på #1A1F28 | **9.88** |
| Kilde-tag | `--neutral-text` på `--surface-2` | #4A5263 på #F1F3F6 | **7.05** | #B4BCC9 på #1D222C | **8.33** |
| Succes-lead-tag / pillen Færdig | `--success-text` på `--success-bg` | #146C43 på #E8F5EE | **5.75** | #7DD8A3 på #11261A | **9.29** |
| Pillen I gang, Ticket-chip | `--info-text` på `--info-bg` | #2B44AE på #EAF0FF | **7.24** | #A3BDFF på #18233D | **8.38** |
| Pillen Venter / Ukendt | `--neutral-text` på `--neutral-bg` | #4A5263 på #F1F3F6 | **7.05** | #B4BCC9 på #242A35 | **7.53** |
| Pillen Ingen lyd / advarselsboks | `--warning-text` på `--warning-bg` | #7A4B00 på #FFF6E5 | **6.90** | #F5BF6B på #2B2111 | **9.45** |
| Pillen Fejlet / fejlboks | `--danger-text` på `--danger-bg` | #A5221B på #FDECEA | **6.46** | #FFA098 på #2D1715 | **8.62** |
| Pillen Ikke valgt (hul) | `--text-muted` på `--surface` | #5B6475 på #FFFFFF | **5.96** | #9AA3B2 på #161A22 | **6.85** |
| Filterpille, inaktiv | `--text-2` på `--surface` | #3A4252 på #FFFFFF | **10.09** | #C3C9D4 på #161A22 | **10.48** |
| Filterpille, aktiv | `--pill-on-text` på `--pill-on-bg` | #FFFFFF på #12151C | **18.26** | #0E1117 på #E6E9EF | **15.54** |
| Primærknap | `--accent-contrast` på `--accent` | #FFFFFF på #3B5BDB | **5.67** | #0B1222 på #7EA3FF | **7.63** |
| Primærknap, hover | `--accent-contrast` på `--accent-hover` | #FFFFFF på #2B44AE | **8.27** | #0B1222 på #A3BDFF | **10.04** |
| Sekundær knap | `--text` på `--surface` | #12151C på #FFFFFF | **18.26** | #E6E9EF på #161A22 | **14.33** |
| Sekundær knap, hover | `--text` på `--bg` | #12151C på #F6F7F9 | **17.04** | #E6E9EF på #0E1117 | **15.54** |
| Farlig knap | `--danger-contrast` på `--danger` | #FFFFFF på #B42318 | **6.57** | #1A0A09 på #FF8A80 | **8.43** |
| Link på siden | `--accent` på `--bg` | #3B5BDB på #F6F7F9 | **5.29** | #7EA3FF på #0E1117 | **7.71** |
| Link på kort | `--accent` på `--surface` | #3B5BDB på #FFFFFF | **5.67** | #7EA3FF på #161A22 | **7.11** |
| Uddrag: "Agent:" | `--agent-ink` på `--surface` | #2B44AE på #FFFFFF | **8.27** | #A3BDFF på #161A22 | **9.36** |
| Uddrag: "Kunde:" | `--kunde-ink` på `--surface` | #A34A14 på #FFFFFF | **5.92** | #F2A877 på #161A22 | **8.83** |
| Agent-boble: tekst | `--text` på `--agent-bg` | #12151C på #F1F4FF | **16.63** | #E6E9EF på #172036 | **13.32** |
| Agent-boble: AGENT | `--agent-ink` på `--agent-bg` | #2B44AE på #F1F4FF | **7.53** | #A3BDFF på #172036 | **8.70** |
| Agent-boble, hover: AGENT | `--agent-ink` på `--agent-bg-hover` | #2B44AE på #E6ECFF | **7.01** | #A3BDFF på #1C2742 | **7.95** |
| Agent-boble: tidsstempel | `--text-muted` på `--agent-bg` | #5B6475 på #F1F4FF | **5.43** | #9AA3B2 på #172036 | **6.37** |
| Kunde-boble: tekst | `--text` på `--kunde-bg` | #12151C på #FFF4EC | **16.88** | #E6E9EF på #2A1D13 | **13.45** |
| Kunde-boble: KUNDE | `--kunde-ink` på `--kunde-bg` | #A34A14 på #FFF4EC | **5.47** | #F2A877 på #2A1D13 | **8.29** |
| Kunde-boble, hover: KUNDE | `--kunde-ink` på `--kunde-bg-hover` | #A34A14 på #FFEBDC | **5.12** | #F2A877 på #33241A | **7.56** |
| Kunde-boble: tidsstempel | `--text-muted` på `--kunde-bg` | #5B6475 på #FFF4EC | **5.51** | #9AA3B2 på #2A1D13 | **6.43** |
| Søgefund | `--text` på `--mark-bg` | #12151C på #FDE68A | **14.67** | #E6E9EF på #5C4A12 | **7.07** |
| Én kolonne: tidsstempel | `--accent-ink` på `--surface` | #2B44AE på #FFFFFF | **8.27** | #A3BDFF på #161A22 | **9.36** |
| Én kolonne: aktuel linje | `--text` på `--accent-subtle` | #12151C på #EAF0FF | **16.01** | #E6E9EF på #18233D | **12.82** |
| Tekst i felt | `--text` på `--input-bg` | #12151C på #FFFFFF | **18.26** | #E6E9EF på #0E1117 | **15.54** |
| Placeholder i felt | `--text-muted` på `--input-bg` | #5B6475 på #FFFFFF | **5.96** | #9AA3B2 på #0E1117 | **7.43** |
| Sidebar: menupunkt | `--chrome-text` på `--chrome-bg` | #C9CFDA på #0F1218 | **11.98** | #C9CFDA på #07090D | **12.73** |
| Sidebar: aktivt eller hover menupunkt | `--chrome-strong` på `--chrome-hover` | #FFFFFF på #1E2430 | **15.55** | #FFFFFF på #1A1F29 | **16.51** |
| Sidebar: antal på aktivt punkt | `--chrome-muted` på `--chrome-hover` | #9AA4B6 på #1E2430 | **6.19** | #9AA4B6 på #1A1F29 | **6.57** |
| Sidebar: rolle og sekundær tekst | `--chrome-muted` på `--chrome-bg` | #9AA4B6 på #0F1218 | **7.46** | #9AA4B6 på #07090D | **7.93** |
| Kø-kort: tal | `--chrome-strong` på `--chrome-raised` | #FFFFFF på #171C26 | **17.07** | #FFFFFF på #12161E | **18.12** |
| Kø-kort: label og motorlinje | `--chrome-muted` på `--chrome-raised` | #9AA4B6 på #171C26 | **6.79** | #9AA4B6 på #12161E | **7.21** |
| Afspiller: tider, inaktiv hastighed | `--chrome-muted` på `--chrome-bg` | #9AA4B6 på #0F1218 | **7.46** | #9AA4B6 på #07090D | **7.93** |
| Afspiller: aktiv hastighed | `--chrome-strong` på `--chrome-control` | #FFFFFF på #262D3B | **13.81** | samme | **13.81** |
| Afspiller: play-ikon | `--chrome-accent-contrast` på `--chrome-accent` | #0F1218 på #7C9CFF | **7.19** | #07090D på #7C9CFF | **7.64** |
| Avatar 1 / 2 / 3 | #FFFFFF på `--av-1/2/3` | #3B5BDB / #0E7A55 / #C2410C | **5.67 / 5.34 / 5.18** | samme | samme |
| Avatar 4 / 5 / 6 / 0 | #FFFFFF på `--av-4/5/6/0` | #7C3AED / #0E7490 / #BE185D / #5B6475 | **5.70 / 5.36 / 6.04 / 5.96** | samme | samme |

**Ikke-tekst (1.4.11, krav 3:1):**

| Element | Tokens | Lyst | Kontrast | Mørkt | Kontrast |
|---|---|---|---|---|---|
| Fokusring på siden / på kort | `--focus` | #3B5BDB | 5.29 / 5.67 | #7EA3FF | 7.71 / 7.11 |
| Fokusring i chrome | `--chrome-accent` mod `--chrome-bg` | #7C9CFF / #0F1218 | 7.19 | #7C9CFF / #07090D | 7.64 |
| Kant på tekstfelt | `--border-input` mod `--input-bg` | #7B8495 / #FFFFFF | 3.77 | #6A7386 / #0E1117 | 3.97 |
| Spolelinje, ikke afspillet | `--chrome-seek-track` mod `--chrome-bg` | #5A6378 / #0F1218 | 3.12 | #5A6378 / #07090D | 3.31 |
| Spolelinje, afspillet og knop; play-knap | `--chrome-accent` mod `--chrome-bg` | #7C9CFF / #0F1218 | 7.19 | #7C9CFF / #07090D | 7.64 |
| Motorprik ok / advarsel / fejl | `--chrome-ok/warn/error` mod `--chrome-raised` | #5FD39A, #F5BF6B, #FF8A80 / #171C26 | 9.16 / 10.20 / 7.48 | samme / #12161E | 9.72 / 10.82 / 7.94 |
| Ring om aktuel boble, agent | `--agent-ink` mod `--agent-bg` | #2B44AE / #F1F4FF | 7.53 | #A3BDFF / #172036 | 8.70 |
| Ring om aktuel boble, kunde | `--kunde-ink` mod `--kunde-bg` | #A34A14 / #FFF4EC | 5.47 | #F2A877 / #2A1D13 | 8.29 |
| Aktuel linje, venstre streg | `--accent` mod `--accent-subtle` | #3B5BDB / #EAF0FF | 4.97 | #7EA3FF / #18233D | 6.36 |

**Kontrol- og kortkanter:** `--border` og `--border-control` (ca. 1,1–1,4:1) er dekorative. Knapper, piller og kort genkendes på deres tekst og fyld. Rigtige tekstfelter (Konto, Brugere, login) får `--border-input`.

**Søgelinjen** er undtagelsen: inputtet har ingen egen kant, men står i et hvidt kort med lup-ikon (`--text-muted`, 5,96:1), placeholder og en "Søg"-knap, som tilsammen viser, hvad det er. Når inputtet har fokus, får hele kortet fokusringen (se Visual states).

**AFVIGELSER FRA DESIGN (kontrast):**
1. **#7A8394** (artboardets dæmpede grå til antal, tidsstempler, VERSAL-labels og lup-ikon) gav 3,82 på hvid og 3,29 på sporet. Den er udgået og erstattet af `--text-muted` #5B6475 overalt. Så er der tre tekstniveauer i stedet for fire.
2. **Kundefarven #B4561F** gav 4,52 på boblen, men kun 4,23 på hover-boblen. Den er ændret til `--kunde-ink` #A34A14.
3. **Bølgeformens uafspillede del #3A4252** (1,86:1) er ændret til `--chrome-seek-track` #5A6378 på spolelinjen.
4. **"Agent:" i uddraget** er #3B5BDB i artboardet og #2B44AE i boblen. Begge bruger nu `--agent-ink` #2B44AE, så én taler har én farve.

---

# Typography

| Element | Font | Størrelse | Vægt | Linjehøjde | Bogstavafstand | Farve |
|---|---|---|---|---|---|---|
| H1 på listesider (Opkald, Konto, Brugere) | display | 30 px (1.875rem) | 700 | 1.15 | -0.02em | `--text` |
| H1 på detaljen ("Sara · indgående opkald") | display | 28 px (1.75rem) | 700 | 1.2 | -0.02em | `--text` |
| H1 i login-, konsent- og fejlkort | display | 24 px | 700 | 1.2 | -0.02em | `--text` |
| H2 (fx "Samtale", sektioner på Konto) | display | 18 px | 700 | 1.25 | -0.01em | `--text` |
| H3 | sans | 15 px | 600 | 1.3 | 0 | `--text` |
| Logonavn "Whisper" i sidebar og topbar | display | 19 px (topbar 18 px) | 700 | 1 | -0.01em | `--chrome-strong` |
| Logonavn over login-kortet | display | 22 px | 700 | 1 | -0.01em | `--text` |
| Statuslinje under H1 | sans | 14 px | 400 | 1.5 | 0 | `--text-muted` |
| Søgeinput | sans | 16 px (ikke mindre, så iOS ikke zoomer) | 500 | 1.4 | 0 | `--text` |
| Faner (segmenteret) | sans | 14 px | 500, aktiv 600 | 1 | 0 | `--neutral-text`, aktiv `--text` |
| Antal på fane | sans | 14 px | 500 | 1 | 0, tabulære tal | `--text-muted` |
| Filterpille og filterknap | sans | 13 px | 500, aktiv 600 | 1 | 0 | se Color |
| Opsummering ("Viser 1–50 af 1.234 …") | sans | 13 px | 400 | 1.5 | 0, tabulære tal | `--text-muted` |
| Kort: agentnavn | sans | 15 px | 600 | 1.35 | 0 | `--text` |
| Kort: metalinje (retning · kunde · tid · Ticket/Kampagne/Lead) | sans | 13 px | 400 | 1.45 | 0, tabulære tal | `--text-muted` |
| Kort: varighed | sans | 14 px | 500 | 1 | 0, tabulære tal | `--text-2` |
| Kort: uddrag | sans | 14 px | 400 ("Agent:"/"Kunde:" 600) | 1.55 | 0 | `--text-2` |
| Status-pille | sans | 12 px | 600 | 1 | 0 | pr. status |
| Kilde-tag og Succes-lead-tag | sans | 12 px | 600 | 1 | 0 | se Color |
| Meta-chips i detaljens hoved | sans | 13 px | 400 (kilde, Ticket, status: 600) | 1 | 0, tabulære tal | `--text-2` |
| Boble: rolle (AGENT/KUNDE) | sans | 12 px VERSALER | 700 | 1.2 | 0.02em | `--agent-ink` / `--kunde-ink` |
| Boble: tidsstempel | sans | 12 px | 500 | 1.2 | 0, tabulære tal | `--text-muted` |
| Boble: tekst | sans | 15 px | 400 | 1.6 | 0 | `--text` |
| Én kolonne: tidsstempel | sans | 13 px | 500 | 1.4 | 0, tabulære tal | `--accent-ink` |
| Én kolonne: tekst | sans | 15 px | 400 | 1.6 | 0 | `--text` |
| Metadata: label | sans | 12 px VERSALER | 600 | 1.3 | 0.02em | `--text-muted` |
| Metadata: værdi | sans | 15 px | 600 | 1.35 | 0, tabulære tal | `--text` |
| Sidebar: menupunkt | sans | 14 px | 500, aktiv 600 | 1.2 | 0 | `--chrome-text`, aktiv `--chrome-strong` |
| Sidebar: antal i menupunkt | sans | 12 px | 500 | 1 | 0, tabulære tal | `--chrome-muted` |
| Kø-kort: label "TRANSCRIPTION" | sans | 12 px VERSALER | 600 | 1.2 | 0.02em | `--chrome-muted` |
| Kø-kort: tal | display | 24 px | 700 | 1 | -0.01em, tabulære tal | `--chrome-strong` |
| Kø-kort: "i kø" og motorlinje | sans | 13 px / 12 px | 400 | 1.4 | 0 | `--chrome-muted` |
| Bruger i sidebarens bund: navn / rolle | sans | 13 px / 12 px | 600 / 400 | 1.3 | 0 | `--chrome-strong` / `--chrome-muted` |
| Afspiller: tider | sans | 13 px | 500 | 1 | 0, tabulære tal | `--chrome-muted` |
| Afspiller: hastighed | sans | 13 px | 500, aktiv 600 | 1 | 0 | `--chrome-muted`, aktiv `--chrome-strong` |
| Knap | sans | 14 px | 600 | 1 | 0 | se Color |
| Label over formularfelt | sans | 14 px | 500 | 1.5 | 0 | `--text` |
| Hjælpetekst | sans | 13 px | 400 | 1.5 | 0 | `--text-muted` |
| Fejltekst under felt | sans | 13 px | 500 | 1.5 | 0 | `--danger-text` |
| Advarsel/besked (`.msg`) | sans | 14 px | 400 (link 600) | 1.5 | 0 | `--*-text` |
| "Spørg Claude"-kort | sans | 14 px (titel 600 `--text`) | 400 | 1.5 | 0 | `--text-muted` |

**Typografiske regler:**
- Alle tal, tider og varigheder har `font-variant-numeric: tabular-nums`. Tusindtal med punktum ("1.234") og decimaler med komma ("1,25×") kommer fra serveren.
- `--font-mono` bruges ikke til tidsstempler i v2. Det bruges kun til kode og nøgler på Konto, som i dag.
- VERSALER (rolle i boblen, metadata-labels og kø-label) skrives med `text-transform: uppercase` på en almindeligt skrevet tekst ("Agent", "Taletid agent"), så skærmlæsere ikke staver ordet.
- **AFVIGELSE FRA DESIGN:**
  - "Samtale" (H2) er Inter Tight 18/700 i stedet for Inter 17/700. Spec'en siger Inter Tight til overskrifter, og 17 px ligger uden for skalaen.
  - Teksten i `.msg` er 14 px i stedet for 13 px, fordi advarsler skal kunne læses.

**Typeskala (tokens):**
- `--fs-xs: 0.75rem` (12)
- `--fs-sm: 0.8125rem` (13)
- `--fs-md: 0.875rem` (14)
- `--fs-card: 0.9375rem` (15)
- `--fs-base: 1rem` (16)
- `--fs-lg: 1.125rem` (18)
- `--fs-brand: 1.1875rem` (19)
- `--fs-xl: 1.5rem` (24)
- `--fs-2xl: 1.75rem` (28)
- `--fs-3xl: 1.875rem` (30)

---

# Spacing and layout

Grid på 4 px. Tokens som i dag (`--sp-1` … `--sp-12`), plus `--sp-7: 28px` (luft i sidebaren).

## Ramme
- **Desktop (900 px og bredere):**
  - To kolonner: `240px minmax(0, 1fr)`.
  - Sidebaren er `position: sticky; top: 0; height: 100vh; overflow-y: auto;` med padding 24 px × 16 px og en lodret flex med gap 28 px.
  - Rækkefølge i sidebaren: logo, menu, en tom flex-fylder, kø-kort, bruger og Log ud.
- **Indhold:** padding 32 px top, 40 px sider og 48 px bund. Indholdet har `max-width: 1200px; margin: 0 auto;` (artboardet er 1440 px bredt). Sektionerne har lodret gap 20 px.
- **Logo:** mærket er 26 px. Mellemrummet til "Whisper" er 10 px, og rækken har padding 0 8 px.
- **Menupunkt:**
  - `display: flex`, gap 12 px, padding 10 px × 12 px, min-højde 40 px og radius 10 px.
  - Ikonet er 18 px.
  - Antal står yderst til højre (`margin-left: auto`).
  - Punkterne har gap 4 px.
- **Kø-kort:** padding 16 px, radius 12 px og lodret gap 8 px.
  - Rækkefølge: label, så tal og "i kø" (baseline, gap 6 px), så motorlinjen med en prik på 8 px før teksten (gap 6 px).
  - En fremdriftslinje må kun med, hvis UI/UX v2 definerer, hvad den måler. Den skal så være `<progress>`, 6 px høj, radius 3 px, med sporet `--chrome-control` og fyldet `--chrome-accent`. Ingen inline-bredde.
- **Bruger i bunden:** avatar 32 px, gap 10 px og padding 8 px. "Log ud" er et menupunkt (knap i formular) med ikonet `log-out`.
- **Under 900 px:**
  - Sidebaren skjules, og der vises en topbar: `position: sticky; top: 0; z-index: 20; height: 56px; padding: 0 16px;` med `--chrome-bg`.
  - Logoet er til venstre (mærke 22 px, navn 18 px). Menu-knappen til højre er 44 × 44 px med radius 10 px og ikonet `menu` 20 px i `--chrome-strong`. Åben menu har ikonet `x`.
  - Menupanelet ligger lige under topbaren i fuld bredde med `--chrome-bg`. Padding er 8 px 16 px 16 px, og menupunkterne er mindst 44 px høje.
  - Kø-kortet og brugeren står nederst i panelet med samme mål som i sidebaren.
  - Uden JS er panelet synligt som en lodret liste (UI/UX bestemmer adfærden).
- **Indholdets padding:** 24 px 24 px 40 px fra 640 til 899 px og 20 px 16 px 32 px under 640 px.

## Opkaldslisten (rækkefølge og afstande)
1. **Sidehoved:**
   - Venstre del er H1 med statuslinjen under (gap 4 px). Til højre står "Download transcripts" (sekundær knap med ikonet `download` 16 px).
   - `flex-wrap: wrap`, gap 16 px og `align-items: center`.
   - Statuslinjen viser kun den aktive kildes linje (spec: egen statuslinje pr. kilde).
2. **Søgelinje:**
   - Kort med `--surface`, 1 px `--border`, radius 12 px og `--shadow-1`.
   - Padding 8 px 8 px 8 px 16 px, `flex-wrap: wrap`, gap 12 px og `align-items: center`.
   - Indhold i rækkefølge: ikonet `search` 20 px i `--text-muted`, inputtet (`flex: 1 1 320px; min-width: 0; min-height: 40px;` uden kant og padding 8 px 0), filterknapperne og "Søg".
   - **Filterknapper** (dato, agent, retning): min-højde 36 px, padding 0 12 px, radius 8 px, 1 px `--border-control` og `--surface`. Har filteret en værdi, bliver fyldet `--surface-2`, og teksten bliver 600. Selve teksten viser også værdien ("I dag" i stedet for "Alle datoer"), så farven ikke er eneste markør.
   - Er filterknapperne `<select>`, beholder de den indbyggede pil (ingen `data:`-pil, CSP), og `color-scheme` sørger for, at pilen følger temaet.
   - **"Søg":** primærknap med min-højde 40 px, padding 0 18 px og radius 10 px.
3. **Fanerække:**
   - De segmenterede faner står til venstre, og filterpillerne til højre. `flex-wrap: wrap`, gap 12 px og `justify-content: space-between`.
   - **Faner:**
     - Sporet har `--track`, padding 4 px, gap 4 px og radius 12 px.
     - Hver fane har min-højde 40 px, padding 0 16 px og radius 8 px. 8 + 4 = 12, så hjørnerne er koncentriske.
     - Antallet står 6 px efter navnet. En eventuel markør (`.tab-flag`, F007 v1) står 6 px efter antallet, er 14 px og har farven `--danger-text`/`--warning-text`. Markørreglerne fra v1 gælder uændret.
   - **Filterpiller:** høj 32 px, padding 0 12 px, radius 999 px og gap 8 px. Den aktive succes-pille har en prik på 6 px og gap 6 px.
4. **Kildens advarsler (`.msg`):** padding 10 px 14 px, radius 10 px, gap 10 px og ikon 16 px. Boksene har 8 px imellem.
5. **Opsummering** (13 px `--text-muted`) og så kortene. Opsummering og første kort har 12 px imellem, og kortene har 12 px imellem hinanden.
6. **Opkaldskort:**
   - `--surface`, 1 px `--border`, radius 12 px, `--shadow-1` og padding 16 px 20 px.
   - Gitter: `grid-template-columns: minmax(0, 1fr) auto;` med `gap: 10px 20px`.
   - **Venstre celle:** flex med wrap og gap 10 px 14 px. Rækkefølge: avatar (36 × 36 px, rund, initialer 13/600 hvid), navneblok (agent og metalinje under, `min-width: 0`), kilde-tag og Succes-lead-tag (kun Adversus).
   - **Højre celle:** varighed og status-pille, gap 14 px og `justify-self: end`.
   - **Uddrag:** `grid-column: 1 / -1`, to linjer med `-webkit-line-clamp: 2` og `overflow: hidden`.
   - **Ticket** (Zendesk) og **Kampagne · Lead** (Adversus) står sidst i metalinjen, adskilt af " · ". Ticket er et link over kortets stretched-link (som `.ticket` i dag, `position: relative; z-index: 1`) med ikonet `external-link` 14 px.
   - **Tags:** højde 22 px, padding 0 8 px og radius 6 px. Succes-lead har ikonet `trophy` 14 px og gap 4 px.
   - **Status-pille:** højde 24 px, padding 0 10 px, radius 999 px, gap 6 px og en prik på 6 px via `::before` i `currentColor`. Artboardets "●"-tegn bruges ikke.
7. **Pager:**
   - Centreret med gap 8 px.
   - "Forrige" og "Næste" er sekundære knapper i lille størrelse: min-højde 36 px, padding 0 12 px, radius 8 px og 14/500 `--text-2`. Ikonerne er `chevron-left`/`chevron-right` 16 px.
   - "Side 1 af 27" er 14 px `--text-muted`.
   - Pageren har 8 px luft over sig ud over sektionens gap.
8. **Tom tilstand:** kort med 1 px stiplet `--border-dashed`, radius 12 px og padding 48 px 24 px. Ikonet `inbox` er 24 px i en cirkel på 48 px med `--surface-2`.

## Detaljesiden
- **"Tilbage til opkald":** 14/500 `--neutral-text` med ikonet `arrow-left` 16 px og gap 6 px. Min-højde 24 px (44 px under 640 px).
- **Hoved:**
  - H1 med meta-chips under (gap 10 px). Til højre står handlingerne: "Kopiér tekst" (sekundær), "Download lyd" (sekundær) og "Download .txt" (primær), med gap 8 px.
  - `flex-wrap: wrap`, gap 16 px og `align-items: flex-start`.
  - **Meta-chips:**
    - Generelt: padding 5 px 10 px, radius 8 px og gap 8 px. Almindelige chips (dato, varighed, nummer) har `--surface`, 1 px `--border-control` og `--text-2`.
    - Kilde-chip: `--surface-2` og `--neutral-text` 600.
    - Ticket-chip: `--info-bg` og `--info-text` 600 med `external-link` 14 px.
    - Status-chip: status-pillen i størrelsen 13 px og højde 26 px.
- **Gitter (1100 px og bredere):** `grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start;`. Sidekolonnen er `position: sticky; top: 24px;` med lodret gap 16 px.
- **Samtalepanel:**
  - `--surface`, 1 px `--border`, radius 16 px, padding 24 px og lodret gap 16 px.
  - Øverste række har H2 "Samtale" og et eventuelt "Find i samtalen"-felt (hvis UI/UX v2 beholder det). Feltet har `--bg`-fyld, 1 px `--border-control`, radius 10 px, padding 8 px 12 px, 14 px tekst, ikonet `search` 16 px og `flex: 0 1 280px`.
- **Chatbobler:**
  - Listen er en lodret flex med gap 12 px.
  - Agent har `align-self: flex-start`, Kunde `align-self: flex-end`.
  - Boblen har `max-width: 78%` (88 % under 640 px) og padding 12 px 14 px.
  - Radius: agent `4px 14px 14px 14px` (spids øverst til venstre), kunde `14px 4px 14px 14px` (spids øverst til højre).
  - Boblens hoved har rolle og tidsstempel på baseline med gap 8 px og 4 px luft til teksten.
  - Teksten har `overflow-wrap: anywhere`.
- **Én kolonne (uden talere):**
  - Listen har gap 2 px.
  - Hver række er et gitter `56px 1fr` med gap 12 px, padding 8 px 12 px og radius 8 px.
  - Tekstbredden er højst 72ch.
- **Afspiller-kort:**
  - `--chrome-bg` (mørkt tema: også 1 px `--chrome-border`), radius 16 px, padding 20 px og lodret gap 14 px.
  - **Række 1:** play/pause-knappen (44 × 44 px, rund, `--chrome-accent`-fyld, ikon 18 px i `--chrome-accent-contrast`) og spolelinjen (`flex: 1`), gap 12 px.
  - **Række 2:** nuværende tid til venstre og samlet tid til højre (`justify-content: space-between`).
  - **Række 3:** hastighederne 0,75×, 1×, 1,25×, 1,5× og 2× med gap 6 px og `flex-wrap: wrap`. Hver har padding 5 px 10 px, radius 8 px og min-højde 32 px (44 px under 640 px).
  - Er der spoleknapper (−15 s / +15 s, hvis UI/UX v2 vil have dem): samme form som hastighederne, med ikonerne `rotate-ccw`/`rotate-cw` 16 px og teksten "15 s", placeret i række 3 før hastighederne, adskilt af 12 px.
  - **Spolelinjen** er `<input type="range">`: sporet er 6 px højt med radius 3 px og `--chrome-seek-track`, knoppen 14 px og afspillet del `--chrome-accent`. Den simple løsning er `accent-color: var(--chrome-accent)` (Chromium og Firefox farver den afspillede del). Bølgeformen udelades (spec: tilladt).
- **Metadata-kort:**
  - `--surface`, 1 px `--border`, radius 16 px og padding 20 px.
  - Gitter `repeat(2, minmax(0, 1fr))` med gap 16 px. Label og værdi har 2 px imellem. Kildens felter følger F004.
- **"Spørg Claude om dette opkald":** `--surface`, 1 px stiplet `--border-dashed`, radius 16 px og padding 16 px 20 px. Titlen er 600 `--text`, og brødteksten er 14 px `--text-muted`. Opkalds-id'et står som `<strong>` i `--text`.
- **Under 1100 px:**
  - Én kolonne. Afspilleren står før samtalen og er `position: sticky` under topbaren: `top: 0` fra 900 px, `top: 56px` under 900 px. Det er en anbefaling, og UI/UX v2 har sidste ord om rækkefølgen.
  - Når afspilleren klæber, får den `--shadow-2`.
  - Metadata og "Spørg Claude" står efter samtalen.

## Konto, Brugere, login, konsent og fejlsider
- **Konto og Brugere:** samme ramme.
  - Kort: `--surface`, 1 px `--border`, radius 16 px og padding 24 px (16 px under 640 px). Sektionerne har 32 px imellem.
  - Felter: min-højde 44 px, padding 10 px 14 px, radius 10 px, 1 px `--border-input`, `--input-bg` og 16 px tekst. Felter har 16 px imellem.
  - Tabeller: radius 12 px. Overskriftsceller har 13/600 `--text-muted` på `--surface-2` og padding 10 px 16 px. Celler har padding 12 px 16 px.
- **Login, konsent og fejl:** ingen sidebar og ingen topbar.
  - Logo (mærke 26 px i `--accent` og `--logo-2`, se Brand, og "Whisper" 22/700) står centreret over kortet med 24 px luft.
  - Kortet er højst 440 px bredt (konsent 560 px), har radius 16 px, padding 32 px (24 px under 640 px) og `--shadow-1`. Siden har padding 48 px 16 px.

## Responsivt ned til 320 px (ingen vandret scroll)
**Under 900 px:** topbar og menupanel (se Ramme).

**Under 640 px:**
- **Sidehoved:** H1 er 26 px (`--fs-2xl` minus 2 px er acceptabelt, eller 28 px). Knappen "Download transcripts" står under statuslinjen og er fuld bredde.
- **Søgelinje:** inputtet fylder hele første række. Filterknapperne står på anden række med wrap, og "Søg" står sidst. Filterknapper og "Søg" har min-højde 44 px.
- **Faner:** sporet fylder hele bredden. Hver fane har `flex: 1`, min-højde 44 px, centreret indhold og `white-space: nowrap`, med padding 0 8 px.
  - Ved 320 px er hver fane ca. 140 px bred, og "Adversus 12.345" fylder ca. 120 px. Det kan være der.
  - Filterpillerne står på egen række under fanerne med min-højde 44 px.
- **Opkaldskort:**
  - Gitteret bliver én kolonne. Varighed og status-pille står på en række under navneblokken (`justify-self: start`).
  - Padding er 14 px 16 px.
  - Metalinjen må ombryde. Lange numre og navne ombrydes med `overflow-wrap: anywhere`.
  - Tags ombrydes under navneblokken.
- **Pager:** knapperne har min-højde 44 px. Står den ikke på én linje, står "Side x af y" over knapperne.
- **Detalje:**
  - Meta-chips ombrydes.
  - Handlingsknapperne ombrydes og er hver mindst 44 px høje. Ved 400 px og smallere har de fuld bredde.
  - Boblerne har max-width 88 %, og samtalepanelet har padding 16 px.
  - Metadata-gitteret beholder 2 kolonner til 360 px og bliver 1 kolonne under 360 px.
- **Alle klikbare elementer** er mindst 44 × 44 px under 640 px. Det gælder fanerne, pillerne, menupunkterne, hastighederne, Ticket-linket (min-højde 44 px via padding) og bobletidsstemplerne.

---

# Iconography

- **Ikonsæt:** Lucide via `Ui::icon($navn, $størrelse)`, inline SVG med `stroke="currentColor"` og stroke 1.75 (som i dag). Farven sættes med klasse på et omsluttende element.
- **Eksisterende ikoner, der genbruges:** `download`, `copy`, `check`, `external-link`, `info`, `circle-check`, `triangle-alert`, `circle-alert`, `chevron-left`, `chevron-right`, `log-out`, `inbox` og `trophy`.
- **Nye ikoner, der skal tilføjes til `Ui::ICONS`:**
  - Path-data kopieres fra lucide.dev eller npm-pakken `lucide-static`, under samme ISC-note, som allerede står i `Ui.php`.

  | Navn | Bruges til | Størrelse |
  |---|---|---|
  | `phone` | menupunkt Opkald | 18 |
  | `user-round` | menupunkt Konto | 18 |
  | `users` | menupunkt Brugere (admin) | 18 |
  | `menu` | menu-knap i topbaren | 20 |
  | `x` | menu-knap, når panelet er åbent | 20 |
  | `search` | søgelinjen (20) og "Find i samtalen" (16) | 20 / 16 |
  | `play` | afspiller | 18 |
  | `pause` | afspiller | 18 |
  | `arrow-left` | "Tilbage til opkald" | 16 |
  | `rotate-ccw` / `rotate-cw` | kun hvis UI/UX v2 vil have spoleknapper | 16 |

- **`icon()` skal kunne størrelse 20:** tilføj `.icon-20 { width: 20px; height: 20px; }`. 12 px bruges ikke.
- **Farver:**
  - Menupunkter følger teksten (`currentColor`).
  - Lup-ikonet er `--text-muted`.
  - Play/pause er `--chrome-accent-contrast`.
  - `.msg`-ikoner har boksens `--*-text`.
  - Fanemarkører er `--danger-text`/`--warning-text`.
  - Ikoner i knapper følger knappens tekst.
- **Tegn, der ikke må stå som tekst:** "←", "→" og "↗" er ikke i Inters latin-subset og ville blive tegnet med en anden font. Brug `arrow-left`, `chevron-left`/`chevron-right` og `external-link`.
  - "↓ Ind" og "↑ Ud" (U+2193/U+2191) er i subsettet og må blive som tekst. Pilen er dekorativ, og ordet bærer betydningen.
- **Logomærket er ikke Lucide** (se Brand).

---

# Visual states

| Element | Hover | Fokus (`:focus-visible`) | Aktiv/valgt | Disabled |
|---|---|---|---|---|
| Primærknap | `--accent-hover` | ring 2 px `--focus`, offset 2 px | `box-shadow: inset 0 1px 2px rgba(0,0,0,.15)` | opacity .5, `cursor: not-allowed` |
| Sekundær knap | fyld `--bg`, kant `--border-input` | ring | som primær | som primær |
| Menupunkt (chrome) | fyld `--chrome-hover`, tekst `--chrome-strong` | ring i `--chrome-accent`, offset 2 px (`-2px` i panelet, så den ikke klippes) | `aria-current="page"`: `--chrome-hover` og `--chrome-strong` 600 | — |
| Fane (segment) | inaktiv: tekst `--text`, intet fyld | ring | `aria-current="page"`: `--segment-on`, `--shadow-seg`, `--text` 600 | findes ikke |
| Filterpille | inaktiv: kant `--border-input` | ring | `--pill-on-bg`/`--pill-on-text` 600 (succes: success-farver og prik) | — |
| Filterknap i søgelinjen | kant `--border-input` | ring | sat værdi: `--surface-2` og 600 | — |
| Søgelinjens kort | — | når inputtet har fokus: `.search-bar:has(input:focus-visible)` får ringen 2 px `--focus` med offset 2 px. Inputtet selv har `outline: none` | — | — |
| Opkaldskort | kant `--border-control` og `--shadow-2` (mørkt: kun kanten) | `:focus-within`: ring 2 px `--focus`, offset 2 px på kortet | — | — |
| Ticket-link i kort | understreget | ring | — | — |
| Boble (klik spoler) | fyld `--agent-bg-hover`/`--kunde-bg-hover`, `cursor: pointer` | ring på tidsstempel-knappen (den, der kan fokuseres) | aktuel boble: `box-shadow: 0 0 0 2px var(--agent-ink)` eller `--kunde-ink` | uden lyd: ingen hover, tidsstemplet er `--text-muted` uden understregning |
| Tidsstempel i boble | understreget, `--text` | ring | — | — |
| Én kolonne-række | fyld `--surface-2` | ring på tidsstemplet | `.is-current`: `--accent-subtle` og `box-shadow: inset 3px 0 0 var(--accent)` | som boble |
| Play/pause | fyld `--chrome-accent` blandet med hvid: brug `filter: brightness(1.1)` | ring i `--chrome-strong`, offset 2 px | ikonet skifter mellem `play` og `pause` (og `aria-label`) | opacity .5 |
| Hastighed | fyld `--chrome-hover`, tekst `--chrome-strong` | ring i `--chrome-accent` | `aria-pressed="true"`: `--chrome-control`, `--chrome-strong` 600 | — |
| Spolelinje | — | ring i `--chrome-accent` på inputtet | afspillet del i `--chrome-accent` | — |
| Tekstfelt | kant `--text-muted` | kant `--focus` og ring med offset 1 px | — | readonly: `--surface-2` |
| Felt med fejl | — | — | `aria-invalid`: kant `--danger`, fejltekst under | — |

**Andre tilstande:**
- **Loading:** ingen skeletter eller spinnere. Siderne renderes på serveren. Pillen "I gang" har en pulserende prik (eksisterende `pulse`, 1,6 s).
- **Fejl og advarsel:** `.msg` med ikon og `--*-bg`/`--*-text` (tabellen ovenfor). Formen (ikon) og teksten bærer betydningen sammen med farven.
- **Succes** (fx "Kopieret"): ikonet skifter til `check` i `--success-text`, som i dag (`.is-copied`). Der er ingen toast.
- **Aktiv fane:** den skelnes på tre måder, nemlig fyld og skygge, vægt (500 → 600) og tekstfarve (`--neutral-text` → `--text`), plus `aria-current`. Hvidt mod sporet er kun 1,15:1, så fyldet alene bærer ikke tilstanden. Vægten og farven gør det (WCAG 1.4.1).

---

# Shadows and borders

**Radius (5 trin og pille):**
- `--radius-xs: 6px`: tags (kilde, Succes-lead).
- `--radius-sm: 8px`: faner, meta-chips, filterknapper, hastigheder, pager og én kolonne-rækker.
- `--radius-md: 10px`: knapper, menupunkter, tekstfelter, `.msg`, "Find i samtalen" og menu-knappen.
- `--radius-lg: 12px`: fanernes spor, opkaldskort, søgelinje, kø-kort, tabeller og tom tilstand.
- `--radius-xl: 16px`: samtalepanel, afspiller, metadata-kort, "Spørg Claude", kort på Konto/Brugere og login-kortet.
- `--radius-pill: 999px`: status-piller, filterpiller, avatarer og play-knap.
- Boblerne bruger 14 px med et 4 px-hjørne. Det er en bevidst form og ikke et trin på skalaen.

**AFVIGELSE FRA DESIGN (radius):** opkaldskort og søgelinje er 12 px i stedet for 14 px, så skalaen ikke får et sjette trin. Forskellen er næsten usynlig.

**Skygger:**
| Token | Lyst | Mørkt | Bruges til |
|---|---|---|---|
| `--shadow-1` | `0 1px 2px rgba(16, 24, 40, 0.04)` | `0 1px 2px rgba(0, 0, 0, 0.40)` | opkaldskort, søgelinje, login-kort |
| `--shadow-2` | `0 4px 12px rgba(16, 24, 40, 0.08), 0 1px 2px rgba(16, 24, 40, 0.04)` | `0 4px 12px rgba(0, 0, 0, 0.50)` | hover på opkaldskort, klæbende afspiller |
| `--shadow-seg` | `0 1px 2px rgba(16, 24, 40, 0.08)` | `0 1px 2px rgba(0, 0, 0, 0.40)` | aktiv fane |

- I mørkt tema bærer kanterne hierarkiet, ikke skyggerne (F005-princip 3). Hover på kort ændrer kun kanten.

**Kanter:**
- 1 px solid overalt. Undtagelserne er stiplet `--border-dashed` på "Spørg Claude" og tom tilstand, og 2 px på fokusring og aktuel boble.
- Sidebaren har `border-right: 1px solid var(--chrome-border)` (transparent i lyst tema).
- Filterpiller har ingen kant, når de er aktive.

---

# Animation / transitions

- Knapper, faner, filterpiller, menupunkter, hastigheder og bobler (hover): `transition: background-color, border-color, color` over `--dur` 120 ms med `--ease` (`cubic-bezier(0.2, 0, 0, 1)`).
- Opkaldskort: `border-color, box-shadow` over 120 ms. Ingen løft eller skalering.
- Menupanelet åbner og lukker uden animation (det vises eller skjules).
- Den aktuelle boble skifter uden animation, mens lyden spiller. Rulning til boblen bruger `scroll-behavior: smooth`, kun når `prefers-reduced-motion` ikke er sat.
- `pulse` på "I gang"-prikken bevares. Den eksisterende `prefers-reduced-motion: reduce`-regel slår al bevægelse fra.
- Ingen nye `@keyframes`.

---

# Brand alignment notes

- **Logomærke, AFVIGELSE FRA DESIGN_LOG/F005:** F005 vedtog fem bølgebjælker i `--accent`. Det godkendte design har **fire afrundede bjælker i to toner**.
  - Bjælkerne i viewBox `0 0 26 26`: x 2, 8, 14 og 20; bredde 3; højder 8, 18, 12 og 6; y 9, 4, 7 og 10; `rx` 1,5.
  - De to venstre er `--logo-1`, de to højre `--logo-2`.
  - I chrome er det #7C9CFF og #A5B8FF. På lyse login- og fejlsider er det `--accent` #3B5BDB og #7C9CFF. På mørke login-sider er det #7C9CFF og #A5B8FF.
  - Farverne sættes med klasser på `<rect>` (`fill: var(--logo-1)`), ikke med `fill`-attributter, så temaet styrer dem. Mærket er dekorativt (`aria-hidden`), og logoer er undtaget fra kontrastkravet.
  - `brandMark()` beholdes med flaget slukket.
- **Én accentfarve:** blå (#3B5BDB / #7EA3FF) bruges kun til handling, links, fokus og "Agent". Den lyse blå #7C9CFF bruges kun i chrome (play, spolelinje, logo og fokus).
- **Statusfarver** bruges kun til status, succes og advarsler. Undtagelsen er kundens orange, som er en taler og ikke en status. Den er bevidst holdt i en anden tone end advarslernes gul-brune (#A34A14 mod #7A4B00), og boblerne skelnes også på side og label.
- **Den mørke chrome** er det nye kendetegn. Den mørke afspiller i lyst tema er bevidst: den knytter lyden til rammen og står frem som det vigtigste værktøj på detaljen.
- **Ting i artboardet, der ikke er med i spec v2, og som derfor ikke bygges:**
  - Statuspillerne "Alle / Færdige / I kø" ved siden af fanerne.
  - Menupunktet "Forbind Claude" (connectoren ligger under Konto, F006).
  - Den fælles statuslinje "Zendesk hentet … · Adversus hentet …". Spec'en kræver én linje pr. kilde, så kun den aktive kildes linje vises.
  - Bølgeformen.
  - Ønsker ejeren nogle af dem, er det en ny spec-runde.
- Ingen TrygEnergi-farver, som før. Skal brandfarven skiftes, ændres `--accent*` og `--logo-*`, og kontrasten skal regnes igen.

---

# Visual decisions

1. **Nyt token-sæt med lyst og mørkt tema og en mørk chrome i begge temaer.** Det er tabellerne ovenfor, og alle tekstpar består AA (laveste er 5,12:1).
2. **Tre tekstniveauer** (`--text`, `--text-2` og `--text-muted`) plus `--neutral-text` til tags og faner. Artboardets fjerde grå (#7A8394) er udgået, fordi den ikke består AA.
3. **Talerfarver som tokens** (`--agent-*`, `--kunde-*`), der bruges både i bobler og uddrag. "Agent" er blå (accentfamilien), "Kunde" er varm orange.
4. **Segmenterede faner** i et spor afløser v1's understregning. Det er ejerens godkendte design. Antal og markører fra v1 bevares.
5. **Inter og Inter Tight selv-hostet**, 5 filer og kun latin-subset. Overskrifter bruger kun Inter Tight 700.
6. **Avatarer med 6 faste, AA-sikre farver** som klasser, valgt deterministisk ud fra agentnavnet.
7. **Spolelinje i stedet for bølgeform**, med `<input type="range">` og `accent-color`. Den er billig, tilgængelig og CSP-sikker.
8. **Radius-skala med 5 trin** og koncentriske hjørner på faner (8 + 4 = 12).
9. **Mørkt tema bruger kanter frem for skygger,** og sidebar og afspiller har 1 px `--chrome-border`.

## Nye visuelle beslutninger i dette brief

### Token-sæt v2 (lyst og mørkt) med chrome- og taler-tokens
- **Hvad:** hele token-sættet i "Color usage", inklusive `--chrome-*`, `--agent-*`, `--kunde-*`, `--track`, `--segment-on`, `--pill-on-*` og `--av-*`.
- **Værdi:** tabellerne ovenfor.
- **Hvorfor:** det realiserer det godkendte redesign og består AA i begge temaer.
- **Gælder for:** alle sider med `F007_kilder` tændt og alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### Webfont: Inter og Inter Tight, selv-hostet (AFVIGELSE fra F005 "ingen webfont")
- **Værdi:** Inter 400/500/600/700 og Inter Tight 700, kun latin-subset, `font-display: swap`, OFL 1.1-licensfiler i `assets/fonts/`.
- **Hvorfor:** spec v2 og ejerens godkendte design.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA. F005-beslutningen erstattes, når flaget går til stable.

### Logomærke med fire bjælker i to toner (AFVIGELSE fra F005 "fem bølgebjælker")
- **Værdi:** geometrien i Brand, `--logo-1`/`--logo-2`.
- **Hvorfor:** det godkendte design.
- **Gælder for:** sidebar, topbar, login, konsent og fejlsider.
- **Anbefaling:** Vedtag som standard? JA

### Talerfarver: Agent blå til venstre, Kunde orange til højre
- **Værdi:**
  - Lyst: agent #F1F4FF / #2B44AE, kunde #FFF4EC / #A34A14.
  - Mørkt: agent #172036 / #A3BDFF, kunde #2A1D13 / #F2A877.
  - Bobleradius 4 px i hjørnet ved taleren.
- **Hvorfor:** spec v2 og designet. Kundens orange er gjort mørkere for AA.
- **Gælder for:** alle visninger af talere (detalje, uddrag og fremtidige eksporter i UI).
- **Anbefaling:** Vedtag som standard? JA

### Segmenterede faner på øverste niveau (erstatter F007 v1's understregede `.source-tabs`)
- **Værdi:**
  - Sporet: `--track`, padding 4 px, radius 12 px.
  - Fanen: min-højde 40 px (44 px under 640 px), radius 8 px, 14 px.
  - Aktiv: `--segment-on`, `--shadow-seg`, 600 `--text`.
  - Antal: 14/500 `--text-muted`.
- **Hvorfor:** ejerens godkendte design.
- **Gælder for:** alle fremtidige opdelinger af en liste på øverste niveau.
- **Anbefaling:** Vedtag som standard? JA. v1-beslutningen "Kilde-faner som understregning" trækkes tilbage, mens "Antal på en fane" og "Problem-markør" fra v1 bevares.

### Avatarpalet med 6 farver
- **Værdi:** #3B5BDB, #0E7A55, #C2410C, #7C3AED, #0E7490, #BE185D, og #5B6475 til ukendt agent. Teksten er hvid, og farven vælges ud fra navnet.
- **Hvorfor:** hurtig visuel genkendelse af agenter. Alle består AA med 13/600 hvide initialer.
- **Gælder for:** alle fremtidige personvisninger.
- **Anbefaling:** Vedtag som standard? JA

### Spolelinje i stedet for bølgeform
- **Værdi:** `<input type="range">` med 6 px spor i `--chrome-seek-track` og afspillet del og knop i `--chrome-accent`.
- **Hvorfor:** spec'en tillader det, kræver ingen lyd i browseren og ingen inline style, og den består 1.4.11.
- **Gælder for:** F007.
- **Anbefaling:** Vedtag som standard? JA

---

**Tjekliste til visuel review**
- [ ] Ingen `style=""` noget sted (heller ikke på SVG, avatarer eller fremdrift). Avatarfarve via `.av-1` … `.av-6`/`.av-0`, og logofarver via klasser.
- [ ] Med `F007_kilder` slukket: ingen fontfiler hentes (tjek netværket), og sider og CSS-effekt er som før F007.
- [ ] Fem woff2-filer i `assets/fonts/` med de navne, briefet angiver, plus `OFL-Inter.txt` og `OFL-Inter-Tight.txt`. Ingen kald til fonts.googleapis.com eller andre eksterne værter, og serveren sender `font/woff2`.
- [ ] H1, H2, logonavn og kø-tal står i Inter Tight 700. Al anden tekst står i Inter. Ingen monospace på tidsstempler.
- [ ] #7A8394 og #B4561F findes ikke i CSS'en. Alle farver er tokens fra briefet.
- [ ] Sidebaren er mørk i begge temaer. I mørkt tema har sidebar og afspiller en 1 px `--chrome-border`. Fokusringen i chrome er #7C9CFF.
- [ ] Under 900 px vises en topbar på 56 px med en menu-knap på 44 × 44 px. Ved 320 px er der ingen vandret scroll på liste, detalje, Konto, Brugere og login (begge temaer).
- [ ] Fanerne har koncentriske hjørner (spor 12 px, fane 8 px, padding 4 px). Den aktive fane er hvid (mørkt #2A3140), har skygge og er 600. Antallet er altid `--text-muted`. Under 640 px er fanerne lige brede og 44 px høje.
- [ ] Opkaldskort: avatar 36 px, navn 15/600, metalinje 13 px, varighed i tabulære tal, status-pille 24 px med prik via `::before`. Kilde-tag og Succes-lead-tag (med trofæ) er kantede tags på 6 px, ikke piller. Uddraget er klippet til to linjer med "Agent:" og "Kunde:" i talerfarver.
- [ ] Zendesk-kort viser Ticket med `external-link`-ikon, og Adversus-kort viser Kampagne · Lead. Der er ingen succes-pille på Zendesk-fanen.
- [ ] Bobler: Agent til venstre med spids øverst til venstre, Kunde til højre med spids øverst til højre, max-width 78 % (88 % under 640 px). Hover gør fyldet mørkere, og den aktuelle boble har en ring på 2 px i talerens farve.
- [ ] Uden talere: én kolonne med et tidsstempel på 56 px i `--accent-ink` og `.is-current` med en streg på 3 px.
- [ ] Afspiller: mørkt kort med radius 16 px, play-knap på 44 px i #7C9CFF, spolelinje på 6 px (`--chrome-seek-track` mod `--chrome-accent`), tider i tabulære tal og fem hastigheder (0,75× … 2×), hvor den aktive har `--chrome-control`. Der er ingen bølgeform.
- [ ] Søgelinje: input på 16 px, og ringen sidder på hele kortet, når inputtet har fokus (ingen dobbelt ring, når en knap i kortet har fokus).
- [ ] "←", "→" og "↗" står ikke som tekst. Der bruges `arrow-left`, chevrons og `external-link`.
- [ ] Nye ikoner i `Ui::ICONS` er `phone`, `user-round`, `users`, `menu`, `x`, `search`, `play` og `pause` (`rotate-ccw`/`rotate-cw` kun hvis UI/UX v2 vil have dem), og `.icon-20` findes.
- [ ] Mørkt tema: hover på kort ændrer kun kanten. Alle piller og tags har mørke `--*-bg` (ingen lyse flader i mørkt tema).
- [ ] Alle klikbare elementer er mindst 44 px høje under 640 px.
- [ ] `prefers-reduced-motion` slår prikkens puls og glat rulning fra.

---

**Kilder til briefet:**
- `C:\ClaudeCoworkers\whisper.xanouso.com\product-ai\F007_v2.md`
- `C:\Users\Albers\AppData\Local\Temp\claude\a531192545503e18\ea325110-936a-4af6-9b77-461f66b7f58a\scratchpad\design\project\v2-Opkald.dc.html`
- `C:\Users\Albers\AppData\Local\Temp\claude\a531192545503e18\ea325110-936a-4af6-9b77-461f66b7f58a\scratchpad\design\project\v2-Detalje.dc.html`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app.css`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\Ui.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\App.php` (CSP, linje 579)
- `C:\ClaudeCoworkers\whisper.xanouso.com\graphic-design\F007_visual_v1.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\graphic-design\F005_visual_v1.md`

**Til Team Lead:**
- Gem briefet som `graphic-design/F007_visual_v2.md`.
- `ui-ux/F007_design_v2.md` findes ikke endnu, selvom spec v2 kalder den bindende. Hvis den tilføjer eller fjerner elementer (fx "Find i samtalen", spoleknapper eller fremdriftslinjen i kø-kortet), gælder de visuelle regler her stadig for dem.
- To beslutninger i briefet afviger fra vedtagne F005-beslutninger (webfont og logomærke) og skal logges, når de vedtages.