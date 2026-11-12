# Impact H007: 2 samtidige whisper-processer

Tier: 2

Grund: ændringen rører samtidighed i køen, låse og eventuelt en reversibel migration. Der er ingen auth, intet nyt input
udefra og ingen ny modtager. Critic er `security-critic` (dataintegritet: dobbelt-claim, stale-rydning, forsøgsregnskab).

## Berørte stier
- `homedir/cron/transcribe.php`
- `homedir/src/Transcription/Transcriber.php`, `WhisperSettings.php`, evt. `ProcessEngine.php`
- evt. `homedir/migrations/` (slot-kolonne)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Transcription er stadig lokal (DECISIONS #2).

## Risiko
- Mere CPU og RAM til whisper (2 × ca. 1,6 GB model). Andre sites på maskinen kan mærke det. `nice`, `ionice` og
  rollback via `workers = 1` begrænser det.
- Hvis claim eller stale-rydning er forkert, kan et opkald blive transcriberet to gange eller nulstillet midt i
  kørslen. AK2 og AK4 dækker det.

## Rollback
Revert-H, eller `[whisper] workers = 1`.
