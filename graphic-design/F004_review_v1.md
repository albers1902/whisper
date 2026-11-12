Branch: feature/F004-adversus @ c2f001dd0a4b9b95241395e520687d3221ad2e66

# F004, visuelt review v1
# Status: APPROVED

Jeg har læst den committede branch: `git diff origin/main...origin/feature/F004-adversus` for `homedir/public_html/assets/app.css`, `app.js`, `homedir/src/Ui.php`, `UiCalls.php`, `CallPages.php`, `App.php` og `CallView.php`. Jeg har også læst `git show` af den fulde app.css for at se de omgivende regler.

Grundlaget er `graphic-design/F004_visual_v1.md` og `ui-ux/F004_design_v1.md`. UI/UX-briefet går forud for placering. Team Leads afgørelser 1–8 står i `fullstack-ai/F004_v1.md`.

Jeg har ingen BLOCKING-fund. Der er to NON-BLOCKING-bemærkninger nederst, og begge kan accepteres med den begrundelse, der står ved dem.

# Tjek

## Tokens og nye farver
- CSS-diffen har ingen hex-, rgb- eller hsl-værdier. Alle farver i de nye regler er eksisterende tokens: `--text-muted`, `--border`, `--border-strong`, `--success-text`, `--success-border`, `--surface`, `--surface-2`, `--text`, `--nav-active` og `--accent-subtle`.
- Der er ingen nye custom properties. Det svarer til briefets "Ingen nye farvetokens".
- Faste mål (20px, 6px, 2px, 4px) står kun der, hvor briefet selv giver dem.

## Kontrast i lyst og mørkt tema
Briefets tal holder, fordi tokenværdierne i `:root` og `prefers-color-scheme: dark` er uændrede:
- Kilde-tag: 6.07 / 6.85 på surface.
- Succes-tag: 7.00 / 10.14.
- "Ikke valgt": tekst 6.07 / 6.85 og ramme 3.77 / 3.66 (≥ 3:1).
- Aktivt segment: 7.14 / 6.38.

Der er én ny kombination, som briefet ikke regnede på: `.hint` (`--text-muted`) i `.msg.info` på `--info-bg`. Jeg har regnet den efter WCAG-formlen til 5.27 i lyst tema og 6.13 i mørkt. Begge består AA.

## .tag, .tag-source, .tag-success og .tags
Alt svarer til briefet:
- `.tag`: inline-flex, gap 4px, 20px høj, padding 0 6px, 1px ramme, `--radius-sm`, `--fs-sm`/500, line-height 1, nowrap, transparent baggrund.
- `.tag-source` og `.tag-success` bruger de rigtige tekst- og rammetokens.
- `.tags` har gap 6px og wrap.
- Placeringen følger UI/UX og Team Lead 1: en egen kolonne Kilde som nr. 2, og snippet-rækken har `colspan="8"`. Visual-briefets fallback (tags under tiden) er derfor korrekt droppet.
- Zendesk og Adversus får det samme neutrale tag.
- Succes-tagget står kun under "Alle opkald" i listen og altid i detaljehovedet (Team Lead 2).
- Detaljehovedet bruger `.head-tags` (flex, gap 8px, wrap) med succes-tagget før badget.
- Undertitlen har "Adversus" som ren tekst, og meta har "#lead · Succes-lead" som ren tekst. Begge dele er efter briefet.

## .segmented og .view-switch
- `.segmented`: inline-flex, padding 2px, gap 2px, `--surface`, 1px `--border-strong`, `--radius-sm`.
- Hvert valg: min-height 32px, padding 0 12px, radius 4px, ingen understregning og 500 i vægt.
- Hover: `--text` på `--surface-2`.
- Aktivt valg: `aria-current="true"` giver `--nav-active` på `--accent-subtle` og vægt 600. Det aktive valg har ingen hover-ændring.
- Det aktive valg skelnes også på vægt og ikke kun på farve, som UX §10 kræver.
- Fokus bruger den globale `:focus-visible`-ring.
- Under 640px: vælgeren er i fuld bredde, hvert valg har `flex: 1` og min-height 44px, og "Vis:" står over vælgeren (UX §8).
- Placeringen er mellem filterkortet og chips med 12px margin (UX §1.1).
- Labelen "Vis:" bruger den eksisterende `.muted` i 14px, ligesom "Hurtigt:".

