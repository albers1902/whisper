# F007, design v1: Zendesk og Adversus hver for sig
# Status: READY_FOR_IMPLEMENTATION

Kilder:
- `product-ai/F007_v1.md` (kun i hovedmappen)
- `ui-ux/F005_design_v1.md` (D1–D7), `ui-ux/F004_design_v1.md` (D-F4-1 til D-F4-5) og `ui-ux/F004_review_v2.md`
- `graphic-design/F005_visual_v1.md` og `graphic-design/F004_visual_v1.md`
- `homedir/src/UiCalls.php`, `Ui.php`, `Calls.php` (`filter`, `sourceSql`, `status`, `agents`, `adversusWarning`), `CallView::query` og `public_html/assets/app.css`
- `tasks/impact-F007.md`

`DESIGN_LOG.md` og `PRD.md` findes ikke, så F005- og F004-briefene er den gældende ramme. Der er én afvigelse fra F005, og den er markeret i 2.3 og i afsnit 11.

## 0. Rammer (bindende)
- **Flaget `F007_kilder` slukket (AK5):** liste, URL'er, kolonner, beskeder og detalje er byte for byte som i dag (F004/F005). Det gælder også Kilde-kolonnen, den samlede `.sync` og "Vis:"-vælgeren for begge kilder.
- **F007 forudsætter `F004_adversus`.** Er F004 slukket, er der kun Zendesk-opkald. Så renderes listen som i dag uden faner, også selvom F007 er tændt.
- **CSP og progressive enhancement (D1):** fanerne er almindelige `<a href>`. Der er ingen JS, ingen `role="tablist"` og ingen auto-submit.
- **Alt ligger i URL'en (D2, D-F4-1):** ingen cookie og ingen session.
- **Kun det nye UI:** der bygges i `UiCalls` og `Ui`, intet i `CallView` ud over `query()`.
- **Visuelt:** F005-tokens. Der kommer ingen nye farver. Nye komponenter er flaget til graphic-design i afsnit 2.

## 1. Page/screen layout

### 1.1 Opkaldslisten `/` med flaget tændt
```
Opkald                                         3 venter på transcription · 2 venter på lyd
                                               ● Transcription er ikke sat op endnu          ← kun når motoren ikke er klar
┌──────────────┬───────────────┐
│ Zendesk 1.234│ Adversus  456 ⚠│                     ● Hentet fra Adversus for 4 min. siden
┴══════════════┴───────────────┴──────────────────────────────────────────────────────────
⚠ Nogle opkald kunne ikke hentes.  ▸ Teknisk besked        ← kun admin, kun på Adversus-fanen
┌ Søg i transcripts ───────────────────────────────────────────────────────────────┐
│ (uændret filterkort; skjulte felter kilde=adversus og vis=alle efter behov)       │
└──────────────────────────────────────────────────────────────────────────────────┘
Vis:  [ Succes og valgte ]  [ Alle opkald ]        ← KUN på Adversus-fanen
Aktive filtre: [Søgning: faktura ×] …
Viser 1–50 af 456 opkald                       [⤓ Download færdige transcripts (.zip)]
Tabel (kolonnesæt efter fane, afsnit 3)
                          ← Forrige   Side 2 af 10   Næste →
```
Rækkefølge fra top til bund:
1. **`.page-head`:** H1 "Opkald" til venstre. Til højre står den **fælles** linje, som afløser den nuværende `.sync` med flaget tændt:
   - kø-linjen "N venter på transcription · N venter på lyd" (kun tal over 0, som i dag);
   - motorlinjen, når transcription ikke er klar (afsnit 2.3).

   Er begge tomme, står der intet til højre.
