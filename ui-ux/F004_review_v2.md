Branch: feature/F004-adversus @ 0dd1d9b60aa316374a00f081abcee45852806701

# F004, review v2
# Status: APPROVED

Læst som committet med `git diff c2f001d..0dd1d9b` i `UiCalls.php`, `Ui.php`, `app.js`, `app.css`, `App.php`, `CallPages.php` og `Calls.php`. Alle fire findings fra v1 er rettet. Det nye kan godkendes. Der er to NON-BLOCKING noter nedenfor, og ingen af dem kræver ændringer før merge.

## Rettelser fra v1
- **Finding 1 (BLOCKING): rettet.** Med `$featureOn` hedder linket "Tilbage til opkald", også når ctx er tom. Med flaget slukket står der stadig "Alle opkald", så AK9 holder.
- **Finding 2: rettet.** `Ui::msg` har fået det valgfri argument `$role`. Info-kvitteringen på detaljen renderes med `role="status"`. Andre kald af `msg` er uændrede, fordi standardværdien er null.
- **Finding 3: rettet.**
  - `pageshow` med `ev.persisted` gendanner `disabled`, `aria-disabled` og labelen "Transcribér dette opkald".
  - Labelen står direkte i JS, men den er identisk med serverens label og er den eneste label, knappen har (§7). Det er acceptabelt.
- **Finding 4: rettet.** `.select-call button` har `width: 100%` og `min-height: 44px` under 480px.

## Det nye
- **Knappen ved fejlet lyd: godkendt.**
  - Knappen vises kun for Adversus-opkald, hvor lyden er fejlet, før der blev lavet et transcript (`CallPages::selectable`). Zendesk er uændret.
  - Venstre kolonne beholder fejlen med "Teknisk besked". Højre kolonne viser "Der kan ikke laves et transcript uden lyd." med samme knap og label. Hjælpeteksten er "Lyden hentes igen fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter."
  - Kvitteringen er "Lyden hentes igen fra Adversus. Transcriptet laves, når lyden er hentet." Den bruger ok-flash med `role="status"`.
  - Briefets punkt 12.3 ("knappen vises ikke igen") er dermed overhalet af QA F-2. Ændringen er en forbedring for brugeren, og copy og mønster er konsistente.
- **Flash ved loftet: godkendt.**
  - Teksten "Der er allerede mange opkald i kø. Prøv igen senere." vises som info med `role="status"`.
  - Den er neutral og beskylder ikke brugeren, og intet ændres. Knappen bliver stående, hvilket er korrekt, fordi et nyt forsøg senere er den rigtige handling.
  - NON-BLOCKING: teksten kunne sige, hvor længe det typisk varer. Det er ikke nødvendigt.
- **Admin-advarslen "Nogle Adversus-opkald kunne ikke hentes.": godkendt.**
  - Den har typen `warn` og `<details>` "Teknisk besked", præcis som mønstret for "Seneste hentning fra Adversus stoppede".
  - Kun administratorer ser den, og den forsvinder efter et døgn (`WARN_TTL`).
  - Den gør afvigelsen "ukendt retning springes over" fra v1 synlig for drift. Det styrker min accept af den afvigelse.
- **Manuelt valgte opkald i standardvisningen: godkendt med en note.**
  - Tomtilstandene er upåvirkede. Når standardvisningen er tom uden filtre, findes der heller ingen manuelt valgte opkald, så teksten "Opkald fra Zendesk og opkald på succes-leads fra Adversus …" er stadig sand. Tomtilstanden med filtre er generisk.
  - Navnet "Succes-leads" er nu ikke helt præcist. Visningen viser også Adversus-opkald, som en bruger selv har valgt. De rækker har intet mærke, fordi Succes-lead-mærket i listen kun står under "Alle opkald" (tolkning 2), så de ser ud som succes-leads.
  - Jeg vurderer det som acceptabelt. Brugeren har selv valgt opkaldet, så det ville overraske mere, hvis det forsvandt fra visningen. Detaljesiden viser altid sandheden: meta "Lead: #id" uden "· Succes-lead".
  - NON-BLOCKING, til Team Lead og et eventuelt senere ID: hvis ejeren oplever navnet som misvisende, kan Succes-lead-mærket vises i begge visninger, så forskellen er synlig. Alternativt kan segmentet omdøbes, fx til "Udvalgte". Det samme gælder caption "Opkald – succes-leads". Jeg kræver ingen ændring i F004.

# Findings
Ingen BLOCKING findings. De to NON-BLOCKING noter ovenfor (loft-teksten og navnet "Succes-leads") accepteres med de givne begrundelser.

Relevante filer på branchen:
- `homedir/src/UiCalls.php`
- `homedir/src/Ui.php`
- `homedir/src/App.php` (`callSelect`)
- `homedir/src/CallPages.php` (`select`, `selectable`)
- `homedir/src/Calls.php` (`sourceSql`, `adversusWarning`)
- `homedir/public_html/assets/app.js`
- `homedir/public_html/assets/app.css`
