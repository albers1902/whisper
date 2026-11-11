#!/bin/bash
# FILE_VERSION: 5.42 — FRAMEWORKETS FIL. Serveropsætning — køres af EJEREN én gang pr. hjemmemappe (dev, test,
# stable), som projektets bruger, i hjemmemappen (/home/<projekt>[-dev|-test]). Installeres ikke.
#   check <dev|test|stable|monitor>   viser hvad der mangler (✘) og advarsler (!) — ændrer intet, med to undtagelser: dev sætter
#                             én harmløs status coworker/setup på main for at bevise, at tokenet kan skrive; og et token med
#                             gruppe/andre-bits får markøren logs/.token-laekket (ingen chmod; logs/ laves kun, hvis markøren kræver det)
#   setup <dev|test|stable|monitor>   check + lægger deploy.sh (dev: også ci.sh, monitor.sh; monitor: KUN monitor.sh —
#                             ekstra vagt fra en anden server, når DEV og STABLE står på samme maskine) i cron/ fra origin/main,
#                             henter CI-værktøjerne og tilføjer manglende cron-linjer (fjerner aldrig nogen); lukker config/
#                             for andre og lukker + melder et token, andre har kunnet læse (logs/.token-laekket)
#   init <dev|test|stable|monitor>    (5.38, U1) nyt miljø: config/ (700), config.ini (600) fra sitectl's private/db-*.txt,
#                             config/github.token (600) og ~/repo — kun det, der mangler — og derefter setup. Et lækket
#                             token erstattes kun af et andet (rotation); forkert repo stopper før setup
# Kør den nyeste fra main med indgangslinjen i OPSAETNING.md ("Serveropsætning"). Den henter scriptet fra GitHub-API'et
# og giver det tokenet i miljøet (CW_TOKEN) og repoet (CW_REPO) — aldrig på en kommandolinje (SERVERKONTRAKT §8).
set -uo pipefail
export PATH="${PATH:+$PATH:}/usr/local/bin:/usr/bin:/bin"   # samme PATH som cron-scripterne
ACT=${1:-}; MODE=${2:-}
TOK=${CW_TOKEN:-}; unset CW_TOKEN   # fra indgangslinjen: kun i denne variabel — ingen underproces (git, sudo, ci.sh) arver det
[[ "$ACT" =~ ^(check|setup|init)$ && "$MODE" =~ ^(dev|test|stable|monitor)$ ]] || { echo "brug: server.sh check|setup|init dev|test|stable|monitor"; exit 2; }
H=$(pwd); bad=0; hbad=0
ok()   { echo "✔ $*"; }
no()   { echo "✘ $*"; bad=1; }
warn() { echo "! $*"; }
[ "$(id -u)" != 0 ] || [ -n "${CW_SELFTEST:-}" ] || { echo "✘ Kør som projektets bruger, ikke root (log ind med SSH som projektets bruger, eller som root: su -P -l <projekt> — su uden -P giver TIOCSTI-risiko)"; exit 1; }
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
tokleak() { local h; [ -f logs/.token-laekket ] && [ ! -L logs/.token-laekket ] && h=$(tokhash 2>/dev/null) && [ -n "$h" ] && [ "$(cat logs/.token-laekket 2>/dev/null)" = "$h" ]; }
# --- init (5.38, U1): et nyt miljø uden håndarbejde. Validerer ALT først og skriver bagefter — en afbrudt init efterlader
# ingen halv config.ini. Findes en fil, røres den ikke. Hemmeligheder kun i variabler, i filer skrevet under umask 077
# (600 fra første byte) og til git/curl via miljø og stdin — aldrig i argv, output eller log (SERVERKONTRAKT §8).
if [ "$ACT" = init ]; then
  REPO_IN=${CW_REPO:-}
  me=$(id -un); hh=$(getent passwd "$me" | cut -d: -f6)
  echo "== Nyt miljø: $MODE i $H"
  [ -n "$hh" ] && [ "$(pwd -P)" = "$(cd "$hh" 2>/dev/null && pwd -P)" ] || { echo "✘ Kør i projektets hjemmemappe ($hh) som projektets bruger — intet er skrevet"; exit 1; }
  [ "$MODE" = monitor ] || [ -d private ] || { echo "✘ private/ mangler — sitet er ikke lavet med sitectl new (SERVERKONTRAKT §3a trin 1) — intet er skrevet"; exit 1; }
  ierr=0; ino() { echo "✘ $*"; ierr=1; }
  if [ -d config ]; then cm=$(stat -c %a config)
    if (( 8#$cm & 022 )); then ino "config/ (chmod $cm) kan skrives af andre — init ændrer ikke en mappe, der findes: chmod 700 config"
    else ok "config/ findes (chmod $cm) — init ændrer den ikke (setup lukker den for andre)"; fi
  elif [ -e config ]; then ino "config findes, men er ikke en mappe"; fi
  # Tokenet (§8): et eksisterende token overskrives aldrig — undtagen et lækket (gruppe/andre-bits, eller markøren
  # logs/.token-laekket = tokenets sha256), som kun kan erstattes af et ANDET token (go-v2 2–4: rotationsvejen)
  TOK=${TOK//[$' \t\r\n']/}; ROT=""
  if [ ! -e config/github.token ]; then
    if [ -z "$TOK" ]; then if [ -t 0 ]; then read -rsp 'GitHub-token: ' TOK; echo; else IFS= read -r TOK || true; fi; TOK=${TOK//[$' \t\r\n']/}; fi
    if [ -z "$TOK" ]; then ino "tokenet mangler — kør linjen fra Team Lead (OPSAETNING.md, Serveropsætning) og indtast det"
    elif ! [[ "$TOK" =~ ^[A-Za-z0-9_-]+$ ]]; then ino "tokenet har tegn, et GitHub-token ikke har — indtast det igen (intet er skrevet)"; fi
  else
    ft=$(tr -d ' \r\n' < config/github.token 2>/dev/null); tmk=""; tlk=""
    tokleak && tmk=1
    (( 8#$(stat -c %a config/github.token) & 077 )) && tlk=1; [ -n "$tmk" ] && tlk=1
    if [ -z "$TOK" ] || [ "$TOK" = "$ft" ]; then   # Enter i linjen = filens token
      [ -n "$tmk" ] && ino "config/github.token er meldt lækket, og det er det lækkede token, linjen har fået — roter tokenet på GitHub (SERVERKONTRAKT §8) og indtast det nye"
    elif ! [[ "$TOK" =~ ^[A-Za-z0-9_-]+$ ]]; then ino "tokenet har tegn, et GitHub-token ikke har — indtast det igen (intet er skrevet)"
    elif [ -n "$tlk" ]; then ROT=1
    else ino "tokenet er ikke det i config/github.token — init overskriver ikke et token, der ikke er meldt lækket (tryk Enter i linjen for at bruge filens)"; fi
    unset ft
  fi
  # Repoet: kun hvis ~/repo mangler; navnet efterprøves mod coworker.ini på main (nedenfor) — ingen del må være . eller ..
  if [ ! -d repo/.git ]; then
    if [ -e repo ]; then ino "repo/ findes, men er ikke en git-klon — flyt den væk, og kør igen"
    elif ! [[ "$REPO_IN" =~ ^[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*$ ]]; then ino "repoet (<ejer>/<repo>) mangler i linjen eller er ugyldigt — brug linjen fra Team Lead"; fi
  fi
  # config.ini: kun hvis den mangler. Værdierne står allerede på maskinen (sitectl db skriver private/db-<base>.txt)
  ini_new=""
  if [ ! -e config/config.ini ]; then
    ae=${CW_ALERT_EMAIL:-alarms@albers.dk}
    [[ "$ae" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$ ]] || ino "CW_ALERT_EMAIL er ikke en mailadresse: $ae"
    dbv() { awk -v k="$2" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2); sub(/\r$/, "", v); print v; exit }' "$1"; }
    # Én blok [<sektion>] fra én sitectl-fil. host = localhost, ikke filens 127.0.0.1: sitectl giver kun
    # '<bruger>'@'localhost' rettigheder, og maskinens MariaDB kører med skip-name-resolve — over TCP matcher kontoen ikke.
    dbblk() { local f=$2 n u p; n=$(dbv "$f" dbname); u=$(dbv "$f" user); p=$(dbv "$f" pass)
      [[ "$n" =~ ^[A-Za-z0-9_]+$ && "$u" =~ ^[A-Za-z0-9_]+$ ]] || { ino "$f: dbname= og user= mangler eller har andre tegn end A–Z, 0–9 og _"; return; }
      [ -n "$p" ] || { ino "$f: pass= mangler"; return; }
      case "$p" in *'"'*|*'\'*|*'$'*) ino "$f: kodeordet indeholder \", \\ eller \$ — frameworket kan ikke læse det sikkert. Intet er skrevet; bed sysadmin-siden om et nyt (sitectl)"; return ;; esac
      ini_new+=$'\n'"[$1]"$'\nhost = localhost\nport = 3306\n'"name = $n"$'\n'"user = $u"$'\n'"pass = \"$p\""$'\n'; }
    if [ "$MODE" != monitor ]; then
      app=(); for f in private/db-"$me"_*.txt; do [ -f "$f" ] || continue; [[ "${f#private/db-"$me"_}" =~ ^ci[0-9]*\.txt$ ]] || app+=("$f"); done
      if [ ${#app[@]} = 1 ]; then dbblk database "${app[0]}"
      elif [ ${#app[@]} = 0 ]; then ino "appens database mangler: ingen private/db-${me}_<navn>.txt — sitectl db <domæne> <navn> (SERVERKONTRAKT §3a trin 2)"
      else ino "flere end én database i private/ (${app[*]}) — init gætter ikke: skriv config/config.ini selv, eller spørg Team Lead"; fi
      if [ "$MODE" = dev ]; then
        if [ -f "private/db-${me}_ci.txt" ]; then dbblk ci_database "private/db-${me}_ci.txt"
        else ino "testdatabasen mangler: private/db-${me}_ci.txt — sitectl db <DEV-domæne> ci (SERVERKONTRAKT §3a trin 3)"; fi
      fi
    fi
    rdv=false
    if [ "$MODE" = stable ] && [ $ierr = 0 ]; then rdv=""   # aldrig et gæt: rigtige kundedata følger kun udgivne releases
      for i in 1 2 3; do printf 'STABLE: rigtige kundedata (real_data)? Skriv true eller false: '
        IFS= read -r a || { echo; break; }; [ -t 0 ] || echo; a=${a//[$' \t\r']/}; case "$a" in true|false) rdv=$a; break ;; esac; done
      [ -n "$rdv" ] || ino "real_data blev ikke besvaret med true eller false — intet er skrevet"
    fi
    hdr="; Skrevet af server.sh init $(date +%F)$([ "$MODE" = monitor ] || echo ' ud fra private/db-*.txt (sitectl)'). Init overskriver den aldrig."$'\n'
    if [ "$MODE" = monitor ]; then ini_new="${hdr}alert_email = $ae"$'\n'
    else ini_new="${hdr}alert_email = $ae"$'\n'"real_data = $rdv"$'\n'"; host er localhost, ikke 127.0.0.1: rettighederne er givet til 'localhost' (sitectl), og MariaDB slår ikke 127.0.0.1 op"$'\n'"$ini_new"; fi
  fi
  [ $ierr = 0 ] || { echo; echo "SERVER: IKKE KLAR ($MODE) — intet er skrevet; ret linjerne med ✘ og kør igen"; exit 1; }
  # Skrivning — først nu
  if [ ! -d config ]; then mkdir -m 700 config && ok "config/ oprettet (chmod 700)" || { echo "✘ config/ kunne ikke oprettes"; exit 1; }; fi
  if [ -n "$ini_new" ]; then
    if ( umask 077; set -C; printf '%s' "$ini_new" > config/config.ini ) 2>/dev/null; then ok "config/config.ini skrevet (chmod 600) — kodeordene er ikke vist"
    else echo "✘ config/config.ini kunne ikke skrives"; exit 1; fi
  else ok "config/config.ini findes — røres ikke"; fi
  unset ini_new
  if [ ! -e config/github.token ]; then
    if ( umask 077; set -C; printf '%s\n' "$TOK" > config/github.token ) 2>/dev/null; then ok "config/github.token skrevet (chmod 600)"
    else echo "✘ config/github.token kunne ikke skrives"; exit 1; fi
  elif [ -n "$ROT" ]; then   # atomisk: ny 600-fil i config/, mv over det lækkede — aldrig et øjeblik uden token eller åben
    tn=$(umask 077; mktemp config/.github.token.XXXXXX 2>/dev/null) && ( umask 077; printf '%s\n' "$TOK" > "$tn" ) && mv -f "$tn" config/github.token \
      || { rm -f "${tn:-}"; echo "✘ config/github.token kunne ikke udskiftes"; exit 1; }
    rm -f logs/.token-laekket 2>/dev/null   # kan den ikke fjernes, passer den ikke længere til filen, og setup melder resten
    ok "config/github.token: token udskiftet — det lækkede er erstattet (chmod 600)"
    unset tn
  fi
  rfandt=""
  if [ -d repo/.git ]; then ok "repo/ findes — røres ikke"; rfandt=1
  else
    gt=$(tr -d ' \r\n' < config/github.token)
    if GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$gt" | base64 -w0)" \
         git clone -q -- "https://github.com/$REPO_IN.git" repo 2>/dev/null; then ok "repo/ klonet fra $REPO_IN"
    else echo "✘ git clone af $REPO_IN fejler (tokenet: Contents: Read? rigtigt repo?)"; echo; echo "SERVER: IKKE KLAR ($MODE) — ret linjen med ✘ og kør igen"; exit 1; fi
    unset gt
  fi
  # Forkert repo = stop FØR setup: ellers lagde setup cron-scripts fra en fremmed main, der kører hvert 2. minut (security
  # MEDIUM, go-v2 1). Også når repo/ fandtes i forvejen — en anden kørsel må ikke springe tjekket over.
  if [ -n "$REPO_IN" ]; then
    # En klon, der fandtes, kan være fra før coworker.ini kom på main (QA F2): hent først, ellers dømmes et rigtigt repo forkert
    if [ -n "$rfandt" ]; then gt=$(tr -d ' \r\n' < config/github.token)
      if ! GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$gt" | base64 -w0)" \
           git -C repo fetch -q origin main 2>/dev/null; then
        echo "✘ git fetch i repo/ fejler (tokenet: Contents: Read? netværk?) — repoet kan ikke efterprøves mod $REPO_IN"
        echo; echo "SERVER: IKKE KLAR ($MODE) — intet er lagt i cron/"; exit 1; fi
      unset gt
    fi
    rr=$(git -C repo show origin/main:coworker.ini 2>/dev/null | cwini deploy repo)
    if [ -z "$rr" ]; then   # intet at sammenligne med — det siger intet om, at repoet er forkert
      echo "✘ main har ingen coworker.ini endnu (eller ingen [deploy] repo i den) — $REPO_IN kan ikke efterprøves. Vent, til framework-kopien er merget på main (spørg Team Lead), og kør samme linje igen"
      echo; echo "SERVER: IKKE KLAR ($MODE) — intet er lagt i cron/"; exit 1; fi
    if [ "${rr,,}" != "${REPO_IN,,}" ]; then rr=${rr//[^A-Za-z0-9_.\/-]/?}   # fremmed tekst: ingen styretegn i terminalen
      echo "✘ repo/ er ikke $REPO_IN fra linjen (coworker.ini på main: [deploy] repo = '$rr') — flyt repo/ væk (fx mv repo repo.forkert; slet den aldrig), ret linjen (spørg Team Lead), og kør igen"
      echo; echo "SERVER: IKKE KLAR ($MODE) — intet er lagt i cron/"; exit 1; fi
  fi
  unset TOK
  echo "== Fortsætter som setup $MODE"; ACT=setup
fi
[ -f config/config.ini ] && [ -d repo/.git ] || { echo "✘ Kør i projektets hjemmemappe (der skal være config/config.ini og repo/)"; exit 1; }

echo "== Server-tjek: $MODE i $H"
# Konfiguration og token
[ -n "$(cfg alert_email)" ] && ok "alert_email" || no "config/config.ini mangler alert_email = <din mail>"
rd=$(cfg real_data)
case "$MODE:$rd" in monitor:*) ;; stable:true|stable:false|dev:false|test:false) ok "real_data = $rd" ;; *:true) no "real_data = true må kun stå i STABLE" ;; *) no "config/config.ini mangler real_data = true|false" ;; esac
TOKEN=""
if [ -f config/github.token ]; then TOKEN=$(tr -d ' \r\n' < config/github.token)
  # AK6 (5.38, U1): gruppe/andre har haft adgang = tokenet er lækket (SERVERKONTRAKT §8; FinanceMCP: 664). Markøren
  # logs/.token-laekket skrives FØR chmod (også af check, deploy.sh og ci.sh), så ✘ står i HVER kørsel, til tokenet er
  # udskiftet — en chmod gør ikke et token, andre har kunnet læse, hemmeligt igen. Fejler markøren: ingen chmod, beviset består.
  tp=$(stat -c %a config/github.token); LK=logs/.token-laekket
  if (( 8#$tp & 077 )); then
    [ -d logs ] || mkdir -m 700 logs 2>/dev/null   # check må oprette logs/ her og kun her: markøren kræver den
    if tokmark; then tfix="; meldt lækket ($LK)"
      if [ "$ACT" = setup ]; then chmod 600 config/github.token && tfix="; filen er nu chmod 600 og meldt lækket ($LK)" || tfix="; meldt lækket ($LK), men chmod 600 fejlede"; fi
    else tfix="; $LK kunne ikke skrives, så filen er ikke ændret — ret logs/ (rettigheder, diskplads), og kør igen"; fi
    no "config/github.token (chmod $tp) har været læsbart for andre — roter tokenet på GitHub (SERVERKONTRAKT §8) og kør linjen igen med det nye$tfix"
  elif [ -L "$LK" ] || { [ -e "$LK" ] && [ ! -f "$LK" ]; }; then
    no "$LK er ikke en almindelig fil — flyt den væk, og kør igen (tokenets status kan ikke afgøres)"
  elif tokleak; then
    no "config/github.token er meldt lækket — roter tokenet på GitHub (SERVERKONTRAKT §8) og kør linjen igen med det nye"
  else
    [ -f "$LK" ] && [ "$ACT" = setup ] && rm -f "$LK"   # tokenet er udskiftet siden markøren blev skrevet
    if [ "$tp" = 600 ]; then ok "config/github.token (chmod 600)"
    else [ "$ACT" = setup ] && chmod 600 config/github.token && ok "config/github.token sat til chmod 600 (var $tp, kun ejeren)" || no "config/github.token skal være chmod 600 (er $tp)"; fi
  fi
else no "config/github.token mangler (fine-grained token, kun dette repo)"; fi
# config/ lukkes for andre på ALLE miljøer (5.38, go-v2 5; før kun dev): en 644-fil derinde er ellers læsbar for alle
cm=$(stat -c %a config)
if (( 8#$cm & 007 )); then
  if [ "$ACT" = setup ] && chmod o-rwx config; then ok "config/ lukket for andre (var chmod $cm)"
  else no "config/ (chmod $cm) kan læses af andre — kør linjen (setup lukker den)"; fi
else ok "config/ er lukket for andre (chmod $cm)"; fi
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader
export GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$TOKEN" | base64 -w0)"
git -C repo fetch -q origin main 2>/dev/null && ok "git fetch virker" || no "git fetch fejler (token: Contents: Read?)"
INI=$(git -C repo show origin/main:coworker.ini 2>/dev/null || true)
ini()  { cwini "$1" "$2" <<< "$INI"; }
REPO=$(ini deploy repo); PHP=$(ini deploy php)
[ -n "$REPO" ] && ok "coworker.ini: repo = $REPO" || no "coworker.ini på main mangler [deploy] repo"
if [ -n "$PHP" ] && "$PHP" -v >/dev/null 2>&1; then ok "PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"; else no "PHP fra coworker.ini [deploy] php findes ikke: '$PHP'"; fi
API() { printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$REPO/$1"; }
if [ -n "$REPO" ] && printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$REPO" > /dev/null 2>&1; then ok "GitHub API svarer for $REPO"; else no "GitHub API afviser tokenet for $REPO"; fi
if [ -n "$REPO" ]; then   # "Automatically delete head branches": ufarligt fra 5.18 (ci.sh henter refs/pull/*/head), men vis det
  dbm=$(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - "https://api.github.com/repos/$REPO" 2>/dev/null | grep -o '"delete_branch_on_merge": *[a-z]*' | grep -o '[a-z]*$' || true)
  [ "$dbm" = true ] && warn "GitHub sletter PR-branches ved merge (Automatically delete head branches) — CI finder reviewede commits via refs/pull, men Team Lead kan ikke længere se branchen lokalt"
fi
if { [ "$ACT" = setup ] || [ "$MODE" = dev ]; } && [ -n "$REPO" ]; then   # skrive-test: en harmløs status på main (kontekst coworker/setup); DEV også ved check — CI skriver derfra (5.34)
  sha=$(git -C repo rev-parse origin/main 2>/dev/null)
  if printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsS -m 30 -K - -X POST -H 'Content-Type: application/json' \
       --data-binary '{"state":"success","context":"coworker/setup","description":"token-test fra server.sh"}' "https://api.github.com/repos/$REPO/statuses/$sha" > /dev/null 2>&1
  then ok "tokenet kan skrive commit-status"; else no "tokenet kan ikke skrive commit-status — giv det 'Commit statuses: Read and write'"; fi
fi
for t in git curl flock rsync sendmail; do command -v "$t" >/dev/null || [ -x "/usr/sbin/$t" ] && ok "$t" || no "$t mangler"; done

hu=$(ini health "$MODE")
if [ "$MODE" != monitor ]; then
  if [ -z "$hu" ] || [[ "$hu" == *'<'* ]]; then no "coworker.ini på main mangler [health] $MODE"
  else hb=$(curl -fsS -m 20 "$hu" 2>/dev/null || true)
    if [ -z "$hb" ]; then hbad=1; no "/health svarer ikke ($hu)"
    else for k in sha db_read db_write; do grep -q "\"$k\"" <<< "$hb" && ok "/health har $k" || no "/health ($hu) mangler feltet $k"; done; fi
  fi
fi

if [ "$MODE" = dev ]; then
  echo "== CI og overvågning (kører i DEV's hjemmemappe)"
  # Isolation (5.19): koden, der testes, kører som <bruger>-ci — uden adgang til DEV's config, token og andre hemmeligheder
  ME=$(id -un); CIU="$ME-ci"
  # 5.37 (sysadmin, Storix d): sudo-reglen skrives ikke i hånden — en tastefejl i /etc/sudoers.d/ lukker sudo på hele maskinen
  if ! getent passwd "$CIU" > /dev/null; then no "CI-brugeren $CIU findes ikke — laves af sysadmin-siden (SERVERKONTRAKT §3a trin 6)"
  elif ! sudo -n -u "$CIU" true 2>/dev/null; then no "$ME må ikke starte kommandoer som $CIU — sudo-reglen laves af sysadmin-siden (SERVERKONTRAKT §3a trin 6)"
  else
    ok "CI-bruger $CIU (koden, der testes, kører som den)"
    # Hemmelige mapper lukkes for "andre" på mappeniveau (5.25): så hjælper en verdenslæsbar fil derinde ikke
    # (Storix: config/deployed_sha.php og features.ini var 644 og blev skrevet sådan igen ved hver udrulning)
    if [ "$ACT" = setup ]; then chmod 600 config/config.ini 2>/dev/null; for d in config private logs ci .ssh; do [ -d "$d" ] && chmod o-rwx "$d"; done; fi
    # Kandidater findes som DEV (CI-brugeren kan ikke liste mappen, men kan gætte stier) — og prøves læst som CI-brugeren
    leak=$(find "$H" -xdev -type f \( -name '*.ini' -o -name '*.env' -o -name '*.key' -o -name '*.pem' -o -name '*.pass' \
             -o \( -name '*token*' ! -name '*.sql' ! -name '*.php' ! -name '*.md' ! -name '*.js' ! -name '*.ts' ! -name '*.html' ! -name '*.twig' \) \
             -o -path '*/private/*' -o -path '*/config/*' \) -not -path "$H/ci-tools/*" -not -path "$H/repo/*" -not -path "$H/ci/*" 2>/dev/null \
           | while IFS= read -r f; do sudo -n -u "$CIU" test -r "$f" 2>/dev/null && printf '%s ' "$f"; done | cut -c1-400)
    [ -z "$leak" ] && ok "$CIU kan ikke læse DEV's config, token eller nøgler" || no "$CIU kan læse: $leak— kør linjen med setup (lukker config/, private/, logs/ for andre)"
    # Ingen egne cron-/at-job for CI-brugeren: ellers kan kode fra en PR køre videre mellem jobs
    co=$(sudo -n -u "$CIU" env PATH=/usr/bin:/bin crontab -l 2>&1 || true)   # crontab afslutter ≠ 0 ved afvisning (pipefail)
    if grep -qiE 'not allowed|denied' <<< "$co"; then ok "$CIU må ikke have cron-job"
    else no "$CIU må have cron-job (kode fra en PR kan blive ved at køre) — som root: echo $CIU >> /etc/cron.deny"; fi
    if command -v at > /dev/null; then
      ao=$(sudo -n -u "$CIU" env PATH=/usr/bin:/bin at -l 2>&1 || true)
      if grep -qiE 'permission|denied|not allowed' <<< "$ao"; then ok "$CIU må ikke bruge at"
      else no "$CIU må bruge at — som root: echo $CIU >> /etc/at.deny"; fi
    fi
    if sudo -n -u "$CIU" test -x "$H"; then ok "$CIU kan nå værktøjerne i $H/ci-tools"
    elif [ "$ACT" = setup ] && chmod o+x "$H"; then ok "$H sat til o+x (CI-brugeren kan nå ci-tools/, ikke se indholdet)"
    else no "$CIU kan ikke nå $H/ci-tools — som $ME: chmod o+x $H"; fi
  fi
  cp_=$(readlink -f "$(command -v composer || true)" 2>/dev/null || true)
  if [ -z "$cp_" ]; then no "composer mangler (composer audit)"
  elif [ -n "${CIU:-}" ] && getent passwd "$CIU" > /dev/null && ! sudo -n -u "$CIU" test -r "$cp_" 2>/dev/null; then no "$CIU kan ikke læse composer ($cp_) — composer audit kan ikke køre"
  else ok "composer ($cp_, kan læses af CI-brugeren)"; fi
  python3 -m venv --help >/dev/null 2>&1 && ok "python3 med venv" || no "python3 med venv mangler (Semgrep) — maskinens sag: spørg sysadmin-subagenten (shared/SERVERKONTRAKT.md)"
  command -v mysql >/dev/null && ok "mysql-klient" || no "mysql-klient mangler"
  case "$(cfgs ci_database pass)" in *'"'*|*'\'*) no "config.ini [ci_database] pass må ikke indeholde \" eller \\ — vælg et andet kodeord" ;; esac
  n=$(cfgs ci_database name)
  if [[ "$n" =~ ^[A-Za-z0-9_]+_ci$ ]]; then
    umask 077; c=$(mktemp -p "$H" .cwmy.XXXX)
    printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' "$(cfgs ci_database host)" "$(cfgs ci_database port)" "$(cfgs ci_database user)" "$(cfgs ci_database pass)" > "$c"
    if mysql --defaults-extra-file="$c" -e "CREATE DATABASE IF NOT EXISTS \`$n\`; DROP DATABASE \`$n\`; CREATE DATABASE \`$n\`" 2>/dev/null; then ok "testdatabase $n: kan oprettes og slettes"
      # CI-brugerens databaserettigheder (5.25): kun testdatabasen — ikke *.* og ikke DEV's egen database
      g=$(mysql --defaults-extra-file="$c" -N -e "SHOW GRANTS" 2>/dev/null || true); dn=$(cfgs database name)
      gbad=$(grep -vE 'GRANT USAGE ON \*\.\*' <<< "$g" | grep -E 'ON \*\.\*|ON `?\*`?\.' || true)
      [ -n "$dn" ] && grep -qF "\`$dn\`" <<< "$g" && gbad+=" (har rettigheder på DEV's database $dn)"
      if [ -z "$gbad" ]; then ok "testdatabasens bruger har kun rettigheder på sin egen database"
      else no "testdatabasens bruger har for brede rettigheder: ${gbad:0:200} — kun GRANT ALL ON \`$n\`.*"; fi
    else no "testdatabasen $n: kan ikke forbinde eller oprette/slette — opret den med: sitectl db <DEV-domæne> ci (SERVERKONTRAKT §3a trin 3 — Albers kører den); [ci_database] skal svare til private/db-<bruger>_ci.txt (init skriver den, når config.ini mangler)"; fi
    rm -f "$c"
  else no "config/config.ini mangler [ci_database] (host, port, name, user, pass) — name SKAL ende på _ci (den slettes ved hver kørsel)"; fi
  # Står STABLE på samme maskine? Så ser overvågningen herfra ikke, at maskinen er nede.
  su=$(ini health stable); sh=${su#*://}; sh=${sh%%/*}; sh=${sh%%:*}
  if [ -n "$sh" ] && [[ "$su" != *'<'* ]]; then
    sip=$(getent ahosts "$sh" 2>/dev/null | awk '{print $1}' | sort -u)
    if [ -z "$sip" ]; then warn "kan ikke slå $sh op herfra — tjek selv, at STABLE står på en anden maskine"
    elif grep -qxF -f <(hostname -I 2>/dev/null | tr ' ' '\n' | grep .) <<< "$sip"; then
      warn "STABLE ($sh) står på denne maskine: går maskinen ned, mailer ingen. Kør også cron/monitor.sh fra en anden server (OPSAETNING.md)."
    else ok "STABLE ($sh) står på en anden maskine — overvågningen herfra ser den"; fi
  else warn "coworker.ini [health] stable mangler — intet at overvåge endnu"; fi
fi

if [ "$ACT" = setup ]; then
  echo "== Opsætning"
  mkdir -p cron logs
  files="deploy.sh"; [ "$MODE" = dev ] && files="deploy.sh ci.sh monitor.sh"; [ "$MODE" = monitor ] && files="monitor.sh"
  for f in $files; do   # ejerens egen handling = godkendelse; senere versioner kommer med deploy (kontrolgate)
    if git -C repo show "origin/main:shared/install/$f" > "cron/$f.new" 2>/dev/null && [ -s "cron/$f.new" ]; then
      if cmp -s "cron/$f.new" "cron/$f"; then rm -f "cron/$f.new"; chmod u+x "cron/$f"; ok "cron/$f er allerede den nyeste"
      else mv "cron/$f.new" "cron/$f"; chmod 750 "cron/$f"; ok "cron/$f lagt ind fra main"; fi
    else rm -f "cron/$f.new"; no "shared/install/$f findes ikke på main — er framework-kopien (hotfix/H###-framework-…) merget?"; fi
  done
  if [ "$MODE" = dev ] && [ -x cron/ci.sh ]; then
    bash cron/ci.sh tools > logs/ci-tools.log 2>&1 && ok "CI-værktøjer hentet (gitleaks, Semgrep, PHPStan)" || no "CI-værktøjer kunne ikke hentes — se logs/ci-tools.log"
  fi
  cur=$(crontab -l 2>/dev/null || true); add=""
  want=("cron/deploy.sh|*/2 * * * * $H/cron/deploy.sh $MODE >> $H/logs/deploy.log 2>&1")
  [ "$MODE" = monitor ] && want=("cron/monitor.sh|47 * * * * $H/cron/monitor.sh >> $H/logs/monitor.log 2>&1")
  [ "$MODE" = dev ] && want+=("cron/ci.sh|* * * * * $H/cron/ci.sh >> $H/logs/ci.log 2>&1" "cron/monitor.sh|17 * * * * $H/cron/monitor.sh >> $H/logs/monitor.log 2>&1")
  for w in "${want[@]}"; do
    k=${w%%|*}; l=${w#*|}
    if grep -F "$H/$k" <<< "$cur" | grep -qv '^[[:space:]]*#'; then ok "cron: $k findes"; else add+="$l"$'\n'; fi
  done
  if [ -n "$add" ]; then
    { printf '%s\n' "$cur" | sed '/^$/d'; printf '%s' "$add"; } | crontab - && printf '%s' "$add" | sed 's/^/✔ cron tilføjet: /' || no "crontab kunne ikke skrives"
  fi
fi
# Backup (5.27): maskinens site-backup tager alle sites og skriver beviset i projektets logs/. Projektet sætter intet op —
# det ser kun efter, at det er med. Advarsel, ikke ✘: et nyt miljø er først med efter nattens kørsel.
if [ "$MODE" != monitor ]; then
  bk=$(tr -d '\r' 2>/dev/null < logs/backup_last_ok | awk 'NR==1{print $1}'); rs=$(tr -d '\r' 2>/dev/null < logs/restore_last_ok | awk 'NR==1{print $1}')
  ba=$(( ( $(date +%s) - $(date -d "${bk:-1970-01-01}" +%s 2>/dev/null || echo 0) ) / 86400 ))
  if [ -z "$bk" ]; then warn "ikke set i serverens backup endnu (logs/backup_last_ok mangler) — maskinens site-backup kører om natten. Står den her i morgen: spørg sysadmin-subagenten. Ret intet på serveren selv"
  elif [ "$ba" -gt 1 ]; then warn "serverens backup har ikke taget projektet siden $bk — spørg sysadmin-subagenten. Ret intet på serveren selv"
  else ok "med i serverens backup (backup $bk, restore-prøve ${rs:-ikke endnu})"; fi
  [ -x cron/backup.sh ] && warn "cron/backup.sh (projektets egen backup fra før 5.27) kører ved siden af serverens — dobbelt, men harmløst"
fi
# Ejerskab (5.26): efter en root-handling ejes filer af root, og PHP/cron som projektets bruger kan ikke læse dem
# 5.34 (Storix): rekursivt og også de kodemapper, deploy.sh skriver i (homedir/<mappe>/ i repoet) — en root-ejet fil
# dybt i src/ får rsync til at fejle midt i en udrulning. public_html/uploads røres ikke af deploy og tælles ikke.
odirs=(config cron logs); for d in repo/homedir/*/; do n=$(basename "$d"); case "$n" in logs|var|config|cron) continue ;; esac; [ -d "$n" ] && odirs+=("$n"); done
own=$(find "${odirs[@]}" -path public_html/uploads -prune -o ! -user "$(id -un)" -print 2>/dev/null | head -5 | tr '\n' ' ')
if [ -z "$own" ]; then ok "${odirs[*]} ejes af $(id -un) (rekursivt)"; else no "ejes ikke af $(id -un): $own— spørg sysadmin-subagenten om ejerskab (${odirs[*]})"; fi
for f in deploy ci monitor backup; do   # cron kører scripts direkte: uden x-bit (fx fra et Windows-repo) kører intet (5.21)
  [ -f "cron/$f.sh" ] || continue
  [ "$ACT" = setup ] && [ ! -x "cron/$f.sh" ] && chmod u+x "cron/$f.sh"
  if [ -x "cron/$f.sh" ]; then ok "cron/$f.sh kan køres"; else no "cron/$f.sh mangler x-bit (cron kan ikke køre den) — kør linjen med setup"; fi
done
echo
if [ $bad = 0 ]; then echo "SERVER: KLAR ($MODE)"; else echo "SERVER: IKKE KLAR ($MODE) — ret linjerne med ✘ og kør igen"; fi
[ $hbad = 1 ] && echo "  /health svarer først efter første deploy fra cron (cron/deploy.sh hvert 2. minut, se logs/deploy.log) — vent, og kør samme linje igen (SERVERKONTRAKT §3a trin 8)"
exit $bad
