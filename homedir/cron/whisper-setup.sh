#!/bin/bash
# F002: transcriptionsmotoren i projektets egen hjemmemappe (DECISIONS #3, SERVERKONTRAKT §2) — ingen root.
# Køres af ejeren som sitets bruger (og igen uden skade, fx efter et modelskift i config.ini):
#
#   bash ~/cron/whisper-setup.sh
#
# Opretter var/whisper/venv (python3 -m venv), installerer de låste pakker fra cron/whisper-requirements.txt (H003:
# præcise versioner og sha256, så pip aldrig vælger en nyere transitiv pakke) og henter modellen fra
# [whisper] model i config/config.ini (turbo, standard; eller large-v3) til var/whisper/models på en låst revision.
# Ingen separat ffmpeg: faster-whisper afkoder med PyAV, som har FFmpeg-bibliotekerne med (F002 v0).
# Slutter med "WHISPER: KLAR (<model>)".
[ -e "$(dirname "$0")/../logs/deploying" ] && { echo "En udrulning kører — prøv igen om et par minutter."; exit 0; }
set -euo pipefail

H=$(cd "$(dirname "$0")/.." && pwd)
cd "$H"
umask 077
LOCK=cron/whisper-requirements.txt
VENV=var/whisper/venv
PYV=3.12   # låsefilens hashes er til cp312-wheels

