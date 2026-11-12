Branch: feature/F006-oauth @ a68471d29a14e23f3af73d4721264e80b962617f

# F006 — Visuelt review af Claude custom connector via OAuth, v1
# Status: APPROVED

Implementationen følger briefet `graphic-design/F006_visual_v1.md` på alle punkter. Der er to NON-BLOCKING fund, og ingen af dem påvirker hierarkiet. Det første kan rettes i samme PR med en linje i PHP, hvis Team Lead vil have det med.

Jeg har læst følgende fra den committede branch (`git fetch origin`, `git diff origin/main...origin/feature/F006-oauth`, `git show origin/feature/F006-oauth:…`):
- `homedir/public_html/assets/app.css`, både diffen (§15) og hele filen for tokens, `button`, `.hint`, `dl.meta`, media-blokke og reduced motion
- `homedir/src/UiPages.php`, nemlig `consent()`, `login()`, `connect()` og `error()`
- `homedir/src/OAuth/Authorize.php`, `homedir/src/View.php` og CSP-headeren i `App.php`

## Tjek

**1. Tokens og kontrast i lyst og mørkt tema: OK**
- De 14 nye linjer i app.css indeholder ingen hex-, rgb- eller andre hardkodede farver. Der bruges kun `--text`, `--surface-2`, `--border`, `--radius-sm`, `--sp-*`.
- Begge temaer får derfor værdierne fra `:root` og `prefers-color-scheme: dark`, og kontrasttallene i briefet gælder uændret:
  - `code.host`: 15.38 i lyst og 13.11 i mørkt tema.
  - `dt` og hints: 6.07 i lyst og 6.85 i mørkt tema.
  - Log ud: 6.27 i lyst og 7.11 i mørkt tema.
- Accent bruges kun på Godkend, Log ud og fokus. Værten har ingen accent- eller statusfarve.

**2. Ingen inline style (CSP): OK**
- Der står ingen `style=` og ingen `<script>` i ny markup.
- CSP er `style-src 'self'`.
- Fejlsidens `<details>` får sin `cursor: pointer` fra app.css og ikke fra inline style.

**3. `.consent`, `dl.meta.single` og `code.host`: OK**
- `.consent`:
  - Kortet er `<div class="card narrow consent">`.
  - `strong` er 600.
  - `ul` har `0 0 12px` og 24 px indrykning, og `li` har 4 px.
  - `form.actions` har `16px 0 24px`.
  - Hints, der følger efter hinanden, har 8 px imellem.
  - Den lodrette rytme passer med tabellen i briefet §4. `p` til hint giver 12 px, fordi hintets 6 px kollapser. `dl` til H2 giver 32 px. Varigheds-hintet står 24 px under formularen.
- `dl.meta.single`:
  - `grid-template-columns: 1fr; gap: 12px`. Specificiteten (0,2,1) slår `dl.meta` (0,1,1).
  - App-hintet er en `span.hint` inde i `dd`.
- `code.host`:
  - Mono, `1em` = 15 px, 600, `--text` på `--surface-2`, 1 px `--border`, 6 px radius, padding 1 px 4 px og `box-decoration-break: clone` med `-webkit-`-præfiks.
  - Specificiteten (0,1,1) slår `code { font-size: 0.9em }`.
  - Al anden `code` er uændret.
- Engineeren skriver `.consent .hint + .hint` i stedet for briefets `p.hint + p.hint`. Det er en korrekt udvidelse: "Ikke dig? Log ud" er en `div.hint` og ikke en `p`, så de 8 px også skal gælde den.

**4. `button.link` i hint ved under 640 px: OK**
- `.consent .hint button.link` har specificiteten (0,3,1) og slår `button { min-height: 44px }` i `@media (max-width: 640px)`, som har (0,0,1). Målet forbliver 24 px.
- `font-size: inherit` giver 13 px, og `padding: 0 2px` passer.
- Formularen er `form.inline` (`display: inline`), så "Ikke dig? Log ud" står på én linje.
- Den er ikke en `form.actions` og bliver derfor heller ikke 100 % bred under 400 px.

**5. Reduced motion: OK**
- Der er ingen nye transitions eller animationer.
- Knappernes eksisterende transition på 120 ms slås fra af den globale regel `prefers-reduced-motion: reduce` (`transition: none !important`).

## Fund

## Fund 1
Severity: NON-BLOCKING
Element: `UiPages::consent()`, `$named` når klientnavnet er tomt (lead og App-`dd`)
Expected: Briefet § Visual states siger: "En unavngivet app" vises uden «» i almindelig `dd`-vægt 500 og ikke i `strong`, fordi det ikke er et navn fra appen.
Actual: `'<strong>En unavngivet app</strong>'` får vægt 600 både i lead og i `dd`. Teksten står korrekt uden «».
Fix: Erstat `<strong>En unavngivet app</strong>` med den rene tekst `En unavngivet app` i `$named`.
Begrundelse for at acceptere: 600 er samme vægt som værten og aldrig tungere, så briefets kerneregel om, at navnet ikke må veje mere end værten, holder stadig. Det er en kosmetisk afvigelse i et kanttilfælde.

## Fund 2
Severity: NON-BLOCKING
Element: app.css §15, `section.card details summary, .card.narrow details summary { cursor: pointer; }`
Expected: Briefet siger, at fejlsidernes `<details>` bruger browserens standard plus `summary { cursor: pointer; }` ligesom `.msg details`, og at der ikke kommer en ny stil.
Actual: Reglen er en ny linje, og den gælder alle `section.card`. Den indeholder kun `cursor` og ingen farve, typografi eller spacing.
Fix: Ingen rettelse nødvendig. Hvis man vil holde den smallere, kan den indsnævres til `.card.narrow details summary`.
Begrundelse for at acceptere: reglen har ingen visuel effekt ud over cursoren og svarer til det eksisterende `.msg summary`.

## Bemærkninger (ingen handling)
- `button.link` er `inline-flex` med `min-height: 24px`, så hint-linjen "Ikke dig? Log ud" er ca. 24 px høj mod 19,5 px i de andre hints. Det er forventet efter briefet §5 (WCAG 2.5.8) og ser ikke forkert ud med 8 px til nabo-hintene.
- Login med kontekst (`.msg.info`) og hintet "Vil du ikke forbinde? Luk bare vinduet." bruger kun eksisterende komponenter.
- "Forbind Claude" på Konto bruger kun eksisterende komponenter: `ol.steps`, `.secret` med readonly-felt på `--surface-2`, `table.keys` og `.button.danger-secondary.small`. Der er ingen rester af `details.advanced`.

## Til Team Lead
- Briefets to forslag kan logges i DESIGN_LOG.md som implementeret: `code.host` og `dl.meta.single` er begge markeret "Vedtag som standard? JA". `DESIGN_LOG.md` findes stadig ikke i projektet.
- Ingen filer er ændret.
