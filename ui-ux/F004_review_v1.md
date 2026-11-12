Branch: feature/F004-adversus @ c2f001dd0a4b9b95241395e520687d3221ad2e66

# F004, review v1
# Status: CHANGES_REQUIRED

Grundlag: `ui-ux/F004_design_v1.md`, Team Leads tolkning 1–8 i `fullstack-ai/F004_v1.md` (på branchen) og `graphic-design/F004_visual_v1.md`. Læst som committet med `git diff origin/main...origin/feature/F004-adversus` og `git show`: `UiCalls.php`, `Ui.php`, `CallPages.php`, `CallView.php`, `App.php`, `Calls.php`, `app.css` og `app.js`.

## Samlet vurdering
Implementationen følger briefet tæt og overholder Team Leads afgørelser. Jeg fandt ét problem, der skal rettes før merge. Det er en navnekonflikt, som F004 selv skaber, og den rammer den mest almindelige vej gennem appen. Resten er NON-BLOCKING.

**Det, der matcher briefet:**
- **Flaget slukket (AK9):**
  - `$adversus` er null, så UiCalls bruger den gamle vej.
  - `Calls::filter` giver `vis = ''`, og derfor er `CallView::query` uændret.
  - `sourceSql` begrænser til Zendesk.
  - Meta-rækken "Kilde" vises kun, når `Feature::on()` er sand.
  - Ruten `/transcriber` findes ikke.
- **Segmentvælger:**
  - Den står mellem filterkortet og chips.
  - Den har `role="group"` med `aria-labelledby="vis-label"` og den synlige label "Vis:".
  - Elementerne er links og ikke radioknapper.
  - Det aktive valg har `aria-current="true"` og fed tekst ud over farven.
  - Begge links bevarer filtrene og nulstiller `side`.
  - Standardvisningen skrives ikke i URL'en.
- **`vis` bæres med alle steder:**
  - det skjulte felt i formularen;
  - "Nulstil filtre" i kortet og i tomtilstanden (`/` eller `/?vis=alle`);
  - chips, hurtigvalg, pager, detaljelinks, ctx på POST og redirecten;
  - zip og zip-sidens "Tilbage".

  Der vises ingen chip for `vis`.
- **Kolonnen Kilde:**
  - Den står som nr. 2, og snippet-rækken har `colspan` 8.
  - Caption er "Opkald – succes-leads" eller "Opkald – alle".
  - "Succes-lead" vises kun under "Alle opkald" (tolkning 2). Den vises altid i detaljens hoved ved badget og i meta som "Lead: #id · Succes-lead".
- **Statusbadge "Ikke valgt":** `Ui::status` afgør den før lydstatus. Den er hul og neutral efter visual-briefet. Opkald med "ikke valgt" tælles ikke med i "venter på lyd".
- **Detaljen for Adversus:**
  - Undertitlen er "· Adversus" uden ticket, og et tomt telefonnummer udelades.
  - Meta-rækkerne er Kilde, Kampagne og Lead i den rigtige rækkefølge, og rækker uden værdi udelades.
  - Teksterne "Venter på lyd" og "Lyd fejlet" bruger kildenavnet.
  - Ticket-linjen "Optagelsen kan måske afspilles direkte i ticket …" er fjernet for Adversus.
- **Knappen:**
  - Den vises kun på detaljen ved "ikke valgt" og renderes aldrig disabled.
  - Den er en almindelig POST-formular med CSRF og ctx.
  - `aria-describedby` peger på hjælpeteksten.
  - Svaret er PRG med tre kvitteringer (ok, allerede i kø, allerede færdig) med teksterne ordret fra §9.
  - Et ukendt opkald eller et Zendesk-opkald giver 404 "Opkaldet findes ikke.".
- **JS:**
  - `data-busy` skifter teksten til "Sætter i kø …".
  - Knappen får `aria-disabled` og `disabled` efter submit via `setTimeout`, så formularen stadig sendes.
  - `#live` får "Sætter opkaldet i kø.".
  - Uden JS virker formularen stadig, så CSP overholdes.
- **Tomtilstande:** alle tre varianter har copy og handlinger som i §5. Det gælder også "Vis alle opkald" og "Søg i alle opkald".
- **Status i `.sync` (tolkning 5):**
  - Der er en Adversus-linje med samme prik-regler. Den vises kun, når `[adversus]` er sat op.
  - Opsætningsbesked og teknisk fejl vises kun for administratorer.
- **Responsivt:**
  - Under 640px stables vælgeren med "Vis:" øverst, og hvert valg har `flex: 1` og 44px.
  - Kilde er en `data-label`-række i mobilkortet.
  - Knappen er mindst 44px høj.
- **Ikoner:** trofæet og alle andre har `aria-hidden="true"`. Teksten "Succes-lead" bærer betydningen alene.

# Findings

