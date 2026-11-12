# Impact H011 — hvem taler (diarization)

Tier: 2 (engineeren bekræfter i v0. Kræver lagringen en migration, eller ændres UI'et mere end en tekstetiket, er det
tier 1, og så siger engineeren til)

Grund: ny lokal model og afhængighed i motoren, ændret transcript-format, visning i UI, .txt og MCP. Ingen ny ekstern
modtager. Critic: `security-critic` (afhængigheder og forsyningskæde, modeldownload, uddata til MCP).

## Berørte stier
- `homedir/cron/transcribe.py` og låste Python-afhængigheder (som H003)
- `homedir/src/Transcription/` (ProcessEngine, Transcriber, evt. ny Diarization-del)
- `homedir/src/CallView.php`, `UiCalls.php`, `Mcp/Tools.php`, .txt/zip
- evt. `homedir/migrations/` (hvis taleren ikke kan ligge i det eksisterende segmentformat)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye modtagere. Diarization sker lokalt (DECISIONS #2). Modellen hentes én gang fra nettet, og det er tilladt
(DECISIONS #3). Talerroller går til Anthropic via MCP som en del af transcriptet (#20).

## Risiko
- CPU-pris oven i whisper. Den måles i loggen og kan slås fra.
- Forkerte roller. Rollerne er en heuristik, og "Taler 1/2" bruges, når de ikke kan afgøres.
- Ny afhængighed i forsyningskæden. Versionen låses, og hashes tjekkes.

## Rollback
Slå diarization fra i config eller flag, eller Revert-H.
