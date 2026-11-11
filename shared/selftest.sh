#!/usr/bin/env bash
# FILE_VERSION: 5.42
# shared/selftest.sh — frameworkets egen test. Køres af framework-vedligeholderen FØR hver levering
# (Linux med git, jq og bash). Bygger et eksempelrepo med releases, rollback, flag, migrationer og
# PR'er, og tjekker at report.sh giver de forventede svar og at CI-kontrollerne afviser det, de skal.
#   bash shared/selftest.sh [master-mappe]      → "SELFTEST: N/N OK" eller fejlliste, exit 0/1
set -uo pipefail
M=$(cd "${1:-$(dirname "$0")/..}" && pwd)
T=$(mktemp -d); trap '[ -n "${KEEP:-}" ] || rm -rf "$T"' EXIT
pass=0; failn=0
ok()   { pass=$((pass+1)); }
bad()  { failn=$((failn+1)); echo "FEJL: $*"; }
has()  { grep -qF -- "$2" <<< "$1" && ok || bad "$3 — forventede: $2"; }
hasnt(){ grep -qF -- "$2" <<< "$1" && bad "$3 — forventede IKKE: $2" || ok; }

mkdir -p "$T/bin"; cd "$T"
git init -q --bare origin.git; git clone -q origin.git repo 2>/dev/null; cd repo
git config user.email t@t; git config user.name T; git checkout -q -b main
cp -r "$M/shared" "$M/agents" "$M/CLAUDE.md" "$M/OPSAETNING.md" .
mkdir -p homedir/config homedir/src homedir/migrations/down homedir/tests changes launched/releases launched/STABLE tasks product-ai fullstack-ai
cp "$M/changes/README.md" changes/ 2>/dev/null || echo "# changes" > changes/README.md
cp "$M/shared/gitignore.framework" .gitignore
bash shared/report.sh install >/dev/null 2>&1 || true
[ -e homedir/cron/backup.sh ] && bad "install lægger backup.sh i et nyt projekt (backup er maskinens fra 5.27)" || pass=$((pass+1))
c(){ git add -A; GIT_COMMITTER_DATE="$2" git commit -q --date "$2" -m "$1"; }
c "docs: framework" 2026-09-01T10:00:00
printf '[F001_login]\ndev = on\ntest = on\nstable = off\n' > homedir/config/features.ini; echo l > homedir/src/login.php
printf '# F001: Login-side\nType: feature\nKunde: Du kan nu logge ind.\nSupport: Ny login-side.\n' > changes/F001.md
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nCREATE TABLE IF NOT EXISTS u(i int);\n' > homedir/migrations/0001_v1.0.0_users.sql
echo 'DROP TABLE IF EXISTS u;' > homedir/migrations/down/0001_v1.0.0_users.sql
c "F001: login" 2026-09-10T10:00:00
printf '# H001: Ret stavefejl\nType: fejl\nKunde: Stavefejl på forsiden er rettet.\n' > changes/H001.md; echo t >> homedir/src/login.php; c "H001: fix typo" 2026-09-15T10:00:00
R1=$(git rev-parse HEAD)
sed -i 's/stable = off/stable = on/' homedir/config/features.ini; c "F001: stable = on" 2026-09-25T10:00:00
printf '\n[F003_x]\ndev = on\ntest = off\nstable = off\n' >> homedir/config/features.ini; printf '# F003: Eksperiment\nType: feature\nKunde: -\nSupport: -\n' > changes/F003.md; c "F003: experiment" 2026-09-26T10:00:00
printf -- '-- reversibel: nej\n-- destruktiv: ja\n-- idempotent: ja\nALTER TABLE u DROP COLUMN x;\n' > homedir/migrations/0002_v1.1.0_drop.sql
printf '# H002: Nedbrud\nType: fejl\nKunde: Login fejler ikke længere.\n' > changes/H002.md; c "H002: crash fix" 2026-09-30T10:00:00
R2=$(git rev-parse HEAD)
python3 - <<'EOF'
p='homedir/config/features.ini'; s=open(p).read(); s=s.replace('[F001_login]\ndev = on\ntest = on\nstable = on\n',''); open(p,'w').write(s.lstrip('\n'))
EOF
c "F001: fjern flag" 2026-10-04T10:00:00
printf '# H003: Cache\nType: fejl\nKunde: Siderne er hurtigere.\n' > changes/H003.md; echo b > homedir/src/b.php; c "H003: cache" 2026-10-05T10:00:00
R3=$(git rev-parse HEAD)
echo z > homedir/src/z.php; c "quick fix" 2026-10-13T10:00:00
echo e > homedir/src/e.php; printf '# F002: Eksport\nType: feature\nKunde: Eksport.\nSupport: -\n' > changes/F002.md; c "F002: eksport" 2026-10-14T09:00:00
python3 - <<'EOF'
p='homedir/config/features.ini'; s=open(p).read(); s=s.replace('[F003_x]\ndev = on\ntest = off\nstable = off\n',''); open(p,'w').write(s.strip('\n')+'\n')
EOF
c "F003: fjern eksperiment" 2026-10-14T10:00:00
cat > ROADMAP.md <<'EOF'
## Nu (denne måned)
- F002: Eksport — plan: 2026-09 · replan 2026-09-20: 2026-10 (kunde) — P1
## Næste (1–3 mdr)
- F004: Rapporter — plan: 2026-12
## Backlog (ikke planlagt)
- F006: Mørk tilstand
## Released
- F001: Login-side
## Droppet
EOF
printf '# DECISIONS\n## #1 — 2026-09-01 — PHP\n' > launched/STABLE/DECISIONS.md
c "docs: roadmap" 2026-10-15T10:00:00
git push -q origin main 2>/dev/null
for t in "v2026.09.20-1 $R1" "v2026.10.02-1 $R2" "v2026.10.10-1 $R3" "v2026.10.12-1 $R2"; do set -- $t; git tag "$1" "$2"; done
git push -q origin --tags 2>/dev/null; git tag -d $(git tag) >/dev/null
cat > "$T/releases.json" <<'EOF'
[{"tag_name":"v2026.10.20-1","name":"Udkast","draft":true,"prerelease":false,"published_at":null},
 {"tag_name":"v2026.10.12-1","name":"Rollback: v2026.10.10-1","draft":false,"prerelease":false,"published_at":"2026-10-12T09:00:00Z"},
 {"tag_name":"v2026.10.10-1","name":"R3","draft":false,"prerelease":false,"published_at":"2026-10-11T08:00:00Z"},
 {"tag_name":"v2026.10.02-1","name":"R2","draft":false,"prerelease":false,"published_at":"2026-10-03T12:00:00Z"},
 {"tag_name":"v2026.09.20-1","name":"R1","draft":false,"prerelease":false,"published_at":"2026-09-21T07:00:00Z"}]
