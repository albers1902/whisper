#!/bin/bash
# FILE_VERSION: 5.42 — FRAMEWORKETS FIL (låst af shared/framework.lock). Installeres UÆNDRET som
# homedir/cron/monitor.sh med `bash shared/report.sh install`. Erstatter .github/workflows/monitor.yml.
#
# Tjekker hvert miljø i coworker.ini [health]: /health svarer, status ok, databasen kan skrives, dagens
# hændelser; STABLE = seneste udgivne release, backup ≤ 1 døgn og restore-test ≤ 35 dage (medmindre
# [backup] external). Problem = mail til alert_email (højst én pr. døgn pr. besked) + GitHub-sag med label
# "incident". Hver kørsel sætter commit-status "coworker/monitor" på STABLE's commit — STABLE's deploy.sh
# mailer, hvis den er over 13 timer gammel (de to vogter hinanden).
#
# SKAL køre på en anden maskine end STABLE (ellers ser ingen, at maskinen er nede). Typisk DEV's hjemmemappe.
# Står DEV og STABLE på samme maskine: kør den (også) fra en anden server med en klon af repoet + config.
# Cron (sættes af server.sh):   17 * * * * /home/<p>/cron/monitor.sh >> /home/<p>/logs/monitor.log 2>&1
set -euo pipefail
export PATH="${PATH:+$PATH:}/usr/local/bin:/usr/bin:/bin"   # cron giver kun /usr/bin:/bin (composer m.fl. ligger i /usr/local/bin)
HOME_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$HOME_DIR"; mkdir -p logs
REPO_DIR="$HOME_DIR/repo"
exec 9> logs/monitor.run.lock; flock -n 9 || exit 0
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
INI=$(git -C "$REPO_DIR" show "$(cat logs/deploy.current 2>/dev/null || echo origin/main):coworker.ini" 2>/dev/null || true)
ini()  { cwini "$1" "$2" <<< "$INI"; }
REPO=$(ini deploy repo); PHP=$(ini deploy php); ALERT=$(cfg alert_email); EXT=$(ini backup external)
[ -n "$REPO" ] && [ -n "$PHP" ] || { echo "$(date -Is) monitor: coworker.ini mangler [deploy] repo/php"; exit 1; }
if [ -f config/github.token ]; then TOKEN=$(tr -d ' \r\n' < config/github.token); else TOKEN=$(cfg github_token); fi
API()  { printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$REPO/$1"; }
APIW() { printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - -X POST -H 'Content-Type: application/json' --data-binary "$2" "https://api.github.com/repos/$REPO/$1" > /dev/null; }
J()    { "$PHP" -r "$1" "${@:2}"; }
field() { J '$a=json_decode($argv[1],true); $v=is_array($a)?($a[$argv[2]]??""):""; echo is_scalar($v)?$v:"";' "$1" "$2"; }
days() { local d=$1; [[ "$d" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2} ]] || { echo 99999; return; }; echo $(( ( $(date +%s) - $(date -d "${d:0:10}" +%s) ) / 86400 )); }

problems=""; ssha=""
for env in stable test dev; do
  url=$(ini health "$env"); [[ -z "$url" || "$url" == *'<'* ]] && continue
  body=$(curl -fsS -m 20 "$url" 2>/dev/null || true)
  if [ -z "$body" ]; then problems+="${env^^}: /health svarer ikke ($url)"$'\n'; continue; fi
  st=$(field "$body" status); [ "$st" = ok ] || problems+="${env^^}: status='${st:-mangler}'"$'\n'
  [ "$(field "$body" db_write)" = ok ] || problems+="${env^^}: databasen kan ikke skrives"$'\n'
  inc=$(field "$body" last_incident)
  [ -n "$inc" ] && [ "$(days "$inc")" -le 0 ] && problems+="${env^^}: hændelse i dag: $inc"$'\n'
  if [ "$env" = stable ]; then
    ssha=$(field "$body" sha)
    rel=$(API 'releases?per_page=30' | J '$a=json_decode(stream_get_contents(STDIN),true) ?: []; $a=array_filter($a,fn($x)=>!$x["draft"]&&!$x["prerelease"]&&strncmp($x["tag_name"],"ctrl-",5)!==0&&$x["published_at"]);
          usort($a,fn($x,$y)=>strcmp($y["published_at"],$x["published_at"])); echo $a ? $a[0]["tag_name"] : "";' || true)
    if [ -n "$rel" ]; then
      rsha=$(API "commits/$rel" | J '$a=json_decode(stream_get_contents(STDIN),true); echo $a["sha"] ?? "";' || true)
      [ -n "$rsha" ] && [ "$ssha" != "$rsha" ] && problems+="STABLE kører ${ssha:0:7}, men seneste release er $rel (${rsha:0:7})"$'\n'
    fi
    if [ -z "$EXT" ]; then
      d=$(field "$body" backup_last_ok);  [ "$(days "$d")" -le 1 ]  || problems+="STABLE: ingen backup det sidste døgn (${d:-mangler}) — sitet er ikke med i serverens backup (site-backup)"$'\n'
      d=$(field "$body" restore_last_ok); [ "$(days "$d")" -le 35 ] || problems+="STABLE: ingen restore-test de sidste 35 dage (${d:-mangler})"$'\n'
    fi
  fi
done

# Livstegn til STABLE's vagt (deploy.sh stable): status på den commit, STABLE kører. Konteksten skifter hver måned
# (coworker/monitor-ÅÅÅÅMM), fordi GitHub højst tager 1000 statusser pr. commit og kontekst.
if [[ "$ssha" =~ ^[0-9a-f]{40}$ ]]; then
  APIW "statuses/$ssha" "$(J 'echo json_encode(["state"=>$argv[1],"context"=>"coworker/monitor-".date("Ym"),"description"=>"monitor ".date("c")], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);' "$([ -z "$problems" ] && echo success || echo failure)")" \
    || echo "$(date -Is) monitor: kunne ikke skrive livstegn — har tokenet Commit statuses: write?"
fi
if [ -z "$problems" ]; then echo "$(date -Is) monitor: alt OK"; exit 0; fi
printf '%s' "$problems" | sed "s/^/$(date -Is) monitor: /"
first=$(head -1 <<< "$problems" | cut -c1-80)
k="logs/alert.$(printf %s "$first" | md5sum | cut -c1-16)"
if [ -z "$(find "$k" -mmin -1440 2>/dev/null)" ]; then
  touch "$k"
  [ -n "$ALERT" ] && printf 'Subject: [%s] Hændelse: %s\n\n%s\n' "$REPO" "$first" "$problems" | /usr/sbin/sendmail "$ALERT" || true
  open=$(API 'issues?labels=incident&state=open&per_page=50' | J '$a=json_decode(stream_get_contents(STDIN),true) ?: []; foreach ($a as $i) echo $i["title"], "\n";' || true)
  if ! grep -qF -- "$first" <<< "$open"; then
    APIW issues "$(J 'echo json_encode(["title"=>$argv[1],"labels"=>["incident"],"body"=>$argv[2]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);' "Hændelse $(date +%F): $first" \
      "$(printf 'Registreret af monitor.sh %s\n\n%s\nLuk sagen, når årsagen er rettet. S1–S2 eller kundedata berørt: incidents/I###.md (report.sh nextid I). Rollback eller fejl i STABLE: final-decisions/H###.md.' "$(date -Is)" "$problems")")" \
      || echo "$(date -Is) monitor: kunne ikke oprette sag — har tokenet Issues: write?"
  fi
fi
exit 1
