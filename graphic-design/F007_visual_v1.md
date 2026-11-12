# F007 — Visuelt design-brief: Zendesk og Adversus hver for sig, v1
# Status: READY_FOR_IMPLEMENTATION

Jeg har læst følgende i hovedmappen (main @ 98f90ac): `product-ai/F007_v1.md`, `ui-ux/F007_design_v1.md` (især §2, §2.2, §9, §11 og §13.1), `graphic-design/F005_visual_v1.md`, `graphic-design/F004_visual_v1.md`, `homedir/public_html/assets/app.css`, `homedir/src/Ui.php` (`icon()`, `ICONS`) og `homedir/src/UiCalls.php` (`statusLine`, `sourceLine`).

**Grundlag:**
- `DESIGN_LOG.md` og `PRD.md` findes ikke. F005- og F004-briefene er derfor rammen.
- Den kørende `app.css` er sandheden, og alle værdier herunder er tokens, der allerede står i den.

**Omfang:**
- **Ingen nye farvetokens og intet nyt ikon.** `circle-alert` og `triangle-alert` findes allerede i `Ui::ICONS`.
- **Ingen inline style.** Ikoner farves via `currentColor` og en klasse på et omsluttende element, som F005 §4 kræver under CSP'ens `style-src 'self'`.
- **Nye komponentklasser:** `.source-bar`, `.source-tabs`, `.tab-count`, `.tab-flag`, `.tab-flag-error` og `.tab-flag-warn`.
- **Flaget slukket:** når `F007_kilder` er slukket, renderes ingen af klasserne, og intet eksisterende ændres (AK5).
- **Motorlinjen** (UI/UX §2.3) bruger de eksisterende `.sync`, `.sync-line` og `.dot-warn` uden ny CSS.

---

# Color usage

Fanerne står direkte på sidens `--bg`, ikke i et kort. Kontrasten er regnet med WCAG-formlen ud fra tokenværdierne i app.css. Hover-baggrunden er `--surface-2`.

| Element | Token | Lyst | Mørkt | Kontrast lys / mørk |
|---|---|---|---|---|
| Inaktiv fane, navn | `--text-muted` | #5a6372 | #9aa3b2 | 5.66 / 7.43 på bg |
| Inaktiv fane, hover: navn | `--text` på `--surface-2` | #141a24 på #eef1f5 | #e6e9ef på #1d222c | 15.39 / 13.11 |
| Aktiv fane, navn | `--text` på `--bg` (transparent) | #141a24 | #e6e9ef | 16.29 / 15.54 |
| Aktiv fane, streg 2 px | `--accent` | #2456d3 | #7ea3ff | 5.85 / 7.71 mod bg (≥ 3:1, 1.4.11) |
| Inaktiv fane, streg | transparent | — | — | — |
| `.tab-count` (alle tilstande) | `--text-muted` | #5a6372 | #9aa3b2 | 5.66 / 7.43 på bg. 5.35 / 6.27 på surface-2 (hover) |
| Markør `circle-alert` (sync stoppet) | `--danger-text` | #a5221b | #ffa098 | 6.88 / 9.65 på bg. 6.50 / 8.14 på surface-2 |
| Markør `triangle-alert` (advarsel) | `--warning-text` | #8a4600 | #f5bf6b | 6.62 / 11.28 på bg. 6.26 / 9.51 på surface-2 |
| Linjen under `.source-bar` | `--border` | #e2e6ec | #2a303c | Dekorativ. Tilstanden bæres af den aktive streg og vægten |
| Kildens statuslinje (`.sync-line` i baren) | `--text-muted` + eksisterende `.dot-*` | som `.sync` | som `.sync` | 5.66 / 7.43 på bg (uændrede regler) |
| Fokus | `--focus` (global ring) | #2456d3 | #7ea3ff | 5.85 / 7.71 mod bg |

**Regler:**
- Accent bruges kun som stregen under den aktive fane, aldrig som tekstfarve eller fyld på fanerne. Sådan adskilles fanerne (niveau 1) fra `.segmented` (niveau 2), hvor det aktive valg er `--nav-active` på `--accent-subtle`.
- Den aktive fane har ingen baggrundsfarve. Hverken `--accent-subtle` eller `--surface` må bruges, for så ligner den `.segmented` eller hovedmenuen.
- Tallet har altid `--text-muted`, også på den aktive fane, og det har ingen baggrund, ingen ramme og ingen prik. Det må ikke ligne et `.badge` eller en `.chip`.
- Markøren er den eneste statusfarve på fanerne. Formen (cirkel eller trekant) og den skjulte tekst bærer betydningen, så farven er ikke den eneste markør.

# Typography

