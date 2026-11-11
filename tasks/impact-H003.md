# Impact H003 — låste Python-afhængigheder til whisper

Tier: 2

Grund: Ændringen rører installationsscriptet og supply chain for motoren. Der er ingen migration og ingen ny
modtager af data. Critic er `security-critic` (supply chain).

## Berørte stier
- `homedir/cron/whisper-setup.sh`
- `homedir/cron/whisper-requirements.txt` (ny)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Pakker og model hentes fra PyPI og Hugging Face som før (DECISIONS #3). Der sendes ingen kundedata ud.

## Risiko
En forkert låst kombination giver en fejl ved setup. Det opdages af import-tjekket, før modellen hentes.

## Rollback
Revert-H. Venv'et i `~/var/whisper` genskabes ved at køre setup igen.
