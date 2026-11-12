# Impact F006 — OAuth til Claude custom connector

Tier: 1

Grund: ny auth-flade (OAuth 2.1 authorization server, dynamisk klientregistrering, tokens). Den rører login, sessions
og `/mcp`, har en migration og en ny UI-side (godkendelse og forbundne apps). Critics er `security-critic` og
`qa-critic`. Designerne er `ui-ux` og `graphic-design`, og kun godkendelsessiden og Konto-afsnittet er nye.

## Berørte stier
- `homedir/src/Mcp/` (Server, Tokens) og nye `homedir/src/OAuth/`
- `homedir/src/App.php` (routes), `UiPages.php`/`View.php` (Konto, godkendelse), `Users.php` (tilbagekald)
- `homedir/public_html/.well-known/` eller tilsvarende routing, `.htaccess`
- `homedir/migrations/0006_*` (klienter, koder, tokens)
- `homedir/config/features.ini` (`F006_oauth`)
- `homedir/tests/`

## Persondata og eksterne modtagere
- Ingen ny modtager. Transcripts går til Anthropic via MCP, som de gør i dag (DECISIONS #20). Nu sker det også via
  claude.ai-connectoren.
- Der gemmes klientnavn og redirect-URI fra Claude og token-hashes pr. bruger.

## Risiko
- Fejl i OAuth (PKCE, redirect-validering, kode-genbrug, open redirect) kan give fremmede adgang til transcripts.
  Derfor tier 1 med security-critic.
- Dynamisk registrering er åben for alle. Allowlisten på redirect-værter, rate limit og oprydning begrænser det.
- Kompatibilitet med Claude's connector (protokolversion, transport) skal verificeres på DEV (AK9).

## Rollback
Slå flaget `F006_oauth` fra (F003-nøglerne virker videre), eller Revert-F. Migrationen er reversibel.
