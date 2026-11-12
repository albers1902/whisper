# Impact F007 — Zendesk og Adversus hver for sig

Tier: 1

Grund: ny UI-struktur (faner, kolonner, statuslinjer) i opkaldslisten. Ingen migration, ingen auth og ingen ny
modtager. Critics: `qa-critic` og, hvis CI kræver tier 1, `security-critic` (URL-parametre og filter-SQL).
Designere: `ui-ux` og `graphic-design`.

Tillæg v2 (2026-11-12): spec v2 lægger det godkendte redesign ind (ny ramme med sidebar, kort, chatbobler,
afspiller, selv-hostede fonte og lidt vanilla JS). Stadig tier 1, nu også `security-critic` fast (ny JS og
fontfiler under CSP).

## Berørte stier
- `homedir/src/UiCalls.php`, `Ui.php`, `Calls.php`, `CallView.php`, sider for Konto, Brugere, login og konsent
- `homedir/public_html/assets/app.css`, ny `app.js` (afspiller, menu), `assets/fonts/` (Inter, Inter Tight + licens)
- Status for transcription-motoren (falsk "ikke sat op")
- `homedir/src/Adversus/Sync.php` (rydning af advarsel efter genopretning)
- `homedir/config/features.ini`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen ændring.

## Risiko
- Overlap med H011, som rører visningen af transcripts i UiCalls og CallView. F007 bygges oven på H011, når den er
  merget.
- Fanen i URL'en skal valideres som en fast værdi.

## Rollback
Slå flaget `F007_kilder` fra, eller lav Revert-F.
