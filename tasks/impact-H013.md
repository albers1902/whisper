# Impact H013: Adversus — alle CDR'er inkrementelt, lyd kun for succes og valgte, ingen venteliste

Tier: 1

Grund: migration 0009 (`adversus_leads`, `calls.disposition`, `audio_status` 'ingen', indeks; expand, reversibel).
Desuden ny hentelogik i `Adversus/Sync.php` (CDR- og lead-cursorer i stedet for optagelser og venteliste). Flere
persondata gemmes: telefonnummer på alle CDR'er, ikke kun opkald med optagelse.
Critic: `qa-critic` og `security-critic`.

## Berørte stier
- `homedir/src/Adversus/Sync.php` (hovedændringen), evt. `homedir/src/Adversus/Client.php` (404 = ingen optagelse)
- `homedir/migrations/0009_v0.9.0_adversus_cdr.sql` + `homedir/migrations/down/` samme navn
- `homedir/tests/` (f004_lib-fake, F004-AK4, H009-AK2/AK3 omskrives, nye H013-tests)
- IKKE `UiCalls.php`, `Ui.php`, `Calls.php`, `CallPages.php` (F007)

## Persondata og eksterne modtagere
Ingen ny ekstern modtager. Data hentes fra Adversus (som i dag), og intet sendes ud. Mere persondata gemmes lokalt:
metadata og telefonnummer for alle CDR'er, ikke kun dem med optagelse (beslutning 2 i `fullstack-ai/H013_v0.md` §7).
Lead-status gemmes (kun id, status og tid; ingen lead-felter med fritekst).

## Risiko
- "Alle opkald" bliver langt større (ca. 10.000 rækker pr. døgn). Det kræver indeks (0009).
- Hvis `/calls`-cursoren er valgt forkert, mistes CDR'er. Valg B har en daglig lukke-kontrol mod `meta.pagination.total`.

## Rollback
Revert-H. Migration 0009 er expand-only, og den gamle kode ignorerer de nye kolonner og tabeller.
