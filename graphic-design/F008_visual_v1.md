Status: READY_FOR_IMPLEMENTATION

# F008, visuelt brief v1: Adversus pr. lead

**Til Team Lead:** gem som `graphic-design/F008_visual_v1.md`.

**Det har jeg læst:**
- `agents/graphic_designer.md`
- `product-ai/F008_v1.md`
- `tasks/impact-F008.md`
- `graphic-design/F007_visual_v2.md`
- `homedir/public_html/assets/app-v2.css` (main)
- ikonlisten i `homedir/src/Ui.php`

**Det findes ikke:**
- `ui-ux/F008_design_v1.md` findes endnu ikke. UI/UX bestemmer, hvilke elementer der findes, ordlyden og adfærden. Dette brief bestemmer kun, hvordan de ser ud.
- `DESIGN_LOG.md` og `PRD.md` findes ikke. Rammen er derfor de vedtagne valg i F007 v2.

**Grundregler:**
- Alt fra F007 v2 genbruges uændret: tokens, typeskala, spacing, radius, skygger, `.call-card`, `.tag`, `.badge`/`.s-*`, `.chip-meta`, `.d-head`, `.back`, `.find`, `.transcript`/`.chat`, `.player-v2`, `.avatar`/`.av-*` og `.empty`.
- Briefet beskriver kun det nye.
- Det nye CSS ligger kun i `app-v2.css` og virker kun, når klasserne bruges. Med `F008_leads` slukket ændres intet (AK9).
- **Ingen nye tokens, ingen nye fonte, ingen nye ikoner og ingen `style=""`.** Det gælder også SVG og avatarer. Overlap og markører laves med klasser og pseudo-elementer.

---

# Color usage

Alle farver er eksisterende tokens. Der er ingen nye tekst/baggrund-par: hvert par herunder står allerede i F007 v2's kontrasttabel.

| Element | Tokens | Lyst | Mørkt |
|---|---|---|---|
| Lead-kort: titel "Lead 123456" | `--text` på `--surface` | 18.26 | 14.33 |
| Lead-kort: metalinjer, "4 opkald · 3 besvarede" | `--text-muted` på `--surface` | 5.96 | 6.85 |
| Lead-kort: taletid | `--text-2` på `--surface` | 10.09 | 10.48 |
| Avatar-"+n" (overløb) | `.av-0`: `--neutral-text` på `--surface-2` | 7.05 | 8.33 |
| Avatarer 1–6, hvide initialer | `--av-1…6` | ≥ 5.18 | samme |
| Leadstatus "Succes-lead" | `.tag-success` (trofæ) | 5.75 | 9.29 |
| Leadstatus, andre | `.tag` neutral: `--neutral-text` på `--surface-2` | 7.05 | 8.33 |
| Tidslinje: kompakt række (ubesvaret), tekst | `--text-muted` på `--bg` | 5.56 | 7.43 |
| Tidslinje: kompakt række, klokkeslæt | `--text` på `--bg` | 17.04 | 15.54 |
| Opkaldssektion: nummer-label "OPKALD 2 AF 5" | `--text-muted` på `--surface` | 5.96 | 6.85 |
| Træf-chip "3 træf" i sektionens hoved | `--text` på `--mark-bg` | 14.67 | 7.07 |
| "Se hele leadet" (chip-link på `/opkald/{id}`) | `--accent-ink` på `--surface` | 8.27 | 9.36 |

**Ikke-tekst (1.4.11):**

| Element | Tokens | Lyst | Mørkt |
|---|---|---|---|
| Markør, besvaret opkald: 2 px ring i `--text-muted`, fyld `--surface` | mod `--bg` | 5.56 | 7.43 |
| Markør, sektion med aktivt træf: fyld og ring `--accent` | mod `--bg` | 5.29 | 7.71 |
| Ring om sektion med aktivt træf: 2 px `--accent` | mod `--bg` / `--surface` | 5.29 / 5.67 | 7.71 / 7.11 |

**Dekorativt** (`aria-hidden`, betydningen bæres af tekst):
- forbindelseslinjen `--border-control`
- markøren for et ubesvaret opkald (8 px, fyld `--text-muted`), fordi teksten "Ikke besvaret" bærer betydningen
- ringen på 2 px i `--surface` mellem overlappende avatarer
- haloen på 3 px i `--accent-subtle` om den aktive markør

