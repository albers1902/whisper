# Impact H006 — whisper-model indlæst én gang pr. kørsel (batch)

Tier: 2

Grund: Ændringen rører motorens kald (argv, flere filer) og køens claim- og fejllogik. Der er ingen migration, ingen
auth og ingen ny modtager. Critic er `security-critic` (argv og filstier).

## Berørte stier
- `homedir/cron/transcribe.py`
- `homedir/src/Transcription/ProcessEngine.php`, `Transcriber.php`, `WhisperSettings.php`, `Engine.php`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Transcription er stadig lokal.

## Risiko
- En fejl i batch-logikken kan efterlade opkald i forkert status. AK2 og AK3 tester fejl og timeout midt i en batch.
- Hukommelsen er den samme som i dag: én model og én proces ad gangen.

## Rollback
Revert-H, eller `[whisper] batch = 1`.