EOF
cat > "$T/bin/gh" <<EOF
#!/bin/bash
case "\$*" in *statuses*) echo "\${CISTATE-success}"; exit 0;; *event=workflow_dispatch*) echo "\${DISPATCH_RUNS:-0}"; exit 0;; *workflows/ci.yml/runs*) echo 1; exit 0;; *actions/runs*) echo '{"workflow_runs":[{"name": "CI"}]}'; exit 0;; esac
while [ \$# -gt 0 ]; do case "\$1" in --jq) shift; JQ="\$1";; esac; shift; done
jq -r "\$JQ" "\${RELJSON:-$T/releases.json}" | sed 's/\$/\r/'
EOF
chmod +x "$T/bin/gh"; export PATH="$T/bin:$PATH"
R() { bash shared/report.sh "$@" 2>&1; }
# 5.23: projektets egen GitHub-nøgle, som .claude/settings.local.json sætter den (OPSAETNING §4)
export GH_TOKEN=selftest-noegle GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=credential.https://github.com.helper GIT_CONFIG_VALUE_0= \
       GIT_CONFIG_KEY_1=credential.https://github.com.helper GIT_CONFIG_VALUE_1='!gh auth git-credential'

o=$(R month 2026-10);           has "$o" "- F001: Login-side" "month: F001 tændt i okt"; has "$o" "Type: rollback" "month: rollback genkendt"; has "$o" "- H003: cache" "month: H003 trukket tilbage"; has "$o" "Login fejler ikke længere." "month: kundetekst"
o=$(R month 2026-09);           has "$o" "- H001: Ret stavefejl" "month: H001 i sep"; hasnt "$o" "- F001: Login-side" "month: F001 mørk i sep"
o=$(R releases);                has "$o" "| v2026.10.12-1 | 2026-10-12 |" "releases: rollback-række"
o=$(R id F001);                 has "$o" "Released i v2026.10.02-1 (2026-10-03)" "id: F001 released"
o=$(R roadmap);                 has "$o" "Merged (på main), ikke Released" "roadmap: F002"; has "$o" "F006: Mørk tilstand" "roadmap: backlog"; hasnt "$o" "OVERSKREDET" "roadmap: replan respekteres"
o=$(R fixes 2026-09..2026-10);  has "$o" "H001: Ret stavefejl" "fixes: H001"; has "$o" "Rollback: v2026.10.10-1" "fixes: rollback-release"
o=$(R restore v2026.09.20-1);   has "$o" "IKKE REVERSIBEL" "restore: destruktiv kræver backup"
o=$(R check);                   has "$o" "BRUD: flag F003" "check: flag-invariant" ; has "$o" "coworker.ini på main er ikke udfyldt" "check: coworker.ini"
o=$(R nextid F);                has "$o" "F007" "nextid"
o=$(R notes v2026.10.20-1 origin/main); f=launched/releases/v2026.10.20-1.md
has "$(cat $f)" "Launch-tjek: IKKE KLAR" "notes: launch-tjek fanger BRUD/monitor/restore"; has "$(cat $f)" "Kontrolændringer" "notes: kontrolændringer"
o=$(R notes v2026.10.20-1 origin/main); has "$o" "findes allerede" "notes: skrives kun én gang"

# --- CI-scenarier
ci() { BASE_REF=main HEAD_REF=$1 bash shared/report.sh ci 2>&1; }
git checkout -q -b feature/F004-rapporter origin/main; mkdir -p fullstack-ai
printf '# System understanding\n- Regler: shared/LIFECYCLE.md (§3)\n- CI: shared/report.sh (cmd_ci)\n' > fullstack-ai/F004_v0.md; c "F004: forståelse" 2026-10-16T09:00:00
echo r > homedir/src/r.php; c "F004: kode" 2026-10-16T10:00:00
o=$(ci feature/F004-rapporter); has "$o" "mangler et NYT changes/F004.md" "ci: fragment"; has "$o" "mangler tasks/impact-F004.md" "ci: impact"
printf '# F004: Rapporter\nType: feature\nKunde: Nye rapporter.\n' > changes/F004.md; printf 'Tier: 2\n' > tasks/impact-F004.md
echo r > fullstack-ai/F004_v1.md; printf '# Spec\n- AK1: PDF virker\n- AK2: hurtig\n' > product-ai/F004_v1.md
printf '<?php // test F004-AK1\n' > homedir/tests/RapportTest.php; c "F004: docs" 2026-10-16T11:00:00
o=$(ci feature/F004-rapporter); has "$o" "mangler linjen 'Support:" "ci: Support-felt"; has "$o" "AK2" "ci: AK-dækning"
printf 'Support: Ny menu.\n' >> changes/F004.md; printf '<?php // F004-AK2\n' >> homedir/tests/RapportTest.php; c "F004: fix" 2026-10-16T12:00:00
o=$(ci feature/F004-rapporter); has "$o" "tier 2: mangler én critic-review" "ci: tier 2 kræver én critic (5.22)"
mkdir -p qa-critic; printf 'Branch: feature/F004-rapporter @ %s\nStatus: APPROVED\n' "$(git rev-parse HEAD)" > qa-critic/F004_v1.md; c "F004: review" 2026-10-16T12:30:00
o=$(ci feature/F004-rapporter); has "$o" "F004 (tier 2)" "ci: grøn tier 2 med én critic"
echo "# lokal rettelse" >> homedir/cron/ci.sh; mkdir -p .github/workflows; printf 'name: x\n' > .github/workflows/egen.yml; c "F004: ci" 2026-10-16T13:00:00
o=$(ci feature/F004-rapporter); has "$o" "afviger fra shared/install/ci.sh" "ci: låst CI-fil"; has "$o" "workflows må ikke tilføjes" "ci: ingen GitHub Actions-workflows"; has "$o" "tier 1" "ci: driftsfiler tvinger tier 1"
git checkout -q -b hotfix/H004-gi origin/main; sed -i '/config\/\*\.ini/d' .gitignore; mkdir -p k; echo x > k/a.pem; git add -A -f; c "H004: x" 2026-10-16T14:00:00
o=$(ci hotfix/H004-gi); has "$o" "linjer fjernet" ".gitignore kun tilføjelser"; has "$o" "hemmelige filer" "ci: hemmelig fil"
git checkout -q -B docs/framework-test origin/main; printf 'FRAMEWORK-PLACEHOLDER\n' > README.md; c "docs: x" 2026-10-16T15:00:00
o=$(ci docs/framework-test); has "$o" "pladsholder" "ci: pladsholder"
# låsefil: framework-fil ændret uden for docs/framework-* og afvigende indhold
git checkout -q -B docs/lock origin/main; bash shared/report.sh lock . >/dev/null; c "docs: lock" 2026-10-16T16:00:00; git push -q origin docs/lock:main 2>/dev/null
git fetch -q origin
git checkout -q -B feature/F005-x origin/main; echo "# ændret" >> shared/LIFECYCLE.md; c "F005: x" 2026-10-16T17:00:00
o=$(ci feature/F005-x); has "$o" "frameworkets filer er ændret" "ci: lås på framework-filer"; has "$o" "afviger fra" "ci: checksum"
git checkout -q -B docs/framework-5.9 origin/main; echo "# lokal rettelse" >> agents/team_lead.md; c "docs: rettet" 2026-10-16T18:00:00
o=$(ci docs/framework-5.9); has "$o" "afviger fra" "ci: også docs/framework-branch skal være ren kopi"; hasnt "$o" "kun tilladt som ren kopi" "ci: docs/framework-branch må ændre framework-filer"
# v5.4: stort træ (>64 KB ls-tree) må ikke give falsk "mangler" (SIGPIPE under pipefail)
git checkout -q -B feature/F004-rapporter origin/main 2>/dev/null; git checkout -q feature/F004-rapporter 2>/dev/null
git checkout -q -B feature/F009-stor origin/main
mkdir -p homedir/assets tasks fullstack-ai product-ai homedir/tests tests; for i in $(seq 1 1500); do : > "homedir/assets/en-meget-lang-filnavn-til-at-fylde-pipe-bufferen-op-nummer-$i.txt"; done
printf '# F009: Stor\nType: feature\nKunde: x\nSupport: y\n' > changes/F009.md; printf 'Tier: 3\n' > tasks/impact-F009.md; echo r > fullstack-ai/F009_v1.md
printf -- '- F009: Stor — plan: 2026-10\n' >> ROADMAP.md; c "F009: stor" 2026-10-17T10:00:00
[ "$(git ls-tree -r --name-only HEAD | wc -c)" -gt 70000 ] && ok || bad "fixture: træet er ikke over 64 KB"
o=$(ci feature/F009-stor); has "$o" "F009 (tier 3)" "ci: stort træ (SIGPIPE)"; hasnt "$o" "mangler engineer-rapport" "ci: ingen falsk manglende rapport"
# push til main: samme kontroller med BASE_SHA
o=$(BASE_SHA=$(git rev-parse origin/main) HEAD_REF=feature/F009-stor bash shared/report.sh ci 2>&1); has "$o" "F009 (tier 3)" "ci: push-tilstand (BASE_SHA)"
# omfang: tunge CI-trin kun ved kode/test/kontrolfiler
o=$(BASE_SHA=$(git rev-parse origin/main) bash shared/report.sh scope 2>&1); has "$o" "true" "scope: kodeændring = tunge trin"
git checkout -q -B docs/kun-docs origin/main; mkdir -p tasks; echo x > tasks/note-x.md; c "docs: note" 2026-10-17T10:30:00
o=$(BASE_SHA=$(git rev-parse origin/main) bash shared/report.sh scope 2>&1); has "$o" "false" "scope: kun dokumenter = lette trin"
# v5.13: ikke-installeret driftsfil, som branchen ikke rører = navngivet advarsel (ikke rød); ændret af branchen = rød
git checkout -q -B docs/gammel-ci origin/main; echo "# gammel version" >> homedir/cron/ci.sh; mkdir -p .github/workflows; echo "name: CI" > .github/workflows/ci.yml; c "docs: gammel ci" 2026-10-17T10:40:00
git push -q origin docs/gammel-ci:main 2>/dev/null; git fetch -q origin
git checkout -q -B feature/F012-kode origin/main; mkdir -p tasks fullstack-ai; echo k > homedir/src/k.php
printf '# F012: K\nType: feature\nKunde: x\nSupport: y\n' > changes/F012.md; printf 'Tier: 3\n' > tasks/impact-F012.md; echo r > fullstack-ai/F012_v1.md
printf -- '- F012: K — plan: 2026-10\n' >> ROADMAP.md; c "F012: k" 2026-10-17T10:41:00
o=$(ci feature/F012-kode); rc=$?; has "$o" "ADVARSEL (install): homedir/cron/ci.sh er endnu ikke installeret" "ci: ikke-installeret = navngivet advarsel"
has "$o" "ADVARSEL (install): .github/workflows/ci.yml kører stadig i GitHub Actions" "ci: gammel workflow = advarsel"
has "$o" "F012 (tier 3)" "ci: advarsel blokerer ikke kode-PR"; hasnt "$o" "CI: homedir/cron/ci.sh" "ci: ikke-installeret er ikke rød"; hasnt "$o" "CI: .github" "ci: urørt gammel workflow er ikke rød"
o=$(BASE_REF=main HEAD_REF=feature/F012-kode bash shared/report.sh ci >/dev/null 2>&1; echo "rc=$?"); has "$o" "rc=0" "ci: exit 0 med install-advarsel"
o=$(bash shared/report.sh precheck 2>&1); has "$o" "ADVARSEL (install)" "precheck: viser install-advarslen"; has "$o" "CI: F012 (tier 3)" "precheck: framework-kontrollerne grønne trods ikke-installeret driftsfil"; hasnt "$o" "CI: .github" "precheck: install er ikke rød"
git checkout -q -B hotfix/H013-ci origin/main; echo "# anden rettelse" >> homedir/cron/ci.sh; c "H013: ci" 2026-10-17T10:42:00
o=$(ci hotfix/H013-ci); has "$o" "er ændret og afviger fra shared/install/ci.sh" "ci: branch, der ændrer driftsfil forkert, er rød"
git checkout -q -B hotfix/H014-install origin/main; bash shared/report.sh install >/dev/null; c "H014: install" 2026-10-17T10:43:00
[ ! -e .github/workflows/ci.yml ] && ok || bad "install: fjerner den gamle GitHub Actions-workflow"
o=$(ci hotfix/H014-install); hasnt "$o" "ADVARSEL (install)" "ci: install-hotfix fjerner advarslen"; hasnt "$o" "afviger fra shared/install" "ci: install-hotfix er ikke rød på installationen"
git checkout -q -B docs/framework-5.13 origin/main; echo "# x" >> homedir/cron/ci.sh; c "docs: x" 2026-10-17T10:44:00
o=$(ci docs/framework-5.13); has "$o" "må ikke ændres på docs/framework-5.13" "ci: docs/framework-branch må ikke røre installerede filer"
git push -q origin hotfix/H014-install:main 2>/dev/null; git fetch -q origin
git checkout -q feature/F009-stor
o=$(bash shared/report.sh precheck 2>&1); has "$o" "Framework-kontroller (som CI) for feature/F009-stor" "precheck: kører CI-kontroller lokalt"; has "$o" "PRECHECK:" "precheck: samlet resultat"
# 5.26: precheck kører ikke tests lokalt (ingen database på Windows) — de afgøres af DEV (coworker/pr)
printf '[ci]\ntests = echo KØRTE-TESTS > /dev/null; exit 1\n' > coworker.ini.tmp; o=$(bash shared/report.sh precheck 2>&1)
has "$o" "Tests køres på DEV-serveren (status coworker/pr)" "precheck: ingen lokale tests som standard"; rm -f coworker.ini.tmp
# 5.23: ingen GitHub-adgang (fx et andet projekt skiftede gh-login) er ikke en kodefejl
o=$(R access); has "$o" "ADGANG: OK" "access: adgang OK"
o=$(GH_TOKEN= R access); has "$o" "projektet har ingen egen nøgle" "access: uden projektets nøgle = ingen adgang (intet fælles login)"
o=$(GIT_CONFIG_COUNT=0 R access); has "$o" "git bruger ikke projektets nøgle" "access: git skal bruge projektets nøgle"
git remote set-url --push origin "$T/kan-ikke-skrives.git"; o=$(R access); git remote set-url --delete --push origin "$T/kan-ikke-skrives.git"
has "$o" "git kan ikke skrive" "access: læseadgang alene er ikke ADGANG: OK (Storix 5.24)"
OU=$(git remote get-url origin); git remote set-url origin "$T/findes-ikke.git"
o=$(R access); has "$o" "INGEN GITHUB-ADGANG" "access: manglende adgang siges præcist"
o=$(bash shared/report.sh precheck 2>&1); has "$o" "PRECHECK: RØD — ingen GitHub-adgang, ikke en kodefejl" "precheck: adgang skelnes fra kodefejl"; hasnt "$o" "Framework-kontroller" "precheck: stopper før kontrollerne"
o=$(ci feature/F009-stor); has "$o" "INGEN GITHUB-ADGANG" "ci: fetch-fejl siges som manglende adgang"
git remote set-url origin "$OU"
# ID med bogstav-suffiks
git checkout -q -B feature/F061a-x origin/main; echo a > homedir/src/a.php; c "F061a: x" 2026-10-17T11:00:00
o=$(ci feature/F061a-x); has "$o" "mangler tasks/impact-F061a.md" "ci: ID-suffiks genkendt"; hasnt "$o" "branchen skal hedde" "ci: F061a er gyldigt branch-navn"
# tier 1: critic-review skal gælde denne branch; known-failures højst 30 dage frem
git checkout -q -B feature/F010-t1 origin/main; mkdir -p tasks fullstack-ai product-ai homedir/tests tests
printf '# System understanding\n- Regler: shared/LIFECYCLE.md (§3)\n- CI: shared/report.sh (cmd_ci)\n' > fullstack-ai/F010_v0.md; c "F010: forståelse" 2026-10-17T11:30:00; echo t > homedir/src/t.php
printf '# F010: T\nType: feature\nKunde: x\nSupport: y\n' > changes/F010.md; printf 'Tier: 1\n' > tasks/impact-F010.md; echo r > fullstack-ai/F010_v1.md
printf '# Spec\n- AK1: x\n' > product-ai/F010_v1.md; printf '<?php // F010-AK1\n' > homedir/tests/T10.php
mkdir -p qa-critic security-critic
for d in qa-critic security-critic; do printf 'Branch: feature/F099-andet @ abc\nStatus: APPROVED\n' > $d/F010_v1.md; done
printf -- '- F010: T — plan: 2026-10\n' >> ROADMAP.md
printf 'X::y | H002 | %s\n' "$(date -d '+60 days' +%F)" > tests/known-failures.txt
c "F010: t" 2026-10-17T12:00:00
o=$(ci feature/F010-t1); has "$o" "gælder ikke denne branch" "ci: review fra anden PR afvises"; has "$o" "mere end 30 dage frem" "ci: known-failures loft"; has "$o" "tier 1" "ci: known-failures tvinger tier 1"
RS=$(git rev-parse HEAD)
for d in qa-critic security-critic; do printf 'Branch: feature/F010-t1 @ %s\nStatus: APPROVED\n' "$RS" > $d/F010_v1.md; done
printf 'X::y | H002 | %s\n' "$(date -d '+10 days' +%F)" > tests/known-failures.txt; c "F010: fix" 2026-10-17T13:00:00
o=$(ci feature/F010-t1); has "$o" "F010 (tier 1)" "ci: tier 1 grøn med rigtig Branch og SHA"
echo "ændret efter review" >> homedir/src/t.php; c "F010: ændring" 2026-10-17T13:30:00
o=$(ci feature/F010-t1); has "$o" "koden er ændret efter" "ci: review gælder kun den reviewede kode"
# immutabelt spor: et merget fragment må ikke ændres
git checkout -q -B docs/x origin/main; echo "ændret" >> changes/F001.md; c "docs: x" 2026-10-17T13:40:00
o=$(ci docs/x); has "$o" "sporet er immutabelt" "ci: immutabelt spor"
# .gitignore: linjer erstattet af frameworket må fjernes
git checkout -q -B hotfix/H005-gi origin/main; printf 'logs/\n' >> .gitignore; c "H005: a" 2026-10-17T14:00:00; git push -q origin hotfix/H005-gi:main 2>/dev/null; git fetch -q origin
sed -i '/^logs\/$/d' .gitignore; c "H005: b" 2026-10-17T15:00:00
o=$(ci hotfix/H005-gi); hasnt "$o" "linjer fjernet" ".gitignore: #erstattet-linje må fjernes"
# .gitignore: en flyttet linje er ikke fjernet
git checkout -q -B hotfix/H006-flyt origin/main; l=$(grep -m1 'config/\*\.ini' .gitignore); grep -vxF "$l" .gitignore > g.tmp; { echo "$l"; cat g.tmp; } > .gitignore; rm g.tmp; c "H006: flyt" 2026-10-17T15:30:00
o=$(ci hotfix/H006-flyt); hasnt "$o" "linjer fjernet" ".gitignore: flyttet linje er ikke fjernet"
# roadmap i tabelrækker og 'Før frameworket'
git checkout -q main 2>/dev/null || git checkout -q -B main origin/main
cp ROADMAP.md "$T/rm.bak"; printf '## Nu\n| ID | Titel | Plan |\n|---|---|---|\n| F002 | Eksport | plan: 2026-10 |\n## Før frameworket\n| R1 | Gammel feature |\n' > ROADMAP.md
o=$(R roadmap); has "$o" "| F002 | Eksport | plan: 2026-10 | · status: Merged" "roadmap: tabelrækker læses"; has "$o" "Bygget før frameworket" "roadmap: før-frameworket-sektion"
cp "$T/rm.bak" ROADMAP.md
o=$(R check); echo "$o" > "$T/check.out"; has "$o" "health-URL for stable mangler" "check: health"; has "$o" "ACCESS.md mangler" "check: adgangsregister"
# tokenen bygges, så frameworkets egen fil aldrig indeholder en (gitleaks i projekternes CI)
fake="gh""p_$(printf 'a%.0s' $(seq 1 36))"; printf 'token = "%s"\n' "$fake" > leak.txt; o=$(R secrets); has "$o" "MULIG HEMMELIGHED: leak.txt" "secrets: scan finder token"; rm -f leak.txt
# 5.36 (econsync): en fil, der er byte-identisk med en låst framework-fil (fx den installerede ci.sh), er ikke en hemmelighed
cp shared/install/ci.sh kopi-ci.sh; cp shared/selftest-ci.sh kopi-st.sh; o=$(R secrets)
hasnt "$o" "MULIG HEMMELIGHED: kopi-ci.sh" "secrets: låst ci.sh (kommentar om github.token) giver ikke falsk alarm"; hasnt "$o" "MULIG HEMMELIGHED: kopi-st.sh" "secrets: låste testfixtures giver ikke falsk alarm"
printf 'token = "%s"\n' "$fake" >> kopi-ci.sh; o=$(R secrets); has "$o" "MULIG HEMMELIGHED: kopi-ci.sh" "secrets: ændret kopi af en låst fil scannes stadig"; rm -f kopi-ci.sh kopi-st.sh
# 5.42: en ctrl-fil gengiver låste linjer i sin diff (+, -, kontekst) — de er masterens tekst; en ny linje scannes stadig
mkdir -p launched/releases; l=$(grep -m1 'config/github.token: ' shared/install/ci.sh)
printf '```diff\n+%s\n-%s\n %s\n```\n' "$l" "$l" "$l" > launched/releases/ctrl-test-1.md; o=$(R secrets)
hasnt "$o" "MULIG HEMMELIGHED: launched/releases/ctrl-test-1.md" "secrets: låst linje i ctrl-diff giver ikke falsk alarm"
printf '+token = "%s"\n' "$fake" >> launched/releases/ctrl-test-1.md; o=$(R secrets)
has "$o" "MULIG HEMMELIGHED: launched/releases/ctrl-test-1.md linje 6" "secrets: ny token-linje i ctrl-diff findes stadig"; rm -f launched/releases/ctrl-test-1.md
# første release på et kørende system: /health findes ikke endnu → bevisfil godtages
git checkout -q -B docs/bevis origin/main; mkdir -p launched/STABLE
printf '[health]\nstable = http://127.0.0.1:9/health\n' > coworker.ini
printf 'backup_last_ok %s\nrestore_last_ok %s 4\n' "$(date +%F)" "$(date +%F)" > launched/STABLE/foerste-release-bevis.txt
c "docs: bevis" 2026-10-17T16:00:00; echo '[]' > "$T/empty.json"
o=$(RELJSON="$T/empty.json" R notes v2026.10.30-1 HEAD); f=launched/releases/v2026.10.30-1.md
has "$(cat $f)" "Bevis for backup/restore: launched/STABLE/foerste-release-bevis.txt" "notes: første-release-bevis læses"
hasnt "$(cat $f)" "ingen bevist restore" "notes: bevisfil dækker restore"
o=$(R check 2>&1); has "$o" "STABLE /health svarer ikke herfra" "check: uopnåelig /health siges som netværk (5.22)"; hasnt "$o" "Ingen backup det sidste døgn" "check: uopnåelig /health er ikke en backup-fejl"
git checkout -q -b docs/uden-bevis; git rm -q launched/STABLE/foerste-release-bevis.txt; c "docs: uden bevis" 2026-10-17T16:10:00
o=$(RELJSON="$T/empty.json" R notes v2026.10.30-9 HEAD); f=launched/releases/v2026.10.30-9.md
has "$(cat $f)" "STABLE /health svarer ikke herfra" "notes: uopnåelig /health blokerer med den rigtige grund"; hasnt "$(cat $f)" "ingen backup det sidste døgn" "notes: ikke en falsk backup-fejl"
rm -f "$f"; git checkout -q -; git branch -q -D docs/uden-bevis
# backup uden for frameworket: [backup] external i coworker.ini
git checkout -q -B docs/ekstern origin/main; printf '[health]\nstable = http://127.0.0.1:9/health\n[backup]\nexternal = hostingens daglige snapshot\n' > coworker.ini
c "docs: ekstern" 2026-10-17T16:30:00
o=$(R notes v2026.10.31-1 HEAD); f=launched/releases/v2026.10.31-1.md
has "$(cat $f)" "Backup: ekstern (hostingens daglige snapshot)" "notes: ekstern backup vises"; hasnt "$(cat $f)" "ingen bevist restore" "notes: ekstern backup kræver ikke restore-bevis"
# v5.14: DEV-rollback — lås DEV til en tidligere main-commit (tagget dev), og følg main igen
git checkout -q -B mainx origin/main; git fetch -q origin
F1=$(git log --first-parent --format=%H --grep='^F001:' origin/main | tail -1)
o=$(R devpin før F001); has "$o" "DEV ER LÅST til $(git rev-parse --short "$F1^")" "devpin: før <ID> = commit før ID'ets første main-commit"
has "$o" "F001: login" "devpin: viser hvad der fjernes fra DEV"; has "$o" "Databasen rulles IKKE tilbage" "devpin: siger at DB ikke rulles tilbage"
[ "$(git ls-remote -q origin refs/tags/dev | cut -f1)" = "$(git rev-parse "$F1^")" ] && ok || bad "devpin: tagget dev peger på målet"
o=$(R devpin "$(git rev-parse HEAD~0^{tree} 2>/dev/null | cut -c1-7)x"); has "$o" "ukendt commit" "devpin: ukendt commit afvises"
git checkout -q -B sidegren origin/main; echo s > homedir/src/s.php; c "H099: side" 2026-10-18T10:00:00
o=$(R devpin HEAD); has "$o" "er ikke på main" "devpin: kun main-versioner"
o=$(R devlog 5); has "$o" "låst til $(git rev-parse --short "$F1^")" "devlog: viser låsen"
o=$(R status); has "$o" "DEV:     LÅST til" "status: viser DEV-lås"
o=$(R devpin off); has "$o" "DEV følger main igen" "devpin off"; [ -z "$(git ls-remote -q origin refs/tags/dev)" ] && ok || bad "devpin off: tagget dev er fjernet"
# v5.16: check læser CI-status fra DEV-serveren (commit-status coworker/ci)
o=$(CISTATE= R check); has "$o" "har ingen CI-status fra DEV-serveren" "check: manglende CI-status på main"
o=$(CISTATE=failure R check); has "$o" "er RØD i CI (failure)" "check: rød main"
o=$(CISTATE=success R check); hasnt "$o" "CI-status" "check: grøn main giver ingen linje"
# v5.17: flere fragmenter til samme ID (F020.md + F020-2.md) må ikke stoppe notes (SIGPIPE under pipefail)
git checkout -q -B feature/F020-to origin/main; mkdir -p changes
printf '\n[F020_to]\ndev = on\ntest = on\nstable = on\n' >> homedir/config/features.ini
printf '# F020: To\nType: feature\nKunde: Første kundetekst.\nSupport: s1\n' > changes/F020.md
{ printf '# F020: To, del 2\nType: feature\nType: feature\nKunde: Anden kundetekst.\nSupport: s2\n'; for i in $(seq 1 3000); do echo "linje $i med fyld til pipe-bufferen"; done; } > changes/F020-2.md
c "F020: to fragmenter" 2026-10-18T12:00:00
o=$(RELJSON="$T/empty.json" R notes v2026.10.31-9 HEAD); f=launched/releases/v2026.10.31-9.md
has "$(cat $f 2>/dev/null)" "Første kundetekst." "notes: kundetekst med to fragmenter"; has "$(cat $f 2>/dev/null)" "Launch-tjek:" "notes: når til launch-tjek med to fragmenter"
o=$(bash -c 'set -euo pipefail; nocr() { tr -d "\r"; }; source <(sed -n "/^fragment_field()/,/^}/p" shared/report.sh); fragment_field F020 HEAD Kunde'); [ "$o" = "Første kundetekst." ] && ok || bad "fragment_field: første fragments værdi (fik '$o')"
# v5.17: undertrykkelses-ord i dokumentation (*.md) gør ikke en docs-PR til kode
git checkout -q -B docs/review-citat origin/main; mkdir -p qa-critic; printf 'Reviewet: ingen nosemgrep eller phpstan-ignore fundet.\n' > qa-critic/note.md; c "docs: citat" 2026-10-18T12:10:00
o=$(ci docs/review-citat); hasnt "$o" "branchen skal hedde" "ci: undertrykkelses-ord i .md tæller ikke"
git checkout -q -B docs/kode-undertryk origin/main; echo '<?php // nosemgrep' > homedir/src/x.php; c "docs: x" 2026-10-18T12:11:00
o=$(ci docs/kode-undertryk); has "$o" "branchen skal hedde" "ci: undertrykkelse i kode tvinger stadig tier 1"
# critics, der aldrig finder noget, ses i check (5.22)
git checkout -q -B docs/reviews origin/main; mkdir -p qa-critic; for i in $(seq 101 110); do printf 'Branch: feature/F%s-x @ abc1234\nStatus: APPROVED\n' $i > qa-critic/F${i}_v1.md; done
c "docs: reviews" "$(date -d '-5 days' +%FT10:00:00)"; git push -q origin docs/reviews:main 2>/dev/null; git fetch -q origin
o=$(R check); has "$o" "qa-critic: 10 reviews de sidste 90 dage og ingen med CHANGES_REQUIRED" "check: critic uden fund i 10 reviews vises"; hasnt "$o" "security-critic: " "check: få reviews giver ingen advarsel"
# v5.15: første framework-kopi og install i et kørende projekt (migrationer før frameworket)
cd "$T"; git init -q --bare o2.git; git clone -q o2.git r2 2>/dev/null; cd r2
git config user.email t@t; git config user.name T; git checkout -q -b main
echo "# Projekt" > README.md; cp "$M/shared/gitignore.framework" .gitignore; c "start" 2026-10-18T09:00:00; git push -q origin main 2>/dev/null
git checkout -q -b docs/framework-5.15; cp -r "$M/shared" "$M/agents" "$M/CLAUDE.md" "$M/OPSAETNING.md" .; c "docs: framework" 2026-10-18T09:10:00
o=$(ci docs/framework-5.15); hasnt "$o" "branchen skal hedde" "første kopi: frameworkets dokumentation tæller ikke som undertrykkelse"
git push -q origin docs/framework-5.15:main 2>/dev/null; git fetch -q origin
git checkout -q -b hotfix/H017-framework origin/main; mkdir -p homedir/migrations homedir/src tasks fullstack-ai product-ai
for i in 0001 0002 0116; do echo "CREATE TABLE t$i(i int);" > homedir/migrations/${i}_v1.0.0_gammel.sql; done; echo a > homedir/src/a.php
mkdir -p changes; printf '# H017: Framework\nType: fejl\nKunde: -\n' > changes/H017.md
printf 'Tier: 1\n' > tasks/impact-H017.md; echo r > fullstack-ai/H017_v1.md; echo s > product-ai/H017_v1.md
printf '[ci]\nphp = 8.2\n' > coworker.ini; c "H017: app ind" 2026-10-18T09:20:00
o=$(ci hotfix/H017-framework); has "$o" "0116_v1.0.0_gammel.sql mangler headerlinjen" "baseline: uden baseline kræves headers"
printf '[ci]\nphp = 8.2\n[migrations]\nbaseline = 0200\n' > coworker.ini; c "H017: for høj" 2026-10-18T09:21:00
o=$(ci hotfix/H017-framework); has "$o" "højere end den højeste migration i repoet (116)" "baseline: kan ikke sættes over højeste migration"
printf '[ci]\nphp = 8.2\n[migrations]\nbaseline = 0116\n' > coworker.ini; c "H017: baseline" 2026-10-18T09:22:00
o=$(ci hotfix/H017-framework); hasnt "$o" "mangler headerlinjen" "baseline: kørte migrationer undtages ved første import"; hasnt "$o" "baseline" "baseline: første import godkendt"
has "$o" "tier 1" "baseline: kræver tier 1 (coworker.ini er kontrolfil)"
git push -q origin hotfix/H017-framework:main 2>/dev/null; git fetch -q origin
git checkout -q -B hotfix/H018-ny origin/main; printf -- '-- reversibel: nej\n-- destruktiv: nej\n-- idempotent: ja\nSELECT 1;\n' > homedir/migrations/0050_v1.1.0_snyd.sql; c "H018: x" 2026-10-18T09:30:00
o=$(ci hotfix/H018-ny); has "$o" "har nummer ≤ [migrations] baseline (0116)" "baseline: ny migration under baseline afvises"
git checkout -q -B hotfix/H019-ny origin/main; echo "SELECT 1;" > homedir/migrations/0117_v1.1.0_ny.sql; c "H019: x" 2026-10-18T09:31:00
o=$(ci hotfix/H019-ny); has "$o" "0117_v1.1.0_ny.sql mangler headerlinjen" "baseline: nye migrationer over baseline kræver headers"
sed -i 's/baseline = 0116/baseline = 0117/' coworker.ini; c "H019: hæv" 2026-10-18T09:32:00
o=$(ci hotfix/H019-ny); has "$o" "dækker homedir/migrations/0117_v1.1.0_ny.sql, som ikke lå på main" "baseline: kan ikke hæves over en ny migration"
# 5.36 (econsync): ord, som MariaDB 12.3 har reserveret, skal stå i backticks i nye migrationer
git checkout -q -B hotfix/H019-ord origin/main
printf -- '-- reversibel: nej\n-- destruktiv: nej\n-- idempotent: ja\n-- to_date i en kommentar\nCREATE TABLE p (id INT, to_date DATE, `conversion` INT, n VARCHAR(9) DEFAULT %s);\nSELECT TO_DATE(%s), p.to_date FROM p;\n' "'to_date'" "'x'" > homedir/migrations/0118_v1.1.0_ord.sql; c "H019: ord" 2026-10-18T09:31:30
o=$(ci hotfix/H019-ord); has "$o" "0118_v1.1.0_ord.sql linje 5: to_date — reserveret i MariaDB 12.3+" "reserverede ord: uciteret to_date afvises"
hasnt "$o" "linje 6:" "reserverede ord: funktion, p.to_date, streng og kommentar tæller ikke"; hasnt "$o" "conversion —" "reserverede ord: i backticks er tilladt"
git checkout -q -B hotfix/H020-tal origin/main; sed -i 's/baseline = 0116/baseline = sidste/' coworker.ini; c "H020: x" 2026-10-18T09:33:00
o=$(ci hotfix/H020-tal); has "$o" "skal være et migrationsnummer" "baseline: skal være et tal"
# 5.20 (econsync H012): ny framework-version = kopi + 'report.sh install' i ÉN tier 1-hotfix; projektets egen
# byte-identitetstest (homedir/cron/* == shared/install/*) må ikke gøre installationen umulig at merge.
git checkout -q -B hotfix/H021-install origin/main; mkdir -p tests
cat > tests/run.sh <<'RUN'
#!/bin/bash
r=0; { echo '<testsuite>'; for f in ci monitor deploy backup; do
  if cmp -s "homedir/cron/$f.sh" "shared/install/$f.sh"; then echo "<testcase classname=\"Ident\" name=\"$f\"/>"
  else echo "<testcase classname=\"Ident\" name=\"$f\"><failure>afviger</failure></testcase>"; r=1; fi; done
  echo '<testcase classname="Gammel" name="kendt"><failure>x</failure></testcase>'; echo '</testsuite>'; } > junit.xml
exit 1   # testløberen fejler altid (den kendte røde) — dømmes på junit + known-failures, som cron/ci.sh
RUN
printf '[ci]\nphp = 8.2\ntests = bash tests/run.sh\n[migrations]\nbaseline = 0116\n' > coworker.ini
printf 'Gammel::kendt | H002 | %s\n' "$(date -d '+10 days' +%F)" > tests/known-failures.txt
mkdir -p homedir/cron; echo '# gammel backup.sh' > homedir/cron/backup.sh   # econsync har sin egen backup.sh fra før 5.27
bash shared/report.sh install >/dev/null; c "H021: install" 2026-10-18T10:00:00
cmp -s homedir/cron/backup.sh shared/install/backup.sh && pass=$((pass+1)) || bad "install opdaterer ikke en eksisterende backup.sh (5.27)"
o=$(PRECHECK_TESTS=1 bash shared/report.sh precheck 2>&1); has "$o" "5 tests, 1 røde/sprunget over, 0 nye" "fixture: H021-branchen kører projektets testløber" ; rm -f junit.xml
git push -q origin hotfix/H021-install:main 2>/dev/null; git fetch -q origin
git checkout -q -B feature/F030-x origin/main; echo b > homedir/src/b.php; mkdir -p tasks fullstack-ai
printf '# F030: X\nType: feature\nKunde: x\nSupport: y\n' > changes/F030.md; printf 'Tier: 3\n' > tasks/impact-F030.md; echo r > fullstack-ai/F030_v1.md
printf -- '- F030: X — plan: 2026-10\n' >> ROADMAP.md; c "F030: x" 2026-10-18T10:05:00
o=$(PRECHECK_TESTS=1 bash shared/report.sh precheck 2>&1); has "$o" "PRECHECK: GRØN" "precheck: rød testløber-exit, men kun kendte røde i junit = GRØN (som cron/ci.sh)"
has "$o" "0 nye" "precheck: dømmer på junit + known-failures"; rm -f junit.xml
# ny version i master: en driftsfil og en doc ændret, låsen regenereret
NM="$T/master-ny"; rm -rf "$NM"; mkdir -p "$NM"; cp -r shared agents CLAUDE.md OPSAETNING.md "$NM/"
echo "# ny version" >> "$NM/shared/install/ci.sh"; echo "# ny" >> "$NM/agents/team_lead.md"; bash "$NM/shared/report.sh" lock "$NM" >/dev/null
# gammel vej: kun kopien (docs/framework-*) → projektets byte-identitetstest bliver rød
git checkout -q -B docs/framework-5.99 origin/main; cp -r "$NM/shared" "$NM/agents" "$NM/CLAUDE.md" "$NM/OPSAETNING.md" .; c "docs: framework 5.99" 2026-10-18T10:10:00
o=$(PRECHECK_TESTS=1 bash shared/report.sh precheck 2>&1); has "$o" "Ident::ci" "fixture: kopi alene gør byte-identitetstesten rød (econsync H012)"; rm -f junit.xml
# ny vej: kopi + install i én hotfix/H###-framework-<version> → GRØN
git checkout -q -B hotfix/H022-framework-5.99 origin/main; cp -r "$NM/shared" "$NM/agents" "$NM/CLAUDE.md" "$NM/OPSAETNING.md" .
bash shared/report.sh install >/dev/null; mkdir -p changes tasks fullstack-ai product-ai qa-critic security-critic
printf '# H022: Framework 5.99\nType: fejl\nKunde: -\n' > changes/H022.md; printf 'Tier: 1\n' > tasks/impact-H022.md; echo r > fullstack-ai/H022_v1.md; echo s > product-ai/H022_v1.md
c "H022: framework 5.99 + install" 2026-10-18T10:20:00; RS=$(git rev-parse HEAD)
for d in qa-critic security-critic; do printf 'Branch: hotfix/H022-framework-5.99 @ %s\nStatus: APPROVED\n' "$RS" > $d/H022_v1.md; done; c "H022: reviews" 2026-10-18T10:21:00
o=$(ci hotfix/H022-framework-5.99); has "$o" "CI: H022 (tier 1)" "ny version: kopi + install i én hotfix er GRØN i CI"
hasnt "$o" "kun tilladt som ren kopi" "ny version: hotfix/H###-framework-* må bære framework-kopien"; hasnt "$o" "ADVARSEL (install)" "ny version: installeret i samme merge"
o=$(PRECHECK_TESTS=1 bash shared/report.sh precheck 2>&1); has "$o" "PRECHECK: GRØN" "ny version: precheck GRØN med projektets byte-identitetstest"; rm -f junit.xml
# stadig låst: afvigende kopi, framework-filer på en almindelig hotfix, installeret fil ændret på hotfixen
echo "# lokal rettelse" >> agents/team_lead.md; c "H022: snyd" 2026-10-18T10:22:00
o=$(ci hotfix/H022-framework-5.99); has "$o" "framework-filer afviger fra" "ny version: kopien skal stadig være byte-identisk med master"
git checkout -q -B hotfix/H023-andet origin/main; cp -r "$NM/shared" "$NM/agents" .; bash shared/report.sh install >/dev/null; c "H023: x" 2026-10-18T10:23:00
o=$(ci hotfix/H023-andet); has "$o" "kun tilladt som ren kopi" "ny version: kun hotfix/H###-framework-* må ændre framework-filer"
git checkout -q -B hotfix/H024-framework-5.99 origin/main; cp -r "$NM/shared" "$NM/agents" "$NM/CLAUDE.md" "$NM/OPSAETNING.md" .
bash shared/report.sh install >/dev/null; echo "# egen" >> homedir/cron/ci.sh; c "H024: x" 2026-10-18T10:24:00
o=$(ci hotfix/H024-framework-5.99); has "$o" "er ændret og afviger fra shared/install/ci.sh" "ny version: installeret fil skal være uændret kopi"
# 5.21 (deskcore): Windows (core.filemode=false) mister x-bit — install sætter den i git, CI fanger den
git checkout -q -B hotfix/H025-mode origin/main; git update-index --chmod=-x homedir/cron/ci.sh; GIT_COMMITTER_DATE=2026-10-18T10:30:00 git commit -q -m "H025: mode"; chmod -x homedir/cron/ci.sh
o=$(ci hotfix/H025-mode); has "$o" "homedir/cron/ci.sh mangler execute-bit i git" "filmode: branch, der fjerner x-bit, er rød"
git push -q origin hotfix/H025-mode:main 2>/dev/null; git fetch -q origin
git checkout -q -B feature/F031-y origin/main; echo c > homedir/src/c.php; mkdir -p tasks fullstack-ai
printf '# F031: Y\nType: feature\nKunde: x\nSupport: y\n' > changes/F031.md; printf 'Tier: 3\n' > tasks/impact-F031.md; echo r > fullstack-ai/F031_v1.md
printf -- '- F031: Y — plan: 2026-10\n' >> ROADMAP.md; c "F031: y" 2026-10-18T10:31:00
o=$(ci feature/F031-y); has "$o" "ADVARSEL (install): homedir/cron/ci.sh mangler execute-bit" "filmode: urørt fil uden x-bit = advarsel"; has "$o" "F031 (tier 3)" "filmode: advarslen blokerer ikke"
git checkout -q -B hotfix/H026-framework-win origin/main; git config core.filemode false; bash shared/report.sh install >/dev/null; c "H026: install" 2026-10-18T10:32:00
[ "$(git ls-tree HEAD -- homedir/cron/ci.sh | cut -c1-6)" = 100755 ] && ok || bad "filmode: install sætter x-bit i git, også med core.filemode=false"
o=$(ci hotfix/H026-framework-win); hasnt "$o" "execute-bit" "filmode: efter install ingen advarsel"; git config core.filemode true
# 5.22 (gennemgang): System understanding før koden, regressionstest i hotfix, accepterede sikkerhedsfund i DECISIONS
proc() {  # $1=ID $2=tier: fragment, impact, spec, rapport
  mkdir -p changes tasks product-ai fullstack-ai; printf '# %s: X\nType: feature\nKunde: x\nSupport: y\n' "$1" > changes/$1.md; printf 'Tier: %s\n' "$2" > tasks/impact-$1.md
  printf '# Spec\n' > product-ai/$1_v1.md; echo r > fullstack-ai/$1_v1.md; }
v0() { mkdir -p fullstack-ai; printf '# System understanding\n- shared/LIFECYCLE.md §3\n- shared/report.sh cmd_ci\n' > fullstack-ai/$1_v0.md; }
rev() { mkdir -p "$1"; printf 'Branch: %s @ %s\nStatus: APPROVED\n%s' "$3" "$(git rev-parse HEAD)" "${4:-}" > "$1/$2_v1.md"; }
git checkout -q -B hotfix/H030-sen origin/main; mkdir -p homedir/src; echo s > homedir/src/sen.php; c "H030: kode" 2026-10-18T11:00:00
v0 H030; proc H030 2; echo t > tests/SenTest.php; c "H030: forståelse bagefter" 2026-10-18T11:01:00; rev qa-critic H030 hotfix/H030-sen; c "H030: review" 2026-10-18T11:02:00
o=$(ci hotfix/H030-sen); has "$o" "er committet EFTER kode i homedir/" "v0: System understanding skrevet efter koden afvises"
git checkout -q -B hotfix/H031-kilder origin/main; mkdir -p fullstack-ai; printf '# System understanding\nJeg antager, at det virker.\n' > fullstack-ai/H031_v0.md; c "H031: v0" 2026-10-18T11:10:00
echo k > homedir/src/k.php; echo t > tests/KTest.php; proc H031 2; c "H031: kode" 2026-10-18T11:11:00; rev qa-critic H031 hotfix/H031-kilder; c "H031: r" 2026-10-18T11:12:00
o=$(ci hotfix/H031-kilder); has "$o" "henviser kun til 0 fil(er)" "v0: påstande uden filkilder afvises"
git checkout -q -B hotfix/H032-fejl origin/main; v0 H032; c "H032: v0" 2026-10-18T11:20:00; echo f > homedir/src/f.php; proc H032 2; c "H032: kode" 2026-10-18T11:21:00
rev qa-critic H032 hotfix/H032-fejl; c "H032: r" 2026-10-18T11:22:00
o=$(ci hotfix/H032-fejl); has "$o" "uden en ny eller ændret test" "hotfix: kræver regressionstest"; hasnt "$o" "mangler fullstack-ai/H032_v0.md" "v0: committet først godtages"
printf 'Ingen test: fejlen sad i en ekstern API-kvote, kan ikke genskabes i test\n' >> changes/H032.md; c "H032: grund" 2026-10-18T11:23:00
o=$(ci hotfix/H032-fejl); has "$o" "CI: H032 (tier 2)" "hotfix: 'Ingen test: <grund>' er en synlig undtagelse"
git checkout -q -B hotfix/H033-sik origin/main; v0 H033; c "H033: v0" 2026-10-18T11:30:00; echo g > homedir/src/g.php; mkdir -p homedir/tests; echo t > homedir/tests/GTest.php; proc H033 2; c "H033: kode" 2026-10-18T11:31:00
rev security-critic H033 hotfix/H033-sik "$(printf '# Severity\nLOW\n# Risk\nlog afslører e-mail\n')"; c "H033: r" 2026-10-18T11:32:00
o=$(ci hotfix/H033-sik); has "$o" "godkender med 1 MEDIUM/LOW-fund, men" "security: accepteret LOW-fund kræver DECISIONS"; hasnt "$o" "uden en ny eller ændret test" "hotfix: test i homedir/tests/ tæller"
mkdir -p launched/STABLE; printf '# Beslutninger\n\n## #1 Logning af e-mail (H033)\nAccepteret risiko: LOW fra security-critic. Udløber: %s\n' "$(date -d '+60 days' +%F)" > launched/STABLE/DECISIONS.md; c "H033: decisions" 2026-10-18T11:33:00
o=$(ci hotfix/H033-sik); has "$o" "CI: H033 (tier 2)" "security: LOW-fund med DECISIONS + Udløber godtages"
git checkout -q -B hotfix/H034-revert-h033 origin/main; echo r > homedir/src/g.php; proc H034 2; rev qa-critic H034 hotfix/H034-revert-h033; c "H034: revert" 2026-10-18T11:40:00
o=$(ci hotfix/H034-revert-h033); hasnt "$o" "_v0.md" "revert: ingen v0 (nødvejen)"; hasnt "$o" "uden en ny eller ændret test" "revert: ingen regressionstest"
# 5.34 (Storix): ét accepteret risiko-afsnit pr. fund, ikke ét pr. ID
git checkout -q -B hotfix/H035-to origin/main; v0 H035; c "H035: v0" 2026-10-18T11:50:00; echo h > homedir/src/h.php; mkdir -p homedir/tests; echo t > homedir/tests/HTest.php; proc H035 2; c "H035: kode" 2026-10-18T11:51:00
rev security-critic H035 hotfix/H035-to "$(printf '## Fund 1\nSeverity: LOW\nlog afslører e-mail\n## Fund 2\nSeverity: MEDIUM\nrate limit mangler\n')"; c "H035: r" 2026-10-18T11:52:00
mkdir -p launched/STABLE; printf '# Beslutninger\n\n## #1 Logning (H035)\nAccepteret risiko: LOW. Udløber: %s\n' "$(date -d '+60 days' +%F)" > launched/STABLE/DECISIONS.md; c "H035: d1" 2026-10-18T11:53:00
o=$(ci hotfix/H035-to); has "$o" "godkender med 2 MEDIUM/LOW-fund, men launched/STABLE/DECISIONS.md har 1" "security: to fund kræver to accepterede risici"
printf '\n## #2 Rate limit (H035)\nAccepteret risiko: MEDIUM. Udløber: %s\n' "$(date -d '+30 days' +%F)" >> launched/STABLE/DECISIONS.md; c "H035: d2" 2026-10-18T11:54:00
o=$(ci hotfix/H035-to); has "$o" "CI: H035 (tier 2)" "security: ét DECISIONS-afsnit pr. fund godtages"
# 5.35: report.sh kode læser coworker/kode på branchens seneste commit
git push -q origin hotfix/H035-to 2>/dev/null
o=$(CISTATE=success R kode hotfix/H035-to); has "$o" "KODE: GRØN" "kode: grøn status"
o=$(CISTATE=failure R kode hotfix/H035-to); has "$o" "KODE: RØD" "kode: rød status"
o=$(CISTATE= KODE_POLL=0 KODE_MAX_MIN=0 R kode hotfix/H035-to); has "$o" "KODE: VENTER" "kode: ingen status = venter, kør igen"
# 5.35: precheck før critics — manglende reviews er en note, ikke rød; CI (coworker/pr) kræver dem stadig
git checkout -q -B hotfix/H036-pre origin/main; v0 H036; c "H036: v0" 2026-10-18T11:55:00; echo p > homedir/src/p.php; mkdir -p homedir/tests; echo t > homedir/tests/PTest.php; proc H036 2; c "H036: kode" 2026-10-18T11:56:00
o=$(bash shared/report.sh precheck 2>&1); has "$o" "VENTER (kommer efter critics): tier 2: mangler én critic-review" "precheck: manglende review er en note"; has "$o" "PRECHECK: GRØN" "precheck: grøn før critics"
o=$(ci hotfix/H036-pre); has "$o" "CI: tier 2: mangler én critic-review" "ci: manglende review er stadig rødt"
# 5.26: PR'en opdateres kun med main, når main har ændret kode eller der er konflikt
git checkout -q -B feature/F040-opd origin/main; echo a > homedir/src/opd.php; c "F040: x" 2026-10-18T12:00:00; git push -q origin feature/F040-opd 2>/dev/null
git checkout -q -B docs/kun-docs-2 origin/main; echo n > tasks/note-2.md; c "docs: n" 2026-10-18T12:01:00; git push -q origin docs/kun-docs-2:main 2>/dev/null
o=$(R behov-opdatering feature/F040-opd); has "$o" "IKKE NØDVENDIGT" "behov-opdatering: kun docs på main = ingen opdatering"
git checkout -q -B docs/kode-2 origin/main; git fetch -q origin; git reset -q --hard origin/main; echo b > homedir/src/andet.php; c "H099: kode" 2026-10-18T12:02:00; git push -q origin docs/kode-2:main 2>/dev/null
o=$(R behov-opdatering feature/F040-opd); has "$o" "OPDATÉR: main har ændret kode" "behov-opdatering: kode på main = opdatér"
git fetch -q origin; git checkout -q -B docs/konf origin/main; echo konflikt > homedir/src/opd.php; c "H098: konf" 2026-10-18T12:03:00; git push -q origin docs/konf:main 2>/dev/null
o=$(R behov-opdatering feature/F040-opd); has "$o" "OPDATÉR: konflikt med main" "behov-opdatering: konflikt = opdatér"
# 5.28: arbejdets art — Type: i hvert nyt fragment; hotfix kræver "Hvorfor nu:"
git checkout -q -B hotfix/H035-type origin/main; v0 H035; c "H035: v0" 2026-10-18T12:10:00; echo h > homedir/src/h.php; echo t > tests/HTest.php; proc H035 2
printf '# H035: X\nKunde: x\n' > changes/H035.md; c "H035: kode" 2026-10-18T12:11:00; rev qa-critic H035 hotfix/H035-type; c "H035: r" 2026-10-18T12:12:00
o=$(ci hotfix/H035-type); has "$o" "mangler linjen 'Type: feature|fejl|hotfix|gæld|sikkerhed'" "type: fragment uden Type afvises"
printf '# H035: X\nType: hotfix\nKunde: x\n' > changes/H035.md; c "H035: type" 2026-10-18T12:13:00
o=$(ci hotfix/H035-type); has "$o" "kræver linjen 'Hvorfor nu:" "type: hotfix kræver 'Hvorfor nu:'"
printf 'Hvorfor nu: kunder kunne ikke logge ind\n' >> changes/H035.md; c "H035: hvorfor" 2026-10-18T12:14:00
o=$(ci hotfix/H035-type); has "$o" "CI: H035 (tier 2)" "type: hotfix med 'Hvorfor nu:' godtages"
git push -q origin hotfix/H035-type:main 2>/dev/null; git fetch -q origin
git checkout -q -B docs/fordeling origin/main; printf '# H036: Gammel\nKunde: x\n' > changes/H036.md; c "H036: gammel" 2026-10-18T12:20:00
printf '# F037: Oprydning\nType: gæld\nKunde: -\nSupport: -\n' > changes/F037.md; c "F037: oprydning" 2026-10-18T12:21:00; git push -q origin docs/fordeling:main 2>/dev/null; git fetch -q origin
o=$(R fordeling 2026-10); has "$o" "| 2026-10 |" "fordeling: måned i tabellen"; has "$o" "H035 (hotfix): X" "fordeling: Type fra fragmentet"
has "$o" "H036 (fejl): Gammel" "fordeling: H uden Type = fejl"; has "$o" "F037 (gæld): Oprydning" "fordeling: F med Type: gæld"
# 5.28: forbrug fra Claude Codes logs — hvert svar én gang, tid pr. ID fra branch eller opgavebeskrivelse
L="$T/claudelogs/$(pwd | sed 's/[^A-Za-z0-9]/-/g')"; mkdir -p "$L/s1/subagents"
a(){ printf '{"type":"assistant","timestamp":"%s","gitBranch":"%s","message":{"usage":{"input_tokens":10,"output_tokens":%s,"cache_read_input_tokens":100,"cache_creation_input_tokens":0}},"requestId":"%s"}\n' "$@"; }
{ a 2026-10-18T10:00:00.000Z main 5 r1; a 2026-10-18T10:02:00.000Z main 5 r2; a 2026-10-18T10:02:00.500Z main 5 r2
  a 2026-10-18T11:00:00.000Z main 5 r3; } > "$L/s1.jsonl"
{ a 2026-10-18T10:05:00.000Z feature/F037-opryd 7 e1; a 2026-10-18T10:08:00.000Z feature/F037-opryd 7 e2; } > "$L/s1/subagents/agent-e.jsonl"
printf '{"agentType":"fullstack-engineer","description":"Byg F037"}' > "$L/s1/subagents/agent-e.meta.json"
{ a 2026-10-18T10:10:00.000Z main 3 q1; a 2026-10-18T10:11:00.000Z main 3 q2; } > "$L/s1/subagents/agent-q.jsonl"
printf '{"agentType":"qa-critic","description":"Review H035 hotfix"}' > "$L/s1/subagents/agent-q.meta.json"
o=$(CW_CLAUDE_LOGS="$T/claudelogs" R forbrug 2026-10)
has "$o" "| F037 | Oprydning | gæld | 0:03 | 14 | 220 | fullstack-engineer |" "forbrug: engineer pr. branch, svar talt én gang"
has "$o" "| H035 | X | hotfix | 0:01 | 6 | 220 | qa-critic |" "forbrug: critic på main pr. opgavebeskrivelse"
has "$o" "| Styring/uden ID | Team Lead på main, opgaver uden ID | - | 0:02 | 15 | 330 | team-lead |" "forbrug: pause over 10 min tæller ikke; gentaget requestId tælles én gang"
has "$o" "I alt: 0:06 aktiv tid, 35 tokens ud" "forbrug: total"
o=$(CW_CLAUDE_LOGS="$T/ingen" R forbrug); has "$o" "FORBRUG: ingen logs" "forbrug: ingen logs siges, ikke gættes"
# 5.28: spild — værktøjstid, lange sessioner, prechecks pr. ID, genlæste filer, critic-runder
tu(){ printf '{"type":"assistant","timestamp":"%s","gitBranch":"%s","message":{"content":[{"type":"tool_use","id":"%s","name":"%s","input":{"%s":"%s"}}]}}\n' "$@"; }
tr_(){ printf '{"type":"user","timestamp":"%s","message":{"content":[{"tool_use_id":"%s","type":"tool_result"}]}}\n' "$@"; }
{ for i in 1 2 3; do tu 2026-10-18T10:1$i:00.000Z feature/F037-opryd p$i Bash command "cd /c/x && bash shared/report.sh precheck"; tr_ 2026-10-18T10:1$i:30.000Z p$i; done
  for i in 1 2 3; do tu 2026-10-18T10:2$i:00.000Z feature/F037-opryd r$i Read file_path 'C:\\ClaudeCoworkers\\Proj\\.claude\\worktrees\\agent-x\\shared\\LIFECYCLE.md'; tr_ 2026-10-18T10:2$i:01.000Z r$i; done
  tu 2026-10-18T10:30:00.000Z feature/F037-opryd s1 Bash command "sleep 240"; tr_ 2026-10-18T10:34:00.000Z s1; } >> "$L/s1/subagents/agent-e.jsonl"
mkdir -p qa-critic; echo r > qa-critic/F037_v1.md; echo r > qa-critic/F037_v2.md; c "docs: runder" 2026-10-18T12:30:00; git push -q origin HEAD:main 2>/dev/null; git fetch -q origin
o=$(CW_CLAUDE_LOGS="$T/claudelogs" R spild 2026-10)
has "$o" "| venter: sleep | 1 | 0:04 | 0:04 |" "spild: ventetid pr. kommando"; has "$o" "| report.sh precheck | 3 | 0:01 | 0:00 |" "spild: report.sh-kommando genkendt"
has "$o" "- F037: 3 prechecks" "spild: prechecks pr. ID"; has "$o" "- shared/LIFECYCLE.md: 3 gange" "spild: genlæst fil (Windows-sti i worktree)"
has "$o" "- F037: qa-critic 2 runder" "spild: critic-runder fra git"; has "$o" "| fullstack-engineer |" "spild: sessioner pr. agent"
# 5.31: køreplanen — features samlet i planlagte releases til STABLE
git checkout -q -B docs/koereplan origin/main
printf '## Nu\n- F037: Oprydning — plan: 2026-10\n## Næste\n- F041: Ny eksport — plan: 2026-11 · release: R2\n- F042: Rapporter — plan: 2026-12 · release: R2\n## Backlog (ikke planlagt)\n## Released\n## Droppet\n' > ROADMAP.md; c "docs: køreplan" 2026-10-18T12:40:00; git push -q origin docs/koereplan:main 2>/dev/null; git fetch -q origin
o=$(R launchplan); has "$o" "## Release R2 — forventet 2026-12" "launchplan: release fra 'release:' — forventet = seneste plan"
has "$o" "- F041: Ny eksport — ikke startet" "launchplan: titel fra ROADMAP, når der ikke er kode endnu"; has "$o" "Klar: 0 af 2." "launchplan: klar-tælling pr. release"
has "$o" "## Release 2026-10 — forventet 2026-10" "launchplan: release ud fra planens måned"; has "$o" "- F037: Oprydning — på main (DEV)" "launchplan: merget feature måles"; has "$o" "TEST: tagget test findes ikke" "launchplan: fælles forudsætninger"
# 5.36 (econsync): config.ini/coworker.ini læses med SAMME funktion i alle frameworkets scripts
ref=$(sed -n '/^cwini()/,/print v; exit }'"'"'; }$/p' "$M/shared/report.sh")
for f in install/deploy.sh install/ci.sh install/monitor.sh install/server.sh install/backup.sh; do
  [ -n "$ref" ] && [ "$(sed -n '/^cwini()/,/print v; exit }'"'"'; }$/p' "$M/shared/$f")" = "$ref" ] && ok || bad "cwini() i $f er ikke den samme som i report.sh"
done
eval "$ref"
printf '%s\n' 'alert_email = a@b ; note' '[database]' "name = 'navn'" 'user = "bruger"' "pass = 'p#a\\b\"c ;d' ; note" 'host = h ; note' > "$T/q.ini"
[ "$(cwini database name < "$T/q.ini")|$(cwini database user < "$T/q.ini")|$(cwini database pass < "$T/q.ini")|$(cwini database host < "$T/q.ini")|$(cwini "" alert_email < "$T/q.ini")" = 'navn|bruger|p#a\b"c ;d|h|a@b' ] && ok || bad "cwini: '…', \"…\", ; og # læses forkert"
# precheck og cron/ci.sh dømmer tests med SAMME kode
a=$(sed -n '/^junit_verdict()/,/^}/p' "$M/shared/report.sh" | sed -n "/-r '\$/,/exit((\$total/p" | sed '1d' | sed "s/' .*//")
b=$(sed -n '/^  tests) cd/,/;;$/p' "$M/shared/install/ci.sh" | sed -n "/-r '\$/,/exit((\$total/p" | sed '1d' | sed "s/' .*//")
[ -n "$a" ] && [ "$a" = "$b" ] && ok || bad "junit-dommen i report.sh (precheck) og install/ci.sh er ikke ens"
cd "$T/repo"
o=$(bash shared/report.sh lint "$M" 2>&1); [ -z "$o" ] && ok || bad "lint af master: $o"

echo "SELFTEST: $pass/$((pass+failn)) OK"
[ $failn = 0 ]
