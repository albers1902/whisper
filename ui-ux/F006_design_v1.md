# F006, design v1
# Status: READY_FOR_IMPLEMENTATION

Kilder: `product-ai/F006_v1.md`, `tasks/impact-F006.md`, `ui-ux/F005_design_v1.md`, `graphic-design/F005_visual_v1.md`, `homedir/src/UiPages.php`, `Ui.php`, `View.php`, `App.php` (CSP og login-flow) og `homedir/public_html/assets/app.css`. `DESIGN_LOG.md` og `PRD.md` findes ikke. Briefet bygger derfor på de vedtagne F005-beslutninger D1–D7. Der er ingen afvigelser fra dem.

## 0. Rammer og tre forudsætninger (Team Lead og engineer)
- **CSP som i F005.** Intet inline, alt virker uden JS, og kopiér-knappen er `hidden` indtil `app.js` viser den. Alle sider bruger `Ui::layout`.
- **BLOKERENDE, teknisk: `form-action 'self'` stopper Godkend og Afvis.** CSP'en i `App.php:475` har `form-action 'self'`. Chrome (og nyere Firefox) anvender `form-action` på redirects efter en formular-POST. POST til `/oauth/authorize` efterfulgt af 302 til `https://claude.ai/...` bliver derfor blokeret, og brugeren ser en tom side. Godkendelsessidens svar skal have `form-action 'self' https://<redirect-vært>` med præcis den registrerede origin, og ellers ingen ændring. `frame-ancestors 'none'` og `X-Frame-Options: DENY` skal blive, fordi de beskytter mod clickjacking af Godkend. Security-critic bør se på det.
- **Flagafhængighed.** F006's UI er kun designet i det nye UI (`F005_ui`). Konto-afsnittet findes kun med `F003_mcp`. Anbefaling: `F006_oauth` må kun være tændt, hvor `F005_ui` og `F003_mcp` også er tændt. Det skrives i kommentaren i `features.ini`. I dag er alle tre kun tændt på dev.
- **Med `F006_oauth` slukket** er Konto byte-for-byte som i dag, `/login` har ingen kontekstlinje, og `/oauth/*` giver den almindelige 404-side.

# Page/screen layout

## 1. Login midt i OAuth-flowet (`/login`, eksisterende side)
UX-krav: den ventende OAuth-anmodning skal kunne genfindes efter login, uden at Whispers URL får en `next=`-parameter, man kan sætte til en vilkårlig adresse (open redirect). Engineeren vælger mekanismen, fx at anmodningen gemmes i sessionen.
```
┌ card.narrow.login ─────────────────────────────┐
│ Whisper                                        │
│ Opkald og transcripts fra kundeservice         │
│ Log ind                                    (h1)│
│ ┌ msg.info ─────────────────────────────────┐  │
│ │ ⓘ Log ind for at forbinde Claude til      │  │
│ │   Whisper. Bagefter ser du, hvad Claude   │  │
│ │   får adgang til, før du godkender.       │  │
│ └───────────────────────────────────────────┘  │
│ [fejl: msg.error, som i dag]                   │
│ E-mail [______________]                        │
│ Adgangskode [______________]                   │
│ [          Log ind (full)          ]           │
│ Glemt adgangskode? Kontakt en administrator.   │
│ Vil du ikke forbinde? Luk bare vinduet.        │
└────────────────────────────────────────────────┘
```
- Kontekstbeskeden vises kun, når der ligger en gyldig, ventende anmodning. Den står under H1 og over en eventuel fejl.
- "Claude" i beskeden er afledt af redirect-værten, som er på allowlisten. Klientens selvopgivne navn bruges aldrig her. Er værten ikke en af Claude-værterne (kun `localhost` på DEV), står der "en app" i stedet for "Claude".
- Efter login går man direkte til godkendelsessiden, ikke til `/`.
- Er man allerede logget ind, springes login over, og godkendelsessiden vises med det samme.

