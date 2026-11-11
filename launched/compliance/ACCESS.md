<!-- FILE_VERSION: 5.42 — fra shared/templates/ACCESS.md.template (H001). Ingen hemmeligheder her — kun hvem/hvad har adgang. -->
# Adgangsregister — Whisper

Én række pr. konto eller nøgle med adgang til kode, server, database eller kundedata.
`bash shared/report.sh check` melder rækker, der ikke er gennemgået de sidste 90 dage
(datoen i sidste kolonne). Rotation skrives som en ny dato, ikke som en ny række.
Felter, der først kendes, når serverne er sat op, står som "udfyldes ved serveropsætning".

| System | Konto / nøgle | Rolle / rettigheder | Ejer | Oprettet | Roteret | Gennemgået |
|---|---|---|---|---|---|---|
| GitHub | albers1902 (repo albers1902/whisper) | admin | albers1902 | 2026-11-11 | — | 2026-11-11 |
| GitHub | token "agenter" (fine-grained) | Contents/PR/Issues RW, Commit statuses R | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Server (DEV) | token `config/github.token` (deploy, CI, monitor) | Contents R, Commit statuses/PR/Issues RW | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Server (STABLE) | token `config/github.token` (deploy) | Contents R, Commit statuses/PR/Issues RW | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Server (DEV) | Linux-bruger: udfyldes ved serveropsætning | shell | albers1902 | udfyldes ved serveropsætning | — | 2026-11-11 |
| Server (DEV) | CI-bruger `<dev-bruger>-ci`: udfyldes ved serveropsætning | kører testet kode, kan ikke læse DEV's config/token | albers1902 | udfyldes ved serveropsætning | — | 2026-11-11 |
| Server (STABLE) | Linux-bruger: udfyldes ved serveropsætning | shell | albers1902 | udfyldes ved serveropsætning | — | 2026-11-11 |
| Database (DEV) | db-bruger: udfyldes ved serveropsætning | læs/skriv DEV-databasen | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Database (DEV) | CI-testdatabase `*_ci`: udfyldes ved serveropsætning | kun testdatabasen | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Database (STABLE) | db-bruger: udfyldes ved serveropsætning | læs/skriv STABLE-databasen | albers1902 | udfyldes ved serveropsætning | udfyldes ved serveropsætning | 2026-11-11 |
| Backup | restic-kodeord (maskinens site-backup) | læs alle backups | albers1902 | udfyldes ved serveropsætning | — | 2026-11-11 |
