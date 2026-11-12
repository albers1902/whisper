# Impact H010 — batched pipeline i whisper (måling)

Tier: 2

Grund: ændrer motorens argv og transcribe.py, men har ingen migration, ingen auth og ingen ny modtager. Critic er
`security-critic`, der tjekker argv, validering og at motorens uddata er uændret.

## Berørte stier
- `homedir/cron/transcribe.py`
- `homedir/src/Transcription/ProcessEngine.php`, `WhisperSettings.php`, evt. `Transcriber.php` (loglinje)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Transcription er stadig lokal (DECISIONS #2).

## Risiko
- Mere RAM og CPU-tryk i batched-tilstand. Den er slået fra som standard, kan slås fra i config, og RAM-tjekket står i
  smoke-testen.
- Kvaliteten kan ændre sig en smule, fordi segmenteringen sker via VAD. Ejeren sammenligner nogle transcripts.

## Rollback
`[whisper] engine_mode = sequential`, eller Revert-H.