**Samlet status på lead-kortet** genbruger `.badge` og `.s-*`. Ordlyden bestemmer UI/UX. Er flere tilstande sande, vises den første i denne rækkefølge:

| Prioritet | Tilstand | Klasse |
|---|---|---|
| 1 | Mindst ét fejlet | `.s-fejlet` |
| 2 | Mindst ét transcriberes | `.s-transcriberer` (pulserende prik) |
| 3 | Mindst ét i kø | `.s-ventende` ("I kø") |
| 4 | Lyd mangler | `.s-lyd` |
| 5 | Alle besvarede er transcriberet | `.s-faerdig` ("3 af 3 transcriberet") |
| 6 | Delvist transcriberet, resten ikke valgt | `.s-ikke-valgt` ("1 af 4 transcriberet") |
| 7 | Ingen besvarede opkald | `.s-ikke-valgt` |

Grøn må kun stå, når hele leadet er færdigt. Ellers ligner et delvist lead et færdigt kvalitetstjek.

---

# Typography

Ingen nye størrelser. Alle tal har `tabular-nums`.

| Element | Font, størrelse og vægt | Farve |
|---|---|---|
| Lead-kort: titel (`.card-link`) | Inter 15/600, lh 1.35 | `--text`, hover `--accent` |
| Lead-kort: metalinje 1 (kampagne · kundens nr.) og 2 (agentnavne · første–seneste) | Inter 13/400, lh 1.45 | `--text-muted` |
| Lead-kort: taletid | Inter 14/500 | `--text-2` |
| Lead-kort: "4 opkald · 3 besvarede" | Inter 13/400 | `--text-muted` |
| Avatar-initialer i stakken | Inter 12/600 | hvid (`.av-0`: `--neutral-text`) |
| Lead-sidens H1 "Lead 123456" | Inter Tight 28/700 (`.d-head h1`, 22 px under 640 px) | `--text` |
| Opkaldssektion: nummer-label | Inter 12/600, VERSALER via `text-transform`, 0.02em | `--text-muted` |
| Opkaldssektion: H2 dato og tid ("tir. 12. nov. 2026, 14:02") | Inter Tight 18/700, lh 1.25 | `--text` |
| Sektionens meta-chips | som `.chip-meta` 13/400. Status-badge 13 px | `--text-2` |
| Kompakt række | Inter 14/400. Klokkeslæt 14/500 | `--text-muted` / `--text` |
| Træf-chip | Inter 12/600 | `--text` på `--mark-bg` |
| Find-status "3 af 12 · opkald 2 af 5" | som `.find-status` 12/400 | `--text-muted` |
| Uddrag i lead-kort ved søgning | som `.cc-excerpt`. Præfiks "14:02:" i `.who-n` (600 `--text-2`) | `--text-2` |

Retning skrives som "↓ Ind" / "↑ Ud". Begge tegn er i subsettet. "→" og "–" må ikke stå mellem første og seneste: "–" (U+2013) er i subsettet og er tilladt, men "→" er ikke.

---

# Spacing and layout

## Lead-kort på Adversus-fanen (`.call-card` + modifier `.lead-card`)
- Samme ramme som `.call-card`: radius 12, padding 16 × 20, `gap: 10px 20px`, to kolonner og samme hover og fokus.
- **Venstre celle** (`.cc-main`): avatarstak, titelblok og tags (leadstatus).
  - Titelblokken har titel og to metalinjer med 2 px imellem.
  - Avatarnavnene står som tekst i metalinje 2 ("Sara, Jonas og 1 mere").
  - Stakken er `aria-hidden`.
- **Avatarstak** (`.av-stack`):
  - `display: flex`. Avatarer på 32 × 32 px med `margin-left: -8px` fra nummer 2 og `box-shadow: 0 0 0 2px var(--surface)`.
  - Højst 3 avatarer. Er der flere, vises "+n" som fjerde cirkel med `.av-0`.
  - Rækkefølge: den agent, der har talt mest med kunden, først (ellers seneste).
  - Én agent: én avatar uden overlap.
  - Ukendt agent: `.av-0` med "?".
