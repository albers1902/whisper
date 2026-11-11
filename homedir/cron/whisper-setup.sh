#!/bin/bash
# F002: transcriptionsmotoren i projektets egen hjemmemappe (DECISIONS #3, SERVERKONTRAKT §2) — ingen root.
# Køres af ejeren som sitets bruger (og igen uden skade, fx efter et modelskift i config.ini):
#
#   bash ~/cron/whisper-setup.sh
#
# Opretter var/whisper/venv (python3 -m venv), installerer faster-whisper i venv'et og henter modellen fra
# [whisper] model i config/config.ini (turbo, standard; eller large-v3) til var/whisper/models.
# Ingen separat ffmpeg: faster-whisper afkoder med PyAV, som har FFmpeg-bibliotekerne med (F002 v0).
# Slutter med "WHISPER: KLAR (<model>)".
[ -e "$(dirname "$0")/../logs/deploying" ] && { echo "En udrulning kører — prøv igen om et par minutter."; exit 0; }
set -euo pipefail

FASTER_WHISPER_V=1.1.1
H=$(cd "$(dirname "$0")/.." && pwd)
cd "$H"
umask 077

model=$(awk -F= '
  /^[[:space:]]*\[/ { s = ($0 ~ /^[[:space:]]*\[whisper\][[:space:]]*$/) }
  s && $1 ~ /^[[:space:]]*model[[:space:]]*$/ { v = $2; gsub(/[[:space:]"]/, "", v); print v; exit }
' config/config.ini 2>/dev/null || true)
model=${model:-turbo}
case "$model" in
  turbo|large-v3) ;;
  *) echo "✘ [whisper] model = $model er ikke tilladt (turbo eller large-v3)"; exit 1 ;;
esac

command -v python3 >/dev/null || { echo "✘ python3 mangler — maskinens sag: spørg sysadmin (SERVERKONTRAKT)"; exit 1; }
mkdir -p var/whisper/models var/whisper/hf
if [ ! -x var/whisper/venv/bin/python ]; then
  python3 -m venv var/whisper/venv || { echo "✘ python3 -m venv fejlede (python3-venv mangler?) — spørg sysadmin"; exit 1; }
fi
var/whisper/venv/bin/pip install -q --disable-pip-version-check "faster-whisper==$FASTER_WHISPER_V"
echo "✔ faster-whisper $FASTER_WHISPER_V i var/whisper/venv"

HF_HOME="$H/var/whisper/hf" var/whisper/venv/bin/python - "$model" "$H/var/whisper/models" <<'PY'
import sys
from faster_whisper import WhisperModel
WhisperModel(sys.argv[1], device="cpu", compute_type="int8", download_root=sys.argv[2])
print("✔ modellen " + sys.argv[1] + " er hentet")
PY
echo "WHISPER: KLAR ($model)"
