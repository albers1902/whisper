#!/bin/bash
# F002: lægger projektets egne cron-linjer i sitets egen crontab (SERVERKONTRAKT §2: "Brugerens egen crontab" er
# projektets). server.sh setup lægger kun frameworkets linjer, så denne gør det for zendesk-sync og transcribe.
# Køres af ejeren én gang som sitets bruger:
#
#   bash ~/cron/whisper-cron.sh
#
# Idempotent: tilføjer kun de linjer, der mangler, og fjerner eller ændrer aldrig andre (samme mønster som server.sh).
# Ingen flock i linjerne: scripterne låser selv (var/locks/), som deploy.sh. Slutter med "CRON: KLAR".
set -uo pipefail

H=$(cd "$(dirname "$0")/.." && pwd)
PHP=/usr/bin/php
[ -x "$PHP" ] || PHP=$(command -v php || true)
[ -n "$PHP" ] || { echo "✘ php findes ikke"; exit 1; }
mkdir -p "$H/logs"

want=(
  "cron/zendesk-sync.php|*/10 * * * * $PHP $H/cron/zendesk-sync.php >> $H/logs/zendesk-sync.log 2>&1"
  "cron/transcribe.php|* * * * * $PHP $H/cron/transcribe.php >> $H/logs/transcribe.log 2>&1"
  "cron/adversus-sync.php|*/10 * * * * $PHP $H/cron/adversus-sync.php >> $H/logs/adversus-sync.log 2>&1"
)
cur=$(crontab -l 2>/dev/null || true)
add=""
for w in "${want[@]}"; do
  k=${w%%|*}; l=${w#*|}
  if grep -F "$H/$k" <<< "$cur" | grep -qv '^[[:space:]]*#'; then echo "✔ cron: $k findes"; else add+="$l"$'\n'; fi
done
if [ -n "$add" ]; then
  { printf '%s\n' "$cur" | sed '/^$/d'; printf '%s' "$add"; } | crontab - || { echo "✘ crontab kunne ikke skrives"; exit 1; }
  printf '%s' "$add" | sed 's/^/✔ cron tilføjet: /'
fi
echo "CRON: KLAR"
