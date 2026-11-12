# F004, design v1 — Adversus-opkald i Whisper
# Status: READY_FOR_IMPLEMENTATION

Kilder:
- `product-ai/F004_v1.md` (afsnittet "Brugerflade", AK6, AK7, AK9)
- `ui-ux/F005_design_v1.md` (D1–D7) og `graphic-design/F005_visual_v1.md`
- `homedir/src/UiCalls.php`, `Ui.php`, `CallPages.php`, `CallView.php` og `Calls.php`
- `launched/STABLE/DECISIONS.md` #31

`DESIGN_LOG.md` og `PRD.md` findes ikke i repoet. Derfor er F005-briefets D1–D7 den gældende ramme. Briefet indeholder ingen AFVIGELSE FRA DESIGN_LOG.

## 0. Rammer (bindende)
- **Kun det nye UI.** F004's brugerflade bygges i `UiCalls`/`Ui` (F005_ui), og intet nyt bygges i `CallView`. Kombinationen F004 tændt og F005_ui slukket er beskrevet i afsnit 10.
- **Flaget slukket (AK9):** listen, detaljen, URL'erne, kolonnerne og teksterne er byte-for-byte som i dag.
- **CSP:** `default-src 'self'`. Det betyder ingen inline style, script eller `onclick`. Knappen er en almindelig `<form method="post">`. JS er kun en forbedring (D1).
- **Statistik i URL'en (D2):** den nye parameter `vis` bæres med på samme måde som `fra`, `til`, `retning`, `agent`, `q` og `side`. Det gælder detaljelinks, tilbage-linket, pageren, chips, hurtigvalg, zip og POST-redirect.
- **Visuelt:** tokens, badges og knapper fra F005-briefet. Der kommer ingen nye farver. Den eneste nye visuelle komponent er "Succes-lead"-mærket (3.3), og graphic-design/F004 fastlægger dets stil.

## 1. Page/screen layout

### 1.1 Opkaldslisten `/` (flaget tændt)
```
Opkald                                        ● Hentet fra Zendesk for 4 min. siden
                                                3 venter på transcription
┌ Søg i transcripts ──────────────────────────────────────────────────────────┐
│ (uændret: søgefelt, Fra/Til/Retning/Agent, hurtigvalg, Nulstil filtre)      │
│ <input type="hidden" name="vis" value="alle">  (kun når "Alle opkald")      │
└─────────────────────────────────────────────────────────────────────────────┘
Vis:  [ Succes-leads ]  [ Alle opkald ]          ← NY segmentvælger (links)
Aktive filtre: [Søgning: faktura ×] …
Viser 1–50 af 123 opkald                   [⤓ Download færdige transcripts (.zip)]
Tabel: Dato og tid · Kilde · Retning · Kunde · Agent · Varighed · Ticket · Status
                       ← Forrige   Side 2 af 3   Næste →
```
- **Segmentvælgeren** står mellem filterkortet og chips. Den er venstrestillet med 12px afstand til begge.
- **Kolonnen "Kilde"** (ny) står som nr. 2 lige efter Dato og tid. Snippet-rækken får `colspan="8"`.
- **Rækkefølge og indhold** er i øvrigt som i F005 §3.3.

### 1.2 Opkaldsdetalje `/opkald/{id}` for et Adversus-opkald
Layoutet er det samme som F005 §3.4: afspiller og metadata til venstre (sticky) og transcript til højre. Forskellene er:
- **Undertitel:** kunde (hvis den findes) · agent · varighed · "Adversus" · [Succes-lead]. Ticket vises ikke, fordi den ikke findes.
- **`dl.meta`:** rækkerne er Dato og tid, **Kilde**, Retning, Kunde, Agent, Varighed, **Kampagne**, **Lead**, Status.
  - Ticket-rækken udelades for Adversus.
  - Lead vises som "#12345" med "· Succes-lead" efter, når det er succes. Det er ren tekst uden link til Adversus (uden for scope).
- **"Ikke valgt"-opkald:** venstre kolonne har en info-boks i stedet for afspilleren. Højre kolonne har en handlingsboks med knappen (afsnit 4).
```
┌ Optagelse ─────────────────┐  ┌ Transcript ───────────────────────────────────┐
│ ⓘ Lyden er ikke hentet.     │  │ Opkaldet er ikke på et succes-lead og bliver  │
│   Opkaldet er ikke på et    │  │ derfor ikke transcriberet automatisk.         │
│   succes-lead.              │  │ [ Transcribér dette opkald ]  (primær)        │
│ dl.meta …                  │  │ Lyden hentes fra Adversus, og opkaldet sættes │
└────────────────────────────┘  │ i kø. Det tager normalt under 15 minutter.    │
                                └───────────────────────────────────────────────┘
```
Zendesk-opkald på detaljesiden er uændrede, bortset fra den nye meta-række "Kilde: Zendesk".

