# Impact H009: Adversus-optagelser matches med deres opkald

Tier: 2

Grund: rettelse af F004-sync's tidsfiltre mod Adversus (lokal væg-tid), en engangs-genopretning af sync-cursoren og en
read-only diagnose. Der er ingen migration, ingen auth og ingen ny ekstern modtager (samme Adversus-API og samme felter
som F004). Critic er `security-critic`, fordi koden læser input udefra (Adversus' svar), udskriver det på DEV
(diagnosen) og ændrer sync-cursorer og ventelisten.

PR 1 (#32, merget) indeholder kun diagnosen. PR 2 (`hotfix/H009-adversus-match-2`) indeholder fixet, genopretningen og
rettelserne af diagnosen (security H009_v1 S1, S2 og S4).

## Berørte stier
- `homedir/src/Adversus/Sync.php` (filtertider, genopretning)
- `homedir/src/Adversus/AdversusConfig.php` (`filter_tz`)
- `homedir/src/Adversus/Diagnose.php`, `homedir/cron/adversus-diagnose.php`
- `homedir/tests/f004_lib.php`, `homedir/tests/test_H009-*.php`

## Persondata og eksterne modtagere
Ingen nye. Diagnosen udskriver kun id'er, tidsstempler, varigheder, HTTP-status, feltnavne og en renset fejltekst
(DECISIONS #53).

## Risiko
- **Hvis Adversus begynder at respektere `Z`,** ligger filtrene 1 t forkert igen. Den rettede diagnose viser det
  (afsnit 1: "inden for vinduet"), og `filter_tz = UTC` er en nødvej uden kodeændring.
- **Når sommertid slutter,** forekommer lokal 02:00–03:00 to gange. Et vindue i den time kan ramme den forkerte. Det
  sker én time om året om natten med få opkald. Det vides og står i fragmentet.
- **Genopretningen** henter vinduer fra `sync_since` igen. Med ca. 27 kald pr. time-vindue ved spidsbelastning er
  efterslæbet indhentet på ca. 8 kørsler. Loftet på 100 kald pr. kørsel er uændret, så Adversus' grænse på 1000 i
  timen holdes.

## Rollback
Revert-H. Nødvej uden deploy: `[adversus] filter_tz = UTC` (`vi ~/config/config.ini`) giver den gamle fortolkning.
