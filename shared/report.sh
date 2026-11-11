#!/usr/bin/env bash
# FILE_VERSION: 5.42
# shared/report.sh — Coworker Framework. Svar og kontroller genereres HER, fra git og
# GitHub — aldrig fra en agents hukommelse (LIFECYCLE.md §11).
#
#   bash shared/report.sh status               hvad kører hvor, flag, næste ledige numre
#   bash shared/report.sh month 2026-10        hvad blev releaset i måneden (også 2026-07..2026-10)
#   bash shared/report.sh releases             alle udgivne releases (version, dato, SHA, migrationer, rollback-mål)
#   bash shared/report.sh id F005              alt om ét ID: sporet spec → review → fragment → test → release
#   bash shared/report.sh roadmap              Nu/Næste/Senere med plan og målt status + backlog uden plan
#   bash shared/report.sh launchplan           køreplanen: planlagte releases til STABLE, deres features og hvad der mangler
#   bash shared/report.sh fixes 2026-07..2026-10   alle hotfixes/fejlrettelser i perioden (+ incidents)
#   bash shared/report.sh fordeling 2026-07..2026-10   antal ID'er pr. måned pr. type (feature/fejl/hotfix/gæld/sikkerhed)
#   bash shared/report.sh forbrug [2026-10]    aktiv tid og tokens pr. ID, agent og type (Claude Codes logs på denne pc)
#   bash shared/report.sh spild [2026-10]      hvor tiden og tokens går: kommandoer, lange sessioner, omarbejde, genlæsning
#   bash shared/report.sh restore <tag>        præcis plan for at vende tilbage til en tidligere release (kode + DB)
#   bash shared/report.sh check                dokumentations-tjek (tomt output = OK)
#   bash shared/report.sh notes <tag> <sha>    skriv launched/releases/<tag>.md (én gang)
#   bash shared/report.sh nextid F|H|I         næste ledige ID (I = incident)
#   bash shared/report.sh phpstan [path]       PHPStan lokalt med CI's sha256-låste phar (path: hent og vis kun stien, 5.36)
#   bash shared/report.sh secrets              scan af staged + nye filer for hemmeligheder (før commit)
#   bash shared/report.sh ci                   framework-kontroller (CI: PR og push til main)
#   bash shared/report.sh monitor              overvågning manuelt (serveren kører cron/monitor.sh hver time)
#   bash shared/report.sh version              frameworkets version i dette projekt
#   bash shared/report.sh claude               .claude/ (guard, agenter) = shared/claude.lock? (Trin 0 tjek 4)
#   bash shared/report.sh precheck             før merge, i engineerens worktree: framework-kontroller + lint + tests lokalt
#   bash shared/report.sh kode [branch]        er kode-commit'en testet på DEV (coworker/kode)? Før critics starter (5.35)
#   bash shared/report.sh install              installér frameworkets CI/overvågning/deploy uændret (backup er serverens, 5.27)
#   bash shared/report.sh ctrl <sha>           udkast til ejerens godkendelse af kontrolændringer
#   bash shared/report.sh devlog [n]           DEV-versioner (main-commits) — hvad kører, og hvad kan DEV gå tilbage til
#   bash shared/report.sh devpin <sha>|før <ID>|off   lås DEV til en tidligere version / følg main igen
#
# Kræver: git, bash, gh (ikke til "ci"). Ingen jq — gh har sin egen.
set -euo pipefail
export MSYS2_ARG_CONV_EXCL='*'   # Git Bash på Windows: omskriv aldrig "origin/main:.github/…" til en Windows-sti (ingen effekt på Linux)
if [ "${1:-}" = lock ] || [ "${1:-}" = lint ]; then :; else cd "$(git rev-parse --show-toplevel)"; fi

EMPTY=$(git hash-object -w -t tree /dev/null 2>/dev/null || echo 4b825dc642cb6eb9a060e54bf8d69288fbd5e904)   # gits tomme træ = "før første release"
FLAGPATHS="homedir/config/features.ini config/features.ini launched/DEV/homedir/config/features.ini"
ROADMAP=ROADMAP.md
DECISIONS=launched/STABLE/DECISIONS.md
DRIFT=launched/DRIFT.md
GH=${GH:-gh}
declare -A FC=()    # cache: "rev|nøgle" -> flag-linjer
declare -A IC=()    # cache: rev -> ID'er i historikken