2. **Flash** (`Ui::message`), uændret og sidebredt.
3. **`.source-bar`:** én række med fanerne til venstre og **kildens egen statuslinje** til højre på samme grundlinje. Under rækken går en 1px `--border`-linje i fuld bredde. Den aktive fane "sidder" på linjen.
4. **Kildens beskeder** (afsnit 2.2), kun for den aktive fane, med højst én boks pr. situation.
5. Filterkortet, `.view-switch` (kun Adversus), chips, `.results-bar`, tip, tabel og pager. Indhold og rækkefølge er som F004/F005.

Info-beskeden "Til-datoen er før fra-datoen." står i punkt 4 under kildens egne beskeder.

### 1.2 Detaljesiden `/opkald/{id}`
Detaljesiden er uændret ud over tilbage-linket (afsnit 5). Felterne følger kilden som i F004. Meta-rækken "Kilde" bliver stående.

## 2. Components

| Komponent | Ny/eksisterende | Konfiguration |
|---|---|---|
| `.source-bar` | **NY (graphic-design)** | Flex-række med `nav.source-tabs` til venstre og `p.sync-line` til højre, `flex-wrap: wrap`, `border-bottom: 1px solid var(--border)` og `margin-bottom: 16px`. |
| `nav.source-tabs` (faner) | **NY (graphic-design)** | `<nav class="source-tabs" aria-label="Kilde"><ul><li><a href="…" aria-current="page">Zendesk <span class="tab-count">1.234</span></a></li>…</ul></nav>`. Altid to faner i fast rækkefølge: Zendesk og Adversus. |
| `.tab-count` | **NY (graphic-design)** | Antallet med tusindtalspunktum ("1.234") og tabulære tal. Det står efter navnet. Det må ikke ligne et statusbadge, så der er ingen prik og ingen statusfarve. |
| Fane-markør for problem | **NY (graphic-design), BØR** | Ikonet `circle-alert` (sync stoppet) eller `triangle-alert` (advarsel) i 14px efter tallet, `aria-hidden`, plus skjult tekst (afsnit 9). Det er genbrug af eksisterende ikoner, så ikonsættet vokser ikke. |
| `p.sync-line` med `.dot` | EKSISTERENDE | `UiCalls::sourceLine` for den aktive kilde. Reglerne er uændrede (grøn, gul, rød, rate limit og "Endnu ikke hentet"). |
| `.sync` / `.sync-queue` | EKSISTERENDE | Står nu kun med fælles kø- og motorlinjer i `.page-head`. |
| Motorlinje "Transcription er ikke sat op" | NY brug af eksisterende `.sync-line` + `.dot-warn` | Afløser den blå `.msg.info`-boks med flaget tændt (2.3). |
| `.msg` info/warn/error med `<details>` | EKSISTERENDE | Kun for den aktive kilde (2.2). |
| `.view-switch` / `.segmented` | EKSISTERENDE | Kun på Adversus-fanen. Links bærer `kilde=adversus`. |
| Kolonnerne Kampagne og Lead | NY i listen (findes i `dl.meta`) | Afsnit 3. |
| `.tag-success` "Succes-lead" | EKSISTERENDE | Står nu i Lead-cellen (afsnit 3). |
| Skjult felt `kilde` | NY | Står i filterformularen, kun når `kilde=adversus`. |
| `.empty` | EKSISTERENDE | Nye tekster pr. fane (afsnit 6). |

**Til graphic-design:** fanerne er niveau 1 (kilde) og `.segmented` er niveau 2 (visning inden for Adversus). De må ikke ligne hinanden.
- Mit forslag er klassiske faner med understregning. Aktiv fane: `--text`, vægt 600 og en 2px `--accent`-streg i bunden, der dækker `.source-bar`'s linje. Inaktiv: `--text-muted` med hover `--surface-2`.
- Aktiv må ikke vises kun med farve (vægt og streg).
- Fanen skal være mindst 40px høj på desktop og 44px på touch.
- Mål, farver i mørkt tema og kontrast for `.tab-count` og markøren afgøres i et `graphic-design/F007_visual_v1.md`.

