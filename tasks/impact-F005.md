# Impact F005 — nyt UI (redesign)

Tier: 2

Grund: Ændringen er præsentationslogik i View og CallView: query-kontekst, fremhævning, rollestyrede beskeder og en
ny JS-fil. Der er ingen migration, ingen auth-ændring og ingen ny ekstern modtager. Den rører visning af data og
escaping, så critic er `qa-critic`, og XSS i fremhævningen dækkes af AK5 og AK10.

## Berørte stier
- `homedir/public_html/assets/app.css`, ny `homedir/public_html/assets/app.js`
- `homedir/src/View.php`, `homedir/src/CallView.php`, `homedir/src/CallPages.php` (kun visning og query-kontekst),
  `homedir/src/Calls.php` (kun hvis uddrag kræver en JOIN; samme WHERE)
- `homedir/config/features.ini` (`[F005_ui]`)
- `homedir/tests/`

## Persondata og eksterne modtagere
Ingen nye. Samme data vises for de samme roller. Søgeuddrag viser transcript-tekst, som allerede vises på detaljen.

## Risiko
- XSS i fremhævning og uddrag. Det modvirkes ved at tokenisere før escaping, og AK5 og AK10 tester det.
- Regressioner i det gamle UI, mens flaget er slukket. Det modvirkes ved, at det gamle UI står uændret bag flaget.

## Rollback
`dev = off` i flaget, eller revert-H.
