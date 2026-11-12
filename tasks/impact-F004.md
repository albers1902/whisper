# Impact F004: Adversus-opkald, auto-transcription af succes-leads

Tier: 1

Grund: ny ekstern kilde med persondata (samtaler og opkaldsmetadata), migration, ny cron, ny UI-handling (POST) og
download af lyd fra en ekstern vært. Critics: `security-critic` og `qa-critic`. Designere: `ui-ux` og `graphic-design`.

## Berørte stier
- `homedir/src/Adversus/` (Client genbruges; ny Sync)
- `homedir/cron/adversus-sync.php` (ny) og crontab via install/deploy
- `homedir/src/Calls.php`, `UiCalls.php`, `CallPages.php`, `CallView.php`, `Transcription/` (kø og lydhentning)
- `homedir/src/Mcp/` (kilde i output)
- `homedir/migrations/0005_*` (kilde, eksternt id, lead, succes, cursorer, succes-leads)
- `homedir/config/features.ini` (`F004_adversus`)
- `homedir/tests/`

## Persondata og eksterne modtagere
- Ny kilde: Adversus (opkaldsmetadata og lyd for succes-opkald og manuelt valgte opkald).
- Lyd ligger lokalt på DEV som Zendesk-lyd. Transcription sker lokalt (DECISIONS #2). Transcripts går til Anthropic via
  MCP som i dag (DECISIONS #20).
- Ingen ny ekstern modtager.
- Telefonnummer gemmes kun, hvis Zendesk-opkald allerede gør det.
- `launched/compliance` opdateres med Adversus som kilde.

## Risiko
- Rate limit (1000/time): kaldloft og cursor.
- Datamængde: ca. 150 succes-leads pr. dag giver begrænset lyd og CPU, nu med H006/H007.
- Signeret URL: gemmes ikke, og redirect følges kun til en godkendt vært.

## Rollback
Flaget `F004_adversus` fra, eller Revert-F. Migrationen er reversibel.