### 2.1 Fælles linjer øverst (begge faner)
- Kø-linjen er uændret: "3 venter på transcription · 2 venter på lyd". Køen er fælles, så tallene er for begge kilder (som `Calls::status()` i dag).
- Motorlinjen står under kø-linjen (2.3).

### 2.2 Kildens statuslinje og beskeder (kun den aktive fane)
Statuslinjen står i `.source-bar` til højre for fanerne:
- **Zendesk-fanen:** `sourceLine('Zendesk', …)`, fx "● Hentet fra Zendesk for 4 min. siden".
- **Adversus-fanen:** `sourceLine('Adversus', …)`, fx "● Hentet fra Adversus for 4 min. siden". Er Adversus ikke sat op (`config ≠ ok`), er linjen "● Adversus er ikke sat op" med grå prik (`.dot` uden modifier). Det gælder for alle roller.

Beskederne står under `.source-bar` som `.msg`:

| Situation | Fane | Admin ser | Bruger ser |
|---|---|---|---|
| Zendesk ikke sat op | Zendesk | info (F005-tekst) | info (F005-tekst) |
| Zendesk-config ugyldig | Zendesk | fejl (F005) | info (F005) |
| Zendesk sync stoppet | Zendesk | fejl "Seneste hentning fra Zendesk stoppede." + "Teknisk besked" | kun rød prik i statuslinjen |
| Adversus ikke sat op | Adversus | info "Adversus er ikke sat op. Opkald hentes, når `[adversus]` er udfyldt i config.ini." | **NY** info "Opkald hentes ikke fra Adversus endnu. Kontakt en administrator, hvis du savner opkald." |
| Adversus-config ugyldig | Adversus | fejl (F004) | samme info som rækken over (NY, symmetrisk med Zendesk) |
| Adversus sync stoppet | Adversus | fejl "Seneste hentning fra Adversus stoppede." + "Teknisk besked" | kun rød prik |
| Adversus-advarsel (forkastede eller manglende opkald, H009) | Adversus | warn "**Nogle opkald kunne ikke hentes.**" + "Teknisk besked" | intet |
| Rate limit (begge) | egen fane | kun statuslinjen | kun statuslinjen |