## 2. Godkendelsessiden (`GET /oauth/authorize`, gyldig anmodning, logget ind)
`card.narrow` (480 px) i den normale ramme med header og nav, så det er tydeligt, at man er i Whisper. `section` = `''`.
```
┌ card.narrow ───────────────────────────────────┐
│ Giv adgang til Whisper                     (h1)│
│ «Claude» vil have læseadgang til dine opkald   │
│ og transcripts i Whisper.                      │
│                                                │
│ dl.meta                                        │
│  App                 «Claude»                  │
│                      Navnet oplyses af appen   │
│                      selv. (hint)              │
│  Sender dig til      claude.ai   (code, fed)   │
│  Logget ind som      Mette Hansen ·            │
│                      mette@…                   │
│                                                │
│ Appen kan                                  (h2)│
│  • søge i dine opkald og transcripts           │
│  • læse transcripts og opkaldsoplysninger      │
│    (dato, kundens nummer, agent og ticket)     │
│ Appen kan ikke ændre, slette eller oprette     │
│ noget i Whisper.                               │
│                                                │
│ Godkend kun, hvis du selv har bedt Claude om   │
│ at forbinde til Whisper lige nu.        (hint) │
│                                                │
│ form.actions (POST, CSRF)                      │
│ [ Godkend ]  [ Afvis ]                         │
│                                                │
│ Adgangen gælder, indtil du tilbagekalder den   │
│ under Konto → Forbundne apps. Ikke dig?        │
│ Log ud (button.link i logout-form).     (hint) │
│ Kombinér ikke Whisper med værktøjer, der kan   │
│ sende mails eller ændre data, i samme          │
│ Claude-samtale.                         (hint) │
└────────────────────────────────────────────────┘
```
**Phishing-værn:**
- **Klientnavnet er ikke-betroet tekst.**
  - Det escapes altid, og ved registrering eller visning fjernes kontroltegn og Unicode-formattegn (kategori Cc og Cf, herunder bidi-overrides som U+202E).
  - Whitespace samles til ét mellemrum, og navnet afkortes til 60 tegn med "…".
  - Det står altid i «» og efter etiketten "App" sammen med hint-linjen "Navnet oplyses af appen selv."
  - Det bliver aldrig et link, og det kommer aldrig i `<title>` eller H1.
  - Tomt navn vises som "En unavngivet app" uden «».
- **Redirect-værten** vises som hostname alene, uden sti eller query, i ASCII/punycode (aldrig Unicode, så homografer ikke kan snyde). Den står i `<code>` i fed skrift.
- **Vært uden for Claude-listen** (kun DEV, `localhost`): over formularen vises `msg.warn` "Appen sender dig til localhost. Det er kun tilladt på udviklingsserveren." Denne linje findes ikke i produktion, fordi allowlisten forhindrer den slags værter dér.
- **"Logget ind som"** viser, hvilken Whisper-konto der giver adgang. På delte maskiner er det vigtigt.
- **Ingen `autofocus` på Godkend**, så et Enter-tryk ikke godkender ved et uheld.
- **Godkendelse vises hver gang.** Der er ingen tavs auto-godkendelse, heller ikke for en klient, der er godkendt før.

