#!/usr/bin/env python3
"""F002: transcriberer én lydfil med faster-whisper på CPU og skriver JSON på stdout.

Kaldes kun af cron/transcribe.php (src/Transcription/ProcessEngine.php) med venv'ets python:

    transcribe.py --model turbo|large-v3 --models <var/whisper/models> <lydfil>

DECISIONS #2: lokal transcription, CPU med compute_type="int8", language="da". Lyden afkodes af PyAV, som har
FFmpeg-bibliotekerne med, så en separat ffmpeg ikke er nødvendig (F002 v0). Svaret:
    {"text": ..., "segments": [{"start": s, "end": s, "text": ...}], "duration": s, "model": ..., "language": "da"}
Fejl går til stderr (gemmes privat af PHP) og giver exit != 0.
"""
import argparse
import json
import sys


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--model", required=True, choices=["turbo", "large-v3"])
    p.add_argument("--models", required=True)
    p.add_argument("audio")
    a = p.parse_args()

    from faster_whisper import WhisperModel

    model = WhisperModel(a.model, device="cpu", compute_type="int8", download_root=a.models)
    segments, info = model.transcribe(a.audio, language="da")
    segs = [{"start": round(s.start, 2), "end": round(s.end, 2), "text": s.text.strip()} for s in segments]
    json.dump({
        "text": " ".join(s["text"] for s in segs if s["text"]).strip(),
        "segments": segs,
        "duration": round(float(info.duration), 2),
        "model": a.model,
        "language": "da",
    }, sys.stdout, ensure_ascii=False)
    return 0


if __name__ == "__main__":
    sys.exit(main())