| Element | Størrelse | Vægt | Linjehøjde | Farve |
|---|---|---|---|---|
| Fanenavn, inaktiv | `--fs-base` 16 px | 500 | `--lh-tight` 1.25 | `--text-muted` |
| Fanenavn, aktiv | 16 px | **600** | 1.25 | `--text` |
| `.tab-count` | `--fs-md` 14 px | 500 (også på den aktive fane) | arver | `--text-muted` |
| Statuslinje i `.source-bar` | `--fs-sm` 13 px | 400 | `--lh-base` 1.5 | `--text-muted` (som `.sync`) |
| Fælles kø- og motorlinje i `.page-head` | uændret `.sync` (13 px) | 400 | 1.5 | `--text-muted` |

- Tallet har tabulære cifre (`font-variant-numeric: tabular-nums`) og tusindtalspunktum fra serveren ("1.234"). Der er ingen parenteser omkring tallet.
- Hierarkiet er H1 24/600, så faner 16/500–600, så `.segmented` 14/500–600. Fanerne er altså tydeligt større end niveau 2.
- Der bruges ingen versaler og ingen letter-spacing.

# Spacing and layout

**`.source-bar` (desktop, over 640 px):**
```
display: flex; align-items: center; justify-content: space-between; flex-wrap: nowrap;
column-gap: var(--sp-4) /* 16px */; margin: 0 0 var(--sp-4) /* 16px */;
border-bottom: 1px solid var(--border);
```
- `nav.source-tabs` har `flex: none`.
- Statuslinjen `.source-bar .sync-line` har:
  ```
  flex: 1 1 0; min-width: 0; margin: 0; padding: var(--sp-1) 0;
  text-align: right; font-size: var(--fs-sm); color: var(--text-muted);
  ```
  Den eksisterende `.dot` giver prikken.
- **Afvigelse fra UI/UX §2 (`flex-wrap: wrap`), kun visuelt:** den længste statustekst er "Adversus bad os vente. Hentningen fortsætter ved næste kørsel." (ca. 400 px ved 13 px). Med wrap ville den i 641–800 px falde ned under fanerne, og så ville linjen og den aktive streg ikke længere sidde under fanerne. Med `nowrap` og `min-width: 0` ombrydes teksten i stedet inden for sin egen kolonne til højre (højst to linjer à 19,5 px i en række på 40 px). Under 640 px stables rækken (se Responsivt), så det UI/UX-briefet vil opnå med wrap, holder stadig.

**`.source-tabs`:**
```
.source-tabs ul { display: flex; gap: var(--sp-1) /* 4px */; list-style: none; margin: 0 0 -1px; padding: 0; }
.source-tabs a {
  display: inline-flex; align-items: center; gap: var(--sp-2) /* 8px */;
  min-height: 40px; padding: 0 var(--sp-3) /* 12px */;
  border-bottom: 2px solid transparent; border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  font: 500 var(--fs-base)/var(--lh-tight) var(--font-sans); color: var(--text-muted);
  text-decoration: none; white-space: nowrap;
}
```
- `margin-bottom: -1px` på `ul` får fanernes 2 px-streg til at dække barens 1 px-linje. Den aktive fane "sidder" på linjen (UI/UX §1.1 punkt 3).
- De 40 px er hele fanens højde inklusive stregen.
- Rækkefølgen inde i linket er navn, så `.tab-count`, så `.tab-flag` (kun når det er relevant). Den skjulte tekst "opkald" og markørteksten er `.sr-only`. `.sr-only` er absolut positioneret og påvirker derfor hverken gap eller bredde.
- Der står 8 px mellem navn og tal og mellem tal og markør (samme `gap`, ingen særregler).

**`.tab-count`:** `font-size: var(--fs-md); font-weight: 500; color: var(--text-muted); font-variant-numeric: tabular-nums;`. Ingen padding, baggrund eller radius.

**`.tab-flag`:** `display: inline-flex;` er en wrapper om ikonet (`Ui::icon('circle-alert', 14)` eller `Ui::icon('triangle-alert', 14)`, der allerede har `aria-hidden="true"`). Farven sættes med `.tab-flag-error { color: var(--danger-text); }` og `.tab-flag-warn { color: var(--warning-text); }`. Ingen `style`-attribut på SVG'en.

**Afstande på siden** (uændrede tokens): `.page-head` margin-bottom 16 px, så flash (`.msg` margin-bottom 16 px), så `.source-bar` margin-bottom 16 px, så kildens `.msg`-bokse og filterkortet. Der kommer ingen ny luft.

