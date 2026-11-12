# F004 — Visuelt design-brief til Adversus-opkald i Whisper, v1
# Status: READY_FOR_IMPLEMENTATION

Jeg har læst følgende på main (929c64b): `product-ai/F004_v1.md` (afsnittet "Brugerflade"), `graphic-design/F005_visual_v1.md`, `ui-ux/F005_design_v1.md`, `homedir/public_html/assets/app.css`, `homedir/src/UiCalls.php`, `homedir/src/Ui.php` (badge og ikoner), `tasks/impact-F004.md` og `launched/STABLE/DECISIONS.md` (#31).

**Huller i grundlaget:**
- `DESIGN_LOG.md` og `PRD.md` findes ikke.
- `ui-ux/F004_design_v1.md` findes ikke endnu. Placering og adfærd er derfor UI/UX' område. Hvor jeg nævner en placering herunder, er det kun en visuel fallback, og UI/UX-briefet går forud.

**Afvigelser mellem den kørende app.css og F005-briefet:**
- app.css har `--nav-active` (lys `#1c46b0`, mørk `#7ea3ff`), `.icon-14` og `--wide-max: 1280px`. F005-briefet har ikke de navne.
- Den kørende app.css er sandheden, og dette brief bygger på den.

**Ingen nye farvetokens.** Alt herunder bruger de eksisterende tokens. Det nye er:
- 4 komponentklasser: `.tag`, `.tag-source`, `.tag-success` og `.segmented`.
- 1 statusklasse: `.s-ikke-valgt`.
- 1 nyt ikon: `trophy`.

Når flaget `F004_adversus` er slået fra, renderes intet af det (AK9).

---

# Color usage

| Element | Lyst tema | Mørkt tema | Kontrast lys / mørk |
|---|---|---|---|
| Kilde-tag, tekst | `--text-muted` #5a6372 | #9aa3b2 | 6.07 / 6.85 på surface. 5.35 / 6.27 på surface-2 (række-hover) |
| Kilde-tag, ramme | `--border` #e2e6ec | #2a303c | Dekorativ. Teksten bærer betydningen |
| Kilde-tag, baggrund | transparent | transparent | — |
| Succes-tag, tekst og ikon | `--success-text` #17663a | #7dd8a3 | 7.00 / 10.14 på surface. 6.17 / 9.28 på surface-2 |
| Succes-tag, ramme | `--success-border` #b5dec5 | #1f4a31 | Dekorativ (1.48 / 1.73). Tekst og ikon bærer betydningen |
| Succes-tag, baggrund | transparent | transparent | — |
| Badge "Ikke valgt", tekst og prik-ring | `--text-muted` | `--text-muted` | 6.07 / 6.85 på surface. 5.35 / 6.27 på surface-2 |
| Badge "Ikke valgt", ramme | `--border-strong` #7b8495 | #6a7386 | 3.77 / 3.66 på surface. 3.32 / 3.35 på surface-2 (≥ 3:1, 1.4.11) |
| Filtervælger, ydre ramme | `--border-strong` | `--border-strong` | 3.77 / 3.66 på surface (vælgeren står i filterkortet) |
| Filtervælger, inaktivt valg | `--text-muted` på `--surface` | samme | 6.07 / 6.85 |
| Filtervælger, hover | `--text` på `--surface-2` | samme | 15.38 / 13.11 |
| Filtervælger, aktivt valg | `--nav-active` på `--accent-subtle` | `--nav-active` #7ea3ff på #18233d | 7.14 / 6.38 |
| "Transcribér dette opkald" (detalje) | primær: `--accent-contrast` på `--accent`, hover `--accent-hover` | samme tokens | 6.27 / 7.63 (hover 8.23 / 10.04) |
| Knappens kant mod info-boksen | `--accent` mod `--info-bg` | samme | 5.44 / 6.38 (≥ 3:1) |
| "Transcribér" i listen (hvis UI/UX vælger det) | `.button.secondary.small` | samme | `--text` på surface 17.46 / 14.33 |

**Regler:**
- Kilden er ikke en status. Zendesk og Adversus får derfor samme neutrale tag, uden farvekodning pr. kilde. Teksten skelner dem.
- Grøn bruges stadig kun til positive tilstande. Succes-tagget skelnes fra badget "Færdig" på tre måder: form (radius 6 px mod pill), fyld (outline mod fyldt) og markør (trofæ-ikon mod prik).
- Accent bruges kun på knappen og det aktive valg i filtervælgeren. Begge er handlinger eller valg, så princip 2 fra F005 holder.

# Typography

| Element | Størrelse | Vægt | Linjehøjde | Farve |
|---|---|---|---|---|
| Kilde-tag ("Zendesk" / "Adversus") | `--fs-sm` 13 px | 500 | 1 (højden er fast) | text-muted |
| Succes-tag ("Succes-lead") | 13 px | 500 | 1 | success-text |
| Badge "Ikke valgt" | 13 px | 500 | som `.badge` | text-muted |
| Filtervælger, valg | `--fs-md` 14 px | 500. Aktiv: 600 | 1 | se farver |
| Filtervælgerens label (fx "Vis", hvis UI/UX bruger en) | 14 px | 400 | 1.5 | text-muted (som "Hurtigt:") |
| Knap "Transcribér dette opkald" | 14 px (`.small` i listen: 13 px) | 500 | 1 | se knapper |
| Forklarende tekst ved knappen | `--fs-table` 15 px | 400 | 1.5 | `--text` (som `.msg`) |
| Kilde i `dl.meta` (dd) | 15 px | 500 | som meta | text (ren tekst, intet tag) |

- Ingen versaler og ingen letter-spacing på tags.
- Teksten er "Succes-lead" og ikke kun "Succes", så den ikke kan forveksles med "Færdig".

# Spacing and layout

**`.tag` (fælles base for kilde og succes):**
```
display: inline-flex; align-items: center; gap: var(--sp-1) /* 4px */;
height: 20px; padding: 0 6px; border: 1px solid; border-radius: var(--radius-sm) /* 6px */;
font-size: var(--fs-sm); font-weight: 500; line-height: 1; white-space: nowrap; vertical-align: middle;
```
- Højden er 20 px, under badgets 22 px, så status stadig er det tungeste element i rækken.
- To tags efter hinanden har `gap: 6px`. Brug en flex-wrapper `.tags { display: inline-flex; gap: 6px; flex-wrap: wrap; }`.

**Placering i listen (fallback, hvis UI/UX ikke bestemmer andet):**
- Tags står i en ny linje under tiden i cellen "Dato og tid" (`.row-link .time` efterfulgt af `.tags`, `margin-top: var(--sp-1)`).
- Ingen ny kolonne. Tabellen har allerede 7 kolonner og bliver for bred i 641–1000 px.
- `.tags` ligger uden for `.row-link`, så de ikke bliver understreget ved hover. Cellen bevarer stretched-link-mønstret.

**Badge "Ikke valgt":** samme mål som `.badge` (22 px, padding 0 8 px, pill). Rammen tegnes som `box-shadow: inset 0 0 0 1px var(--border-strong)`, så højden ikke ændres.

**`.segmented` (filtervælgeren):**
```
display: inline-flex; padding: 2px; gap: 2px; background: var(--surface);
border: 1px solid var(--border-strong); border-radius: var(--radius-sm);
```
- Hvert valg: `display: inline-flex; align-items: center; min-height: 32px; padding: 0 var(--sp-3) /* 12px */; border-radius: 4px; text-decoration: none;`
- Under 640 px: hvert valg har `min-height: 44px` og `flex: 1`, og vælgeren har `display: flex; width: 100%`.
- Placering (fallback): første element i `.filter-foot` før "Hurtigt:" med `margin-right: var(--sp-4)`, eller i `.results-bar` til venstre for antallet. Valget følger UI/UX-briefet.

**"Transcribér dette opkald" på detaljen:**
- Står i højre kolonne (`.detail-main`), hvor transcript-tilstanden ellers vises.
- Det er en `.msg.info` med info-ikonet og forklaringen i `.msg-body`.
- Formularen ligger under teksten med `margin-top: var(--sp-3)` (12 px), og knappen står venstrestillet.
- Under 400 px er knappen i fuld bredde (dækket af den eksisterende regel `form.actions > *`, hvis formularen får `class="actions"`).

**I listen (kun hvis UI/UX vil have knappen der):**
- `.button.secondary.small` med `position: relative; z-index: 1` (samme mønster som `.ticket`), så den ligger over rækkens stretched link.
- Den står i Status-cellen efter badget med `margin-left: var(--sp-2)`.

**Detaljehovedet:**
- Statusbadget og succes-tagget står i en flex-gruppe til højre (`display: flex; gap: var(--sp-2); align-items: center; flex-wrap: wrap`). Succes-tagget står først.
- Kilden står i undertitlen (`.sub`) som ren tekst, fx "Adversus · Agent … · 3:12". Der er intet tag i undertitlen.

**`dl.meta`:** nye par for Kilde, Kampagne og Lead følger det eksisterende mønster, uden ny spacing.

# Iconography

- Der er ét nyt ikon, **Lucide `trophy`**. Path-data kopieres fra Lucide (ISC-licens, samme helper `Ui::icon`), med de fælles attributter (stroke 1.75, `currentColor`, `aria-hidden="true"`, `focusable="false"` og ingen `style`).
- Det bruges kun i succes-tagget, i 14 px (`.icon-14`), i `--success-text` via `currentColor`.
- Kilde-tagget har intet ikon. Det er heller ikke nødvendigt at efterligne Zendesk- eller Adversus-logoer, og de må ikke bruges (varemærker og eksterne ressourcer).
- Knappen "Transcribér dette opkald" har intet ikon. Det er den eneste handling i boksen, og teksten er tydelig.
- Filtervælgeren har ingen ikoner.
- Ikonlisten fra F005 vokser fra 14 til 15 ikoner.

# Visual states

**Badge "Ikke valgt"** (`.badge.s-ikke-valgt`):
- Prikken er en hul ring: `::before { background: transparent; box-shadow: inset 0 0 0 1.5px currentColor; }`. Ringen betyder "ikke startet". Den fyldte prik på "Venter" betyder "i kø".
- Ingen animation.
- Når opkaldet er sat i kø, skifter badget til det eksisterende `s-ventende` ("Venter"). Derefter følger det F005-tabellen (Transcriberer, Færdig, Fejlet).

**Knappen "Transcribér dette opkald":**
- Hover, active og focus følger de eksisterende knapregler (accent-hover, inset-skygge, 2 px fokusring med offset 2 px).
- Der er ingen disabled-tilstand. Er opkaldet allerede i kø eller færdigt, vises knappen slet ikke (idempotent på serveren, AK7).
- Efter POST og redirect viser flash-beskeden `.msg.ok` med circle-check. Den bliver stående, uden toast og uden fade. Teksten er UI/UX' område.
- POST-fejl (fx CSRF) vises med `.msg.error` med circle-alert.

**Filtervælgeren:**
- Inaktivt valg: hover giver `--text` på `--surface-2`.
- Aktivt valg (`aria-current="true"` på linket, eller `label:has(input:checked)`, hvis UI/UX vælger radioknapper i formularen): `--nav-active` på `--accent-subtle` og vægt 600. Det aktive valg har ingen hover-effekt.
- Fokus: den globale `:focus-visible`-ring med offset 2 px. Bruges radioknapper, sidder ringen på labelen via `label:has(:focus-visible)`, og selve inputtet er `.sr-only`.

**Tags:** ingen hover og ingen fokus, fordi de ikke er interaktive. På række-hover (`--surface-2`) er de transparente, så rækkefarven skinner igennem, og kontrasten holder (se tabellen).

**Loading:** ingen ny tilstand. Transcription i gang vises med det eksisterende pulserende `s-transcriberer`.

# Shadows and borders

| Element | Radius | Ramme | Skygge |
|---|---|---|---|
| `.tag` | `--radius-sm` 6 px | 1 px solid (border eller success-border) | ingen |
| `.s-ikke-valgt` | `--radius-pill` | inset box-shadow 1 px `--border-strong` | ingen |
| `.segmented` | `--radius-sm` 6 px | 1 px solid `--border-strong` | ingen (den står i et kort) |
| Valg i `.segmented` | 4 px (6 − 2 px indre padding) | ingen | ingen |
| Knapper | som F005 (`--radius-sm`; `.secondary` har `--shadow-1`) | — | — |

# Animation / transitions

- Ingen nye animationer.
- `.segmented a` får samme `transition` som knapper: `background-color` og `color`, `var(--dur)` (120 ms), `var(--ease)`.
- Alt slås fra af den eksisterende `prefers-reduced-motion`-regel.

# Brand alignment notes

- Adversus-opkald skal se ud som de opkald, der allerede er der: samme række, badge, afspiller og transcript. Kilden er en diskret etiket og ikke et nyt visuelt sprog.
- Succes-markeringen er den eneste nye farvede markør. Den er bevidst holdt som outline, så en liste fuld af succes-opkald (standardfiltret) ikke bliver grøn og tung.
- I standardvisningen "Succes-leads" har næsten alle Adversus-rækker succes-tagget. Det er støj uden information. Til UI/UX: overvej at vise succes-tagget i listen kun under "Alle opkald" og altid på detaljesiden. Det er en adfærdsbeslutning, og visuelt virker begge løsninger.
- Spec'en siger "Transcribér" med accent. Det er korrekt dansk bydeform, og teksten skal stå ens på knap og i flash.

# Visual decisions

1. **Ingen nye farvetokens.** Status-, accent- og neutralfarverne fra F005 dækker alt og består AA i begge temaer (tabellen ovenfor).
2. **Ny komponent `.tag`, kantet og outline**, adskilt fra `.badge` (pill, fyldt, prik). Badges er status, tags er egenskaber. Formen fortæller forskellen, også for farveblinde.
3. **Kilden er neutral og ens for begge kilder**, fordi farvekodning pr. kilde ville bryde med "én accentfarve, statusfarver kun til status".
4. **Succes-lead i success-tekstfarve med trofæ**, fordi trofæ betyder "salgssucces". circle-check bruges allerede til ok-beskeder og ligner "Færdig".
5. **"Ikke valgt" som hul neutral badge.** Den er neutral, fordi det ikke er en fejl eller advarsel, men et bevidst fravalg (DECISIONS #31). Den er hul, så den skelnes fra "Venter".
6. **Filtervælgeren som segmenteret kontrol** med samme aktiv-farve som hovedmenu og hurtigvalg (`--nav-active` på `--accent-subtle`). Det er et kendt mønster i appen, og det virker uden JS som GET-links.
7. **Primær knap på detaljen og sekundær small i listen.** Det overholder "højst én primær pr. visning".

## Nye visuelle beslutninger i dette brief

### Tag-komponent for egenskaber (`.tag`)
- **Hvad:** en kantet outline-etiket til egenskaber ved et opkald (kilde, lead), adskilt fra statusbadges.
- **Værdi:** 20 px høj, padding 0 6 px, `--radius-sm`, 1 px ramme, 13 px/500, transparent baggrund. Neutral variant: `--text-muted` med `--border`. Succes-variant: `--success-text` med `--success-border`.
- **Hvorfor:** status og egenskab skal kunne skelnes på form, ikke kun på farve.
- **Gælder for:** alle fremtidige features (fx kampagne og flere kilder).
- **Anbefaling:** Vedtag som standard? JA

### Badge "Ikke valgt" (`.s-ikke-valgt`)
- **Hvad:** en hul neutral statusbadge med en ring som prik.
- **Værdi:** tekst og ring i `--text-muted`, transparent baggrund, inset-ramme 1 px `--border-strong`.
- **Hvorfor:** et bevidst fravalg er hverken en fejl eller en kø.
- **Gælder for:** F004 og fremtidige "ikke automatisk behandlet"-tilstande.
- **Anbefaling:** Vedtag som standard? JA. Tilføj rækken til badge-tabellen i F005 §3.

### Segmenteret kontrol (`.segmented`)
- **Hvad:** to til fire gensidigt udelukkende visningsvalg som links eller radioknapper.
- **Værdi:** se Spacing. Aktivt valg i `--nav-active` på `--accent-subtle`.
- **Hvorfor:** valget skal kunne ses og skiftes med ét klik uden JS. Det genbruger aktiv-mønstret fra nav og hurtigvalg.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### Ikonet `trophy` (Lucide) som markør for succes-lead
- **Hvad:** ikon nummer 15 i ikonsættet, 14 px, i `--success-text`.
- **Hvorfor:** det skelner succes-lead fra færdig-status (circle-check og prik).
- **Gælder for:** succes-markeringen i F004 og i MCP-visninger, hvis de får HTML.
- **Anbefaling:** Vedtag som standard? JA

---

**Til Team Lead:**
- Briefet er skrevet uden `ui-ux/F004_design_v1.md`. Ændrer UI/UX placeringen (fx en ny kolonne "Kilde" eller ingen knap i listen), gælder værdierne her stadig. Kun fallback-placeringerne falder bort.
- Kontrasttallene er regnet efter WCAG-formlen ud fra tokenværdierne i den kørende `homedir/public_html/assets/app.css`.
- Der er ingen afvigelser fra vedtagne visuelle valg. Der findes ingen `DESIGN_LOG.md`, så F005-briefet er referencen.
