# Impact H001 — Coworker Framework 5.42 installation

Tier: 1

Grund: Ændringen rører `homedir/migrations/`, `homedir/cron/deploy.sh`, `ci.sh`, `monitor.sh` og `coworker.ini`
(kontrolfil), så den er tier 1 efter LIFECYCLE §4.

## Berørte stier
- `coworker.ini` (ny)
- `homedir/cron/` (ci.sh, deploy.sh og monitor.sh installeret uændret, migrate.php ny)
- `homedir/src/`, `homedir/public_html/` (health), `homedir/migrations/0001_*` (+ down), `homedir/tests/`,
  `homedir/config/features.ini`
- `launched/DRIFT.md`, `launched/compliance/ACCESS.md`, `changes/H001.md`

## Persondata og eksterne modtagere
Ingen. Skelettet behandler ingen persondata og sender intet til eksterne modtagere.

## Risiko
Første installation. Main har ingen `coworker.ini`, så `coworker/pr` kan ikke sættes, og der merges på
`PRECHECK: GRØN` alene (CLAUDE.md trin 4). `coworker/ci` på main skal være grøn efter serveropsætningen af DEV.

## Rollback
Revert-H. Migration 0001 har en down-fil.
