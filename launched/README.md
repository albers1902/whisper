# /launched/

KUN dokumentation — aldrig kode. Se `shared/LIFECYCLE.md` §7.

```
launched/
  releases/<tag>.md  ← én fil per release-udkast (report.sh notes), ændres aldrig
  STABLE/            ← DECISIONS.md, DEPENDENCY_MAP.md, DEPLOYMENT.md (alle tre miljøer)
  F00X/  H00X/       ← immutable arkiv per shipped feature/hotfix
```

DEV- og TEST-tilstand skrives ikke ned — `bash shared/report.sh` og
`<url>/health` beregner dem. `DEV/` og `TEST/` er udgået i v5.2.
