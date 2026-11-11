#!/usr/bin/env bash
# FILE_VERSION: 5.42
# shared/selftest-guard.sh — test af guarden (_claude/hooks/guard.ps1 i master, .claude/ hvis den mangler).
# Kræver pwsh. Køres af framework-vedligeholderen før hver levering.   → "SELFTEST-GUARD: N/N OK"
M=$(cd "${1:-$(dirname "$0")/..}" && pwd); G="$M/_claude/hooks/guard.ps1"; [ -f "$G" ] || G="$M/.claude/hooks/guard.ps1"
command -v pwsh >/dev/null || { echo "SELFTEST-GUARD: sprunget over (pwsh mangler)"; exit 0; }
pass=0; failn=0
t(){ printf '%s' "$2" | pwsh -NoProfile -File "$G" >/dev/null 2>&1; rc=$?; if [ "$rc" = "$1" ]; then pass=$((pass+1)); else failn=$((failn+1)); echo "FEJL (rc=$rc, forventet $1): $3"; fi; }
S='"agent_id":"x",'
t 0 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git tag -l 'v*'\"}}" "engineer læser tags"
t 0 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh release list\"}}" "engineer læser releases"
t 0 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git ls-remote --tags origin\"}}" "engineer læser remote tags"
t 2 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git tag v2026.10.07-1\"}}" "engineer laver tag"
t 2 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh release create v1 --draft --target abc\"}}" "engineer laver release"
t 0 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git commit -m 'F005: retter v1' && git push -u origin feature/F005-x\"}}" "commit-besked med v1"
t 2 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git push origin v1\"}}" "push af v-tag"
t 2 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git push origin main\"}}" "push til main"
t 2 "{${S}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git push --force origin feature/x\"}}" "force-push"
t 0 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git push --force origin refs/tags/test\"}}" "flyt test-tag"
t 0 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"bash shared/report.sh gitignore\"}}" "report.sh gitignore"
t 2 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo x >> .gitignore\"}}" "Team Lead redirect"
# 5.22: critics, tester og designere er read-only — også via Bash (en critic må ikke ændre koden og godkende den)
Q='"agent_id":"x","agent_type":"qa-critic",'; Z='"agent_id":"x","agent_type":"security-critic",'; E='"agent_id":"x","agent_type":"fullstack-engineer",'
t 2 "{${Q}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo x > homedir/src/a.php\"}}" "qa-critic: redirect blokeret"
t 2 "{${Q}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"sed -i s/a/b/ homedir/src/a.php\"}}" "qa-critic: sed -i blokeret"
t 2 "{${Q}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git checkout feature/F005-x\"}}" "qa-critic: git checkout blokeret"
t 2 "{${Z}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"php -r 'file_put_contents(1,2);'\"}}" "security-critic: inline-script blokeret"
t 2 "{${Z}\"tool_name\":\"Write\",\"tool_input\":{\"file_path\":\"homedir/src/a.php\",\"content\":\"x\"}}" "security-critic: Write blokeret"
t 0 "{${Q}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git fetch -q origin && git diff origin/main...origin/feature/F005-x 2>&1\"}}" "qa-critic: læser diffen"
t 0 "{${Z}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git log --oneline -5 origin/feature/F005-x\"}}" "security-critic: læser log"
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo x > homedir/src/a.php\"}}" "engineer: skriver stadig"
t 0 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"mv gammel.md _indifferent/gammel.md\"}}" "Team Lead: flytter til _indifferent"
# 5.23: gh-login deles af alle projekter — ingen agent skifter det
t 2 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh auth switch --user x\"}}" "Team Lead: gh auth switch blokeret"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo t | gh auth login --with-token\"}}" "engineer: gh auth login blokeret"
t 0 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh auth status\"}}" "gh auth status tilladt"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo \$GH_TOKEN\"}}" "engineer: viser ikke nøglen"
t 2 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"env\"}}" "Team Lead: env-dump blokeret"
t 0 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"bash shared/report.sh access\"}}" "report.sh access tilladt"
# 5.27: servere ændres ikke med agenternes egne kommandoer; sysadmin-subagenten er read-only
A='"agent_id":"x","agent_type":"sysadmin",'
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"ssh root@h 'useradd x'\"}}" "engineer: ssh root@h 'useradd x' blokeret"
t 2 "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"ssh storix@h \\\"sudo systemctl restart php8.4-fpm\\\"\"}}" "Team Lead: systemctl restart via ssh blokeret"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"sudo apt install php8.4-intl\"}}" "engineer: sudo apt install blokeret"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"ssh root@h 'mariadb -e \\\"GRANT ALL ON *.* TO x\\\"'\"}}" "engineer: GRANT via ssh blokeret"
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git commit -m 'retter chown i deploy'\"}}" "commit-besked med chown er tilladt"
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"ssh storix@h 'tail -50 logs/php-error.log'\"}}" "læsning via ssh er tilladt"
t 2 "{${A}\"tool_name\":\"Write\",\"tool_input\":{\"file_path\":\"x.md\",\"content\":\"x\"}}" "sysadmin: Write blokeret"
t 2 "{${A}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo x > a\"}}" "sysadmin: redirect blokeret"
# 5.34 (Storix): download af en release-fil er læsning; skrivende kald mod releases er stadig blokeret
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"curl -fsSL -o /home/storix/tools/phpstan.phar https://github.com/phpstan/phpstan/releases/download/1.12.0/phpstan.phar\"}}" "engineer: henter PHPStan-phar fra releases/download"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"curl -fsS -X POST https://api.github.com/repos/a/b/releases -d '{}'\"}}" "engineer: curl -X POST mod releases blokeret"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"curl -fsS --data '{}' https://api.github.com/repos/a/b/releases\"}}" "engineer: curl --data mod releases blokeret"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh api repos/a/b/releases -f tag_name=v1\"}}" "engineer: gh api -f mod releases blokeret"
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh api repos/a/b/releases --jq '.[0].tag_name'\"}}" "engineer: gh api læser releases"
# 5.28: opdater-pc.ps1 kopierer kun _claude, når hver fil matcher shared/claude.lock
R=$(mktemp -d); mkdir -p "$R/master/shared"; cp "$M/shared/opdater-pc.ps1" "$R/master/shared/"
for p in ren aendret ukendt; do
  mkdir -p "$R/$p/agents" "$R/$p/shared" "$R/$p/.claude"; echo x > "$R/$p/agents/team_lead.md"
  cp -r "$(dirname "$(dirname "$G")")" "$R/$p/_claude"; rm -f "$R/$p/_claude/settings.local.json"; rm -rf "$R/$p/_claude/worktrees"
  cp "$M/shared/claude.lock" "$R/$p/shared/"; printf '{"env":{"GH_TOKEN":"x"}}' > "$R/$p/.claude/settings.local.json"