**Under 640 px:**
```
.source-bar { flex-direction: column; align-items: stretch; row-gap: var(--sp-2); border-bottom: 0; }
.source-tabs { border-bottom: 1px solid var(--border); }
.source-tabs li { flex: 1; }
.source-tabs a { width: 100%; justify-content: center; min-height: 44px; padding: 0 var(--sp-2); }
.source-bar .sync-line { text-align: left; padding: 0; }
```
- Linjen flyttes fra baren til `nav`, så den stadig ligger lige under fanerne, og statuslinjen står under den (UI/UX §9).
- De to faner er lige brede halvdele. Navn, tal og markør står på én linje og er centreret.
- Ved 320 px og "Adversus 12.345" plus markør er indholdet ca. 135 px mod en halvdel på ca. 140 px. Det kan være stramt, så ingen ombrydning i linket (`white-space: nowrap`). Padding 8 px gør, at det kan være der.
- `.source-tabs a` skal tilføjes til den eksisterende 44 px-regel i `@media (max-width: 640px)` eller have reglen ovenfor. Den ene af de to er nok.

# Iconography

- Ikonerne er de eksisterende Lucide-ikoner `circle-alert` og `triangle-alert` via `Ui::icon($navn, 14)`. Ikonsættet vokser ikke.
- Størrelsen er 14 px (`.icon-14`, findes), samme størrelse som `external-link` og `trophy` inline i tekst.
- `circle-alert` i `--danger-text` betyder, at hentningen er stoppet (`last_error`, ikke rate limit), og vises for alle roller.
- `triangle-alert` i `--warning-text` betyder Adversus-advarsel og vises kun for admin.
- Betydningen er den samme som i `.msg.error` og `.msg.warn`. Det er bevidst, så markøren peger på den boks, man får ved at klikke.
- Har en kilde både stop og advarsel, vises kun `circle-alert`, fordi den er den alvorligste. Der er aldrig to ikoner på én fane.
- Den aktive fane har aldrig en markør (UI/UX §2.2 punkt 6).
- Der er ingen kilde- eller varemærkeikoner (Zendesk- og Adversus-logoer) på fanerne, som i F004.

# Visual states

| Tilstand | Inaktiv fane | Aktiv fane (`aria-current="page"`) |
|---|---|---|
| Standard | `--text-muted` 500, transparent, ingen streg | `--text` 600, transparent, 2 px `--accent`-streg i bunden |
| Hover | `--text` på `--surface-2` (øverste hjørner 6 px), ingen streg | uændret, ingen baggrund (`.source-tabs a[aria-current="page"]:hover { background: transparent; color: var(--text); }`) |
| Fokus (`:focus-visible`) | global ring 2 px `--focus`, offset 2 px | samme |
| Active (tryk) | ingen ekstra effekt (et klik loader en ny side) | — |
| Disabled | findes ikke. Inaktive faner er aldrig disabled, heller ikke ved "0" (UI/UX §6) | — |
| Loading | ingen (siden renderes på serveren) | — |
| Fejl eller advarsel i den anden kilde | markør efter tallet (se Iconography) | ingen markør |

- Aktiv fane skelnes på tre måder: vægt (600 mod 500), streg (2 px accent) og tekstfarve (`--text` mod `--text-muted`). Selv uden farvesyn er den skelnelig på vægt og streg (WCAG 1.4.1).
- Tallet ændrer sig ikke med tilstanden og forbliver `--text-muted`/500. Navnet er det, der markerer valget.
- Vægtskiftet giver en lille breddeforskel (ca. 2–4 px) på det aktive navn. Den viser sig kun ved sideskift (hvert klik er en ny side) og ikke under hover. Det er acceptabelt. Brug ikke ghost-tekst eller `data-`-tricks for at undgå det.

# Shadows and borders

| Element | Radius | Ramme | Skygge |
|---|---|---|---|
| `.source-bar` | ingen | `border-bottom: 1px solid var(--border)` (under 640 px: på `.source-tabs` i stedet) | ingen |
| `.source-tabs a` | `var(--radius-sm) var(--radius-sm) 0 0` (6 px øverst, så hover-fladen ikke "flyder") | `border-bottom: 2px solid transparent` / `var(--accent)` aktiv | ingen |
| `.tab-count` | ingen | ingen | ingen |
| `.tab-flag` | ingen | ingen | ingen |

Der er ingen ydre ramme om fanegruppen. Det er den væsentligste formforskel til `.segmented` (1 px `--border-strong`-ramme, 2 px indre padding og fyldt aktivt valg).

# Animation / transitions

- `.source-tabs a`: `transition: background-color var(--dur) var(--ease), color var(--dur) var(--ease);` (120 ms), som `.segmented a` og knapper.
- Stregen animeres ikke (den skifter med ny side).
- Den eksisterende `prefers-reduced-motion`-regel slår det fra.
- Der er ingen nye `@keyframes`.

# Brand alignment notes

