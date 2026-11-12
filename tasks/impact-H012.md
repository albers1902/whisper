# Impact H012: kortere kø (nyeste først, spring korte og gamle over)

Tier: 1

Grund: migration 0008 (`calls.manual_at`, reversibel). Det er valgt i v0 og godkendt af Team Lead 2026-11-12.
Reglerne regnes ud fra config og gemmes ikke som status. Desuden kø-logikken i Transcriber (claim-sortering og filtre),
`source_priority` (spec v2), UI-tæller og knappen. Ingen auth, ingen ny modtager.
Critic: `qa-critic` og `security-critic`. Ændringen rører migration, kø, tilstand og en POST-handling.

## Berørte stier
- `homedir/src/Transcription/Transcriber.php`, `WhisperSettings.php`
- lydhentning (Zendesk/Adversus) for rækkefølge, hvis relevant
- `homedir/src/Calls.php`, `UiCalls.php`, `Ui.php`, `CallPages.php` (status, tæller, knap)
- `homedir/src/Transcription/QueueRules.php` (ny), `homedir/cron/transcribe.php`, `homedir/src/App.php` (route)
- `homedir/migrations/0008_v0.8.0_manuelt_valgt.sql` + down
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen ændring. Færre opkald transcriberes automatisk.

## Risiko
- Korte opkald med indhold (fx en hurtig opsigelse) transcriberes ikke automatisk. De kan vælges manuelt, og grænsen
  kan ændres i config.
- Overlap med H011 (`Transcriber::finish`) og F007 (UiCalls). Rækkefølgen af merges koordineres af Team Lead.

## Rollback
`min_seconds = 0` og `max_age_days = 0`, eller Revert-H.
