# F006, design review v1
Branch: feature/F006-oauth @ a68471d29a14e23f3af73d4721264e80b962617f
# Status: APPROVED

Grundlag: `ui-ux/F006_design_v1.md` med DECISIONS #48 (Konto viser kun "Forbind Claude"; "Avanceret", `details.advanced` og D9 udgår) og `graphic-design/F006_visual_v1.md` (`code.host` får sin egen `--surface-2`). Jeg har læst diffen `origin/main...origin/feature/F006-oauth` i `App.php`, `UiPages.php`, `View.php`, `OAuth/Authorize.php`, `OAuth/OAuth.php`, `OAuth/Grants.php`, `Users.php`, `public_html/index.php` og `app.css`. `app.js` er uændret, og det er korrekt, fordi `Ui::copyButton` allerede findes.

Der er ingen blokerende fund. Implementationen følger briefet tæt. Der er tre NON-BLOCKING-bemærkninger nederst. De kan accepteres med den begrundelse, der står ved hver.

## Gennemgang

**Login-kontekst (§1, E6)**
- `msg.info` står under H1 og over fejlen, og "Vil du ikke forbinde? Luk bare vinduet." står nederst. Begge vises kun, når der er en gyldig, ventende anmodning (`Authorize::pending`, 30 min.).
- Teksten afhænger af værten (`isClaudeHost`: claude.ai/claude.com) og ikke af klientnavnet. Ved andre værter står der "en app".
- Konteksten står også ved BAD_LOGIN og ved LOCKED (429), som E6 kræver.
- Anmodningen ligger i sessionen og ikke i en `next=`. Det er kun den, der overlever `clear()`/`regenerate()` ved login. Efter login går man til `/oauth/authorize`, og er man allerede logget ind, ser man godkendelsessiden med det samme.

**Godkendelsessiden og phishing-værn (§2, D8)**
- Siden står i den normale ramme med header og nav. Den bruger `card narrow consent`. `<title>` og H1 er "Giv adgang til Whisper", og klientnavnet står aldrig i title eller H1.
- Navnet renses i `cleanName`: Cc, Cf, Zl og Zp fjernes, whitespace samles, og `displayName` afkorter til 60 tegn med "…". Navnet escapes, står i `<strong>«…»</strong>` og har hint-linjen "Navnet oplyses af appen selv." Det er aldrig et link.
- Værten vises som hostname alene i `code.host`. Registreringen afviser ikke-ASCII, så værten er altid ASCII.
- "Logget ind som" viser navn · e-mail. "Appen kan" er en liste med to punkter, og linjen "kan ikke …" og phishing-hintet følger efter.
- localhost-advarslen vises kun på DEV og kun for en vært uden for Claude-listen. Den står over formularen.
- Formularen er én form med CSRF og et engangs-`rid`. Godkend er primær og står først, Afvis er `secondary`, og ingen af dem har `autofocus`. Varigheds-hintet står efter, og så "Ikke dig? Log ud" som en `div.hint` med en inline logout-form (korrekt: en form må ikke stå i et `p`). Prompt-injection-hintet står sidst.
- Fokusrækkefølgen er H1 → … → Godkend → Afvis → Log ud.
- Der er ingen auto-godkendelse.
- CSP `form-action` udvides kun med den registrerede redirect-origin på siden og på redirecten. `frame-ancestors 'none'` og DENY er bevaret.
- Det visuelle brief er fulgt: `.consent strong` 600, `dl.meta.single`, `code.host` (1em/600, ramme, `box-decoration-break`), liste-rytme, `form.actions` 16/24 px, `.hint + .hint` 8 px. Desuden slår `.consent .hint button.link` (0,3,1) den globale 44 px-regel under 640 px.

