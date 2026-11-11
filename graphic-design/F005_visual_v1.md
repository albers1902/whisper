# F005 — Visuelt design-brief til Whisper, v1
# Status: READY_FOR_IMPLEMENTATION

Kilder jeg har læst på main: `homedir/public_html/assets/app.css`, `homedir/src/View.php`, `homedir/src/CallView.php`, CSP'en i `homedir/src/App.php:375`, `launched/STABLE/DECISIONS.md`, `SPRINT.md` og `ROADMAP.md`. `DESIGN_LOG.md` og `PRD.md` findes ikke, og der er ingen tidligere briefs i `ui-ux/` eller `graphic-design/`. Det betyder, at alle visuelle valg herunder er nye beslutninger (se det sidste afsnit). Ingen af dem afviger fra noget, der allerede er vedtaget.

Visuel reference: Claude Design-lærredet "Whisper UI 2026" (https://claude.ai/artifact/RPsUbpGfccGjjkYtWo9cbJ). Hvor lærred og brief afviger i værdier, gælder tokens i dette brief.

**Begrænsninger fra CSP'en, som briefet er bygget efter:**
- `style-src 'self'`: der må ikke være `style=""`-attributter, heller ikke på SVG-elementer. Ikoner farves med attributterne `fill`/`stroke="currentColor"`.
- `img-src 'self'`: `data:`-URI'er i CSS er blokeret. Der laves derfor ingen custom select-pil eller baggrundsikoner via `url(data:…)`. Select-felter beholder den indbyggede pil, og `color-scheme` får den til at følge temaet.
- `script-src` falder tilbage til `'self'`. En kopiér-knap kræver derfor en ekstern fil, `/assets/app.js`, uden inline-handlers. Uden JS skal den skjules (`hidden`), og selve adfærden er UI/UX' område.

---

## 1) Designprincipper
1. **Indholdet er i fokus:** transcripts og opkaldsdata står i fokus, mens rammen (header, kort, rammer) står i neutrale toner.
2. **Én accentfarve:** blå bruges kun til handlinger, links og fokus. Statusfarver bruges kun til status.
3. **Tydelige flader:** hierarkiet bygges med flader og 1 px rammer, ikke med tunge skygger. Mørkt tema bruger lysere flader i stedet for skygger.
4. **Tæt og læsbart:** 16 px brødtekst, 15 px i tabeller, tabulære tal og et fast 4 px-grid.
5. **Robust:** ingen eksterne ressourcer, AA-kontrast i begge temaer, og alt virker uden JS.

---

## 2) Design tokens

Lyst tema står i `:root`. Mørkt tema overskriver de samme navne i `@media (prefers-color-scheme: dark) { :root { … } }`. `:root` har altid `color-scheme: light dark;` (så dato-, select- og audio-kontroller følger temaet) og `accent-color: var(--accent);`.

### Farver: lyst tema (kontrast målt efter WCAG-formlen)
```css
--bg: #f6f7f9;              /* side */
--surface: #ffffff;         /* kort, header, tabel */
--surface-2: #eef1f5;       /* tabelhoved, hover, kode, player */
--border: #e2e6ec;          /* dekorative rammer, skillelinjer */
--border-strong: #7b8495;   /* felt-rammer: 3.77 på surface, 3.51 på bg, 3.32 på surface-2 (≥3:1, 1.4.11) */
--text: #141a24;            /* 17.46 på surface, 16.29 på bg */
--text-muted: #5a6372;      /* 6.07 på surface, 5.66 på bg, 5.35 på surface-2 */
--accent: #2456d3;          /* links 6.27 på surface, 5.85 på bg */
--accent-hover: #1c46b0;
--accent-contrast: #ffffff; /* på accent 6.27, på accent-hover 8.23 */
--accent-subtle: #e9effd;   /* aktiv nav, info-bg. accent-hover-tekst på den: 7.14 */
--focus: #2456d3;           /* 5.85 mod bg */
--success-text: #17663a;  --success-bg: #e5f4eb;  --success-border: #b5dec5;  /* 6.16 */
--info-text: #1c46b0;     --info-bg: #e9effd;     --info-border: #c5d5fa;     /* 7.14 */
--warning-text: #8a4600;  --warning-bg: #fdf1de;  --warning-border: #f3d199;  /* 6.36 */
--danger-text: #a5221b;   --danger-bg: #fdecea;   --danger-border: #f5c2bd;   /* 6.46 */
--danger: #b42318;  --danger-hover: #962016;  --danger-contrast: #ffffff;    /* 6.57 / 8.35 */
--neutral-text: #454d5b;  --neutral-bg: #eef1f5;                              /* 7.52 */
--mark-bg: #fde68a;        /* tekst #141a24 på den: 14.02 */
--input-bg: #ffffff;
```

### Farver: mørkt tema
```css
--bg: #0e1117;
--surface: #161a22;
--surface-2: #1d222c;
--border: #2a303c;
--border-strong: #6a7386;   /* 3.66 på surface, 3.97 på bg, 3.35 på surface-2 */
--text: #e6e9ef;            /* 14.33 på surface, 15.54 på bg, 13.11 på surface-2 */
--text-muted: #9aa3b2;      /* 6.85 på surface, 7.43 på bg, 6.27 på surface-2 */
--accent: #7ea3ff;          /* 7.11 på surface, 7.71 på bg */
--accent-hover: #a3bdff;
--accent-contrast: #0b1222; /* på accent 7.63, på hover 10.04 (lys knap med mørk tekst) */
--accent-subtle: #18233d;   /* #a3bdff på den: 8.38 */
--focus: #7ea3ff;           /* 7.71 mod bg */
--success-text: #7dd8a3;  --success-bg: #11261a;  --success-border: #1f4a31;  /* 9.29 */
--info-text: #a3bdff;     --info-bg: #18233d;     --info-border: #2a3d6b;     /* 8.38 */
--warning-text: #f5bf6b;  --warning-bg: #2b2111;  --warning-border: #4d3a17;  /* 9.45 */
--danger-text: #ffa098;   --danger-bg: #2d1715;   --danger-border: #5a2622;   /* 8.62 */
--danger: #ff8a80;  --danger-hover: #ffa098;  --danger-contrast: #1a0a09;    /* 8.43 */
--neutral-text: #c3c9d4;  --neutral-bg: #242a35;                              /* 8.66 */
--mark-bg: #5c4a12;        /* tekst på den: 7.81 */
--input-bg: #0e1117;
```

### Typografi
Kun system-fonte, ingen selvhostet font. Begrundelse: Segoe UI Variable på Windows 11 (kundeservicens maskiner) og SF på Mac er moderne nok. En webfont ville give en ekstra fil, FOUT og licensspørgsmål uden at gøre læsbarheden bedre.
```css
--font-sans: "Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif;
--font-mono: ui-monospace, "Cascadia Mono", "SF Mono", SFMono-Regular, Consolas, "Liberation Mono", monospace;
--fs-sm: 0.8125rem;   /* 13px: hint, badge, th, tidsstempel, statuslinje */
--fs-md: 0.875rem;    /* 14px: labels, nav, knapper */
--fs-table: 0.9375rem;/* 15px: tabelceller, beskeder, metadata */
--fs-base: 1rem;      /* 16px: brødtekst, transcript, felter */
--fs-lg: 1.125rem;    /* 18px: h2, brand */
--fs-xl: 1.5rem;      /* 24px: h1 */
--lh-tight: 1.25;     /* overskrifter */
--lh-base: 1.5;       /* UI */
--lh-read: 1.65;      /* transcript */
--fw-regular: 400; --fw-medium: 500; --fw-semibold: 600;
```
| Element | Font og størrelse | Vægt | Linjehøjde | Farve |
|---|---|---|---|---|
| h1 | sans 24 px, `letter-spacing: -0.01em` | 600 | tight | text |
| h2 | sans 18 px | 600 | tight | text |
| Brødtekst | 16 px | 400 | 1.5 | text |
| Label | 14 px | 500 | 1.5 | text |
| Hint og caption | 13 px | 400 | 1.5 | text-muted |
| Knap | 14 px | 500 | 1 | se knapper |
| Fejltekst under et felt | 13 px | 500 | 1.5 | danger-text |

Tal i tabeller, metadata, pager og tidsstempler får `font-variant-numeric: tabular-nums`.

### Spacing (4 px-grid)
```css
--sp-1: 4px; --sp-2: 8px; --sp-3: 12px; --sp-4: 16px; --sp-5: 20px;
--sp-6: 24px; --sp-8: 32px; --sp-10: 40px; --sp-12: 48px;
```
- Sidepadding: 32 px top og bund, 24 px til siderne. Under 640 px: 20 px og 16 px.
- Kortpadding: 24 px. Under 640 px: 16 px.
- Mellem sektioner: 32 px. Mellem formularfelter: 16 px.
- Tabelceller: 12 px × 16 px, og th 10 px × 16 px.

### Radius, skygger, fokus, bevægelse og layout
```css
--radius-sm: 6px;     /* knapper, felter, kodefelter, transcript-rækker */
--radius-md: 10px;    /* kort, tabel, beskeder, player, filterpanel */
--radius-pill: 999px; /* badges */
/* lyst */
--shadow-1: 0 1px 2px rgba(16, 24, 40, 0.05), 0 1px 3px rgba(16, 24, 40, 0.06);
--shadow-2: 0 4px 12px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06);
/* mørkt */
--shadow-1: 0 1px 2px rgba(0, 0, 0, 0.40);
--shadow-2: 0 4px 12px rgba(0, 0, 0, 0.50);
--focus-ring: 2px solid var(--focus);
--focus-offset: 2px;
--dur: 120ms; --ease: cubic-bezier(0.2, 0, 0, 1);
--header-h: 56px;
--content-max: 1200px; --detail-max: 880px; --narrow-max: 440px;
```
- Fokus: `:focus-visible { outline: var(--focus-ring); outline-offset: var(--focus-offset); }`. Felter bruger `outline-offset: 1px` og `border-color: var(--focus)`. Fokus fjernes aldrig.
- Sticky header: `html { scroll-padding-top: calc(var(--header-h) + 8px); }`, så fokus ikke skjules (WCAG 2.2, 2.4.11).

---

## 3) Komponenter

**Header og nav** (`header.top`)
- `position: sticky; top: 0; z-index: 10;` højde 56 px, `--surface`, `border-bottom: 1px solid var(--border)`. Indholdet er inde i en 1200 px kolonne med padding 0 24 px.
- Brand: SVG-mærke på 20 px i `--accent` (fem lodrette bølgebjælker, se ikoner) plus "Whisper" i 18 px/600 `--text`. Afstand 8 px. Ingen understregning.
- Nav-links: 14 px/500 `--text-muted`, padding 6 px × 10 px, `--radius-sm`, ingen understregning. Hover: `--text` på `--surface-2`. Aktiv (`[aria-current="page"]`): `--accent-hover` (lys) eller `--accent` (mørk) på `--accent-subtle`.
- Til højre: `.who` i 14 px `--text-muted` og "Log ud" som `.ghost`-knap med log-out-ikon.
- Under 640 px skjules `.who` (navnet står på Konto), afstanden mellem links bliver 4 px, og headeren må ombrydes til to rækker (højde auto).

**Knapper** (`button`, `.button`, med primær som standard)
- Højde 36 px (`min-height`), padding 0 14 px, `--radius-sm`, 14 px/500, `display: inline-flex; align-items: center; gap: 8px`. Ikon 16 px. `transition: background-color, border-color, color var(--dur) var(--ease)`.
- Primær: `--accent` med `--accent-contrast`. Hover `--accent-hover`. Active: `--accent-hover` plus `box-shadow: inset 0 1px 2px rgba(0,0,0,.15)`.
- `.secondary`: `--surface`, tekst `--text`, `1px solid var(--border-strong)`, `--shadow-1`. Hover `--surface-2`. Bruges til "Download alle (.zip)", "Download transcript (.txt)", "Nulstil adgangskode …", "Annuller", "Nulstil" (filter) og pager.
- `.danger`: `--danger` med `--danger-contrast`. Hover `--danger-hover`.
- `.link`: ingen baggrund, ingen ramme, `--accent`, `text-decoration: underline; text-underline-offset: 3px`, højde auto og padding 0.
- `.ghost`: ingen ramme, `--text-muted`. Hover `--text` på `--surface-2`.
- `.small`: højde 32 px, padding 0 10 px, 13 px. Bruges til pager og kopiér.
- Disabled (`:disabled`, `[aria-disabled="true"]`): `opacity: .5; cursor: not-allowed;` og ingen hover-effekt.
- Regel: højst én primær knap pr. visning.

**Formularfelter**
- `label`: blok, 14 px/500 `--text`, `margin-bottom: 16px`. Feltet ligger 6 px under labelteksten.
- `input`, `select`, `textarea`: min-højde 40 px, padding 8 px × 12 px, 16 px (forhindrer zoom på iOS), `--input-bg`, `1px solid var(--border-strong)`, `--radius-sm`, `color: var(--text)`. Hover: `border-color: var(--text-muted)`. Focus-visible: `border-color: var(--focus)` og ring med offset 1 px.
- `::placeholder`: `--text-muted`.
- `[aria-invalid="true"]`: `border-color: var(--danger)`, og fejlteksten `.field-error` står i 13 px/500 `--danger-text`.
- `.hint`: 13 px/400 `--text-muted`, margin-top 6 px.
- `[readonly]`: baggrund `--surface-2`.
- `input.otp`: mono 18 px, `letter-spacing: .04em`, `--surface-2`.

**Filterpanel** (`form.filters`)
- `--surface`, ramme `--border`, `--radius-md`, padding 16 px, `margin-bottom: 16px`.
- `display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px 16px; align-items: end;` `label.wide` får `grid-column: span 2`. Under 640 px er det én kolonne, og `span 1`.
- Handlingerne står til højre i den sidste celle: Filtrér (primær) og Nulstil (`.secondary`).

**Tabel og listerækker** (`table`, `table.calls`)
- `border-collapse: separate; border-spacing: 0; width: 100%;` `--surface`, `1px solid var(--border)`, `--radius-md`, `overflow: hidden`, `--shadow-1`.
- `th`: 13 px/600 `--text-muted`, baggrund `--surface-2`, padding 10 px × 16 px, `border-bottom: 1px solid var(--border)`. Ingen versaler.
- `td`: 15 px, padding 12 px × 16 px, `border-bottom: 1px solid var(--border)`. Sidste række har ingen ramme. `vertical-align: middle`, tabulære tal.
- `table.calls tbody tr:hover`: `--surface-2`.
- Første kolonnes link: `--text` 500 uden understregning. Hover og fokus: `--accent` med understregning.
- Under 640 px bliver hver `tr` et kort: blok, `--surface`, ramme `--border`, `--radius-md`, padding 12 px × 16 px, `margin-bottom: 8px`. `td` er en blok med padding 2 px 0 og ingen ramme. `td[data-label]::before { content: attr(data-label); display: block; font-size: var(--fs-sm); color: var(--text-muted); }`. Selve tabellen har ingen ramme eller baggrund.

**Badges for opkaldsstatus** (`.badge`)
- `inline-flex`, gap 6 px, højde 22 px, padding 0 8 px, `--radius-pill`, 13 px/500, `white-space: nowrap`. `::before`: 6 × 6 px prik i `currentColor`, radius 50 %.

| Klasse | Tekst | Farver |
|---|---|---|
| `s-faerdig` | Færdig | success-text på success-bg |
| `s-transcriberer` | Transcriberer | info-text på info-bg. Prikken pulserer (opacity 1 til .35, 1.6 s ease-in-out, uendeligt). Ingen animation med `prefers-reduced-motion: reduce` |
| `s-ventende` | Venter | neutral-text på neutral-bg |
| `s-lyd` | Lyd mangler | warning-text på warning-bg |
| `s-fejlet` | Fejlet / Lyd fejlet | danger-text på danger-bg |

Alle farvepar består AA (6.16–9.45). Teksten bærer betydningen, så farven er ikke den eneste markør.

**Kort og paneler** (`.card`)
- `--surface`, `1px solid var(--border)`, `--radius-md`, `--shadow-1`, padding 24 px (16 px under 640 px).
- `.card > h1:first-child` og `h2:first-child` har margin-top 0. `h2` i et kort har margin 32 px 0 12 px.
- `.card.narrow`: max 440 px, centreret, margin-top 48 px på login. Alle andre steder 0.
- `.detail` (opkaldsdetaljen): max 880 px, centreret.

**Metadata** (`dl.meta`)
- `display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px 24px;` padding-bottom 24 px og `border-bottom: 1px solid var(--border)`.
- Hvert par står i `<div>`: `dt` i 13 px `--text-muted` og `dd` i 15 px/500 `--text` med tabulære tal og margin 2 px 0 0.

**Beskeder** (`.msg` med `.info`, `.ok`, `.error`, `.warn`)
- `display: flex; gap: 10px; align-items: flex-start;` padding 12 px × 14 px, `--radius-md`, `1px solid` statusrammen, 15 px, margin 0 0 16 px.
- Ikon på 18 px (`flex: none; margin-top: 2px`) i statusfarven.
- `.info` bruger info-farverne, `.ok` success, `.warn` warning (ny klasse; skal bruges til rate limit-beskeden, som i dag er `.info`) og `.error` danger.

**Tom tilstand** (`.empty`)
- Centreret, padding 48 px × 24 px, `--surface`, `1px dashed var(--border-strong)`, `--radius-md`.
- Ikon på 24 px i en 48 px cirkel (`--surface-2`, `--text-muted`), margin-bottom 12 px.
- `.empty-title`: 16 px/600 `--text`. `.empty-text`: 14 px `--text-muted`, max 48ch.

**Paginering** (`.pager`)
- `display: flex; justify-content: space-between; align-items: center; margin-top: 16px`.
- Forrige og Næste er `.button.secondary.small` med chevron-ikon på 16 px. Midt imellem står "Side 2 af 9" i 14 px `--text-muted` med tabulære tal.
- Er man på første eller sidste side, vises knappen som `<span class="button secondary small" aria-disabled="true">`, så layoutet ikke hopper.

**Lydafspiller-ramme** (`.player`)
- `--surface-2`, `1px solid var(--border)`, `--radius-md`, padding 12 px × 16 px, margin 24 px 0.
- Øverst `.player-label`: "Optagelse" med headphones-ikon på 16 px, 13 px/500 `--text-muted`, margin-bottom 8 px.
- `audio { display: block; width: 100%; height: 40px; }`. Selve kontrollerne styles ikke (de følger `color-scheme`).
- Lyd-fejl og venter-beskeder står i den samme ramme som `.msg`, uden player-baggrund.

**Transcript** (`.transcript`)
- Header-rækken `.row` har h2 til venstre og `.button.secondary` med download-ikon til højre.
- Containeren: max-width 75ch, `margin-top: 12px`.
- Hvert segment `p`: `display: grid; grid-template-columns: 4.5rem 1fr; gap: 16px;` padding 8 px × 12 px, `--radius-sm`, margin 0, 16 px/`--lh-read`. Hover: `--surface-2`.
- `.ts`: mono 13 px `--text-muted`, tabulære tal, `padding-top: 3px` (flugter med brødtekstens grundlinje). Kantede parenteser fjernes i HTML'en; .txt-downloaden beholder dem.
- Fremhævning: `mark` får `--mark-bg`, `color: inherit`, `border-radius: 3px`, `padding: 0 2px`.
- Fremtidigt aktivt segment (`.is-active`): `--accent-subtle` plus `box-shadow: inset 3px 0 0 var(--accent)`.
- Under 640 px: én kolonne, og `.ts` står over teksten.

**Kode og nøgle med kopiér** (`.secret` og `pre.code`) til OTP og de kommende MCP-nøgler
- `.secret`: `display: flex; gap: 8px; align-items: stretch;`
  - Værdien er en readonly-`input` eller `code`: `flex: 1`, mono 15 px, `--surface-2`, ramme `--border-strong`, `--radius-sm`, padding 10 px × 12 px, `overflow-wrap: anywhere`.
  - Kopiér-knappen er `.button.secondary` med copy-ikon og teksten "Kopiér". Efter klik vises check-ikonet (i `--success-text`) og "Kopieret" i 2 s. Knappen har attributten `hidden` i HTML og vises af `/assets/app.js`.
  - Under 400 px står knappen under feltet i fuld bredde.
- `pre.code` (fx Claude Desktop-config): mono 13.5 px (`0.84375rem`), linjehøjde 1.6, `--surface-2`, ramme `--border`, `--radius-md`, padding 16 px, `overflow-x: auto`, max-height 320 px. Kopiér-knappen er placeret absolut øverst til højre (`.code-wrap { position: relative }`, knap top 8 px og right 8 px, `.small`).

---

## 4) Ikoner
- Kun inline SVG i skabelonerne, ingen ikonfont og ingen eksterne filer. Path-data kopieres fra **Lucide** (ISC-licens; licensteksten lægges i en kommentar i den PHP-helper, der udskriver dem).
- Fælles attributter: `viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"`, og ingen `style`-attribut. Ikonerne arver tekstfarven.
- Størrelser: 16 px i knapper og nav, 18 px i beskeder og 24 px i tom tilstand. CSS: `.icon { width: 1em; height: 1em; flex: none; }` med eksplicitte størrelser via `.icon-16`, `.icon-18` og `.icon-24`.
- Kun disse 14 ikoner:

| Ikon | Bruges til |
|---|---|
| `phone-incoming` / `phone-outgoing` | Retning i tabel og meta, foran teksten Indgående/Udgående, i `--text-muted` |
| `download` | Zip og transcript |
| `copy` / `check` | Kopiér og Kopieret |
| `external-link` | Ticket-link til Zendesk, 14 px efter tallet |
| `info`, `circle-check`, `triangle-alert`, `circle-alert` | Beskederne info, ok, warn og error |
| `chevron-left` / `chevron-right` | Pager og "Alle opkald"-tilbagelinket |
| `headphones` | Player-label |
| `log-out` | Log ud |
| `key-round` | MCP-nøgler (kommende) |
| `inbox` | Tom tilstand |

- Brand-mærke (ikke Lucide): fem afrundede bjælker, `fill="currentColor"` i `--accent`, viewBox 0 0 20 20. Bjælkerne er `<rect>` med x = 1, 5, 9, 13, 17, width 2, rx 1 og højderne 6, 12, 16, 10, 4, centreret lodret (y = 7, 4, 2, 5, 8).
- Ingen ikoner på tabelkolonner, labels eller i overskrifter.

---

## 5) Skitse til ny app.css (rækkefølge og selektorer)
```
/* 0. Header-kommentar: Whisper — design tokens + komponenter. Ingen eksterne ressourcer (CSP). */

/* 1. Tokens */
:root { color-scheme: light dark; accent-color: var(--accent); --…(lyst) }
@media (prefers-color-scheme: dark) { :root { --…(mørkt) } }

/* 2. Base */
*, *::before, *::after { box-sizing: border-box }
html { scroll-padding-top; -webkit-text-size-adjust: 100% }
body { margin:0; background:--bg; color:--text; font: --fs-base/--lh-base --font-sans; -webkit-font-smoothing: antialiased }
h1, h2, h3 · p · a, a:hover · strong · small · code, kbd, pre, .mono · mark · ::selection (accent-subtle)
:focus-visible · .visually-hidden · [hidden] { display:none !important }
.icon, .icon-16, .icon-18, .icon-24

/* 3. Layout */
header.top · .top-inner · main · .narrow · .detail · .row · .stack (lodret gap 16) · .muted · .status · .back

/* 4. Header og nav */
.brand · .brand svg · nav · nav a · nav a:hover · nav a[aria-current="page"] · nav .who · nav form.inline

/* 5. Knapper */
button, .button · :hover · :active · .secondary · .danger · .link · .ghost · .small
:disabled, [aria-disabled="true"] · div.actions, form.actions · form.inline

/* 6. Formularer */
label · input, select, textarea · :hover · :focus-visible · ::placeholder · [readonly]
[aria-invalid="true"] · .hint · .field-error · input.otp
form.filters · form.filters label · form.filters label.wide · form.filters .actions

/* 7. Kort og metadata */
.card · .card > :first-child · .card h2 · dl.meta · dl.meta > div · dl.meta dt · dl.meta dd

/* 8. Tabeller */
table · th · td · tbody tr:last-child td · table.calls tbody tr:hover
table.calls td:first-child a · .num (tabular-nums) · .dir (ikon + tekst)

/* 9. Badges */
.badge · .badge::before · .s-faerdig · .s-transcriberer · .s-ventende · .s-lyd · .s-fejlet

/* 10. Beskeder */
.msg · .msg .icon · .msg.info · .msg.ok · .msg.warn · .msg.error

/* 11. Tom tilstand og pager */
.empty · .empty-icon · .empty-title · .empty-text · .pager · .pager .page-info

/* 12. Afspiller og transcript */
.player · .player-label · audio · .transcript · .transcript p · .transcript p:hover
.transcript .ts · .transcript mark · .transcript .is-active

/* 13. Kode og nøgler */
.secret · .secret input, .secret code · .code-wrap · pre.code · .code-wrap .button

/* 14. Bevægelse */
@keyframes pulse · .s-transcriberer::before { animation }
@media (prefers-reduced-motion: reduce) { *, ::before { animation: none !important; transition: none !important } }

/* 15. Responsivt */
@media (max-width: 640px) { header.top · nav .who · main padding · .card padding ·
  table→kort (table, thead, tbody, tr, th, td; thead visually-hidden; td[data-label]::before) ·
  form.filters (1 kolonne) · .transcript p (1 kolonne) · .pager }
@media (max-width: 400px) { .secret (kolonne) · div.actions > * (fuld bredde) }
```

**Skabelonændringer, engineeren skal lave i `View.php` og `CallView.php`** (de er nødvendige for, at CSS'en kan virke):
1. `View::layout` sætter `aria-current="page"` på det aktive nav-link (layout skal kende den aktive side) og lægger headerens indhold i `<div class="top-inner">`.
2. Hver `td` i `table.calls` og i brugertabellen får `data-label="…"` med kolonnenavnet.
3. `CallView::show`: `<div class="detail">` omslutter tilbagelinket og kortet, `dl.meta`-par står i `<div>`, `<audio>` ligger i `<div class="player">` med `.player-label`, og `.ts` står uden kantede parenteser.
4. Den tomme tilstand ændres fra `<p class="empty">` til `<div class="empty">` med ikon, `.empty-title` og `.empty-text`.
5. Rate limit-beskeden får `msg warn`. `View::message` og de andre `.msg` får det rigtige ikon først.
6. Sekundære handlinger får `class="button secondary"`: Download alle, Download transcript, Nulstil adgangskode, Annuller og Nulstil (filter). Annuller- og Nulstil-links bliver knapper.
7. Pager bliver `<nav class="pager" aria-label="Sider">` med knapper og `.page-info`.
8. OTP-visningen bruger `.secret` med en kopiér-knap og `hidden`, plus `/assets/app.js` (ekstern fil, CSP-sikker). Adfærden afklares med UI/UX.

---

## Visuelle tilstande (opsummeret)
- **Loading:** der er ingen spinner. Igangværende transcription vises med den pulserende badge-prik.
- **Error:** ramme og tekst i danger og et ikon. Der er aldrig kun farve.
- **Success:** `.msg.ok` med circle-check, og den bliver stående (ingen toast og ingen auto-fade).
- **Disabled:** opacity .5 og `not-allowed`.
- **Hover:** kun en farveskift på baggrund eller ramme, ingen scale og ingen skyggeskift.
- **Focus:** en 2 px ring i `--focus` med offset 2 px (1 px på felter).

## Animation
Kun `transition` (120 ms, `cubic-bezier(.2,0,0,1)`) på `background-color`, `border-color` og `color`, plus den ene pulse-animation. Begge slås fra med `prefers-reduced-motion`.

## Brandnoter
- Accenten flyttes fra `#2b5fd9` til `#2456d3`: samme blå familie, lidt dybere og med bedre kontrast (6.27 mod hvid). Det bryder ikke med den nuværende app.
- Bølgebjælke-mærket refererer til lyd og tale uden at være et telefonikon.
- Ingen TrygEnergi-farver er brugt, fordi ingen er vedtaget. Ønsker ejeren TrygEnergis brandfarve, skal kun `--accent*` udskiftes, og kontrasten skal regnes igen.

## Nye visuelle beslutninger i dette brief (der findes ingen DESIGN_LOG.md, så alle er nye)

### Design tokens og to temaer
- **Hvad:** Hele tokensættet i afsnit 2, med lyst og mørkt tema via `prefers-color-scheme`.
- **Hvorfor:** ét sted til farver og mål og AA i begge temaer.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### System-fontstak, ingen webfont
- **Værdi:** `--font-sans` og `--font-mono` som i afsnit 2.
- **Hvorfor:** CSP og ingen eksterne ressourcer, og Segoe UI Variable er moderne på målmaskinerne.
- **Anbefaling:** Vedtag som standard? JA

### Ikoner: Lucide-paths som inline SVG, 14 navngivne ikoner, stroke 1.75
- **Hvorfor:** ingen ikonfont, CSP-sikkert, og et ensartet udtryk.
- **Anbefaling:** Vedtag som standard? JA

### Badge-farver pr. opkaldsstatus
- **Værdi:** tabellen i afsnit 3.
- **Gælder for:** F002 og F004 (Adversus skal bruge samme status-klasser).
- **Anbefaling:** Vedtag som standard? JA

### Brand-mærke (fem bølgebjælker)
- **Hvorfor:** Whisper har ingen visuel identitet i dag.
- **Anbefaling:** Vedtag som standard? JA, men Team Lead bør spørge ejeren, om TrygEnergis eget logo eller farve skal bruges i stedet.

### `/assets/app.js` til kopiér-knapper
- Nødvendig for kopiér-komponenten under CSP'en. Afgøres af Team Lead og UI/UX.
- **Anbefaling:** Vedtag som standard? JA (progressiv forbedring, intet inline).