model=$(awk -F= '
  /^[[:space:]]*\[/ { s = ($0 ~ /^[[:space:]]*\[whisper\][[:space:]]*$/) }
  s && $1 ~ /^[[:space:]]*model[[:space:]]*$/ { v = $2; gsub(/[[:space:]"]/, "", v); print v; exit }
' config/config.ini 2>/dev/null || true)
model=${model:-turbo}
# Hugging Face-commit for modellens repo (faster-whisper 1.2.1 _MODELS). Ny revision = nyt H-ID.
case "$model" in
  turbo)    rev=0a363e9161cbc7ed1431c9597a8ceaf0c4f78fcf ;;   # mobiuslabsgmbh/faster-whisper-large-v3-turbo
  large-v3) rev=edaa852ec7e145841d8ffdb056a99866b5f0a478 ;;   # Systran/faster-whisper-large-v3
  *) echo "✘ [whisper] model = $model er ikke tilladt (turbo eller large-v3)"; exit 1 ;;
esac

command -v python3 >/dev/null || { echo "✘ python3 mangler — maskinens sag: spørg sysadmin (SERVERKONTRAKT)"; exit 1; }
pyver() { "$1" -c 'import sys; print("%d.%d" % sys.version_info[:2])' 2>/dev/null || true; }
[ "$(pyver python3)" = "$PYV" ] || { echo "✘ python3 er $(pyver python3), låsefilen $LOCK er til Python $PYV — spørg sysadmin"; exit 1; }
[ "$(uname -m)" = x86_64 ] || { echo "✘ låsefilen $LOCK er til x86_64, maskinen er $(uname -m)"; exit 1; }
[ -f "$LOCK" ] || { echo "✘ $LOCK mangler"; exit 1; }

mkdir -p var/whisper/models var/whisper/hf
rm -f "var/whisper/ready-$model"   # en afbrudt kørsel må ikke efterlade en gammel markør

# Pakkerne som "navn==version", navne normaliseret som pip freeze (små bogstaver, - i stedet for _ og .)
want=$(awk '/^[A-Za-z0-9]/ { split($1, a, "=="); n = tolower(a[1]); gsub(/[_.]/, "-", n); print n "==" a[2] }' "$LOCK" | sort)
have() {
  "$VENV/bin/python" -m pip freeze --disable-pip-version-check 2>/dev/null \
    | awk -F'==' '{ n = tolower($1); gsub(/[_.]/, "-", n); print n "==" $2 }' | sort
}
# Et venv med andre pakker eller en anden Python (fx fra før H003) bygges forfra, så intet ulåst bliver liggende
if [ -x "$VENV/bin/python" ] && { [ "$(pyver "$VENV/bin/python")" != "$PYV" ] || [ "$(have)" != "$want" ]; }; then
  echo "… var/whisper/venv passer ikke til $LOCK — bygges forfra"
  rm -rf "$VENV"
fi
if [ ! -x "$VENV/bin/python" ]; then
  python3 -m venv "$VENV" || { echo "✘ python3 -m venv fejlede (python3-venv mangler?) — spørg sysadmin"; exit 1; }
fi
if [ "$(have)" != "$want" ]; then
  # --no-deps: låsefilen er hele mængden; pip check bagefter fanger en lås, der ikke hænger sammen
  "$VENV/bin/python" -m pip install -q --disable-pip-version-check --require-hashes --only-binary :all: --no-deps -r "$LOCK" \
    || { echo "✘ pip install -r $LOCK fejlede"; exit 1; }
fi
[ "$(have)" = "$want" ] || { echo "✘ var/whisper/venv har ikke præcis pakkerne i $LOCK"; exit 1; }
"$VENV/bin/python" -m pip check --disable-pip-version-check >/dev/null || { echo "✘ pip check: pakkerne i $LOCK passer ikke sammen"; exit 1; }

# Import-tjek før modelhentningen: en halv installation må ikke først vise sig efter flere GB download
fw=$("$VENV/bin/python" -c '
import av, ctranslate2, huggingface_hub, onnxruntime, tokenizers
import faster_whisper
from faster_whisper import WhisperModel
print(faster_whisper.__version__)
') || { echo "✘ import-tjek fejlede — modellen hentes ikke"; exit 1; }
echo "✔ faster-whisper $fw i var/whisper/venv (låst: $LOCK)"

HF_HOME="$H/var/whisper/hf" "$VENV/bin/python" - "$model" "$H/var/whisper/models" "$rev" <<'PY'
import io
import os
import sys
import wave

import huggingface_hub
from faster_whisper import WhisperModel
from faster_whisper.utils import _MODELS, download_model

name, root, rev = sys.argv[1:4]
repo = _MODELS[name]
# Revisionen tjekkes før download: hentes med revision=<sha>, skriver huggingface_hub ikke refs/main, og transcribe.py
# (offline, uden revision) finder så ikke modellen. Derfor hentes "main" og godtages kun, når den er den låste commit.
main = huggingface_hub.HfApi().model_info(repo, revision="main").sha
if main != rev:
    sys.exit("✘ %s main er %s, låst er %s — modellen hentes ikke (ny revision = nyt H-ID)" % (repo, main, rev))
path = download_model(name, cache_dir=root)
if os.path.basename(os.path.normpath(path)) != rev:
    sys.exit("✘ hentet snapshot %s er ikke den låste revision %s" % (path, rev))
# Som transcribe.py: samme kald, kun lokale filer, og ét sekunds stilhed gennem hele kæden (PyAV-afkodning,
# CTranslate2, tokenizer), så en pakke, der importerer fint men fejler ved brug, stopper her og ikke i cron
model = WhisperModel(name, device="cpu", compute_type="int8", download_root=root, local_files_only=True)
silence = io.BytesIO()
with wave.open(silence, "wb") as w:
    w.setnchannels(1)
    w.setsampwidth(2)
    w.setframerate(16000)
    w.writeframes(b"\0\0" * 16000)
silence.seek(0)
segments, info = model.transcribe(silence, language="da")
list(segments)
print("✔ modellen %s er hentet (%s@%s) og transcriberer" % (name, repo, rev[:12]))
PY
# Markøren til sidst: transcribe.php tager først opkald, når setup er kørt helt færdig for netop denne model (QA F-3)
date -u +%Y-%m-%dT%H:%M:%SZ > "var/whisper/ready-$model"
echo "WHISPER: KLAR ($model)"