- Fanerne bruger det mest neutrale mønster i appen. Klassisk understregning er et velkendt "sektion"-mønster, mens det fyldte, blå valg allerede betyder "valg inden for" (`.segmented`, hurtigvalg, hovedmenu). Niveauerne kan dermed aflæses på formen.
- Siden bliver roligere end i dag. Statuslinjen flytter op på fanerækken, og motorboksen bliver en linje (UI/UX §2.3), så der kommer ingen ny visuel vægt, kun en tynd linje og en accentstreg.
- Spec'en og UI/UX-briefet har intet, der virker uden for brandet. UI/UX-forslaget (`--text`, 600, 2 px `--accent`, hover `--surface-2`) er fulgt. Det eneste, der er lagt til, er tal i 14 px og radius på hover-fladen.

# Visual decisions

1. **Ingen nye farvetokens.** Alle par består AA i begge temaer (tabellen under Color usage).
2. **Faner som understregning uden ramme og fyld.** Det adskiller niveau 1 fra niveau 2 (`.segmented`) på form, fyld, størrelse og farve.
3. **Accent kun som streg.** Princip 2 fra F005 holder: accent betyder valg eller handling, og stregen er det valgte.
4. **Navn 16 px og vægt 600 aktiv.** Fanerne ligger mellem H1 (24 px) og niveau 2 (14 px) i hierarkiet.
5. **Tallet er dæmpet tekst og ikke et badge.** Det er information om fanen, ikke en status. Badge-formen er reserveret til status (F005) og chips til filtre.
6. **Markøren genbruger beskedernes ikoner og farver,** så den visuelt peger på den boks, der venter på fanen. Den er 14 px som de andre inline-ikoner.
7. **Linjen flytter til `nav` under 640 px,** så fanerne altid sidder på linjen, også når statuslinjen stables.
8. **`nowrap` på desktop** med ombrydning inde i statuslinjen i stedet for at rækken brækker (afvigelse fra UI/UX §2, kun visuelt, se Spacing).

## Nye visuelle beslutninger i dette brief

### Kilde-faner (`.source-tabs` i `.source-bar`)
- **Hvad:** faner på øverste niveau som understregede links på en fuld-bredde skillelinje.
- **Værdi:**
  - min-højde 40 px (44 px under 640 px), padding 0 12 px, gap 8 px, 16 px;
  - inaktiv: 500 `--text-muted`, hover `--text` på `--surface-2` med 6 px radius øverst;
  - aktiv: 600 `--text` med 2 px `--accent`-streg, der dækker 1 px `--border`-linjen.
- **Hvorfor:** niveau 1 skal kunne skelnes fra `.segmented` (niveau 2) og chips (filtre) på form og ikke kun på farve.
- **Gælder for:** alle fremtidige opdelinger af en liste på øverste niveau.
- **Anbefaling:** Vedtag som standard? JA

### Antal på en fane (`.tab-count`)
- **Hvad:** et tal efter fanenavnet som almindelig dæmpet tekst.
- **Værdi:** 14 px/500 `--text-muted`, tabulære tal, ingen baggrund, ramme eller prik. Uændret på den aktive fane.
- **Hvorfor:** et tal er ikke en status og må ikke ligne `.badge` eller `.chip`.
- **Gælder for:** alle fremtidige faner med antal.
- **Anbefaling:** Vedtag som standard? JA

### Problem-markør på en fane (`.tab-flag`)
- **Hvad:** et statusikon efter tallet på en inaktiv fane, når den anden kontekst har et problem.
- **Værdi:** `circle-alert` 14 px i `--danger-text` (fejl) eller `triangle-alert` 14 px i `--warning-text` (advarsel), højst ét ikon (fejl vinder), aldrig på den aktive fane, altid med skjult tekst.
- **Hvorfor:** det samme ikon og den samme farve som den `.msg`, man finder bag fanen, og det fylder ikke.
- **Gælder for:** alle fremtidige faner og andre steder, hvor et problem står i en anden kontekst.
- **Anbefaling:** Vedtag som standard? JA

---

**Tjekliste til visuel review (til engineeren):**
- Ingen `style=""` noget sted. Ikonfarven kommer fra `.tab-flag-error` og `.tab-flag-warn` via `currentColor`.
- Den aktive fane har ingen baggrund, og stregen dækker barens linje (ingen dobbeltlinje og ingen 1 px-luft).
- Fanerne er 40 px høje over 640 px og 44 px under. Under 640 px står to lige brede halvdele, og linjen er under fanerne, ikke under statuslinjen.
- Kildens `.sync-line` i baren har `margin: 0` (den arver ellers `p { margin: 0 0 12px }`, fordi den ikke står i `.sync`).
- Tjek begge temaer og 320 px med "Adversus 12.345" plus markør.
- Med `F007_kilder` slukket er HTML og CSS-effekt uændrede.