## 3. Konto (`/konto`, med `F006_oauth` og `F003_mcp` tændt)
Rækkefølgen i `.card.medium`: H1 "Konto", flash, `dl.meta` (Navn, E-mail, Rolle), sektionen "Adgangskode" (uændret) og til sidst sektionen "Forbind Claude", som erstatter "Claude Desktop (MCP)".
```
Forbind Claude                                                    (h2)
Forbind Claude til Whisper, så Claude kan søge i og læse dine
transcripts. Claude får kun læseadgang.

Tilføj Whisper i Claude                                           (h3)
1. Åbn Claude (claude.ai eller Claude Desktop) → Indstillinger
   → Connectors.
2. Vælg "Tilføj custom connector". Skriv Whisper som navn, og indsæt
   URL'en:
   [https://whisper.xanouso.com/mcp (readonly, mono)] [⧉ Kopiér URL]
3. Vælg "Tilføj" og derefter "Forbind". Whisper åbner i et vindue:
   log ind, og vælg Godkend.
4. Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning."
Du skal ikke bruge en nøgle, Node.js eller genstarte Claude.     (hint)
Bruger I Claude Team eller Enterprise, kan det være en ejer af
organisationen, der skal tilføje connectoren.                    (hint)
⚠ msg.warn: Kombinér ikke Whisper med værktøjer, der kan sende mails
  eller ændre data, i samme Claude-samtale.

Forbundne apps (2)                                                (h3)
┌ table.keys ──────────────────────────────────────────────────────┐
│ App          │ Forbundet         │ Sidst brugt    │              │
│ Claude       │ 12.11.2026 09:14  │ For 3 min. siden│[Tilbagekald]│
│ Claude       │ 02.11.2026 15:40  │ Aldrig         │[Tilbagekald]│
└──────────────────────────────────────────────────────────────────┘

▸ Avanceret: nøgle til Claude Desktop via mcp-remote (1 aktiv nøgle)
  (details.advanced. Indeholder HELE den nuværende F003-sektion uændret:
   ok-kortet med den nye nøgle, intro-linjerne, "Dine nøgler (n af 5)",
   "Opret ny nøgle", genindlæs-hint og "Request headers"-hint)
```
- **Trinnene** er en `ol.steps`. Menupunkternes navne verificeres af engineeren i Claude's aktuelle UI, ligesom i F005 §3.6. Er Claude's UI på engelsk, skrives den engelske betegnelse i parentes: Indstillinger (Settings), "Tilføj custom connector" (Add custom connector).
- **URL-feltet** er det eksisterende `.secret`-mønster med `input#connector-url` readonly og `Ui::copyButton('connector-url', 'Kopiér URL')`. Værdien er `Server::siteOrigin() . '/mcp'`.
- **Mangler origin** (tom streng): feltet og kopiér-knappen vises ikke. I stedet vises `msg.info` "Whispers adresse er ikke sat op. Kontakt en administrator." Pladsholderen `https://<din-whisper-adresse>` må ikke kunne kopieres.
- **Forbundne apps** genbruger `table.keys` med samme mobilkort og `data-label`. Der er én række pr. aktiv bevilling (klient og bruger med en kæde, der hverken er tilbagekaldt eller udløbet). Rækkerne er sorteret efter Forbundet, nyeste først. Navnet renses som i afsnit 2 og vises med `class="ellipsis" title="…"`. "Sidst brugt" vises som relativ tid med `title` som i dag, og "Aldrig" uden brug.
- **Tilbagekald** følger D7: én `form` pr. række (POST, CSRF) med `.button.danger-secondary.small` og `aria-label="Tilbagekald adgangen for «navn», forbundet 12.11.2026 09:14"`. Datoen er med, fordi flere rækker kan hedde "Claude". Der er ingen bekræftelsesside, og man redirectes til `/konto` med flash.
- **Avanceret** er et native `<details class="advanced">` med `<summary>`. Det er lukket som standard, men står åbent (`open`), når mindst én af disse er sand:
  - en nøgle er netop oprettet (POST-svaret),
  - nøgle-formularen har en fejl,
  - flashen handler om en nøgle.
  Summary viser antallet af aktive nøgler: "(1 aktiv nøgle)" eller "(n aktive nøgler)". Uden nøgler vises intet tal. Så kan brugere med gamle nøgler se, at de findes, og tilbagekalde dem.
- `MCP_WARNING` står nu i den primære del (afsnit 3) og gentages ikke inde i Avanceret.

## 4. Fejl- og afbrudte tilstande
Alle bruger det eksisterende `UiPages::error`-mønster (`card.narrow`, H1, tekst og `div.actions` med "Til opkald" eller "Til login"). Mønstret skal kunne tage en titel, og der skal kunne være en `<details>` med den tekniske besked (D4).

