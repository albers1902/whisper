# Impact F008 — Adversus pr. lead

Tier: 1

Grund:
- ny UI-struktur (lead-liste og lead-side)
- nyt MCP-værktøj (uddata til Claude)
- ny download (zip pr. lead)
- sandsynligvis en expand-migration med indeks

Critics: `qa-critic` og `security-critic`. Designere: `ui-ux` og `graphic-design`.

## Berørte stier
- `homedir/src/Calls.php`, `UiCallsV2.php`, `UiV2.php`, `CallPages.php`, `App.php` (rute `/lead/{id}`)
- `homedir/src/Mcp/Tools.php`
- `homedir/public_html/assets/app-v2.css`, `app-v2.js`
- `homedir/migrations/0011_*` (hvis indeks er nødvendigt)
- `homedir/config/features.ini`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen ny modtager. MCP `get_lead` giver Claude flere transcripts samlet. Det er samme data og adgang som `get_transcript`
(DECISIONS #22 og #72 gælder).

## Risiko
- Skala: gruppering pr. lead over 300.000 opkald kræver indeks og et loft som i F007.
- `.htaccess` må ikke ændres (H014-whitelist).

## Rollback
Slå flaget `F008_leads` fra, eller lav Revert-F.
