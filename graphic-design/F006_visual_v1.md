# F006 — Visuelt design-brief til Claude custom connector via OAuth, v1
# Status: READY_FOR_IMPLEMENTATION

Jeg har læst følgende på `feature/F006-oauth` (96394d1): `ui-ux/F006_design_v1.md` (især "Til graphic-design"), `product-ai/F006_v1.md`, `graphic-design/F005_visual_v1.md`, `graphic-design/F004_visual_v1.md`, `homedir/public_html/assets/app.css` og markup-mønstrene i `homedir/src/UiPages.php` og `UiCalls.php`.

**Omfang (Team Lead 2026-11-12):**
- Briefet dækker godkendelsessiden (`dl.meta` og visning af værten) og listen "Appen kan".
- `details.advanced` udgår. Ejeren vil kun have den rigtige connector, og mcp-remote-afsnittet fjernes fra Konto. Der kommer derfor ingen ny stil til foldbare afsnit.
- Konto i øvrigt ("Forbind Claude", `ol.steps`, `.secret` og Forbundne apps i `table.keys`) bruger kun eksisterende komponenter. Det kræver intet visuelt brief.

**Huller og afvigelser i grundlaget:**
- `DESIGN_LOG.md` og `PRD.md` findes ikke. Referencen er F005- og F004-briefene og den kørende app.css, som er sandheden.
- **Rettelse til UI/UX-briefet (§ Til graphic-design, punkt 2):** briefet skriver, at `code` allerede har `--surface-2`. Det passer ikke. I den kørende app.css har inline-`code` kun `font-family: var(--font-mono)` og `font-size: 0.9em`. Kun `.secret input` og `pre.code` har `--surface-2`. Værtens baggrund herunder er derfor en ny, scopet stil.

**Ingen nye tokens, ingen nye ikoner, ingen nye farver og intet inline style.** Det nye er tre klasser i app.css:
- `.consent` (modifier på godkendelseskortet)
- `dl.meta.single`
- `code.host`

Kontrasten er regnet efter WCAG-formlen på tokenværdierne i den kørende app.css.

---

# Color usage

| Element | Lyst tema | Mørkt tema | Kontrast lys / mørk |
|---|---|---|---|
| Kortets baggrund | `--surface` #ffffff | #161a22 | — |
| H1, lead, `dd`, punkter | `--text` #141a24 | #e6e9ef | 17.46 / 14.33 på surface |
| `dt`, `.hint` | `--text-muted` #5a6372 | #9aa3b2 | 6.07 / 6.85 på surface |
| Værten `code.host`, tekst | `--text` | `--text` | 15.38 / 13.11 på surface-2 |
| `code.host`, baggrund | `--surface-2` #eef1f5 | #1d222c | — |
| `code.host`, ramme | `--border` #e2e6ec | #2a303c | Dekorativ. Teksten bærer værdien |
| Godkend (primær) | `--accent-contrast` på `--accent`, hover `--accent-hover` | samme tokens | 6.27 / 7.63 (hover 8.23 / 10.04) |
| Afvis (`.secondary`) | `--text` på `--surface`, ramme `--border-strong` | samme | 17.46 / 14.33. Ramme 3.77 / 3.66 |
| "Log ud" (`button.link` i hint) | `--accent` | `--accent` | 6.27 / 7.11 på surface |
| Fokusring | `--focus` | `--focus` | 6.27 / 7.11 på surface |
| Punkt-markører (`disc`) | `--text` | `--text` | 17.46 / 14.33 |
| localhost-advarsel (kun DEV) | eksisterende `.msg.warn` | samme | uændret komponent |

**Regler:**
- Accent bruges kun på Godkend, Log ud-linket og fokus (F005-princip 2).
- Værten får ingen accent- eller statusfarve. Den er en neutral, præcis værdi og ikke en handling. En farvet vært ville ligne et link, og det må den ikke (UI/UX D8).

# Typography

