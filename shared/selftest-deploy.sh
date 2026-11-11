#!/usr/bin/env bash
# FILE_VERSION: 5.42
# shared/selftest-deploy.sh — test af shared/install/deploy.sh mod et lokalt repo med stubs for GitHub,
# health og mail. Køres af framework-vedligeholderen før hver levering (Linux med git, php, rsync, flock).
#   bash shared/selftest-deploy.sh [master-mappe]   → "SELFTEST-DEPLOY: N/N OK"
set -uo pipefail
M=$(cd "${1:-$(dirname "$0")/..}" && pwd)
S=$(mktemp -d); trap 'rm -rf "$S"' EXIT
pass=0; failn=0
chk() { if grep -qF -- "$2" <<< "$1"; then pass=$((pass+1)); else failn=$((failn+1)); echo "FEJL: $3 — forventede: $2"; fi; }
mkdir -p "$S/bin"; cd "$S"
git init -q --bare origin.git; git -c init.defaultBranch=main clone -q origin.git src 2>/dev/null; cd src
git config user.email t@t; git config user.name t; git checkout -q -b main
c(){ git add -A; git commit -qm "$1"; git push -q origin main 2>/dev/null; }
mkdir -p homedir/public_html homedir/config .github/workflows; echo v1 > homedir/public_html/index.php; echo '[F1_x]' > homedir/config/features.ini; echo ci1 > .github/workflows/ci.yml
printf '[deploy]\nrepo = o/r\nphp = php\nmigrate = bash -c "exit ${MIGFAIL:-0}"\n[health]\ndev = http://health\nstable = http://health\n' > coworker.ini
c "H001: v1"
H=$S/home; mkdir -p "$H/config" "$H/cron" "$H/public_html"; git clone -q "$S/origin.git" "$H/repo" 2>/dev/null
echo LIVE > "$H/public_html/index.php"
mkdir -p "$H/public_html/uploads"; echo kundefil > "$H/public_html/uploads/kunde.pdf"; echo NØGLE > "$H/public_html/uploads/server.key"
printf 'alert_email=a@b\nreal_data=false\n' > "$H/config/config.ini"
printf 'tok-HEMMELIG-123\n' > "$H/config/github.token"; chmod 644 "$H/config/github.token"
sed 's#/usr/sbin/sendmail#sendmail#' "$M/shared/install/deploy.sh" > "$H/cron/deploy.sh"
cat > "$S/bin/curl" <<'E'
#!/bin/bash
for a; do u=$a; done
echo "ARGV: $*" >> "$S_DIR/curl.log"; [[ " $* " == *" -K - "* ]] && echo "STDIN: $(cat)" >> "$S_DIR/curl.log"
case "$u" in
 *releases*) cat "$S_DIR/releases.json" 2>/dev/null || echo '[]';;
 */statuses*) if [ -n "${NOSTATUS:-}" ]; then echo '[]'; elif [ "${GREEN:-1}" = 1 ]; then echo '[{"context":"coworker/monitor","state":"success","updated_at":"'"${MONTIME:-2000-01-01T00:00:00Z}"'"},{"context":"coworker/ci","state":"success"},{"context":"coworker/ci","state":"failure"}]'; else echo '[{"context":"coworker/ci","state":"pending"}]'; fi;;
 *actions/workflows/monitor*) echo '{"workflow_runs":[]}';;
 *actions/workflows*) echo "{\"total_count\": ${ACTIONS:-0}}";;
 *api.github.com*) echo "{\"total_count\": 0}";;
 *health*) [ -n "${HEALTHBAD:-}" ] && { echo '{"status":"fejl"}'; exit 0; }
           sha=$(sed -n "s/.*'\(.*\)'.*/\1/p" "$HOMEX/config/deployed_sha.php"); echo "{\"sha\":\"$sha\",\"db_read\": \"ok\",\"db_write\": \"ok\"}";;
