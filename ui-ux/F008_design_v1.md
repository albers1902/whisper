Status: READY_FOR_IMPLEMENTATION

# F008, design v1: Adversus pr. lead

Status: READY_FOR_IMPLEMENTATION

Kilder:
- `product-ai/F008_v1.md` (bindende) og `tasks/impact-F008.md`.
- `ui-ux/F007_design_v2.md` og `graphic-design/F007_visual_v2.md`.
- Koden på main @ ee5ef76: `homedir/src/UiCallsV2.php` (`list`, `cards`, `show`, `player`, `conversation`, `status`, `statusPills`, `viewSwitch`), `UiV2.php`, `app-v2.css` og `app-v2.js`.

`DESIGN_LOG.md` og `PRD.md` findes ikke. Rammen er derfor F007 v2.

Alt fra F007 genbruges uændret: ramme, kø-kort, søgekort med piller, faner, statuspiller, succes-vælger, kort, tags, status-pille, pager, tom-kort, afspillerkort, bobler, én kolonne, "Find i samtalen", `.msg`, knapper og fokusring. Dette brief beskriver kun det nye.

---

## 0. Rammer

**Flaget**
- `F008_leads` virker kun, når `F007_kilder` er tændt. Med F007 slukket er alt som i dag.
- Med `F008_leads` slukket (AK9):
  - Adversus-fanen er byte for byte som F007, med opkaldskort.
  - `/opkald/{id}` har intet lead-link.
  - `/lead/...` giver 404 (samme fejlside som et ukendt opkald).
  - Ingen ny CSS-regel må ramme F007-siderne. Nye klasser starter med `lead-` og bruges kun af nye sider og elementer.

**Zendesk-fanen** er uændret.

**Tilstand i URL'en**
- Filtre, `kilde=adversus`, `vis`, `status`, `q` og `side` er som i F007.
- Lead-siden bærer listens kontekst i query'en, som `/opkald/{id}` gør i dag (`CallView::query`).

**Teknik**
- Siderne renderes på serveren og skal virke uden JS.
- CSP er som F007. Ingen nye afhængigheder og ingen nye fonte.

---

## 1. Page/screen layout

### 1.1 Adversus-fanen med flaget tændt

Layoutet er F007 §1.4 med fire ændringer:
- **Opsummering:** "Viser 1–50 af 1.234 leads". Over loftet står "flere end 10.000 leads".
- **Succes-vælger:** "Succes og valgte · Alle leads".
- **Liste:** `<ol class="call-list lead-list">` med lead-kort (§2.1).
- **"Uden lead":** findes der opkald uden lead-id i filtret, står kortet "Uden lead" først på side 1 (§2.2). Det tæller ikke med i N.

