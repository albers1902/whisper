# Impact H011 — hvem taler (diarization)

Tier: 1

Grund:
- Migration 0006 (expand, `transcripts.diar_status`/`diar_attempts`/`diarized_at`).
- Diarization er efter ejerens krav et separat trin i ledig tid (spec v2, `fullstack-ai/H011_v0.md` tillæg 1). Det
  skal derfor kunne se, hvilke transcripts der mangler talere, og huske fejl.
- Derudover: ny lokal model og ny afhængighed i venv'et (sherpa-onnx 2.0.1, låst med hash), ændret transcript-format
  (`speaker`/`role` pr. segment) og visning i UI, .txt og MCP. Ingen ny ekstern modtager.

Critics: `qa-critic` og `security-critic` (afhængigheder og forsyningskæde, modeldownload, proces/argv, uddata til
MCP). Ingen designere: UI-delen er kun tekstetiketten "Agent:"/"Kunde:".

## Berørte stier
- Nye:
  - `homedir/cron/diarize.php`, `homedir/cron/diarize.py`
  - `homedir/src/Transcription/Diarization.php`, `DiarizationEngine.php`, `DiarizationSettings.php`, `Diarizer.php`,
    `ProcessDiarizer.php`
  - `homedir/migrations/0006_v0.6.0_diarization.sql` (+ down)
- Ændrede:
  - `homedir/cron/whisper-requirements.txt` (to nye låste wheels), `whisper-setup.sh` (modeller + selvtest) og
    `whisper-cron.sh` (ny cron-linje)
  - `homedir/src/Transcription/Transcriber.php`: kun nulstilling af bogføringen ved en ny transcription
  - `homedir/src/Calls.php`, `UiCalls.php`, `CallView.php`, `Mcp/Tools.php`
  - `homedir/config/features.ini` (`H011_diarization`)
- Urørte: `homedir/cron/transcribe.py`, `ProcessEngine.php`, `WhisperSettings.php` (H010).
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye modtagere. Diarization sker lokalt (DECISIONS #2), og ingen lyd forlader serveren. Modellerne hentes én gang
fra Hugging Face af `whisper-setup.sh`, låst på revision og sha256 (DECISIONS #3). Talerroller går til Anthropic via
MCP som en del af transcriptet (#20). Det er samme modtager og samme data, nu med etiketten "Agent:"/"Kunde:".
`SUBPROCESSORS.md` er uændret.

## Risiko
- CPU i ledig tid. Transcription går altid først (stop inden for ca. 5 s). Ingen ekstra tid i transcription.
- Forkerte roller. Rollerne er en heuristik, "Taler 1/2" bruges, når de ikke kan afgøres, og markørerne kan rettes i
  config.
- Ny afhængighed i forsyningskæden. Version og sha256 er låst for wheels og modeller.
- Linux-kompatibilitet for sherpa-onnx' egen onnxruntime er ikke målt. Setup-selvtesten afgør det; uden den kører
  intet.

## Rollback
`[diarization] enabled = off` i config.ini (uden deploy), flaget off eller Revert-H. 0006 har en down-fil. Talerne i
segments_json ignoreres af kode fra før H011.