| # | Situation | Hvad brugeren ser | HTTP |
|---|---|---|---|
| E1 | `client_id` ukendt, `redirect_uri` ikke registreret eller ikke på allowlisten, ugyldig `resource` | Side, ingen redirect: H1 "Forbindelsen kan ikke oprettes", tekst "Anmodningen fra appen er ugyldig, så Whisper sender dig ikke videre. Gå tilbage til Claude, fjern Whisper-connectoren, og tilføj den igen.", `<details><summary>Teknisk besked</summary>` med en fast kode (`unknown_client`, `invalid_redirect_uri`, `invalid_resource`). Klientens værdier (redirect_uri, navn) gengives ALDRIG. | 400 |
| E2 | Andre protokolfejl med gyldig klient og redirect (mangler `code_challenge`, ikke-S256, forkert `response_type`) | Redirect med `error=invalid_request` og `state` efter specifikationen. Claude viser fejlen. Er engineeren i tvivl om, hvorvidt redirect er tilladt, bruges E1-siden (`invalid_request`). | 302 / 400 |
| E3 | Afvis | Ingen side i Whisper. 302 til redirect_uri med `error=access_denied` og `state`. Claude viser sin egen besked. | 302 |
| E4 | Udløbet: CSRF-fejl, den ventende anmodning er væk (sessionen udløbet, logget ud i en anden fane) eller allerede brugt (dobbeltklik, genindsend efter refresh, tilbage-knap efter Godkend) | Side: H1 "Forbindelsen er udløbet", tekst "Siden har været åben for længe, eller anmodningen er allerede brugt. Gå tilbage til Claude, og vælg Forbind igen." | 400 |
| E5 | `F006_oauth` slukket | Den almindelige 404-side. | 404 |
| E6 | Login spærret (F001-throttling) | Login-siden med den eksisterende `LOCKED`-besked. Kontekstbeskeden bliver stående. | 429 |
| E7 | Brugeren er deaktiveret mellem login og Godkend | Sessionen dør som i dag, og man kommer til login. Anmodningen er væk, så et senere forsøg giver E4. | 302 |

# Components
| Komponent | Brug | NY/EKSISTERENDE |
|---|---|---|
| `Ui::layout`, `.card.narrow`, `.card.medium` | alle sider | EKSISTERENDE |
| `Ui::msg('info')` | kontekstlinjen på login, manglende origin | EKSISTERENDE |
| `Ui::msg('warn')` | prompt-injection-advarsel, localhost-advarsel | EKSISTERENDE |
| `dl.meta` | App / Sender dig til / Logget ind som | EKSISTERENDE |
| `<ul>` uden ikoner | "Appen kan" | EKSISTERENDE (standardpunkter) |
| `form.actions` + `button` (primær) + `button.secondary` | Godkend / Afvis, to submit-knapper med `name="beslutning"` og `value="godkend|afvis"` i én form | EKSISTERENDE |
| `ol.steps` | vejledningen "Tilføj Whisper i Claude" | EKSISTERENDE |
| `.secret` + `Ui::copyButton` | connector-URL | EKSISTERENDE |
| `table.keys` + `.table-wrap` + `.button.danger-secondary.small` | Forbundne apps | EKSISTERENDE |
| `UiPages::error` med titel og teknisk `<details>` | E1, E4 | EKSISTERENDE (udvides med en parameter) |
| `<details class="advanced">` | Avanceret: mcp-remote | **NY stil, sendes til graphic-design** (afsnittet "Til graphic-design") |
| `button.link` i logout-form | "Log ud" i "Ikke dig?" | EKSISTERENDE |

Der kommer ingen nye ikoner og ingen nye farver.