## 2. Components
| Komponent | Ny/eksisterende | Konfiguration |
|---|---|---|
| `.view-switch` segmentvælger | NY | `<div class="view-switch" role="group" aria-labelledby="vis-label">` med `<span id="vis-label" class="muted">Vis:</span>` og to `<a class="button secondary small">`. Det aktive link har `aria-current="true"` og samme aktiv-stil som nav (`--accent-subtle`/`--accent-hover`). Begge links bevarer de øvrige filtre og nulstiller `side`. |
| Skjult felt `vis` i filterformularen | NY | Renderes kun ved `vis=alle`, så "Søg" ikke falder tilbage til standardvisningen. |
| Kolonnen "Kilde" | NY | Teksten "Zendesk" eller "Adversus" i `--text`, plus `.tag-success` ved succes. |
| `.tag-success` "Succes-lead" | NY | Lille pille, 13px, som står ved siden af kilden. Den har ingen prik, så den ikke kan forveksles med statusbadges (D3). Et ✓-ikon med `aria-hidden` kan bruges. Teksten bærer betydningen. |
| Statusbadge `s-ikke-valgt` "Ikke valgt" | NY værdi i eksisterende system | Stilen er neutral som `s-ventende`. Den afgøres FØR lydstatus i `Ui::status`. |
| Formularen "Transcribér dette opkald" | NY | `<form method="post" action="/opkald/{id}/transcriber">` med CSRF-felt og skjult `ctx` (listens query), og `<button type="submit" class="button">` (primær). |
| `.msg info/error`, flash `role="status"` | EKSISTERENDE | Kvitteringer og fejl. |
| Tomtilstand `.empty` | EKSISTERENDE | Nye tekster (afsnit 7). |
| Meta-rækker Kilde/Kampagne/Lead | EKSISTERENDE `dl.meta` | Rækker uden værdi udelades på detaljen. I listen bruges "—". |

## 3. Visning og markering

### 3.1 Filtervælgeren (AK6)
- Parameternavnet er `vis`. Værdien `alle` giver "Alle opkald". Udeladt eller ukendt værdi giver "Succes-leads", som er standard.
- Standarden skrives aldrig i URL'en, så `/` forbliver standardvisningen.
- **"Succes-leads"** viser alle Zendesk-opkald og Adversus-opkald på succes-leads. **"Alle opkald"** viser også de øvrige Adversus-opkald.
- `vis` er en visning, ikke et filter:
  - den giver ingen chip og tæller ikke med i "Nulstil filtre";
  - "Nulstil filtre" (i kortet og i tomtilstanden) går til `/` ved standardvisningen og til `/?vis=alle` ved "Alle opkald". Den nulstiller altså filtrene og bevarer visningen.
- Valget huskes kun i URL'en. Der bruges ingen cookie og ingen session.

### 3.2 Kilde
Teksten i kolonnen "Kilde" er "Zendesk" eller "Adversus". Ved en ukendt kilde vises "—".

### 3.3 Succes-markering
`.tag-success` "Succes-lead" vises kun på Adversus-opkald, hvis lead er succes, både i "Succes-leads" og i "Alle opkald".

Zendesk-opkald får intet mærke. Mærket betyder "lead i Adversus" og må ikke antyde noget om Zendesk.

### 3.4 Delvise data (Adversus)
| Felt | Mangler | Liste | Detalje |
|---|---|---|---|
| Kunde (telefon) | Gemmes ikke (dataminimering) | "—" | Rækken udelades i meta og undertitel |
| Agent | Kun userId kendes | "Bruger #123" | "Bruger #123" |
| Retning | `origin` kan ikke mappes til ind/ud | "—" | "Ukendt" |
| Ticket | Altid for Adversus | "—" | Rækken udelades |
| Kampagne | Mangler | – | Rækken udelades |

Engineeren beskriver i v0 mapningen af `origin` til ind/ud og af agentnavn. Hvis navnet kan slås op, bruges navnet.

## 4. Knappen "Transcribér dette opkald" (AK7)

**Vises kun** på detaljesiden for Adversus-opkald med status "ikke valgt". Knappen står ikke i listen, se D-F4-3.

**Klik og redirect:** POST går til `/opkald/{id}/transcriber` med CSRF. Der kræves login, og alle roller må bruge knappen. Svaret er altid en 303-redirect (PRG) tilbage til `/opkald/{id}?<ctx>`, så genindlæsning aldrig sender igen.

