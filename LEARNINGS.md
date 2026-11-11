2026-11-11 | H001 | `report.sh install` springer cron-filerne over i stilhed, når `homedir/` ikke findes — i et tomt projekt skal mappen oprettes først.
2026-11-11 | H001 | Første installation fra en ikke-git-mappe: frameworkets filer committet på branchen forsvinder fra disken ved `git switch main` — gendan dem med `git restore --source=<sha> --worktree -- .`, og synk efter merge med `git reset --hard origin/main` først efter blob-sammenligning.
2026-11-11 | H001 | `migrate.php` med `PDO::exec` på flersætningsfiler fanger kun første sætnings fejl — QA-critic fandt det; brug `query()` + `nextRowset()`.
2026-11-11 | H001 | `sitectl new` lægger root-ejede Apache/FPM-logfiler i `logs/`, som `server.sh`s ejerskabstjek afviser — ikke besluttet i kontrakten.
2026-11-11 | F001 | Semgrep i DEV's CI afviser enhver bcrypt-streng i koden, også en dummy-hash. Brug `password_hash()` af inputtet til ukendte e-mails.
2026-11-11 | F001 | Appen kender ikke sit miljø; hvert flag kræver `[app] env` i miljøets config.ini — første flagede feature har et menneskeskridt på serveren.
2026-11-11 | F001 | Throttle der tjekker før bcrypt og skriver efter, lader parallelle requests igennem: skriv forsøget først og tæl kun lavere id'er.
