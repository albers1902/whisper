#!/usr/bin/env bash
# FILE_VERSION: 5.42
# shared/selftest-ci.sh — test af shared/install/ci.sh (CI på DEV-serveren) mod en RIGTIG MariaDB, et lokalt
# git-repo og en stub af GitHub-API'et. Køres af framework-vedligeholderen før hver levering
# (Linux med git, php med pdo_mysql, mariadbd, mysql-klient). PHPStan og Semgrep er stubbe her (hentes fra
# packagist/semgrep.dev på serveren); gitleaks er den rigtige (hentes og checksum-tjekkes, eller GITLEAKS=<sti>).
# Kræver root: opretter en rigtig DEV-bruger og CI-bruger (<dev>-ci) med sudo-regel og tester isolationen (5.19).
#   bash shared/selftest-ci.sh [master-mappe]   → "SELFTEST-CI: N/N OK"
set -uo pipefail
M=$(cd "${1:-$(dirname "$0")/..}" && pwd)
command -v mariadbd >/dev/null && command -v mysql >/dev/null || { echo "SELFTEST-CI: SPRUNGET OVER (mariadbd/mysql mangler)"; exit 0; }
[ "$(id -u)" = 0 ] && command -v sudo >/dev/null && command -v useradd >/dev/null || { echo "SELFTEST-CI: SPRUNGET OVER (kræver root, sudo og useradd)"; exit 0; }
U=cwt$$; CIU=$U-ci; S=/srv/cwtest-$$; mkdir -p "$S"; chmod 755 "$S"; DBPID=""
useradd --create-home "$U"; useradd --create-home "$CIU"; H=$(getent passwd "$U" | cut -d: -f6); CIH=$(getent passwd "$CIU" | cut -d: -f6)
echo "$U ALL=($CIU) NOPASSWD: ALL" > /etc/sudoers.d/cwtest-$$; chmod 440 /etc/sudoers.d/cwtest-$$
echo "$CIU" >> /etc/cron.deny; echo "$CIU" >> /etc/at.deny
trap '[ -n "$DBPID" ] && kill "$DBPID" 2>/dev/null; sleep 1; rm -f /etc/sudoers.d/cwtest-$$; sed -i "/^$CIU\$/d" /etc/cron.deny /etc/at.deny 2>/dev/null; userdel -r "$U" 2>/dev/null; userdel -r "$CIU" 2>/dev/null; rm -rf "$S"' EXIT
pass=0; failn=0
chk()  { if grep -qF -- "$2" <<< "$1"; then pass=$((pass+1)); else failn=$((failn+1)); echo "FEJL: $3 — forventede: $2"; fi; }
nchk() { if grep -qF -- "$2" <<< "$1"; then failn=$((failn+1)); echo "FEJL: $3 — forventede IKKE: $2"; else pass=$((pass+1)); fi; }

# --- rigtig MariaDB på en socket i testmappen
mkdir -p "$S/db"; mariadb-install-db --no-defaults --datadir="$S/db" --auth-root-authentication-method=normal --user="$(id -un)" > /dev/null 2>&1
mariadbd --no-defaults --datadir="$S/db" --socket="$S/db.sock" --port=3399 --bind-address=127.0.0.1 --user="$(id -un)" > "$S/db.log" 2>&1 & DBPID=$!
for i in $(seq 1 30); do mysql --no-defaults -S "$S/db.sock" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mysql --no-defaults -S "$S/db.sock" -uroot -e "CREATE USER 'p_ci'@'127.0.0.1' IDENTIFIED BY 'hemmelig-ci'; GRANT ALL ON \`p_ci\`.* TO 'p_ci'@'127.0.0.1'; CREATE DATABASE p_dev; CREATE TABLE p_dev.vigtig(i int);"

# --- repo med frameworket og en lille app (migration + test, der bruger databasen)
mkdir -p "$S/bin"; cd "$S"; git init -q --bare origin.git
git -c init.defaultBranch=main clone -q origin.git src 2>/dev/null; cd src
git config user.email t@t; git config user.name t; git checkout -q -b main
# Testens commits er "gamle": en main-commit under 15 min uden PR venter (5.26); det testes for sig nedenfor
export GIT_COMMITTER_DATE="2026-01-01T10:00:00"
cp -r "$M/shared" "$M/agents" "$M/CLAUDE.md" .; cp "$M/shared/gitignore.framework" .gitignore
mkdir -p homedir/src homedir/migrations/down tests changes tasks fullstack-ai
echo '<?php echo 1;' > homedir/src/a.php
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nCREATE TABLE IF NOT EXISTS t(i int);\n' > homedir/migrations/0001_v1.0.0_t.sql
echo 'DROP TABLE IF EXISTS t;' > homedir/migrations/down/0001_v1.0.0_t.sql
cat > tests/run.php <<'E'
<?php
$p = new PDO("mysql:host=".getenv("CW_DB_HOST").";port=".getenv("CW_DB_PORT").";dbname=".getenv("CW_DB_NAME"), getenv("CW_DB_USER"), getenv("CW_DB_PASS"));
$p->exec("INSERT INTO t VALUES (1)"); $n = (int) $p->query("SELECT COUNT(*) FROM t")->fetchColumn();
$fail = getenv("CW_FAIL_TEST") ?: (file_exists(__DIR__."/FAIL") ? "1" : "");
$x = '<?xml version="1.0"?><testsuites><testsuite><testcase classname="T" name="db"'.($n === 1 && !$fail ? '/>' : '><failure>n='.$n.'</failure></testcase>').'</testsuite></testsuites>';
file_put_contents("junit.xml", $x); file_put_contents("OUTDIR/env-i-test.txt", (getenv("GIT_CONFIG_VALUE_0") ?: "").(getenv("GIT_CONFIG_COUNT") ?: ""));
$leak = ""; foreach (["config/github.token", "config/config.ini", "private/db.php"] as $f) if (@file_get_contents("DEVHOME/$f") !== false) $leak .= "LÆK:$f ";
file_put_contents("OUTDIR/leak.txt", $leak . "user=" . get_current_user() . " uid=" . getmyuid() . " euid=" . posix_geteuid()); exit($n === 1 && !$fail ? 0 : 1);
E
printf 'parameters:\n\tignoreErrors: []\n' > phpstan-baseline.neon
printf '[ci]\nphp = 8.4\ntests = php tests/run.php\nanalyse = homedir/src\n[deploy]\nrepo = o/r\nphp = php\nmigrate = true\n[health]\ndev = http://health\n' > coworker.ini
mkdir -p "$S/out"; chmod 777 "$S/out"; sed -i "s#DEVHOME#$H#; s#OUTDIR#$S/out#g" tests/run.php; bash shared/report.sh install > /dev/null 2>&1
git add -A; git commit -qm "start"; git push -q origin main 2>/dev/null; MAIN1=$(git rev-parse HEAD)

# --- DEV-hjemmemappen
chmod -R a+rX "$S"; mkdir -p "$H/config" "$H/logs" "$H/private"; git clone -q "$S/origin.git" "$H/repo" 2>/dev/null
echo '<?php $pass="db-hemmelig";' > "$H/private/db.php"; chmod 600 "$H/private/db.php"
cp "$M/shared/install/ci.sh" "$H/cron.ci.sh" 2>/dev/null; mkdir -p "$H/cron"; mv "$H/cron.ci.sh" "$H/cron/ci.sh"
# 5.36 (econsync): [ci_database] med '…' og "…" og en kommentar — læses som i deploy.sh (før: navnet blev "'p_ci'")
printf '%s\n' 'alert_email=a@b' 'real_data=false' '[database]' 'name=p_dev' '[ci_database]' 'host = "127.0.0.1"' 'port=3399' "name = 'p_ci'" 'user = "p_ci" ; CI-brugeren' 'pass = "hemmelig-ci"' > "$H/config/config.ini"
printf 'tok-HEMMELIG-9\n' > "$H/config/github.token"; chmod 600 "$H/config/github.token" "$H/config/config.ini"
# værktøjer: rigtig gitleaks; PHPStan og Semgrep som stubbe (ingen adgang til packagist/semgrep.dev herfra)
GL=${GITLEAKS:-$(command -v gitleaks || true)}
T=$H/ci-tools; mkdir -p "$T/gitleaks-8.21.2" "$T/semgrep-1.97.0/bin" "$T/phpstan-1.12.7"
# 5.25: RIGTIG PHPStan-phar (sha256-tjekkes af ci.sh tools). PHPSTAN_PHAR=<sti til phpstan.phar 1.12.7>
PP=${PHPSTAN_PHAR:-}; [ -f "$PP" ] || { echo "SELFTEST-CI: PHPSTAN_PHAR mangler (phpstan.phar 1.12.7 fra GitHub-releasen)"; exit 1; }
cp "$PP" "$T/phpstan-1.12.7/phpstan.phar"
if [ -n "$GL" ]; then cp "$GL" "$T/gitleaks-8.21.2/gitleaks"; fi
printf '#!/bin/bash\necho semgrep-stub "$@"\n' > "$T/semgrep-1.97.0/bin/semgrep"; chmod +x "$T"/*/bin/*
# GitHub: git-URL omdirigeres til det lokale repo; API'et er en stub, der logger skrivninger
export HOME=$S; git config --global url."file://$S/origin.git".insteadOf "https://github.com/o/r.git"
git config --global user.email t@t; git config --global user.name t; git config --global --add safe.directory "*"; cp "$S/.gitconfig" "$H/.gitconfig"
cat > "$S/bin/curl" <<'E'
#!/bin/bash
for a; do u=$a; done
args="$*"; [[ " $args " == *" -K - "* ]] && { cat > /dev/null; }
case "$args" in
  *"-X POST"*) [ -n "${FAILPOST:-}" ] && exit 22; for a; do [ "$prev" = --data-binary ] && b=$a; prev=$a; done; echo "$u $b" >> "$S_DIR/posts.log"; exit 0;;
esac
case "$u" in
  *pulls\?state=open*) cat "$S_DIR/prs.json" 2>/dev/null || echo '[]';;
  *health*) cat "$S_DIR/health.json" 2>/dev/null;;
  */releases*) cat "$S_DIR/releases.json" 2>/dev/null || echo '[]';;
  */commits/v1) echo "{\"sha\":\"$(cat "$S_DIR/relsha")\"}";;
  */commits/*/pulls) [ -n "${FAILPULLS:-}" ] && exit 22; sha=${u%/pulls}; sha=${sha##*/}; if [ -f "$S_DIR/merged.$sha" ]; then cat "$S_DIR/merged.$sha"; else echo '[]'; fi;;
  *) echo '[]';;
