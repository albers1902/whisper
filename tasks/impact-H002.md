# Impact H002 — Zendesks OAuth-fejlkode i probe og log

Tier: 2

Grund: logikændring i Zendesk-klientens fejlhåndtering, uden migration og uden ny ekstern modtager. Den rører input
fra en ekstern kilde og hemmeligheder i nærheden, så critic er `security-critic`.

## Berørte stier
- `homedir/src/Zendesk/Client.php`, `ZendeskError.php`
- `homedir/cron/zendesk-probe.php`
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Fejltekster fra Zendesk vises kun i proben og i projektets egen log, og de renses.

## Risiko
At en hemmelighed lækker gennem fejlteksten. Det modvirkes af rensning og test (AK2).

## Rollback
Revert-H.