die() { echo "FEJL: $*" >&2; exit 1; }
gh_repo() { git remote get-url origin 2>/dev/null | sed -nE 's#.*github\.com[:/]([^/]+/[^/]+)$#\1#p' | sed 's/\.git$//'; }
no_access() {   # $1=hvad fejlede — 403/404 fra GitHub er ikke en kodefejl (5.23)
  echo "INGEN GITHUB-ADGANG til ${R_:-$(gh_repo)} ($1). Ikke en kodefejl."
  echo "  Mennesket: læg projektets egen nøgle i .claude/settings.local.json med linjen i OPSAETNING §4, og start en ny session."
  echo "  Agenter skifter aldrig gh-login (guarden blokerer det): stop og send mennesket denne besked."
}
cmd_access() {
  local repo push hl; repo=$(gh_repo)
  [ -n "${GH_TOKEN:-}" ] || { R_=$repo no_access "projektet har ingen egen nøgle: GH_TOKEN er ikke sat"; return 1; }
  hl=$(git config --get-all credential.https://github.com.helper 2>/dev/null | tail -1 || true)
  [[ "$hl" == *"gh auth git-credential"* ]] || { R_=$repo no_access "git bruger ikke projektets nøgle (credential-helper '${hl:-ingen}')"; return 1; }
  git ls-remote -q origin HEAD >/dev/null 2>&1 || { R_=$repo no_access "git ls-remote origin fejler (403/404)"; return 1; }
  # Skriveadgang prøves af git selv (receive-pack): --dry-run sender og opretter intet. gh api .permissions viser
  # KONTOENS rolle (admin), ikke den fine-grained nøgles rettigheder (Storix 2026-10-15: OK, men push gav 403).
  git push -q --dry-run origin "HEAD:refs/heads/cw-adgangsprobe" >/dev/null 2>&1 \
    || { R_=$repo no_access "git kan ikke skrive (push --dry-run afvist — nøglen mangler Contents: Read and write, eller er til et andet repo)"; return 1; }
  if [ -n "$repo" ]; then
    $GH api "repos/$repo" --jq .name >/dev/null 2>&1 || { R_=$repo no_access "gh api repos/$repo svarer ikke (403/404)"; return 1; }
  fi
  echo "ADGANG: OK (${repo:-origin} — projektets egen nøgle; git kan læse og skrive, gh svarer)"
}
nocr() { tr -d '\r'; }
gq() { grep "$@" > /dev/null; }   # som grep -q, men læser input til ende (ingen SIGPIPE under pipefail)
IDR='[FH][0-9]{3,}[a-z]?'           # ID-grammatik: F005, H012, F061a

fetch() {
  git fetch -q origin main 2>/dev/null || echo "ADVARSEL: git fetch origin main fejlede — bruger lokal kopi" >&2
  git fetch -q origin '+refs/tags/v*:refs/tags/v*' 2>/dev/null || true
}

# Udgivne releases, ældste først: published_at TAB tag TAB navn
RELEASES=""
load_releases() {
  RELEASES=$($GH api 'repos/{owner}/{repo}/releases?per_page=100' --paginate \
    --jq '.[] | select(.draft|not) | select(.prerelease|not) | select(.tag_name|startswith("ctrl-")|not) | select(.published_at != null) | [.published_at, .tag_name, .name] | @tsv' \
    | nocr | sort) || die "kunne ikke hente releases med gh"
  local pub tag name
  while IFS=$'\t' read -r pub tag name; do [ -n "$tag" ] && warm "$tag"; done <<< "$RELEASES"
  warm origin/main
}
DRAFTS=""
load_drafts() {
  DRAFTS=$($GH api 'repos/{owner}/{repo}/releases?per_page=100' --paginate --jq '.[] | select(.draft) | .tag_name' | nocr) || DRAFTS=""
  # Udgivne pre-releases (kontrolændringer) er hverken slettede eller udkast (5.26: falsk "slettet" i Storix)
  PRERELEASES=$($GH api 'repos/{owner}/{repo}/releases?per_page=100' --paginate --jq '.[] | select(.draft|not) | select(.prerelease or (.tag_name|startswith("ctrl-"))) | .tag_name' | nocr) || PRERELEASES=""
}
last_release() { printf '%s\n' "$RELEASES" | tail -1 | cut -f2; }

sha_of() { git rev-parse -q --verify "$1^{commit}" 2>/dev/null || true; }

flags_raw() {   # $1=rev $2=nøgle (stable|test|dev): "ID<TAB>on|off" per [F###_…]/[H###_…]
  local rev=$1 key=$2 p
  [ "$rev" = "$EMPTY" ] && return 0
  for p in $FLAGPATHS; do
    if git cat-file -e "$rev:$p" 2>/dev/null; then
      git show "$rev:$p" | nocr | awk -v key="$key" '
        /^[ \t]*\[/ { id=""; if (match($0, /\[[FH][0-9][0-9][0-9]+[a-z]?_/)) { id=substr($0, RSTART+1, RLENGTH-2); if (!(id in v)) { v[id]="off"; o[++n]=id } } ; next }
        id != "" && $0 ~ ("^[ \t]*" key "[ \t]*=") { s=$0; sub(/^[^=]*=[ \t]*/, "", s); sub(/[ \t;#].*$/, "", s); v[id]=s }
        END { for (i=1; i<=n; i++) print o[i] "\t" v[o[i]] }'
      return 0
    fi
  done
}
warm() {   # fyld caches for en revision i HOVED-shellen (subshells arver dem)
  local rev=$1 k
  for k in stable test dev; do [ -n "${FC[$rev|$k]+x}" ] || FC[$rev|$k]=$(flags_raw "$rev" "$k"); done
  # || true: findes revisionen ikke (slettet eller ikke hentet tag), må tildelingen ikke afslutte scriptet tavst (set -e, 5.31)
  [ -n "${IC[$rev]+x}" ] || IC[$rev]=" $(git log --format='%s' "$rev" 2>/dev/null | sed -nE "s/^($IDR):.*/\\1/p" | sort -u | tr '\n' ' ' || true)"
}
flags_at() {   # $1=rev [$2=nøgle]
  local rev=$1 key=${2:-stable}
  if [ -n "${FC[$rev|$key]+x}" ]; then [ -n "${FC[$rev|$key]}" ] && printf '%s\n' "${FC[$rev|$key]}"; return 0; fi
  flags_raw "$rev" "$key"
}
flag_of() { flags_at "$2" "${3:-stable}" | awk -F'\t' -v id="$1" '$1==id{print $2}'; }
in_history() {   # $1=ID $2=rev: har rev en commit "ID: …" i sin historik?
  if [ -n "${IC[$2]+x}" ]; then [[ "${IC[$2]} " == *" $1 "* ]]; return; fi
  git log --format='%s' "$2" | awk -v id="$1" 'index($0, id ":")==1 {f=1} END{exit f?0:1}'
}

ids_in() {   # ID'er i commit-emner i intervallet $1..$2 (EMPTY = fra begyndelsen)
  local range=$2; [ "$1" != "$EMPTY" ] && range="$1..$2"
  git log --format='%s' "$range" | sed -nE "s/^($IDR):.*/\\1/p" | sort -u
}

title_of() {   # titel fra fragmentets første linje, ellers fra første commit-emne
  local id=$1 rev=$2 t="" r
  for r in "$rev" origin/main; do
    if [ -z "$t" ] && git cat-file -e "$r:changes/$id.md" 2>/dev/null; then
      t=$(git show "$r:changes/$id.md" | nocr | sed -n 1p | sed -E 's/^#+ *//; s/^[FH][0-9]{3,}[a-z]?: *//')
    fi
  done
  [ -z "$t" ] && t=$(git log --format='%s' origin/main | grep -m1 -E "^$id:" | sed -E "s/^$id: *//" || true)
  printf '%s' "$t"
}

in_droppet() {
  [ -f "$ROADMAP" ] || return 1
  nocr < "$ROADMAP" | awk -v id="$1" '/^## /{s=$0} s ~ /^## Droppet/ && $0 ~ ("(^|[^0-9A-Za-z])" id "([^0-9A-Za-z]|$)") {f=1} END{exit f?0:1}'
}

is_rollback() {   # $1=prev $2=ny
  [ "$1" != "$EMPTY" ] && [ "$(sha_of "$1")" != "$(sha_of "$2")" ] && git merge-base --is-ancestor "$2" "$1"
}

# Analyse af én release: prev -> ny. Markdown-afsnit på stdout.
analyse() {   # $1=prev-rev (EMPTY hvis ingen) $2=ny-rev
  local prev=$1 new=$2 rollback=0 id p n m
  is_rollback "$prev" "$new" && rollback=1
  declare -A P=() N=()
  while IFS=$'\t' read -r id p; do [ -n "$id" ] && P[$id]=$p; done < <(flags_at "$prev")
  while IFS=$'\t' read -r id n; do [ -n "$id" ] && N[$id]=$n; done < <(flags_at "$new")
  local on="" off="" gone="" brud="" plain=""
  for id in $(printf '%s\n' "${!P[@]}" "${!N[@]}" | sort -u); do
    p=${P[$id]:-absent}; n=${N[$id]:-absent}
    if [ "$n" = on ] && [ "$p" != on ]; then
      [ $rollback = 1 ] && [ "$p" = absent ] || on+="$id "   # rollback der genindfører et ryddet flag: ingen hændelse
    elif [ "$p" = on ] && [ "$n" = off ]; then off+="$id "
    elif [ "$p" != absent ] && [ "$p" != on ] && [ "$n" = absent ]; then
      if in_droppet "$id"; then gone+="$id "; else brud+="$id "; fi
    fi
  done
  if [ $rollback = 0 ]; then
    for id in $(ids_in "$prev" "$new"); do
      { [ -n "${P[$id]:-}" ] || [ -n "${N[$id]:-}" ]; } && continue   # flag-ID: kun flag-reglen gælder
      if [ "$prev" != "$EMPTY" ] && in_history "$id" "$prev"; then plain+="$id(del-leverance) "; else plain+="$id "; fi
    done
  fi
  echo "Type: $([ $rollback = 1 ] && echo rollback || echo release)"
  list() { local h=$1; shift; echo "## $h"; local x any=0
    for x in "$@"; do any=1; echo "- $x: $(title_of "${x%%(*}" "$new")"; done
    [ $any = 0 ] && echo "- (ingen)"; return 0; }
  # shellcheck disable=SC2086
  list "Tændt i STABLE" $on
  # shellcheck disable=SC2086
  list "Slukket" $off
  # shellcheck disable=SC2086
  list "Med uden flag (hotfixes m.m.)" $plain
  if [ $rollback = 1 ]; then
    echo "## Trukket tilbage (rollback) — commits der ikke længere er i STABLE"
    git log --format='- %s' "$new..$prev"
  fi
  # shellcheck disable=SC2086
  [ -n "$gone" ] && list "Flag fjernet (droppet)" $gone
  # shellcheck disable=SC2086
  [ -n "$brud" ] && list "BRUD: flag fjernet uden at have været on i STABLE" $brud
  echo "## Migrationer"
  m=$(git diff --name-only --diff-filter=A "$prev" "$new" -- homedir/migrations/ 2>/dev/null | grep -v '/down/' || true)
  if [ -n "$m" ]; then printf '%s\n' "$m" | sed 's/^/- /'; else echo "- (ingen)"; fi
  echo "## Ændringsfragmenter (nyt i koden — også mørke features)"
  m=$(git diff --name-only --diff-filter=A "$prev" "$new" -- changes/ 2>/dev/null | grep -v '/README.md$' || true)
  if [ -n "$m" ]; then printf '%s\n' "$m" | sed 's/^/- /'; else echo "- (ingen)"; fi
  echo "## Kontrolændringer siden forrige release (CI, deploy, backup, .gitignore — læs før Publish)"
  m=$(git diff --name-only "$prev" "$new" 2>/dev/null | grep -E "$CONTROL_RE" || true)
  if [ -n "$m" ]; then printf '%s\n' "$m" | sed 's/^/- /'; else echo "- (ingen)"; fi
  if [ $rollback = 0 ]; then
    echo "## Til kunderne"
    local x k any=0
    for x in $on $plain; do x=${x%%(*}; k=$(fragment_field "$x" "$new" Kunde); [ -n "$k" ] && { any=1; echo "- $k"; }; done
    [ $any = 0 ] && echo "- (intet kundesynligt)"
  fi
  LAST_ON="$on"; LAST_PLAIN="$plain"; LAST_BRUD="$brud"
}

# ---------- Fælles hjælpere (v5.4) ----------
# config.ini og coworker.ini læses ENS i alle frameworkets scripts (5.36, econsync): "…" eller '…' omkring en værdi
# fjernes, og i en citeret værdi er ; og # almindelige tegn. Uciteret værdi: " ;…" til linjens ende er en kommentar.
cwini() { awk -v s="$1" -v k="$2" 'function cq(v, q,  j, r) { for (j = 2; j <= length(v); j++) if (substr(v, j, 1) == q) { r = substr(v, j + 1); if (r ~ /^[ \t]*(;.*)?$/) return j }; return 0 }
  /^[ \t]*\[/ { sec = $0; gsub(/^[ \t]+|[ \t\r]+$/, "", sec); next }
  s == "" || sec == "[" s "]" { i = index($0, "="); if (!i) next; key = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", key); if (key != k) next
    v = substr($0, i + 1); sub(/\r$/, "", v); sub(/^[ \t]+/, "", v); q = substr(v, 1, 1)
    if ((q == "\"" || q == "\047") && (e = cq(v, q))) v = substr(v, 2, e - 2); else { sub(/[ \t]+;.*$/, "", v); sub(/[ \t]+$/, "", v) }
    print v; exit }'; }
ini_at() {    # $1=rev $2=sektion $3=nøgle fra coworker.ini i en bestemt commit (tom, hvis filen/nøglen mangler)
  git show "$1:coworker.ini" 2>/dev/null | nocr | cwini "$2" "$3" || true
}
mignum() { local n; n=$(basename "$1" | grep -oE '^[0-9]+' || true); echo $(( 10#${n:-0} )); }   # 0116_v… → 116
ini_get() {   # $1=sektion $2=nøgle fra coworker.ini (projektets værdier — kontrolfil)
  [ -f coworker.ini ] || return 0
  nocr < coworker.ini | cwini "$1" "$2" || true
}
backup_ext() { ini_get backup external; }   # [backup] external = <tekst> i coworker.ini: backup ligger uden for frameworket
health_url() {   # $1=dev|test|stable -> URL fra coworker.ini [health] (ellers DEPLOYMENT.md "health_<env>: …")
  local u; u=$(ini_get health "$1"); [[ "$u" == *'<'* ]] && u=""; [ -n "$u" ] && { printf '%s\n' "$u"; return 0; }
  local f=launched/STABLE/DEPLOYMENT.md
  [ -f "$f" ] || return 0
  nocr < "$f" | sed -nE "s/^health_$1: *(https?:\/\/[^ ]+).*/\1/p" | sed -n 1p
}
# Frameworkets låste driftsfiler og hvor de installeres (uændret)
INSTALL_MAP="shared/install/ci.sh:homedir/cron/ci.sh shared/install/monitor.sh:homedir/cron/monitor.sh shared/install/deploy.sh:homedir/cron/deploy.sh shared/install/backup.sh:homedir/cron/backup.sh"
ci_state() {   # $1=commit -> seneste "coworker/ci"-status (success|failure|pending|error) fra DEV-serverens cron/ci.sh, ellers tom
  $GH api "repos/{owner}/{repo}/commits/$1/statuses?per_page=100" --jq '[.[] | select(.context=="coworker/ci")][0].state // ""' 2>/dev/null | nocr || true
}
json_field() {   # $1=json $2=felt -> værdi (streng eller tal)
  { printf '%s' "$1" | grep -oE "\"$2\" *: *(\"[^\"]*\"|[0-9.]+|true|false|null)" || true; } | sed -n 1p | sed -E 's/^[^:]*: *//; s/^"//; s/"$//'
}
days_since() { local d=$1; [[ "$d" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2} ]] || { echo 99999; return; }; echo $(( ( $(date +%s) - $(date -d "${d:0:10}" +%s) ) / 86400 )); }

FW_LOCK=shared/framework.lock
# Filer, der afgør hvad CI, deploy og overvågning tolererer. Tvinger tier 1 (CI), vises før Publish (notes),
# og når ingen server, før de er med i en udgivet release (deploy.sh har samme liste).
CTRL_FILES='(\.github/workflows/|coworker\.ini$|shared/install/|homedir/cron/(deploy|ci|monitor|backup)\.sh$|homedir/cron/deploy-keep\.txt$|shared/report\.sh$|shared/framework\.lock$|launched/STABLE/DEPLOYMENT\.md$|(homedir/)?tests/known-failures\.txt$|(homedir/)?phpunit\.xml(\.dist)?$|(homedir/)?phpstan[^/]*\.neon(\.dist)?$|\.gitleaks\.toml$|\.gitleaksignore$|\.semgrepignore$|\.gitignore$|\.gitattributes$|\.claude/)'
CONTROL_RE="^$CTRL_FILES"

fw_files() {   # frameworkets egne filer i en mappe (til låsefilen)
  local d=$1
  ( cd "$d" && {
      ls CLAUDE.md OPSAETNING.md 2>/dev/null
      find agents shared -type f 2>/dev/null | grep -vE '^shared/(framework\.lock|FRAMEWORK-REVISION-.*|SYSTEM_VERSION\.md)$'
      for x in launched launched/STABLE launched/DEV launched/TEST launched/releases changes tasks product-ai fullstack-ai qa-critic security-critic ui-ux graphic-design final-decisions; do
        if [ -f "$x/README.md" ]; then echo "$x/README.md"; fi
      done
    } | sort -u )
}
sha_file() { tr -d '\r' < "$1" | sha256sum | cut -d' ' -f1; }
sha_blob() { git show "$1:$2" 2>/dev/null | tr -d '\r' | sha256sum | cut -d' ' -f1; }

spec_of() {   # seneste spec for ID i rev
  git ls-tree -r --name-only "$2" 2>/dev/null | grep -E "^(product-ai/$1_v[0-9]+\.md|launched/$1/[^/]*spec[^/]*\.md)$" | sort -V | tail -1 || true
}
ak_missing() {   # $1=ID $2=rev -> AK'er i specen uden test, der nævner "<ID>-AKn"; "NOSPEC"/"NOAK" hvis de mangler
  local spec aks a tests
  spec=$(spec_of "$1" "$2"); [ -n "$spec" ] || { echo NOSPEC; return; }
  aks=$(git show "$2:$spec" | nocr | grep -oE '^[-*#0-9. ]*AK[0-9]+' | grep -oE 'AK[0-9]+' | sort -u || true)
  [ -n "$aks" ] || { echo NOAK; return; }
  if [ -n "${JUNIT:-}" ] && [ -s "${JUNIT:-}" ]; then   # CI: kun tests, der KØRTE og bestod
    tests=$(python3 -c 'import sys,xml.etree.ElementTree as E
for t in E.parse(sys.argv[1]).iter("testcase"):
  if not any(c.tag in ("failure","error","skipped") for c in t): print((t.get("classname") or t.get("class") or "")+"::"+(t.get("name") or ""))' "$JUNIT" | grep -oE "$1-AK[0-9]+" | sort -u || true)
  else
    tests=$(git grep -ohE "$1-AK[0-9]+" "$2" -- 'homedir/tests' 'tests' 2>/dev/null | sed -E 's/^[^:]*://' | sort -u || true)
  fi
  for a in $aks; do grep -qx "$1-$a" <<< "$tests" || printf '%s ' "$a"; done
}
fragment_field() {   # $1=ID $2=rev $3=felt -> første værdi fra changes/<ID>*.md (F001.md før F001-2.md)
  local f v       # Ingen "| head" uden for løkken: den gav SIGPIPE ved fil nr. 2 og stoppede notes under pipefail (5.17)
  for f in $(git ls-tree -r --name-only "$2" changes/ 2>/dev/null | grep -E "^changes/$1(-[0-9]+)?\.md$" | sort -V); do
    v=$(git show "$2:$f" | nocr | sed -nE "s/^\**$3:\** *(.+)/\1/p" | sed -n 1p) || true
    [ -n "$v" ] && { printf '%s\n' "$v"; return 0; }
  done
  return 0
}

prev_of() { printf '%s\n' "$RELEASES" | awk -F'\t' -v t="$1" '$2==t{print p; exit} {p=$2}'; }

# Første udgivne release, hvor ID'et blev releaset: "tag<TAB>dato" (tom hvis aldrig)
released_in() {
  local id=$1 tag pub rest prev="" f pf
  while IFS=$'\t' read -r pub tag rest; do
    [ -z "$tag" ] && continue
    f=$(flag_of "$id" "$tag"); pf=""; [ -n "$prev" ] && pf=$(flag_of "$id" "$prev")
    if [ -n "$f$pf" ]; then
      if [ "$f" = on ] && [ "$pf" != on ]; then printf '%s\t%s\n' "$tag" "${pub%%T*}"; return 0; fi
    elif in_history "$id" "$tag"; then printf '%s\t%s\n' "$tag" "${pub%%T*}"; return 0; fi
    prev=$tag
  done <<< "$RELEASES"
}

last_mig() { git ls-tree -r --name-only "$1" homedir/migrations/ 2>/dev/null | grep -v '/down/' | grep -E '/[0-9]{4}_[^/]*\.sql$' | sort | tail -1 || true; }

cmd_status() {
  fetch; load_releases
  local last; last=$(last_release)
  echo "# Status (genereret $(date +%F) fra git/GitHub)"
  echo "main:    $(git log -1 --format='%h %ad %s' --date=short origin/main)"
  if git ls-remote --exit-code -q origin refs/tags/test >/dev/null 2>&1; then
    git fetch -q origin '+refs/tags/test:refs/tags/test' 2>/dev/null || true
    echo "TEST:    tag test @ $(git log -1 --format='%h %ad %s' --date=short 'refs/tags/test^{commit}')"
  else echo "TEST:    tag test findes ikke"; fi
  local dp; dp=$(dev_pin); [ -n "$dp" ] && echo "DEV:     LÅST til ${dp:0:7} (følger ikke main — report.sh devpin off)"
  if [ -n "$last" ]; then
    echo "STABLE:  $last (udgivet $(printf '%s\n' "$RELEASES" | tail -1 | cut -f1 | cut -c1-10)) @ $(git rev-parse --short "$last^{commit}")"
  else echo "STABLE:  ingen udgivet release"; fi
  # Hvad kører faktisk: health-URL'er fra DEPLOYMENT.md (linjer "health_dev: https://…")
  local f=launched/STABLE/DEPLOYMENT.md env url body sha
  if [ -f "$f" ]; then
    while read -r env url; do
      env=${env#health_}
      body=$(curl -fsS -m 10 "$url" 2>/dev/null || true)
      sha=$(printf '%s' "$body" | grep -m1 -oE '[0-9a-f]{40}' || true)
      if [ -n "$sha" ]; then echo "Kører i ${env^^}: $(git rev-parse --short "$sha" 2>/dev/null || echo "${sha:0:7}")"
      else echo "Kører i ${env^^}: UKENDT (health svarer ikke)"; fi
    done < <(nocr < "$f" | grep -E '^health_(dev|test|stable): *https?://' | sed -E 's/: */ /')
  fi
  echo "## Flag tændt"
  echo "DEV:     $(flags_at origin/main dev | awk -F'\t' '$2=="on"{printf "%s ", $1}')"
  local tf=""; git rev-parse -q --verify refs/tags/test >/dev/null && tf=$(flags_at refs/tags/test test | awk -F'\t' '$2=="on"{printf "%s ", $1}')
  echo "TEST:    $tf"
  local sf=""; [ -n "$last" ] && sf=$(flags_at "$last" stable | awk -F'\t' '$2=="on"{printf "%s ", $1}')
  echo "STABLE:  $sf"
  echo "## Næste ledige"
  local lm n; lm=$(last_mig origin/main)
  echo "Seneste migration: ${lm:-(ingen)}"
  n=$(basename "${lm:-0000_}" | cut -c1-4 | sed 's/^0*//')
  printf 'Næste: %s · %s · migration %04d\n' "$(nextid F)" "$(nextid H)" $(( ${n:-0} + 1 ))
}

cmd_month() {
  local month=${1:-} from to
  [[ "$month" =~ ^([0-9]{4}-[0-9]{2})(\.\.([0-9]{4}-[0-9]{2}))?$ ]] || die "brug: month ÅÅÅÅ-MM eller ÅÅÅÅ-MM..ÅÅÅÅ-MM"
  from=${BASH_REMATCH[1]}; to=${BASH_REMATCH[3]:-$from}
  fetch; load_releases
  local pub tag name prev any=0
  while IFS=$'\t' read -r pub tag name; do
    [ -z "$tag" ] && continue
    [[ ! "${pub:0:7}" < "$from" && ! "${pub:0:7}" > "$to" ]] || continue
    any=1; prev=$(prev_of "$tag")
    echo "# $tag — udgivet ${pub%%T*} — $name"
    echo "Forrige: ${prev:-(ingen — første release)}"
    analyse "${prev:-$EMPTY}" "$tag"
    echo
  done <<< "$RELEASES"
  [ $any = 1 ] || echo "Ingen udgivne releases i $month."
}

cmd_releases() {
  fetch; load_releases
  [ -n "$RELEASES" ] || { echo "Ingen udgivne releases."; return 0; }
  echo "| Version | Udgivet | SHA | Type | Migration til | Tændt / med | Rollback-mål |"
  echo "|---|---|---|---|---|---|---|"
  local pub tag name prev typ on
  while IFS=$'\t' read -r pub tag name; do
    [ -z "$tag" ] && continue
    prev=$(prev_of "$tag")
    typ=release; is_rollback "${prev:-$EMPTY}" "$tag" && typ=rollback
    on=$(analyse "${prev:-$EMPTY}" "$tag" | awk '/^## (Tændt|Med uden)/{s=1;next} /^## /{s=0} s && /^- [FH]/{sub(/^- /,""); sub(/:.*/,""); printf "%s ", $0}')
    echo "| $tag | ${pub%%T*} | $(git rev-parse --short "$tag^{commit}") | $typ | $(basename "$(last_mig "$tag")" 2>/dev/null | cut -c1-4) | ${on:-—} | ${prev:-—} |"
  done <<< "$RELEASES"
}

cmd_id() {
  local id=${1:-}; [[ "$id" =~ ^$IDR$ ]] || die "brug: id F005"
  fetch; load_releases
  echo "# $id"
  echo "## Commits på main (emne starter med $id: eller nævner $id)"
  git log origin/main --format='%h%x09%ad%x09%s' --date=short \
    | awk -F'\t' -v id="$id" '$3 ~ ("^" id ":") || $3 ~ ("(^|[^0-9A-Za-z])" id "([^0-9]|$)") {print "- " $1 " " $2 " " $3}'
  echo "## Fragmenter"
  local fr; fr=$(git ls-tree -r --name-only origin/main changes/ 2>/dev/null | grep -E "^changes/$id(-[0-9]+)?\.md$" || true)
  if [ -n "$fr" ]; then printf '%s\n' "$fr" | sed 's/^/- /'; else echo "- (ingen)"; fi
  trace "$id"
  if [[ "$id" == F* ]]; then
    local mis; mis=$(ak_missing "$id" origin/main)
    case "$mis" in NOSPEC) echo "## Acceptkriterier: ingen spec på main";; NOAK) echo "## Acceptkriterier: specen har ingen AK";;
      "") echo "## Acceptkriterier: alle dækket af tests";; *) echo "## Acceptkriterier uden test: $mis";; esac
    local k; k=$(fragment_field "$id" origin/main Kunde); [ -n "$k" ] && echo "## Til kunderne: $k"
  fi
  echo "## Releases (udgivet, ældste først)"
  local pub tag name f hit any=0
  while IFS=$'\t' read -r pub tag name; do
    [ -z "$tag" ] && continue
    f=$(flag_of "$id" "$tag"); hit=nej; in_history "$id" "$tag" && hit=ja
    [ $hit = nej ] && [ -z "$f" ] && continue
    any=1; echo "- $tag (${pub%%T*}) — flag stable: ${f:-intet flag} — commits med: $hit"
  done <<< "$RELEASES"
  [ $any = 1 ] || echo "- (ingen)"
  local r last lf; r=$(released_in "$id")
  if [ -z "$r" ]; then
    if in_history "$id" origin/main; then echo "## Status: Merged (på main), ikke Released"; else echo "## Status: ikke startet"; fi
    return 0
  fi
  echo "## Status: Released i $(cut -f1 <<< "$r") ($(cut -f2 <<< "$r"))"
  last=$(last_release); lf=$(flag_of "$id" "$last")
  if [ "$lf" = off ] || { [ -z "$lf" ] && ! in_history "$id" "$last"; }; then
    echo "OBS: ikke aktiv i nuværende STABLE ($last) — slukket eller trukket tilbage"
  fi
}

# Sporet for ét ID på main: krav → impact → rapport → reviews → fragment → test (✅/❌/—)
trace() {
  local id=$1 t f v all tier
  all=$(git ls-tree -r --name-only origin/main 2>/dev/null)
  pick() { grep -E "$1" <<< "$all" | sort -V | tail -1 || true; }
  echo "## Spor (main)"
  f=$(pick "^tasks/impact-$id\.md$|^launched/$id/impact\.md$")
  tier=""; [ -n "$f" ] && tier=$(git show "origin/main:$f" | nocr | grep -oiE '^[#* -]*tier:?\**:? *[123]' | grep -oE '[123]$' | sed -n 1p || true)
  echo "- Impact:   ${f:+✅ $f (tier ${tier:-?})}${f:-❌}"
  f=$(pick "^product-ai/${id}_v[0-9]+\.md$"); echo "- Spec:     ${f:+✅ $f}${f:-$([ "$tier" = 3 ] && echo '— (tier 3)' || echo ❌)}"
  f=$(pick "^fullstack-ai/${id}_v[0-9]+\.md$"); echo "- Rapport:  ${f:+✅ $f}${f:-❌}"
  for t in qa-critic security-critic; do
    f=$(pick "^$t/${id}_v[0-9]+\.md$")
    if [ -n "$f" ]; then
      v=$(git show "origin/main:$f" | nocr | grep -m1 -oiE 'status:?\**:? *\**[A-Z_]+' | grep -oE '[A-Z_]+$' || true)
      echo "- $t: ✅ $f (${v:-ingen status}; $(git show "origin/main:$f" | nocr | grep -m1 -oE 'Branch:[^@]*' || echo 'ingen Branch:'))"
    else echo "- $t: $([ "$tier" = 1 ] && echo ❌ || echo '— (kun tier 1)')"; fi
  done
  f=$(pick "^changes/$id(-[0-9]+)?\.md$");      echo "- Fragment: ${f:+✅ $f}${f:-❌}"
  f=$(pick "^tasks/test-$id\.md$")
  echo "- Test DEV: ${f:+✅ $f ($(git show "origin/main:$f" | nocr | grep -m1 -oE 'PASSED|FAILED|BESTÅET|FEJLET' || echo 'intet resultat'))}${f:-❌}"
  f=$(pick "^incidents/I[0-9]+\.md$"); [ -n "$(git grep -l -E "(^|[^0-9A-Za-z])$id([^0-9A-Za-z]|$)" origin/main -- incidents/ 2>/dev/null)" ] && echo "- Incident: nævnt i incidents/"
  return 0
}

roadmap_ids() { nocr < "$ROADMAP" | grep -oE "(^|[^0-9A-Za-z])$IDR" | grep -oE "$IDR" | sort -u || true; }
row_id() { [[ "$1" =~ ^(-|\|)[\ *]*($IDR)([^0-9A-Za-z]|$) ]] && printf '%s' "${BASH_REMATCH[2]}"; return 0; }   # '- F012: …' eller '| F012 | …'

cmd_roadmap() {
  [ -f "$ROADMAP" ] || die "ROADMAP.md findes ikke"
  fetch; load_releases
  local line id r st plan sec=0
  echo "# Roadmap — næste 6 måneder (genereret $(date +%F); 'faktisk' og status er målt, ikke skrevet)"
  while IFS= read -r line; do
    case "$line" in
      "## Nu"*|"## Næste"*|"## Senere"*) sec=1; echo; echo "$line"; continue ;;
      "## Backlog"*|"## Idéer"*) sec=2; echo; echo "## Backlog — ikke lagt i roadmap endnu (ingen plan)"; continue ;;
      "## Før"*) sec=3; echo; echo "## Bygget før frameworket — kører, aldrig releaset (flyttes til Released ved første release)"; continue ;;
      "## "*) sec=0; continue ;;
    esac
    [ $sec = 0 ] && continue
    [[ "$line" =~ ^(-|\|) ]] || continue
    [[ "$line" =~ ^\|[-\ :|]*$ ]] && continue                       # tabellens skillelinje
    if [ $sec = 2 ] || [ $sec = 3 ]; then echo "$line"; continue; fi
    id=$(row_id "$line")
    if [ -z "$id" ]; then echo "$line"; continue; fi
    r=$(released_in "$id")
    if [ -n "$r" ]; then echo "$line · faktisk: Released $(cut -f2 <<< "$r") ($(cut -f1 <<< "$r"))"; continue; fi
    st="ikke startet"; in_history "$id" origin/main && st="Merged (på main), ikke Released"
    # gældende plan = sidste måned efter "plan:" eller "replan …:"
    plan=$(grep -oE '(plan: *|replan [0-9-]+: *)[0-9]{4}-[0-9]{2}' <<< "$line" | tail -1 | grep -oE '[0-9]{4}-[0-9]{2}$' || true)
    [ -n "$plan" ] && [[ "$plan" < "$(date +%Y-%m)" ]] && st="$st · OVERSKREDET (plan $plan)"
    echo "$line · status: $st"
  done < <(nocr < "$ROADMAP")
}

# 5.31: køreplanen — features samlet i de releases, der skal på STABLE. En planlagt release er 'release: <navn>'
# på ROADMAP-linjen (fx "release: R2" eller "release: 2026-11"); uden den er releasen planens måned. Status er målt.
cmd_launchplan() {
  [ -f "$ROADMAP" ] || die "ROADMAP.md findes ikke"
  fetch; load_releases
  git fetch -q origin '+refs/tags/test:refs/tags/test' 2>/dev/null || true
  local hastest=0; git rev-parse -q --verify refs/tags/test >/dev/null 2>&1 && hastest=1
  local line id rel plan tt sec=0 rows=""
  while IFS= read -r line; do
    case "$line" in
      "## Nu"*|"## Næste"*|"## Senere"*) sec=1; continue ;;
      "## "*) sec=0; continue ;;
    esac
    [ $sec = 1 ] || continue
    [[ "$line" =~ ^(-|\|) ]] || continue
    id=$(row_id "$line"); [ -n "$id" ] || continue
    plan=$(grep -oE '(plan: *|replan [0-9-]+: *)[0-9]{4}-[0-9]{2}' <<< "$line" | tail -1 | grep -oE '[0-9]{4}-[0-9]{2}$' || true)
    rel=$(sed -nE 's/.*release: *([A-Za-z0-9._-]+).*/\1/p' <<< "$line" | sed -n 1p)
    [ -n "$rel" ] || rel=${plan:-uden plan}
    tt=$(sed -E "s/^[-|* ]*$id[:| ]+ *//; s/ *(—|\|| plan:).*//" <<< "$line")
    rows+="$rel"$'\t'"${plan:-9999-99}"$'\t'"$id"$'\t'"$tt"$'\n'
  done < <(nocr < "$ROADMAP")
  echo "# Køreplan til STABLE (genereret $(date +%F) — status er målt fra git, flag og releases)"
  echo "En release = det, der går på STABLE samlet. Planlægges med 'release: <navn>' på ROADMAP-linjen; uden den gælder planens måned."
  [ -n "$rows" ] || { echo; echo "Ingen features i Nu/Næste/Senere i ROADMAP.md."; }
  local r first g st ready total miss mis k sp f
  # rækkefølge: efter tidligste plan-måned i hver release
  for r in $(printf '%s' "$rows" | awk -F'\t' 'NF>=3 { if (!($1 in m) || $2 < m[$1]) m[$1]=$2 } END { for (k in m) print m[k] "\t" k }' | sort | cut -f2 | tr ' ' '\037'); do
    r=$(tr '\037' ' ' <<< "$r")
    first=$(printf '%s' "$rows" | awk -F'\t' -v r="$r" '$1==r {print $2}' | sort | tail -1)
    echo; echo "## Release $r — forventet ${first/9999-99/(ingen plan)}"
    ready=0; total=0; miss=""
    while IFS=$'\t' read -r g plan id tt; do
      [ "$g" = "$r" ] || continue; total=$((total+1))
      st=""; sp=""
      if [ -n "$(released_in "$id")" ]; then st="✔ på STABLE ($(released_in "$id" | cut -f1))"; ready=$((ready+1))
      elif in_history "$id" origin/main; then
        f=$(flag_of "$id" origin/main stable)
        st="på main (DEV)"
        if [ $hastest = 1 ] && in_history "$id" refs/tags/test; then st="på TEST"; fi
        [ -n "$f" ] && st="$st · flag stable=$f"
        mis=""
        if [[ "$id" == F* ]]; then
          k=$(fragment_field "$id" origin/main Kunde); [ -n "$k" ] || mis+=" mangler Kunde-tekst;"
          k=$(fragment_field "$id" origin/main Support); [ -n "$k" ] || mis+=" mangler Support-tekst;"
          k=$(ak_missing "$id" origin/main); case "$k" in NOSPEC|NOAK|"") ;; *) mis+=" acceptkriterier uden test: $k;" ;; esac
        fi
        if [ -z "$mis" ]; then st="$st · klar"; ready=$((ready+1)); else st="$st ·$mis"; miss+="- $id:$mis"$'\n'; fi
      else
        if git branch -r --list "origin/feature/$id-*" "origin/hotfix/$id-*" 2>/dev/null | gq .; then st="i gang (branch findes, ikke merget)"; else st="ikke startet"; fi
        miss+="- $id: $st"$'\n'
      fi
      [ "$plan" != 9999-99 ] && [[ "$plan" < "$(date +%Y-%m)" ]] && [[ "$st" != ✔* ]] && st="$st · OVERSKREDET (plan $plan)"
      k=$(title_of "$id" origin/main 2>/dev/null || true); echo "- $id: ${k:-$tt} — $st"
    done < <(printf '%s' "$rows")
    echo "Klar: $ready af $total."
    [ -n "$miss" ] && { echo "Mangler før release:"; printf '%s' "$miss"; }
  done
  echo; echo "## Fælles for enhver release til STABLE"
  local cs h body d
  cs=$(ci_state "$(git rev-parse origin/main)"); [ "$cs" = success ] && echo "- CI på main: grøn" || echo "- CI på main: ${cs:-ingen status} — skal være grøn"
  [ $hastest = 1 ] && echo "- TEST: tagget test findes" || echo "- TEST: tagget test findes ikke — intet er prøvet på TEST endnu"
  [ -n "$(last_release)" ] && echo "- STABLE: seneste release $(last_release)" || echo "- STABLE: ingen release endnu (første release)"
  h=$(health_url stable)
  if [ -z "$h" ]; then echo "- [health] stable mangler i coworker.ini"
  elif [ -n "$(backup_ext)" ]; then echo "- Backup: ekstern ($(backup_ext))"
  else body=$(curl -fsS -m 15 "$h" 2>/dev/null || true)
    if [ -z "$body" ]; then echo "- STABLE /health svarer ikke herfra — backup/restore kan ikke ses (første release: launched/STABLE/foerste-release-bevis.txt)"
    else d=$(json_field "$body" backup_last_ok); echo "- Backup: ${d:-mangler}$([ "$(days_since "$d")" -le 1 ] || echo ' — for gammel')"
      d=$(json_field "$body" restore_last_ok); echo "- Restore-prøve: ${d:-mangler}$([ "$(days_since "$d")" -le 90 ] || echo ' — for gammel')"; fi
  fi
  echo "Det endelige Launch-tjek kører 'report.sh notes', når mennesket siger \"go\" til STABLE."
}

# Første commit på main med v5.2-sporing (changes/README.md). Før den: historik, der ikke kan rettes.
v52_base() { git log --diff-filter=A --format=%H -1 origin/main -- changes/README.md 2>/dev/null || true; }

cmd_check() {
  fetch; load_releases; load_drafts
  local id r sec tag pub name f base; base=$(v52_base)
  # 0) frameworkets driftsfiler (CI, overvågning, deploy, backup) kører uændret på main
  local pair src dst
  for pair in $INSTALL_MAP; do
    src=${pair%%:*}; dst=${pair#*:}
    [ -f "$src" ] || continue
    if git cat-file -e "origin/main:$dst" 2>/dev/null; then
      [ "$(sha_blob origin/main "$dst")" = "$(sha_file "$src")" ] || echo "$dst på main afviger fra $src — kør 'bash shared/report.sh install' (tier 1)"
    else case "$dst" in .github/*) echo "$dst mangler på main — kør 'bash shared/report.sh install'" ;; esac; fi
  done
  local dp; dp=$(dev_pin); [ -n "$dp" ] && echo "DEV er låst til ${dp:0:7} og følger ikke main (bash shared/report.sh devpin off)"
  if git cat-file -e origin/main:coworker.ini 2>/dev/null; then
    git show origin/main:coworker.ini | nocr | grep -vE '^[ \t]*;' | gq '<' && echo "coworker.ini på main er ikke udfyldt (<…> står stadig)"
  else echo "coworker.ini mangler på main (skabelon: shared/templates/coworker.ini.template)"; fi
  # åbne incident-sager skal have et incident-spor efter et døgn
  local num cr
  while IFS=$'\t' read -r num cr; do
    [ -n "$num" ] || continue
    [ "$(days_since "${cr:0:10}")" -ge 1 ] || continue
    git grep -qE "#$num([^0-9]|$)" origin/main -- incidents/ final-decisions/ 2>/dev/null || echo "Incident-sag #$num (åben siden ${cr:0:10}) har ingen incidents/I0NN.md, der henviser til '#$num'"
  done < <($GH issue list --label incident --state open --json number,createdAt --jq '.[] | [.number, .createdAt] | @tsv' 2>/dev/null | nocr || true)
  [ -n "$base" ] || echo "changes/README.md er ikke på main — tjek 2 og 6 (ROADMAP-linjer, ID-præfiks) er slået fra, indtil den er committet"
  # 1) procesfiler, der kun ligger på disken
  git status --short --untracked-files=all -- tasks product-ai fullstack-ai qa-critic security-critic ui-ux graphic-design final-decisions launched changes ROADMAP.md SPRINT.md LEARNINGS.md PROJEKTVIDEN.md 2>/dev/null \
    | sed 's/^/Ikke i git (commit via docs-PR): /' || true
  if [ -f "$ROADMAP" ]; then
    # 2) F-ID'er i git uden ROADMAP-linje (fra v5.2 og frem)
    if [ -n "$base" ]; then
      local rm; rm=$(roadmap_ids)
      for id in $(ids_in "$base" origin/main | grep '^F' || true); do
        grep -qx "$id" <<< "$rm" || echo "ROADMAP mangler linje for $id"
      done
    fi
    # 3) Released-ID'er der stadig står i Nu/Næste/Senere; Released uden release (kun ID'er fra v5.2 og frem)
    while IFS=$'\t' read -r sec id; do
      [ -n "$id" ] || continue
      r=$(released_in "$id")
      if [ "$sec" = plan ] && [ -n "$r" ]; then echo "Flyt $id til ## Released (Released $(cut -f2 <<< "$r") i $(cut -f1 <<< "$r"))"; fi
      if [ "$sec" = released ] && [ -z "$r" ] && [ -n "$base" ] && ! in_history "$id" "$base"; then echo "$id står under ## Released, men er ikke Released"; fi
    done < <(sec=""; while IFS= read -r line; do
               case "$line" in "## Nu"*|"## Næste"*|"## Senere"*) sec=plan; continue ;; "## Released"*) sec=released; continue ;; "## "*) sec=""; continue ;; esac
               [ -n "$sec" ] && printf '%s\t%s\n' "$sec" "$(row_id "$line")"
             done < <(nocr < "$ROADMAP"))
  else
    echo "ROADMAP.md findes ikke"
  fi
  # 4) release-filer: mangler for udgivne releases (fra første fil), eller udkast der er væk
  if ls launched/releases/v*.md >/dev/null 2>&1; then
    local first=""
    while IFS=$'\t' read -r pub tag name; do
      [ -z "$tag" ] && continue
      [ -z "$first" ] && [ -f "launched/releases/$tag.md" ] && first=$tag
      [ -n "$first" ] && [ ! -f "launched/releases/$tag.md" ] && echo "Release-fil mangler: launched/releases/$tag.md"
    done <<< "$RELEASES"
    for f in launched/releases/v*.md; do
      tag=$(basename "$f" .md)
      awk -F'\t' -v t="$tag" '$2==t{f=1} END{exit f?0:1}' <<< "$RELEASES" && continue
      grep -qx "$tag" <<< "$DRAFTS" && continue
      grep -qx "$tag" <<< "${PRERELEASES:-}" && continue
      if git ls-remote --exit-code -q origin "refs/tags/$tag" >/dev/null 2>&1; then
        echo "Release $tag har et tag, men findes ikke længere på GitHub — en udgivet release er slettet: incident (nextid I), skriv IKKE 'kasseret'"
      else grep -q '^Status: kasseret' "$f" || echo "Udkast $tag findes ikke længere: tilføj linjen 'Status: kasseret $(date +%F)' i $f"; fi
    done
  fi
  # 5) flag-invariant main mod seneste udgivne release
  local last; last=$(last_release)
  if [ -n "$last" ]; then
    declare -A L=() M=()
    while IFS=$'\t' read -r id f; do [ -n "$id" ] && L[$id]=$f; done < <(flags_at "$last")
    while IFS=$'\t' read -r id f; do [ -n "$id" ] && M[$id]=$f; done < <(flags_at origin/main)
    for id in "${!L[@]}"; do
      [ -n "${M[$id]:-}" ] && continue
      [ "${L[$id]}" = on ] && continue
      in_droppet "$id" && continue
      echo "BRUD: flag $id fjernet på main, men var '${L[$id]}' i $last (kun tilladt når stable = on, eller ID'et er Droppet)"
    done
  fi
  # 6) commits på main uden ID-præfiks (fra v5.2 og frem)
  if [ -n "$base" ]; then
    git log "$base..origin/main" --format='%h %s' | grep -vE "^[0-9a-f]+ ($IDR|docs):" | sed 's/^/Commit uden ID-præfiks: /' || true
  fi
  # 7) én flag-sektion per ID
  local p
  for p in $FLAGPATHS; do
    if git cat-file -e "origin/main:$p" 2>/dev/null; then
      git show "origin/main:$p" | nocr | grep -oE "^\\[$IDR"'_' | sort | uniq -d | sed -E 's/^\[(.*)_$/Flere flag-sektioner for \1 (højst én per ID)/' || true
      break
    fi
  done
  # 7b) flag efter deres "fjernes senest"
  for p in $FLAGPATHS; do
    if git cat-file -e "origin/main:$p" 2>/dev/null; then
      git show "origin/main:$p" | nocr | awk -v now="$(date +%Y-%m)" '
        /^\[/ { sec=$0; next }
        match($0, /fjernes senest:? *[0-9][0-9][0-9][0-9]-[0-9][0-9]/) { d=substr($0, RSTART+RLENGTH-7, 7); if (d < now) print "Flag " sec " skulle være fjernet senest " d }'
      break
    fi
  done
  # 8) drift bevist af maskiner (STABLE /health), ikke af datoer i markdown
  [ -f "$DRIFT" ] || echo "$DRIFT mangler (skabelon: shared/templates/DRIFT.md.template)"
  local hu body d; hu=$(health_url stable)
  if [ -z "$hu" ]; then echo "health-URL for stable mangler ([health] stable i coworker.ini)"
  else
    body=$(curl -fsS -m 15 "$hu" 2>/dev/null || true)
    # Intet svar = intet at bedømme backup ud fra: én besked, ikke en falsk backup-fejl (som monitor.sh)
    if [ -z "$body" ]; then echo "STABLE /health svarer ikke herfra ($hu) — netværk/VPN/firewall, eller STABLE er nede. Backup og restore kan ikke ses"
    elif [ -z "$(backup_ext)" ]; then
    d=$(json_field "$body" backup_last_ok);  [ "$(days_since "$d")" -le 1 ]  || echo "Ingen backup det sidste døgn (backup_last_ok='${d:-mangler}') — STABLE er ikke med i serverens backup. Spørg sysadmin-subagenten; ret intet på serveren selv"
    d=$(json_field "$body" restore_last_ok); [ "$(days_since "$d")" -le 35 ] || echo "Ingen automatisk restore-test de sidste 35 dage (restore_last_ok='${d:-mangler}') — spørg sysadmin-subagenten"
    fi
  fi
  git cat-file -e "origin/main:homedir/cron/monitor.sh" 2>/dev/null || echo "homedir/cron/monitor.sh mangler på main (bash shared/report.sh install)"
  local cs; cs=$(ci_state "$(git rev-parse origin/main)")
  case "$cs" in
    success) ;;
    "") echo "main ($(git rev-parse --short origin/main)) har ingen CI-status fra DEV-serveren — kører cron/ci.sh? (OPSAETNING.md: serveropsætning)" ;;
    pending) ;;
    *) echo "main ($(git rev-parse --short origin/main)) er RØD i CI ($cs) — H-nummer straks" ;;
  esac
  for h in $(git ls-tree -r --name-only origin/main .github/workflows/ 2>/dev/null); do echo "$h på main: GitHub Actions skal fjernes (bash shared/report.sh install i en tier 1-hotfix)"; done
  # 8b) frameworkets filer = låsefilen (ingen lokale rettelser i framework-filer)
  if [ -f "$FW_LOCK" ]; then
    local ls lp
    while read -r ls lp; do
      [ -z "$lp" ] && continue
      if [ ! -f "$lp" ]; then echo "Framework-fil mangler: $lp"
      elif [ "$(sha_file "$lp")" != "$ls" ]; then echo "Framework-fil afviger fra master-versionen: $lp (rettes kun i master)"; fi
    done < <(nocr < "$FW_LOCK" | grep -vE '^#|^$')
  fi
  # 9) LEARNINGS-loft (efter størrelse: de ældste linjer flyttes, til filen er under loftet)
  if [ -f LEARNINGS.md ] && [ "$(wc -l < LEARNINGS.md)" -gt 300 ]; then
    echo "LEARNINGS.md har $(wc -l < LEARNINGS.md) linjer (loft 300): kopiér de ældste linjer til launched/learnings/ÅÅÅÅ-QN.md, fjern dem derefter fra LEARNINGS.md, til den er under 300"
  fi
  # 10) accepterede risici skal have en udløbsdato, og udløbne skal tages op igen
  if [ -f "$DECISIONS" ]; then
    nocr < "$DECISIONS" | awk -v today="$(date +%F)" '
      function done_() { if (h != "" && risk) { if (ex == "") print "Accepteret risiko uden \"Udløber: ÅÅÅÅ-MM-DD\": " h; else if (ex < today) print "Accepteret risiko er udløbet (" ex "): " h } }
      /^## #[0-9]+/ { done_(); h=$0; risk=0; ex=""; next }
      tolower($0) ~ /accepteret risiko/ { risk=1 }
      match($0, /Udløber:? *[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/) { ex=substr($0, RSTART+RLENGTH-10, 10) }
      END { done_() }'
  fi
  # 11) adgangsregister: findes, og hver række gennemgået inden for 90 dage
  local acc=launched/compliance/ACCESS.md
  if [ -f "$acc" ]; then
    nocr < "$acc" | awk -v lim="$(date -d '-90 days' +%F)" -F'|' '
      /^\|/ && $2 !~ /^ *(System|-)/ { d=""; for (i=NF; i>1; i--) if (match($i, /[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/)) { d=substr($i, RSTART, 10); break }
        if (d == "" || d < lim) { gsub(/^ +| +$/, "", $2); gsub(/^ +| +$/, "", $3); print "ACCESS.md: \"" $2 " / " $3 "\" ikke gennemgået de sidste 90 dage (" (d == "" ? "ingen dato" : d) ")" } }'
  else echo "$acc mangler — adgangsregister (skabelon: shared/templates/ACCESS.md.template)"; fi
  # 12) critics skal kunne finde noget (5.22): mange reviews og aldrig CHANGES_REQUIRED er et signal, ikke et bevis
  local cdir n r f
  for cdir in qa-critic security-critic; do
    n=0; r=0
    for f in $(git log origin/main --since='90 days ago' --diff-filter=A --name-only --format= -- "$cdir/" 2>/dev/null | grep -E '_v[0-9]+[a-z]?\.md$' | sort -u); do
      n=$((n+1))
      if git show "origin/main:$f" 2>/dev/null | nocr | gq -iE '^[#* -]*status:?\**:? *\**CHANGES_REQUIRED'; then r=$((r+1)); fi
    done
    if [ "$n" -ge 10 ] && [ "$r" = 0 ]; then echo "$cdir: $n reviews de sidste 90 dage og ingen med CHANGES_REQUIRED — finder den noget? Læs tre af dem mod diffen (agents/team_lead.md)"; fi
  done
}

type_of() {   # $1=ID [$2=rev] -> feature|fejl|hotfix|gæld|sikkerhed|incident (Type: i fragmentet; ellers ud fra bogstavet)
  local t; t=$(fragment_field "$1" "${2:-origin/main}" Type)
  case "$t" in
    feature*|fejl*|hotfix*|sikkerhed*) echo "${t%% *}" ;;
    gæld*|gaeld*) echo gæld ;;
    *) case "$1" in F*) echo feature ;; H*) echo fejl ;; I*) echo incident ;; *) echo "-" ;; esac ;;
  esac
}
period_of() {   # $1=ÅÅÅÅ-MM[..ÅÅÅÅ-MM] -> "fra til" (tom = alt)
  [ -z "${1:-}" ] && { echo " "; return 0; }
  [[ "$1" =~ ^([0-9]{4}-[0-9]{2})(\.\.([0-9]{4}-[0-9]{2}))?$ ]] || die "periode: ÅÅÅÅ-MM eller ÅÅÅÅ-MM..ÅÅÅÅ-MM"
  echo "${BASH_REMATCH[1]} ${BASH_REMATCH[3]:-${BASH_REMATCH[1]}}"
}

# 5.28: arbejdets art — hvor mange ID'er af hver type blev færdige (første "ID: …"-commit på main) pr. måned.
cmd_fordeling() {
  local from to; read -r from to <<< "$(period_of "${1:-}")"
  [ -n "$from" ] || die "brug: fordeling ÅÅÅÅ-MM eller ÅÅÅÅ-MM..ÅÅÅÅ-MM"
  fetch
  local d id f rows=""
  while IFS=$'\t' read -r d id; do
    [[ ! "${d:0:7}" < "$from" && ! "${d:0:7}" > "$to" ]] || continue
    rows+="${d:0:7}"$'\t'"$(type_of "$id")"$'\t'"$id"$'\n'
  done < <(git log origin/main --format='%ad%x09%s' --date=short --reverse | awk -F'\t' '$2 ~ /^[FH][0-9][0-9][0-9]+[a-z]?:/ { id=$2; sub(/:.*/, "", id); if (!(id in seen)) { seen[id]=1; print $1 "\t" id } }')
  for f in $(git ls-tree -r --name-only origin/main incidents/ 2>/dev/null | grep -E '^incidents/I[0-9]{3,}\.md$' || true); do
    d=$(git log -1 --diff-filter=A --format=%ad --date=short origin/main -- "$f")
    [[ ! "${d:0:7}" < "$from" && ! "${d:0:7}" > "$to" ]] || continue
    rows+="${d:0:7}"$'\t'incident$'\t'"$(basename "$f" .md)"$'\n'
  done
  echo "# Arbejdets art $from..$to — antal ID'er pr. måned (færdige på main), ikke tid"
  echo "Type fra 'Type:' i changes/<ID>.md; uden den: F = feature, H = fejl. Tid pr. type: bash shared/report.sh forbrug $from..$to"
  echo
  printf '%s' "$rows" | awk -F'\t' '
    NF>=2 { n[$1 SUBSEP $2]++; m[$1]=1; tot[$2]++; all++ }
    END {
      split("feature fejl hotfix gæld sikkerhed incident", T, " ")
      printf "| Måned |"; for (i=1;i<=6;i++) printf " %s |", T[i]; print " I alt |"
      printf "|---|"; for (i=1;i<=6;i++) printf "---|"; print "---|"
      k=0; for (x in m) M[++k]=x
      for (i=1;i<=k;i++) for (j=i+1;j<=k;j++) if (M[j] < M[i]) { t=M[i]; M[i]=M[j]; M[j]=t }
      for (i=1;i<=k;i++) { s=0; printf "| %s |", M[i]; for (j=1;j<=6;j++) { v=n[M[i] SUBSEP T[j]]+0; s+=v; printf " %d |", v }; print " " s " |" }
      printf "| I alt |"; for (j=1;j<=6;j++) printf " %d |", tot[T[j]]+0; print " " all+0 " |"
      if (all > 0) { print ""; printf "Andel hotfix: %d %%. Mange hotfixes = et kvalitetsproblem, ikke et kapacitetsproblem.\n", 100*(tot["hotfix"]+0)/all }
    }'
  echo
  echo "## ID'er"
  printf '%s' "$rows" | sort | while IFS=$'\t' read -r d f id; do [ -n "$id" ] && echo "- $d $id ($f): $(title_of "$id" origin/main 2>/dev/null || true)"; done
}

# 5.28: tid og tokens fra Claude Codes egne sessionslogs (~/.claude/projects/<projektmappe>/) på denne pc.
# Hvert modelsvar tælles én gang (requestId — Claude Code gentager forbruget på hver linje af svaret).
# Aktiv tid = summen af pauser under 10 min mellem to svar i samme log; længere pauser tæller ikke.
# ID: fra branchen (feature/F012-…, hotfix/H023-…); critics/tester på main: fra opgavebeskrivelsen i subagentens
# .meta.json; ellers "Styring/uden ID" (Team Lead på main).
forbrug_emit() {   # $1=jsonl $2=agent $3=ID fra beskrivelsen $4=nøgle
  awk -v agent="$2" -v did="$3" -v fk="$4" '
    function fld(k,  r) { if (match($0, "\"" k "\":\"[^\"]*\"")) { r=substr($0,RSTART,RLENGTH); sub(/^"[^"]*":"/,"",r); sub(/"$/,"",r); return r } return "" }
    function num(k,  r) { if (match($0, "\"" k "\":[0-9]+")) { r=substr($0,RSTART,RLENGTH); sub(/.*:/,"",r); return r+0 } return 0 }
    index($0, "\"type\":\"assistant\"") && index($0, "\"usage\"") {
      rq=fld("requestId"); if (rq != "") { if (rq in seen) next; seen[rq]=1 }
      ts=fld("timestamp"); br=fld("gitBranch"); id=""
      if (match(br, /(^|\/)[FHI][0-9][0-9][0-9]+/)) { id=substr(br,RSTART,RLENGTH); sub(/^\//,"",id) }
      if (id == "") id=did
      printf "%s\t%s\t%s\t%d\t%d\t%d\t%d\t%s\n", fk, ts, (id==""?"-":id), num("input_tokens"), num("output_tokens"), num("cache_read_input_tokens"), num("cache_creation_input_tokens"), agent
    }' "$1"
}
log_files() {   # projektets sessionslogs: "fil<TAB>agent<TAB>ID fra opgavebeskrivelsen" (tomt + besked på stderr = ingen)
  local top base m d f meta at did found=0
  top=$(git rev-parse --show-toplevel); base=${CW_CLAUDE_LOGS:-$HOME/.claude/projects}
  m=$(printf '%s' "$top" | sed 's/[^A-Za-z0-9]/-/g')
  for d in "$base/$m" "$base/$m"--claude-worktrees-*; do
    [ -d "$d" ] || continue; found=1
    for f in "$d"/*.jsonl; do [ -f "$f" ] && printf '%s\tteam-lead\t\n' "$f"; done
    for f in "$d"/*/subagents/agent-*.jsonl; do
      [ -f "$f" ] || continue; meta=${f%.jsonl}.meta.json
      at=$(grep -o '"agentType":"[^"]*"' "$meta" 2>/dev/null | sed -n 1p | cut -d'"' -f4 || true)
      did=$(grep -o '"description":"[^"]*"' "$meta" 2>/dev/null | grep -oE '[FHI][0-9]{3,}' | sed -n 1p || true)
      printf '%s\t%s\t%s\n' "$f" "${at:-subagent}" "$did"
    done
  done
  [ $found = 1 ] || echo "ingen logs i $base/$m — Claude Code har ikke kørt projektet fra $top på denne pc" >&2
}
cmd_forbrug() {
  local from to; read -r from to <<< "$(period_of "${1:-}")"
  local top f at did lf; top=$(git rev-parse --show-toplevel)
  lf=$(log_files 2>&1 >/dev/null || true)
  if [ -n "$lf" ]; then echo "FORBRUG: $lf"; return 0; fi
  local raw; raw=$(
    while IFS=$'\t' read -r f at did; do [ -n "$f" ] && { forbrug_emit "$f" "$at" "$did" "$f" || true; }; done < <(log_files 2>/dev/null) \
      | sort -t$'\t' -k1,1 -k2,2)
  [ -n "$raw" ] || { echo "FORBRUG: logs fundet, men ingen modelsvar i dem"; return 0; }
  # pr. svar: aktiv tid (sek.) siden forrige svar i samme log, hvis pausen er under 10 min
  local rec; rec=$(printf '%s\n' "$raw" | awk -F'\t' -v from="$from" -v to="$to" '
    function ep(t,  y,mo,d,h,mi,s,a) { y=substr(t,1,4)+0; mo=substr(t,6,2)+0; d=substr(t,9,2)+0; h=substr(t,12,2)+0; mi=substr(t,15,2)+0; s=substr(t,18,2)+0
      if (mo <= 2) { y--; mo+=12 }   # dage siden 1970 (civil kalender), ingen mktime (mawk)
      a=365*y + int(y/4) - int(y/100) + int(y/400) + int((153*(mo-3)+2)/5) + d - 719469
      return a*86400 + h*3600 + mi*60 + s }
    { e=ep($2); g=0; if ($1 == pf && e >= pe && e - pe < 600) g=e-pe; pf=$1; pe=e
      mo=substr($2,1,7); if (from != "" && (mo < from || mo > to)) next
      print $3 "\t" $8 "\t" g "\t" $4+$6+$7 "\t" $5 }')
  [ -n "$rec" ] || { echo "FORBRUG: ingen modelsvar i perioden ${from:-}${to:+..$to}"; return 0; }
  local ids; ids=$(printf '%s\n' "$rec" | cut -f1 | sort -u)
  local typemap="" i
  for i in $ids; do [ "$i" = "-" ] && continue; typemap+="$i"$'\t'"$(type_of "$i" origin/main 2>/dev/null || echo -)"$'\t'"$(title_of "$i" origin/main 2>/dev/null || true)"$'\n'; done
  echo "# Forbrug ${from:-hele perioden}${to:+..$to} — fra Claude Codes logs på denne pc ($top)"
  echo "Aktiv tid = summen af pauser under 10 min mellem to modelsvar. Tokens ud = skrevet af modellen; ind = læst (inkl. cache)."
  echo "Pris beregnes ikke (den afhænger af model og aftale). Logs fra andre pc'er er ikke med."
  { printf '%s' "$typemap" | sed 's/^/T\t/'; printf '%s\n' "$rec" | sed 's/^/R\t/'; } | awk -F'\t' '
    function hm(s) { return sprintf("%d:%02d", int(s/3600), int((s%3600)/60)) }
    function tk(x) { return (x >= 1000000 ? sprintf("%.1f mio", x/1000000) : (x >= 1000 ? sprintf("%.0f k", x/1000) : x)) }
    $1 == "T" { T[$2]=$3; N[$2]=$4; next }
    $1 == "R" && $2 != "" {
      id=$2; a=$3; ty=(id=="-" ? "styring/uden ID" : (id in T ? T[id] : "-"))
      it[id]+=$4; ii[id]+=$5; io[id]+=$6; ag[id SUBSEP a]=1
      at[a]+=$4; ai[a]+=$5; ao[a]+=$6
      tt[ty]+=$4; ti[ty]+=$5; to_[ty]+=$6; S+=$4; SI+=$5; SO+=$6 }
    END {
      print ""; print "## Pr. ID"; print "| ID | Titel | Type | Aktiv tid | Tokens ud | Tokens ind | Agenter |"; print "|---|---|---|---|---|---|---|"
      for (id in it) { al=""; for (k in ag) { split(k, kk, SUBSEP); if (kk[1]==id) al=al (al==""?"":", ") kk[2] }
        printf "| %s | %s | %s | %s | %s | %s | %s |\n", (id=="-" ? "Styring/uden ID" : id), (id=="-" ? "Team Lead på main, opgaver uden ID" : N[id]), (id=="-" ? "-" : T[id]), hm(it[id]), tk(io[id]), tk(ii[id]), al | "sort" }
      close("sort")
      print ""; print "## Pr. type"; print "| Type | Aktiv tid | Tokens ud | Tokens ind |"; print "|---|---|---|---|"
      for (t in tt) printf "| %s | %s | %s | %s |\n", t, hm(tt[t]), tk(to_[t]), tk(ti[t]) | "sort"
      close("sort")
      print ""; print "## Pr. agent"; print "| Agent | Aktiv tid | Tokens ud | Tokens ind |"; print "|---|---|---|---|"
      for (a in at) printf "| %s | %s | %s | %s |\n", a, hm(at[a]), tk(ao[a]), tk(ai[a]) | "sort"
      close("sort")
      print ""; printf "I alt: %s aktiv tid, %s tokens ud, %s tokens ind.\n", hm(S), tk(SO), tk(SI)
    }'
}

# 5.28: hvor går tiden og tokens? Fire målinger fra samme logs som 'forbrug' + sporet i git.
spild_emit() {   # $1=jsonl $2=agent $3=ID fra beskrivelsen -> T (værktøj), S (session), L (læsning)
  awk -v agent="$2" -v did="$3" -v fk="$1" '
    function fld(k,  r) { if (match($0, "\"" k "\":\"[^\"]*\"")) { r=substr($0,RSTART,RLENGTH); sub(/^"[^"]*":"/,"",r); sub(/"$/,"",r); return r } return "" }
    function num(k,  r) { if (match($0, "\"" k "\":[0-9]+")) { r=substr($0,RSTART,RLENGTH); sub(/.*:/,"",r); return r+0 } return 0 }
    function ep(t,  y,mo,d,a) { y=substr(t,1,4)+0; mo=substr(t,6,2)+0; d=substr(t,9,2)+0; if (mo <= 2) { y--; mo+=12 }
      a=365*y + int(y/4) - int(y/100) + int(y/400) + int((153*(mo-3)+2)/5) + d - 719469
      return a*86400 + substr(t,12,2)*3600 + substr(t,15,2)*60 + substr(t,18,2) }
    function idof(br,  i) { i=""; if (match(br, /(^|\/)[FHI][0-9][0-9][0-9]+/)) { i=substr(br,RSTART,RLENGTH); sub(/^\//,"",i) } return (i=="" ? (did=="" ? "-" : did) : i) }
    function kat(n, c,  w) {
      if (n == "Agent" || n == "Task") return "venter på subagent"
      if (n != "Bash" && n != "PowerShell") return n
      if (match(c, /shared\/report\.sh [a-z][a-z-]+/)) return substr(c, RSTART+7, RLENGTH-7)
      if (c ~ /(^|[;&| ])sleep /) return "venter: sleep"
      if (c ~ /gh (pr checks|run watch|pr view|api)/) return "GitHub (gh)"
      if (c ~ /phpunit|composer|phpstan|php /) return "tests og PHP"
      sub(/^cd [^&;]*(&&|;) */, "", c); sub(/^[( ]+/, "", c)
      while (match(c, /^[A-Za-z_][A-Za-z0-9_]*=[^ ]* +/)) c=substr(c, RLENGTH+1)   # VAR=x kommando
      split(c, w, " ")
      if (w[1] == "git") return "git " w[2]
      return w[1] }
    index($0, "\"type\":\"assistant\"") {
      ts=fld("timestamp"); br=fld("gitBranch")
      if (index($0, "\"usage\"")) { rq=fld("requestId"); if (!(rq in seen)) { seen[rq]=1; n++
          ctx=num("input_tokens")+num("cache_read_input_tokens")+num("cache_creation_input_tokens"); ind+=ctx; if (ctx > mx) mx=ctx
          if (first == "") first=ts; last=ts } }
      line=$0
      while (match(line, /"type":"tool_use","id":"[^"]*","name":"[^"]*"/)) {
        t=substr(line, RSTART, RLENGTH); line=substr(line, RSTART+RLENGTH)
        split(t, q, "\""); tid=q[8]; nm=q[12]; c=""
        if (match(line, /^,"input":\{"command":"([^"\\]|\\.)*"/)) { c=substr(line, 22, RLENGTH-22); gsub(/\\"/, "\"", c) }
        if (match(line, /^,"input":\{"file_path":"[^"]*"/)) { fp=substr(line, 24, RLENGTH-24); if (nm == "Read") print "L\t" fk "\t" agent "\t" fp }
        st[tid]=ts; ka[tid]=kat(nm, c); ib[tid]=idof(br) } }
    index($0, "\"tool_use_id\":\"") {
      tid=fld("tool_use_id"); if (tid in st) { d=ep(fld("timestamp"))-ep(st[tid]); if (d < 0) d=0
        print "T\t" substr(st[tid],1,7) "\t" ib[tid] "\t" agent "\t" ka[tid] "\t" d; delete st[tid] } }
    END { if (n > 0) print "S\t" substr(first,1,7) "\t" fk "\t" agent "\t" n "\t" ep(last)-ep(first) "\t" mx "\t" ind "\t" substr(first,1,16) }' "$1"
}
cmd_spild() {
  local from to; read -r from to <<< "$(period_of "${1:-}")"
  local lf f at did; lf=$(log_files 2>&1 >/dev/null || true)
  if [ -n "$lf" ]; then echo "SPILD: $lf"; return 0; fi
  local raw; raw=$(while IFS=$'\t' read -r f at did; do [ -n "$f" ] && { spild_emit "$f" "$at" "$did" || true; }; done < <(log_files 2>/dev/null); true)
  echo "# Hvor går tiden og tokens ${from:-(hele perioden)}${to:+..$to} — Claude Codes logs på denne pc + git"
  printf '%s\n' "$raw" | awk -F'\t' -v from="$from" -v to="$to" '
    function hm(s) { return sprintf("%d:%02d", int(s/3600), int((s%3600)/60)) }
    function tk(x) { return (x >= 1000000 ? sprintf("%.1f mio", x/1000000) : (x >= 1000 ? sprintf("%.0f k", x/1000) : x)) }
    function inp(m) { return (from == "" || (m >= from && m <= to)) }
    $1 == "T" && inp($2) { k=$5; tid[k]+=$6; tn[k]++; if ($6 > tmx[k]) tmx[k]=$6; if (k == "report.sh precheck") pc[$3]++; if ($3 != "-") idt[$3]+=$6 }
    $1 == "S" && inp($2) { ns++; sa[ns]=$4; sn[ns]=$5; sd[ns]=$6; sm[ns]=$7; si[ns]=$8; st[ns]=$9 }
    $1 == "L" { rd[$2 SUBSEP $4]++ }
    END {
      print ""; print "## 1. Hvad tiden går med (værktøjskald, sum af ventetid til svar)"
      print "| Kommando/værktøj | Kald | Samlet | Længste |"; print "|---|---|---|---|"
      for (k in tid) printf "%d\t| %s | %d | %s | %s |\n", tid[k], k, tn[k], hm(tid[k]), hm(tmx[k]) | "sort -rn | head -15 | cut -f2-"
      close("sort -rn | head -15 | cut -f2-")
      print ""; print "## 2. Lange sessioner (hvert svar genlæser hele samtalen — kontekst over 150 k er dyr)"
      print "| Start | Agent | Svar | Varighed | Største kontekst | Tokens ind i alt |"; print "|---|---|---|---|---|---|"
      for (i=1;i<=ns;i++) printf "%d\t| %s | %s | %d | %s | %s | %s |\n", si[i], st[i], sa[i], sn[i], hm(sd[i]), tk(sm[i]), tk(si[i]) | "sort -rn | head -8 | cut -f2-"
      close("sort -rn | head -8 | cut -f2-")
      print ""; print "## 3. Prechecks pr. ID (mange = engineeren prøver sig frem)"
      any=0; for (i in pc) if (pc[i] >= 3) { any=1; printf "%d\t- %s: %d prechecks\n", pc[i], i, pc[i] | "sort -rn | cut -f2-" }
      close("sort -rn | cut -f2-"); if (!any) print "- ingen ID med 3 eller flere"
      print ""; print "## 4. Filer læst 3+ gange af samme agent i samme session (Read)"
      any=0; for (k in rd) if (rd[k] >= 3) { split(k, z, SUBSEP); f=z[2]; gsub(/\\\\/, "/", f); sub(/.*\/ClaudeCoworkers\/[^\/]*\//, "", f); sub(/^\.claude\/worktrees\/[^\/]*\//, "", f); any=1; printf "%d\t- %s: %d gange\n", rd[k], f, rd[k] | "sort -rn | head -10 | cut -f2-" }
      close("sort -rn | head -10 | cut -f2-"); if (!any) print "- ingen"
    }'
  echo
  echo "## 5. Critic-runder pr. ID (git: v2+ = review afvist og lavet om)"
  local x any=0
  while read -r x; do [ -n "$x" ] && { any=1; echo "- $x"; }; done < <(git ls-tree -r --name-only origin/main qa-critic/ security-critic/ 2>/dev/null \
    | sed -nE 's#^(qa-critic|security-critic)/([FHI][0-9]{3,}[a-z]?)_v([0-9]+)\.md$#\2 \1 \3#p' \
    | awk '{ k=$1" "$2; if ($3 > m[k]) m[k]=$3 } END { for (k in m) if (m[k] >= 2) { split(k, a, " "); print a[1] ": " a[2] " " m[k] " runder" } }' | sort)
  [ $any = 1 ] || echo "- ingen ID med mere end én runde"
  echo
  echo "Tid = fra kaldet blev sendt til svaret kom (også ventetid). Tal fra andre pc'er er ikke med."
}

cmd_fixes() {
  local per=${1:-} from to
  [[ "$per" =~ ^([0-9]{4}-[0-9]{2})(\.\.([0-9]{4}-[0-9]{2}))?$ ]] || die "brug: fixes ÅÅÅÅ-MM eller ÅÅÅÅ-MM..ÅÅÅÅ-MM"
  from=${BASH_REMATCH[1]}; to=${BASH_REMATCH[3]:-$from}
  fetch; load_releases
  echo "# Hotfixes og fejlrettelser merget $from..$to (fra git)"
  local h d s id r inc any=0
  while IFS=$'\t' read -r h d s; do
    [[ ! "${d:0:7}" < "$from" && ! "${d:0:7}" > "$to" ]] || continue
    id=${s%%:*}; any=1
    r=$(released_in "$id"); inc=""
    git cat-file -e "origin/main:final-decisions/$id.md" 2>/dev/null && inc=" · INCIDENT (final-decisions/$id.md)"
    if [ -n "$r" ]; then r="Released $(cut -f2 <<< "$r") ($(cut -f1 <<< "$r"))"; else r="Merged, ikke Released"; fi
    echo "- $d $id: $(title_of "$id" origin/main) — $r$inc"
  done < <(git log origin/main --format='%h%x09%ad%x09%s' --date=short --reverse | awk -F'\t' '$3 ~ /^H[0-9][0-9][0-9]+[a-z]?:/ { id=$3; sub(/:.*/, "", id); if (!(id in seen)) { seen[id]=1; print } }')
  [ $any = 1 ] || echo "- (ingen)"
  echo "## Rollback-releases i perioden"
  local pub tag name prev rb=0
  while IFS=$'\t' read -r pub tag name; do
    [ -z "$tag" ] && continue
    [[ ! "${pub:0:7}" < "$from" && ! "${pub:0:7}" > "$to" ]] || continue
    prev=$(prev_of "$tag"); is_rollback "${prev:-$EMPTY}" "$tag" && { rb=1; echo "- ${pub%%T*} $tag: $name (rullede $prev tilbage)"; }
  done <<< "$RELEASES"
  [ $rb = 1 ] || echo "- (ingen)"
  echo "## Incidents (incidents/I###.md)"
  local f2 any2=0 dd
  for f2 in $(git ls-tree -r --name-only origin/main incidents/ 2>/dev/null | grep -E '^incidents/I[0-9]{3,}\.md$' || true); do
    dd=$(git log -1 --diff-filter=A --format=%ad --date=short origin/main -- "$f2")
    [[ ! "${dd:0:7}" < "$from" && ! "${dd:0:7}" > "$to" ]] || continue
    any2=1; echo "- $dd $(basename "$f2" .md): $(git show "origin/main:$f2" | nocr | sed -n 1p | sed -E 's/^#+ *//') · $(git show "origin/main:$f2" | nocr | grep -m1 -iE '^[*-]* *Alvor' | sed -E 's/^[*-]* *//' || true)"
  done
  [ $any2 = 1 ] || echo "- (ingen)"
}

# Plan for at vende tilbage til en tidligere release: kode, flag og database.
cmd_restore() {
  local tag=${1:-}; [ -n "$tag" ] || die "brug: restore <tag>"
  fetch; load_releases
  awk -F'\t' -v t="$tag" '$2==t{f=1} END{exit f?0:1}' <<< "$RELEASES" || die "$tag er ikke en udgivet release"
  local cur; cur=$(last_release)
  echo "# Tilbage til $tag (nu i STABLE: ${cur:-ingen})"
  echo "## 1. Kode"
  echo "Team Lead: bash shared/report.sh notes vÅÅÅÅ.MM.DD-n $(git rev-parse --short "$tag^{commit}") og et udkast med titlen 'Rollback: $cur' — mennesket udgiver."
  echo "## 2. Flag efter rollback (stable = on ved $tag)"
  local fl; fl=$(flags_at "$tag" stable | awk -F'\t' '$2=="on"{printf "%s ", $1}')
  echo "${fl:-(ingen)}"
  echo "## 3. Database — migrationer der skal tilbage (nyeste først)"
  # Databasen har ALLE migrationer, der nogensinde er udgivet (rollback af kode ruller ikke DB tilbage)
  local mt f any=0 irr=0 src pub t2 name
  mt=$(last_mig "$tag")
  declare -A SRC=()
  while IFS=$'\t' read -r pub t2 name; do
    [ -z "$t2" ] && continue
    for f in $(git ls-tree -r --name-only "$t2" homedir/migrations/ 2>/dev/null | grep -v '/down/' | grep -E '/[0-9]{4}_[^/]*\.sql$' || true); do SRC[$f]=$t2; done
  done <<< "$RELEASES"
  for f in $(printf '%s\n' "${!SRC[@]}" | sort -r); do
    [[ "$(basename "$f")" > "$(basename "${mt:-0000}")" ]] || continue
    any=1; src=${SRC[$f]}
    local rev des down
    rev=$(git show "$src:$f" | nocr | grep -oiE '^-- *reversibel: *(ja|nej)' | grep -oiE '(ja|nej)$' || echo "?")
    des=$(git show "$src:$f" | nocr | grep -oiE '^-- *destruktiv: *(ja|nej)' | grep -oiE '(ja|nej)$' || echo "?")
    down="homedir/migrations/down/$(basename "$f")"
    if [ "$rev" = ja ] && git cat-file -e "$src:$down" 2>/dev/null; then echo "- $(basename "$f"): kør $down"
    else irr=1; echo "- $(basename "$f"): IKKE REVERSIBEL (reversibel: $rev, destruktiv: $des) — kræver restore fra backup taget før migrationen"; fi
  done
  [ $any = 1 ] || echo "- ingen (databasen har ikke fået migrationer efter $tag)"
  [ $irr = 1 ] && echo "STOP: mindst én migration kan ikke rulles tilbage med et script. Følg backup/restore i $DRIFT."
  echo "## 4. Eksterne sideeffekter"
  echo "Se afsnittet 'Eksterne systemer' i $DRIFT — det, der er sendt ud (mails, bogføringer, API-kald), rulles ikke tilbage."
  return 0
}

cmd_notes() {
  local tag=${1:-} target=${2:-}
  [ -n "$tag" ] && [ -n "$target" ] || die "brug: notes <tag> <sha>"
  [[ "$tag" =~ ^v[0-9]{4}\.[0-9]{2}\.[0-9]{2}-[0-9]+$ ]] || die "tag skal hedde vÅÅÅÅ.MM.DD-n"
  local f="launched/releases/$tag.md"
  [ -e "$f" ] && die "$f findes allerede — release-filer skrives kun én gang. Kasseret? Tilføj 'Status: kasseret <dato>' og brug næste -n."
  fetch; load_releases
  git cat-file -e "$target^{commit}" 2>/dev/null || die "ukendt commit $target (kør git fetch origin)"
  local prev; prev=$(last_release)
  LAST_ON=""; LAST_PLAIN=""; LAST_BRUD=""
  mkdir -p launched/releases
  {
    echo "# $tag"
    echo "Udkast: $(date +%F) · Target: $(git rev-parse --short "$target") · Migration til: $(basename "$(last_mig "$target")" 2>/dev/null | cut -c1-4)"
    echo "Forrige: ${prev:-(ingen — første release)}"
    analyse "${prev:-$EMPTY}" "$target"
    echo "## Rollback-vej"
    if [ -n "$prev" ]; then echo "Rollback-udkast mod $prev ($(git rev-parse --short "$prev^{commit}")): bash shared/report.sh restore $prev. Enkelt feature: stable = off i en ny release."
    else echo "Ingen forrige release. Enkelt feature: stable = off i en ny release."; fi
    launch_check "$target"
    echo
    echo "Udgivelsesdato og endelig tekst: GitHub-releasen. Denne fil ændres aldrig — kun linjen 'Status: kasseret <dato>' må tilføjes."
  } > "$f"
  echo "$f"
  if grep -q '^Launch-tjek: IKKE KLAR' "$f"; then echo "STOP: Launch-tjek IKKE KLAR — lav intet udkast. Ret det, filen viser, tilføj 'Status: kasseret <dato>', og brug næste -n." >&2; fi
  return 0
}

# Launch readiness: maskintjek før et release-udkast. Skriver "Launch-tjek: KLAR" eller "IKKE KLAR" + årsager.
launch_check() {
  local t=$1 why="" x k s mis h body d
  [ -n "$LAST_BRUD" ] && why+="- BRUD i flag-invarianten: $LAST_BRUD"$'\n'
  [ "$(ci_state "$(git rev-parse "$t^{commit}")")" = success ] \
    || $GH api "repos/{owner}/{repo}/actions/workflows/ci.yml/runs?head_sha=$(git rev-parse "$t^{commit}")&event=push&status=success&per_page=1" --jq .total_count 2>/dev/null | nocr | gq -xE "[1-9][0-9]*" \
    || why+="- CI er ikke grøn på $(git rev-parse --short "$t")"$'\n'
  for x in $LAST_ON $LAST_PLAIN; do
    x=${x%%(*}
    [[ "$x" == F* ]] || continue
    k=$(fragment_field "$x" "$t" Kunde); s=$(fragment_field "$x" "$t" Support)
    [ -n "$k" ] || why+="- $x: changes/$x.md mangler 'Kunde:'"$'\n'
    [ -n "$s" ] || why+="- $x: changes/$x.md mangler 'Support:'"$'\n'
    mis=$(ak_missing "$x" "$t")
    case "$mis" in NOSPEC|NOAK|"") ;; *) why+="- $x: acceptkriterier uden test: $mis"$'\n' ;; esac
  done
  git cat-file -e "$t:homedir/cron/monitor.sh" 2>/dev/null || git cat-file -e "$t:.github/workflows/monitor.yml" 2>/dev/null \
    || why+="- homedir/cron/monitor.sh mangler (overvågning — bash shared/report.sh install)"$'\n'
  h=$(health_url stable)
  local pf=launched/STABLE/foerste-release-bevis.txt
  if [ -z "$h" ]; then why+="- health-URL for stable mangler ([health] stable i coworker.ini)"$'\n'
  else
    body=$(curl -fsS -m 15 "$h" 2>/dev/null || true)
    # Første release på et kørende system: /health findes først efter udrulningen. Så godtages serverens
    # egne bevisfiler, indsat af mennesket i $pf (output af: for f in backup_last_ok restore_last_ok; do echo "$f $(cat logs/$f)"; done)
    if [ -z "$body" ] && [ -z "$(last_release)" ] && git cat-file -e "$t:$pf" 2>/dev/null; then
      body=$(git show "$t:$pf" | nocr | awk '$1=="backup_last_ok"||$1=="restore_last_ok" {printf "\"%s\": \"%s\", ", $1, $2}')
      echo "Bevis for backup/restore: $pf (første release — /health findes ikke endnu; indsat af mennesket, ikke maskinlæst)"
    fi
    if [ -n "$(backup_ext)" ]; then
      echo "Backup: ekstern ($(backup_ext)) — uden for frameworket, ikke maskinbevist (coworker.ini [backup] external)"
    elif [ -z "$body" ]; then   # blokerer stadig — men siger, hvad der er galt
      why+="- STABLE /health svarer ikke herfra ($h) — netværk/VPN/firewall, eller STABLE er nede; backup og restore kan ikke ses"$'\n'
    else
    d=$(json_field "$body" restore_last_ok)
    [ "$(days_since "$d")" -le 90 ] || why+="- ingen bevist restore de sidste 90 dage (health: restore_last_ok='${d:-mangler}')"$'\n'
    d=$(json_field "$body" backup_last_ok)
    [ "$(days_since "$d")" -le 1 ] || why+="- ingen backup det sidste døgn (health: backup_last_ok='${d:-mangler}')"$'\n'
    fi
  fi
  if [ -z "$why" ]; then echo; echo "Launch-tjek: KLAR"
  else echo; echo "Launch-tjek: IKKE KLAR"; printf '%s' "$why"; fi
}

nextid() {
  local k=$1 max
  max=$( {
      git log origin/main --format='%s'
      [ -f "$ROADMAP" ] && nocr < "$ROADMAP"
      git ls-remote --heads origin 2>/dev/null | cut -f2
      git branch -a --format='%(refname)'
      git ls-tree -r --name-only origin/main changes/ tasks/ incidents/ 2>/dev/null
      flags_at origin/main
    } | grep -oE "(^|[^0-9A-Za-z])$k[0-9]{3,}" | grep -oE "[0-9]+" | sed 's/^0*//' | sort -n | tail -1 || true )
  printf '%s%03d\n' "$k" $(( ${max:-0} + 1 ))
}
cmd_nextid() { local k=${1:-}; [[ "$k" =~ ^[FHI]$ ]] || die "brug: nextid F|H|I"; fetch; nextid "$k"; }

# ---------- CI: framework-kontroller (ingen gh — kun git). Køres af cron/ci.sh på DEV-serveren og af precheck ----------
cmd_ci() {
  local base=${BASE_REF:-} head=${HEAD_REF:-} err=0 b
  if [ -n "${BASE_SHA:-}" ]; then b=$BASE_SHA                     # push til main: forrige main-commit
  else [ -n "$base" ] || die "BASE_REF eller BASE_SHA mangler (køres af CI)"; git fetch -q origin "$base" 2>/dev/null || { no_access "git fetch origin $base fejler"; return 1; }; b="origin/$base"; fi
  local changed added
  changed=$(git diff --name-only "$b...HEAD")
  added=$(git diff --name-only --diff-filter=A "$b...HEAD")
  fail() { echo "CI: $*"; err=1; }
  # 5.35: i precheck mangler rapport og critic-reviews med vilje endnu (critics starter først på KODE: GRØN).
  # Dér er de en note, ikke rød. cron/ci.sh (coworker/pr) og main kræver dem altid.
  later() { if [ -n "${CW_PRE_REVIEW:-}" ]; then echo "VENTER (kommer efter critics): $*"; else fail "$*"; fi; }
  local app forced m h
  # Frameworkets egne filer (framework.lock) nævner undertrykkelserne som dokumentation — de tæller ikke.
  # De er låst til masterens indhold (kontrollen længere nede), så undtagelsen åbner ikke for noget.
  local fwx=()
  if git cat-file -e "HEAD:$FW_LOCK" 2>/dev/null; then
    mapfile -t fwx < <(git show "HEAD:$FW_LOCK" | nocr | grep -vE '^#|^$' | awk '{print ":(exclude)"$2}')
    fwx+=(":(exclude)$FW_LOCK")
  fi
  app=$(grep '^homedir/' <<< "$changed" | grep -vx 'homedir/config/features.ini' || true)
  # Tier 1 uanset impact: alt, der afgør hvad CI og deploy tolererer
  forced=$( { grep -E "^(homedir/migrations/|$CTRL_FILES)" <<< "$changed" | grep -vE '^(shared/|\.gitignore$|\.gitattributes$)'
              git diff --name-only --diff-filter=D "$b...HEAD" -- tests/ homedir/tests/
              git diff "$b...HEAD" -- . "${fwx[@]}" ':(exclude,glob)**/*.md' | gq -E '^\+[^+].*(nosemgrep|phpstan-ignore|gitleaks:allow|markTestSkipped|@codeCoverageIgnore)' && echo "(undertrykkelse i koden)"; } || true )
  # Sporet er immutabelt: specs, rapporter, reviews, fragmenter, release-filer og incidents ændres eller
  # slettes aldrig. Tilladt: tilføjede linjer i incidents/ og DECISIONS, og 'Status: kasseret' i release-filer.
  local st f1 f2 rm ad
  while IFS=$'\t' read -r st f1 f2; do
    [ -n "$st" ] || continue
    [[ "$f1" =~ ^(changes|launched/releases|final-decisions|product-ai|fullstack-ai|qa-critic|security-critic|incidents|ui-ux|graphic-design)/ || "$f1" =~ ^tasks/test- || "$f1" == "$DECISIONS" ]] || continue
    [[ "$f1" == */README.md ]] && continue
    case "$st" in
      D*|R*) fail "$f1 er slettet/omdøbt — sporet er immutabelt (ny version = ny fil)" ;;
      M*) rm=$(git diff "$b...HEAD" -- "$f1" | grep -cE '^-[^-]' || true); ad=$(git diff "$b...HEAD" -- "$f1" | grep -E '^\+[^+]' | sed 's/^+//' || true)
          if [[ "$f1" =~ ^(incidents/|$DECISIONS) ]] && [ "$rm" = 0 ]; then :
          elif [[ "$f1" =~ ^launched/releases/ ]] && [ "$rm" = 0 ] && ! grep -vE '^Status: kasseret [0-9]{4}-[0-9]{2}-[0-9]{2} *$|^$' <<< "$ad" > /dev/null; then :
          else fail "$f1 er ændret — sporet er immutabelt (ny version = ny fil; incidents/DECISIONS: kun tilføjelser)"; fi ;;
    esac
  done < <(git diff --name-status "$b...HEAD")
  # Frameworkets pladsholdere må aldrig committes over projektets egne filer
  for h in README.md .gitignore; do
    git show "HEAD:$h" 2>/dev/null | gq 'FRAMEWORK-PLACEHOLDER' && fail "$h er frameworkets pladsholder — hent projektets egen fra git (CLAUDE.md Trin 0, tjek 6)"
  done
  # .gitignore: beskyttelse må aldrig fjernes, og hemmeligheder må aldrig ind i git
  for h in .gitignore .gitattributes; do
    if grep -qx "$h" <<< "$changed"; then
      local okrm rem; okrm=$(git show "HEAD:shared/gitignore.framework" 2>/dev/null | nocr | sed -n 's/^#erstattet: //p')
      # Mængde-sammenligning: en linje, der kun er flyttet, er ikke fjernet
      rem=$(comm -23 <(git show "$b:$h" 2>/dev/null | nocr | grep -vE '^[[:space:]]*(#|$)' | sort -u) \
                     <(git show "HEAD:$h" 2>/dev/null | nocr | grep -vE '^[[:space:]]*(#|$)' | sort -u) \
            | grep -vxF -f <(printf '%s\n' "$okrm") | tr '\n' ' ' || true)
      [ -n "$rem" ] && fail "$h: linjer fjernet ($rem) — må kun få linjer tilføjet, aldrig fjernet"
    fi
  done
  # Frameworkets driftsfiler køres uændret — ingen agent retter CI, overvågning, deploy eller backup
  local pair src dst
  # Rød KUN når denne branch selv ændrer/sletter en installeret fil, så den afviger. En fil, branchen ikke rører,
  # og som endnu ikke er installeret fra en ny version, er en navngivet advarsel — ellers blokerer
  # install-hotfixen (trin 5) alle andre PR'er, også dem den selv venter på. Serverne tager kontrolfiler
  # først ind efter ejerens Publish (deploy.sh), så advarslen åbner ikke for noget.
  local touched
  for pair in $INSTALL_MAP; do
    src=${pair%%:*}; dst=${pair#*:}
    git cat-file -e "HEAD:$src" 2>/dev/null || continue
    touched=""; grep -qxF -- "$dst" <<< "$changed" && touched=1
    if [[ "$head" == docs/framework-* ]]; then   # (før 5.20) ren framework-kopi; installationen kommer i en tier 1-hotfix
      [ -n "$touched" ] && fail "$dst må ikke ændres på $head — kopi og installation hører til i ÉN hotfix/H###-framework-<version> (CLAUDE.md 'Ny framework-version' trin 4)"
      continue
    fi
    if git cat-file -e "HEAD:$dst" 2>/dev/null; then
      if [[ "$dst" == *.sh ]] && [ "$(git ls-tree HEAD -- "$dst" | cut -c1-6)" != 100755 ]; then   # cron kører filen direkte
        if [ -n "$touched" ]; then fail "$dst mangler execute-bit i git (Windows: core.filemode=false) — serveren fjerner x-bit ved udrulning. Kør 'bash shared/report.sh install' igen eller 'git update-index --chmod=+x $dst'"
        else echo "ADVARSEL (install): $dst mangler execute-bit i git — rettes af 'bash shared/report.sh install' i framework-hotfixen"; fi
      fi
      if [ "$(sha_blob HEAD "$dst")" != "$(sha_blob HEAD "$src")" ]; then
        if [ -n "$touched" ]; then fail "$dst er ændret og afviger fra $src — driftsfilerne installeres kun uændret ('bash shared/report.sh install')"
        else echo "ADVARSEL (install): $dst er endnu ikke installeret fra $src — den gamle version kører, til framework-hotfixen er merget"; fi
      fi
    elif [ -n "$touched" ]; then fail "$dst er slettet — frameworkets driftsfiler må ikke fjernes"
    elif [ "$src" = shared/install/ci.sh ] && git cat-file -e HEAD:homedir 2>/dev/null; then echo "ADVARSEL (install): $dst mangler — kør 'bash shared/report.sh install' i framework-hotfixen"; fi
  done
  # GitHub Actions er slået fra (koster penge): CI kører på DEV-serveren (cron/ci.sh). Ingen workflow må
  # tilføjes eller ændres; gamle workflows, branchen ikke rører, er en advarsel, til install-hotfixen fjerner dem.
  for h in $(git ls-tree -r --name-only HEAD .github/workflows/ 2>/dev/null); do
    if grep -qxF -- "$h" <<< "$changed"; then fail "$h: GitHub Actions bruges ikke (CI kører på DEV-serveren, cron/ci.sh) — workflows må ikke tilføjes eller ændres"
    else echo "ADVARSEL (install): $h kører stadig i GitHub Actions — 'bash shared/report.sh install' i framework-hotfixen fjerner den"; fi
  done
  # Frameworkets egne filer må kun ændres ved en ren kopi af en ny version: hotfix/H###-framework-<version> (tier 1,
  # kopi + 'report.sh install' i én merge — 5.20) eller den ældre docs/framework-<version> (kun kopien)
  if git cat-file -e "HEAD:$FW_LOCK" 2>/dev/null; then
    local lockpaths fwchanged bad="" lp ls
    lockpaths=$(git show "HEAD:$FW_LOCK" | nocr | grep -vE '^#|^$' | awk '{print $2}')
    fwchanged=$(grep -xF -f <(printf '%s\n%s\n' "$lockpaths" "$FW_LOCK") <<< "$changed" || true)
    if [ -n "$fwchanged" ]; then
      [[ "$head" == docs/framework-* || "$head" =~ ^hotfix/H[0-9]{3,}[a-z]?-framework- ]] || fail "frameworkets filer er ændret ($(tr '\n' ' ' <<< "$fwchanged")) — kun tilladt som ren kopi af en ny version på en hotfix/H###-framework-<version>-branch"
      while read -r ls lp; do
        [ -z "$lp" ] && continue
        [ "$(sha_blob HEAD "$lp")" = "$ls" ] || bad+="$lp "
      done < <(git show "HEAD:$FW_LOCK" | nocr | grep -vE '^#|^$')
      [ -z "$bad" ] || fail "framework-filer afviger fra $FW_LOCK: $bad— de skal være en uændret kopi af master"
    fi
  fi
  for h in homedir/config/config.ini config/config.ini .claude/settings.local.json; do
    git check-ignore -q --no-index "$h" || fail "$h er ikke beskyttet af .gitignore (se shared/gitignore.framework)"
  done
  local sec; sec=$(grep -E '(^|/)(config\.ini|\.env|[^/]*\.(key|pem|p12|pfx))$' <<< "$added" || true)
  [ -n "$sec" ] && fail "hemmelige filer må aldrig i git: $(tr '\n' ' ' <<< "$sec")"
  # Migrations-baseline (coworker.ini [migrations] baseline = NNNN): migrationer, der fandtes og var kørt, før
  # frameworket kom ind. De ændres aldrig (LIFECYCLE §6) og undtages fra header-tjek og genkørsel.
  # Må kun dække migrationer, der allerede lå på main — eller som kommer ind sammen med appen i samme PR,
  # når main ikke havde nogen migrationer (første import). Nye migrationer skal have et højere nummer.
  local mb mbb mmax mf mn
  mb=$(ini_at HEAD migrations baseline); mbb=$(ini_at "$b" migrations baseline)
  [[ "$mbb" =~ ^[0-9]+$ ]] || mbb=""
  if [ -n "$mb" ] && ! [[ "$mb" =~ ^[0-9]+$ ]]; then fail "coworker.ini [migrations] baseline = '$mb' skal være et migrationsnummer (fx 0116)"; mb=""; fi
  if [ -n "$mb" ] && [ "$mb" != "$mbb" ]; then
    mmax=0
    for mf in $(git ls-tree -r --name-only HEAD homedir/migrations/ 2>/dev/null | grep -E '\.sql$' | grep -v '/down/' || true); do
      mn=$(mignum "$mf"); [ "$mn" -gt "$mmax" ] && mmax=$mn
    done
    [ "$((10#$mb))" -le "$mmax" ] || fail "[migrations] baseline = $mb er højere end den højeste migration i repoet ($mmax) — baseline må kun dække migrationer, der findes"
    if [ -n "$(git ls-tree -r --name-only "$b" homedir/migrations/ 2>/dev/null | grep -E '\.sql$' || true)" ]; then
      for mf in $(git ls-tree -r --name-only HEAD homedir/migrations/ 2>/dev/null | grep -E '\.sql$' | grep -v '/down/' || true); do
        mn=$(mignum "$mf")
        if [ "$mn" -gt "$((10#${mbb:-0}))" ] && [ "$mn" -le "$((10#$mb))" ] && ! git cat-file -e "$b:$mf" 2>/dev/null; then
          fail "[migrations] baseline = $mb dækker $mf, som ikke lå på main — baseline må aldrig hæves over en ny migration"
        fi
      done
    fi
  fi
  # Migration-header (alle nye migrationer over baseline)
  for m in $(grep -E '^homedir/migrations/.*\.sql$' <<< "$added" | grep -v '/down/' || true); do
    if [ -n "$mb" ] && [ "$(mignum "$m")" -le "$((10#$mb))" ]; then
      [ "$mb" = "$mbb" ] && fail "$m har nummer ≤ [migrations] baseline ($mb) — en ny migration skal have det næste nummer over den højeste"
      continue
    fi
    for h in reversibel destruktiv idempotent; do
      nocr < "$m" | gq -iE "^-- *$h: *(ja|nej)" || fail "$m mangler headerlinjen '-- $h: ja/nej'"
    done
    if nocr < "$m" | gq -iE '^-- *reversibel: *ja'; then
      [ -f "homedir/migrations/down/$(basename "$m")" ] || fail "$m siger reversibel: ja — mangler homedir/migrations/down/$(basename "$m")"
    fi
    if nocr < "$m" | gq -iE '^-- *destruktiv: *ja'; then
      local hu d; hu=$(health_url stable); d=""
      [ -n "$hu" ] && d=$(json_field "$(curl -fsS -m 15 "$hu" 2>/dev/null || true)" restore_last_ok)
      if [ -n "$(backup_ext)" ]; then echo "CI: $m er destruktiv — backup er ekstern ($(backup_ext)); gendannelse er IKKE maskinbevist"
      else [ "$(days_since "$d")" -le 90 ] || fail "$m er destruktiv: kræver en maskinbevist restore højst 90 dage gammel (STABLE /health: restore_last_ok='${d:-mangler}')"; fi
    fi
  done
  # 5.36 (econsync): nye migrationer (også down/) må ikke bruge ord, som nyere MariaDB har reserveret, uden backticks
  for m in $(grep -E '^homedir/migrations/.*\.sql$' <<< "$added" || true); do
    [ -f "$m" ] || continue
    while IFS= read -r h; do [ -n "$h" ] && fail "$m $h — reserveret i MariaDB 12.3+ (fejl 1064 på en tom base). Skriv \`${h##*: }\` i backticks"; done < <(sql_reserved "$m")
  done
  # Kendte røde tests: hver linje "<test-case> | H### | ÅÅÅÅ-MM-DD", ikke udløbet
  local kf
  for kf in tests/known-failures.txt homedir/tests/known-failures.txt; do
    [ -f "$kf" ] || continue
    local line today cap; today=$(date +%F); cap=$(date -d '+30 days' +%F)
    while IFS= read -r line; do
      line=${line%%#*}; [ -z "${line// /}" ] && continue
      if ! [[ "$line" =~ ^[^|]+\|\ *H[0-9]{3,}[a-z]?\ *\|\ *([0-9]{4}-[0-9]{2}-[0-9]{2})\ *$ ]]; then fail "$kf: '$line' skal være '<test-case> | H### | ÅÅÅÅ-MM-DD'"
      elif [[ "${BASH_REMATCH[1]}" < "$today" ]]; then fail "kendt rød test er udløbet: $line"; fi
    done < <(nocr < "$kf")
    while IFS= read -r line; do   # nye linjer: højst 30 dage frem
      [[ "$line" =~ ([0-9]{4}-[0-9]{2}-[0-9]{2})\ *$ ]] && [[ "${BASH_REMATCH[1]}" > "$cap" ]] && fail "$kf: ny udløbsdato ${BASH_REMATCH[1]} er mere end 30 dage frem (højst $cap)"
    done < <(git diff "$b...HEAD" -- "$kf" | grep -E '^\+[^+]' | sed 's/^+//' | nocr || true)
  done
  if [ -z "$app" ] && [ -z "$forced" ]; then [ $err = 0 ] && echo "CI: ingen app-ændringer udover flag — OK"; return $err; fi
  local id; id=$(sed -nE "s#^(feature|hotfix)/($IDR)-.*#\\2#p" <<< "$head")
  [ -n "$id" ] || { fail "branchen skal hedde feature/F###-… eller hotfix/H###-… (fik: '$head')"; return 1; }
  # Fragment
  if [ -n "$app" ]; then
    local nf; nf=$(grep -E "^changes/${id}(-[0-9]+)?\.md$" <<< "$added" || true)
    if [ -z "$nf" ]; then fail "mangler et NYT changes/${id}.md (eller -2.md …) i PR'en"
    else   # 5.28: arbejdets art (ITIL-ændringstype) — grundlaget for 'report.sh fordeling' og 'forbrug'
      local ty; ty=$(cat $nf | nocr | sed -nE 's/^\**Type:\** *([^ ]+).*/\1/p' | sed -n 1p)
      case "$ty" in
        feature|fejl|hotfix|gæld|gaeld|sikkerhed) ;;
        *) fail "$nf mangler linjen 'Type: feature|fejl|hotfix|gæld|sikkerhed' (fik: '${ty:-ingen}')" ;;
      esac
      if [ "$ty" = hotfix ]; then
        cat $nf | nocr | gq -iE '^\**Hvorfor nu:\** *[^ ]' || fail "$nf: Type: hotfix kræver linjen 'Hvorfor nu: <hvorfor den ikke kunne vente til næste release>'"
      fi
    fi
    if [ -n "$nf" ] && [[ "$id" == F* ]]; then
      for h in Kunde Support; do
        cat $nf | nocr | gq -E "^\**$h:\** *[^ ]" || fail "$nf mangler linjen '$h: …' (én sætning til ${h,,})"
      done
    fi
  fi
  # Procesfiler skal være i git (i PR'en eller på main) — LIFECYCLE.md §11
  has() { [ -n "$(git ls-tree -r --name-only HEAD -- "$@" 2>/dev/null)" ]; }
  hasglob() { git ls-tree -r --name-only HEAD 2>/dev/null | gq -E "$1"; }
  local tier=2 imp="tasks/impact-$id.md"
  has "launched/$id/impact.md" && imp="launched/$id/impact.md"
  if has "$imp"; then
    tier=$(git show "HEAD:$imp" | nocr | grep -oiE '^[#* -]*tier:?\**:? *[123]' | grep -oE '[123]$' | sed -n 1p || true)
    [ -n "$tier" ] || { fail "$imp mangler linjen 'Tier: 1|2|3'"; tier=2; }
  else fail "mangler $imp i git (i PR'en eller på main)"; fi
  [ -n "$forced" ] && tier=1
  hasglob "^(fullstack-ai/${id}_v[1-9][0-9]*\.md|launched/$id/.*fullstack.*)$" || later "mangler engineer-rapport fullstack-ai/${id}_vN.md (N ≥ 1) i git"
  # Undtaget fra forståelse (v0) og regressionstest: revert (nødvejen) og framework-installation
  local plain=1; [[ "$head" =~ -(revert|framework)- ]] && plain=""
  if [ "$tier" -le 2 ]; then
    hasglob "^(product-ai/${id}_v[0-9]+\.md|launched/$id/.*spec.*)$" || fail "mangler spec product-ai/${id}_vN.md i git (tier $tier)"
    if [[ "$id" == F* ]] && [ -n "$app" ]; then
      local mis; mis=$(ak_missing "$id" HEAD)
      case "$mis" in
        NOSPEC) ;;
        NOAK) fail "specen for $id har ingen acceptkriterier (linjer '- AK1: …')" ;;
        "") ;;
        *) fail "$id: acceptkriterier uden test, der nævner '$id-AKn': $mis" ;;
      esac
    fi
    # System understanding FØR koden (5.22): engineeren committer fullstack-ai/<ID>_v0.md som branchens første
    # commit og stopper; Team Lead læser den, før der kodes. Kilder skal være filer, der findes.
    if [ -n "$app" ] && [ -n "$plain" ]; then
      local v0="fullstack-ai/${id}_v0.md" v0c refs=0 tok
      if ! has "$v0"; then fail "tier $tier: mangler $v0 (System understanding, committet før koden — agents/fullstack_engineer.md)"
      else
        git show "HEAD:$v0" | nocr | gq -iE '^#+ *System understanding' || fail "$v0 mangler overskriften '# System understanding'"
        for tok in $(git show "HEAD:$v0" | nocr | grep -oE '[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+' | sort -u); do
          git cat-file -e "HEAD:$tok" 2>/dev/null && refs=$((refs+1))
        done
        [ "$refs" -ge 2 ] || fail "$v0 henviser kun til $refs fil(er), der findes i repoet — hver påstand skal have en kilde (mindst 2 filstier)"
        # Rækkefølge: kun på PR'en (på main er alt én squash-commit)
        if [ -z "${PR_NUMBER:-}" ]; then
          v0c=$(git log --format=%H --diff-filter=A "$b..HEAD" -- "$v0" | tail -1)
          if [ -n "$v0c" ] && [ -n "$(git log --format=%H "$b..$v0c" -- homedir/ ':(exclude)homedir/config/features.ini' | sed -n 1p)" ]; then
            fail "$v0 er committet EFTER kode i homedir/ — System understanding skal være branchens første commit, før der kodes"
          fi
        fi
      fi
    fi
  fi
  # Critics: tier 1 begge; tier 2 mindst én (5.22). Hver review skal være APPROVED, gælde denne branch og den
  # aktuelle kode (patch-id). Accepterede MEDIUM/LOW-sikkerhedsfund skal stå i DECISIONS med Udløber.
  local c lastf need=0 got=0
  [ "$tier" = 1 ] && need=2
  [ "$tier" = 2 ] && [ -n "$app" ] && need=1
  if [ "$need" -gt 0 ]; then
    for c in qa-critic security-critic; do
      lastf=$(git ls-tree -r --name-only HEAD 2>/dev/null | grep -E "^($c/${id}_v[0-9]+\.md|launched/$id/$c[^/]*\.md)$" | sort -V | tail -1 || true)
      if [ -z "$lastf" ]; then [ "$need" = 2 ] && later "tier 1: mangler $c/${id}_vN.md i git"; continue; fi
      got=$((got+1))
      if ! git show "HEAD:$lastf" | nocr | gq -iE '^[#* -]*status:?\**:? *\**APPROVED'; then fail "tier $tier: $lastf har ikke Status: APPROVED"
      elif ! git show "HEAD:$lastf" | nocr | gq -E "^[#* -]*Branch:[* \`]*$head([\` @]|$)"; then fail "tier $tier: $lastf gælder ikke denne branch (mangler linjen 'Branch: $head') — en anden PR's review tæller ikke"
      else
        local rs; rs=$(git show "HEAD:$lastf" | nocr | grep -m1 -oE "^[#* -]*Branch:[^@]*@ *\`?[0-9a-f]{7,40}" | grep -oE '[0-9a-f]{7,40}$' || true)
        if [ -z "$rs" ]; then fail "tier $tier: $lastf mangler '@ <commit-SHA>' i Branch-linjen"
        elif ! git cat-file -e "$rs^{commit}" 2>/dev/null; then fail "tier $tier: $lastf: den reviewede commit $rs findes ikke i repoet (er branchen slettet? cron/ci.sh henter refs/pull/*/head fra 5.18)"
        elif [ "$(git diff "$b...$rs" -- homedir/ ':(exclude)homedir/config/features.ini' | git patch-id --stable | cut -d' ' -f1)" != \
               "$(git diff "$b...HEAD" -- homedir/ ':(exclude)homedir/config/features.ini' | git patch-id --stable | cut -d' ' -f1)" ]; then
          fail "tier $tier: koden er ændret efter $c reviewede $rs — ny critic-review af den aktuelle commit"
        fi
      fi
      # 5.34 (Storix): ét DECISIONS-afsnit PR FUND, ikke ét pr. ID — tre LOW-fund kræver tre accepterede risici
      local nf=0 nd=0
      [ "$c" = security-critic ] && nf=$(git show "HEAD:$lastf" | nocr | awk '
            tolower($0) ~ /^#+ *severity/ {s=1; next} s && NF {if (toupper($0) ~ /^[#* -]*(MEDIUM|LOW)/) f++; s=0; next}
            toupper($0) ~ /SEVERITY:?\**:? *\**(MEDIUM|LOW)/ {f++} END {print f+0}' || true)
      if [ "${nf:-0}" -gt 0 ]; then
        nd=$(git show "HEAD:$DECISIONS" 2>/dev/null | nocr | awk -v id="$id" '
            function done() { if (sec && hid && risk && ex) n++ }
            /^## #[0-9]+/ {done(); sec=1; hid=0; risk=0; ex=0} sec {if (index($0, id)) hid=1; if (tolower($0) ~ /accepteret risiko/) risk=1; if ($0 ~ /Udløber:? *[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/) ex=1}
            END {done(); print n+0}' || true)
        [ "${nd:-0}" -ge "$nf" ] \
          || fail "$lastf godkender med $nf MEDIUM/LOW-fund, men $DECISIONS har $nd accepteret risiko for $id — hvert fund sit eget afsnit: '## #N', '$id', 'Accepteret risiko' og 'Udløber: ÅÅÅÅ-MM-DD'"
      fi
    done
    [ "$need" = 1 ] && [ "$got" = 0 ] && later "tier 2: mangler én critic-review — security-critic/${id}_vN.md (data, input, adgang) eller qa-critic/${id}_vN.md"
  fi
  # Hotfix = en fejl, der kom igennem alle kontroller: kræv en test, der genskaber den (5.22)
  if [[ "$id" == H* ]] && [ "$tier" -le 2 ] && [ -n "$plain" ] && [ -n "$(grep -vE '^homedir/(tests/|config/features\.ini$)' <<< "$app" || true)" ]; then
    if ! grep -qE '^(homedir/)?tests/' <<< "$(git diff --name-only --diff-filter=AM "$b...HEAD")"; then
      local nt; nt=$(grep -E "^changes/${id}(-[0-9]+)?\.md$" <<< "$added" || true)
      { [ -n "$nt" ] && cat $nt | nocr | gq -iE '^\**Ingen test:\** *[^ ]'; } \
        || fail "hotfix $id ændrer koden uden en ny eller ændret test i tests/ eller homedir/tests/ — tilføj en test, der genskaber fejlen, eller linjen 'Ingen test: <grund>' i changes/${id}.md"
    fi
  fi
  # F-ID skal stå i ROADMAP
  if [[ "$id" == F* ]]; then
    git show "HEAD:$ROADMAP" 2>/dev/null | gq -E "(^|[^0-9A-Za-z])$id([^0-9A-Za-z]|$)" || fail "$id står ikke i ROADMAP.md"
  fi
  # Henvisninger til DECISIONS #N skal findes
  local n fr
  for n in $( { git log --format='%B' "$b..HEAD"; for fr in $(grep '^changes/' <<< "$added" || true); do cat "$fr"; done; } | grep -oE 'DECISIONS #[0-9]+' | grep -oE '[0-9]+' | sort -u || true); do
    git show "HEAD:$DECISIONS" 2>/dev/null | gq -E "^(## #$n( |$)|\| *#?$n *\|)" || fail "henviser til DECISIONS #$n, som ikke findes i $DECISIONS"
  done
  [ $err = 0 ] && echo "CI: $id (tier $tier) — fragment, procesfiler og headers OK"
  return $err
}

# ---------- .gitignore: tilføj frameworkets manglende linjer (kun tilføj, aldrig fjern) ----------
cmd_gitignore() {
  touch .gitignore
  [ -z "$(tail -c1 .gitignore)" ] || echo >> .gitignore
  local miss; miss=$(nocr < shared/gitignore.framework | grep -vE '^#|^$' | grep -vxF -f <(nocr < .gitignore) || true)
  if [ -n "$miss" ]; then printf '%s\n' "$miss" >> .gitignore; echo "Tilføjet til .gitignore: $(grep -c . <<< "$miss") linjer"; else echo ".gitignore indeholder allerede alle frameworkets linjer"; fi
  [ "$(git check-ignore --no-index homedir/config/config.ini .claude/settings.local.json .claude/worktrees/x | wc -l)" = 3 ] && echo "check-ignore: OK" || echo "check-ignore: FEJL — beskyttelsen virker ikke"
}

# ---------- Omfang: skal CI køre de tunge trin (PHP, PHPStan, Semgrep, migrationer, tests)? ----------
# Kun når koden, testene eller kontrolfilerne er ændret siden BASE_SHA. Ellers (rene dokument-/procesændringer)
# kører kun framework-kontrollerne og gitleaks. (cron/ci.sh har sin egen kopi af reglen + genbrug af grønne træer.)
cmd_scope() {
  local b=${BASE_SHA:-}
  if [ -z "$b" ] || ! git cat-file -e "$b^{commit}" 2>/dev/null; then echo true; return 0; fi
  if git diff --name-only "$b" HEAD | gq -E "^(homedir/|tests/|$CTRL_FILES)"; then echo true; else echo false; fi
}

# Testresultat som cron/ci.sh (trin "tests"): rød, hvis en test-case er rød/sprunget over og ikke står i
# known-failures, hvis der er 0 tests, eller hvis løberen fejlede uden en eneste rød test. selftest kræver, at
# PHP-koden herunder er tegn for tegn den samme som i shared/install/ci.sh.
junit_verdict() {   # $1=php $2=testløberens exit-kode $3=known-failures-fil; læser ./junit.xml
  "$1" -r '
      $x = simplexml_load_file("junit.xml") ?: exit(1); $red = []; $known = [];
      foreach ($x->xpath("//testcase[failure or error or skipped]") as $t) $red[] = ((string) $t["classname"] ?: (string) $t["class"]) . "::" . $t["name"];
      foreach (@file($argv[2]) ?: [] as $l) { $l = trim(explode("#", $l)[0]); if ($l !== "") $known[] = trim(explode("|", $l)[0]); }
      $new = array_diff($red, $known); $total = count($x->xpath("//testcase"));
      echo "$total tests, " . count($red) . " røde/sprunget over, " . count($new) . " nye\n";
      foreach ($new as $n) echo "Ny rød eller sprunget over: $n\n";
      exit(($total === 0 || $new || ($argv[1] !== "0" && !$red)) ? 1 : 0);' "$2" "$3"
}

# ---------- Lokal forhåndskontrol (gratis). Engineeren kører den i sin worktree ----------
cmd_precheck() {
  local br rc=0 php tests
  br=$(git branch --show-current); [ -n "$br" ] || die "ingen branch (kør i engineerens worktree)"
  local acc; if ! acc=$(cmd_access 2>&1); then echo "$acc"
    echo "PRECHECK: RØD — ingen GitHub-adgang, ikke en kodefejl ($(git rev-parse --short HEAD))"; return 1; fi
  echo "== Framework-kontroller (som CI) for $br"
  CW_PRE_REVIEW=1 BASE_REF=main HEAD_REF="$br" bash "$0" ci || rc=1
  # Hurtig og lokal (5.26): kun det, der kan afgøres på engineerens pc. Tests og fuld lint kører på DEV (coworker/pr) —
  # der er ingen database på Windows, så lokale tests var altid røde og kostede 6–11 min pr. kørsel (Storix H023).
  php=$(command -v php || true)
  if [ -n "$php" ]; then
    local ch; ch=$(git diff --name-only --diff-filter=AM origin/main...HEAD -- 'homedir/*.php' 2>/dev/null || true)
    echo "== PHP lint ($(grep -c . <<< "$ch" || true) ændrede filer)"
    [ -z "$ch" ] || { tr '\n' '\0' <<< "$ch" | xargs -0 -r -n1 php -l > /dev/null || rc=1; }
  else echo "== PHP findes ikke lokalt: lint er IKKE kørt (DEV-serveren linter hele koden: status coworker/pr)"; fi
  tests=$(ini_get ci tests)
  if [ -n "$tests" ] && [ "${PRECHECK_TESTS:-}" = 1 ] && [ -n "$php" ]; then   # kun på opfordring, dømt som cron/ci.sh
    echo "== Tests: $tests"; local trc=0 kf=tests/known-failures.txt; [ -f "$kf" ] || kf=homedir/tests/known-failures.txt
    rm -f junit.xml; bash -c "$tests" || trc=$?
    if [ ! -s junit.xml ]; then echo "Testløberen skrev ingen junit.xml (exit $trc) — se [ci] tests i coworker.ini"; rc=1
    else junit_verdict "$php" "$trc" "$kf" || rc=1; fi
  else echo "== Tests køres på DEV-serveren (status coworker/pr), ikke her. Lokalt kun på opfordring: PRECHECK_TESTS=1"; fi
  if [ $rc = 0 ]; then echo "PRECHECK: GRØN ($(git rev-parse --short HEAD))"; else echo "PRECHECK: RØD ($(git rev-parse --short HEAD))"; fi
  return $rc
}

# ---------- Er koden testet på DEV? (5.35) Status "coworker/kode" = alle CI-trin undtagen review-kravene ----------
# Engineeren kører den efter push, FØR rapporten; Team Lead starter critics først på KODE: GRØN. Så findes fejl i
# tests og migrationer én gang, ikke efter hver review-runde. Venter højst KODE_MAX_MIN (9) min pr. kald.
cmd_kode() {
  local br=${1:-} sha s st t=0 max=${KODE_MAX_MIN:-9} poll=${KODE_POLL:-30}
  [ -n "$br" ] || br=$(git branch --show-current)
  [ -n "$br" ] || die "brug: kode <branch>"
  git fetch -q origin "$br" 2>/dev/null || { no_access "git fetch fejler"; return 1; }
  sha=$(git rev-parse "origin/$br")
  while :; do
    s=$($GH api "repos/{owner}/{repo}/commits/$sha/statuses?per_page=100" \
          --jq '[.[] | select(.context=="coworker/kode")][0] | "\(.state)\t\(.description)"' 2>/dev/null | nocr || true)
    st=${s%%$'\t'*}
    case "$st" in
      success) echo "KODE: GRØN på DEV (${sha:0:7}) — tests, migrationer, lint og PHPStan er grønne. Critics kan starte"; return 0 ;;
      failure|error) echo "KODE: RØD på DEV (${sha:0:7}): ${s#*$'\t'}"
                     echo "Læs DEV's kommentar: gh pr view $br --comments — ret, push, og kør kommandoen igen"; return 1 ;;
    esac
    if [ "$t" -ge $(( max * 60 / (poll > 0 ? poll : 1) )) ]; then
      echo "KODE: VENTER (${sha:0:7}) — ingen status fra DEV endnu (kører eller i kø). Kør kommandoen igen."
      echo "Efter 30 min uden status: er PR'en åbnet (ikke draft)? bash shared/report.sh check viser, om CI kører på DEV."
      return 2
    fi
    sleep "$poll"; t=$((t+1))
  done
}

# ---------- Skal PR'en opdateres med main? Kun ved konflikt, eller når main har ændret kode/tests/kontrolfiler (5.26) ----------
# Hver opdatering er en ny commit = en ny CI-kørsel. main-commits efter merge testes alligevel af coworker/ci.
cmd_behov_opdatering() {
  local br=${1:-} mb mt rc=0
  [ -n "$br" ] || die "brug: behov-opdatering <branch>"
  git fetch -q origin main "$br" 2>/dev/null || { no_access "git fetch fejler"; return 1; }
  mb=$(git merge-base origin/main "origin/$br") || die "ingen fælles historik med main"
  mt=$(git merge-tree --write-tree origin/main "origin/$br" 2>/dev/null) || rc=$?
  if [ "$rc" = 1 ]; then echo "OPDATÉR: konflikt med main — engineeren kører git merge origin/main og retter"; return 0; fi
  if [ "$rc" != 0 ]; then echo "OPDATÉR: kunne ikke afgøre konflikt (git $(git --version | cut -d' ' -f3)) — gh pr update-branch $br"; return 0; fi
  local ch; ch=$(git diff --name-only "$mb" origin/main | grep -E "^(homedir/|tests/|$CTRL_FILES)" || true)
  if [ -n "$ch" ]; then echo "OPDATÉR: main har ændret kode/tests/kontrolfiler siden branchen ($(grep -c . <<< "$ch") filer) — gh pr update-branch $br"
  else echo "IKKE NØDVENDIGT: main har kun ændret dokumentation siden branchen — merge uden opdatering"; fi
}

# ---------- DEV-rollback: DEV følger main, medmindre tagget "dev" låser den til en tidligere main-commit ----------
dev_running() { local u b; u=$(health_url dev); [ -n "$u" ] || return 0; b=$(curl -fsS -m 10 "$u" 2>/dev/null || true); json_field "$b" sha; }
dev_pin() { git ls-remote -q origin refs/tags/dev 2>/dev/null | cut -f1 | sed -n 1p; }
cmd_devlog() {
  local n=${1:-15} run pin sha d subj mark
  fetch; run=$(dev_running); pin=$(dev_pin)
  [ -n "$pin" ] && pin=$(git rev-parse "$pin^{commit}" 2>/dev/null || echo "$pin")
  echo "# DEV-versioner (nyeste først). DEV følger ${pin:+IKKE }main${pin:+ — låst til ${pin:0:7}}"
  while IFS=$'\t' read -r sha d subj; do
    mark=""; [ "$sha" = "$run" ] && mark+=" ← kører i DEV"; [ "$sha" = "$pin" ] && mark+=" ← DEV låst hertil"
    echo "${sha:0:7}  $d  $subj$mark"
  done < <(git log --first-parent -n "$n" --format='%H%x09%ad%x09%s' --date=format:'%Y-%m-%d %H:%M' origin/main)
  [ -n "$run" ] || echo "(DEV's /health svarer ikke eller [health] dev mangler i coworker.ini — kørende version ukendt)"
}
cmd_devpin() {
  local a=${1:-} sha id c j n m down
  [ -n "$a" ] || die "brug: devpin <sha> | devpin før <ID> | devpin off"
  fetch
  if [ "$a" = off ]; then
    git push -q origin :refs/tags/dev 2>/dev/null || true; git tag -d dev >/dev/null 2>&1 || true
    echo "DEV følger main igen ($(git rev-parse --short origin/main)). Serveren skifter ved næste deploy-kørsel."; return 0
  fi
  if [ "$a" = før ] || [ "$a" = for ]; then
    id=${2:-}; [[ "$id" =~ ^$IDR$ ]] || die "brug: devpin før F012"
    c=$(git log --first-parent --reverse --format=%H --grep="^$id:" origin/main | sed -n 1p)
    [ -n "$c" ] || die "$id har ingen commit på main (git log --grep '^$id:')"
    sha=$(git rev-parse "$c^")
  else sha=$(git rev-parse -q --verify "$a^{commit}" 2>/dev/null) || die "ukendt commit $a"; fi
  git merge-base --is-ancestor "$sha" origin/main || die "${sha:0:7} er ikke på main — DEV kan kun gå tilbage til main-versioner"
  j=$(ci_state "$sha")
  [ "$j" = success ] || $GH api "repos/{owner}/{repo}/actions/workflows/ci.yml/runs?head_sha=$sha&event=push&branch=main&status=success&per_page=1" --jq .total_count 2>/dev/null | nocr | gq -xE "[1-9][0-9]*" || die "${sha:0:7} har ingen grøn CI-kørsel på main — deploy.sh ville ikke tage den. Vælg en anden i 'report.sh devlog'"
  echo "# DEV låses til ${sha:0:7}: $(git log -1 --format=%s "$sha")"
  echo "Fjernes fra DEV (nyere end den):"; git log --first-parent --format='  - %h %s' "$sha..origin/main"
  m=$(git diff --name-only --diff-filter=A "$sha" origin/main -- homedir/migrations/ | grep -v '/down/' | sort -r || true)
  if [ -n "$m" ]; then
    echo "Databasen rulles IKKE tilbage. Migrationer, der bliver stående i DEV's database (nyeste først):"
    for f in $m; do down="homedir/migrations/down/$(basename "$f")"
      if git cat-file -e "origin/main:$down" 2>/dev/null; then echo "  - $(basename "$f") (down-fil findes: $down)"; else echo "  - $(basename "$f") (ingen down-fil)"; fi
    done
  fi
  git diff --quiet "$sha" origin/main -- $(git ls-tree -r --name-only origin/main | grep -E "^$CTRL_FILES" || true) 2>/dev/null \
    || echo "Kontrolfiler er forskellige: DEV tager dem uden Publish, hvis de har kørt på DEV før; ellers mailer serveren og venter."
  git tag -f dev "$sha" >/dev/null
  git push -q origin :refs/tags/dev 2>/dev/null || true
  git push -q origin refs/tags/dev || die "kunne ikke skubbe tagget dev"
  echo "DEV ER LÅST til ${sha:0:7}. Serveren skifter ved næste deploy-kørsel (se 'report.sh devlog'). Følg main igen: report.sh devpin off"
}

# ---------- Installation af frameworkets driftsfiler (uændret kopi; projektets værdier i coworker.ini) ----------
cmd_install() {
  local pair src dst
  for pair in $INSTALL_MAP; do
    src=${pair%%:*}; dst=${pair#*:}
    [ -f "$src" ] || continue
    case "$dst" in homedir/cron/*) [ -d homedir ] || continue ;; esac
    # 5.27: backup er maskinens (site-backup). backup.sh installeres kun som opdatering, hvor projektet allerede har den
    case "$dst" in homedir/cron/backup.sh) [ -f "$dst" ] || continue ;; esac
    mkdir -p "$(dirname "$dst")"; cp "$src" "$dst"
    case "$dst" in *.sh) chmod +x "$dst"   # Windows (core.filemode=false) gemmer ikke chmod: sæt x-bit i git selv (5.21)
      git add -- "$dst" 2>/dev/null && git update-index --chmod=+x -- "$dst" 2>/dev/null || true ;; esac
    echo "installeret: $dst"
  done
  for dst in .github/workflows/ci.yml .github/workflows/monitor.yml; do   # GitHub Actions er erstattet af cron/ci.sh og cron/monitor.sh
    [ -f "$dst" ] && { git rm -q "$dst" 2>/dev/null || rm -f "$dst"; echo "fjernet: $dst (CI og overvågning kører nu på serveren)"; }
  done
  if [ ! -f coworker.ini ]; then cp shared/templates/coworker.ini.template coworker.ini; echo "oprettet: coworker.ini — udfyld værdierne (projektets PHP, testkommando, repo, health-URL'er)"; fi
  echo "Commit sammen med framework-kopien på hotfix/H###-framework-<version> (tier 1). Serverne tager det først ind efter ejerens Publish (bash shared/report.sh ctrl <sha>)."
}

# ---------- Kontrolændring: udkast, som ejeren godkender med Publish (pre-release "Kontrol: …") ----------
cmd_ctrl() {
  local t=${1:-}; [ -n "$t" ] || die "brug: ctrl <sha>"
  fetch; git cat-file -e "$t^{commit}" 2>/dev/null || die "ukendt commit $t"
  local base n tag f
  base=$($GH api 'repos/{owner}/{repo}/releases?per_page=30' --jq '[.[] | select(.draft|not) | select(.published_at != null)] | sort_by(.published_at) | last | .tag_name // empty' 2>/dev/null | nocr || true)
  [ -n "$base" ] && git fetch -q origin "+refs/tags/$base:refs/tags/$base" 2>/dev/null || true
  n=1; while [ -e "launched/releases/ctrl-$(date +%Y.%m.%d)-$n.md" ]; do n=$((n+1)); done
  tag="ctrl-$(date +%Y.%m.%d)-$n"; f="launched/releases/$tag.md"; mkdir -p launched/releases
  {
    echo "# $tag — kontrolændring til godkendelse"
    echo "Target: $(git rev-parse --short "$t") · Sammenlignet med: ${base:-(intet godkendt endnu)}"
    echo "Når du trykker Publish, må serverne (DEV/TEST) køre disse kontrolfiler. STABLE følger stadig kun releases."
    echo "## Ændrede kontrolfiler"
    git diff --stat "${base:-$EMPTY}" "$t" -- $(git ls-tree -r --name-only "$t" | grep -E "^$CTRL_FILES" || true) | sed 's/^/    /'
    echo "## Ændringer (uddrag)"
    echo '```diff'
    git diff "${base:-$EMPTY}" "$t" -- $(git ls-tree -r --name-only "$t" | grep -E "^$CTRL_FILES" || true) | sed -n '1,400p'
    echo '```'
  } > "$f"
  echo "$f"
  echo "Næste: gh release create $tag --draft --prerelease --target $(git rev-parse "$t") --title \"Kontrol: <kort beskrivelse>\" --notes-file $f"
}

# ---------- PHPStan lokalt (5.36, econsync): SAMME sha256-låste phar som CI, version og hash læses fra ci.sh ----------
cmd_phpstan() {
  local ci=shared/install/ci.sh v h d p a f
  v=$(sed -n 's/^PHPSTAN_V=\([^;]*\);.*/\1/p' "$ci"); h=$(sed -n 's/^PHPSTAN_V=[^;]*; *PHPSTAN_SHA=\([0-9a-f]\{64\}\).*/\1/p' "$ci")
  [ -n "$v" ] && [ -n "$h" ] || die "kan ikke læse PHPStan-version og hash fra $ci"
  d="${XDG_CACHE_HOME:-$HOME/.cache}/coworker/phpstan-$v"; p="$d/phpstan.phar"   # fast sti uden for repoet, deles af projekterne
  if ! echo "$h  $p" | sha256sum -c - >/dev/null 2>&1; then
    mkdir -p "$d"
    curl -fsSL -o "$p.new" "https://github.com/phpstan/phpstan/releases/download/$v/phpstan.phar" || { rm -f "$p.new"; die "PHPStan $v: download fejlede"; }
    echo "$h  $p.new" | sha256sum -c - >/dev/null 2>&1 || { rm -f "$p.new"; die "PHPStan $v: checksum passer ikke — filen er slettet"; }
    mv -f "$p.new" "$p"
  fi
  echo "PHPStan $v (sha256-låst, samme som CI): $p"
  [ "${1:-}" = path ] && return 0
  command -v php >/dev/null 2>&1 || die "php findes ikke her — brug stien ovenfor med en php (CI kører: php -d memory_limit=1G <phar> analyse --level=5)"
  a=$(nocr < coworker.ini 2>/dev/null | cwini ci analyse || true); [ -n "$a" ] || die "coworker.ini mangler [ci] analyse"
  mkdir -p "$d/tmp"; { printf 'parameters:\n\ttmpDir: %s\n' "$d/tmp"   # som CI: baseline, ellers projektets egen config
    for f in phpstan-baseline.neon phpstan.neon phpstan.neon.dist; do if [ -f "$f" ]; then printf 'includes:\n\t- %s\n' "$PWD/$f"; break; fi; done; } > "$d/local.neon"
  # shellcheck disable=SC2086  # [ci] analyse er en liste af stier, som i CI
  php -d memory_limit=1G "$p" analyse --level=5 --no-progress -c "$d/local.neon" $a
}

# ---------- MariaDB: ord, der er blevet reserveret EFTER 10.11 (5.36, econsync) ----------
# DEV/CI kører typisk 10.11; en uciteret kolonne med et af disse navne bygger der, men en tom base på 12.3 fejler med
# 1064. Kilde: mariadb.com/docs … reserved-words (+ MDEV-40693). Ord, der var reserveret i 10.11, fanger CI i forvejen.
MARIADB_NEW_RESERVED='to_date conversion current_path vector sql_after_gtids sql_before_gtids'
sql_reserved() {   # $1 = .sql-fil -> "linje N: ord" for hvert uciteret brug (ikke i strenge/kommentarer, ikke efter . eller før ( )
  nocr < "$1" | awk -v words="$MARIADB_NEW_RESERVED" '
    BEGIN { n = split(words, w, " "); for (i = 1; i <= n; i++) R[w[i]] = 1; st = "" }
    { line = $0; out = ""; i = 1; L = length(line)
      while (i <= L) { c = substr(line, i, 1); d = substr(line, i, 2)
        if (st == "/*") { if (d == "*/") { st = ""; i += 2 } else i++; out = out " "; continue }
        if (st != "") { if (c == "\\") { i += 2; out = out "  "; continue }; if (c == st) st = ""; out = out " "; i++; continue }
        if (d == "/*") { st = "/*"; i += 2; out = out "  "; continue }
        if (d == "--" || c == "#") break
        if (c == "\047" || c == "\"" || c == "`") { st = c; out = out " "; i++; continue }
        out = out c; i++ }
      t = tolower(out)
      while (match(t, /[a-z_][a-z0-9_$]*/)) { wd = substr(t, RSTART, RLENGTH); pre = substr(t, RSTART - 1, 1); rest = substr(t, RSTART + RLENGTH)
        sub(/^[ \t]+/, "", rest)
        if ((wd in R) && pre != "." && substr(rest, 1, 1) != "(" && substr(rest, 1, 1) != ".") print "linje " NR ": " wd
        t = substr(t, RSTART + RLENGTH) } }'
}

# ---------- Hemmeligheder: Team Lead kører den før hver commit (læser kun, viser kun filnavn + linjenr.) ----------
cmd_secrets() {
  local files f hit=0
  files=$( { git diff --cached --name-only --diff-filter=AM; git ls-files --others --exclude-standard; } | sort -u)
  [ -n "$files" ] || { echo "Intet at scanne"; return 0; }
  if command -v gitleaks >/dev/null 2>&1; then gitleaks protect --staged --redact --no-banner || hit=1; fi
  # 5.36 (econsync): frameworkets låste filer og deres installerede kopier (homedir/cron/*.sh) er byte-identiske med en
  # hash i framework.lock — de er masterens tekst (kommentarer om github.token, testfixtures), ikke projektets hemmeligheder.
  local fwh=""; [ -f "$FW_LOCK" ] && fwh=$(nocr < "$FW_LOCK" | grep -vE '^#|^$' | cut -d' ' -f1)
  # 5.42: en LINJE, der — uden diff-markør (+, -, mellemrum) — står ordret i en låst framework-fil, er også masterens tekst.
  # Fx kommentaren om config/github.token i ci.sh, som launched/releases/ctrl-*.md gengiver i sin diff. Kun filer, hvis hash
  # stadig passer med framework.lock, tæller; rigtige hemmeligheder står aldrig ordret i masteren.
  local fwl="" h p m n l out
  if [ -f "$FW_LOCK" ]; then
    fwl=$(mktemp)
    while read -r h p; do
      case "$h" in '#'*|'') continue ;; esac
      [ -f "$p" ] && [ "$(sha_file "$p")" = "$h" ] && nocr < "$p"
    done < <(nocr < "$FW_LOCK") > "$fwl" || true
  fi
  while IFS= read -r f; do
    [ -f "$f" ] || continue
    [ -n "$fwh" ] && grep -qxF "$(sha_file "$f")" <<< "$fwh" && continue
    out=$(grep -nIE -- '-----BEGIN [A-Z ]*PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,}|(pass(word)?|secret|token|api_?key)[ \t]*[=:][ \t]*["'"'"']?[^ \t"'"'"'<>{}$]{8,}' "$f" 2>/dev/null \
      | while IFS= read -r m; do
          n=${m%%:*}; l=${m#*:}; l=${l%$'\r'}
          [ -n "$fwl" ] && { grep -qxF -- "$l" "$fwl" || grep -qxF -- "${l#[+ -]}" "$fwl"; } && continue
          echo "MULIG HEMMELIGHED: $f linje $n"
        done || true)
    if [ -n "$out" ]; then echo "$out"; hit=1; fi
  done <<< "$files"
  [ -n "$fwl" ] && rm -f "$fwl"
  [ $hit = 0 ] && echo "Ingen hemmeligheder fundet"
  return $hit
}

# ---------- Frameworkets egen version og låsefil (køres i master af vedligeholderen) ----------
cmd_version() {
  if [ -f shared/VERSION ]; then echo "Coworker Framework $(nocr < shared/VERSION)"; else echo "Coworker Framework (ukendt version)"; fi
  [ -f "$FW_LOCK" ] && head -1 "$FW_LOCK"
}
claude_files() {   # guardens og agenternes filer i _claude/ (master) eller .claude/ — til shared/claude.lock
  local c=$1
  ( cd "$c" && find . -type f ! -name settings.local.json ! -path './worktrees/*' | sed 's|^\./||' | sort )
}
cmd_lock() {   # skriver shared/claude.lock og shared/framework.lock for mappen $1 (default: denne)
  local d=${1:-.} v f c
  v=$(nocr < "$d/shared/VERSION" 2>/dev/null || echo ukendt)
  # 5.28: _claude/ er git-ignoreret i projekterne, så CI kan ikke se den. Dens hashes ligger i shared/claude.lock,
  # som selv er låst af framework.lock. opdater-pc.ps1 og 'report.sh claude' tjekker .claude/ mod den.
  c="$d/_claude"; [ -d "$c" ] || c="$d/.claude"
  if [ -d "$c" ]; then
    { echo "# coworker-framework $v — guard og agenter (_claude/). Genereret $(date +%F). Rettes aldrig i hånden."
      claude_files "$c" | while read -r f; do echo "$(sha_file "$c/$f")  $f"; done
    } > "$d/shared/claude.lock"
  fi
  { echo "# coworker-framework $v — genereret $(date +%F). Rettes aldrig i hånden."
    fw_files "$d" | while read -r f; do echo "$(sha_file "$d/$f")  $f"; done
  } > "$d/$FW_LOCK"
  echo "$d/$FW_LOCK: $(grep -vc '^#' "$d/$FW_LOCK") filer, version $v"
}
cmd_claude() {   # 5.28: .claude/ (guard, agenter, settings.json) er præcis den version, shared/claude.lock siger
  local l=shared/claude.lock h f bad=0
  [ -f "$l" ] || { echo "CLAUDE: shared/claude.lock mangler (framework før 5.28)"; return 0; }
  while read -r h f; do
    case "$h" in '#'*|'') continue ;; esac
    if [ ! -f ".claude/$f" ]; then echo "mangler: .claude/$f"; bad=1
    elif [ "$(sha_file ".claude/$f")" != "$h" ]; then echo "afviger: .claude/$f"; bad=1; fi
  done < <(nocr < "$l")
  if [ $bad = 0 ]; then echo "CLAUDE: OK"
  else echo "CLAUDE: FORÆLDET ELLER ÆNDRET — mennesket kører: powershell -ExecutionPolicy Bypass -File C:\ClaudeCoworkers\coworker-development\shared\opdater-pc.ps1"; fi
}
cmd_lint() {   # frameworkets selvkonsistens: forældede begreber og modsigelser (tomt = OK)
  local d=${1:-.}
  ( cd "$d" && grep -nE 'Product Architect|APPROVED_BY_CRITICS|IN_DEV|Backup = releases|APPROVED_FOR_DEPLOY|Shipped|RELEASE-NOTES|CHANGELOG\.md opdateret|alle 4 trin|## In progress|## Idéer|FILE_VERSION: 5\.([0-9]|1[0-9]|2[0-9]|3[0-4])\b|TILPAS|Læst: [a-zA-Z_]+\.md [0-9]|shared/WORKFLOW\.md' CLAUDE.md OPSAETNING.md agents/*.md shared/*.md shared/*.sh shared/templates/* shared/install/* */README.md launched/*/README.md 2>/dev/null \
      | grep -vE '^(shared/FRAMEWORK-CHANGELOG\.md|shared/report\.sh|shared/selftest\.sh):|tasks/RELEASE-NOTES\.md' || true )
  # Frameworkets åbne huller skal have en frist (5.22) — udløbet = tag beslutningen op igen
  [ -f "$d/shared/AABNE-HULLER.md" ] && nocr < "$d/shared/AABNE-HULLER.md" | awk -v today="$(date +%F)" '
    function done_() { if (h != "") { if (ex == "") print "shared/AABNE-HULLER.md: uden \"Udløber: ÅÅÅÅ-MM-DD\": " h; else if (ex < today) print "shared/AABNE-HULLER.md: udløbet " ex " — luk hullet eller tag beslutningen op igen: " h } }
    /^## / { done_(); h=$0; ex=""; next }
    match($0, /^Udløber:? *[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/) { ex=substr($0, RSTART+RLENGTH-10, 10) }
    END { done_() }'
  return 0
}

# ---------- Overvågning (manuel kørsel med gh; serveren kører cron/monitor.sh) ----------
cmd_monitor() {
  local env url body st problems="" sha rel d inc
  for env in stable test dev; do
    url=$(health_url "$env"); [ -n "$url" ] || continue
    body=$(curl -fsS -m 20 "$url" 2>/dev/null || true)
    if [ -z "$body" ]; then problems+="${env^^}: /health svarer ikke ($url)"$'\n'; continue; fi
    st=$(json_field "$body" status)
    [ "$st" = ok ] || problems+="${env^^}: status='${st:-mangler}'"$'\n'
    [ "$(json_field "$body" db_write)" = ok ] || problems+="${env^^}: databasen kan ikke skrives"$'\n'
    inc=$(json_field "$body" last_incident)
    if [ -n "$inc" ] && [ "$inc" != null ]; then
      [ "$(days_since "${inc:0:10}")" -le 0 ] && problems+="${env^^}: hændelse i dag: $inc"$'\n'
    fi
    if [ "$env" = stable ]; then
      sha=$(json_field "$body" sha)
      rel=$($GH api 'repos/{owner}/{repo}/releases?per_page=30' --jq '[.[] | select(.draft|not) | select(.prerelease|not) | select(.tag_name|startswith("ctrl-")|not) | select(.published_at != null)] | sort_by(.published_at) | last | .tag_name' 2>/dev/null | nocr || true)
      if [ -n "$rel" ] && [ "$rel" != null ]; then
        git fetch -q origin "+refs/tags/$rel:refs/tags/$rel" 2>/dev/null || true
        [ "$sha" = "$(git rev-parse -q --verify "$rel^{commit}" 2>/dev/null)" ] || problems+="STABLE kører ${sha:0:7}, men seneste release er $rel"$'\n'
      fi
      [ -n "$(backup_ext)" ] || { d=$(json_field "$body" backup_last_ok);  [ "$(days_since "$d")" -le 1 ]  || problems+="STABLE: ingen backup det sidste døgn (${d:-mangler})"$'\n'
      d=$(json_field "$body" restore_last_ok); [ "$(days_since "$d")" -le 35 ] || problems+="STABLE: ingen restore-test de sidste 35 dage (${d:-mangler})"$'\n'; }
    fi
  done
  if [ -z "$problems" ]; then echo "Monitor: alt OK"; return 0; fi
  printf '%s' "$problems"
  local title="Hændelse $(date +%F): $(head -1 <<< "$problems" | cut -c1-80)"
  $GH label create incident --color B60205 --description "Automatisk registreret hændelse" --force >/dev/null 2>&1 || true
  if ! $GH issue list --label incident --state open --json title --jq '.[].title' 2>/dev/null | gq -F "$(head -1 <<< "$problems" | cut -c1-80)"; then
    $GH issue create --label incident --title "$title" --body "$(printf 'Registreret af report.sh monitor %s\n\n%s\nLuk sagen, når årsagen er rettet. S1–S2 eller kundedata berørt: incidents/I###.md (report.sh nextid I). Rollback eller fejl i STABLE: final-decisions/H###.md.' "$(date -Is)" "$problems")" >/dev/null || true
  fi
  return 1
}

case "${1:-}" in
  version)  shift; cmd_version "$@" ;;
  lock)     shift; cmd_lock "$@" ;;
  claude)   shift; cmd_claude "$@" ;;
  lint)     shift; cmd_lint "$@" ;;
  monitor)  shift; cmd_monitor "$@" ;;
  status)   shift; cmd_status "$@" ;;
  month)    shift; cmd_month "$@" ;;
  releases) shift; cmd_releases "$@" ;;
  id)       shift; cmd_id "$@" ;;
  roadmap)  shift; cmd_roadmap "$@" ;;
  launchplan) shift; cmd_launchplan "$@" ;;
  fixes)    shift; cmd_fixes "$@" ;;
  fordeling) shift; cmd_fordeling "$@" ;;
  forbrug)  shift; cmd_forbrug "$@" ;;
  spild)    shift; cmd_spild "$@" ;;
  restore)  shift; cmd_restore "$@" ;;
  check)    shift; cmd_check "$@" ;;
  notes)    shift; cmd_notes "$@" ;;
  nextid)   shift; cmd_nextid "$@" ;;
  ci)       shift; cmd_ci "$@" ;;
  secrets)  shift; cmd_secrets "$@" ;;
  install)  shift; cmd_install "$@" ;;
  gitignore) shift; cmd_gitignore "$@" ;;
  ctrl)     shift; cmd_ctrl "$@" ;;
  scope)    shift; cmd_scope "$@" ;;
  precheck) shift; cmd_precheck "$@" ;;
  kode)     shift; cmd_kode "$@" ;;
  phpstan)  shift; cmd_phpstan "$@" ;;
  access)   cmd_access ;;
  behov-opdatering) shift; cmd_behov_opdatering "$@" ;;
  devlog)   shift; cmd_devlog "$@" ;;
  devpin)   shift; cmd_devpin "$@" ;;
  *) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