# States
**Login med kontekst**
- Default som beskrevet.
- Fejl: som i dag (`role="alert"`, fokus på e-mail), og kontekstlinjen bliver stående.
- Tvunget skift af adgangskode: login sender som i dag til `/konto`. Der viser info-banneret en ekstra linje: "Når du har valgt en ny adgangskode, fortsætter du med at forbinde Claude." Efter skiftet går man til godkendelsessiden, hvis anmodningen stadig gælder, og ellers til `/`. **BØR, sendes til Team Lead (ikke i spec'en).** Uden det må brugeren starte forfra i Claude.

**Godkendelsessiden**
- Default som i afsnit 2.
- Loading: der er ingen. Knapperne er ikke deaktiverede. Et dobbeltklik fanges af serveren (koden er engangs), og andet klik ender i E4.
- Disabled: ingen.
- Delvise data: tomt klientnavn vises som "En unavngivet app", og et langt navn afkortes med "…".
- Success: Whisper viser ingen side. 302 sender videre til Claude, som viser "Forbundet".

**Forbundne apps**
- Tom: `p.muted` "Ingen apps er forbundet endnu. Når du godkender Claude, står forbindelsen her."
- Én eller flere: tabellen. Der er intet loft, og listen pagineres ikke. Bevillinger udløber efter 30 dage uden brug, så listen bliver ikke lang.
- Success ved tilbagekald: flash `msg.ok` "Adgangen for «navn» er tilbagekaldt. Appen kan ikke længere læse dine transcripts i Whisper."
- Fejl ved tilbagekald: allerede tilbagekaldt eller ukendt id giver flash `msg.error` "Forbindelsen er allerede tilbagekaldt." Et fremmed id afvises af serveren som de andre ejerskabstjek.

**Connector-URL:** default er feltet med kopiér-knappen. Uden JS er feltet readonly og kan markeres. Uden origin vises info-beskeden i stedet.

**Avanceret:** lukket, eller åben efter reglerne i afsnit 3. Indholdet har F003's tilstande uændret.

**Ny adgangskode og fjernet bruger (eksisterende flash-tekster opdateres, når `F006_oauth` er tændt)**
- `KEYS_REVOKED_SELF` bliver "Dine forbundne apps og MCP-nøgler er tilbagekaldt. Forbind Claude igen her, hvis du stadig skal bruge det." Den vises kun, når mindst én ting faktisk blev tilbagekaldt, som i dag.
- `KEYS_REVOKED_RESET` bliver "Brugerens forbundne apps og MCP-nøgler er tilbagekaldt."
- På bekræftelsessiden for "Deaktiver bruger" tilføjes sætningen "Brugerens forbundne apps og nøgler holder op med at virke." (BØR)

# User flow
**Happy path (ikke logget ind):**
1. I Claude: Indstillinger → Connectors → Tilføj custom connector → URL → Forbind.
2. Claude åbner Whisper `/oauth/authorize` i en browserfane eller et popup-vindue. Whisper gemmer anmodningen og viser login med kontekstlinjen.
3. Brugeren logger ind og kommer til godkendelsessiden.
4. Brugeren vælger Godkend. Det giver POST og 302 til Claude's callback, og Claude viser "Forbundet".
5. Konto → Forbundne apps viser nu rækken.

**Allerede logget ind:** trin 2 springer login over og går direkte til trin 3.

**Afvis:** 302 med `access_denied`. Der laves ingen bevilling, og hændelsen skrives i AccessLog.

**Fejlvejen:** E1 og E4 fortæller brugeren, at han skal tilbage til Claude. Whisper kan ikke selv genstarte flowet, fordi kun Claude har `state` og PKCE.

**Tilbage og refresh:**
- Refresh på godkendelsessiden (GET) viser den samme side igen.
- Refresh efter Godkend (genindsend POST) eller tilbage-knap og Godkend igen giver E4.
- Godkendelsessiden og E-siderne sendes med `Cache-Control: no-store`.

**Flere faner:** Er man logget ud i en anden fane, giver Godkend E4. Godkender man i den ene af to faner med hver sin anmodning, giver det to bevillinger. Det er acceptabelt, og begge står i listen.

**Genforbindelse:** Hver ny forbindelse giver en ny række. Den gamle står, til den udløber eller tilbagekaldes. Kolonnen "Forbundet" skiller dem ad.

**Kanttilfælde:**
- Et klientnavn på 200 tegn afkortes til 60.
- RTL- og bidi-tegn fjernes.
- En vært med punycode vises i ASCII.
- Ved 320 px er alt i én kolonne (se Responsive).

# Form design
**Godkendelsesformularen**
- Én `form method="post" action="/oauth/authorize"` med CSRF og de skjulte felter, engineeren skal bruge, eller en reference til den ventende anmodning. Ingen inputfelter.
- To submit-knapper: Godkend (primær) og Afvis (`secondary`). Godkend står først, som i det eksisterende bekræftelsesmønster.
- Ingen validering på klienten, ingen deaktivering og ingen `autofocus`.

**Login:** uændret.

**Tilbagekald:** én form pr. række, ingen bekræftelse (D7).

**Konto:** der kommer ingen nye felter.

# Responsive behavior
- **Godkendelse og login** (`card.narrow`): Claude åbner ofte et popup-vindue på 400–500 px, og mobilappen bruger en indbygget browser. Siden skal derfor virke ned til 320 px uden vandret scroll.
  - `dl.meta` stables (den eksisterende auto-fill-grid giver én kolonne under ca. 360 px).
  - Værten og navnet bruger `overflow-wrap: anywhere` (findes allerede på `dl.meta dd`).
  - Under 400 px står Godkend og Afvis i fuld bredde under hinanden (den eksisterende regel for `form.actions > *`), med Godkend øverst.
  - Touch-mål er mindst 44 px (den eksisterende regel under 640 px).
- **Konto:**
  - `.secret` stables under 400 px (eksisterende regel), så Kopiér URL står under feltet.
  - `table.keys` bliver til kort med `data-label` (App, Forbundet, Sidst brugt) under 640 px, som nøgletabellen i dag.
  - `summary` i Avanceret skal have en klikflade på mindst 44 px i højden på touch.
- Der er ingen swipe-bevægelser.

# Copy / microcopy
Appen har ingen sprogfil (F005 §5). Teksterne lægges som konstanter i den nye OAuth-klasse eller i UiPages. Nøglerne herunder er kun navneforslag.

| Nøgle | Tekst |
|---|---|
| `oauth.login.context` | Log ind for at forbinde Claude til Whisper. Bagefter ser du, hvad Claude får adgang til, før du godkender. |
| `oauth.login.context_generic` | Log ind for at forbinde en app til Whisper. Bagefter ser du, hvad appen får adgang til, før du godkender. |
| `oauth.login.close` | Vil du ikke forbinde? Luk bare vinduet. |
| `oauth.consent.title` (`<title>` og H1) | Giv adgang til Whisper |
| `oauth.consent.lead` | «{navn}» vil have læseadgang til dine opkald og transcripts i Whisper. |
| `oauth.consent.app` / `.app_hint` | App / Navnet oplyses af appen selv. |
| `oauth.consent.unnamed` | En unavngivet app |
| `oauth.consent.redirect` | Sender dig til |
| `oauth.consent.user` | Logget ind som |
| `oauth.consent.can_h` | Appen kan |
| `oauth.consent.can_1` | søge i dine opkald og transcripts |
| `oauth.consent.can_2` | læse transcripts og opkaldsoplysninger (dato, kundens nummer, agent og ticket) |
| `oauth.consent.cannot` | Appen kan ikke ændre, slette eller oprette noget i Whisper. |
| `oauth.consent.phishing` | Godkend kun, hvis du selv har bedt Claude om at forbinde til Whisper lige nu. |
| `oauth.consent.approve` / `.deny` | Godkend / Afvis |
| `oauth.consent.duration` | Adgangen gælder, indtil du tilbagekalder den under Konto → Forbundne apps. |
| `oauth.consent.not_you` | Ikke dig? + knappen "Log ud" |
| `oauth.consent.localhost` | Appen sender dig til localhost. Det er kun tilladt på udviklingsserveren. |
| `mcp.warning` (ny ordlyd i den primære del) | Kombinér ikke Whisper med værktøjer, der kan sende mails eller ændre data, i samme Claude-samtale. |
| `oauth.err.invalid.title` | Forbindelsen kan ikke oprettes |
| `oauth.err.invalid.text` | Anmodningen fra appen er ugyldig, så Whisper sender dig ikke videre. Gå tilbage til Claude, fjern Whisper-connectoren, og tilføj den igen. |
| `oauth.err.expired.title` | Forbindelsen er udløbet |
| `oauth.err.expired.text` | Siden har været åben for længe, eller anmodningen er allerede brugt. Gå tilbage til Claude, og vælg Forbind igen. |
| `oauth.err.details` | Teknisk besked |
| `konto.claude.h2` | Forbind Claude |
| `konto.claude.intro` | Forbind Claude til Whisper, så Claude kan søge i og læse dine transcripts. Claude får kun læseadgang. |
| `konto.claude.add_h3` | Tilføj Whisper i Claude |
| `konto.claude.step1` | Åbn Claude (claude.ai eller Claude Desktop) → Indstillinger → Connectors. |
| `konto.claude.step2` | Vælg "Tilføj custom connector". Skriv Whisper som navn, og indsæt URL'en: |
| `konto.claude.url_label` (aria-label) | Whispers connector-URL |
| `konto.claude.copy` | Kopiér URL |
| `konto.claude.step3` | Vælg "Tilføj" og derefter "Forbind". Whisper åbner i et vindue: log ind, og vælg Godkend. |
| `konto.claude.step4` | Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning." |
| `konto.claude.nokey` | Du skal ikke bruge en nøgle, Node.js eller genstarte Claude. |
| `konto.claude.team` | Bruger I Claude Team eller Enterprise, kan det være en ejer af organisationen, der skal tilføje connectoren. |
| `konto.claude.no_origin` | Whispers adresse er ikke sat op. Kontakt en administrator. |
| `konto.apps.h3` | Forbundne apps ({n}) |
| `konto.apps.cols` | App · Forbundet · Sidst brugt · (skjult: Handling) |
| `konto.apps.empty` | Ingen apps er forbundet endnu. Når du godkender Claude, står forbindelsen her. |
| `konto.apps.revoke` | Tilbagekald |
| `konto.apps.revoke_aria` | Tilbagekald adgangen for «{navn}», forbundet {dato} |
| `konto.apps.revoked` | Adgangen for «{navn}» er tilbagekaldt. Appen kan ikke længere læse dine transcripts i Whisper. |
| `konto.apps.already` | Forbindelsen er allerede tilbagekaldt. |
| `konto.apps.caption` (sr-only) | Forbundne apps |
| `konto.advanced.summary` | Avanceret: nøgle til Claude Desktop via mcp-remote |
| `konto.advanced.count` | (1 aktiv nøgle) / ({n} aktive nøgler) |
| `KEYS_REVOKED_SELF` (F006 tændt) | Dine forbundne apps og MCP-nøgler er tilbagekaldt. Forbind Claude igen her, hvis du stadig skal bruge det. |
| `KEYS_REVOKED_RESET` (F006 tændt) | Brugerens forbundne apps og MCP-nøgler er tilbagekaldt. |
| `confirm.deactivate.extra` | Brugerens forbundne apps og nøgler holder op med at virke. |
| `konto.forced.oauth` | Når du har valgt en ny adgangskode, fortsætter du med at forbinde Claude. |

Menupunkternes navne i Claude ("Indstillinger", "Connectors", "Tilføj custom connector", "Tilføj", "Forbind") verificeres af engineeren og rettes til Claude's faktiske ordlyd.

# Accessibility
- **Fokusrækkefølge på godkendelsessiden:** skip-link → header → H1 (`main` får fokus via skip-link) → "Log ud" i "Ikke dig?" står efter knapperne i DOM-rækkefølgen → Godkend → Afvis. Altså: H1, lead, dl, liste, Godkend, Afvis, Log ud.
- **Ingen `autofocus`** på godkendelsessiden. På login beholdes `autofocus` på e-mail som i dag.
- **`<title>`:** "Giv adgang til Whisper · Whisper" og "Forbindelsen er udløbet · Whisper". Klientnavnet kommer aldrig i titlen.
- **Klientnavnet** står i `<strong>`. «» er tekst og læses op, og det er godt, fordi de markerer, at det er appens eget navn.
- **Værten** står i `<code>`, så den læses tegn for tegn i de fleste skærmlæsere.
- **Tabellen** har `<caption class="sr-only">Forbundne apps</caption>`, `th scope="col"` og en skjult header "Handling". Tilbagekald-knapperne har unikke `aria-label` med navn og dato.
- **`details.advanced`:**
  - Det er native, så Enter og mellemrum folder ud uden JS.
  - Teksten i `summary` er hele etiketten.
  - Indholdets H3'er ("Dine nøgler", "Opret ny nøgle") bliver stående, så overskriftsstrukturen er H2 → H3 → H3 → (summary) → H3.
  - Summary skal være visuelt tydelig som en interaktiv kontrol (fokusring som alle andre).
- **Kopiér URL** bruger den eksisterende live-region `#live`: "Kopieret til udklipsholderen."
- **Kontrast:** kun eksisterende tokens, så AA er opfyldt i begge temaer. Fejl- og advarselsbeskeder har altid ikon og tekst, aldrig kun farve.
- **Mål:** mindst 24×24, og 44 px på touch.

# Design decisions
1. **Login genbruges, og der kommer ikke en separat OAuth-login.** Det giver ét sted til throttling og fejltekster. Kontekstlinjen bygger på værten fra allowlisten, ikke på det ikke-betroede navn, så en angriber ikke kan få sin egen tekst på Whispers login.
2. **Godkendelsessiden står i den normale ramme med header.** Når man genkender Whisper, giver det tillid, og "Logget ind som" plus "Log ud" dækker delte maskiner.
3. **Tre lag mod phishing:**
   - navnet mærkes som selvopgivet og renses,
   - værten vises i ASCII og i fed skrift,
   - linjen "Godkend kun, hvis du selv har bedt …" står der.
   Der er ingen autofocus og ingen auto-godkendelse.
4. **Ugyldig klient eller redirect giver en side og aldrig en redirect**, og klientens værdier gengives ikke på siden. Det følger OAuth-reglen og D4: den tekniske kode ligger i `<details>`.
5. **Forbundne apps genbruger nøgletabellen og D7** (tilbagekald uden bekræftelse). Det er velkendt for brugeren, og et fejlklik kan rettes ved at forbinde igen.
6. **mcp-remote flyttes ind i et lukket `<details>`** og fjernes ikke. Det åbnes automatisk, når det er relevant, og summary viser antallet af nøgler, så ingen glemmer nøgler, der stadig virker.
7. **Ingen nye ikoner eller farver.** Den eneste nye stil er `details.advanced`.

## Nye design-beslutninger i dette brief

### D8: OAuth-godkendelse viser selvopgivne navne som ikke-betroede
- **Hvad:** Tekst, en tredjepart selv har opgivet, vises i «», med etiketten "oplyses af appen selv". Den renses for kontrol- og bidi-tegn, afkortes og kommer aldrig i title eller H1. Destinationsværten vises i ASCII.
- **Hvorfor:** Det beskytter mod phishing og spoofing på en side, der giver adgang.
- **Gælder for:** alle fremtidige features, der viser tredjepartsnavne.
- **Anbefaling:** Vedtag som standard? JA

### D9: Avancerede eller forældede vejledninger ligger i `<details class="advanced">`
- **Hvad:** Den sekundære vej ligger i et native `details`. Det åbnes automatisk, når der netop er sket noget eller er en fejl derinde, og summary viser en tæller over ting, der stadig er aktive.
- **Hvorfor:** Den primære vej skal være tydelig, uden at eksisterende brugere mister adgangen til deres nøgler.
- **Gælder for:** alle fremtidige features.
- **Anbefaling:** Vedtag som standard? JA

### D10: Fejl i tredjepartsflows fortæller, hvor man skal starte forfra
- **Hvad:** Fejlsider midt i et flow, som en anden app har startet, siger altid "Gå tilbage til <app>, og …" i stedet for kun "Til opkald".
- **Hvorfor:** Whisper kan ikke selv genstarte flowet.
- **Anbefaling:** Vedtag som standard? JA

## Til graphic-design
1. **`details.advanced`** er en ny stil, fordi der i dag kun findes `.msg details` og `.meta-details`, der kun gælder på mobil. Ønsket:
   - Den skal ligne et kort: `--surface`, ramme `--border`, `--radius-md` og padding 12×16.
   - `summary` i 16 px/600 med den indbyggede markør og `cursor: pointer`.
   - Mindst 24 px høj (44 px på touch) og med den normale fokusring.
   - Hover må kun skifte baggrund til `--surface-2`.
   - Ingen nye ikoner.
2. **Godkendelsessiden:** bekræft, at `dl.meta` i `card.narrow` (auto-fill 160 px) står pænt med tre par, og at værten i `<code>` med `font-weight: 600` er tydelig nok. Skal værten have sin egen baggrund (fx `--surface-2`, som `code` har i forvejen)?
3. **`<ul>` "Appen kan":** standardpunkter, eller kræver det en lille stil til afstand inde i et kort?

## Til Team Lead
- **BLOKERENDE, teknisk:** `form-action 'self'` i CSP'en blokerer redirecten efter Godkend og Afvis i Chrome (§0). Det skal være i engineerens opgave og i security-critics tjekliste.
- **Flagafhængighed:** `F006_oauth` forudsætter `F005_ui` og `F003_mcp` (§0).
- **Ikke i spec'en, foreslået som BØR:**
  - fortsæt OAuth-flowet efter et tvunget skift af adgangskode,
  - opdatér flash-teksterne `KEYS_REVOKED_*`,
  - tilføj sætningen på deaktiver-bekræftelsen.
- **Fejlside-mønstret:** `UiPages::error` skal kunne tage en egen titel og en teknisk `<details>`. Det er en lille udvidelse, ikke en ny komponent.
- **Engineeren verificerer**, at Claude's menupunkter og knaptekster svarer til trinnene i vejledningen, og at de "Claude-værter", der bestemmer kontekstteksten, er de samme som standarden i `[oauth] redirect_hosts`.