esac
E
printf '#!/bin/bash\ncat >> %s/mail.txt\n' "$S" > "$S/bin/sendmail"; chmod +x "$S"/bin/*
sed -i "s#/usr/sbin/sendmail#sendmail#" "$H/cron/ci.sh"
export PATH="$S/bin:$PATH" S_DIR="$S"
touch "$S/posts.log" "$S/mail.txt" "$S/crontab"; chmod 666 "$S/posts.log" "$S/mail.txt" "$S/crontab"
chown -R "$U:$U" "$H"; chmod 711 "$H"; chmod -R a+rX "$S"
asu() { sudo -u "$U" env PATH="$S/bin:/usr/local/bin:/usr/bin:/bin" HOME="$H" S_DIR="$S" FAILPOST="${FAILPOST:-}" FAILPULLS="${FAILPULLS:-}" "$@"; }
run() { asu bash "$H/cron/ci.sh" 2>&1; }
posts() { cat "$S/posts.log" 2>/dev/null; }

# 1a) 5.26: frisk main-commit, som GitHub endnu ikke har knyttet til en PR = vent (ikke "direkte push")
MAIN1_OLD=$(git rev-parse HEAD); GIT_COMMITTER_DATE="$(date -Is)" git commit -q --amend --no-edit; git push -q -f origin main 2>/dev/null
o=$(run); chk "$o" "endnu ikke knyttet til en PR hos GitHub" "frisk merge uden PR-link venter"; nchk "$(posts)" "\"state\":\"failure\"" "frisk merge er ikke rød"
git reset -q --hard "$MAIN1_OLD"; git push -q -f origin main 2>/dev/null; : > "$S/posts.log"
# 1) Første main-commit er ikke en merget PR: fuld kørsel, framework-kontrollen rød, mail + status failure
o=$(run); chk "$o" "coworker/ci ${MAIN1:0:12}" "main testes først"
chk "$(posts)" "statuses/$MAIN1 {\"state\":\"pending\",\"context\":\"coworker/ci\"" "pending-status sat"
chk "$(posts)" "\"state\":\"failure\",\"context\":\"coworker/ci\",\"description\":\"rød: Framework-kontroller" "direkte push til main = rød status"
chk "$(cat "$S/mail.txt" 2>/dev/null)" "main er rød" "rød main = mail"
L=$(cat "$H"/ci/runs/${MAIN1:0:12}-ci.log)
chk "$L" "== Tests" "tunge trin kørt"; chk "$L" "1 tests, 0 røde" "tests kørt mod den rigtige testdatabase"; chk "$L" "[OK] No errors" "rigtig PHPStan (sha256-låst phar) kørt og grøn"
chk "$L" "tmpDir: $CIH/phpstan-tmp" "PHPStan: tmpDir i CI-brugerens egen mappe (ikke delt /tmp/phpstan, 5.21)"; nchk "$L" "tmpDir: /tmp" "PHPStan bruger ikke /tmp"; chk "$L" "/phpstan-baseline.neon" "PHPStan: projektets baseline inkluderes"
nchk "$(posts)" "hemmelig-ci" "databasens kode står aldrig i GitHub-kald"; nchk "$L" "hemmelig-ci" "databasens kode står ikke i loggen"
[ -e "$CIH/my.cnf" ] && { failn=$((failn+1)); echo "FEJL: my.cnf (med kode) ligger tilbage"; } || pass=$((pass+1))
# Isolation: testkoden kørte som CI-brugeren og kunne hverken læse token, config eller private/
I=$(cat "$S/out/leak.txt" 2>/dev/null); chk "$I" "user=$CIU" "testene kører som CI-brugeren, ikke som DEV"; nchk "$I" "LÆK" "testkoden kan ikke læse DEV's token, config eller private/"
o=$(mysql --no-defaults -S "$S/db.sock" -uroot -N -e "SELECT COUNT(*) FROM p_dev.vigtig" 2>&1); chk "$o" "0" "DEV's egen database er urørt"
o=$(run); [ -z "$o" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: samme commit testes to gange: $o"; }

# 2) PR med kodeændring: status coworker/pr, grøn
git checkout -q -b hotfix/H001-x; echo '<?php echo 2;' > homedir/src/a.php
printf '# H001: Ret\nType: fejl\nKunde: Rettet.\n' > changes/H001.md; printf 'Tier: 3\n' > tasks/impact-H001.md; echo r > fullstack-ai/H001_v1.md
git add -A; git commit -qm "H001: ret"; git push -q origin hotfix/H001-x 2>/dev/null; PR1=$(git rev-parse HEAD)
printf '[{"number":7,"draft":false,"head":{"ref":"hotfix/H001-x","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR1" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$o" "coworker/pr ${PR1:0:12} (PR #7 hotfix/H001-x) start" "åben PR testes"
chk "$(posts)" "statuses/$PR1 {\"state\":\"success\",\"context\":\"coworker/pr\",\"description\":\"grøn (fuld)" "grøn PR = success-status (coworker/pr)"
nchk "$(posts)" "issues/7/comments" "grøn PR får ingen kommentar"
# 5.26: kun dokumentation oven på den grønne commit = de tunge trin genbruges (ingen ny tung kørsel)
echo "# rapport" > fullstack-ai/H001_v2.md; git add -A; git commit -qm "H001: rapport"; git push -q origin hotfix/H001-x 2>/dev/null; PR1=$(git rev-parse HEAD)
printf '[{"number":7,"draft":false,"head":{"ref":"hotfix/H001-x","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR1" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$(cat "$H"/ci/runs/${PR1:0:12}-pr.log)" "tunge trin genbrugt: den samme kode" "kun .md oven på grøn commit = tunge trin genbrugt"
chk "$(posts)" "statuses/$PR1 {\"state\":\"success\",\"context\":\"coworker/pr\"" "docs-commit oven på grøn kode = grøn"

# 3) Squash-merge af PR'en: samme træ → tunge trin genbruges, main grøn
git checkout -q main; git merge -q --squash hotfix/H001-x; git commit -qm "H001: ret (#7)"; git push -q origin main 2>/dev/null; MAIN2=$(git rev-parse HEAD)
printf '[{"number":7,"merged_at":"2026-10-10T10:00:00Z","head":{"ref":"hotfix/H001-x","sha":"%s"}}]' "$PR1" > "$S/merged.$MAIN2"; echo '[]' > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$o" "coworker/ci ${MAIN2:0:12} GRØN" "merget main grøn"
chk "$(cat "$H"/ci/runs/${MAIN2:0:12}-ci.log)" "tunge trin genbrugt" "samme træ som grøn PR = ingen ny tung kørsel"
chk "$(posts)" "\"state\":\"success\",\"context\":\"coworker/ci\",\"description\":\"grøn (lette trin)" "main: success-status (coworker/ci)"

# 4) Rød PR (test fejler): failure + kommentar på PR'en med loggens sidste linjer
git checkout -q -b hotfix/H002-y; echo x > tests/FAIL; echo '<?php echo 3;' > homedir/src/a.php
printf '# H002: Y\nType: fejl\nKunde: Y.\n' > changes/H002.md; printf 'Tier: 3\n' > tasks/impact-H002.md; echo r > fullstack-ai/H002_v1.md
git add -A; git commit -qm "H002: y"; git push -q origin hotfix/H002-y 2>/dev/null; PR2=$(git rev-parse HEAD)
printf '[{"number":8,"draft":false,"head":{"ref":"hotfix/H002-y","sha":"%s","repo":{"full_name":"o/r"}}},{"number":9,"draft":false,"head":{"ref":"x","sha":"%s","repo":{"full_name":"fremmed/fork"}}}]' "$PR2" "$PR2" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$(posts)" "\"state\":\"failure\",\"context\":\"coworker/pr\",\"description\":\"rød: Tests" "rød test = failure"
chk "$(posts)" "issues/8/comments" "rød PR får kommentar"; chk "$(posts)" "Ny rød eller sprunget over: T::db" "kommentaren viser den røde test"
o=$(run); nchk "$o" "PR #9" "PR fra et fremmed repo (fork) køres aldrig"
chk "$(posts)" "\"state\":\"failure\",\"context\":\"coworker/kode\",\"description\":\"rød: Tests" "rød test = coworker/kode failure (5.35)"
# 5.36 (econsync): kommentaren viser det RØDE trins eget afsnit — PHPStans fejllinjer, selv om senere trin skrev efter den
git checkout -q -b hotfix/H099-stan main; printf '<?php\nfunction h003(): int { return findes_ikke_h003(); }\n' > homedir/src/stan.php
printf '# H099: S\nType: fejl\nKunde: S.\n' > changes/H099.md; printf 'Tier: 3\n' > tasks/impact-H099.md; echo r > fullstack-ai/H099_v1.md
git add -A; git commit -qm "H099: stan"; git push -q origin hotfix/H099-stan 2>/dev/null; PRS=$(git rev-parse HEAD)
printf '[{"number":99,"draft":false,"head":{"ref":"hotfix/H099-stan","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PRS" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$(posts)" "issues/99/comments" "rød PHPStan: PR-kommentar"
chk "$(posts)" "findes_ikke_h003" "kommentaren har PHPStans fejllinje (ikke kun loggens hale)"; chk "$(posts)" "== PHPStan" "kommentaren viser PHPStan-afsnittet"
nchk "$(posts)" "== Semgrep" "kommentaren viser ikke grønne trin"
# 5.35: kode-commit'en testes på DEV, før critics starter. Kun review-kravene røde = coworker/kode grøn, ingen kommentar;
# reviews/rapport (kun *.md) ovenpå genbruger de tunge trin
git checkout -q -b hotfix/H004-k main; echo '<?php echo 9;' > homedir/src/k.php
printf '# H004: K\nType: fejl\nKunde: K.\n' > changes/H004.md; printf 'Tier: 3\n' > tasks/impact-H004.md
git add -A; git commit -qm "H004: kode"; git push -q origin hotfix/H004-k 2>/dev/null; PRK=$(git rev-parse HEAD)
printf '[{"number":12,"draft":false,"head":{"ref":"hotfix/H004-k","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PRK" > "$S/prs.json"
: > "$S/posts.log"; o=$(run)
chk "$(posts)" "statuses/$PRK {\"state\":\"success\",\"context\":\"coworker/kode\",\"description\":\"koden grøn (fuld)" "kode-commit uden rapport: coworker/kode grøn"
chk "$(posts)" "statuses/$PRK {\"state\":\"failure\",\"context\":\"coworker/pr\",\"description\":\"rød: Framework-kontroller" "kode-commit uden rapport: coworker/pr stadig rød (merge kræver alt)"
nchk "$(posts)" "issues/12/comments" "kun review-krav røde: ingen PR-kommentar"
echo r > fullstack-ai/H004_v1.md; git add -A; git commit -qm "H004: rapport"; git push -q origin hotfix/H004-k 2>/dev/null; PRK=$(git rev-parse HEAD)
printf '[{"number":12,"draft":false,"head":{"ref":"hotfix/H004-k","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PRK" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$(cat "$H"/ci/runs/${PRK:0:12}-pr.log)" "tunge trin genbrugt" "rapport oven på grøn kode: tunge trin genbruges"
chk "$(posts)" "statuses/$PRK {\"state\":\"success\",\"context\":\"coworker/pr\"" "rapport tilføjet: coworker/pr grøn"

sed -i "s/^name = 'p_ci'/name = 'p_dev'/" "$H/config/config.ini"
sed -i 's/^name=p_ci/name=p_dev/' "$H/config/config.ini"
git checkout -q -b hotfix/H003-z main; echo '<?php echo 4;' > homedir/src/a.php
printf '# H003: Z\nType: fejl\nKunde: Z.\n' > changes/H003.md; printf 'Tier: 3\n' > tasks/impact-H003.md; echo r > fullstack-ai/H003_v1.md
git add -A; git commit -qm "H003: z"; git push -q origin hotfix/H003-z 2>/dev/null; PR3=$(git rev-parse HEAD)
printf '[{"number":10,"draft":false,"head":{"ref":"hotfix/H003-z","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR3" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$(posts)" "rød: Testdatabase" "forkert databasenavn = rød, ingen DROP"
o=$(mysql --no-defaults -S "$S/db.sock" -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='p_dev' AND table_name='vigtig'" 2>&1); chk "$o" "1" "DEV's database findes stadig"
printf 'alert_email=a@b\nreal_data=false\n[database]\nname=p_dev\n[ci_database]\nhost=127.0.0.1\nport=3399\nname=p_ci\nuser=p_ci\npass=hemmelig-ci\n' > "$H/config/config.ini"; chmod 600 "$H/config/config.ini"

# 6) Kun dokumenter: lette trin (ingen PHP/tests)
git checkout -q -b docs/note main; echo n > tasks/note.md; git add -A; git commit -qm "docs: note"; git push -q origin docs/note 2>/dev/null; PR4=$(git rev-parse HEAD)
printf '[{"number":11,"draft":false,"head":{"ref":"docs/note","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR4" > "$S/prs.json"
: > "$S/posts.log"; o=$(run); L=$(cat "$H"/ci/runs/${PR4:0:12}-pr.log); nchk "$L" "== Tests" "docs-PR: ingen tunge trin"
chk "$(posts)" "\"state\":\"success\",\"context\":\"coworker/pr\",\"description\":\"grøn (lette trin)" "docs-PR grøn"

# 6b) Robusthed: GitHub-fejl må ikke give falsk rød eller tabt grøn; mellemliggende main-commits får også status
nchk "$(cat "$S/out/env-i-test.txt" 2>/dev/null)" "AUTHORIZATION" "tokenet er ikke i testenes miljø"
git checkout -q main; echo '<?php echo 5;' > homedir/src/b.php; printf '# H005: B\nType: fejl\nKunde: B.\n' > changes/H005.md; printf 'Tier: 3\n' > tasks/impact-H005.md; echo r > fullstack-ai/H005_v1.md
git add -A; git commit -qm "H005: b (#12)"; M5=$(git rev-parse HEAD); echo m > tasks/m.md; git add -A; git commit -qm "docs: m (#13)"; M6=$(git rev-parse HEAD); git push -q origin main 2>/dev/null
printf '[{"number":12,"merged_at":"2026-10-10T11:00:00Z","head":{"ref":"hotfix/H005-b","sha":"%s"}}]' "$M5" > "$S/merged.$M5"
printf '[{"number":13,"merged_at":"2026-10-10T11:01:00Z","head":{"ref":"docs/m","sha":"%s"}}]' "$M6" > "$S/merged.$M6"; echo '[]' > "$S/prs.json"
: > "$S/posts.log"; o=$(FAILPULLS=1 run); chk "$o" "GitHub svarer ikke (commits/pulls)" "GitHub-fejl: prøv igen"; nchk "$(posts)" "failure" "GitHub-fejl giver ikke falsk rød"
o=$(FAILPOST=1 run); chk "$o" "coworker/ci ${M5:0:12} GRØN" "mellemliggende main-commit testes (ældste først)"; chk "$(cat "$H/ci/repost")" "$M5" "status, der ikke kunne skrives, gemmes"
: > "$S/posts.log"; o=$(run); chk "$(posts)" "statuses/$M5 {\"state\":\"success\",\"context\":\"coworker/ci\"" "gemt status skrives ved næste kørsel"
chk "$o" "coworker/ci ${M6:0:12} GRØN" "nyeste main-commit testes bagefter"; [ -s "$H/ci/repost" ] && { failn=$((failn+1)); echo "FEJL: repost tømmes ikke"; } || pass=$((pass+1))
# 6c) Tier 1 efter squash-merge, når GitHub har slettet PR-branchen: den reviewede commit hentes via refs/pull/*/head
git checkout -q -b hotfix/H010-m main; mkdir -p qa-critic security-critic product-ai fullstack-ai
printf '# System understanding\n- homedir/migrations/0002_v1.1.0_t2.sql: ny tabel\n- tests/run.php: testløberen\n' > fullstack-ai/H010_v0.md; git add -A; git commit -qm "H010: forståelse"
echo t > tests/h010-regression.txt
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nCREATE TABLE IF NOT EXISTS t2(i int);\n' > homedir/migrations/0002_v1.1.0_t2.sql
echo 'DROP TABLE IF EXISTS t2;' > homedir/migrations/down/0002_v1.1.0_t2.sql
printf '# H010: M\nType: fejl\nKunde: M.\n' > changes/H010.md; printf 'Tier: 1\n' > tasks/impact-H010.md; echo s > product-ai/H010_v1.md
git add -A; git commit -qm "H010: migration"; RS=$(git rev-parse HEAD)
for c in qa-critic security-critic; do printf 'Branch: hotfix/H010-m @ %s\nStatus: APPROVED\n' "$RS" > $c/H010_v1.md; done; echo r > fullstack-ai/H010_v1.md
git add -A; git commit -qm "H010: reviews"; PRH=$(git rev-parse HEAD)
git push -q origin hotfix/H010-m 2>/dev/null; git push -q origin "$PRH:refs/pull/14/head" 2>/dev/null
git checkout -q main; git merge -q --squash hotfix/H010-m >/dev/null; git commit -qm "H010: migration (#14)"; M7=$(git rev-parse HEAD); git push -q origin main 2>/dev/null
git push -q origin --delete hotfix/H010-m 2>/dev/null; git branch -q -D hotfix/H010-m
asu git -C "$H/ci/repo" update-ref -d refs/remotes/origin/hotfix/H010-m 2>/dev/null
printf '[{"number":14,"merged_at":"2026-10-12T10:00:00Z","head":{"ref":"hotfix/H010-m","sha":"%s"}}]' "$PRH" > "$S/merged.$M7"; echo '[]' > "$S/prs.json"
: > "$S/posts.log"; o=$(run); chk "$o" "coworker/ci ${M7:0:12} GRØN" "tier 1 grøn, selv om PR-branchen er slettet (refs/pull)"
nchk "$(cat "$H"/ci/runs/${M7:0:12}-ci.log 2>/dev/null)" "findes ikke i repoet" "reviewet commit findes via refs/pull"
o=$(asu bash "$H/cron/ci.sh" retest "$M7"); chk "$o" "testes igen" "retest af en commit"; grep -q " $M7 " "$H/ci/done" && { failn=$((failn+1)); echo "FEJL: retest fjerner ikke resultatet"; } || pass=$((pass+1))
o=$(run); chk "$o" "coworker/ci ${M7:0:12}" "commit testes igen efter retest"
# 5.32: down/up — en nyere migrations ændring overlever den ældres down (alle downs nyeste→ældste, så alle ups)
git checkout -q main
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nCREATE TABLE IF NOT EXISTS t3(a int);\n' > homedir/migrations/0003_v1.2.0_t3.sql
echo 'DROP TABLE IF EXISTS t3;' > homedir/migrations/down/0003_v1.2.0_t3.sql
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nALTER TABLE t3 ADD COLUMN IF NOT EXISTS b int;\n' > homedir/migrations/0004_v1.2.0_t3b.sql
echo 'ALTER TABLE t3 DROP COLUMN IF EXISTS b;' > homedir/migrations/down/0004_v1.2.0_t3b.sql
git add -A; git commit -qm "H011: kolonne b"; M8=$(git rev-parse HEAD); git push -q origin main 2>/dev/null; o=$(run)
chk "$(cat "$H"/ci/runs/${M8:0:12}-ci.log 2>/dev/null)" "Migrationer" "down/up: migrationstrinnet kørte"
o=$(mysql --no-defaults -S "$S/db.sock" -uroot -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='p_ci' AND table_name='t3' AND column_name='b'" 2>&1)
chk "$o" "1" "down/up: den nyere migrations kolonne findes stadig i testdatabasen (ældste down må ikke få sidste ord)"
# 6d) Isolation håndhæves: uden sudo-regel eller med læsbart token testes intet (status rød + mail) — og når det er rettet, testes PR'en
git checkout -q -b docs/iso main; echo i > tasks/iso.md; git add -A; git commit -qm "docs: iso"; git push -q origin docs/iso 2>/dev/null; PR5=$(git rev-parse HEAD)
printf '[{"number":15,"draft":false,"head":{"ref":"docs/iso","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR5" > "$S/prs.json"
mv /etc/sudoers.d/cwtest-$$ "$S/sudoers.bak"; : > "$S/posts.log"; : > "$S/mail.txt"
o=$(run); chk "$(posts)" "statuses/$PR5 {\"state\":\"failure\",\"context\":\"coworker/pr\",\"description\":\"CI kører ikke: sudo-reglen for $CIU mangler" "uden sudo-regel: rød status, intet testes"
chk "$(cat "$S/mail.txt")" "CI kører ikke" "uden sudo-regel: mail til ejeren"; [ -e "$H/ci/runs/${PR5:0:12}-pr.log" ] && { failn=$((failn+1)); echo "FEJL: koden blev kørt uden isolation"; } || pass=$((pass+1))
mv "$S/sudoers.bak" /etc/sudoers.d/cwtest-$$; chmod 644 "$H/config/config.ini"; : > "$S/posts.log"
o=$(run); chk "$(cat "$S/mail.txt")" "$CIU kan læse config/config.ini" "læsbar config.ini: CI nægter at køre"
[ -e "$H/ci/runs/${PR5:0:12}-pr.log" ] && { failn=$((failn+1)); echo "FEJL: koden blev kørt med læsbar config.ini"; } || pass=$((pass+1))
chmod 600 "$H/config/config.ini"; : > "$S/posts.log"
o=$(run); chk "$(posts)" "statuses/$PR5 {\"state\":\"success\",\"context\":\"coworker/pr\"" "isolation rettet: PR'en testes og bliver grøn"
echo '[]' > "$S/prs.json"
# 7) monitor.sh: sundt STABLE = livstegn (status coworker/monitor); problem = mail + sag, højst én pr. døgn
cp "$M/shared/install/monitor.sh" "$H/cron/monitor.sh"; sed -i "s#/usr/sbin/sendmail#sendmail#" "$H/cron/monitor.sh"; chown "$U:$U" "$H/cron/monitor.sh"
git checkout -q main; printf '[ci]\nphp = 8.4\ntests = php tests/run.php\nanalyse = homedir/src\n[deploy]\nrepo = o/r\nphp = php\nmigrate = true\n[health]\ndev = http://health\nstable = http://health\n[backup]\nexternal = snapshot\n' > coworker.ini
git commit -qam "H004: ini"; git push -q origin main 2>/dev/null; ST=$(git rev-parse HEAD); asu git -C "$H/repo" fetch -q origin; asu git -C "$H/repo" checkout -q "$ST"; echo "$ST" | asu tee "$H/logs/deploy.current" > /dev/null
echo "$ST" > "$S/relsha"; echo '[{"tag_name":"v1","draft":false,"prerelease":false,"published_at":"2026-10-01T10:00:00Z"}]' > "$S/releases.json"
printf '{"status":"ok","sha":"%s","db_read":"ok","db_write":"ok"}' "$ST" > "$S/health.json"; : > "$S/posts.log"; : > "$S/mail.txt"
o=$(asu bash "$H/cron/monitor.sh" 2>&1); chk "$o" "alt OK" "monitor: sundt STABLE"
chk "$(posts)" "statuses/$ST {\"state\":\"success\",\"context\":\"coworker/monitor-$(date +%Y%m)\"" "monitor: livstegn på STABLE's commit (månedlig kontekst)"
printf '{"status":"ok","sha":"%s","db_read":"ok","db_write":"fejl"}' "$ST" > "$S/health.json"; : > "$S/posts.log"
o=$(asu bash "$H/cron/monitor.sh" 2>&1); chk "$o" "databasen kan ikke skrives" "monitor: problem fundet"
chk "$(cat "$S/mail.txt")" "Hændelse: STABLE: databasen kan ikke skrives" "monitor: mail"; chk "$(posts)" "/issues {\"title\":\"Hændelse" "monitor: GitHub-sag"
chk "$(posts)" "\"state\":\"failure\",\"context\":\"coworker/monitor-" "monitor: livstegn viser fejl"
: > "$S/posts.log"; asu bash "$H/cron/monitor.sh" > /dev/null 2>&1; [ "$(grep -c '^Subject' "$S/mail.txt")" = 1 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: monitor: samme mail to gange samme døgn"; }
nchk "$(posts)" "/issues {" "monitor: ingen ny sag samme døgn"
echo 0000000000000000000000000000000000000000 > "$S/relsha"; printf '{"status":"ok","sha":"%s","db_read":"ok","db_write":"ok"}' "$ST" > "$S/health.json"
o=$(asu bash "$H/cron/monitor.sh" 2>&1); chk "$o" "men seneste release er v1" "monitor: STABLE ≠ seneste release"
# U1 v3 (go-v3 2, 3, 6): ci.sh skriver markøren FØR chmod, i samme format som server.sh; følger ikke et symlink; fejler markøren, består beviset
thash() { tr -d ' \r\n' < "$1" | sha256sum | cut -d' ' -f1; }
LKH=$H/logs/.token-laekket; chmod 644 "$H/config/github.token"; rm -rf "$LKH"; o=$(run)
[ "$(cat "$LKH" 2>&1)" = "$(thash "$H/config/github.token")" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: ci.sh skrev ikke markøren med sha256 af det trimmede token"; }
[ "$(stat -c %a "$LKH" 2>&1)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: ci.sh's markør er ikke 600"; }
[ "$(stat -c %a "$H/config/github.token")" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: ci.sh lukkede ikke tokenet"; }
chk "$o" "ci: ADVARSEL: config/github.token (chmod 644) har været læsbart for andre" "go-v3 2: ci.sh logger markøren"
rm -f "$LKH"; mkdir -p "$LKH/x"; chmod 644 "$H/config/github.token"; o=$(run); chk "$o" "logs/.token-laekket kunne ikke skrives" "go-v3 6: ci.sh logger en markør, der fejler"
[ "$(stat -c %a "$H/config/github.token")" = 644 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 6: ci.sh chmod'ede tokenet, selv om markøren fejlede"; }
rm -rf "$LKH"; echo uroert > "$S/lk-maal"; chmod 666 "$S/lk-maal"; ln -s "$S/lk-maal" "$LKH"; o=$(run)
[ "$(cat "$S/lk-maal")" = uroert ] && [ ! -L "$LKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: ci.sh's markør fulgte et symlink"; }
rm -f "$LKH"
# 8) server.sh: tjek og opsætning af DEV-hjemmemappen (crontab er en stub; rigtig git, mysql og PHP)
printf '#!/bin/bash\nif [ "$1" = -l ]; then cat "%s/crontab" 2>/dev/null; else cat > "%s/crontab"; fi\n' "$S" "$S" > "$S/bin/crontab"; chmod +x "$S/bin/crontab"
cp "$M/shared/install/server.sh" "$S/server.sh"; chmod 644 "$S/server.sh"
printf '# min egen linje\n5 3 * * * echo egen\n' > "$S/crontab"; chmod 644 "$H/config/github.token"; rm -f "$H/cron/monitor.sh"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✘ config/github.token (chmod 644) har været læsbart for andre — roter tokenet på GitHub (SERVERKONTRAKT §8)" "U1-AK6: server check melder et læsbart token som lækket"
# go-v3 4 (security LOW 2): check skriver markøren — og kun den: ingen chmod, så en chmod i hånden rydder ikke ✘
[ "$(cat "$LKH" 2>&1)" = "$(thash "$H/config/github.token")" ] && [ "$(stat -c %a "$LKH" 2>&1)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 4: check skrev ikke markøren logs/.token-laekket (600, sha256 af det trimmede token)"; }
[ "$(stat -c %a "$H/config/github.token")" = 644 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 4: check chmod'ede tokenet"; }
chmod 600 "$H/config/github.token"; o2=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o2" "✘ config/github.token er meldt lækket" "go-v3 4: chmod 600 i hånden efter check er stadig ✘"; chmod 644 "$H/config/github.token"
chk "$o" "✔ testdatabase p_ci: kan oprettes og slettes" "server check: testdatabasen virker"; chk "$o" "SERVER: IKKE KLAR" "server check: samlet resultat"
[ "$(cat "$S/crontab")" = "$(printf '# min egen linje\n5 3 * * * echo egen')" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: check ændrede crontab"; }
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' setup dev" 2>&1); chk "$o" "cron/monitor.sh lagt ind fra main" "server setup: henter driftsfiler fra main"
chk "$o" "✘ config/github.token (chmod 644) har været læsbart for andre — roter tokenet på GitHub (SERVERKONTRAKT §8) og kør linjen igen med det nye; filen er nu chmod 600 og meldt lækket (logs/.token-laekket)" "U1-AK6: server setup lukker tokenet, men melder ✘ roter (ikke ✔)"
[ "$(stat -c %a "$LKH" 2>&1)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK6: setup skrev ikke markøren logs/.token-laekket (600)"; }
chk "$o" "SERVER: IKKE KLAR (dev)" "U1-AK6: et lækket token er ikke KLAR"; [ "$(stat -c %a "$H/config/github.token")" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK6: setup lukkede ikke tokenet (chmod 600)"; }
chk "$(cat "$S/crontab")" "5 3 * * * echo egen" "server setup: ejerens egne cron-linjer bevares"
chk "$(cat "$S/crontab")" "$H/cron/ci.sh >> $H/logs/ci.log" "server setup: cron-linje for ci.sh"; chk "$(cat "$S/crontab")" "$H/cron/monitor.sh" "server setup: cron-linje for monitor.sh"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' setup dev" 2>&1); [ "$(grep -c 'cron/ci.sh' "$S/crontab")" = 1 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: setup to gange gav dobbelte cron-linjer"; }
# go-v2 2: ✘ står i HVER kørsel (setup og check), til tokenet er udskiftet — chmod 600 alene gør det ikke KLAR
chk "$o" "✘ config/github.token er meldt lækket — roter tokenet på GitHub" "U1-AK6: anden setup melder stadig det lækkede token"; chk "$o" "SERVER: IKKE KLAR (dev)" "U1-AK6: lækket token er stadig ikke KLAR efter chmod 600"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✘ config/github.token er meldt lækket" "U1-AK6: check melder markøren"
# go-v3 3 (security LOW 1): hashen er over det trimmede token — samme token uden \n eller med \r\n er stadig det lækkede
TKS=$(tr -d ' \r\n' < "$H/config/github.token"); printf '%s' "$TKS" > "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' setup dev" 2>&1)
chk "$o" "✘ config/github.token er meldt lækket" "go-v3 3: samme token uden linjeskift forbliver ✘"; [ -e "$LKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 3: setup fjernede markøren, fordi blanktegn var ændret"; }
printf '%s\r\n' "$TKS" > "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✘ config/github.token er meldt lækket" "go-v3 3: samme token med CRLF forbliver ✘"
# Rotation (go-v2 3): et ANDET token erstatter det lækkede (init uden indgangslinjen, token i miljøet) — så KLAR igen
o=$(asu bash -c "cd '$H' && CW_TOKEN=tokU1rotSek8 bash '$S/server.sh' init dev" 2>&1); chk "$o" "✔ config/github.token: token udskiftet" "U1 rotation: init udskifter et token, der er meldt lækket"
chk "$o" "SERVER: KLAR (dev)" "server setup: klar efter rotation"; [ -e "$LKH" ] && { failn=$((failn+1)); echo "FEJL: U1 rotation: markøren står tilbage"; } || pass=$((pass+1))
# go-v3 6 (QA F3): markøren fejler (her: en mappe på dens plads, virker også som root) = ✘ og INGEN chmod
mkdir -p "$LKH/x"; chmod 644 "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' setup dev" 2>&1)
chk "$o" "logs/.token-laekket kunne ikke skrives, så filen er ikke ændret" "go-v3 6: setup melder en markør, der fejler"; chk "$o" "SERVER: IKKE KLAR (dev)" "go-v3 6: markør fejlet er ikke KLAR"
[ "$(stat -c %a "$H/config/github.token")" = 644 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 6: setup chmod'ede tokenet, selv om markøren fejlede"; }
chmod 600 "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "logs/.token-laekket er ikke en almindelig fil" "go-v3: en markør, der ikke er en fil, er ✘"; rm -rf "$LKH"
# Markøren følger ikke et symlink — hverken ved skrivning eller læsning
echo uroert > "$S/lk-maal"; ln -s "$S/lk-maal" "$LKH"; chmod 644 "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1)
[ "$(cat "$S/lk-maal")" = uroert ] && [ ! -L "$LKH" ] && [ -f "$LKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3: check's markør fulgte et symlink"; }
rm -f "$LKH"; ln -s "$S/lk-maal" "$LKH"; chmod 600 "$H/config/github.token"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "logs/.token-laekket er ikke en almindelig fil" "go-v3: et symlink som markør er ✘"; rm -f "$LKH"
sed -i 's/^name=p_ci/name=p_dev/' "$H/config/config.ini"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "name SKAL ende på _ci" "server check: afviser testdatabase uden _ci"
printf 'alert_email=a@b\nreal_data=false\n[database]\nname=p_dev\n[ci_database]\nhost=127.0.0.1\nport=3399\nname=p_ci\nuser=p_ci\npass=hemmelig-ci\n' > "$H/config/config.ini"; chmod 600 "$H/config/config.ini"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check monitor" 2>&1); chk "$o" "SERVER: KLAR (monitor)" "server check: ekstra vagt-tilstand (monitor)"
chmod o+rx "$H/private"; chmod 644 "$H/private/db.php"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "$CIU kan læse: $H/private/db.php" "server check: finder hemmeligheder, CI-brugeren kan læse"
# 5.25: setup lukker mappen for andre — så hjælper en verdenslæsbar fil derinde ikke (Storix: deployed_sha.php 644)
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' setup dev" 2>&1); chk "$o" "✔ $CIU kan ikke læse DEV's config, token eller nøgler" "server setup: lukker private/ på mappeniveau, også med en 644-fil"
chmod 600 "$H/private/db.php"
asu bash -c "printf x > '$H/config/deployed_sha.php'; chmod 644 '$H/config/deployed_sha.php'"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1)
chk "$o" "✔ $CIU kan ikke læse DEV's config" "server check: verdenslæsbar fil i config/ er lukket af mappen"
chk "$o" "testdatabasens bruger har kun rettigheder på sin egen database" "server check: SHOW GRANTS — kun testdatabasen"
mysql --no-defaults -S "$S/db.sock" -uroot -e "GRANT SELECT ON \`p_dev\`.* TO 'p_ci'@'127.0.0.1'"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1)
mysql --no-defaults -S "$S/db.sock" -uroot -e "REVOKE SELECT ON \`p_dev\`.* FROM 'p_ci'@'127.0.0.1'"
chk "$o" "har rettigheder på DEV's database p_dev" "server check: testbrugeren med adgang til DEV's database afvises"
mv /etc/sudoers.d/cwtest-$$ "$S/sudoers.bak"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); mv "$S/sudoers.bak" /etc/sudoers.d/cwtest-$$
chk "$o" "✘ $U må ikke starte kommandoer som $CIU — sudo-reglen laves af sysadmin-siden (SERVERKONTRAKT §3a trin 6)" "server check: sender sudo-reglen til sysadmin, ingen root-kommando (5.37)"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✔ $CIU kan ikke læse DEV's config, token eller nøgler" "server check: isolation OK"
sed -i "/^$CIU\$/d" /etc/cron.deny; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); echo "$CIU" >> /etc/cron.deny
chk "$o" "✘ $CIU må have cron-job" "server check: CI-brugeren må ikke have cron-job"
# 5.27: backup er maskinens (site-backup) — projektet ser kun efter beviset i logs/
o=$(asu bash -c "cd '$H' && rm -f logs/backup_last_ok logs/restore_last_ok && bash '$S/server.sh' check dev" 2>&1)
chk "$o" "! ikke set i serverens backup endnu" "server check: intet bevis = navngivet advarsel"; chk "$o" "SERVER: KLAR (dev)" "server check: manglende backup-bevis blokerer ikke et nyt miljø"
o=$(asu bash -c "cd '$H' && date +%F > logs/backup_last_ok && echo \"\$(date +%F) 3\" > logs/restore_last_ok && bash '$S/server.sh' check dev" 2>&1)
chk "$o" "✔ med i serverens backup (backup $(date +%F), restore-prøve $(date +%F))" "server check: frisk bevis fra site-backup"
o=$(asu bash -c "cd '$H' && echo 2026-01-01 > logs/backup_last_ok && bash '$S/server.sh' check dev" 2>&1)
chk "$o" "! serverens backup har ikke taget projektet siden 2026-01-01" "server check: gammelt bevis = advarsel"
asu bash -c "cd '$H' && rm -f logs/backup_last_ok logs/restore_last_ok"
# 5.34 (Storix): ejerskab rekursivt · check dev beviser at tokenet kan skrive status · *token* i en migration er ikke et fund
mkdir -p "$H/cron/dyb"; touch "$H/cron/dyb/rod.php"   # root ejer, to niveauer nede
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✘ ejes ikke af $U: cron/dyb" "server check: root-ejet fil dybt i en kodemappe findes"
rm -rf "$H/cron/dyb"
: > "$S/posts.log"; o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$(posts)" '"context":"coworker/setup"' "server check dev: skrive-test af commit-status"
asu bash -c "mkdir -p '$H/sqlprøve' && echo 'ALTER TABLE x ADD cursor_token INT;' > '$H/sqlprøve/0108_v1.0.0_cursor_token.sql' && chmod o+rx '$H/sqlprøve' && chmod 644 '$H/sqlprøve/0108_v1.0.0_cursor_token.sql'"
o=$(asu bash -c "cd '$H' && bash '$S/server.sh' check dev" 2>&1); chk "$o" "✔ $CIU kan ikke læse DEV's config, token eller nøgler" "server check: *token* i et migrationsnavn er ikke en hemmelighed"
asu bash -c "rm -rf '$H/sqlprøve'"
# Et job efterlader en proces og en fil hos CI-brugeren — næste job starter med en tom hjemmemappe og ingen processer
sudo -u "$CIU" sh -c 'touch "$HOME/rest"; nohup sleep 300 >/dev/null 2>&1 &'; sleep 1
git checkout -q -b docs/rest main; echo r > tasks/rest.md; git add -A; git commit -qm "docs: rest"; git push -q origin docs/rest 2>/dev/null; PR6=$(git rev-parse HEAD)
printf '[{"number":16,"draft":false,"head":{"ref":"docs/rest","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR6" > "$S/prs.json"; o=$(run); echo '[]' > "$S/prs.json"
[ -e "$CIH/rest" ] && { failn=$((failn+1)); echo "FEJL: CI-brugerens hjemmemappe tømmes ikke mellem jobs"; } || pass=$((pass+1))
ps -u "$CIU" -o stat= 2>/dev/null | grep -qv '^Z' && { failn=$((failn+1)); echo "FEJL: CI-brugerens processer overlever et job"; } || pass=$((pass+1))
# 5.25: [ci] phpstan fra en PR vælger aldrig værktøj eller sti (Storix H042, security-critic fund 1)
git checkout -q -b hotfix/H050-ond main; sed -i '/^\[ci\]/a phpstan = ../../ci/ond' coworker.ini; git add -A; git commit -qm "H050: ond"; git push -q origin hotfix/H050-ond 2>/dev/null; PR9=$(git rev-parse HEAD)
printf '[{"number":19,"draft":false,"head":{"ref":"hotfix/H050-ond","sha":"%s","repo":{"full_name":"o/r"}}}]' "$PR9" > "$S/prs.json"; o=$(run); echo '[]' > "$S/prs.json"
chk "$(cat "$H"/ci/runs/${PR9:0:12}-pr.log 2>/dev/null)" "kun 1.12.7 er hash-låst" "PR's [ci] phpstan afvises (ingen sti/version fra testet kode)"
[ -e "$H/ci/ond" ] && { failn=$((failn+1)); echo "FEJL: [ci] phpstan fra PR'en oprettede noget"; } || pass=$((pass+1))
# 5.26 (Storix): composer audit under CI-brugeren. COMPOSER må ikke stå i miljøet (composer læser den som composer.json),
# og "sårbarhed fundet" skal kunne skelnes fra "audit kunne ikke køre". Stub = PHP-fil, der kaldes som "php composer audit".
cat > "$S/bin/composer" <<'E'
<?php
@file_put_contents(__DIR__ . '/../composer-kald.log', "kaldt\n", FILE_APPEND);   // U2: beviser om composer blev kaldt
if (getenv('COMPOSER') !== false) { fwrite(STDERR, "JsonLint: COMPOSER peger på ".getenv('COMPOSER')."\n"); exit(2); }
$svar = __DIR__ . '/audit-svar.json';
if (!is_file($svar)) { fwrite(STDERR, "The \"https://repo.packagist.org/api/security-advisories/\" file could not be downloaded\n"); exit(100); }
echo file_get_contents($svar); exit(1);
E
chmod 755 "$S/bin/composer"; chmod 755 "$S/bin" "$S"; : > "$S/composer-kald.log"; chmod 666 "$S/composer-kald.log"
# U2-AK2: locken SKAL have en pakke — en lock uden pakker springer composer over (U2), og så testede 21–23 ingenting
git checkout -q -b hotfix/H051-lock main; echo '{"packages":[{"name":"acme/lib","version":"1.0.0"}],"packages-dev":[]}' > homedir/composer.lock; git add -A; git commit -qm "H051: lock"
git push -q origin hotfix/H051-lock 2>/dev/null; PRA=$(git rev-parse HEAD)
aud() { local br=${2:-hotfix/H051-lock} sha=${3:-$PRA}; printf '[{"number":%s,"draft":false,"head":{"ref":"%s","sha":"%s","repo":{"full_name":"o/r"}}}]' "$1" "$br" "$sha" > "$S/prs.json"; asu bash "$H/cron/ci.sh" retest "$sha" >/dev/null 2>&1; o=$(run); echo '[]' > "$S/prs.json"; cat "$H"/ci/runs/${sha:0:12}-pr.log 2>/dev/null; }
lockpr() { git checkout -q -b "$1" main; printf '%s' "$2" > homedir/composer.lock; git add -A; git commit -qm "${1#hotfix/}: lock"; git push -q origin "$1" 2>/dev/null; git rev-parse HEAD; }
L=$(aud 21); chk "$L" "AUDIT KUNNE IKKE KØRE (composer exit 100)" "audit: netværksfejl = kunne ikke køre"; chk "$L" "RØD: Afhængigheder (composer audit) — kunne ikke køre (ikke en sårbarhed)" "audit: miljøfejl ligner ikke en sårbarhed"
nchk "$L" "JsonLint" "audit: COMPOSER står ikke i CI-brugerens miljø (Storix: altid rød)"
printf '{"advisories":{"acme/lib":[{"advisoryId":"PKSA-1","title":"RCE i acme","affectedVersions":"<1.2"}]},"abandoned":{}}' > "$S/bin/audit-svar.json"; chmod 644 "$S/bin/audit-svar.json"
L=$(aud 22); chk "$L" "SÅRBARHED: acme/lib PKSA-1 RCE i acme" "audit: fund navngives"; chk "$L" "RØD: Afhængigheder (composer audit) — sårbarhed fundet" "audit: fund = sårbarhed fundet"
printf '{"advisories":[],"abandoned":{"old/pkg":"new/pkg"}}' > "$S/bin/audit-svar.json"
L=$(aud 23); chk "$L" "0 sårbarhed(er)" "audit: ingen fund"; nchk "$L" "RØD: Afhængigheder" "audit: ingen fund = grøn (forladt pakke er ikke rødt)"
chk "$(cat "$S/composer-kald.log")" "kaldt" "U2-AK2: lock med en pakke i packages → composer kaldes (uændret)"
# U2: uden svarfil fejler stubben (exit 100) — kaldes composer, hvor den ikke må, ses det i loggen og i composer-kald.log
rm -f "$S/bin/audit-svar.json"
: > "$S/composer-kald.log"; PRU=$(lockpr hotfix/H052-tom '{"packages": [], "packages-dev": []}'); L=$(aud 24 hotfix/H052-tom "$PRU")
chk "$L" "composer.lock har ingen pakker — audit sprunget over" "U2-AK1: tom lock → audit sprunget over med teksten"
nchk "$L" "RØD: Afhængigheder" "U2-AK1: tom lock → audit-trinnet er grønt"; nchk "$L" "AUDIT KUNNE IKKE KØRE" "U2-AK1: composer kaldes ikke (log)"
[ -s "$S/composer-kald.log" ] && { failn=$((failn+1)); echo "FEJL: U2-AK1: composer blev kaldt på en lock uden pakker"; } || pass=$((pass+1))
: > "$S/composer-kald.log"; PRU=$(lockpr hotfix/H053-dev '{"packages": [], "packages-dev": [{"name": "acme/dev", "version": "1.0.0"}]}'); L=$(aud 25 hotfix/H053-dev "$PRU")
nchk "$L" "ingen pakker" "U2-AK2: pakke kun i packages-dev → ikke sprunget over"; chk "$L" "AUDIT KUNNE IKKE KØRE (composer exit 100)" "U2-AK2: pakke i packages-dev → composer afgør"
chk "$(cat "$S/composer-kald.log")" "kaldt" "U2-AK2: pakke i packages-dev → composer kaldes"
: > "$S/composer-kald.log"; PRU=$(lockpr hotfix/H054-ugyldig '{"packages": [], "packages-dev": [}'); L=$(aud 26 hotfix/H054-ugyldig "$PRU")
nchk "$L" "ingen pakker" "U2-AK3: ugyldig JSON → intet stille grønt"; chk "$L" "RØD: Afhængigheder (composer audit) — kunne ikke køre" "U2-AK3: ugyldig JSON → composer afgør (her: stubbens fejl)"
chk "$(cat "$S/composer-kald.log")" "kaldt" "U2-AK3: ugyldig JSON → composer kaldes"
rm -f "$S/composer-kald.log"
rm -f "$S/bin/composer" "$S/bin/audit-svar.json"
# 5.33 (Storix): ren installation — et view, der er ugyldigt efter alle migrationer, er rødt i CI
git checkout -q main
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nCREATE TABLE IF NOT EXISTS t5(a int, b int);\nCREATE OR REPLACE VIEW v5 AS SELECT * FROM t5;\n' > homedir/migrations/0005_v1.3.0_v5.sql
echo 'DROP VIEW IF EXISTS v5; DROP TABLE IF EXISTS t5;' > homedir/migrations/down/0005_v1.3.0_v5.sql
printf -- '-- reversibel: ja\n-- destruktiv: nej\n-- idempotent: ja\nALTER TABLE t5 DROP COLUMN IF EXISTS b;\n' > homedir/migrations/0006_v1.3.0_t5b.sql
echo 'ALTER TABLE t5 ADD COLUMN IF NOT EXISTS b int;' > homedir/migrations/down/0006_v1.3.0_t5b.sql
git add -A; git commit -qm "H013: view"; M9=$(git rev-parse HEAD); git push -q origin main 2>/dev/null; o=$(run)
chk "$(cat "$H"/ci/runs/${M9:0:12}-ci.log 2>/dev/null)" "view kan ikke læses efter alle migrationer: v5" "ren installation: ugyldigt view er rødt i CI"
# 9) U1 (5.38): server.sh init — et nyt miljø ud fra sitectl's filer, kørt med indgangslinjen fra OPSAETNING.md. Rigtige
# brugere (DEV med CI-bruger og sudo-regel; STABLE/TEST uden), en MariaDB som maskinernes (--skip-name-resolve, grants på
# 'localhost' som sitectl db) og øjebliksbilleder af ALLE processers argv, mens curl, git og mysql kører (AK3).
I1=cwi$$; I1C=$I1-ci; I2=cws$$; DB2PID=""
u1clean() { [ -n "$DB2PID" ] && kill "$DB2PID" 2>/dev/null; rm -f /etc/sudoers.d/cwtest-u1-$$; sed -i "/^$I1C\$/d" /etc/cron.deny /etc/at.deny 2>/dev/null; for u in "$I1" "$I1C" "$I2"; do userdel -r "$u" 2>/dev/null; done; }
eval "$(trap -p EXIT | sed "s/^trap -- '/trap -- 'u1clean; /")"
for u in "$I1" "$I1C" "$I2"; do useradd --create-home "$u"; done; H1=$(getent passwd "$I1" | cut -d: -f6); H2=$(getent passwd "$I2" | cut -d: -f6)
echo "$I1 ALL=($I1C) NOPASSWD: ALL" > /etc/sudoers.d/cwtest-u1-$$; chmod 440 /etc/sudoers.d/cwtest-u1-$$; echo "$I1C" >> /etc/cron.deny; echo "$I1C" >> /etc/at.deny
mkdir -p "$S/db2"; mariadb-install-db --no-defaults --datadir="$S/db2" --auth-root-authentication-method=normal --user="$(id -un)" > /dev/null 2>&1
mariadbd --no-defaults --datadir="$S/db2" --socket="$S/db2.sock" --port=3398 --bind-address=127.0.0.1 --skip-name-resolve --user="$(id -un)" > "$S/db2.log" 2>&1 & DB2PID=$!
for i in $(seq 1 30); do mysql --no-defaults -S "$S/db2.sock" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
PA=AppKodeU1x7Qm2; PC=CiKodeU1y8Rn3; TK=tokU1HEMMELIG42   # alfanumeriske som pwgen -s; testværdier
for b in "${I1}_${I1}:$PA" "${I1}_ci:$PC"; do d=${b%%:*}; mysql --no-defaults -S "$S/db2.sock" -uroot -e "CREATE DATABASE \`$d\`; CREATE USER '$d'@'localhost' IDENTIFIED BY '${b#*:}'; GRANT ALL PRIVILEGES ON \`$d\`.* TO '$d'@'localhost'"; done
dbf() { printf 'host=127.0.0.1\ndbname=%s\nuser=%s\npass=%s\n' "$2" "$2" "$3" > "$1"; }   # sitectl cmd_db's format
mkdir -p "$H1/private" "$H2/private"; dbf "$H1/private/db-${I1}_${I1}.txt" "${I1}_${I1}" "$PA"; dbf "$H1/private/db-${I1}_ci.txt" "${I1}_ci" "$PC"
dbf "$H2/private/db-${I2}_${I2}.txt" "${I2}_${I2}" "$PA"
cp -r "$T" "$H1/ci-tools"; cp "$S/.gitconfig" "$H1/.gitconfig"; cp "$S/.gitconfig" "$H2/.gitconfig"
git config --file "$H2/.gitconfig" --add url."file://$S/origin.git".insteadOf "https://github.com/o/x.git"   # o/x: klonbart, men coworker.ini siger o/r
printf '[client]\nsocket=%s\n' "$S/db2.sock" > "$H1/.my.cnf"   # host = localhost = socket; testens socket er ikke standardstien
chown -R "$I1:$I1" "$H1"; chown -R "$I2:$I2" "$H2"; chmod 700 "$H1/private" "$H2/private"; chmod 600 "$H1"/private/* "$H2"/private/* "$H1/.my.cnf"
# Stubbe foran de andre: snap = argv for alle processer; curl serverer server.sh fra main til indgangslinjen og gemmer dens stdin
mkdir -p "$S/bin1"; cat > "$S/bin1/snap" <<'E'
for f in /proc/[0-9]*/cmdline; do tr '\0' ' ' < "$f" 2>/dev/null; echo; done >> "$S_DIR/u1-argv.log"
E
cat > "$S/bin1/curl" <<'E'
#!/bin/bash
. "$S_DIR/bin1/snap"; for a; do u=$a; done
case "$u" in */repos/*"/contents/shared/install/server.sh?ref=main") cat > "$S_DIR/u1-fetch.cfg"; cat "$S_DIR/u1-server.sh"; exit 0 ;; esac
exec "$S_DIR/bin/curl" "$@"
E
for t in git mysql; do printf '#!/bin/bash\n. "$S_DIR/bin1/snap"\nexec %s "$@"\n' "$(command -v $t)" > "$S/bin1/$t"; done
git -C "$S/origin.git" show main:shared/install/server.sh > "$S/u1-server.sh"; touch "$S/u1-argv.log" "$S/u1-fetch.cfg"
chmod 755 "$S/bin1" "$S/bin1/curl" "$S/bin1/git" "$S/bin1/mysql"; chmod 644 "$S/bin1/snap" "$S/u1-server.sh"; chmod 666 "$S/u1-argv.log" "$S/u1-fetch.cfg"
au() { local u=$1 h=$2; shift 2; sudo -u "$u" env PATH="$S/bin1:$S/bin:/usr/local/bin:/usr/bin:/bin" HOME="$h" S_DIR="$S" "$@"; }
perm() { [ "$(stat -c '%a %U' "$1" 2>&1)" = "$2" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: $3 — $(stat -c '%a %U' "$1" 2>&1), forventede $2"; }; }
LINE=$(grep -m1 "cd ~ && read -rsp" "$M/OPSAETNING.md" | sed 's/^ *//; s#<ejer>/<repo>#o/r#')
[ "$(grep -c "cd ~ && read -rsp" "$M/OPSAETNING.md")" = 1 ] && [ -n "$LINE" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK1: indgangslinjen står ikke præcis ét sted i OPSAETNING.md"; }
# AK1–AK3: frisk DEV efter sitectl new + db + db ci + CI-bruger — én kommando, én indtastning (tokenet på stdin)
bash -c 'sleep 3; :' u1-kontrol-argv & kp=$!; sleep 1; au "$I1" "$H1" bash -c '. "$S_DIR/bin1/snap"'; wait "$kp"   # kontrol: målingen ser root's argv
chk "$(cat "$S/u1-argv.log")" "u1-kontrol-argv" "U1-AK3: argv-målingen ser andre brugeres processer (kontrol — ellers beviser nchk intet)"
: > "$S/u1-argv.log"; o=$(printf '%s\n' "$TK" | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1)
chk "$o" "✔ config/config.ini skrevet (chmod 600)" "U1-AK1: init skriver config.ini ud fra private/db-*.txt"; chk "$o" "✔ repo/ klonet fra o/r" "U1-AK1: init kloner repoet"
chk "$o" "✔ testdatabase ${I1}_ci: kan oprettes og slettes" "U1-AK1: [ci_database] virker mod MariaDB med skip-name-resolve og grants på 'localhost'"
chk "$o" "SERVER: KLAR (dev)" "U1-AK1: én kommando og én indtastning giver SERVER: KLAR"; chk "$(cat "$S/crontab")" "$H1/cron/deploy.sh dev" "U1-AK1: deploy-cron lagt"
perm "$H1/config" "700 $I1" "U1-AK2: config/ er 700"; perm "$H1/config/config.ini" "600 $I1" "U1-AK2: config.ini er 600"; perm "$H1/config/github.token" "600 $I1" "U1-AK2: github.token er 600"
C=$(cat "$H1/config/config.ini"); chk "$C" "real_data = false" "U1-AK5: dev sætter real_data = false"; chk "$C" "alert_email = alarms@albers.dk" "U1-AK1: alert_email"
chk "$(sed -n '/^\[database\]/,/^\[ci_database\]/p' <<< "$C")" "name = ${I1}_${I1}" "U1-AK1: [database] er appens base, ikke _ci"; chk "$C" "host = localhost" "U1-AK1: host = localhost"
[ "$(cat "$H1/config/github.token")" = "$TK" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK1: github.token har ikke det indtastede token"; }
A=$(cat "$S/u1-argv.log"); chk "$A" "contents/shared/install/server.sh" "U1-AK3: argv målt, mens indgangslinjens curl kørte"
chk "$A" "clone -q" "U1-AK3: argv målt under git clone"; chk "$A" "defaults-extra-file" "U1-AK3: argv målt under mysql"
chk "$(cat "$S/u1-fetch.cfg")" "Bearer $TK" "U1-AK3: tokenet når curl på stdin (-K -)"
for x in "$TK" "$PA" "$PC" "$(printf 'x-access-token:%s' "$TK" | base64 -w0)"; do
  nchk "$A" "$x" "U1-AK3: hemmelighed i en process' argv (/proc/<pid>/cmdline)"; nchk "$o" "$x" "U1-AK3: hemmelighed i output"
  nchk "$(cat "$H1"/logs/* 2>/dev/null)" "$x" "U1-AK3: hemmelighed i logs/"; nchk "$(posts)" "$x" "U1-AK3: hemmelighed i et GitHub-kald"; done
o2=$(au "$I1" "$H1" bash -c 'cd ~ && . <(sed -n "/^\[database\]/,/^\[ci_database\]/{s/^\(name\|user\|pass\) = \"\{0,1\}\([^\"]*\)\"\{0,1\}$/d_\1=\2/p}" config/config.ini) && MYSQL_PWD=$d_pass mysql -h localhost -u "$d_user" -N -e "SELECT 42" "$d_name"' 2>&1)
chk "$o2" "42" "U1-AK1: [database] fra sitectl-filen kan forbinde (host = localhost)"
# Negativ kontrol (go-v2 6): med --skip-name-resolve matcher '<bruger>'@'localhost' IKKE 127.0.0.1 over TCP — derfor skriver
# init host = localhost. Kontrolkontoen @'127.0.0.1' viser, at TCP i sig selv virker (afvisningen skyldes værten, ikke porten).
mysql --no-defaults -S "$S/db2.sock" -uroot -e "CREATE USER 'u1tcp'@'127.0.0.1' IDENTIFIED BY 'TcpKontrolU1'"
o3=$(MYSQL_PWD=TcpKontrolU1 mysql --no-defaults --protocol=TCP -h 127.0.0.1 -P 3398 -u u1tcp -N -e 'SELECT 42' 2>&1); chk "$o3" "42" "U1 host: kontrol — TCP til 127.0.0.1 virker for en konto @'127.0.0.1'"
o3=$(MYSQL_PWD=$PC mysql --no-defaults --protocol=TCP -h 127.0.0.1 -P 3398 -u "${I1}_ci" -N -e 'SELECT 42' 2>&1)
grep -q "Access denied" <<< "$o3" && ! grep -qx 42 <<< "$o3" && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1 host: 127.0.0.1 over TCP blev ikke afvist for '${I1}_ci'@'localhost' med skip-name-resolve — er host = localhost stadig nødvendig? ($o3)"; }
o3=$(MYSQL_PWD=$PC mysql --no-defaults -S "$S/db2.sock" -u "${I1}_ci" -N -e 'SELECT 42' 2>&1); chk "$o3" "42" "U1 host: samme konto over socket (localhost) virker"
# AK4: anden kørsel (efter første deploy) ændrer intet og ender igen i KLAR
st1() { stat -c '%Y %a %s' "$H1/config/config.ini" "$H1/config/github.token"; sha256sum "$H1/config/config.ini" "$H1/config/github.token"; git -C "$H1/repo" rev-parse HEAD; }
B4=$(st1); sleep 1; o=$(printf '%s\n' "$TK" | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1)
[ "$(st1)" = "$B4" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK4: anden kørsel ændrede config.ini, token eller repo-HEAD"; }
chk "$o" "✔ config/config.ini findes — røres ikke" "U1-AK4: config.ini røres ikke"; chk "$o" "✔ repo/ findes — røres ikke" "U1-AK4: repo røres ikke"
chk "$o" "SERVER: KLAR (dev)" "U1-AK4: anden kørsel ender igen i KLAR"; [ "$(grep -c "$H1/cron/ci.sh" "$S/crontab")" = 1 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK4: dobbelte cron-linjer"; }
# AK6 ved init: et eksisterende token med gruppe/andre-rettigheder (FinanceMCP: 664) er lækket — indholdet røres ikke;
# setup (via init) skriver markøren logs/.token-laekket og lukker det, så ✘ står i hver kørsel (go-v2 2, go-v3 1)
chmod 664 "$H1/config/github.token"; B4=$(sha256sum "$H1/config/github.token" "$H1/config/config.ini"); o=$(printf '%s\n' "$TK" | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1)
chk "$o" "✘ config/github.token (chmod 664) har været læsbart for andre — roter tokenet på GitHub (SERVERKONTRAKT §8)" "U1-AK6: init melder et læsbart token som lækket"
nchk "$o" "✔ config/github.token" "U1-AK6: init melder ikke et lækket token som ✔"; chk "$o" "SERVER: IKKE KLAR (dev)" "U1-AK6: init med lækket token er ikke KLAR"
[ "$(sha256sum "$H1/config/github.token" "$H1/config/config.ini")" = "$B4" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1-AK6: init ændrede tokenet eller config.ini"; }
perm "$H1/config/github.token" "600 $I1" "U1-AK6: init/setup lukkede det lækkede token"; perm "$H1/logs/.token-laekket" "600 $I1" "U1-AK6: markøren logs/.token-laekket er skrevet"; [ -e "$H1/config/.token-laekket" ] && { failn=$((failn+1)); echo "FEJL: go-v3 1: markøren står i config/ (deploy sletter den)"; } || pass=$((pass+1))
# Det lækkede token igen — indtastet, eller Enter (= filens token, go-v2 4) — er ✘ i init og i check, hver gang
o=$(printf '%s\n' "$TK" | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1); chk "$o" "det er det lækkede token, linjen har fået" "U1-AK6: det lækkede token indtastet igen = ✘"
chk "$o" "SERVER: IKKE KLAR (dev)" "U1-AK6: stadig ikke KLAR i næste kørsel (filen er nu 600)"; nchk "$o" "== Fortsætter som setup" "U1-AK6: init stopper før setup med det lækkede token"
o=$(printf '\n' | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1); chk "$o" "det er det lækkede token, linjen har fået" "U1 Enter: tom indtastning = filens token (her det lækkede) = ✘"
chk "$(cat "$S/u1-fetch.cfg")" "Bearer $TK" "U1 Enter: linjen henter server.sh med filens token"
o=$(au "$I1" "$H1" bash -c "cd ~ && bash '$S/u1-server.sh' check dev" 2>&1); chk "$o" "✘ config/github.token er meldt lækket" "U1-AK6: check melder det lækkede token, også efter chmod 600"
# go-v3 3: samme token uden \n er stadig det lækkede — i init (Enter) og i check. Filen er stadig 600 (root skriver indholdet)
printf '%s' "$TK" > "$H1/config/github.token"; o=$(printf '\n' | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1); chk "$o" "det er det lækkede token, linjen har fået" "go-v3 3: init: samme token uden linjeskift forbliver ✘"
o=$(au "$I1" "$H1" bash -c "cd ~ && bash '$S/u1-server.sh' check dev" 2>&1); chk "$o" "✘ config/github.token er meldt lækket" "go-v3 3: check: samme token uden linjeskift forbliver ✘"
# Rotation (go-v2 3): et ANDET token erstatter det lækkede atomisk (600), markøren fjernes, og samme kørsel ender i KLAR
TK2=tokU1NYTrot77; : > "$S/u1-argv.log"; o=$(printf '%s\n' "$TK2" | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1)
chk "$o" "✔ config/github.token: token udskiftet" "U1 rotation: et nyt token erstatter det lækkede"; chk "$o" "SERVER: KLAR (dev)" "U1 rotation: KLAR i samme kørsel"
[ "$(cat "$H1/config/github.token")" = "$TK2" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1 rotation: config/github.token har ikke det nye token"; }
perm "$H1/config/github.token" "600 $I1" "U1-AK2: det udskiftede token er 600"; [ -e "$H1/logs/.token-laekket" ] && { failn=$((failn+1)); echo "FEJL: U1 rotation: markøren står tilbage"; } || pass=$((pass+1))
[ -z "$(ls -A "$H1/config" | grep '^\.github\.token\.')" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1 rotation: midlertidig tokenfil ligger tilbage i config/"; }
A=$(cat "$S/u1-argv.log"); chk "$A" "contents/shared/install/server.sh" "U1-AK3: argv målt under rotationen"
for x in "$TK2" "$TK"; do nchk "$A" "$x" "U1-AK3: token i argv under rotationen"; nchk "$o" "$x" "U1-AK3: token i output under rotationen"; done
# Et andet token, når filens IKKE er meldt lækket: intet overskrives (go-v2 3) — Enter bruger filens (go-v2 4, QA F3)
B4=$(sha256sum "$H1/config/github.token"); o=$(printf '%s\n' tokU1FREMMED99 | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1)
chk "$o" "✘ tokenet er ikke det i config/github.token — init overskriver ikke et token, der ikke er meldt lækket" "U1 rotation: andet token uden markør = ✘"
chk "$o" "SERVER: IKKE KLAR (dev)" "U1 rotation: andet token uden markør er ikke KLAR"; [ "$(sha256sum "$H1/config/github.token")" = "$B4" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: U1 rotation: et token uden markør blev overskrevet"; }
o=$(printf '\n' | au "$I1" "$H1" bash -c "${LINE/<miljø>/dev}" 2>&1); chk "$o" "SERVER: KLAR (dev)" "U1 Enter: tom indtastning bruger filens token — KLAR uden at taste det"
chk "$(cat "$S/u1-fetch.cfg")" "Bearer $TK2" "U1 Enter: hentningen brugte filens token"
# Validering før skrivning: to appbaser, et kodeord frameworket ikke kan læse, intet token eller et ugyldigt repo = ✘, intet skrevet
L2=${LINE/<miljø>/test}; dbf "$H2/private/db-${I2}_andet.txt" "${I2}_andet" "$PA"; chown "$I2:$I2" "$H2/private/db-${I2}_andet.txt"
o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L2" 2>&1); chk "$o" "flere end én database i private/" "U1: to appbaser = init gætter ikke"
[ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1: init skrev noget trods ✘ (to baser)"; } || pass=$((pass+1)); rm -f "$H2/private/db-${I2}_andet.txt"
dbf "$H2/private/db-${I2}_${I2}.txt" "${I2}_${I2}" 'ab"c$d'; o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L2" 2>&1)
chk "$o" "kodeordet indeholder" "U1: kodeord med \" \\ eller \$ afvises"; nchk "$o" 'ab"c$d' "U1-AK3: et afvist kodeord vises ikke"
[ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1: init skrev noget trods ✘ (kodeord)"; } || pass=$((pass+1)); dbf "$H2/private/db-${I2}_${I2}.txt" "${I2}_${I2}" "$PA"
o=$(printf '\n' | au "$I2" "$H2" bash -c "$L2" 2>&1); chk "$o" "✘ intet token — config/github.token findes ikke" "U1 Enter: tom indtastning uden config/github.token = ✘"
[ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1 Enter: tom indtastning uden token skrev noget"; } || pass=$((pass+1))
o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "${L2//o\/r/o\/..}" 2>&1); chk "$o" "repoet (<ejer>/<repo>) mangler i linjen eller er ugyldigt" "U1 repo: en del, der er .., afvises (go-v2 1)"
[ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1 repo: ugyldigt repo skrev noget"; } || pass=$((pass+1))
# Forkert repo (go-v2 1, security MEDIUM): o/x kan klones, men coworker.ini på main siger o/r — stop FØR setup: ingen cron
L4=${L2//o\/r/o\/x}; o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L4" 2>&1)
chk "$o" "✘ repo/ er ikke o/x fra linjen (coworker.ini på main: [deploy] repo = 'o/r')" "U1 repo: forkert repo meldes"; chk "$o" "SERVER: IKKE KLAR (test) — intet er lagt i cron/" "U1 repo: forkert repo stopper init"
nchk "$o" "== Fortsætter som setup" "U1 repo: forkert repo når ikke setup"; nchk "$(cat "$S/crontab")" "$H2/cron" "U1 repo: forkert repo giver ingen cron-linjer"
[ -e "$H2/cron/deploy.sh" ] && { failn=$((failn+1)); echo "FEJL: U1 repo: forkert repo lagde cron/deploy.sh"; } || pass=$((pass+1))
o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L4" 2>&1); chk "$o" "✘ repo/ er ikke o/x fra linjen" "U1 repo: anden kørsel (repo/ findes) stopper også"
nchk "$(cat "$S/crontab")" "$H2/cron" "U1 repo: heller ingen cron-linjer efter anden kørsel"; rm -rf "$H2/config" "$H2/repo" "$H2/cron" "$H2/logs"
# go-v3 5 (QA F2): main uden coworker.ini er ikke "forkert repo" (egen besked, repo/ bliver), og en gammel klon hentes før sammenligningen
git init -q --bare "$S/y.git"; git -C "$S/y.git" symbolic-ref HEAD refs/heads/main; git clone -q "$S/y.git" "$S/ysrc" 2>/dev/null
( cd "$S/ysrc" && git checkout -q -b main && echo x > a && git add -A && git commit -qm start && git push -q origin main 2>/dev/null ); chmod -R a+rX "$S/y.git"
git config --file "$H2/.gitconfig" --add url."file://$S/y.git".insteadOf "https://github.com/o/y.git"; cp "$S/crontab" "$S/crontab.u1y"; L5=${L2//o\/r/o\/y}
o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L5" 2>&1); chk "$o" "✘ main har ingen coworker.ini endnu" "go-v3 5: main uden coworker.ini har sin egen besked"; nchk "$o" "flyt repo/ væk" "go-v3 5: main uden coworker.ini siger ikke flyt repo/"
chk "$o" "SERVER: IKKE KLAR (test) — intet er lagt i cron/" "go-v3 5: main uden coworker.ini stopper før setup"; nchk "$(cat "$S/crontab")" "$H2/cron" "go-v3 5: ingen cron-linjer uden coworker.ini"; OLDH=$(git -C "$H2/repo" rev-parse HEAD)
( cd "$S/ysrc" && printf '[deploy]\nrepo = o/y\n' > coworker.ini && git add -A && git commit -qm ini && git push -q origin main 2>/dev/null ); chmod -R a+rX "$S/y.git"
o=$(printf '\n' | au "$I2" "$H2" bash -c "$L5" 2>&1); nchk "$o" "main har ingen coworker.ini" "go-v3 5: klonen hentes før sammenligningen"; nchk "$o" "✘ repo/ er ikke" "go-v3 5: det rigtige repo dømmes ikke forkert"; chk "$o" "== Fortsætter som setup" "go-v3 5: init fortsætter efter hentning"
[ "$(git -C "$H2/repo" rev-parse HEAD)" = "$OLDH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 5: init flyttede HEAD i repo/ (hent må ikke ændre klonen)"; }
au "$I2" "$H2" git -C "$H2/repo" remote set-url origin "file://$S/findes-ikke.git"; cp "$S/crontab.u1y" "$S/crontab"
o=$(printf '\n' | au "$I2" "$H2" bash -c "$L5" 2>&1); chk "$o" "✘ git fetch i repo/ fejler" "go-v3 5: fejlet hentning meldes"; chk "$o" "intet er lagt i cron/" "go-v3 5: fejlet hentning stopper før setup"; nchk "$o" "main har ingen coworker.ini" "go-v3 5: fejlet hentning er ikke 'ingen coworker.ini'"
cp "$S/crontab.u1y" "$S/crontab"; rm -rf "$H2/config" "$H2/repo" "$H2/cron" "$H2/logs"
# AK5: STABLE kræver et eksplicit svar (EOF eller tre forkerte = afbryd uden at skrive); TEST sætter false uden at spørge
L3=${LINE/<miljø>/stable}; o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L3" 2>&1)
chk "$o" "real_data blev ikke besvaret" "U1-AK5: stable uden svar afbryder"; [ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1-AK5: stable uden svar skrev noget"; } || pass=$((pass+1))
o=$(printf '%s\nja\nTrue\n1\nfalse\n' "$TK" | au "$I2" "$H2" bash -c "$L3" 2>&1); chk "$o" "intet er skrevet" "U1-AK5: tre forkerte svar afbryder"
[ -e "$H2/config" ] && { failn=$((failn+1)); echo "FEJL: U1-AK5: tre forkerte svar skrev noget"; } || pass=$((pass+1))
o=$(printf '%s\nmåske\ntrue\n' "$TK" | au "$I2" "$H2" bash -c "$L3" 2>&1); chk "$(cat "$H2/config/config.ini")" "real_data = true" "U1-AK5: stable skriver det eksplicitte svar"
chk "$o" "✔ real_data = true" "U1-AK5: setup stable godtager svaret"; perm "$H2/config/config.ini" "600 $I2" "U1-AK2: config.ini er 600 (stable)"
rm -rf "$H2/config" "$H2/repo" "$H2/cron" "$H2/logs"; o=$(printf '%s\n' "$TK" | au "$I2" "$H2" bash -c "$L2" 2>&1)
chk "$(cat "$H2/config/config.ini")" "real_data = false" "U1-AK5: test sætter real_data = false"; nchk "$o" "rigtige kundedata (real_data)?" "U1-AK5: test spørger ikke"
# config/ lukkes for andre på ALLE miljøer (go-v2 5): check melder en 755-mappe på test og ændrer intet; linjen (setup) lukker den
chmod 755 "$H2/config"; o=$(au "$I2" "$H2" bash -c "cd ~ && bash '$S/u1-server.sh' check test" 2>&1)
chk "$o" "✘ config/ (chmod 755) kan læses af andre" "U1 config/: check test melder en mappe, andre kan læse"; perm "$H2/config" "755 $I2" "U1 config/: check ændrer ikke mappen"
o=$(printf '\n' | au "$I2" "$H2" bash -c "$L2" 2>&1); chk "$o" "✔ config/ lukket for andre (var chmod 755)" "U1 config/: setup test lukker mappen"; perm "$H2/config" "750 $I2" "U1 config/: o-rwx på test"
# go-v3 4: logs/ findes ikke — check laver den (700), fordi markøren kræver det, og intet andet (ingen cron/, ingen chmod af tokenet)
rm -rf "$H2/logs" "$H2/cron"; chmod 664 "$H2/config/github.token"; o=$(au "$I2" "$H2" bash -c "cd ~ && bash '$S/u1-server.sh' check test" 2>&1)
perm "$H2/logs" "700 $I2" "go-v3 4: check laver logs/ (700) til markøren"; perm "$H2/logs/.token-laekket" "600 $I2" "go-v3 4: check skriver markøren uden logs/ i forvejen"; perm "$H2/config/github.token" "664 $I2" "go-v3 4: check chmod'er ikke"
[ -e "$H2/cron" ] && { failn=$((failn+1)); echo "FEJL: go-v3 4: check lavede cron/"; } || pass=$((pass+1))
[ "$(ls -A "$H2/logs")" = ".token-laekket" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 4: check skrev andet end markøren i logs/: $(ls -A "$H2/logs" | tr '\n' ' ')"; }
# Et rent token: check rører ikke logs/
chmod 600 "$H2/config/github.token"; rm -rf "$H2/logs"; o=$(au "$I2" "$H2" bash -c "cd ~ && bash '$S/u1-server.sh' check test" 2>&1); [ -e "$H2/logs" ] && { failn=$((failn+1)); echo "FEJL: go-v3 4: check lavede logs/ uden at markøren krævede det"; } || pass=$((pass+1))
[ -n "$GL" ] || echo "BEMÆRK: gitleaks ikke fundet — hemmelighedstrinnet blev testet som rødt (sæt GITLEAKS=<sti>)"
echo "SELFTEST-CI: $pass/$((pass+failn)) OK"
[ $failn = 0 ]
