# Impact H005 — hurtigere transcription og måling

Tier: 2

Grund: Ændringen rører motorens argumenter og config. Der er ingen migration, ingen auth og ingen ny modtager.
Shell-kaldet ændres (nye argv-elementer), så critic er `security-critic`, der tjekker argv og validering.

## Berørte stier
- `homedir/cron/transcribe.py`
- `homedir/src/Transcription/ProcessEngine.php`, `Transcriber.php`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Transcription er stadig lokal (DECISIONS #2).

## Risiko
- Kvaliteten falder lidt med `beam_size=1`. Den kan sættes tilbage i config uden deploy.
- VAD kan skære enkelte meget stille ord fra. Den kan slås fra i config.
- Mere CPU til whisper kan påvirke de andre sites på maskinen. `nice`, `ionice` og kerner minus 1 begrænser det.

## Rollback
Revert-H, eller config-værdierne beskrevet i spec'en.
