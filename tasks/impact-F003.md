# Impact F003 — MCP-server til Claude Desktop

Tier: 1

Grund: ny migration, ny autentificering (bearer-nøgler), og en ny ekstern modtager af kundedata. Når en bruger
forbinder Claude Desktop, sendes transcripts til Anthropic. Det er tier 1 efter LIFECYCLE §4.

## Berørte stier
- `homedir/migrations/0004_v0.4.0_mcp_tokens.sql` (+ down)
- `homedir/src/Mcp/` (ny), `homedir/src/App.php`, `homedir/src/View.php`
- `homedir/config/features.ini` (`[F003_mcp]`)
- `homedir/tests/`
- `launched/compliance/SUBPROCESSORS.md`

## Persondata og eksterne modtagere
- **Data der udleveres:** transcripts (samtalens indhold), telefonnumre, agentnavne og opkaldsmetadata. De sendes kun,
  når en indlogget Whisper-bruger med egen nøgle beder Claude om det.
- **Ny ekstern modtager:** Anthropic (Claude Desktop / Claude API), som behandler indholdet for brugeren. Det er
  ejerens beslutning (DECISIONS #1: "analyse i Claude Desktop via en MCP-server"). SUBPROCESSORS.md opdateres i samme
  PR med formål, data og fra-dato.
- **Ikke udleveret:** lydfiler, filstier, hemmeligheder.
- **Bemærkning:** DECISIONS #2 ("ingen lyd forlader serveren") holder stadig, for der sendes kun tekst. Tekst, der
  sendes til Claude, forlader til gengæld serveren. Det er en bevidst ændring af databilledet, og den skrives i
  DECISIONS ved merge.

## Risiko
- Lækkede nøgler giver læseadgang til alle transcripts. Det modvirkes med hash, visning én gang, tilbagekald,
  rate limit og adgangslog.
- Fejl i undtagelsen af `/mcp` fra session og CSRF kan åbne andre ruter. Undtagelsen gælder derfor kun `/mcp`.

## Migration
0004, expand, reversibel med down-fil. Nummeret er tildelt af Team Lead.

## Rollback
`dev = off` i flaget, eller revert-H. Down-migrationen fjerner tabellerne.
