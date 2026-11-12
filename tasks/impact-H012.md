# Impact H012: kortere kø (nyeste først, spring korte og gamle over)

Tier: 2. Hvis mekanismen kræver en migration (ny status), er det tier 1, og så siger engineeren til.

Grund: kø-logikken i Transcriber (claim-sortering og filtre), en ny status og UI-tæller. Ingen auth, ingen ny modtager.
Critic: `security-critic`, fordi ændringen rører kø og tilstand. `qa-critic` tilføjes ved tier 1.

## Berørte stier
- `homedir/src/Transcription/Transcriber.php`, `WhisperSettings.php`
- lydhentning (Zendesk/Adversus) for rækkefølge, hvis relevant
- `homedir/src/Calls.php`, `UiCalls.php`, `Ui.php`, `CallPages.php` (status, tæller, knap)
- evt. `homedir/migrations/0008_*`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen ændring. Færre opkald transcriberes automatisk.

## Risiko
- Korte opkald med indhold (fx en hurtig opsigelse) transcriberes ikke automatisk. De kan vælges manuelt, og grænsen
  kan ændres i config.
- Overlap med H011 (`Transcriber::finish`) og F007 (UiCalls). Rækkefølgen af merges koordineres af Team Lead.

## Rollback
`min_seconds = 0` og `max_age_days = 0`, eller Revert-H.