- **Højre celle** (`.cc-side`, gap 14):
  - Talblok (`.lead-stats`: lodret flex, `align-items: flex-end`, gap 2): taletid øverst, "4 opkald · 3 besvarede" under.
  - Derefter status-badge.
- **Uddrag** (kun ved søgning): `grid-column: 1 / -1`, 2 linjer som i F007.
- **Under 640 px:**
  - Én kolonne. `.cc-side` står under titelblokken med `justify-self: start`, og `.lead-stats` bliver vandret (`flex-direction: row`, gap 8, `align-items: baseline`).
  - Avatarer på 28 px med overlap −6 px.
- **"Uden lead"-kortet:**
  - Samme kort. I stakkens plads står en cirkel på 32 px i `--surface-2` med ikonet `phone` 16 px i `--text-muted` (form som `.empty-icon`, bare mindre).
  - Titlen "Uden lead" og ingen status-tag.
  - Det står sidst på siden eller pr. opkald, som UI/UX vælger. Vælges pr. opkald, bruges F007's opkaldskort uændret.

## Lead-sidens hoved (`/lead/{id}`)
- `.back` ("Tilbage") og så `.d-head`.
- Venstre del: H1 "Lead 123456" og `.d-chips`, i denne rækkefølge:
  1. kilde-chip "Adversus"
  2. leadstatus (`.tag-success` med `trophy` 14, eller neutral `.tag`, begge 26 px høje som i `.d-chips .tag`)
  3. kampagne
  4. kundens nummer
  5. "4 opkald · 3 besvarede"
  6. taletid ("Taletid 12:34")
- Højre del (`.head-actions`, gap 8): "Download .zip" (sekundær) og "Download .txt" (primær). Højst én primærknap.
- Under hovedet, 16 px nede: værktøjsrækken (`.lead-tools`) med `.find` (flex 0 1 360px) og find-status. Den har samme form som F007's `.find`.
  - Anbefaling til UI/UX: rækken klæber (`position: sticky; top: 0`, under 900 px `top: var(--topbar-h)`, `z-index: 4`).
  - Klæber den, får den `--bg`-fyld, padding 8 px 0 og `border-bottom: 1px solid var(--border)`. Teksten bag den dækkes, ellers kan feltet ikke læses.
- Indholdets max-width er 1280 px (som `.detail-v2`).

## Tidslinjen (`ol.lead-timeline`, `list-style: none`)
- **Hvert punkt** (`li.lt-item`):
  - `display: grid; grid-template-columns: 24px minmax(0, 1fr); column-gap: 16px;`.
  - Punkterne har 16 px imellem. To kompakte rækker i træk har kun 4 px imellem.
- **Forbindelseslinjen:**
  - `li.lt-item::before`: absolut i gutteren, `left: 11px; width: 2px; top: 0; bottom: -16px;` i `--border-control`.
  - På første punkt starter linjen ved markørens midte. På sidste punkt slutter den ved markørens midte (ingen hale).
  - Linjen er dekorativ.
- **Markør** (`.lt-dot` i gutteren, `aria-hidden`, `justify-self: center`, `border-radius: 50%`, `position: relative; z-index: 1`):
  - **Besvaret:** 12 × 12 px, fyld `--surface`, `box-shadow: inset 0 0 0 2px var(--text-muted)`, `margin-top: 24px`, så midten flugter med sektionens H2.
  - **Ubesvaret (kompakt):** 8 × 8 px, fyld `--text-muted`, lodret centreret i rækken.
  - **Sektion med aktivt træf:** 12 × 12 px, fyld `--accent`, `box-shadow: 0 0 0 3px var(--accent-subtle)`.
- **Opkaldssektion, besvaret** (`section.lt-call`, kort):
  - `--surface`, 1 px `--border`, radius 16, padding 20 × 24, lodret gap 16, `--shadow-1`.
  - **Hoved** (`.lt-head`): `flex-wrap: wrap`, `justify-content: space-between`, gap 12.
    - Venstre: nummer-label, så H2 (2 px imellem), så meta-chips med gap 8 og 10 px over.
    - Chips i rækkefølge: retning, agent (avatar 24 px + navn inde i chippen, gap 6), varighed, disposition (`.tag` neutral) og status-badge.
    - Højre: eventuel træf-chip og "Transcribér dette opkald" (`.button.secondary.small`). Begge flugter med H2.
  - **Indhold, 1180 px og bredere:** `grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start;`.
    - Samtalen (`.transcript`, uændret) står til venstre.
    - `.player-v2` står til højre med `position: sticky; top: 72px` (under den klæbende værktøjsrække). Den klæber kun inden for sin egen sektion.
  - **Indhold under 1180 px:** én kolonne. Afspilleren står over samtalen og klæber **ikke**, fordi flere klæbende afspillere oven på hinanden ikke kan bruges.
  - Intet transcript endnu: `.msg.info` eller en statuslinje i stedet for samtalen (ordlyd fra UI/UX).
