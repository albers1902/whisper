#!/usr/bin/env python3
"""F002: transcriberer lydfiler med faster-whisper på CPU og skriver JSON på stdout.

Kaldes kun af cron/transcribe.php (src/Transcription/ProcessEngine.php) med venv'ets python:

    transcribe.py --model turbo|large-v3 --models <var/whisper/models> --threads N --beam N --vad 0|1 <lydfil>
    transcribe.py ... --vad 0|1 --batch-max N -- <lydfil> [<lydfil> ...]
    transcribe.py ... --vad 1 --mode batched --batched-size N [--batch-max N --] <lydfil> ...

DECISIONS #2: lokal transcription, CPU med compute_type="int8", language="da". Lyden afkodes af PyAV, som har
FFmpeg-bibliotekerne med, så en separat ffmpeg ikke er nødvendig (F002 v0).
--threads/--beam/--vad går til faster-whisper 1.2.1's WhisperModel(cpu_threads=) og transcribe(beam_size=, vad_filter=)
(H005). Udelades de, gælder faster-whispers egne standarder (4 tråde, beam 5, ingen VAD). VAD bruger onnxruntime og
silero-modellen i pakken, så intet hentes. Ugyldige værdier afvises af argparse (exit 2), før faster_whisper importeres.
--mode batched --batched-size N (H010) bruger faster-whisper 1.2.1's BatchedInferencePipeline(model).transcribe(...,
batch_size=N): VAD-stykker på højst 30 s køres N ad gangen gennem modellen. Den kræver VAD (uden VAD og med lyd over
30 s kaster den RuntimeError), så --vad 0 afvises sammen med batched. without_timestamps=False, fordi pipelinens standard
(True) gør hvert segment til et helt VAD-stykke; med False deles der ved Whispers tidsstempler som i sequential.
Uddata er det samme JSON i begge tilstande.

Én fil (uden --batch-max) giver ét JSON-objekt:
    {"text": ..., "segments": [{"start": s, "end": s, "text": ...}], "duration": s, "model": ..., "language": "da"}
Batch (H006, --batch-max 1-50): modellen indlæses én gang, og filerne (absolutte stier uden "..", højst --batch-max)
transcriberes i rækkefølge. Svaret er JSON Lines, skrevet og flushet, så snart hver fil er færdig:
    {"file": <sti som input>, <felterne ovenfor>, "elapsed": s}   eller   {"file": ..., "error": <fejltype>, "elapsed": s}
og til sidst {"load_seconds": s}. En fejl på én fil stopper ikke de næste; detaljen står på stderr.
Fejl går til stderr (gemmes privat af PHP) og giver exit != 0.
"""
import argparse
import json
import sys
import time
import traceback


def int_range(lo: int, hi: int):
    def parse(v: str) -> int:
        if not (v.isascii() and v.isdigit()) or not lo <= int(v) <= hi:
            raise argparse.ArgumentTypeError(f"skal være et heltal {lo}-{hi}")
        return int(v)
    return parse


def safe_path(v: str) -> bool:
    """Absolut, uden ".."-led og kontroltegn (PHP sender kun realpath'ede filer under var/recordings)."""
    return v.startswith("/") and ".." not in v.split("/") and not any(ord(c) < 32 for c in v)


def transcribe_one(model, audio: str, a) -> dict:
    if a.mode == "batched":
        segments, info = model.transcribe(audio, language="da", beam_size=a.beam, vad_filter=True,
                                          batch_size=a.batched_size, without_timestamps=False)
    else:
        segments, info = model.transcribe(audio, language="da", beam_size=a.beam, vad_filter=a.vad == "1")
    segs = [{"start": round(s.start, 2), "end": round(s.end, 2), "text": s.text.strip()} for s in segments]
    return {
        "text": " ".join(s["text"] for s in segs if s["text"]).strip(),
        "segments": segs,
        "duration": round(float(info.duration), 2),
        "model": a.model,
        "language": "da",
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--model", required=True, choices=["turbo", "large-v3"])
    p.add_argument("--models", required=True)
    p.add_argument("--threads", type=int_range(1, 32), default=4)
    p.add_argument("--beam", type=int_range(1, 10), default=5)
    p.add_argument("--vad", choices=["0", "1"], default="0")
    p.add_argument("--batch-max", type=int_range(1, 50), default=None)
    p.add_argument("--mode", choices=["sequential", "batched"], default="sequential")
    p.add_argument("--batched-size", type=int_range(1, 32), default=None)
    p.add_argument("audio", nargs="+")
    a = p.parse_args()
    if a.mode == "batched":
        if a.vad != "1":
            p.error("--mode batched kræver --vad 1")
        if a.batched_size is None:
            a.batched_size = 4
    elif a.batched_size is not None:
        p.error("--batched-size kun med --mode batched")
    if a.batch_max is None:
        if len(a.audio) != 1:
            p.error("præcis én lydfil uden --batch-max")
    else:
        if len(a.audio) > a.batch_max:
            p.error("flere lydfiler end --batch-max")
        if not all(safe_path(f) for f in a.audio):
            p.error("lydstier skal være absolutte og uden '..'")
        if len(set(a.audio)) != len(a.audio):
            p.error("samme lydfil flere gange")

    from faster_whisper import WhisperModel

    t0 = time.monotonic()
    model = WhisperModel(a.model, device="cpu", compute_type="int8", download_root=a.models, cpu_threads=a.threads)
    if a.mode == "batched":
        from faster_whisper import BatchedInferencePipeline

        # Én pipeline pr. proces, genbrugt til alle filer i batchen (den holder ingen tilstand mellem filer)
        model = BatchedInferencePipeline(model)
    load = time.monotonic() - t0

    if a.batch_max is None:
        json.dump(transcribe_one(model, a.audio[0], a), sys.stdout, ensure_ascii=False)
        return 0

    for f in a.audio:
        t = time.monotonic()
        try:
            res = {"file": f, **transcribe_one(model, f, a)}
        except Exception as e:  # noqa: BLE001 - én dårlig fil må ikke stoppe resten af batchen
            traceback.print_exc(file=sys.stderr)
            res = {"file": f, "error": type(e).__name__}
        res["elapsed"] = round(time.monotonic() - t, 2)
        print(json.dumps(res, ensure_ascii=False), flush=True)
    print(json.dumps({"load_seconds": round(load, 2)}), flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