**Konto "Forbind Claude" og Forbundne apps (§3 efter #48)**
- Der er kun sektionen "Forbind Claude": intro, H3 og `ol.steps` med de verificerede menunavne (Customize → Connectors, Add → "Add custom connector", Continue → Add → Connect), connector-URL'en i `.secret` med `Kopiér URL`, nøgle-hintet, Team/Enterprise-hintet og `msg.warn` med `MCP_WARNING`.
- Uden origin vises `msg.info`, og pladsholderen kan ikke kopieres.
- Teksterne afviger fra briefets danske pladsholdere, fordi engineeren har rettet dem til Claudes faktiske ordlyd, som briefet bad om. Team-hintet er gjort mere præcist (Organization settings → Connectors, derefter selv Connect). Det accepteres.
- "Forbundne apps (n)" bruger `table.keys` med sr-only caption, `th scope="col"`, en skjult "Handling" og `data-label` til mobilkortene.
- Navnet bruger `ellipsis` + `title`. "Sidst brugt" er relativ tid med `title`, ellers "Aldrig". Rækkerne er sorteret nyeste først.
- Tilbagekald er én POST-form pr. række med `danger-secondary small` og en unik `aria-label` med navn og dato. Der er ingen bekræftelse (D7).
- Tom liste giver `p.muted` med briefets tekst.
- Success-flashen ved tilbagekald og `flash_error` "Forbindelsen er allerede tilbagekaldt." matcher briefet. Konto-GET læser nu `flash_error`.
- Med flaget slukket går `account()` til den uændrede `mcp()`-sektion, så det gamle UI er uændret.

**Fejlsider E1–E7 og D10**
- `UiPages::error` har fået `$title` og `$details` (`<details><summary>Teknisk besked</summary>` med en fast kode). Klientens værdier gengives aldrig.
- E1: `unknown_client`, `invalid_redirect_uri`, `invalid_resource` og `invalid_host` giver en side med 400 og aldrig en redirect.
- E2: fejl går først til redirect_uri, når den er bevist, og har `state` og `iss`.
- E3: Afvis giver `access_denied` og en AccessLog-linje.
- E4: CSRF, manglende eller brugt anmodning og forkert `rid` giver "Forbindelsen er udløbet" med 400. Anmodningen fjernes før Godkend, så et dobbeltklik giver E4.
- E5: flaget slukket giver 404.
- E7: en POST uden bruger går til `/login`.
- Begge E-tekster siger "Gå tilbage til Claude …" (D10).
- `Cache-Control: no-store` kommer fra `secure()` på alle svar.

**BØR-punkterne**
- Fortsæt efter tvunget skift er implementeret:
  - Login går til `/konto`.
  - Info-banneret får den ekstra linje (`resume`), når der ligger en anmodning.
  - Efter skiftet går man til `/oauth/authorize`.
  - GET og POST på authorize sender en bruger med `must_change_password` til `/konto`.
- Flash-tekster: `APPS_REVOKED_SELF` og `APPS_REVOKED_RESET` bruges med flaget tændt. "og MCP-nøgler" er udeladt, hvilket er konsekvent med #48.
- Deaktiveringssætningen "Brugerens forbundne apps holder op med at virke." vises kun med flaget tændt. "og nøgler" er udeladt efter #48, og det accepteres.

**Copy, tilgængelighed og responsivt**
- Al copy er dansk og ligger som konstanter (appen har ingen sprogfil, F005 §5).
- Ingen nye ikoner eller farver.
- 320 px: `dl.meta.single` står i én kolonne, `overflow-wrap: anywhere` gælder på `dd`, knapperne er i fuld bredde under 400 px med Godkend øverst, og touch-målene er 44 px under 640 px.
- På Konto stables `.secret`, og tabellen bliver til kort.

# Findings (NON-BLOCKING)

## Finding 1
Severity: NON-BLOCKING
Element: Godkendelsessiden, tomt klientnavn (`UiPages::consent`, `$named`)
Expected: Det visuelle brief (Visual states) siger, at "En unavngivet app" vises uden «» i almindelig vægt og ikke i `<strong>`, fordi det ikke er et navn fra appen.
Actual: `'<strong>En unavngivet app</strong>'` både i lead og i `dd`. «» udelades korrekt.
Fix: Fjern `<strong>` om "En unavngivet app". Det kan accepteres som det er, fordi DCR kræver et navn i praksis og tilfældet er sjældent.

## Finding 2
Severity: NON-BLOCKING
Element: Flash ved skift eller nulstilling af adgangskode med `F006_oauth` tændt (`App::accountPost`, `userConfirm` reset)
Expected: Flashen vises kun, når en forbundet app faktisk blev tilbagekaldt.
Actual: `$revoked = Tokens::revokeAll + Grants::revokeAll`. En bruger, der kun har gamle F003-nøgler (som er usynlige og virkningsløse med flaget tændt, #48), får "Dine forbundne apps er tilbagekaldt", selv om listen var tom.
Fix: Med flaget tændt skal tekst-betingelsen kun tælle `Grants::revokeAll`. Det kan accepteres midlertidigt, fordi teksten er harmløs og kun rammer gamle nøgle-brugere én gang.

## Finding 3
Severity: NON-BLOCKING
Element: Tvunget skift → fortsæt til godkendelse (`App::accountPost`)
Expected: Brugeren får bekræftet, at adgangskoden er skiftet (det gør Konto i dag).
Actual: Flashen "Adgangskoden er skiftet." sættes, men man redirectes til `/oauth/authorize`, som ikke viser flash. Brugeren ser derfor ingen bekræftelse i popup-vinduet. Flashen bliver i stedet stående og dukker op ved næste besøg i Whisper, ude af kontekst.
Fix: Vis flashen som `msg.ok` øverst på godkendelsessiden (og forbrug den dér), eller lad være med at sætte den, når man fortsætter til godkendelse. Det kan accepteres, fordi flowet virker og beskeden er sand, bare forsinket.

## Til Team Lead
- D8 og D10 er implementeret som foreslået og kan vedtages i DESIGN_LOG. D9 bortfalder (#48).
- `code.host` og `dl.meta.single` fra det visuelle brief er implementeret præcist.
- Ingen nye design-beslutninger i dette review.
