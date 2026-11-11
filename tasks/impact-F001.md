# Impact F001 — Brugere og login

Tier: 1

Grund: auth, sessioner og adgangskoder samt en ny migration (`homedir/migrations/`). Det er tier 1 efter LIFECYCLE §4.

## Berørte stier
- `homedir/migrations/0002_v0.2.0_brugere.sql` (+ down)
- `homedir/public_html/` (front controller, `.htaccess`, css)
- `homedir/src/` (auth, session, CSRF, brugere, throttling, flag, views)
- `homedir/cron/opret-admin.php`
- `homedir/config/features.ini` (`[F001_brugere]`)
- `homedir/tests/`

## Persondata og eksterne modtagere
- Persondata: medarbejderes navn, e-mail, hash af adgangskode, sidste login og IP-adresser i `login_attempts`, som
  slettes efter 30 dage. Alt ligger i projektets egen database på serveren.
- Eksterne modtagere: ingen. Der sendes intet ud af serveren, så SUBPROCESSORS.md er uændret.

## Risiko
- Fejl i adgangskontrol kan åbne data, som F002–F004 lægger bag login (opkald og transcripts). Rolle og aktiv-status
  tjekkes derfor ved hver request, og critics kører på tier 1.
- `.htaccess` omskrives: `/health` skal fortsat virke uden login, ellers ruller deploy tilbage.

## Migration
0002, expand, reversibel med down-fil. Nummeret er tildelt af Team Lead.

## Rollback
`dev = off` i flaget, eller revert-H. Down-migrationen fjerner tabellerne.
