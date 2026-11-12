# Impact H009: Adversus-optagelser matches med deres opkald

Tier: 2

Grund: rettelse af F004-sync's opslag i Adversus' `/calls` og en read-only diagnose. Ingen migration, ingen auth, ingen
ny ekstern modtager (samme Adversus-API og samme felter som F004). Critic er `security-critic`, fordi koden læser
input udefra (Adversus' svar) og udskriver det på DEV (diagnosen), og fordi genopretningen ændrer sync-cursorer.

## Berørte stier
- `homedir/src/Adversus/Sync.php` (opslaget af opkald, genopretning)
- `homedir/src/Adversus/Diagnose.php` (ny, read-only)
- `homedir/cron/adversus-diagnose.php` (ny, køres manuelt, ikke i crontab)
- `homedir/tests/f004_lib.php`, `homedir/tests/test_H009-*.php`

## Persondata og eksterne modtagere
Ingen nye. Diagnosen udskriver kun id'er, tidsstempler, varigheder, HTTP-status og feltnavne — aldrig telefonnummer,
navne, URL'er eller fri tekst ud over den rensede fejltekst (samme regel som F004a-proben og F004 security S5).

## Risiko
- Et forkert valgt opslag kan stadig ramme forkert. Derfor diagnosen før fixet og en fake, der svarer som det målte API.
- Genopretningen henter vinduer fra `sync_since` igen og bruger kald, til cursoren er tilbage ved nu. Kaldloftet pr.
  kørsel er uændret, så Adversus' grænse (1000/time) holdes.
- Diagnosen kører ved siden af cron-sync'en. Begge bruger Client med loft og 429-håndtering.

## Rollback
Revert-H.
