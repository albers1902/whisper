#!/bin/bash
# FILE_VERSION: 5.42 — FRAMEWORKETS FIL (låst). Installeres UÆNDRET med `bash shared/report.sh install`
# som homedir/cron/backup.sh. CI afviser enhver afvigelse.
# Brug:  backup.sh backup         (dagligt)   — krypteret backup af database + config + uploads væk fra serveren
#        backup.sh restore-test   (månedligt) — gendanner seneste backup i en prøvedatabase og MÅLER tiden
# Beviset skrives til logs/backup_last_ok og logs/restore_last_ok, som /health viser (backup_last_ok,
# restore_last_ok, restore_rto_min). Monitor, launch-tjek og CI læser derfra — aldrig fra markdown.
#
# Kræver på serveren (én gang, Team Lead guider): restic + rclone (apt install restic rclone), en rclone-
# destination uden for serveren, og i config/config.ini (samme fil som appen bruger):
#   [database]                                            ; appens database — det, der tages backup af
#   host = localhost · name = <db> · user = <bruger med SELECT, LOCK TABLES, SHOW VIEW, TRIGGER> · pass = <kode>
#   [backup]
#   repository    = rclone:<remote>:<projekt>-backup     ; fx Google Drive via rclone
#   password_file = /home/<projekt>/config/restic.pass   ; chmod 600. Ligger i config/ og dermed i sit eget repository (AABNE-HULLER A15)
#   restore_db    = <projekt>_restoretest                ; tom database kun til prøve-restore
#   restore_user  = <bruger med CREATE/DROP på restore_db>
#   restore_pass  = <kode>
# Cron (i projektbrugerens crontab):
#   15 3 * * * /home/<p>/cron/backup.sh backup >> /home/<p>/logs/backup.log 2>&1
#   45 4 1 * * /home/<p>/cron/backup.sh restore-test >> /home/<p>/logs/backup.log 2>&1
# Restore-testen beviser: seneste snapshot er under 26 timer gammelt, ALLE tabeller er gendannet, hver
# tabel med rækker i drift har rækker i gendannelsen, i alt mindst 90 % af driftens rækker, og
# config.ini kan gendannes fra fil-backuppen.
set -euo pipefail
export PATH="${PATH:+$PATH:}/usr/local/bin:/usr/bin:/bin"   # cron giver kun /usr/bin:/bin (composer m.fl. ligger i /usr/local/bin)
HOME_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$HOME_DIR"; mkdir -p logs
exec 9> logs/backup.run.lock; flock -n 9 || exit 0   # eget navn: en gammel cron-linje med flock på backup.lock låser os ikke ude
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
ini()  { cwini "$1" "$2" < config/config.ini; }
ALERT=$(cwini "" alert_email < config/config.ini)
alert() {
  echo "$(date -Is) BACKUP ALARM: $*"
  echo "$(date -Is)|backup|$*" >> logs/incidents.log
  [ -n "$ALERT" ] && printf 'Subject: backup fejlede (%s)\n\n%s\n' "$HOME_DIR" "$*" | /usr/sbin/sendmail "$ALERT" || true
}
export RESTIC_REPOSITORY; RESTIC_REPOSITORY=$(ini backup repository)
export RESTIC_PASSWORD_FILE; RESTIC_PASSWORD_FILE=$(ini backup password_file)
[ -n "$RESTIC_REPOSITORY" ] && [ -f "$RESTIC_PASSWORD_FILE" ] || { alert "[backup] mangler i config.ini"; exit 1; }
# Database-login uden kode på kommandolinjen: midlertidig fil i logs/ (aldrig /tmp), slettes igen
CNF="$HOME_DIR/logs/.backup.cnf"; CNF2="$HOME_DIR/logs/.backup-live.cnf"; trap 'rm -rf "$CNF" "$CNF2" "$HOME_DIR/logs/restoretest"' EXIT
mkcnf() { umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$(cnfq "$1")" "$(cnfq "$2")" "$(cnfq "$(ini database host)")" > "${3:-$CNF}"; }
rows() {   # $1=cnf $2=db -> "tabel<TAB>antal" for alle tabeller (præcist COUNT(*))
  local t
  for t in $(mariadb --defaults-extra-file="$1" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$2' AND table_type='BASE TABLE' ORDER BY 1"); do
    printf '%s\t%s\n' "$t" "$(mariadb --defaults-extra-file="$1" -N -e "SELECT COUNT(*) FROM \`$2\`.\`$t\`")"
  done
}

case "${1:-}" in
  backup)
    mkcnf "$(ini database user)" "$(ini database pass)"
    restic snapshots >/dev/null 2>&1 || restic init >/dev/null
    bdb=$(ini database name)
    DUMP=$(command -v mariadb-dump || command -v mysqldump || echo mariadb-dump)   # ældre MariaDB/MySQL: kun mysqldump (5.34)
    if ! "$DUMP" --defaults-extra-file="$CNF" --single-transaction --routines --triggers "$bdb" \
         | restic backup --stdin --stdin-filename db.sql --tag db --quiet; then
      # 5.33 (Storix): et ugyldigt view (fejl 1356) må ikke stoppe backuppen før den migration, der retter det.
      # Andet forsøg: alle tabeller, ingen views (data ligger i tabellerne; views genskabes af migrationerne).
      bv=$(mariadb --defaults-extra-file="$CNF" -N -e "SELECT table_name FROM information_schema.views WHERE table_schema=DATABASE()" "$bdb" 2>/dev/null || true)
      bign=(); for v in $bv; do bign+=("--ignore-table=$bdb.$v"); done
      if [ -n "$bv" ] && "$DUMP" --defaults-extra-file="$CNF" --single-transaction --routines --triggers "${bign[@]}" "$bdb" \
           | restic backup --stdin --stdin-filename db.sql --tag db --quiet; then
        alert "database-dump UDEN views ($(echo $bv)) — et view kan ikke læses; tabellerne er sikret"
      else alert "database-dump fejlede"; exit 1; fi
    fi
    keep=(config)
    [ -d public_html/uploads ] && keep+=(public_html/uploads)
    restic backup "${keep[@]/#/$HOME_DIR/}" --tag files --quiet || { alert "fil-backup fejlede"; exit 1; }
    restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune --quiet || alert "oprydning i backup fejlede"
    date +%F > logs/backup_last_ok
    echo "$(date -Is) BACKUP OK" ;;
  restore-test)
    mkcnf "$(ini backup restore_user)" "$(ini backup restore_pass)"
    mkcnf "$(ini database user)" "$(ini database pass)" "$CNF2"
    db=$(ini backup restore_db); start=$(date +%s)
    last=$(restic snapshots --tag db --json latest | grep -oE '"time":"[^"]+' | sed -n 1p | cut -d'"' -f4)
    [ -n "$last" ] && [ $(( $(date +%s) - $(date -d "$last" +%s) )) -lt 93600 ] || { alert "restore-test: seneste database-backup er ældre end 26 timer ($last)"; exit 1; }
    mariadb --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`"
    if ! restic dump latest db.sql --tag db | mariadb --defaults-extra-file="$CNF" "$db"; then alert "restore-test fejlede (gendannelse)"; exit 1; fi
    live=$(rows "$CNF2" "$(ini database name)"); got=$(rows "$CNF" "$db")
    mariadb --defaults-extra-file="$CNF" -e "DROP DATABASE \`$db\`"
    bad=$(awk -F'\t' 'NR==FNR {g[$1]=$2; next} { lt+=$2; gt+=g[$1]; if (!($1 in g)) print "mangler tabel " $1; else if ($2 > 0 && g[$1] == 0) print "tom tabel " $1 }
                     END { if (lt > 0 && gt < lt * 0.9) print "kun " gt " af " lt " rækker" }' <(printf '%s\n' "$got") <(printf '%s\n' "$live"))
    [ -z "$bad" ] || { alert "restore-test fejlede: $(tr '\n' ';' <<< "$bad")"; exit 1; }
    n=$(grep -c . <<< "$got")
    restic restore latest --tag files --target logs/restoretest --include "$HOME_DIR/config/config.ini" --quiet \
      && [ -s "logs/restoretest$HOME_DIR/config/config.ini" ] || { alert "restore-test fejlede: config.ini kan ikke gendannes fra fil-backuppen"; exit 1; }
    printf '%s %s\n' "$(date +%F)" $(( ( $(date +%s) - start + 59 ) / 60 )) > logs/restore_last_ok
    echo "$(date -Is) RESTORE-TEST OK: $n tabeller på $(( $(date +%s) - start )) s" ;;
  *) echo "brug: backup.sh backup|restore-test"; exit 2 ;;
esac
