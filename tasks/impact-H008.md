# Impact H008: batch-claim efter resttid, intet forsøg ved resttids-timeout

Tier: 2

Grund: ændringen rører kø, claim og regnskabet med forsøg i Transcriber. Den indeholder ingen migration, ingen auth og
ingen ny modtager. Critic: `security-critic` (dataintegritet i kø og forsøg).

## Berørte stier
- `homedir/src/Transcription/Transcriber.php` og `WhisperSettings.php`
- `homedir/cron/transcribe.php` (kun hvis resttiden skal sendes anderledes)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye.

## Risiko
- Er `rtf_estimate` for lav, claimes for mange opkald. Det svarer til i dag, men uden tab af forsøg.
- Er den for høj, bliver batchene mindre, og modellen indlæses lidt oftere.
- Kan tilfældet "resttid" ikke skelnes korrekt fra "opkaldets egen grænse", kan et opkald, der aldrig bliver færdigt,
  genforsøges for evigt. AK4 dækker det.

## Rollback
Revert-H, eller `[whisper] batch = 1`.
