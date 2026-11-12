#!/usr/bin/env python3
"""F002: transcriberer én lydfil med faster-whisper på CPU og skriver JSON på stdout.

Kaldes kun af cron/transcribe.php (src/Transcription/ProcessEngine.php) med venv'ets python:

    transcribe.py --model turbo|large-v3 --models <var/whisper/models> --threads N --beam N --vad 0|1 <lydfil>

DECISIONS #2: lokal transcription, CPU med compute_type="int8", language="da". Lyden afkodes af PyAV, som har
FFmpeg-bibliotekerne med, så en separat ffmpeg ikke er nødvendig (F002 v0).
--threads/--beam/--vad går til faster-whisper 1.2.1's WhisperModel(cpu_threads=) og transcribe(beam_size=, vad_filter=)
(H005). Udelades de, gælder faster-whispers egne standarder (4 tråde, beam 5, ingen VAD). VAD bruger onnxruntime og
silero-modellen i pakken, så intet hentes. Ugyldige værdier afvises af argparse (exit 2), før faster_whisper importeres.
Svaret:
    {"text": ..., "segments": [{"start": s, "end": s, "text": ...}], "duration": s, "model": ..., "language": "da"}
Fejl går til stderr (gemmes privat af PHP) og giver exit != 0.
"""
import argparse
import json
import sys


def int_range(lo: int, hi: int):
    def parse(v: str) -> int:
        if not (v.isascii() and v.isdigit()) or not lo <= int(v) <= hi:
            raise argparse.ArgumentTypeError(f"skal være et heltal {lo}-{hi}")
        return int(v)
    return parse


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--model", required=True, choices=["turbo", "large-v3"])
    p.add_argument("--models", required=True)
    p.add_argument("--threads", type=int_range(1, 32), default=4)
    p.add_argument("--beam", type=int_range(1, 10), default=5)
    p.add_argument("--vad", choices=["0", "1"], default="0")
    p.add_argument("audio")
    a = p.parse_args()

    from faster_whisper import WhisperModel

    model = WhisperModel(a.model, device="cpu", compute_type="int8", download_root=a.models, cpu_threads=a.threads)
    segments, info = model.transcribe(a.audio, language="da", beam_size=a.beam, vad_filter=a.vad == "1")
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