## Finding 1
Severity: BLOCKING
Element: Detaljesiden, tilbage-linket når listens kontekst er tom (`UiCalls::show`, `$back`)
Expected: Briefet §6.1 siger "← Tilbage til opkald", og linket bevarer `vis` og filtrene. Linkets tekst må ikke modsige segmentvælgerens navne.
Actual: Når `$ctx === ''`, er linkteksten "Alle opkald", og linket går til `/`. Med F004 tændt viser `/` "Succes-leads", mens "Alle opkald" nu er navnet på den anden visning. Brugeren kommer fra standardvisningen uden filtre, som er den daglige vej. Han klikker "Alle opkald" og lander i "Succes-leads". Det samme sker ved direkte links til et opkald.
Fix: Når `Feature::on()` er sand, skal linkteksten være "Tilbage til opkald" (eller "Til opkald"), også ved tom ctx. Med flaget slukket skal teksten forblive "Alle opkald" (AK9).

## Finding 2
Severity: NON-BLOCKING
Element: Info-kvitteringerne "Opkaldet er allerede i kø." og "Opkaldet er allerede transcriberet." på detaljen
Expected: §10 siger, at flash efter redirect bruger den eksisterende `role="status"`.
Actual: `Ui::msg('info', …)` sætter ingen role. Kun ok-kvitteringen har `role="status"`.
Fix: Render info-kvitteringen med `role="status"`, fx et valgfrit role-argument til `Ui::msg`, eller en lokal wrapper i `UiCalls::show`.

## Finding 3
Severity: NON-BLOCKING
Element: Knappen "Transcribér dette opkald" efter browserens tilbage-knap (bfcache)
Expected: §6.4 siger, at tilbage viser siden fra før klikket. Et nyt klik giver "Opkaldet er allerede i kø."
Actual: Hvis browseren genskaber siden fra bfcache, står knappen stadig som disabled med "Sætter i kø …" og kan ikke klikkes. Det er ufarligt, men det ser ud som om den hænger.
Fix: Gendan label, `disabled` og `aria-disabled` på `pageshow` med `event.persisted`.

## Finding 4
Severity: NON-BLOCKING
Element: Knappens bredde på smal skærm
Expected: §8 siger, at knappen er fuld bredde og mindst 44px under 480px.
Actual: Knappen er 44px under 640px, men fuld bredde først under 400px (den eksisterende regel `form.actions > *`).
Fix: Kan accepteres som det eksisterende mønster for `.actions`. Vil man følge briefet præcist, får `.select-call button` `width: 100%` under 480px.

## Finding 5
Severity: NON-BLOCKING
Element: Succes-lead-mærket i mobilkortet
Expected: §8 siger, at mærket står i kortets første linje ved status.
Actual: Mærket står i Kilde-rækken, som er nr. 2 lige efter Dato og tid.
Fix: Ingen. Tolkning 2 begrænser mærket til "Alle opkald", og række 2 ses uden at scrolle. Jeg accepterer det med denne begrundelse.

## Afvigelser, som Team Lead spurgte til
- **Kampagnen vises kun som id ("#31"): acceptabel.** Spec'en kræver ikke navnet, og et ekstra endpoint ville være scope creep. Rækken udelades, når værdien mangler. Kravet i §6.5 om ellipsis og `title` bliver dermed irrelevant. Id'et siger dog ikke brugeren meget. Jeg anbefaler kampagnenavnet som et senere ID, hvis ejeren efterspørger det.
- **Ukendt retning springes over: acceptabel.** `direction` er en NOT NULL ENUM, og swagger tillader kun inbound og outbound. Sync tæller disse opkald i loglinjen, så de ikke forsvinder ubemærket for driften. Rækken "Retning: Ukendt" i §3.4 udgår. Til gengæld får listen ingen nye "—"-værdier, og retningsfiltret forbliver entydigt.
- **Telefonnummeret gemmes og vises som for Zendesk: acceptabel fra UX-siden.** Spec'en siger, at det gemmes, hvis Zendesk gemmer det, og briefets antagelse om "gemmes ikke" var forkert. UI'et håndterer begge tilfælde korrekt: "—" i listen og udeladt række og undertitel på detaljen, når feltet er tomt. Om nummeret skal gemmes, er et spørgsmål om dataminimering og hører til security-critic og Team Lead, ikke UX.
- **Agent uden navn hedder "Adversus-bruger <id>" i stedet for "Bruger #id" (Team Lead): acceptabel.** Den er klar og konsistent i liste, detalje og agentfilter.

## Til Team Lead
- Tolkning 1 bruger en neutral `.tag` som kilde i stedet for ren tekst. Det følger visual-briefet og er acceptabelt.
- Finding 1 er en rettelse på én linje. Når den er lavet, kan reviewet godkendes uden en ny fuld gennemgang. Findings 2–4 kan tages med i samme commit eller accepteres med begrundelserne ovenfor.
- Relevante filer på branchen: `homedir/src/UiCalls.php` (`show()`, `$back`), `homedir/src/Ui.php` (`msg()`), `homedir/public_html/assets/app.js` (afsnit 4) og `homedir/public_html/assets/app.css` (afsnit 9b, 9c og 15).