- **Kompakt række, ubesvaret** (`div.lt-missed`, intet kort):
  - `display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; min-height: 32px; padding: 4px 0;`.
  - Indhold: "12. nov. 14:02" (`--text`, 500), " · ↑ Ud · Sara · Ikke besvaret" (`--text-muted`).
  - Ingen afspiller, ingen kant og ingen avatar.
- **Under 640 px:**
  - Gutteren er 16 px, column-gap 10, og linjen står på `left: 7px`.
  - Den besvarede markør er 10 px.
  - Sektionens padding er 16 px.
  - Handlingerne i hovedet får fuld bredde og 44 px højde.
  - Bobler 88 % som i F007.

## `/opkald/{id}`: "Se hele leadet"
- Sidste chip i `.d-chips`: `<a class="chip-meta chip-lead">`.
- Teksten er "Se hele leadet" i `--accent-ink` 600 med `chevron-right` 14 px efter teksten (gap 4).
- Hover: understregning. Fokus: den globale ring.
- 44 px høj under 640 px.

---

# Iconography

Kun eksisterende ikoner i `Ui::ICONS`. **Ingen nye.**

| Ikon | Størrelse | Brug |
|---|---|---|
| `trophy` | 14 | Succes-lead-tag (liste og hoved) |
| `phone` | 16 | pladsholder på "Uden lead"-kortet, `--text-muted` |
| `download` | 16 | "Download .txt" og "Download .zip" |
| `search` | 16 | "Find i samtalen" på tværs af opkald |
| `arrow-left` | 16 | "Tilbage" |
| `chevron-right` | 14 | "Se hele leadet" |
| `chevron-left` / `chevron-right` | 16 | eventuelle knapper til forrige og næste træf, hvis UI/UX vil have dem (`aria-label` "Forrige træf"/"Næste træf") |

Retningen står som tekst ("↓ Ind"/"↑ Ud"). Markører og linje er CSS, ikke ikoner. `phone-missed`, `phone-incoming` og lignende tilføjes ikke.

---

# Visual states

| Element | Hover | Fokus | Aktiv/valgt |
|---|---|---|---|
| Lead-kort | som `.call-card`: kant `--border-control` + `--shadow-2` (mørkt: kun kant) | `:focus-within`: 2 px `--focus`, offset 2 | — |
| Opkaldssektion | ingen hover (kortet er ikke et link) | — | **aktivt træf:** `.is-hit-current` giver `box-shadow: var(--shadow-1), 0 0 0 2px var(--accent)`, og markøren skifter til accent |
| Sektion med træf (ikke aktiv) | — | — | `.has-hits`: træf-chippen "3 træf" vises i hovedet. Markør og ramme er uændrede |
| Træf i teksten | — | — | uændret fra F007: `mark.find-hit` og `mark.find-hit.is-active` (accent) |
| Kompakt række | ingen | — | kan ikke have træf |
| "Se hele leadet" | understreget | ring | — |
| "Transcribér dette opkald" | som `.secondary` | ring | sendt: disabled (opacity .5) eller statusskift efter UI/UX |

**Det aktive træf vises på fire måder:**
1. markeringen i teksten (accent-fyld)
2. ringen om sektionen
3. markøren på tidslinjen
4. find-status "opkald 2 af 5"

Farven er altså ikke eneste bærer (1.4.1). Ringen tegnes med `box-shadow`, ikke `border`, så layoutet ikke flytter sig, når man hopper.

**Loading:** som F007. Der er ingen skeletter, kun den pulserende prik på "Transcriberer".

---

# Shadows and borders

