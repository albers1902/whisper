#!/usr/bin/env python3
"""H011: hvem taler — speaker diarization med sherpa-onnx på CPU, JSON Lines på stdout.

Kaldes kun af cron/diarize.php (src/Transcription/ProcessDiarizer.php) med whisper-venv'ets python, i ledig tid:

    diarize.py --models <var/whisper/diarization> --threads N -- <lydfil> [<lydfil> ...]

DECISIONS #2: lokalt, ingen lyd forlader serveren. Lyden afkodes med faster-whispers decode_audio (PyAV, ingen ffmpeg,
DECISIONS #12) til 16 kHz mono. Modellerne (lagt og sha256-tjekket af cron/whisper-setup.sh):
    segmentation.onnx  pyannote/segmentation-3.0 i ONNX (MIT)
    embedding.onnx     3D-Speaker CAM++ VoxCeleb 16k (Apache-2.0)
Altid 2 talere (num_clusters=2): automatisk antal gav for mange talere i målingen (fullstack-ai/H011_v0.md).
Modellen indlæses én gang; filerne køres i rækkefølge, og hver linje skrives og flushes, så snart filen er færdig:
    {"file": <sti som input>, "seconds": s, "intervals": [{"start": s, "end": s, "speaker": n}]}
    {"file": <sti>, "error": <fejltype>, "seconds": s}
En fejl på én fil stopper ikke de næste; detaljen står på stderr. Ugyldige argumenter giver exit 2, før noget importeres.
"""
import argparse
import json
import os
import sys
import time
import traceback

SAMPLE_RATE = 16000
NUM_SPEAKERS = 2
MAX_FILES = 10
SEGMENTATION = "segmentation.onnx"
EMBEDDING = "embedding.onnx"


def safe_path(v: str) -> bool:
    """Absolut, uden ".."-led og kontroltegn (PHP sender kun realpath'ede filer under var/)."""
    return v.startswith("/") and ".." not in v.split("/") and not any(ord(c) < 32 for c in v)


def threads(v: str) -> int:
    if not (v.isascii() and v.isdigit()) or not 1 <= int(v) <= 32:
        raise argparse.ArgumentTypeError("skal være et heltal 1-32")
    return int(v)


def create(models: str, n_threads: int):
    import sherpa_onnx as so

    cfg = so.OfflineSpeakerDiarizationConfig(
        segmentation=so.OfflineSpeakerSegmentationModelConfig(
            pyannote=so.OfflineSpeakerSegmentationPyannoteModelConfig(model=os.path.join(models, SEGMENTATION)),
            num_threads=n_threads),
        embedding=so.SpeakerEmbeddingExtractorConfig(model=os.path.join(models, EMBEDDING), num_threads=n_threads),
        clustering=so.FastClusteringConfig(num_clusters=NUM_SPEAKERS),
        min_duration_on=0.3,
        min_duration_off=0.5,
    )
    if not cfg.validate():
        raise ValueError("ugyldig diarization-konfiguration (mangler modelfilerne?)")
    sd = so.OfflineSpeakerDiarization(cfg)
    if sd.sample_rate != SAMPLE_RATE:
        raise ValueError("modellen forventer %d Hz" % sd.sample_rate)
    return sd


def diarize(sd, samples) -> list:
    """Intervallerne for én lyd (float32 mono 16 kHz), sorteret efter start. Tom liste = ingen tale fundet."""
    if len(samples) == 0:
        return []
    res = sd.process(samples).sort_by_start_time()
    return [{"start": round(float(s.start), 2), "end": round(float(s.end), 2), "speaker": int(s.speaker)} for s in res]


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--models", required=True)
    p.add_argument("--threads", type=threads, default=3)
    p.add_argument("audio", nargs="+")
    a = p.parse_args()
    if not safe_path(a.models):
        p.error("--models skal være en absolut sti uden '..'")
    if len(a.audio) > MAX_FILES:
        p.error("højst %d lydfiler" % MAX_FILES)
    if not all(safe_path(f) for f in a.audio):
        p.error("lydstier skal være absolutte og uden '..'")
    if len(set(a.audio)) != len(a.audio):
        p.error("samme lydfil flere gange")

    from faster_whisper.audio import decode_audio

    sd = create(a.models, a.threads)
    for f in a.audio:
        t = time.monotonic()
        try:
            res = {"file": f, "intervals": diarize(sd, decode_audio(f, sampling_rate=SAMPLE_RATE))}
        except Exception as e:  # noqa: BLE001 - én dårlig fil må ikke stoppe resten
            print("fejl i %s:" % os.path.basename(f), file=sys.stderr)
            traceback.print_exc(file=sys.stderr)
            res = {"file": f, "error": type(e).__name__}
        res["seconds"] = round(time.monotonic() - t, 2)
        print(json.dumps(res), flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
