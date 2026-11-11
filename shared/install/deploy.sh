#!/bin/bash
# FILE_VERSION: 5.42 — FRAMEWORKETS FIL (låst af shared/framework.lock). Installeres UÆNDRET:
#   cp shared/install/deploy.sh homedir/cron/deploy.sh      (CI afviser enhver afvigelse)
# Serverens config/config.ini (ejerens fil, aldrig i git): alert_email, real_data = true|false (true =
# rigtige kundedata: kun releases). Tokenet (fine-grained, kun dette repo: Contents: Read + Commit statuses: Read)
# ligger i config/github.token (chmod 600 — webserverens gruppe må ikke kunne læse det); github_token i
# config.ini virker stadig, men giver en advarsel. Tokenet står aldrig på en kommandolinje (ps).
# Projektets værdier står i coworker.ini i repoet (kontrolfil — ændringer kræver ejerens Publish):
#   [deploy] repo = <ejer>/<repo> · php = /usr/local/bin/php84 · migrate = <kommando, ≠ 0 ved fejl>
#   [health] dev = https://… · test = https://… · stable = https://…
# Cron (INGEN flock i cron-linjen — scriptet låser selv):
#   */2 * * * * /home/<p>/cron/deploy.sh dev|test|stable >> /home/<p>/logs/deploy.log 2>&1
#
# Garantier: kun commits med grøn CI (commit-status "coworker/ci" = success, sat af cron/ci.sh på DEV-serveren) · test/stable kun commits på
# main · rigtige data følger KUN udgivne releases · KONTROLÆNDRINGER (CI, deploy, backup, report.sh,
# test-/scan-konfiguration) når ingen server, før ejeren har trykket Publish på en release eller en
# "Kontrol"-pre-release, der indeholder dem · én kørsel ad gangen · workers holder pause ·
# migrationer (kun expand) før koden skiftes · filerne skiftes næsten atomisk · health beviser ny SHA +
# DB-læsning + DB-skrivning · fejl = gammel kode tilbage + alarm (højst én mail pr. døgn pr. besked) ·
# GitHub-fejl eller rød CI i 60+ min = alarm · STABLE: alarm hvis overvågningen (cron/monitor.sh) står stille.
set -euo pipefail
export PATH="${PATH:+$PATH:}/usr/local/bin:/usr/bin:/bin"   # cron giver kun /usr/bin:/bin (composer m.fl. ligger i /usr/local/bin)
MODE="${1:-}"
HOME_DIR="$(cd "$(dirname "$0")/.." && pwd)"     # /home/<projekt>[-test|-dev]
REPO_DIR="$HOME_DIR/repo"
cd "$HOME_DIR"; mkdir -p logs
# config.ini og coworker.ini læses ENS i alle frameworkets scripts (5.36, econsync): "…" eller '…' omkring en værdi
# fjernes, og i en citeret værdi er ; og # almindelige tegn. Uciteret værdi: " ;…" til linjens ende er en kommentar.
cwini() { awk -v s="$1" -v k="$2" 'function cq(v, q,  j, r) { for (j = 2; j <= length(v); j++) if (substr(v, j, 1) == q) { r = substr(v, j + 1); if (r ~ /^[ \t]*(;.*)?$/) return j }; return 0 }
  /^[ \t]*\[/ { sec = $0; gsub(/^[ \t]+|[ \t\r]+$/, "", sec); next }
  s == "" || sec == "[" s "]" { i = index($0, "="); if (!i) next; key = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", key); if (key != k) next
    v = substr($0, i + 1); sub(/\r$/, "", v); sub(/^[ \t]+/, "", v); q = substr(v, 1, 1)
    if ((q == "\"" || q == "\047") && (e = cq(v, q))) v = substr(v, 2, e - 2); else { sub(/[ \t]+;.*$/, "", v); sub(/[ \t]+$/, "", v) }
    print v; exit }'; }
# Værdi i en MariaDB-optionsfil (.cnf): altid i "…" med \ og " escaped — #, \, " eller " ;" i et kodeord afkorter den ikke
cnfq() { local v=${1//\\/\\\\}; printf '"%s"' "${v//\"/\\\"}"; }
cfg()  { cwini "" "$1" < config/config.ini; }
cfgs() { cwini "$1" "$2" < config/config.ini; }
# Lækket token (5.38, U1): markøren står i logs/, som ingen udrulning rører (LIFECYCLE §8) — én linje, sha256 af det trimmede
# token. Ny fil + mv -T: følger aldrig et symlink og er aldrig halvskrevet. tokhash og tokmark er ens i server.sh, deploy.sh og ci.sh.
tokhash() { tr -d ' \r\n' < config/github.token | sha256sum | cut -d' ' -f1; }
tokmark() { local h t; h=$(tokhash 2>/dev/null) && [ ${#h} = 64 ] && t=$(umask 077; mktemp logs/.token-laekket.XXXXXX 2>/dev/null) || return 1; printf '%s\n' "$h" > "$t" && mv -fT "$t" logs/.token-laekket 2>/dev/null || { rm -f "$t"; return 1; }; }
# coworker.ini læses fra den commit, der kører nu, så nye værdier først virker, når de selv er godkendt og udrullet.
# Første gang (ingen deploy.current): origin/main hentes frisk til repo og php (kun til at finde målet), og når målet
# er fundet, læses ALT igen fra målets egen commit (5.34, Storix): STABLE tager aldrig php/migrate fra uudgivet main.
ini()  { cwini "$1" "$2" <<< "$INI"; }
load_ini() {   # $1 = commit
  INI=$(git -C "$REPO_DIR" show "$1:coworker.ini" 2>/dev/null || true)
  REPO=$(ini deploy repo); PHP=$(ini deploy php); MIGRATE=$(ini deploy migrate); HEALTH_URL=$(ini health "${MODE:-x}")
  CHMOD=$(ini deploy chmod)   # valgfri, fx "Du=rwx,Dg=rx,Do=,Fu=rw,Fg=r,Fo=" — tom = serverens rettigheder røres ikke
}
load_ini "$(cat logs/deploy.current 2>/dev/null || echo origin/main)"
# Filer, der afgør hvad CI, deploy og overvågning tolererer — samme liste som CTRL_FILES i report.sh
CTRL=(.github/workflows coworker.ini shared/report.sh shared/framework.lock shared/install homedir/cron/deploy.sh
      homedir/cron/ci.sh homedir/cron/monitor.sh
      homedir/cron/deploy-keep.txt homedir/cron/backup.sh launched/STABLE/DEPLOYMENT.md tests/known-failures.txt
      homedir/tests/known-failures.txt ':(glob)**/phpunit.xml*' ':(glob)**/phpstan*.neon*' .gitleaks.toml
      .gitleaksignore .semgrepignore .gitignore .gitattributes)

exec 9> logs/deploy.run.lock; flock -n 9 || exit 0     # én kørsel ad gangen (eget filnavn: en gammel cron-flock på deploy.lock låser os ikke ude)
ALERT=$(cfg alert_email)
if [ -f config/github.token ]; then
  TOKEN=$(tr -d ' \r\n' < config/github.token)
  tp=$(stat -c %a config/github.token 2>/dev/null || true)
  if (( 8#${tp:-0} & 077 )); then   # andre har kunnet læse det: markøren FØR chmod, ellers fjerner chmod beviset (server.sh melder ✘, til det er roteret)
    if tokmark; then chmod 600 config/github.token; echo "$(date -Is) ADVARSEL: config/github.token (chmod $tp) har været læsbart for andre — meldt lækket i logs/.token-laekket og sat til chmod 600. Roter tokenet på GitHub (SERVERKONTRAKT §8)"
    else echo "$(date -Is) ADVARSEL: config/github.token (chmod $tp) er læsbart for andre, og logs/.token-laekket kunne ikke skrives — filen er ladt åben"; fi
  elif [ "$tp" != 600 ]; then chmod 600 config/github.token; echo "$(date -Is) ADVARSEL: config/github.token var ikke chmod 600 — rettet"; fi
else
  TOKEN=$(cfg github_token)
  [ -n "$TOKEN" ] && echo "$(date -Is) ADVARSEL: tokenet ligger i config.ini (kan læses af webserverens gruppe) — flyt det til config/github.token (chmod 600)"
fi
alert() {   # log + hændelseslog (/health → monitor opretter en sag) + mail — samme besked højst én gang pr. døgn
  local k; k="logs/alert.$(printf %s "$*" | md5sum | cut -c1-16)"
  echo "$(date -Is) $MODE ALARM: $*"
  [ -n "$(find "$k" -mmin -1440 2>/dev/null)" ] && return 0
  touch "$k"; echo "$(date -Is)|$MODE|$*" >> logs/incidents.log
  [ -n "$ALERT" ] && printf 'Subject: [%s] deploy %s fejlede\n\n%s\n' "$REPO" "$MODE" "$*" | /usr/sbin/sendmail "$ALERT" || true
}
since() {   # alarm først når tilstanden $1 har stået på i 60 min
  local f="logs/deploy.$1.since"; [ -s "$f" ] || date +%s > "$f"
  [ $(( $(date +%s) - $(cat "$f") )) -lt 3600 ] || alert "$2"
}
[[ "$MODE" =~ ^(dev|test|stable)$ ]] || { echo "brug: deploy.sh dev|test|stable [retry]"; exit 2; }
RETRY=""; [ "${2:-}" = retry ] && RETRY=1   # forbigående fejl: prøv den fejlede commit én gang til (RUNBOOK)
# Tokenet gives via miljø (git) og stdin (curl) — aldrig som argument, så det ikke ses i ps.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader
export GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$TOKEN" | base64 -w0)"
if [ ! -s logs/deploy.current ]; then   # første kørsel: en gammel klon må ikke afgøre repo/php (5.34, Storix)
  git -C "$REPO_DIR" fetch -q --no-tags origin main 2>/dev/null || true
  load_ini origin/main
  "$PHP" -v >/dev/null 2>&1 || PHP=$(command -v php || echo "$PHP")   # kun til at finde målet; målets egen php gælder bagefter
fi
{ [ -n "$REPO" ] && [ -n "$HEALTH_URL" ] && [ -n "$PHP" ] && [ -n "$MIGRATE" ]; } || { alert "coworker.ini mangler [deploy] repo/php/migrate eller [health] $MODE"; exit 1; }
if [ -e logs/deploying ]; then   # vi har låsen, så filen er efterladt af en afbrudt kørsel (kill -9, genstart)
  alert "logs/deploying hang fra en afbrudt kørsel — workers har holdt pause. Fjernet, deploy prøves igen."
  rm -f logs/deploying
fi
# 5.36: login-filen til dumpet kan være efterladt af en dræbt kørsel (vi har låsen). Et dump fra en FEJLET udrulning
# ligger til fejlen er undersøgt, dog højst 7 døgn — det er en fuld kopi af databasen.
rm -f logs/.predeploy.cnf
find logs -maxdepth 1 -name 'predeploy-db.sql.gz' -mtime +6 -delete 2>/dev/null || true
# Et miljø med rigtige data eller skrivning i rigtige eksterne systemer ER produktion. Skal stå eksplicit.
case "$(cfg real_data)" in
  true)  [ "$MODE" = stable ] || { alert "real_data = true, men MODE=$MODE. Rigtige data følger kun udgivne releases (stable)."; exit 1; } ;;
  false) ;;
  *)     alert "config.ini skal have real_data = true eller false (står: '$(cfg real_data)')"; exit 1 ;;
esac
G() { git -C "$REPO_DIR" "$@"; }
API() { printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$REPO/$1"; }

latest() {   # $1=releases-JSON $2=1 også kontrol-releases: seneste UDGIVNE efter published_at (tom = ingen)
  # Kontrol = pre-release ELLER tag ctrl-* (5.28): et kontroludkast udgivet uden pre-release-valget er aldrig et mål for STABLE
  $PHP -r '$p=$argv[1]==="1"; $r=array_filter(json_decode(stream_get_contents(STDIN),true),fn($x)=>!$x["draft"]&&($p||(!$x["prerelease"]&&strncmp($x["tag_name"],"ctrl-",5)!==0))&&$x["published_at"]); usort($r,fn($a,$b)=>strcmp($b["published_at"],$a["published_at"])); echo $r ? $r[0]["tag_name"] : "";' "$2" <<< "$1"
}
approved() {   # commit for seneste udgivne release ELLER pre-release ("Kontrol: …") — ejerens godkendelse
  local j t; j=$(API 'releases?per_page=30') || return 1; t=$(latest "$j" 1) || return 1
  [ -n "$t" ] || return 0
  G fetch -q --no-tags --force origin "refs/tags/$t:refs/tags/$t" || return 1
  G rev-parse "refs/tags/$t^{commit}"
}
# STABLE overvåger overvågningen: har monitor.sh ikke sat sit livstegn ("coworker/monitor" på den commit, STABLE kører)
# i 13 timer, får ejeren en mail (tjekket højst hver time). Overgang: en kørsel af den gamle monitor.yml tæller også.
if [ "$MODE" = stable ] && [ -z "$(find logs/monitor.checked -mmin -60 2>/dev/null)" ] && [ -s logs/deploy.current ]; then
  touch logs/monitor.checked
  mt=$(API "commits/$(cat logs/deploy.current)/statuses?per_page=100" | $PHP -r 'foreach (json_decode(stream_get_contents(STDIN),true) ?: [] as $x) if (str_starts_with($x["context"]??"","coworker/monitor")) { echo $x["updated_at"]; exit; }' || true)
  [ -n "$mt" ] || mt=$(API 'actions/workflows/monitor.yml/runs?per_page=1' | $PHP -r '$a=json_decode(stream_get_contents(STDIN),true); echo $a["workflow_runs"][0]["updated_at"] ?? "";' || true)
  if [[ "$mt" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T ]] && [ $(( $(date +%s) - $(date -d "$mt" +%s) )) -lt 46800 ]; then :
  else alert "Overvågningen (cron/monitor.sh) har ikke meldt sig i 13 timer — ingen ser STABLE. Kører cron-linjen på DEV-serveren?"; fi
fi
target() {   # kaldes i if → set -e virker ikke her, derfor || return 1 på hvert trin
  local j t
  case "$MODE" in
    dev)    # DEV følger main — medmindre Team Lead har låst DEV til en tidligere version (tagget dev, report.sh devpin)
            G fetch -q --no-tags origin main || return 1
            if G ls-remote --exit-code -q origin refs/tags/dev >/dev/null 2>&1; then
              G fetch -q --no-tags --force origin 'refs/tags/dev:refs/tags/dev' || return 1
              G rev-parse 'refs/tags/dev^{commit}'
            else G ls-remote -q origin >/dev/null || return 1; G rev-parse origin/main; fi ;;
    test)   G ls-remote --exit-code -q origin refs/tags/test >/dev/null 2>&1 || { G ls-remote -q origin >/dev/null || return 1; return 0; }
            G fetch -q --no-tags --force origin main 'refs/tags/test:refs/tags/test' || return 1
            G rev-parse 'refs/tags/test^{commit}' ;;
    stable) # Seneste UDGIVNE release efter published_at (en rollback-release vinder) — samme regel som report.sh
            j=$(API 'releases?per_page=30') || return 1
            t=$(latest "$j" 0) || return 1
            [ -n "$t" ] || return 0
            G fetch -q --no-tags --force origin main "refs/tags/$t:refs/tags/$t" || return 1
            G rev-parse "refs/tags/$t^{commit}" ;;
  esac
}
green() {   # 0 = grøn, 1 = ikke grøn, 2 = GitHub svarer ikke. Seneste "coworker/ci"-status på commit'en (cron/ci.sh).
  local j s                 # Overgang: findes ingen status, tæller en grøn push-kørsel af den gamle ci.yml på main.
  j=$(API "commits/$1/statuses?per_page=100") || return 2
  s=$($PHP -r '$a=json_decode(stream_get_contents(STDIN),true); if (!is_array($a)) { echo "ERR"; exit; }
       foreach ($a as $x) if (($x["context"]??"")==="coworker/ci") { echo $x["state"]; exit; } echo "none";' <<< "$j") || return 2
  case "$s" in
    success) return 0 ;;
    ERR) return 2 ;;
    none) j=$(API "actions/workflows/ci.yml/runs?head_sha=$1&event=push&branch=main&status=success&per_page=1") || return 1
          [[ "$j" =~ \"total_count\":\ *([0-9]+) ]] && [ "${BASH_REMATCH[1]}" -gt 0 ] ;;
    *) return 1 ;;
  esac
}
if ! NEW=$(target); then since github "GitHub svarer ikke i 60+ min (udløbet github_token?) — $MODE står stille."; exit 0; fi
rm -f logs/deploy.github.since
[ -n "$NEW" ] || exit 0
[[ "$NEW" =~ ^[0-9a-f]{40}$ ]] || { alert "ugyldig commit fra GitHub/git: '${NEW:0:60}'"; exit 1; }
OLD=$(cat logs/deploy.current 2>/dev/null || echo none)
[ "$NEW" = "$OLD" ] && exit 0
if [ "$OLD" = none ]; then   # første udrulning: målets egen coworker.ini gælder (ikke main's)
  load_ini "$NEW"
  { [ -n "$REPO" ] && [ -n "$HEALTH_URL" ] && [ -n "$PHP" ] && [ -n "$MIGRATE" ]; } || { alert "coworker.ini i $NEW mangler [deploy] repo/php/migrate eller [health] $MODE"; exit 1; }
fi
if [ "$NEW" = "$(cat logs/deploy.failed 2>/dev/null)" ]; then   # fejlet før — prøv ikke igen, medmindre ejeren beder om det
  [ -n "$RETRY" ] || exit 0
  echo "$(date -Is) $MODE prøver $NEW igen (retry)"; rm -f logs/deploy.failed
fi
rc=0; green "$NEW" || rc=$?
[ $rc = 2 ] && { since github "GitHub svarer ikke i 60+ min — $MODE står stille."; exit 0; }
[ $rc = 1 ] && { echo "$(date -Is) $MODE $NEW ikke grøn endnu"; since "ci-${NEW:0:12}" "$NEW har ikke grøn CI efter 60 min — $MODE står stille. Rød, eller kører cron/ci.sh ikke på DEV-serveren?"; exit 0; }
if ! G merge-base --is-ancestor "$NEW" origin/main; then
  echo "$NEW" > logs/deploy.failed; alert "$NEW ($MODE) er ikke på main — afvist."; exit 1
fi

# Kontrolændringer kræver ejerens Publish: NEW's kontrolfiler skal være dem, der kører nu, eller dem
# i seneste udgivne release/pre-release. Ellers venter serveren (og ejeren får én mail).
# DEV må altid gå tilbage til kontrolfiler, der allerede har kørt på DENNE server (logs/deploy.history).
ran_here() { local h; while read -r h; do [[ "$h" =~ ^[0-9a-f]{40}$ ]] && G diff --quiet "$h" "$1" -- "${CTRL[@]}" 2>/dev/null && return 0; done < <(cat logs/deploy.history 2>/dev/null; echo "$OLD"); return 1; }
if [ "$MODE" != stable ] && [ "$OLD" != none ] && ! G diff --quiet "$OLD" "$NEW" -- "${CTRL[@]}" \
   && ! { [ "$MODE" = dev ] && ran_here "$NEW"; }; then
  if ! A=$(approved); then since github "GitHub svarer ikke i 60+ min — $MODE står stille."; exit 0; fi
  if [ -z "$A" ] || ! G diff --quiet "$A" "$NEW" -- "${CTRL[@]}"; then
    echo "$(date -Is) $MODE $NEW venter: kontrolændring uden godkendelse"
    alert "Kontrolændring i ${NEW:0:7} venter på dig: tryk Publish på udkastet 'Kontrol: …' (Team Lead laver det). Ændrede filer: $(G diff --name-only "$OLD" "$NEW" -- "${CTRL[@]}" | tr '\n' ' ')"
    exit 0
  fi
fi

# Workers holder pause, så længe denne fil findes (hvert cron-script: [ -e logs/deploying ] && exit 0)
touch logs/deploying; trap 'rm -f "$HOME_DIR/logs/deploying"' EXIT
G checkout -q -f "$NEW"
# Gulv: røres aldrig, uanset deploy-keep.txt (som kun kan TILFØJE stier). logs/ og var/ er runtime.
{ printf '%s\n' config/config.ini config/github.token config/restic.pass config/deployed_sha.php public_html/uploads/
  cat "$REPO_DIR/homedir/cron/deploy-keep.txt" 2>/dev/null || true; } > logs/deploy-keep.all
# Spejl <rod>/<mappe>/ ind i hjemmemappen — mappe for mappe, aldrig hjemmemappen selv (.ssh, .bashrc).
# -rlt: ejer, gruppe og rettigheder på serveren røres ikke. --delay-updates: skift til sidst, på én gang.
sync_from() {
  local d n
  for d in "$1"/*/; do
    n=$(basename "$d"); case "$n" in logs|var) continue ;; esac
    sed -n "s|^$n/|/|p" logs/deploy-keep.all > logs/deploy-keep.tmp || return 1
    local cf=()
    # Tilbagerulning fra logs/predeploy: det, der aldrig blev kopieret derind (hemmeligheder), må heller ikke slettes
    [ "$1" = logs/predeploy ] && cf+=(--exclude=config.ini --exclude=restic.pass --exclude=github.token)
    if [ "$n" = cron ]; then   # overgang til 5.16: CI/overvågning lagt ind af server.sh slettes ikke af en ældre commit,
      cf+=(--filter='P /ci.sh' --filter='P /monitor.sh')   # og deploy.sh erstattes aldrig af en version uden cron/ci.sh
      [ -f "$d/deploy.sh" ] && ! grep -q 'coworker/ci' "$d/deploy.sh" && cf+=(--exclude=/deploy.sh)
    fi
    rsync -rlt --executability ${CHMOD:+--chmod="$CHMOD"} --checksum --delete-after --delay-updates "${cf[@]}" \
          --exclude-from=logs/deploy-keep.tmp "$d" "$HOME_DIR/$n/" || return 1
    if [ "$n" = cron ]; then   # cron kører dem direkte: et repo uden x-bit (Windows) eller [deploy] chmod må ikke stoppe dem (5.21)
      for f in deploy ci monitor backup; do if [ -f "$HOME_DIR/cron/$f.sh" ]; then chmod u+x "$HOME_DIR/cron/$f.sh" || return 1; fi; done
    fi
  done
}
sync_sha() { G checkout -q -f "$1" && sync_from "$REPO_DIR/homedir" && ( umask 027; printf '<?php return %s;\n' "'$1'" > config/deployed_sha.php ); }
rollback() { if [ "$OLD" = none ]; then [ -d logs/predeploy ] && sync_from logs/predeploy; else sync_sha "$OLD"; fi; }
fail() { local r; if rollback; then r="rullet tilbage til ${OLD/#none/indholdet før første udrulning}"; else r="TILBAGERULNING FEJLEDE — serveren kan være halvt opdateret"; fi
         echo "$NEW" > logs/deploy.failed; alert "$1 på $NEW — $r. Migrationer er IKKE rullet tilbage."; exit 1; }
if [ "$OLD" = none ] && [ ! -d logs/predeploy ]; then   # første udrulning: gem det, der står der nu
  mkdir -p logs/predeploy
  for d in "$REPO_DIR"/homedir/*/; do n=$(basename "$d")
    case "$n" in logs|var) continue ;; esac
    [ -d "$n" ] || continue   # hemmeligheder (gulvet + deploy-keep.txt) kopieres aldrig til logs/ — de røres heller ikke ved tilbagerulning
    sed -n "s|^$n/|/|p" logs/deploy-keep.all > logs/deploy-keep.tmp
    rsync -a --exclude=config.ini --exclude=restic.pass --exclude=github.token \
          --exclude-from=logs/deploy-keep.tmp "$n/" "logs/predeploy/$n/"
  done
fi
# 1) Migrationer FØR koden skiftes. Kun expand er tilladt (LIFECYCLE.md §6) — gammel kode kører på nyt skema.
# 0) Nye migrationer: databasen gemmes FØR migration. Maskinens site-backup er natlig (5.27), så en migration kl. 14
#    har ellers kun nattens kopi. Har projektet stadig sin egen backup.sh (+ [backup] i config.ini), bruges den.
#    Ellers: et dump som projektets egen bruger i logs/predeploy-db.sql.gz (chmod 600; slettes efter vellykket udrulning, 5.36).
if [ "$OLD" != none ] && [ -n "$(G diff --name-only --diff-filter=A "$OLD" "$NEW" -- homedir/migrations/ | grep -v '/down/' || true)" ]; then
  if [ -x cron/backup.sh ] && grep -q '^\[backup\]' config/config.ini; then
    cron/backup.sh backup || { echo "$NEW" > logs/deploy.failed; alert "backup før migration fejlede på $NEW — intet ændret."; exit 1; }
  elif [ -z "$(cfgs database name)" ]; then
    # 5.36 (econsync): uden [database] name og uden backup.sh kørte migrationen før UDEN dump og uden besked.
    echo "$NEW" > logs/deploy.failed
    alert "nye migrationer på ${NEW:0:7}, men intet dump er muligt: config.ini mangler [database] name (og der er ingen cron/backup.sh med [backup]) — intet ændret. Tilføj [database] name/user/pass/host i config/config.ini og kør cron/deploy.sh $MODE retry (RUNBOOK)."; exit 1
  else
    pc="$HOME_DIR/logs/.predeploy.cnf"; prc=0
    # Kodeordet citeres og escapes (5.36): #, \, " eller " ;" i koden afkortede det før. Filen slettes også, hvis
    # deploy stoppes midt i dumpet (trap nedenfor) — og ved næste start, hvis den blev dræbt (kill -9).
    trap 'rm -f "$HOME_DIR/logs/deploying" "$HOME_DIR/logs/.predeploy.cnf"' EXIT
    ( umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$(cnfq "$(cfgs database user)")" "$(cnfq "$(cfgs database pass)")" "$(cnfq "$(cfgs database host)")" > "$pc" )
    pdb=$(cfgs database name)
    DUMP=$(command -v mariadb-dump || command -v mysqldump || echo mariadb-dump)   # ældre MariaDB/MySQL: kun mysqldump (5.34)
    ( umask 077; : > logs/predeploy-db.err )   # fejlteksten kan nævne databasen: kun ejeren læser (5.34)
    ( umask 077; set -o pipefail; "$DUMP" --defaults-extra-file="$pc" --single-transaction --routines --triggers "$pdb" \
        | gzip > logs/predeploy-db.sql.gz.tmp ) 2>> logs/predeploy-db.err || prc=1
    if [ $prc = 1 ]; then
      # 5.33 (Storix): et ugyldigt view fra en ældre migration (fejl 1356) må ikke blokere den migration, der retter
      # det. Data ligger i tabellerne; views genskabes af migrationerne. Andet forsøg: alle tabeller, ingen views.
      pv=$(mariadb --defaults-extra-file="$pc" -N -e "SELECT table_name FROM information_schema.views WHERE table_schema=DATABASE()" "$pdb" 2>/dev/null || true)
      if [ -n "$pv" ]; then
        pign=(); for v in $pv; do pign+=("--ignore-table=$pdb.$v"); done
        if ( umask 077; set -o pipefail; "$DUMP" --defaults-extra-file="$pc" --single-transaction --routines --triggers "${pign[@]}" "$pdb" \
               | gzip > logs/predeploy-db.sql.gz.tmp ) 2>> logs/predeploy-db.err; then
          prc=0; alert "dump før migration UDEN views ($(echo $pv)) på ${NEW:0:7} — et view kan ikke læses; migrationen køres. Fuldt dump fejlede: $(tr '\n' ' ' < logs/predeploy-db.err | tail -c 200)"
        fi
      fi
    fi
    rm -f "$pc"
    if [ $prc = 0 ]; then mv -f logs/predeploy-db.sql.gz.tmp logs/predeploy-db.sql.gz
    else rm -f logs/predeploy-db.sql.gz.tmp; echo "$NEW" > logs/deploy.failed; alert "dump af databasen før migration fejlede på $NEW — intet ændret: $(tr '\n' ' ' < logs/predeploy-db.err | tail -c 200)"; exit 1; fi
  fi
fi
if ! bash -c "$MIGRATE"; then
  echo "$NEW" > logs/deploy.failed
  alert "migration fejlede på $NEW — koden er IKKE skiftet. Tjek databasen (launched/DRIFT.md)."; exit 1
fi
# 2) Skift koden, 3) bevis at den nye kode kører OG kan læse og skrive i databasen
sync_sha "$NEW" || fail "filskift fejlede"
BODY=$(curl -fsS -m 20 "$HEALTH_URL" || true)
{ grep -q "$NEW" <<< "$BODY" && grep -q '"db_read": *"ok"' <<< "$BODY" && grep -q '"db_write": *"ok"' <<< "$BODY"; } \
  || fail "health fejlede (svar: ${BODY:0:300})"
echo "$NEW" > logs/deploy.current
echo "$NEW" >> logs/deploy.history
# 5.36 (econsync): dumpet før migration er en fuld kopi af databasen (på STABLE: kundedata). Det skal kun bruges, hvis
# DENNE udrulning fejler — efter en vellykket udrulning slettes det (maskinens natlige backup har databasen).
rm -rf logs/deploy.failed logs/predeploy logs/deploy.*.since logs/predeploy-db.sql.gz
echo "$(date -Is) $MODE DEPLOY OK $NEW"