| Tilstand | Når | Hvad brugeren ser |
|---|---|---|
| Standard | Status "ikke valgt" | Handlingsboksen fra 1.2 med den primære knap aktiv |
| Sender (kun JS, BØR) | Efter klik indtil siden skifter | `app.js` sætter `disabled` og `aria-disabled` og skifter teksten til "Sætter i kø …". Uden JS sker intet, og serverens idempotens beskytter mod dobbeltklik. |
| Sat i kø (succes) | Første POST | Flash ok: "Opkaldet er sat i kø. Lyden hentes fra Adversus, og transcriptet laves derefter." Knappen er væk. Badget er "Venter på lyd". Venstre kolonne: info "Lyden hentes fra Adversus. Det sker normalt inden for 10 minutter." Højre kolonne: neutral "Transcriptet laves, når lyden er hentet." |
| Allerede i kø (idempotent) | POST på opkald i kø eller under arbejde (fx en anden fane) | Flash info: "Opkaldet er allerede i kø." Intet ændres. |
| Allerede færdig (idempotent) | POST på opkald med transcript | Flash info: "Opkaldet er allerede transcriberet." Intet ændres. |
| Lyd fejlet senere | Hentning fejler efter køen | Fejl som F005 med kilden skiftet: "Lyden kunne ikke hentes fra Adversus (N forsøg)." + `<details>` "Teknisk besked". Højre: "Der kan ikke laves et transcript uden lyd." Knappen vises IKKE igen (se 10). |
| Transcription fejlet | Som Zendesk | Uændret F005-tekst: "Transcriptet kunne ikke laves." + `<details>`. |
| Færdig | Som Zendesk | Afspiller, segmenter, "Kopiér tekst" og "Download transcript (.txt)" |
| CSRF ugyldig/udløbet | Gammel fane | Det eksisterende CSRF-fejlmønster (samme side og tekst som andre POST i appen) med linket tilbage til opkaldet |
| Ikke logget ind | Session udløbet | Det eksisterende login-redirect. Der sættes intet i kø. |
| Findes ikke / ikke Adversus / flag slukket | – | 404 "Opkaldet findes ikke." med "Til opkald" (bevar query) |
| Disabled | – | Knappen renderes aldrig disabled. Kan opkaldet ikke sættes i kø, vises knappen ikke. |

Der er ingen bekræftelsesdialog. Handlingen er billig, idempotent og ufarlig. Det er samme princip som D7.

## 5. States for siderne

### Listen
- **Standard, data:** som F005, med Kilde-kolonnen.
- **Loading:** der er ingen loading-tilstand, fordi siden renderes på serveren. Badget "Transcriberer" pulserer som i dag.
- **Ikke valgt i "Alle opkald":** badget "Ikke valgt". Rækken er klikbar til detaljen, hvor knappen står.
- **Tomt, "Succes-leads", uden filtre:** H2 "Ingen opkald endnu". Tekst: "Opkald fra Zendesk og opkald på succes-leads fra Adversus dukker op her inden for 10 minutter." Handling: sekundær knap "Vis alle opkald" (`/?vis=alle`).
- **Tomt, "Alle opkald", uden filtre:** H2 "Ingen opkald endnu". Tekst: "Opkald med optagelse fra Zendesk og Adversus dukker op her inden for 10 minutter." Ingen handling.
- **Tomt med filtre:** F005-tomtilstanden "Ingen opkald matcher" med "Nulstil filtre" (bevarer `vis`, 3.1). I "Succes-leads" kommer desuden linket "Søg i alle opkald" (samme query + `vis=alle`).
- **Fejl:** systembeskederne er uændrede (Zendesk). Adversus-status er et åbent punkt, se 10.

### Detaljen for Adversus
F005 §3.4's tilstandstabel gælder. Overalt står "Adversus", hvor der i dag står "Zendesk". Teksterne vælges efter kilde:
- Venter på lyd: "Lyden er ikke hentet fra Adversus endnu. Det sker normalt inden for 10 minutter."
- Lyd fejlet: "Lyden kunne ikke hentes fra Adversus (N forsøg)." Linjen "Optagelsen kan måske afspilles direkte i ticket …" udelades.
- Ikke valgt: afsnit 1.2 og 4.

