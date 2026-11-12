# Impact H004 — opfølgning på critic-fund fra F002, F003 og F005

Tier: 1

Grund: Rettelsen ændrer auth-adfærd. MCP-nøgler tilbagekaldes ved nulstilling og skift af adgangskode, og
fejlhåndteringen i `/mcp` ændres. Derfor tier 1 efter LIFECYCLE §4.

## Berørte stier
- `homedir/src/Mcp/{Server,Tools,Tokens}.php`, `homedir/src/Users.php`, `homedir/src/App.php` (konto og nulstilling)
- `homedir/src/Ui.php`, `homedir/src/UiCalls.php`, `homedir/src/UiPages.php`, `homedir/src/Calls.php` (uddrag)
- `homedir/public_html/assets/app.js`
- `homedir/src/Flags.php`, `homedir/src/Zendesk/Sync.php`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Anthropic som modtager er uændret (DECISIONS #20). Rammen om kundetale mindsker risikoen fra #22.

## Risiko
- Brugerne mister MCP-nøgler ved skift af adgangskode. Det er tilsigtet, og flash-teksten forklarer det.
- Hvis rettelsen af uddrag bruger `REGEXP_INSTR`, skal funktionen findes i både MariaDB 10.11 og 12.3. Det afgøres i v0.

## Migration
Ingen, medmindre N-3 vælger en kolonne. Så bliver det 0005 (tildelt af Team Lead).

## Rollback
Revert-H.