Zip-knappen og statuspillerne Alle / Færdige / I kø står samme sted som i dag. Statuspillerne virker på leadets samlede status (spec'en).

```
Viser 1–50 af 1.234 leads · 12 opkald uden lead
┌──────────────────────────────────────────────────────────────────────────┐
│ ◌◌◌  Opkald uden lead                                       12 opkald  › │  (dæmpet kort, kun side 1)
└──────────────────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────────────────────┐
│ (SA)(MC)+1  Lead #12345  [🏆 Succes-lead] [Kampagne #12]                  │
│             +45 12 34 89 75 · 4 opkald, 2 besvarede · 12:41 taletid       │
│             3. nov. 09:12 → i dag 12:09 · Sara, Maria C. og 1 mere        │
│                                   [● 2 af 2 transcriberet] [● I kø]       │
│ 6. nov. 12:09 — Agent: … vi sender <mark>faktura</mark> i morgen …        │  (kun ved søgning)
└──────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Lead-siden `/lead/{lead_id}?{ctx}`

Siden har én kolonne med maks. bredde 960 px. Et forløb læses oppefra og ned, så der er ingen sidekolonne.

```
← Tilbage til opkald

Lead #12345                                     [⤓ Download .txt] [⤓ Download .zip]
[🏆 Succes-lead] [Kampagne #12] [+45 12 34 89 75] [4 opkald · 2 besvarede]
[12:41 taletid] [3. nov. 2026 → 6. nov. 2026] [● 2 af 2 transcriberet]
Agenter: Sara, Maria Christensen, Jonas

┌ 🔍 Find i alle opkald ……………………… ┐  3 af 12 · opkald 2 af 4

Forløb                                                    (H2)
 ① ┌ Opkald 1 af 4 · 3. nov. 2026 kl. 09:12 ─────────────────── Åbn opkaldet ┐
   │ ↑ Udgående · Sara · 3:01 · Salg · [● Færdig]                              │
   │ [ mørkt afspillerkort, kompakt, sticky inden for sektionen ]              │
   │ AGENT 00:01  Hej, det er Sara fra …                                       │
   │                         KUNDE 00:04  Ja hej …                             │
   └───────────────────────────────────────────────────────────────────────────┘
 ② ○ 3. nov. 2026 kl. 11:40 · ↑ Udgående · Sara · Ikke besvaret (optaget)       (kompakt linje)
 ▸ 5 ubesvarede opkald · 4. nov. 08:02 – 5. nov. 16:30                            (gruppe, §2.5)
 ③ ┌ Opkald 3 af 4 · 6. nov. 2026 kl. 12:09 ──────────────────────────────────┐
   │ ↓ Indgående · Maria C. · 9:40 · Ingen disposition · [Ikke valgt]          │
   │ ⓘ Opkaldet er ikke på et succes-lead …  [Transcribér dette opkald]         │
   └───────────────────────────────────────────────────────────────────────────┘
```

**DOM-rækkefølge:**
1. Tilbage.
2. Flash.
3. `.d-head` med H1, chips, agentlinje og handlinger.
4. Find-feltet.
5. `<section aria-labelledby="forloeb-h">` med `<h2 id="forloeb-h">Forløb</h2>`.
6. `<ol class="lead-timeline">`.
7. Pager, hvis der er over 100 opkald (§3).

**Tidslinjen:**
- En lodret linje på 2 px i `--border` til venstre.
- Hvert punkt har en markør på 10 px: fyldt for besvarede og hul for ubesvarede (`aria-hidden`).
- Under 600 px fjernes linjen og markørerne.

### 1.3 `/opkald/{id}` med flaget tændt og `lead_id` > 0

- I `.head-actions` står en sekundær knap som første knap: ikon `users` og teksten "Se hele leadet (4 opkald)". Den linker til `/lead/{lead}?{ctx}#opkald-{id}`.
- Knappen vises også, når der ikke er noget transcript. `.head-actions` renderes da med kun denne knap.
- I metadata-gitteret bliver "Lead #12345 · Succes-lead" et link til det samme.
- Alt andet er uændret.

---

## 2. Components

| Komponent | Ny/eksisterende | Note |
|---|---|---|
| Lead-kort | **NY** (bygger på `.call-card`) | §2.1 |
| Kortet "Uden lead" og siden `/lead/uden` | **NY** | §2.2 |
| Samlet status (to piller) | **NY** (bruger `.badge`-klasserne) | §2.3 |
| Lead-status-tag | **NY** tekst, eksisterende `.tag` og `.tag-success` | §2.3 |
| Lead-sidehoved | EKSISTERENDE `.d-head` og `.d-chips` | §1.2 |
| Opkaldssektion | **NY** (`<li><section>`), indeholder F007-afspiller og samtale | §2.4 |
| Ubesvaret linje og gruppe | **NY** | §2.5 |
| Find på tværs | EKSISTERENDE `.find`, udvidet | §2.6 |
| "Transcribér dette opkald" | EKSISTERENDE formular og tekster, ny retur | §2.7 |
| Download .txt og .zip for leadet | **NY** (knapper som F007) | §2.8 |

### 2.1 Lead-kort

Kortet er `<li class="call-card lead-card">` med samme form, hover og stretched link som F007 §2.5.

**Venstre celle:**
1. **Avatarstak:**
   - Op til 3 agent-avatarer på 32 px med `UiV2::avatar`, overlappende med −8 px og en 2 px hvid kant.
   - Ved flere end 3 agenter står en neutral cirkel med "+N".
   - Hele stakken er `aria-hidden`.
   - Uden agenter vises den neutrale avatar.
2. **Titel:**
   - `<a class="card-link" href="/lead/{id}?{ctx}">Lead #12345</a>` i 15 px/600.
   - Linket har en sr-only hale: ", Succes-lead, 4 opkald, seneste i dag kl. 12:09, 2 af 2 transcriberet".
3. **Tags efter titlen:** lead-status (§2.3) og "Kampagne #12". Kampagne udelades uden værdi.
4. **Meta-linje 1** (13 px, muted): kundens nummer · "4 opkald, 2 besvarede" · "12:41 taletid".
   - Mangler nummeret, udelades leddet.
   - Taletid er summen af de besvarede opkalds varighed, formateret med `Calls::duration`.
5. **Meta-linje 2:** første → seneste som `<time>`, med samme format og `title` som F007 ("3. nov. 09:12 → i dag 12:09"), og så " · " og agenterne.
   - Har leadet kun ét opkald, står kun én tid.
   - Agentlisten er "Sara", "Sara og Mads", "Sara, Mads og Jonas" eller "Sara, Mads og 3 mere". Rækkefølgen er efter første opkald. Navnet "Ukendt agent" er med.
   - Pilen "→" er `aria-hidden`, og der står sr-only " til " i stedet.

**Højre celle:** samlet status (§2.3). Der er ingen varighed her, fordi den står i meta-linjen.

**Uddrag:**
- Uddraget vises kun ved søgning (`q`). Det er det første matchende opkalds søgeuddrag (`Ui::snippet` med `<mark>`).
- Foran står opkaldets tid i 600: "6. nov. 12:09 — ".
- Det har samme to-linjers klip som F007.
- Uden søgning er der intet uddrag. Kortet er et overblik, og samtalen står på lead-siden.

### 2.2 "Uden lead"

**Kortet:**
- Det står kun på side 1 og kun, når mindst ét Adversus-opkald uden `lead_id` matcher fanens filtre.
- Form: `.call-card` med stiplet kant (`--border-dashed`) og ingen skygge.
- Venstre: neutral avatar med ikonet `inbox`, linket "Opkald uden lead" og meta "Opkald, som Adversus ikke har knyttet til et lead".
- Højre: "12 opkald" (13 px/600).
- Linket går til `/lead/uden?{ctx}`.

**Siden `/lead/uden`:**
- H1 "Opkald uden lead" og tilbage-link som på lead-siden.
- Under det F007's opkaldskort (`UiCallsV2::cards`) for de matchende opkald, nyeste først, med pager på 50.
- Kortene linker til `/opkald/{id}`, og tilbage derfra går til listen.
- Det er genbrug uden nye komponenter (AK10).

### 2.3 Status på lead-niveau

**Lead-status-tag:**
- `success_status` giver det eksisterende `Ui::successTag()` "Succes-lead".
- Andre værdier vises som neutral `.tag`, med kendte Adversus-værdier oversat:

| Værdi | Tekst |
|---|---|
| `notInterested` | Ikke interesseret |
| `unqualified` | Ukvalificeret |
| `automaticRedial`, `privateRedial`, `sharedRedial` | Genopkald |
| `invalid` | Ugyldig |
| ukendt værdi | den rå værdi |
| ingen række i `adversus_leads` | intet tag |

**Samlet transcription-status:** højst to piller i denne rækkefølge.
1. **Tællepille.** Den vises, når der er mindst 1 besvaret opkald: "{n} af {m} transcriberet", hvor m er antal besvarede opkald.
   - n = m: `.badge.s-faerdig` (grøn).
   - Ellers: `.badge.s-ikke-valgt` (omrids).
2. **Aktivitetspille.** Den vises kun, hvis et besvaret opkald er i en af disse tilstande. Kun den højeste vises:
   1. Transcriberer
   2. I kø
   3. Fejlet
   4. Lyd mangler
   5. Venter på lyd

   Klasser og tekster er de samme som `UiCallsV2::status`.
3. **0 besvarede opkald:** kun pillen "Ikke besvaret" (`.s-ikke-valgt`).

Pillerne bruger de samme farver som F007, så der er ingen nye tokens.

### 2.4 Opkaldssektion (besvaret opkald)

Sektionen er `<li class="lead-item" id="opkald-{id}"><section class="lead-call" aria-labelledby="oc-{id}-h">`. Kortet er hvidt med radius 16, padding 20 (16 under 600 px) og lodret gap 12.

**Hoved:**
- `<h3 id="oc-{id}-h">Opkald 1 af 4 · 3. nov. 2026 kl. 09:12</h3>` i 15 px/700. Tallene tæller alle opkald, også ubesvarede.
- Til højre står et diskret link "Åbn opkaldet" til `/opkald/{id}?{ctx}` (13 px, ikon `chevron-right`). Det giver adgang til opkaldets egen .txt, metadata og Claude-boks.

**Meta-linje** (13 px, muted, ombrydes):
- Retning ("↑ Udgående" / "↓ Indgående") · agent · varighed · disposition · status-pille (`UiCallsV2::status`).
- Dispositionen oversættes kun, hvor teksten findes i dag. Ellers vises den rå værdi, og uden værdi udelades leddet.

**Lyd:**
- Afspillerkortet fra F007 §2.9 (`player-v2`) i fuld bredde, kompakt: række 1 med play og spol, tiderne til højre for spolelinjen, så hastighed og "Download lyd".
- `aria-label="Optagelse, opkald 1"`.
- Afspilleren er `position: sticky` inden for sin egen sektion (`top: 0`, 56 px under 900 px). Den slipper, når sektionen er forbi. Ved `max-height: 600px` er den ikke sticky.
- Lyd-tilstandene (ikke hentet, venter, fejlet, mangler) står samme sted med F007's tekster.

**Samtale:**
- `UiCallsV2::conversation` uændret: bobler eller én kolonne.
- Ingen H2 "Samtale" i sektionen, fordi H3 er nok.
- Transcript-tilstandene (på vej, laves nu, fejlet, ingen tale, mangler) har F007's tekster.

**Unikke id'er:**
- `#afspiller` bliver til `afspiller-{id}`, og `#transcript` bliver til `transcript-{id}`.
- Boble-ankre bliver til `t-{id}-{sek}`, og ankre for søgefund bliver til `fund-{id}-{n}`.
- Dette er krævet, fordi siden har flere af hver.

**JS (app-v2.js):**
- Afspillerlogikken skal arbejde pr. `.lead-call`: knap, spol, hastighed, tidsstempler og aktuel boble inden for sektionen.
- Starter man én afspiller, pauses alle andre.
- Fejl i én afspiller slår kun den sektions kontroller fra.

### 2.5 Ubesvarede opkald (kompakt)

**Én linje:** `<li class="lead-item lead-miss">` uden kort, 13 px, muted, min-højde 32 px.
- Indhold: "○ 3. nov. 2026 kl. 11:40 · ↑ Udgående · Sara · Ikke besvaret (optaget)".
- Dispositionerne `busy`, `no-answer`, `failed` og `congestion` vises som "optaget", "intet svar", "fejlet" og "ikke igennem".
- Der er ingen afspiller, ingen knap og intet link. Der er intet at åbne.
- Linjen har sr-only "Opkald 2 af 4: " foran, så nummereringen også høres her.

**Gruppe:** 3 eller flere ubesvarede i træk samles i `<li><details class="lead-miss-group">`.
- Summary: "5 ubesvarede opkald · 4. nov. 08:02 – 5. nov. 16:30".
- Lukket som standard. Åben viser den linjerne ovenfor.
- Det holder automatiske genopkald fra at drukne forløbet.

### 2.6 Find på tværs af opkald (AK4)

**Feltet:**
- F007's `.find` står under sidehovedet i fuld bredde (maks. 480 px).
- `data-js-only hidden`, så det ikke vises uden JS (som i F007).
- Label (sr): "Find i alle opkald". Placeholder: "Find i alle opkald".

**Hvad der søges i:** alle `.say`-noder i `#forloeb`.
- Med kun ét transcript på siden er feltet det samme, bare med færre træf.
- Uden transcripts vises feltet ikke.

**Status** (`aria-live="polite"`):
- "3 af 12 · opkald 2 af 4", hvor "opkald x af y" er sektionen med det aktive træf.
- Ingen træf: "Ingen træf".

**Taster:** som i F007. Enter og pil ned går til næste træf, Skift+Enter og pil op til forrige, og Esc rydder.
- Næste træf efter det sidste i et opkald hopper til det første i det næste opkald.
- `scrollIntoView({block: 'center'})`, så den sticky afspiller ikke dækker træffet.

**Lukkede grupper:** de indeholder ingen transcripts, så de påvirkes ikke.

**Server-søgning (`q` fra listen):**
- Træf markeres med `<mark>` som på `/opkald`.
- Under H2 "Forløb" står "7 fund for «faktura» i 2 opkald · Gå til første fund", eller "Ingen fund for «faktura» i dette lead.".

### 2.7 "Transcribér dette opkald"

- Formular, betingelser, hjælpetekster og `data-busy` er som i F007 og F004, men pr. sektion.
- **Ny retur:** POST fra lead-siden sender med, at svaret skal tilbage til `/lead/{lead}?{ctx}#opkald-{id}` (PRG).
  - Flash ("sat i kø" eller idempotent info) står under tilbage-linket.
  - Engineeren vælger mekanismen. Returen må kun kunne pege på leadets egen side, så der ikke opstår en åben redirect.
- Knappen findes kun i sektioner for besvarede opkald. Ubesvarede har intet at transcribere.

### 2.8 Download af leadet (AK5)

I `.head-actions`:
- **"Download .txt"** (primær) → `/lead/{id}/transcript`.
  - Vises, når mindst ét opkald har et færdigt transcript med tale.
  - Format: se spec'en. Hvert opkald får en overskrift med nummer, tid, retning og agent. Opkald uden transcript står med overskrift og "(intet transcript)", så rækkefølgen er hel.
- **"Download .zip"** (sekundær) → `/lead/{id}/download`.
  - Vises, når der er mindst én lydfil eller ét transcript.
  - `aria-describedby` peger på sr-only "Lyd og transcripts for alle opkald på leadet."

Uden indhold vises ingen af knapperne, som på `/opkald`.

---

## 3. States

### Listen
- **Standard:** §1.1.
- **Indlæsning:** ingen, fordi siden renderes på serveren.
- **Tom (Succes og valgte, uden filtre):** "Ingen Adversus-leads endnu" / "Leads med succes og leads med opkald, der er valgt til transcription, dukker op her inden for 10 minutter." + knappen "Vis alle leads".
- **Tom (Alle leads, uden filtre):** "Ingen Adversus-leads endnu" / "Leads med opkald fra Adversus dukker op her inden for 10 minutter."
- **Tom med filtre:** "Ingen leads matcher" / F007's tekst og handlinger ("Nulstil filtre", "Søg i alle leads", hint om den anden fane).
- **Tom ved "I kø" uden andre filtre:** "Ingen leads i kø lige nu" / "Nye opkald transcriberes, så snart lyden er hentet."
- **Kun "Uden lead"-træf:** tom-tilstanden står under kortet "Uden lead", så man kan se, at noget findes.
- **Delvise data:**
  - Uden kampagne, nummer eller lead-status: elementet udelades.
  - Uden agentnavn: "Ukendt agent".
- **Loft:** tal og "10.000+" som F007. Hint ved loftet: "Kun de nyeste 10.000 leads kan bladres. Indsnævr med periode, agent eller søgning for at se ældre leads."

### Lead-siden
- **Ukendt lead-id** (ingen opkald og ingen lead-række): 404-siden med "Leadet findes ikke." og tilbage-link til listen med ctx.
- **Leadet findes, men har 0 opkald:** sidehoved uden tal, og et tom-kort med "Ingen opkald på dette lead endnu." og teksten "Opkald hentes fra Adversus inden for 10 minutter."
- **Kun ubesvarede opkald:** kun kompakte linjer. Find og download vises ikke.
- **Mange opkald:** højst 100 opkald pr. side, ældste først.
  - Over 100 står F007's pager under forløbet (`?side=2`, sammen med ctx).
  - Under H2 står "Leadet har 342 opkald. Siden viser opkald 1–100."
- **Fejl:** pr. sektion med F007's lyd- og transcript-tekster. Én fejl påvirker ikke resten.
- **Disabled:** afspillerens kontroller efter en afspilningsfejl (F007), og "Transcribér" under afsendelse.

### `/opkald/{id}`
- `lead_id` mangler: ingen lead-knap.
- Leadet har 1 opkald: knappen står stadig, med teksten "Se hele leadet (1 opkald)".

---

## 4. User flow

1. **Kvalitetstjek af et salg:**
   1. Adversus-fanen, "Succes og valgte".
   2. Lead-kort med Succes-lead.
   3. Lead-siden.
   4. Læs opkaldene oppefra, og afspil fra en boble.
   5. "Find i alle opkald" efter "pris" → hop mellem opkaldene.
   6. Download .zip.
   7. "Tilbage til opkald" fører til samme fane, filtre og side.
2. **Fra søgning:** søg "opsigelse" → kortet viser opkaldets tid og uddrag → lead-siden markerer fundene og har "Gå til første fund".
3. **Manuel transcription:**
   1. Lead-siden.
   2. En sektion med "Ikke valgt".
   3. "Transcribér dette opkald".
   4. Svaret fører tilbage til samme sektion (`#opkald-{id}`) med flash. Pillen siger "I kø".
4. **Fra MCP eller et bogmærke:** `/opkald/{id}` → "Se hele leadet" → lead-siden ved det opkald.
5. **Opkald uden lead:** kortet "Uden lead" → F007-kort → `/opkald/{id}`.

**Tilbage og genindlæs** er ufarlige, fordi alt er GET med tilstand i URL'en. POST bruger PRG, og bfcache-reglerne i app-v2.js gælder for alle `select-call`-formularer på siden.

**Tilbage-link fra lead-siden:**
- Det går altid til Adversus-fanen med ctx.
- Er ctx tom (fx kommet fra MCP), går det til `/?kilde=adversus`.

---

## 5. Form design

- Ingen nye formularer. Søgekortet er F007's.
- "Transcribér" er F004's formular, gentaget pr. sektion med unikke id'er (`vaelg-hjaelp-{id}`).

---

## 6. Responsive behavior

| Bredde | Lead-kort | Lead-side |
|---|---|---|
| ≥ 900 | som §1.1 | 960 px, tidslinje med markører |
| 600–899 | som F007 | som ovenfor, ramme med topbar |
| < 600 | avatarstakken bliver til én avatar (den første); status står på egen række under meta; tags ombrydes | se nedenfor |

**Lead-siden under 600 px:**
- H1 er 22 px, og chips ombrydes.
- Download .txt og .zip står i fuld bredde under hinanden.
- Find-feltet er i fuld bredde.
- Tidslinjen og markørerne fjernes. Sektionerne er kort med 12 px imellem.
- I sektionens hoved står "Åbn opkaldet" under H3.
- Afspilleren er F007's kompakte mobil-version, sticky under topbaren inden for sektionen.
- Boblerne er højst 88 % brede.
- Ubesvarede linjer ombrydes, og summary på en gruppe er 44 px høj.

**Ved 320 px:**
- Ingen vandret scroll. `overflow-wrap: anywhere` gælder i meta, chips og bobler.
- Agentlisten på kortet er klippet til "Sara og 3 mere".

**Mål:** 44 × 44 px for alle links og knapper under 600 px.

---

## 7. Copy / microcopy (dansk, appen har ingen sprogfil)

| Sted | Tekst |
|---|---|
| Succes-vælger | Succes og valgte · Alle leads |
| Fanetal (sr) | " leads" (Adversus), " opkald" (Zendesk) |
| Opsummering | Viser {a}–{b} af {N} leads · {n} opkald uden lead |
| Lead-kort | Lead #{id} · {n} opkald, {b} besvarede · {tid} taletid · {første} → {seneste} · {A}, {B} og {n} mere |
| Lead-kort, uddrag | {tid} — {uddrag} |
| Samlet status | {n} af {m} transcriberet · Ikke besvaret · (aktivitet: Transcriberer / I kø / Fejlet / Lyd mangler / Venter på lyd) |
| Lead-status | Succes-lead · Ikke interesseret · Ukvalificeret · Genopkald · Ugyldig · {rå værdi} |
| Uden lead | Opkald uden lead · Opkald, som Adversus ikke har knyttet til et lead · {n} opkald |
| `<title>` | Lead #{id} · Adversus · Opkald uden lead · Adversus |
| Lead-side | Tilbage til opkald · Lead #{id} · Agenter: … · Forløb · Opkald {x} af {y} · {dato} kl. {hh:mm} · Åbn opkaldet |
| Ubesvaret | Ikke besvaret (optaget / intet svar / fejlet / ikke igennem) · {n} ubesvarede opkald · {fra} – {til} |
| Find | Find i alle opkald · {a} af {b} · opkald {x} af {y} · Ingen træf |
| Server-fund | {n} fund for «{q}» i {k} opkald · Gå til første fund · Ingen fund for «{q}» i dette lead. |
| Download | Download .txt · Download .zip · (sr) Lyd og transcripts for alle opkald på leadet. |
| Mange opkald | Leadet har {n} opkald. Siden viser opkald {a}–{b}. |
| Tomme tilstande | se §3 |
| `/opkald/{id}` | Se hele leadet ({n} opkald) |
| Afspiller (aria) | Optagelse, opkald {x} |
| Fejl | Leadet findes ikke. |

---

## 8. Accessibility (WCAG 2.2 AA)

**Fokusrækkefølge, lead-siden:**
1. Tilbage.
2. Download .txt og .zip.
3. Find.
4. "Gå til første fund".
5. Pr. sektion i tidsorden:
   1. "Åbn opkaldet".
   2. Afspiller: play, spol, hastighed og Download lyd.
   3. Tidsstempel-knapper.
   4. Transcribér.
   5. "Teknisk besked".
6. Ubesvarede grupper (summary).
7. Pager.

DOM-rækkefølgen er den visuelle, og der bruges ingen `order`.

**Overskrifter:**
- H1 "Lead #…" og H2 "Forløb".
- H3 pr. besvaret opkald, så man kan hoppe mellem opkald med H-tasten i en skærmlæser.
- Ubesvarede linjer har ingen overskrift.

**Liste:**
- Tidslinjen er en `<ol>`, så positionen bliver læst op.
- Markører og linjen er `aria-hidden`.

**Lead-kort:**
- Ét link pr. kort med en beskrivende sr-hale (§2.1).
- Avatarstakken og "→" er `aria-hidden` med tekstækvivalent.

**Status:** pillerne har altid tekst og bruger aldrig kun farve.

**Afspillere:**
- Hver har sit eget `aria-label` med opkaldsnummer.
- Kun én spiller ad gangen, og der er ingen autoplay.
- En fejl meldes i `#live` med opkaldsnummer: "Lyden til opkald 3 kunne ikke afspilles."

**Find:** status i `aria-live="polite"` med opkaldsnummer.

**Kontrast og fokusring:** F007's tokens og ringe. Der er ingen nye farvepar, bortset fra at "+N"-cirklen bruger den neutrale avatar.

**Ingen nye tastaturgenveje.**

---

## 9. Design decisions

- **D-F8-1 Lead-kort bygget på opkaldskortet.** Samme stretched link, tags og status. Titlen er leadet og ikke en agent, fordi flere agenter kan have talt med kunden.
- **D-F8-2 To statuspiller.** "n af m transcriberet" svarer på kvalitetstjekkets spørgsmål (er alt med?), og aktivitetspillen viser, hvad der mangler. Ubesvarede tæller ikke i m, fordi de aldrig kan transcriberes.
- **D-F8-3 "Uden lead" som ét kort øverst på side 1 med egen F007-liste.** Opkaldene forsvinder ikke (AK10). Leadtallet forbliver et leadtal, og der bygges intet nyt (genbrug af `cards`).
- **D-F8-4 Lead-siden har én kolonne og ingen sidekolonne.** Et forløb læses som en historie. Metadata pr. opkald står i sektionens meta-linje.
- **D-F8-5 Én afspiller pr. besvaret opkald, sticky inden for sin sektion.** Klik på en boble skal spille netop det opkald, og den sticky afspiller følger det opkald, man læser. Én afspiller spiller ad gangen.
- **D-F8-6 Ubesvarede opkald grupperes fra 3 i træk.** Automatiske genopkald kan give mange linjer, og forløbet skal kunne læses.
- **D-F8-7 Højst 100 opkald pr. lead-side.** Det holder siden hurtig ved leads med mange genopkald (skala, AK8). Tallet kan justeres i backend uden designændring.
- **D-F8-8 Uddrag på lead-kortet kun ved søgning.** Det viser, hvorfor kortet er et træf, og holder listen kort.

## Nye design-beslutninger i dette brief

### Gruppeside med tidslinje af underemner
- **Hvad:** en side for en samlet enhed (her et lead) med H3-sektioner i tidsorden. Hver sektion har sin egen afspiller og samtale, og der er find på tværs af sektionerne.
- **Hvorfor:** kvalitetstjek kræver, at hele forløbet kan læses ét sted.
- **Gælder for:** fremtidige grupperede visninger (fx ticket med flere opkald).
- **Anbefaling:** Vedtag som standard? JA

### Samlet status som "n af m" og aktivitetspille
- **Hvad:** en aggregeret status vises som en tællepille plus højst én aktivitetspille.
- **Hvorfor:** den giver både fuldstændighed og årsag uden en ny farveskala.
- **Gælder for:** alle aggregerede statusser.
- **Anbefaling:** Vedtag som standard? JA

---

## 10. Til Team Lead

1. **Afvigelser fra spec'en, som du skal godkende:**
   - Lead-siden viser højst 100 opkald pr. side med pager (D-F8-7). Spec'en siger "alle opkald". Alle opkald kan stadig nås, bare over flere sider. Download .txt og .zip gælder hele leadet.
   - "Uden lead" er én samlet indgang (spec'en giver designeren valget).
2. **Statusoversættelsen** i §2.3 bygger på Adversus' kendte lead-status-værdier. Product-ai eller engineeren bør bekræfte værdierne mod de rækker, der findes i `adversus_leads`. Ukendte værdier vises rå, så intet går tabt.
3. **JS-ændringer i app-v2.js** (relevant for security-critic):
   - Afspiller, tidsstempler og aktuel boble går fra ét globalt id til pr. sektion.
   - Én afspiller ad gangen.
   - Find søger i flere sektioner og viser "opkald x af y".
   - Fortsat ingen inline JS, og tekst sættes aldrig som HTML.
4. **Retur efter "Transcribér"** fra lead-siden skal valideres som ctx i dag: kun `/lead/{eget lead-id}`.
5. **Ydelse:** samlet status pr. kort, agentliste og første/seneste skal komme fra samme grupperede forespørgsel (AK8). Søgeuddraget kræver det første matchende opkald pr. lead.
6. **Zip-knappen på listen** er uændret (opkald i filtret, højst 2000). Ret mig, hvis ejeren vil have den til at gå pr. lead.
7. **Graphic-design:** kun små visuelle detaljer mangler: avatarstak med kant og "+N", tidslinjens linje og markører (begge temaer) og den stiplede "Uden lead"-flade. Der er ingen nye farver.
8. **Ingen Claude-boks på lead-siden** (spec'en kræver det ikke). Er `get_lead` live, er en linje "Opsummér lead {id}" en oplagt opfølger.

Relevante filer:
- `C:\ClaudeCoworkers\whisper.xanouso.com\product-ai\F008_v1.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\tasks\impact-F008.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\ui-ux\F007_design_v2.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\graphic-design\F007_visual_v2.md`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\UiCallsV2.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\UiV2.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\src\CallPages.php`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app-v2.css`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\public_html\assets\app-v2.js`
- `C:\ClaudeCoworkers\whisper.xanouso.com\homedir\migrations\0009_v0.9.0_adversus_cdr.sql` (`adversus_leads.status`)