## 6. User flow
1. **Hverdagen:** `/` viser "Succes-leads" → klik på en række → detaljen med afspiller og transcript → "← Tilbage til opkald" (bevarer `vis` og filtre).
2. **Manuel transcription:** klik på "Alle opkald" → find opkaldet (badge "Ikke valgt") → detaljen → "Transcribér dette opkald" → redirect til samme side med flash og badge "Venter på lyd" → brugeren genindlæser senere (der er ingen auto-refresh, F005 §4) → "Færdig" → tilbage til listen, stadig i "Alle opkald".
3. **Fejlvej:** udløbet CSRF → fejlside → tilbage til opkaldet → klik igen (idempotent).
4. **Tilbage/genindlæs:** efter POST står brugeren på en GET-side, så genindlæsning er ufarlig. Browserens tilbage-knap fører til detaljen, før der blev klikket. Siden kan vise en gammel knap, men et nyt klik giver "Opkaldet er allerede i kø."
5. **Kanttilfælde:**
   - "Alle opkald" kan give mange rækker (ca. 20 % af alle Adversus-opkald har optagelse). Pageren på 50 og zip-loftet på 2000 er uændrede.
   - Zip følger den aktuelle visning og filtrene.
   - Lange kampagnenavne afkortes med `ellipsis` + `title` i meta.

## 7. Form design
Den eneste formular er knappen.
- **Indhold:** felterne er skjulte (CSRF og ctx), og der er ingen brugerinput.
- **Validering:** al validering sker på serveren, og fejl vises som flash eller fejlside efter afsnit 4.
- **Label:** knappens label er altid "Transcribér dette opkald".

## 8. Responsive behavior
- **Segmentvælger under 640px:** fuld bredde med to lige brede halvdele og mindst 44px høje touch-mål. "Vis:"-labelen står over knapperne.
- **Mobilkort (F005 §3.9):** "Kilde" bliver en `data-label`-række. "Succes-lead"-mærket står i kortets første linje ved siden af status, så det ses uden at scrolle.
- **Detalje:** følger F005 §3.4 (700–1099px og under 700px). Handlingsboksen ligger øverst i transcript-sektionen. Under 480px er knappen fuld bredde og mindst 44px høj.

## 9. Copy / microcopy (dansk; appen har ingen sprogfil, F005 §5)
| Sted | Tekst |
|---|---|
| Segmentvælger, label | Vis: |
| Segmenter | Succes-leads · Alle opkald |
| Kolonne | Kilde |
| Kildeværdier | Zendesk · Adversus |
| Mærke | Succes-lead |
| Badge | Ikke valgt |
| Agent uden navn | Bruger #{id} |
| Meta-rækker | Kilde · Kampagne · Lead |
| Ikke valgt, venstre | Lyden er ikke hentet. Opkaldet er ikke på et succes-lead. |
| Ikke valgt, højre | Opkaldet er ikke på et succes-lead og bliver derfor ikke transcriberet automatisk. |
| Knap | Transcribér dette opkald |
| Hjælpetekst under knap | Lyden hentes fra Adversus, og opkaldet sættes i kø. Det tager normalt under 15 minutter. |
| Knap, sender (JS) | Sætter i kø … |
| Flash ok | Opkaldet er sat i kø. Lyden hentes fra Adversus, og transcriptet laves derefter. |
| Flash, allerede i kø | Opkaldet er allerede i kø. |
| Flash, allerede færdig | Opkaldet er allerede transcriberet. |
| Venter på lyd (Adversus) | Lyden er ikke hentet fra Adversus endnu. Det sker normalt inden for 10 minutter. |
| Lyd fejlet (Adversus) | Lyden kunne ikke hentes fra Adversus (N forsøg). |
| Tom, Succes-leads | Ingen opkald endnu / Opkald fra Zendesk og opkald på succes-leads fra Adversus dukker op her inden for 10 minutter. / [Vis alle opkald] |
| Tom, Alle opkald | Ingen opkald endnu / Opkald med optagelse fra Zendesk og Adversus dukker op her inden for 10 minutter. |
| Tom med filtre, ekstra link | Søg i alle opkald |
| Skjult tabeltekst | `<caption class="sr-only">`: "Opkald – succes-leads" eller "Opkald – alle" |

"Under 15 minutter" er udledt af en sync hvert 10. minut plus kø. Engineeren retter tallet i teksten, hvis lyden til manuelle opkald hentes på en anden kadence.

