# Impact F002 — Zendesk Talk: hentning, lokal transcription og visning

Tier: 1

Grund: ny migration, en ny integration med hemmelighed (Zendesk OAuth client_secret og access token), persondata (kunders opkald) og
shell-kald til en lokal motor. Det er tier 1 efter LIFECYCLE §4.

## Berørte stier
- `homedir/migrations/0003_v0.3.0_opkald.sql` (+ down)
- `homedir/src/` (Zendesk-klient, sync, opkald, transcription, lyd-streaming, App og View)
- `homedir/cron/` (zendesk-sync.php, transcribe.php, transcribe.py, whisper-setup.sh)
- `homedir/config/features.ini` (`[F002_zendesk]`)
- `homedir/tests/`
- `~/var/recordings/` og `~/var/whisper/` på serveren (uden for git)

## Persondata og eksterne modtagere
- **Persondata:** opkaldsoptagelser (kunders stemme), telefonnumre, agentnavne og transcripts med samtalens indhold.
  Det ligger i projektets database og i `~/var/` på serveren. På STABLE er det med i maskinens backup, og på DEV er
  der ingen off-site backup.
- **Kilde:** Zendesk (`trygenergidanmarkhelp.zendesk.com`). Vi henter data derfra og sender intet personhenførbart
  dertil.
- **Eksterne modtagere:** ingen. Transcription sker lokalt (DECISIONS #2). Modellen hentes fra Hugging Face og pakker
  fra PyPI, men der sendes ingen kundedata ud. SUBPROCESSORS.md opdateres kun, hvis v0 finder, at det kræves.
- **Retention:** der er ingen slettepolitik endnu. Den er en åben risiko og kommer i et senere ID, før STABLE.

## Risiko
- Fejl i adgangskontrol på lyd, transcript eller zip udstiller kundeopkald. Alle ruter er derfor bag F001-login, og
  lydfiler ligger uden for webroden.
- CPU-tung transcription på maskinen køres med nice/ionice, ét job ad gangen (DECISIONS #3).
- SSRF via `recording_url`: kun Zendesks egne domæner over https.

## Migration
0003, expand, reversibel med down-fil. Nummeret er tildelt af Team Lead.

## Rollback
`dev = off` i flaget, eller revert-H. Down-migrationen fjerner tabellerne. Filerne i `var/recordings` bliver liggende.