| Element | Størrelse | Vægt | Linjehøjde | Farve |
|---|---|---|---|---|
| H1 "Giv adgang til Whisper" | 24 px (eksisterende h1) | 600 | tight | text |
| Lead "«Claude» vil have …" | 16 px | 400. Navnet i `<strong>`: **600** | 1.5 | text |
| `dl.meta dt` | 13 px (eksisterende) | 400 | 1.5 | text-muted |
| `dl.meta dd` | 15 px (eksisterende) | 500. Navnet i `<strong>`: **600** | 1.5 | text |
| Værten `code.host` | **1em = 15 px** (ikke code's 0.9em), `--font-mono` | 600 | arver | text |
| H2 "Appen kan" | 18 px (eksisterende h2) | 600 | tight | text |
| Punkter under "Appen kan" | 16 px | 400 | 1.5 | text |
| "Appen kan ikke …" | 16 px | 400 | 1.5 | text |
| Hints (app-hint, phishing, varighed, prompt-injection) | 13 px `.hint` | 400 | 1.5 | text-muted |
| "Log ud" i hint | arver 13 px | 500 | arver | accent, understreget |
| Knapper | 14 px (eksisterende) | 500 | 1 | se farver |

- **`strong` på godkendelsessiden er 600, ikke browserens `bolder`.** `dd` er 500, så `bolder` giver 700. Så ville det ikke-betroede navn veje tungere end den betroede vært (600), og vægten skal være omvendt eller lige. Regel: `.consent strong { font-weight: 600; }`. Den gælder både lead og `dd`.
- Værten er 15 px og ikke 13,5 px. Den er dermed mindst lige så stor som navnet og nem at læse tegn for tegn (homografer).

# Spacing and layout

Kortet får klassen `consent`: `<div class="card narrow consent">`. Alle regler herunder er scopet til den klasse eller til de nye klasser.

## 1. `dl.meta` i én kolonne: `dl.meta.single`
**Svar på UI/UX' spørgsmål:** auto-fill med 160 px står **ikke** pænt med tre par i `card.narrow`.
- Indholdsbredden er 480 − 2 − 48 = 430 px. Det giver 2 kolonner (2 × 160 + 24 = 344), så parrene står 2 + 1: "App" og "Sender dig til" øverst og "Logget ind som" alene nedenunder.
- I Claude's popup på 400–500 px er indholdet ca. 300–350 px. Der skifter layoutet mellem 1 og 2 kolonner afhængigt af vinduets bredde.
- Hint-linjen under App gør desuden de to celler i første række ulige høje.

**Regel:** `dl.meta.single { grid-template-columns: 1fr; gap: var(--sp-3); }`
- Der er 12 px mellem parrene, og `dt` står over `dd` som i alle andre `dl.meta`.
- Resultatet er en lodret "kvittering", der læses i samme rækkefølge ved 320 px og 480 px.
- Margin forbliver 0 0 24 px (eksisterende).
- App-hintet "Navnet oplyses af appen selv." står som `<span class="hint">` inde i `dd` under navnet. Det er den eksisterende `.hint` (blok, 13 px, margin-top 6 px) uden ændringer.

## 2. Værten `code.host`
**Svar på UI/UX' spørgsmål:** ja, værten får sin egen baggrund. Den scopes til klassen `host` og lægges ikke på al `code`.
```
code.host   font-size: 1em; font-weight: 600; color: var(--text);
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 1px var(--sp-1) /* 1px 4px */;
            -webkit-box-decoration-break: clone; box-decoration-break: clone;
```
- Begrundelse:
  - Rammen viser præcist, hvor værtsnavnet begynder og slutter. Det er relevant mod forfalskninger som `claude.ai.evil.example`.
  - Værten læses som "teknisk værdi" og ikke som brødtekst.
  - Den matcher det readonly connector-URL-felt på Konto (`--surface-2` og mono), så den samme vært ser ens ud begge steder.
- `box-decoration-break: clone` gentager padding og ramme, hvis en lang punycode-vært ombrydes ved 320 px. `overflow-wrap: anywhere` arves fra `dl.meta dd`.
- Al anden `code` (i `.msg`, `pre.code` og nøgletabellen) er uændret. En global baggrund ville give dobbelt baggrund og padding i `pre.code` og ændre F003/F004-visningerne.

## 3. Listen "Appen kan"
**Svar:** ja, en lille stil.
- Browserens standard giver 16 px margin over og under og 40 px indrykning. I en kolonne på ca. 280 px ved 320 px er 40 px indrykning for meget.
- De 16 px over listen bryder også rytmen med h2's 12 px.
```
.consent ul     margin: 0 0 var(--sp-3); padding-left: var(--sp-6);   /* 0 0 12px; 24px */
.consent li     margin-bottom: var(--sp-1);                           /* 4px; punkterne er korte */
```
- Punkterne bruger standard-bullets (`disc`) i `--text`. Der er ingen ikoner (UI/UX: "uden ikoner") og ingen farvede markører.
- Reglen er bevidst scopet til `.consent` og ikke gjort global (restraint).

## 4. Lodret rytme i kortet (ovenfra og ned)
| Fra | Til | Afstand | Kilde |
|---|---|---|---|
| H1 | lead | 16 px | eksisterende h1-margin |
| lead (`p`) | `dl.meta.single` | 12 px | `p`-margin |
| `dl` | H2 "Appen kan" | 32 px | h2-margin (kollapser med dl's 24 px) |
| H2 | liste | 12 px | h2-margin |
| liste | "Appen kan ikke …" (`p`) | 12 px | punkt 3 |
| `p` | phishing-hint (`p.hint`) | 12 px | `p`-margin. Hintets egne 6 px kollapser |
| hint eller `.msg.warn` | `form.actions` | **16 px** | ny: `.consent form.actions { margin: var(--sp-4) 0 var(--sp-6); }` |
| `form.actions` | varigheds-hint | **24 px** | samme regel |
| hint | hint | 8 px | ny: `.consent p.hint + p.hint { margin-top: var(--sp-2); }` |

- Uden `.consent form.actions`-reglen står knapperne 0 px under phishing-hintet, fordi `form` har margin 0.
- Godkend og Afvis har et mellemrum på 12 px (eksisterende `form.actions`). Under 400 px har de fuld bredde under hinanden (eksisterende regel), med Godkend øverst.

## 5. "Log ud" som `button.link` inde i et hint
- Regel: `.consent .hint button.link { font-size: inherit; padding: 0 2px; min-height: 24px; vertical-align: baseline; }`
- Reglen skal også gælde under 640 px. Ellers gør den globale `button { min-height: 44px }` hint-linjen 44 px høj. Selektoren (0,3,1) vinder over `button` i media-blokken.
- Inline-links i løbende tekst er undtaget fra 44 px-kravet (WCAG 2.5.8, inline-undtagelsen), og målet holder 24 px.

# Iconography (if applicable)
- Der kommer ingen nye ikoner. Ikonsættet forbliver på 15 (F005 + `trophy` fra F004).
- `.msg.info` på login og `.msg.warn` (localhost) bruger de eksisterende ikoner `info` og `triangle-alert` på 18 px.

# Visual states

**Godkendelsessiden**
- Knapperne har de eksisterende tilstande for hover, active og fokus (2 px ring med offset 2 px).
- Der er ingen disabled- og ingen loading-tilstand (UI/UX §States).
- Siden har ingen `autofocus`, så ingen knap har fokus ved indlæsning.
- `code.host` og `strong` er ikke interaktive og har derfor ingen hover og ingen fokus.
- localhost-advarslen (kun DEV) er en eksisterende `.msg.warn` med ikon og tekst, altså aldrig kun farve.
- Delvise data: "En unavngivet app" vises uden «» i almindelig `dd`-vægt 500, ikke i `strong`, fordi det ikke er et navn fra appen. Et afkortet navn ("…") ombrydes normalt.

**Fejlsider E1 og E4:** eksisterende `card.narrow` og `div.actions`. Den tekniske `<details>` er en almindelig `details` med `summary { cursor: pointer; }` og browserens standard, svarende til `.msg details`. Der er ingen ny stil.

# Shadows and borders

| Element | Radius | Ramme | Skygge |
|---|---|---|---|
| Godkendelseskortet | eksisterende `.card`: `--radius-md` 10 px | 1 px `--border` | `--shadow-1` |
| `code.host` | `--radius-sm` 6 px | 1 px solid `--border` | ingen |
| Knapper | eksisterende `--radius-sm` | `.secondary`: `--border-strong` | `.secondary`: `--shadow-1` |

# Animation / transitions (if applicable)
Ingen nye. Knapperne har de eksisterende transitions på 120 ms, og den eksisterende `prefers-reduced-motion`-regel gælder.

# Brand alignment notes
- Godkendelsessiden står i den normale Whisper-ramme (header, brand-mærke, nav) og bruger kun eksisterende komponenter. Den ligner Whisper og ikke en generisk OAuth-side, og det er i sig selv en tillidsmarkør.
- Hierarkiet: H1 → lead → kvitteringen (App, vært, bruger) → hvad appen kan → handlinger. Værten er det eneste indrammede element. Den er bevidst det mest markerede faktum på siden, fordi det er det, man ikke kan forfalske.
- Intet føles off-brand i spec'en eller UI/UX-briefet. Den eneste rettelse er den faktuelle fejl om `code`-baggrunden, som er nævnt øverst.

# Visual decisions
1. **`dl.meta.single` på godkendelsessiden.** Tre par med et hint giver et skævt 2 + 1-grid i 480 px og et ustabilt layout i Claude's popup. Én kolonne er forudsigelig ved alle bredder.
2. **`code.host` med `--surface-2`, ramme, 15 px og 600**, kun for værten. Den afgrænser tydeligt mod homograf- og subdomæne-snyd og ser ens ud med connector-URL-feltet på Konto.
3. **`strong` = 600 på godkendelsessiden**, så det ikke-betroede navn aldrig vejer tungere end værten.
4. **En lille, scopet liste-stil** (24 px indrykning, 4 px mellem punkterne og 12 px under) i stedet for browserens 40/16 px.
5. **To nødvendige spacing-rettelser:** `form.actions` får 16 px over og 24 px under, og hints, der følger efter hinanden, får 8 px imellem.

## Nye visuelle beslutninger i dette brief

### `code.host` — indrammet værtsnavn
- **Hvad:** visning af en destinationsvært eller en anden sikkerhedsrelevant teknisk værdi, som skal kunne læses præcist.
- **Værdi:** mono, 1em, 600, `--text` på `--surface-2`, 1 px `--border`, `--radius-sm`, padding 1 px 4 px og `box-decoration-break: clone`.
- **Hvorfor:** phishing-værn (D8). Værdiens grænser er tydelige, og den ser ens ud med URL-feltet.
- **Gælder for:** alle fremtidige features, der viser en destination, man skal stole på. Ikke almindelig inline-`code`.
- **Anbefaling:** Vedtag som standard? JA

### `dl.meta.single` — metadata i én kolonne
- **Hvad:** en modifier, der tvinger `dl.meta` til én kolonne med 12 px mellem parrene.
- **Værdi:** `grid-template-columns: 1fr; gap: var(--sp-3);`
- **Hvorfor:** i smalle kort (`card.narrow`, popups) giver auto-fill et ustabilt og skævt grid.
- **Gælder for:** alle fremtidige `dl.meta` i `card.narrow`.
- **Anbefaling:** Vedtag som standard? JA

### Godkendelseskortet `.consent` (vægt, liste, rytme)
- **Hvad:**
  - `strong` 600,
  - `ul` med 24 px indrykning og 4 px mellem punkterne,
  - `form.actions` med margin 16 / 24 px,
  - 8 px mellem hints, der følger efter hinanden,
  - et inline `button.link` på 24 px i hints, også på touch.
- **Hvorfor:** navnet må ikke veje tungere end værten, og den smalle kolonne kræver en roligere rytme.
- **Gælder for:** denne feature. Liste-stilen kan løftes til en generel klasse, når en anden feature har brug for den.
- **Anbefaling:** Vedtag som standard? NEJ (feature-specifik)

---

**Til Team Lead:**
- UI/UX-briefets antagelse om, at `code` har `--surface-2`, er forkert (se øverst). Beslutningen her erstatter den, og UI/UX-briefet behøver ikke en ny version for det.
- UI/UX-briefets "Avanceret"-afsnit (`details.advanced`, D9, tælleren i summary og auto-`open`) bortfalder med ejerens beslutning. UI/UX-briefet bør markeres eller opdateres, så engineeren ikke bygger det.
- Engineeren skal være opmærksom på, at `.consent .hint button.link` skal slå den globale 44 px-regel for `button` under 640 px.
- Kontrasttallene er regnet ud fra tokenværdierne i den kørende `homedir/public_html/assets/app.css`, og de stammer fra F005- og F004-briefene.