## 10. Accessibility (WCAG 2.2 AA)
- **Fokusrækkefølge i listen:** H1 → filterkort → segmentvælger → chips → zip → tabel → pager.
- **Fokusrækkefølge på detaljen:** tilbage-link → (afspiller) → handlingsboksens knap → transcript.
- **Segmentvælger:** `role="group"` med synlig label "Vis:". Det aktive link har `aria-current="true"` plus en visuel markering, der ikke kun er farve (fx fed tekst eller en indre kant). Det er links, ikke radioknapper, og der er ingen auto-submit.
- **Knap og hjælpetekst:** knappen har `aria-describedby` til hjælpeteksten. Tilstanden "Sender" sætter `aria-disabled="true"`, og den eksisterende `#live`-region får "Sætter opkaldet i kø."
- **Flash efter redirect:** bruger den eksisterende `role="status"`, og fejl bruger `role="alert"`.
- **Mærker og badges:** "Succes-lead" og "Ikke valgt" er synlig tekst og bærer betydningen alene. Ikoner har `aria-hidden`. Kontrasten følger de eksisterende `--success-*` og `--neutral-*` tokens (mindst 6:1).
- **Mål:** mindst 24×24 og 44px på touch.

## 11. Design decisions
- **D-F4-1 Visningen i URL'en som `vis=alle`, uden chip.** Det er en visning med to værdier og en standard, ikke et filter. Ingen chip, og "Nulstil filtre" bevarer den, så brugeren ikke mister "Alle opkald" ved at rydde en søgning. Standarden skrives ikke i URL'en, så eksisterende links til `/` bevarer deres betydning.
- **D-F4-2 Segmentvælger med links i stedet for en select i filterformularen.** Skiftet virker med ét klik og uden "Søg", og der er ingen auto-submit (F005 §4). Det følger hurtigvalg-mønstret (links, der bevarer de andre filtre, `aria-current`).
- **D-F4-3 Knappen kun på detaljesiden, ikke i listen.** Fordelene er:
  - der kommer ikke 50 POST-formularer og et konflikt med den strakte række-link (F005 §3.3);
  - brugeren ser opkaldets metadata, før han bruger maskinkraft på det;
  - det giver færre fejlklik.

  Prisen er ét klik ekstra pr. opkald, og det er acceptabelt, fordi manuel transcription er undtagelsen (#31).
- **D-F4-4 Kilde som egen kolonne og succes som mærke uden prik.** Det holder statusbadge-systemet (D3) rent: prik betyder behandlingsstatus, og mærket betyder lead-egenskab.
- **D-F4-5 Ingen bekræftelsesdialog og PRG med idempotente flash-tekster.** Det er samme princip som D7.

## Nye design-beslutninger i dette brief
### Visning vs. filter (`vis`)
- **Hvad:** en parameter med få faste værdier og en standard vises som segmentvælger og er ikke en chip. "Nulstil filtre" bevarer den.
- **Hvorfor:** brugeren skelner mellem "hvad ser jeg" og "hvad har jeg søgt på".
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### Handlinger, der koster maskinkraft, kun på detaljesiden
- **Hvad:** POST-handlinger pr. opkald står på detaljen, ikke i listens rækker.
- **Hvorfor:** det giver bevidste valg, ingen konflikt med klikbare rækker og færre formularer.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### Lead-/egenskabsmærke uden prik (`.tag`)
- **Hvad:** pille uden prik til egenskaber, adskilt fra statusbadges med prik.
- **Hvorfor:** det adskiller tilstand fra egenskab.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

## 12. Til Team Lead (åbne punkter, ikke designet her)
1. **"Download som for Zendesk":** F005 har ingen lyd-download (uden for scope, F005 §10), kun transcript (.txt) og zip. Briefet tolker spec'en som "afspiller + .txt + zip". Skal lyd kunne downloades, er det et spec-tillæg.
2. **Adversus-sundhed:** spec'en nævner ingen statuslinje eller admin-beskeder for Adversus (ikke sat op, sync stoppet). Forslag:
   - en anden linje i `.sync`: "Hentet fra Adversus for N min. siden" med samme prik-regler;
   - admin-info "Adversus er ikke sat op …", som Zendesk.

   Begge dele kræver status-data fra `adversus-sync`. Afgør, om det er med i F004 eller et senere ID.
3. **Knappen efter mislykket lydhentning:** spec'en siger kun, at "i kø eller færdigt ændres ikke". Briefet viser ikke knappen igen (admin-genkø som F005). Vil ejeren have "Prøv igen" for brugere, skal det i spec'en.
4. **F004 tændt med F005_ui slukket:** det gamle UI skal have samme standardfiltrering (succes), så "ikke valgt"-opkald ikke vises ufiltreret. Briefet designer ikke det gamle UI. Anbefalingen er, at F004_adversus kræver F005_ui (begge er tændt på dev og slukket på test).
5. **MCP (F003):** kilde og succes-markering i værktøjernes output er tekst og ligger uden for UI-briefet.
