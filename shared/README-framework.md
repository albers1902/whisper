<!-- FILE_VERSION: 5.42 -->
# Coworker Framework — master

Master-mappen er `C:\ClaudeCoworkers\coworker-development`. HELE mappen kopieres ind
i et projekt (Erstat filer). Derfor indeholder master kun frameworkets egne filer;
`README.md` og `.gitignore` i roden er pladsholdere, som Team Lead erstatter med
projektets egne (CLAUDE.md Trin 0, punkt 5), og som CI aldrig lader blive committet.

- `CLAUDE.md` — Team Leads start (indlæses automatisk af Code-fanen)
- `shared/LIFECYCLE.md` — processen og hvad der låser den
- `shared/report.sh` — alle rapporter, CI-kontroller, overvågning og låsefil
- `shared/framework.lock` + `shared/VERSION` — hvilke framework-filer der hører til versionen
- `shared/install/` — CI, overvågning, deploy: låste filer, installeres uændret (`report.sh install`). Backup er maskinens (`shared/SERVERKONTRAKT.md`)
- `shared/templates/` — coworker.ini, drift, incident, adgangsregister, deploy-keep (Team Lead udfylder)
- `shared/selftest.sh`, `shared/selftest-deploy.sh`, `shared/selftest-guard.sh` — frameworkets egne tests
- `shared/FRAMEWORK-CHANGELOG.md` — hvad er ændret og hvorfor
- `OPSAETNING.md` — opsætning i Claude Desktop og på serveren