| Element | Radius | Kant | Skygge |
|---|---|---|---|
| Lead-kort | `--radius-lg` (12) | 1 px `--border` | `--shadow-1`, hover `--shadow-2` |
| Opkaldssektion | `--radius-xl` (16) | 1 px `--border` | `--shadow-1` |
| Afspiller i sektion | uændret (16) | uændret | ingen `--shadow-2` (den klæber kun inden for sin sektion) |
| Klæbende værktøjsrække | 0 | bund 1 px `--border` | ingen |
| Træf-chip | `--radius-xs` (6), højde 22, padding 0 8 | ingen | ingen |
| Avatarer og markører | `--radius-pill` | 2 px via `box-shadow` | ingen |

Forbindelseslinjen er 2 px solid. Alle andre kanter er 1 px som i F007.

---

# Animation / transitions

- Lead-kort: som `.call-card` (border-color og box-shadow, 120 ms, `--ease`).
- Ringen og markøren ved aktivt træf skifter **uden** animation.
- Rulning til et træf i et andet opkald bruger `scroll-behavior: smooth`, kun uden `prefers-reduced-motion`. Den globale regel findes allerede.
- Mål for rulningen: `scroll-margin-top: 88px` på `mark.find-hit`, så træffet ikke ender under den klæbende værktøjsrække (under 900 px: 88 px + `--topbar-h`).
- Ingen nye `@keyframes`.

---

# Brand alignment notes

- Lead-siden er en sammenstilling af F007's detaljeside. Kortet, chatboblerne og afspilleren er de samme, så et opkald ser ens ud på `/opkald/{id}` og inde i leadet.
- Succes hører til **leadet**: trofæ-tagget står kun i lead-kortet og lead-hovedet, aldrig i en opkaldssektion. Det løser spec'ens problem med, at "Succes-lead" ser ud, som om netop det opkald var salget.
- Tidslinjen er bevidst neutral (grå linje og grå markører). Accent bruges kun til det aktive træf, så "hvor er jeg" er det eneste, der lyser.
- Ubesvarede opkald fylder én linje. Forløbet kan læses, uden at støj konkurrerer med samtalerne.

---

# Visual decisions

1. **Avatarstak** med højst 3 avatarer + "+n", overlap 8 px og en ring i kortets farve. Navnene står som tekst, så stakken kan være dekorativ.
2. **Samlet status med prioritet.** Grøn kræver, at hele leadet er færdigt. Delvist transcriberet er hult (`.s-ikke-valgt`).
3. **Tidslinjen med gutter på 24 px**, grå linje på 2 px og markør på 12 px (8 px for ubesvarede). Ingen ikoner.
4. **Afspilleren klæber pr. sektion** fra 1180 px. Under det klæber den ikke.
5. **Aktivt træf vises fire steder**: mark, ring, markør og find-status. Ringen er `box-shadow`, så layoutet står stille.
6. **Træf-chippen bruger `--mark-bg`**, så den visuelt hører til markeringerne i teksten.

## Nye visuelle beslutninger i dette brief

### Tidslinje for forløb (gutter, linje og markør)
- **Hvad:** lodret tidslinje med en sektion pr. hændelse.
- **Værdi:** gutter 24 px (16 under 640), linje 2 px `--border-control`, markør 12 px med ring 2 px `--text-muted` (aktiv: `--accent` + halo 3 px `--accent-subtle`), mindre hændelser 8 px fyldt.
- **Hvorfor:** forløbet skal læses som en historie (spec), og den består 1.4.11 uden nye tokens.
- **Gælder for:** F008 og fremtidige forløbsvisninger (fx Zendesk-tickets med flere opkald).
- **Anbefaling:** Vedtag som standard? JA

### Avatarstak for flere personer
- **Værdi:** 32 px (28 under 640), overlap −8 px (−6), ring 2 px `--surface`, højst 3 + "+n" i `.av-0`.
- **Hvorfor:** flere agenter på ét lead skal kunne ses med ét blik.
- **Gælder for:** alle fremtidige visninger med flere personer.
- **Anbefaling:** Vedtag som standard? JA

### Samlet status for en gruppe (prioritetsrækkefølge)
- **Værdi:** Fejlet, Transcriberer, I kø, Lyd mangler, Færdig (alle), Delvist/ingen (hul).
- **Hvorfor:** grøn må kun betyde "færdig" for hele gruppen.
- **Gælder for:** F008 og fremtidige grupperede lister.
- **Anbefaling:** Vedtag som standard? JA