esac
E
printf '#!/bin/bash\ncat >> %s/mail.txt\n' "$S" > "$S/bin/sendmail"; chmod +x "$S"/bin/*
export PATH="$S/bin:$PATH" HOMEX="$H" S_DIR="$S"
run(){ bash "$H/cron/deploy.sh" "${1:-dev}" 2>&1; }
page(){ cat "$H/public_html/index.php"; }
o=$(HEALTHBAD=1 run); chk "$o" "indholdet før første udrulning" "første udrulning fejler → snapshot"; chk "$(page)" "LIVE" "snapshot gendannet"
# U1 v3 (go-v3 2, 3): åbent token (644, sat ovenfor) → markøren skrevet FØR chmod, med sha256 af det TRIMMEDE token, 600
LKF=$H/logs/.token-laekket; TOKH=$(tr -d ' \r\n' < "$H/config/github.token" | sha256sum | cut -d' ' -f1)
chk "$o" "meldt lækket i logs/.token-laekket" "go-v3 2: deploy.sh logger markøren"
[ "$(cat "$LKF" 2>&1)" = "$TOKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: åbent token gav ikke markøren med sha256 af det trimmede token"; }
[ "$(stat -c %a "$LKF" 2>&1)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: markøren er ikke 600"; }
[ -z "$(ls -A "$H/logs" | grep '^\.token-laekket\.')" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: midlertidig markørfil ligger tilbage i logs/"; }
echo k > "$H/config/.kanari"   # beviser nedenfor, at udrulningen spejler config/ — ellers siger "markøren overlever" intet
ls -R "$H/logs/predeploy" > "$S/pre.txt" 2>/dev/null; grep -qE 'kunde.pdf|server.key' "$S/pre.txt" && { failn=$((failn+1)); echo "FEJL: hemmeligheder/kundefiler kopieret til logs/predeploy"; } || pass=$((pass+1))
[ -f "$H/public_html/uploads/kunde.pdf" ] && [ -f "$H/public_html/uploads/server.key" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: tilbagerulning fra predeploy slettede filer, der ikke var kopieret"; }
rm -f "$H/logs/deploy.failed"
o=$(run); chk "$o" "DEPLOY OK" "første udrulning"; chk "$(page)" "v1" "v1 kører"
echo v2 > homedir/public_html/index.php; c "H002"
o=$(GREEN=0 run); chk "$o" "ikke grøn endnu" "rød CI venter"
o=$(HEALTHBAD=1 run); chk "$o" "rullet tilbage" "health-fejl → rollback"; chk "$(page)" "v1" "gammel kode tilbage"
o=$(run); chk "$(page)" "v1" "fejlet commit prøves ikke igen af sig selv"
o=$(bash "$H/cron/deploy.sh" dev retry 2>&1); chk "$o" "prøver" "deploy.sh dev retry prøver den fejlede commit igen (5.22)"; chk "$(page)" "v2" "retry udruller, når fejlen var forbigående"
echo v3 > homedir/public_html/index.php; c "H003"
o=$(MIGFAIL=1 run); chk "$o" "migration fejlede" "migrationsfejl stopper"; chk "$(page)" "v2" "kode ikke skiftet ved migrationsfejl"
echo v4 > homedir/public_html/index.php; c "H004"; o=$(run); chk "$(page)" "v4" "ny kode udrulles"
echo ci2 > .github/workflows/ci.yml; echo v5 > homedir/public_html/index.php; c "H005"
o=$(run); chk "$o" "venter på dig" "kontrolændring venter på Publish"; chk "$(page)" "v4" "kontrolændring ikke udrullet"
git tag ctrl-1; git push -q origin ctrl-1 2>/dev/null
echo '[{"tag_name":"ctrl-1","draft":false,"prerelease":true,"published_at":"2026-10-07T10:00:00Z"}]' > "$S/releases.json"
o=$(run); chk "$o" "DEPLOY OK" "godkendt kontrolændring udrulles"; chk "$(page)" "v5" "v5 kører"
touch "$H/logs/deploying"; echo v6 > homedir/public_html/index.php; c "H006"
o=$(run); chk "$o" "hang fra en afbrudt kørsel" "efterladt pause ryddes med alarm"
sed -i '/real_data/d' "$H/config/config.ini"; o=$(run); chk "$o" "real_data = true eller false" "real_data skal være eksplicit"
o=$(run); n=$(grep -c 'real_data' "$S/mail.txt"); [ "$n" = 1 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: alarm-dedup — $n mails"; }
l=$(cat "$S/curl.log"); chk "$l" "Bearer tok-HEMMELIG-123" "token sendes til GitHub via stdin"
grep -q '^ARGV:.*HEMMELIG' "$S/curl.log" && { failn=$((failn+1)); echo "FEJL: token står i curl-argumenter"; } || pass=$((pass+1))
[ "$(stat -c %a "$H/config/github.token")" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: token-fil ikke chmod 600"; }
[ -s "$H/config/github.token" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: config/github.token slettet af udrulningen"; }
find "$S" -path '*predeploy*' -name github.token | grep -q . && { failn=$((failn+1)); echo "FEJL: token kopieret til predeploy"; } || pass=$((pass+1))
# go-v3 1, 2 (QA F1): markøren overlever udrulninger, tilbagerulninger og spejlingen af homedir/config/ (features.ini)
[ -e "$H/config/.kanari" ] && { failn=$((failn+1)); echo "FEJL: go-v3 2: testen beviser intet — udrulningen spejlede ikke config/"; } || pass=$((pass+1))
[ "$(cat "$LKF" 2>&1)" = "$TOKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: markøren overlevede ikke en udrulning med homedir/config/"; }
# Kæden: markøren fra deploy.sh læses af server.sh (samme format) — også når samme token står uden linjeskift (go-v3 3)
sv() { ( cd "$H" && CW_SELFTEST=1 bash "$M/shared/install/server.sh" check test 2>&1 ); }
o=$(sv); chk "$o" "✘ config/github.token er meldt lækket" "go-v3 2: server.sh læser markøren fra deploy.sh"
printf 'tok-HEMMELIG-123' > "$H/config/github.token"; o=$(sv); chk "$o" "✘ config/github.token er meldt lækket" "go-v3 3: samme token uden linjeskift forbliver ✘"; printf 'tok-HEMMELIG-123\n' > "$H/config/github.token"
# go-v3 6 (QA F3): fejler markøren (en mappe på dens plads), lader deploy.sh filen være og logger det
rm -f "$LKF"; mkdir -p "$LKF/x"; chmod 644 "$H/config/github.token"; o=$(run); chk "$o" "logs/.token-laekket kunne ikke skrives — filen er ladt åben" "go-v3 6: deploy.sh logger en markør, der fejler"
[ "$(stat -c %a "$H/config/github.token")" = 644 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 6: deploy.sh chmod'ede tokenet, selv om markøren fejlede"; }
rm -rf "$LKF"
# Markøren følger ikke et symlink
echo uroert > "$S/lk-maal"; ln -s "$S/lk-maal" "$LKF"; o=$(run)
[ "$(cat "$S/lk-maal")" = uroert ] && [ ! -L "$LKF" ] && [ "$(cat "$LKF")" = "$TOKH" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: markøren fulgte et symlink"; }
[ "$(stat -c %a "$H/config/github.token")" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 2: token ikke lukket efter markøren"; }
# Et token, der kun er forkert for ejeren (400): chmod uden markør — andre har ikke kunnet læse det
rm -f "$LKF"; chmod 400 "$H/config/github.token"; o=$(run); chk "$o" "var ikke chmod 600 — rettet" "go-v3 2: 400 rettes som før"; [ -e "$LKF" ] && { failn=$((failn+1)); echo "FEJL: go-v3 2: 400 gav en markør"; } || pass=$((pass+1))
# tokhash og tokmark er ens i de tre scripts (samme format overalt)
for f in ci.sh server.sh; do [ "$(grep -E '^tok(hash|mark)\(\)' "$M/shared/install/deploy.sh")" = "$(grep -E '^tok(hash|mark)\(\)' "$M/shared/install/$f")" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: go-v3 3: tokhash/tokmark i $f er ikke som i deploy.sh"; }; done
# v5.14: DEV-rollback via tagget dev — også tilbage over en kontrolændring, der har kørt på DEV før
printf 'alert_email=a@b\nreal_data=false\n' > "$H/config/config.ini"; rm -f "$H/logs/deploy.failed"
o=$(run); chk "$(page)" "v6" "v6 kører før rollback"
H4=$(git log --format=%H --grep='^H004' -1); git tag -f dev "$H4" >/dev/null; git push -q -f origin refs/tags/dev 2>/dev/null
o=$(run); chk "$o" "DEPLOY OK" "DEV ruller tilbage til låst version"; chk "$(page)" "v4" "DEV kører v4 (før kontrolændringen, som har kørt her)"
H2=$(git log --format=%H --grep='^H002' -1); git tag -f dev "$H2" >/dev/null; git push -q -f origin refs/tags/dev 2>/dev/null
o=$(run); chk "$(page)" "v2" "DEV kan gå til en version, der aldrig har kørt (samme kontrolfiler)"
git checkout -q -b side; echo vX > homedir/public_html/index.php; git commit -qam "side"; git push -q origin side 2>/dev/null
git tag -f dev side >/dev/null; git push -q -f origin refs/tags/dev 2>/dev/null; git checkout -q main
o=$(run); chk "$o" "er ikke på main" "DEV afviser låsning til commit uden for main"; chk "$(page)" "v2" "DEV uændret ved afvist lås"
git push -q origin :refs/tags/dev 2>/dev/null; rm -f "$H/logs/deploy.failed"
o=$(run); chk "$(page)" "v6" "uden tagget dev følger DEV main igen"
# v5.16: CI-status fra cron/ci.sh — ingen status: kun en grøn push-kørsel af den gamle ci.yml tæller (overgang)
echo v7 > homedir/public_html/index.php; c "H007"
o=$(NOSTATUS=1 run); chk "$o" "ikke grøn endnu" "ingen CI-status og ingen Actions-kørsel = ikke grøn"; chk "$(page)" "v6" "v7 ikke udrullet uden grøn CI"
o=$(NOSTATUS=1 ACTIONS=1 run); chk "$o" "DEPLOY OK" "overgang: grøn gammel ci.yml-kørsel tæller"; chk "$(page)" "v7" "v7 kører"
echo v8 > homedir/public_html/index.php; c "H008"
o=$(run); chk "$o" "DEPLOY OK" "seneste coworker/ci = success udrulles (ældre failure ignoreres)"; chk "$(page)" "v8" "v8 kører"
# v5.16: overgang — cron/ci.sh og cron/monitor.sh (lagt ind af server.sh) slettes ikke, og deploy.sh nedgraderes ikke
cp "$H/cron/deploy.sh" "$S/deploy.ny"; echo '# ci' > "$H/cron/ci.sh"; echo '# mon' > "$H/cron/monitor.sh"
mkdir -p homedir/cron; printf '#!/bin/bash\n# gammel deploy.sh (kun Actions)\n' > homedir/cron/deploy.sh; echo x > homedir/cron/andet.sh; echo v9 > homedir/public_html/index.php; c "H009"
git tag ctrl-2; git push -q origin ctrl-2 2>/dev/null
echo '[{"tag_name":"ctrl-2","draft":false,"prerelease":true,"published_at":"2026-10-09T10:00:00Z"}]' > "$S/releases.json"
o=$(run); chk "$o" "DEPLOY OK" "commit med gammel homedir/cron udrulles (godkendt)"; chk "$(page)" "v9" "v9 kører"
[ -f "$H/cron/ci.sh" ] && [ -f "$H/cron/monitor.sh" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: cron/ci.sh eller cron/monitor.sh slettet af deploy"; }
cmp -s "$H/cron/deploy.sh" "$S/deploy.ny" && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: deploy.sh nedgraderet til en version uden cron/ci.sh"; }
[ -f "$H/cron/andet.sh" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: andre cron-filer udrulles ikke"; }
# v5.21: frameworkets cron-scripts er eksekverbare efter udrulning, også når repo/server mistede x-bit (Windows, [deploy] chmod)
[ -x "$H/cron/ci.sh" ] && [ -x "$H/cron/monitor.sh" ] && [ -x "$H/cron/deploy.sh" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: cron-scripts uden x-bit efter deploy"; }
# v5.16: STABLE's vagt over overvågningen læser livstegnet "coworker/monitor" (sat af cron/monitor.sh)
rm -f "$H/logs/monitor.checked"; o=$(MONTIME=2000-01-01T00:00:00Z run stable); chk "$o" "har ikke meldt sig i 13 timer" "STABLE: gammelt livstegn = alarm"
rm -f "$H/logs/monitor.checked"; o=$(MONTIME=$(date -u +%Y-%m-%dT%H:%M:%SZ) run stable); grep -q "13 timer" <<< "$o" && { failn=$((failn+1)); echo "FEJL: STABLE: frisk livstegn gav alarm"; } || pass=$((pass+1))
# v5.28: et kontroludkast udgivet UDEN pre-release-valget (ctrl-2026.10.09-3) er aldrig et mål for STABLE
H4=$(git log --format=%H --grep='^H004' -1); git tag -f v2026.10.18-1 "$H4" >/dev/null; git push -q -f origin refs/tags/v2026.10.18-1 2>/dev/null
git tag -f ctrl-3 HEAD >/dev/null; git push -q -f origin refs/tags/ctrl-3 2>/dev/null
echo '[{"tag_name":"v2026.10.18-1","draft":false,"prerelease":false,"published_at":"2026-10-18T09:00:00Z"},{"tag_name":"ctrl-3","draft":false,"prerelease":false,"published_at":"2026-10-18T10:00:00Z"}]' > "$S/releases.json"
rm -f "$H/logs/deploy.failed"; o=$(MONTIME=$(date -u +%Y-%m-%dT%H:%M:%SZ) run stable); chk "$(page)" "v4" "STABLE følger kunde-releasen, ikke et ctrl-tag uden pre-release (5.28)"
rm -f "$H/logs/deploy.failed"; run >/dev/null
# v5.27: databasen gemmes før en migration (maskinens site-backup er natlig, projektets backup.sh udgår)
if mariadb -e 'SELECT 1' >/dev/null 2>&1; then
  mariadb -e "DROP DATABASE IF EXISTS cwdeploytest; CREATE DATABASE cwdeploytest; CREATE TABLE cwdeploytest.k (id INT); INSERT INTO cwdeploytest.k VALUES (7)"
  # 5.36 (econsync): værdier i '…' læses som i "…"; kodeordet citeres i login-filen (#, \, " og " ;" i koden)
  mariadb -e "DROP USER IF EXISTS 'cwdt'@'localhost'; CREATE USER 'cwdt'@'localhost' IDENTIFIED BY 'p#a\\\\b\"c ;d'; GRANT ALL ON cwdeploytest.* TO 'cwdt'@'localhost'"
  printf '%s\n' 'alert_email=a@b' 'real_data=false' '[database]' 'host = localhost' "name = 'cwdeploytest'" 'user = "cwdt"' "pass = 'p#a\\b\"c ;d' ; kommentar" > "$H/config/config.ini"
  mkdir -p homedir/migrations; echo 'SELECT 1;' > homedir/migrations/0001_v1_x.sql; echo v10 > homedir/public_html/index.php; c "H010"
  o=$(HEALTHBAD=1 run); chk "$o" "rullet tilbage" "migration: dump før, udrulningen fejler bagefter (dumpet bliver liggende)"
  chk "$(zcat "$H/logs/predeploy-db.sql.gz" 2>/dev/null)" '(7)' "dump før migration med 'navn' og kodeord med # \\ \" ; (5.36)"
  [ "$(stat -c %a "$H/logs/predeploy-db.sql.gz" 2>/dev/null)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: dump før migration er ikke chmod 600"; }
  [ ! -e "$H/logs/.predeploy.cnf" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: databasens login-fil blev liggende i logs/"; }
  o=$(bash "$H/cron/deploy.sh" dev retry 2>&1); chk "$o" "DEPLOY OK" "migration: retry udruller"; chk "$(page)" "v10" "v10 kører"
  [ ! -e "$H/logs/predeploy-db.sql.gz" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: dumpet (fuld kopi af databasen) blev liggende efter vellykket udrulning (5.36)"; }
  sed -i 's/^name = .*/name = findesikke/' "$H/config/config.ini"; echo 'SELECT 2;' > homedir/migrations/0002_v1_y.sql; echo v11 > homedir/public_html/index.php; c "H011"
  o=$(run); chk "$o" "dump af databasen før migration fejlede" "fejlet dump stopper udrulningen"; chk "$(page)" "v10" "kode ikke skiftet, når dumpet fejler"
  # 5.36: uden [database] name (og uden backup.sh) kørte migrationen før uden dump og uden besked
  sed -i '/^name = /d' "$H/config/config.ini"; rm -f "$H/logs/deploy.failed"
  o=$(run); chk "$o" "intet dump er muligt" "nye migrationer uden [database] name: afvist med alarm (5.36)"; chk "$(page)" "v10" "kode og migration ikke kørt uden dump"
  # 5.36: login-fil efterladt af en dræbt kørsel slettes; et dump fra en fejlet udrulning ligger højst 7 døgn
  echo "[client]" > "$H/logs/.predeploy.cnf"; echo x | gzip > "$H/logs/predeploy-db.sql.gz"; touch -d '8 days ago' "$H/logs/predeploy-db.sql.gz"
  run >/dev/null; [ ! -e "$H/logs/.predeploy.cnf" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: efterladt .predeploy.cnf blev ikke slettet"; }
  [ ! -e "$H/logs/predeploy-db.sql.gz" ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: dump ældre end 7 døgn blev ikke slettet"; }
  # 5.33 (Storix): et ugyldigt view (fejl 1356) må ikke blokere den migration, der retter det
  printf 'name = cwdeploytest\n' >> "$H/config/config.ini"; rm -f "$H/logs/deploy.failed"
  mariadb -e "CREATE TABLE cwdeploytest.k2 (a INT, b INT); INSERT INTO cwdeploytest.k2 VALUES (8,9); CREATE VIEW cwdeploytest.vb AS SELECT b FROM cwdeploytest.k2; ALTER TABLE cwdeploytest.k2 DROP COLUMN b"
  echo 'SELECT 3;' > homedir/migrations/0003_v1_ret-view.sql; echo v12 > homedir/public_html/index.php; c "H012"
  o=$(HEALTHBAD=1 run); chk "$o" "dump før migration UDEN views (vb)" "ugyldigt view: dump uden views, siges i loggen"
  chk "$(zcat "$H/logs/predeploy-db.sql.gz" 2>/dev/null)" '(8)' "dump uden views har tabellernes data"
  o=$(bash "$H/cron/deploy.sh" dev retry 2>&1); chk "$o" "DEPLOY OK" "ugyldigt view blokerer ikke migrationen"; chk "$(page)" "v12" "v12 kører"
  chk "$(cat "$H/logs/incidents.log" 2>/dev/null)" "UDEN views (vb)" "dump uden views er en alarm, som monitor ser (5.34)"
  [ "$(stat -c %a "$H/logs/predeploy-db.err" 2>/dev/null)" = 600 ] && pass=$((pass+1)) || { failn=$((failn+1)); echo "FEJL: predeploy-db.err er ikke chmod 600"; }
  mariadb -e "DROP USER IF EXISTS 'cwdt'@'localhost'"
  mariadb -e "DROP DATABASE IF EXISTS cwdeploytest"
else echo "(dump før migration: sprunget over — ingen MariaDB)"; fi
# 5.34 (Storix): første kørsel på STABLE fra en gammel klon — origin/main hentes frisk, og målets egen coworker.ini
# gælder (main's uudgivne [deploy] php må ikke styre STABLE)
sed -i 's#^php = php$#php = /findes/ikke/php#' coworker.ini; c "H020: php skiftet på main, ikke udgivet"
H2=$S/home2; mkdir -p "$H2/config" "$H2/cron" "$H2/public_html"; git clone -q "$S/origin.git" "$H2/repo" 2>/dev/null
git -C "$H2/repo" update-ref refs/remotes/origin/main "$(git -C "$H2/repo" commit-tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904 -m gammel </dev/null)"
printf 'alert_email=a@b\nreal_data=true\n' > "$H2/config/config.ini"; printf 'tok\n' > "$H2/config/github.token"; chmod 600 "$H2/config/github.token"
sed 's#/usr/sbin/sendmail#sendmail#' "$M/shared/install/deploy.sh" > "$H2/cron/deploy.sh"
echo '[{"tag_name":"v2026.10.18-1","draft":false,"prerelease":false,"published_at":"2026-10-18T09:00:00Z"}]' > "$S/releases.json"
o=$(HOMEX="$H2" bash "$H2/cron/deploy.sh" stable 2>&1); chk "$o" "DEPLOY OK" "første STABLE-kørsel fra gammel klon: henter main og bruger releasens php"
chk "$(cat "$H2/public_html/index.php")" "v4" "første STABLE-kørsel udruller releasen"
echo "SELFTEST-DEPLOY: $pass/$((pass+failn)) OK"
[ $failn = 0 ]