Sådan undgår vi, at beskederne dominerer:
1. **En kildes beskeder står kun på dens egen fane (AK4).** På Zendesk-fanen er der derfor ingen Adversus-boks.
2. **Motorboksen bliver til en linje** (2.3). Den blå boks forsvinder dermed fra begge faner.
3. **Advarslen ryddes, når genopretningen har kørt (AK6, backend).** Den forsvinder i forvejen efter `WARN_TTL`.
4. **"Teknisk besked" er altid lukket `<details>`,** så hver boks fylder én linje plus summary.
5. **Ordet "Adversus-" udgår i advarslen,** fordi fanen allerede siger kilden (spec'ens tekst). Fejl- og opsætningstekster beholder kildenavnet, fordi de peger på en config-sektion.
6. **Markøren på den inaktive fane (BØR)** gør, at en admin ikke overser et problem i den anden kilde, selvom boksen ikke står på hans fane:
   - `circle-alert`, når den kilde har `last_error` (ikke rate limit). Markøren vises for alle roller, fordi den røde prik også er synlig for alle.
   - `triangle-alert`, når der er en Adversus-advarsel. Den vises kun for admin, som boksen.
   - Den aktive fane får ingen markør, fordi boksen står lige under den.

### 2.3 Motorlinjen (AFVIGELSE FRA F005 §3.3, kun med flaget tændt)
F005 viser "motor ikke klar" som en `.msg.info`-boks. Med F007 bliver den en linje i den fælles `.sync` under kø-linjen med `.dot-warn`:
- **Admin:** "Transcription er ikke sat op endnu (kør cron/whisper-setup.sh)."
- **Bruger:** "Nye opkald bliver ikke transcriberet lige nu. Lyden kan stadig afspilles."

Begrundelsen er ejerens skærmbillede: den fælles besked fyldte på begge faner. Spec'en kalder den selv en "linje om transcription-kø og -status". Teksterne er uændrede. Kun formen ændres.

## 3. Kolonnesæt pr. fane

**Zendesk-fanen (7 kolonner, snippet `colspan="7"`):**
Dato og tid · Retning · Kunde · Agent · Varighed · Ticket · Status
- Det er præcis F005's tabel, og Kilde-kolonnen udgår.

**Adversus-fanen (8 kolonner, snippet `colspan="8"`):**
Dato og tid · Retning · Kunde · Agent · Varighed · Kampagne · Lead · Status
- **Kampagne:** "#123" med tabulære tal og venstrestillet. Uden værdi "—". Der vises ikke noget kampagnenavn, fordi det ikke findes (som i F004-meta).
- **Lead:** "#12345" og, når `is_success = 1`, `.tag-success` "Succes-lead" efter tallet (`.tags`-wrapper, gap 6px). Uden lead "—".
  - Mærket vises i **begge** visninger, altså også "Succes og valgte". Det løser NON-BLOCKING-noten i `F004_review_v2.md`: manuelt valgte opkald står nu uden mærke og kan skelnes fra succes-leads.
- **Kunde:** "—", når telefonnummeret mangler (F004 §3.4). **Retning:** "—" ved ukendt retning. **Agent:** "Bruger #123" uden navn.
- Ticket-kolonnen findes ikke.

**Fælles for begge:**
- Varighed er højrestillet `.num`.
- Status er badge (inkl. "Ikke valgt" på Adversus).
- Hele rækken er klikbar (stretched link). Ticket-linket har fortsat `z-index: 1`.
- `<caption class="sr-only">`: "Zendesk-opkald", "Adversus-opkald – succes og valgte" eller "Adversus-opkald – alle".
- `data-label` på hver `td` til mobilkort, også "Kampagne" og "Lead".

**Agentfilteret** viser kun agenter fra den aktive kilde (`Calls::agents()` får kilden). Ellers kan man vælge en agent, der giver 0 træf.

## 4. "Succes og valgte / Alle opkald" (kun Adversus)
- Vælgeren renderes kun, når `kilde=adversus`, og står samme sted som i F004: under filterkortet, før chips. Labels: "Vis:", "Succes og valgte" og "Alle opkald".
- Begge links bærer `kilde=adversus` og filtrene og nulstiller `side` (F004).
- **På Zendesk-fanen findes `vis` ikke:**
  - der er ingen vælger og ingen skjult `vis`-felt;
  - `Calls::filter()` sætter `vis = ''`, når kilden er Zendesk;
  - Zendesk-links bærer aldrig `vis`;
  - `sourceSql` for Zendesk-fanen er kun `source = 'zendesk'`.
- **Fanen "Adversus" fra Zendesk** fører til standardvisningen "Succes og valgte". Valget "Alle opkald" huskes ikke på tværs af faneskift, fordi der kun er URL og ingen session (D-F4-1).
- **Gamle links (F004-bogmærker):** `vis=alle` uden `kilde` giver Adversus-fanen med "Alle opkald", fordi `vis` kun giver mening dér.
- Visningen er stadig ikke et filter: ingen chip, og "Nulstil filtre" bevarer både `kilde` og `vis`.

## 5. URL, tilbage-link og bevarelse af fane og filtre
- **Parameter:** `kilde`. Værdien `adversus` giver Adversus-fanen. Udeladt, `zendesk` eller ukendt værdi giver Zendesk.
  - Standarden skrives ikke i genererede links (D-F4-1), så `/` er Zendesk, og hovedmenuens "Opkald" er uændret.
  - Valideres som fast værdi (impact-risiko).
- **Standardfane:** spec'ens "sidst valgte" tolkes som "den i URL'en", ellers Zendesk. Der er ingen cookie (se afsnit 12).
- **`kilde` bæres i** detaljelinks (ctx), pager, chips, hurtigvalg, "Nulstil filtre" (kort og tomtilstand), zip-linket, `.view-switch`, skjult felt i filterformularen og POST-redirect fra "Transcribér dette opkald" (`ctx`).
  - `CallView::query()` får `kilde` med (kun ved `adversus`).
- **Fanelinks:**
  - De bærer `fra`, `til`, `retning` og `q`, så en søgning kan sammenlignes mellem kilderne med ét klik.
  - De dropper `agent` (agentlisterne er forskellige), `side` (altid side 1) og `vis` (kun Adversus, se 4).
- **Antallet på fanen er antallet, man får ved at klikke på den.**
  - Aktiv fane: samme tal som "Viser … af N".
  - Inaktiv fane: COUNT med fanelinkets parametre. Adversus tælles i standardvisningen, eller i den aktuelle visning, hvis man står på Adversus.
  - Tallet vises med tusindtalspunktum. Ved 0 vises "0", og fanen er stadig klikbar.
- **Tilbage-link på detaljen:**
  - Fanen tages fra **opkaldets egen kilde**, ikke fra ctx: `/?kilde=adversus&<ctx>` for Adversus og `/?<ctx>` for Zendesk. Så rammer linket altid rigtigt, også når man kommer fra et gammelt link, MCP eller en manipuleret URL.
  - For Zendesk-opkald fjernes et eventuelt `vis`.
  - Teksten er uændret: "Tilbage til opkald" (spec), med `chevron-left`.
- **Forrige/næste opkald** fra detaljen (F005 KAN, hvis det findes) holder sig inden for fanen.
- **Browserens tilbage-knap og genindlæsning** er ufarlige, fordi alt er GET med tilstand i URL'en.
- **`<title>`:** "Opkald · Zendesk" eller "Opkald · Adversus", så historik og faneblade i browseren kan skelnes (WCAG 2.4.2). H1 forbliver "Opkald".

## 6. States

### Listen pr. fane
- **Standard med data:** layout fra 1.1 med fanens kolonner.
- **Loading:** ingen, fordi siden renderes på serveren. "Transcriberer" pulserer som i dag.
- **Tom Zendesk uden filtre:** H2 "Ingen Zendesk-opkald endnu". Tekst "Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter." Ingen handling.
- **Tom Adversus, "Succes og valgte", uden filtre:** H2 "Ingen Adversus-opkald endnu". Tekst "Opkald på succes-leads og opkald, der er valgt til transcription, dukker op her inden for 10 minutter." Handling: sekundær knap "Vis alle opkald" (`/?kilde=adversus&vis=alle`).
- **Tom Adversus, "Alle opkald", uden filtre:** H2 "Ingen Adversus-opkald endnu". Tekst "Opkald med optagelse fra Adversus dukker op her inden for 10 minutter." Ingen handling.
- **Ikke sat op:** tomtilstanden ovenfor plus info-boksen fra 2.2. Teksterne må gerne stå begge, fordi boksen forklarer hvorfor.
- **Tom med filtre (begge faner):** H2 "Ingen opkald matcher". Tekst "Prøv en længere periode, færre søgeord eller en anden agent." Handling: "Nulstil filtre" (bevarer `kilde` og `vis`).
  - På Adversus "Succes og valgte" kommer desuden "Søg i alle opkald" (F004).
  - **BØR:** har den anden fane træf (dens tal er over 0), kommer en ekstra linje: "Søgningen giver N træf under Adversus." eller "… under Zendesk." med linket "Se dem" til den fane.
- **Fejl:** beskederne i 2.2 vises kun på egen fane, og markøren står på den anden fane.
- **Delvise data:** som i afsnit 3 ("—" og "Bruger #id").
- **Disabled:** inaktive faner er aldrig disabled.
- **Pager og zip:** som F005, men pr. fane. Zip hedder det samme, og indholdet er kun den aktive fanes færdige transcripts i filtret.

### Kanttilfælde
- **1000+ opkald:** pager på 50 og zip-loft på 2000 er uændrede pr. fane. Tallet på fanen kan blive langt ("12.345"), men fanen vokser bare.
- **Lange agentnavne:** ellipsis + `title` (uændret).
- **`side` større end sidste side efter faneskift:** kan ikke ske, fordi fanelinks altid går til side 1.

## 7. User flow
1. **Zendesk-hverdag:** `/` viser Zendesk-fanen → søg → klik på række → detalje → "Tilbage til opkald" → samme fane, filtre og side.
2. **Adversus-hverdag:** klik på "Adversus" (eller bogmærke `/?kilde=adversus`) → "Succes og valgte" → række → detalje → tilbage til Adversus med samme visning og filtre.
3. **Manuel transcription:** Adversus → "Alle opkald" → "Ikke valgt"-række → detalje → "Transcribér dette opkald" → PRG tilbage til detaljen (ctx med `kilde` og `vis`) → tilbage til listen, stadig "Alle opkald".
4. **Søg på tværs:** søg "faktura" på Zendesk → fanen Adversus viser "37" → klik → samme søgning og periode i Adversus, agentfilteret er nulstillet.
5. **Admin ser problem:** står på Zendesk, Adversus-fanen har markøren → klik → statuslinjen er rød og boksen "Seneste hentning fra Adversus stoppede." med "Teknisk besked".
6. **Fejlvej:** ugyldig `kilde` giver Zendesk uden fejl. Ugyldigt CSRF på "Transcribér" følger det eksisterende mønster.

## 8. Form design
Filterformularen er uændret bortset fra:
- skjult `<input type="hidden" name="kilde" value="adversus">` på Adversus-fanen og skjult `vis` som i F004;
- agentlisten pr. kilde.

Der er ingen nye felter og ingen ny validering.

## 9. Responsive behavior
- **Under 640px:**
  - `.page-head` stables (uændret), og de fælles linjer er venstrestillet.
  - `.source-bar` ombrydes. Fanerne står i fuld bredde med to lige brede halvdele (`flex: 1`), mindst 44px høje, og tal og markør står på samme linje som navnet. Statuslinjen står under fanerne, venstrestillet, før beskederne.
- **Mobilkort (F005 §3.9):**
  - Zendesk-kort: som i dag uden Kilde.
  - Adversus-kort: rækkerne Kampagne og Lead med `data-label`. "Succes-lead" står i Lead-rækken.
- **641–1000px:** Adversus-tabellen har 8 kolonner. Kunde er ofte "—" og smal, og Agent afkortes med ellipsis. Vandret scroll i `.table-wrap` er acceptabelt som sidste udvej (som i dag).
- **Swipe:** ingen.

## 10. Copy / microcopy (dansk; appen har ingen sprogfil, F005 §5)
| Sted | Tekst |
|---|---|
| Fanernes `nav` (aria-label) | Kilde |
| Faner | Zendesk · Adversus |
| Fane, skjult tekst efter tallet | "opkald" (fx "Adversus 456 opkald") |
| Fane-markør, skjult | ", hentningen er stoppet" / ", har en advarsel" |
| `<title>` | Opkald · Zendesk / Opkald · Adversus |
| Statuslinje | uændret `sourceLine` (fx "Hentet fra Adversus for 4 min. siden") |
| Statuslinje, Adversus ikke sat op | Adversus er ikke sat op |
| Motorlinje, admin | Transcription er ikke sat op endnu (kør cron/whisper-setup.sh). |
| Motorlinje, bruger | Nye opkald bliver ikke transcriberet lige nu. Lyden kan stadig afspilles. |
| Adversus-advarsel | Nogle opkald kunne ikke hentes. + "Teknisk besked" |
| Adversus ikke sat op, bruger | Opkald hentes ikke fra Adversus endnu. Kontakt en administrator, hvis du savner opkald. |
| Vælger | Vis: · Succes og valgte · Alle opkald |
| Kolonner, Adversus | Kampagne · Lead |
| Mærke | Succes-lead |
| Captions | Zendesk-opkald / Adversus-opkald – succes og valgte / Adversus-opkald – alle |
| Tom, Zendesk | Ingen Zendesk-opkald endnu / Opkald med optagelse fra Zendesk Talk dukker op her inden for 10 minutter. |
| Tom, Adversus standard | Ingen Adversus-opkald endnu / Opkald på succes-leads og opkald, der er valgt til transcription, dukker op her inden for 10 minutter. / [Vis alle opkald] |
| Tom, Adversus alle | Ingen Adversus-opkald endnu / Opkald med optagelse fra Adversus dukker op her inden for 10 minutter. |
| Tom med filtre | Ingen opkald matcher / Prøv en længere periode, færre søgeord eller en anden agent. / [Nulstil filtre] (+ [Søg i alle opkald] på Adversus standard) |
| Træf i anden fane (BØR) | Søgningen giver N træf under Adversus. Se dem / Søgningen giver N træf under Zendesk. Se dem |
| Tilbage-link | Tilbage til opkald |

## 11. Accessibility (WCAG 2.2 AA)
- **Fokusrækkefølge:** skip-link → hovedmenu → brugermenu → H1 → fanerne (Zendesk, Adversus) → beskedernes "Teknisk besked" → filterkort (søgning først) → `.view-switch` (Adversus) → chips → zip → tabel → pager. Statuslinjerne er tekst og er ikke fokuserbare.
- **Faner er links, ikke ARIA-tabs:**
  - `<nav aria-label="Kilde">` med `<ul>` og `<a>`, og `aria-current="page"` på den aktive.
  - **Ingen** `role="tab"`, `tablist` eller `tabpanel` og ingen piletaster. ARIA-tabs lover skift i siden med piletaster. Her loader et klik en ny URL, og en skærmlæser skal annoncere det som et link.
  - Tastatur: Tab og Shift+Tab mellem fanerne og Enter for at åbne.
  - Den aktive fane er også et link (til sig selv), så rækkefølgen er stabil.
- **Tal og markør er en del af linkets navn:** fx "Adversus 456 opkald, har en advarsel". Tallet står i synlig tekst. "opkald" og markørteksten er `.sr-only`, og ikonet er `aria-hidden`.
- **Aktiv fane** markeres med vægt og streg, ikke kun farve. Kontrastkrav: fanetekst ≥ 4.5:1 og aktiv streg ≥ 3:1 mod baggrunden (graphic-design regner efter).
- **Statusprikker** er `aria-hidden`, og teksten bærer betydningen (uændret).
- **Mål:** fanerne er mindst 24×24 på desktop (anbefalet 40px høje) og 44px på touch.
- **`<title>`** skifter med fanen (2.4.2). Caption angiver kilde og visning.
- **Ingen tilstand vises kun med farve.** Ikonmarkøren har skjult tekst.

## 12. Design decisions
- **D-F7-1 Faner som links med `kilde` i URL'en, standard Zendesk, uden cookie.** Det følger D2 og D-F4-1: tilstanden kan bogmærkes og deles og virker uden JS. Standarden skrives ikke, så `/` og hovedmenuen er uændrede. Adversus-brugere kan bogmærke `/?kilde=adversus`.
- **D-F7-2 Links med `aria-current="page"` frem for ARIA-tabs.** Semantikken svarer til adfærden (ny side pr. klik). Det samme gælder hovedmenuen.
- **D-F7-3 Kildens statuslinje på fanerækken og fælles linjer i sidehovedet.** Placeringen viser, hvad der gælder kilden, og hvad der er fælles, uden at bruge en ekstra lodret række.
- **D-F7-4 Beskeder kun på egen fane plus en markør på den anden.** Det holder siden rolig (ejerens skærmbillede) uden at skjule driftsproblemer for admin.
- **D-F7-5 Motorstatus som linje, ikke boks (AFVIGELSE FRA F005 §3.3, kun med flaget tændt).** Teksten er den samme, og det er formen, der fyldte.
- **D-F7-6 Faneantal = det, klikket giver.** Tallet skal kunne stoles på. Et ufiltreret tal ved siden af en filtreret liste ville vildlede.
- **D-F7-7 Fanelinks bevarer periode, retning og søgning, men ikke agent og visning.** Det gør sammenligning på tværs nem uden at give et falsk 0 på grund af en agent, der ikke findes i den anden kilde.
- **D-F7-8 Succes-lead-mærket i Lead-kolonnen i begge Adversus-visninger.** Kolonnen giver mærket en fast plads, og forskellen på succes-leads og manuelt valgte bliver synlig (F004 review v2-note).
- **D-F7-9 Tilbage-linkets fane kommer fra opkaldets kilde.** Det er robust mod gamle links, MCP-links og manipuleret ctx.

## Nye design-beslutninger i dette brief

### Kilde-faner (`nav.source-tabs`)
- **Hvad:** faner på øverste niveau, som skifter datakilde. De er links med `aria-current="page"`, har antal på fanen og har en valgfri problem-markør.
- **Hvorfor:** forskellige kilder har forskellige felter, status og visninger og skal ikke blandes.
- **Gælder for:** alle fremtidige kilder og lister med samme opdeling.
- **Anbefaling:** Vedtag som standard? JA

### Status og beskeder hører til deres kilde
- **Hvad:** en kildes statuslinje og systembeskeder vises kun i dens egen kontekst. Andre kontekster får højst en markør.
- **Hvorfor:** fælles bokse fylder for alle og bliver overset (ejeren 2026-11-12).
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### Niveauer for visningsvalg
- **Hvad:** faner er niveau 1 (hvad slags data), `.segmented` er niveau 2 (udsnit inden for data) og chips er filtre. De tre må ikke ligne hinanden.
- **Hvorfor:** brugeren skal kunne se, hvad der er valgt på hvilket niveau.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

## 13. Til Team Lead
1. **graphic-design skal lave `graphic-design/F007_visual_v1.md`** for `.source-bar`, `.source-tabs`, `.tab-count` og fane-markøren (lyst og mørkt tema, kontrast, 40px/44px, forskel til `.segmented`). Der er ingen nye ikoner og ingen nye farvetokens.
2. **Standardfane:** spec'ens "sidst valgte" er tolket som "den i URL'en" (ingen cookie, D-F4-1). Vil ejeren have, at Whisper husker fanen mellem besøg, kræver det en cookie og en ny beslutning.
3. **Antal på den inaktive fane** koster én ekstra COUNT pr. sidevisning, med FULLTEXT når `q` er sat. Er det for dyrt, er fallback: intet tal på den inaktive fane, mens `q` er sat. Tallet må aldrig være ufiltreret.
4. **Afvigelse fra F005 §3.3:** motorboksen bliver til en linje (D-F7-5), kun med flaget tændt.
5. **Gamle `/?vis=alle`-links** åbner Adversus-fanen (afsnit 4). Ret mig, hvis ejeren hellere vil have Zendesk.
6. **Kampagne** vises som "#id" (der er intet navn i data, som i F004-meta). Navne kræver et nyt ID.
7. **Fane-markøren og "træf i anden fane"** er BØR. Resten er SKAL.
8. **F007 forudsætter `F004_adversus`.** Uden den renderes listen som i dag (afsnit 0).
9. **H011 rører `UiCalls`.** Impact siger, at F007 bygges oven på H011, når den er merget.
10. **MCP er uden for briefet** (spec: ingen ændring).