---

**Tjekliste til visuel review**
- [ ] Ingen `style=""` noget sted (heller ikke på avatarer, markører eller SVG). Overlap, ringe og linje kommer fra klasser og pseudo-elementer.
- [ ] Ingen nye tokens, fonte eller hex-værdier i `app-v2.css`. Alle farver er F007 v2-tokens.
- [ ] Ingen nye ikoner i `Ui::ICONS`. Der bruges kun `trophy`, `phone`, `download`, `search`, `arrow-left` og `chevron-left`/`chevron-right`.
- [ ] Med `F008_leads` slukket ser Adversus-fanen ud præcis som F007.
- [ ] Lead-kort: ramme som `.call-card`. Avatarstakken har højst 3 + "+n" med en ring på 2 px i `--surface`. Agentnavnene står i metalinjen. Taletid 14/500 `--text-2` og "n opkald · m besvarede" 13 px `--text-muted` med tabulære tal.
- [ ] Den samlede status følger prioritetstabellen. Delvist transcriberet er aldrig grøn.
- [ ] Succes-lead-tagget med trofæ står i lead-kort og lead-hoved, aldrig i en opkaldssektion.
- [ ] "Uden lead"-kortet bruger `phone` i en cirkel på 32 px i `--surface-2` (eller F007-kort pr. opkald).
- [ ] Lead-hovedet har H1 28/700 Inter Tight og chips i rækkefølgen kilde, status, kampagne, kunde, opkald og taletid. ".txt" er den eneste primærknap.
- [ ] Tidslinje: gutter 24 px, linje 2 px `--border-control` uden hale efter sidste punkt, markør 12 px flugtende med H2 og 8 px for ubesvarede.
- [ ] Opkaldssektion: kort med radius 16 og padding 20 × 24. H2 18/700 med nummer-label i VERSALER over. Chips: retning, agent, varighed, disposition, status.
- [ ] Ubesvarede vises på én linje (min-højde 32 px) uden kort og uden afspiller.
- [ ] Fra 1180 px står afspilleren til højre i sektionen og klæber kun inden for sin sektion. Under 1180 px står den over samtalen og klæber ikke.
- [ ] Aktivt træf: `mark.find-hit.is-active` + ring 2 px `--accent` om sektionen (box-shadow, intet layoutskift) + accentmarkør + "opkald x af y" i find-status. Sektioner med træf har træf-chippen i `--mark-bg`.
- [ ] Et træf havner ikke under den klæbende værktøjsrække eller topbaren (`scroll-margin-top`).
- [ ] "Se hele leadet" på `/opkald/{id}` er en chip i `--accent-ink` med `chevron-right` 14 og 44 px høj under 640 px.
- [ ] Ingen "→" som tekst.
- [ ] Ved 320 px er der ingen vandret scroll på listen og lead-siden i begge temaer. Alle klikbare elementer er 44 px høje under 640 px.
- [ ] Mørkt tema: hover på lead-kort ændrer kun kanten. Alle chips og tags har mørke flader.
- [ ] `prefers-reduced-motion` slår glat rulning og pulsen fra.

---

**Kilder:**
- `C:\ClaudeCoworkers\whisper.xanouso.com\product-ai\F008_v1.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\tasks\impact-F008.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\graphic-design\F007_visual_v2.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app-v2.css`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\Ui.php` (ICONS)

**Til Team Lead:**
- `ui-ux/F008_design_v1.md` findes ikke. Følgende er visuelle anbefalinger, og UI/UX v1 har sidste ord om adfærd og ordlyd:
  - den klæbende værktøjsrække
  - knapperne til forrige og næste træf
  - placeringen af "Uden lead"
  - om flere ubesvarede i træk samles
- Jeg kunne ikke køre en kontrastberegning (guarden blokerer scripts). Derfor bruger briefet bevidst kun par, der allerede er beregnet i F007 v2.
- Én ting i `app-v2.css` afviger fra F007 v2-briefet: `.av-0` er `--surface-2` med `--neutral-text`, ikke #5B6475 med hvid. Briefet her følger CSS'en på main ("+n" giver 7.05 / 8.33).