done
echo '# ond' >> "$R/aendret/_claude/hooks/guard.ps1"; echo 'ond' > "$R/ukendt/_claude/agents/ond.md"
o=$(pwsh -NoProfile -File "$R/master/shared/opdater-pc.ps1" 2>&1); [ -n "${DBG:-}" ] && echo "$o"
op(){ if grep -qF -- "$2" <<< "$o"; then pass=$((pass+1)); else failn=$((failn+1)); echo "FEJL: $1 — forventede: $2"; fi; }
op "opdater-pc: ren kopi opdateres" "ren: .claude opdateret"
op "opdater-pc: ændret guard kopieres ikke" "aendret: _claude afviger fra shared\\claude.lock (afviger hooks/guard.ps1)"
op "opdater-pc: ukendt agentfil kopieres ikke" "ukendt: _claude afviger fra shared\\claude.lock (ukendt agents/ond.md)"
[ -f "$R/ren/.claude/hooks/guard.ps1" ] && [ ! -f "$R/aendret/.claude/hooks/guard.ps1" ] && [ ! -f "$R/ukendt/.claude/agents/ond.md" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: opdater-pc kopierede trods afvigelse"; }
# report.sh claude: .claude mod låsen (Trin 0 tjek 4)
(cd "$R/ren" && cp -r "$M/shared/report.sh" shared/ && bash shared/report.sh claude) 2>/dev/null | grep -q "CLAUDE: OK" && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: report.sh claude: ren .claude ikke OK"; }
echo '# x' >> "$R/ren/.claude/hooks/guard.ps1"
(cd "$R/ren" && bash shared/report.sh claude) 2>/dev/null | grep -q "afviger: .claude/hooks/guard.ps1" && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: report.sh claude: ændret guard ikke fundet"; }
rm -rf "$R"
# 5.36 (econsync): Team Lead, der har lavet "cd" ind i en worktree, kan selv komme ud igen — men intet andet
tw(){ printf '%s' "$2" | CLAUDE_PROJECT_DIR='C:\p' pwsh -NoProfile -File "$G" >/dev/null 2>&1; rc=$?; if [ "$rc" = "$1" ]; then pass=$((pass+1)); else failn=$((failn+1)); echo "FEJL (rc=$rc, forventet $1): $3"; fi; }
WT='"cwd":"C:\\p\\.claude\\worktrees\\agent-1",'
tw 0 "{${WT}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"cd ../../..\"}}" "worktree: cd relativt tilbage til roden"
tw 0 "{${WT}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"cd /c/p\"}}" "worktree: cd absolut (Git Bash) tilbage"
tw 0 "{${WT}\"tool_name\":\"PowerShell\",\"tool_input\":{\"command\":\"Set-Location 'C:\\\\p'\"}}" "worktree: Set-Location tilbage"
tw 2 "{${WT}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"cd ../agent-2\"}}" "worktree: cd til en anden worktree blokeret"
tw 2 "{${WT}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"cd ../../.. && echo x > a.php\"}}" "worktree: cd + anden kommando blokeret"
tw 2 "{${WT}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"bash shared/report.sh status\"}}" "worktree: alt andet stadig blokeret"
# 5.36: gh release download er læsning (PHPStan-phar'en); gh release create/upload stadig blokeret
t 0 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh release download 1.12.7 -R phpstan/phpstan -p phpstan.phar\"}}" "engineer: gh release download tilladt"
t 2 "{${E}\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"gh release upload v1 x.zip\"}}" "engineer: gh release upload blokeret"
echo "SELFTEST-GUARD: $pass/$((pass+failn)) OK"; [ $failn = 0 ]