## .s-ikke-valgt
- Tekst i `--text-muted`, transparent baggrund og en inset-ramme på 1px `--border-strong`, så højden forbliver 22px.
- Prikken er en hul ring: `::before` er transparent med en inset-skygge på 1.5px i currentColor.
- Kaskaden er korrekt. `.s-ikke-valgt` og `.s-ikke-valgt::before` står efter `.badge` og `.badge::before` med samme specificitet og vinder derfor.
- `Ui::status` afgør "Ikke valgt" før lydstatus (UX §2).
- Ingen animation.

## Trophy-ikonet
- Path-data er identisk med Lucide `trophy`: de 6 paths, herunder `M18 2H6v7a6 6 0 0 0 12 0V2Z`.
- Ikonet renderes gennem `Ui::icon` med de fælles attributter: stroke 1.75, currentColor, `aria-hidden="true"`, `focusable="false"` og ingen `style`.
- Det bruges kun i `.tag-success` i 14px (`.icon-14`) og arver derfor `--success-text`.
- Kilde-tagget, knappen og vælgeren har ingen ikoner.

## CSP og inline style
- Der er ingen `style=` i nogen af de tilføjede linjer i `homedir/src` eller `public_html`.
- Knappen er en almindelig `<form method="post">` med CSRF.
- JS'en i `app.js` er en listener i den eksterne fil. Den har ingen `onclick` og ingen inline script.

## Reduced motion
- Der er ingen nye animationer.
- `.segmented a` har kun `transition` på background-color og color (`var(--dur)` og `var(--ease)`), og den eksisterende globale regel `prefers-reduced-motion: reduce` slår den fra.
- JS'ens sender-tilstand skifter kun tekst og attributter, uden bevægelse.

## Knappen "Transcribér dette opkald"
- Den er en primær `.button` i `.msg.info` i højre kolonne. Den har intet ikon og står venstrestillet.
- `form.actions.select-call` har margin-top 12px. Afstanden til teksten over den bliver 12px, fordi marginerne kollapser med `p`.
- Under 400px er knappen i fuld bredde via `form.actions > *`, og under 640px er den mindst 44px høj.
- Der er ingen disabled-tilstand fra serveren.
- JS'ens "Sætter i kø …" bruger den eksisterende regel `[aria-disabled="true"]` (opacity .5). Det er en kendt tilstand i appen, ikke en ny visuel behandling.

# Bemærkninger (NON-BLOCKING, kan accepteres)

## Bemærkning 1
Severity: NON-BLOCKING
Element: hjælpeteksten under knappen (`p.hint#vaelg-hjaelp`)
Expected: visual-briefets typografitabel siger "Forklarende tekst ved knappen: 15px, `--text` (som `.msg`)".
Actual: forklaringen over knappen er 15px `--text`, som briefet siger. UX' ekstra hjælpetekst under knappen bruger den eksisterende `.hint` i 13px og `--text-muted`. Kontrasten er 5.27 / 6.13 på `--info-bg`.
Fix: ingen. Accepteres, fordi `.hint` er appens etablerede mønster for hjælpetekst (fx ved zip), og fordi teksten er sekundær og knyttet til knappen med `aria-describedby`.

## Bemærkning 2 (til UI/UX, ikke visuelt)
Severity: NON-BLOCKING
Element: mobilkort under 640px
Expected: UX §8 siger, at "Succes-lead"-mærket står i kortets første linje ved siden af status.
Actual: mærket står i Kilde-cellens `data-label`-række sammen med kilde-tagget. Visuelt fungerer det: tags wrapper pænt, og kontrasten er den samme på kortets `--surface`.
Fix: ingen fra grafisk side. Placeringen hører under UI/UX, og efter Team Lead 2 vises mærket kun under "Alle opkald". Team Lead afgør, om det skal flyttes.

# Til Team Lead
Briefets fire "Nye visuelle beslutninger" er implementeret præcis som foreslået og kan logges som vedtaget:
- `.tag`
- `.s-ikke-valgt`
- `.segmented`
- `trophy`

`DESIGN_LOG.md` findes stadig ikke.
