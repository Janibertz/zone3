"""
Garmin FIT Workout File Generator — Python Microservice
Uses the official garmin-fit-sdk (write_mesg API).

POST /generate-fit  →  binary .fit file
GET  /health        →  {"status": "ok"}
"""

from datetime import datetime, timezone
from typing import Optional
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

app = FastAPI(title="FIT Workout Generator")


def _ascii(value: str, max_len: int = 15) -> str:
    """Normalize German umlauts, strip non-ASCII, truncate."""
    for k, v in [("ä","ae"),("ö","oe"),("ü","ue"),("Ä","Ae"),("Ö","Oe"),("Ü","Ue"),("ß","ss")]:
        value = value.replace(k, v)
    return "".join(c for c in value if 0x20 <= ord(c) <= 0x7E)[:max_len]


def build_fit(name: str, steps: list[dict]) -> bytes:
    from garmin_fit_sdk import Encoder, FIT_EPOCH_S
    from garmin_fit_sdk.profile import Profile

    encoder  = Encoder()
    now_fit  = int(datetime.now(tz=timezone.utc).timestamp()) - FIT_EPOCH_S
    n        = len(steps)

    # ── file_id ───────────────────────────────────────────────────────────────
    encoder.write_mesg({
        "mesg_num":    Profile["mesg_num"]["FILE_ID"],
        "type":        "workout",
        "manufacturer":"development",
        "product":     0,
        "time_created": now_fit,
    })

    # ── workout ───────────────────────────────────────────────────────────────
    encoder.write_mesg({
        "mesg_num":       Profile["mesg_num"]["WORKOUT"],
        "sport":          "running",
        "num_valid_steps": n,
        "wkt_name":       _ascii(name or "Training"),
    })

    # ── workout_step (one per step) ───────────────────────────────────────────
    for i, step in enumerate(steps):
        if i == 0:
            intensity = "warmup"
        elif i == n - 1:
            intensity = "cooldown"
        else:
            intensity = "active"

        mesg = {
            "mesg_num":      Profile["mesg_num"]["WORKOUT_STEP"],
            "message_index": i,
            "wkt_step_name": _ascii(step.get("name") or f"Step {i+1}"),
            "duration_type": "distance",
            "duration_value": max(100, int(step.get("meters") or 1000)) * 100,
            "intensity":     intensity,
        }

        speed = step.get("speedMps")
        if speed and speed > 0:
            mesg["target_type"]              = "speed"
            mesg["target_value"]             = 0
            mesg["custom_target_value_low"]  = round(speed * 0.95 * 1000)
            mesg["custom_target_value_high"] = round(speed * 1.05 * 1000)
        else:
            mesg["target_type"] = "open"

        encoder.write_mesg(mesg)

    result = bytes(encoder.close())
    print(f"[FIT] Generated {len(result)} bytes via garmin-fit-sdk", flush=True)
    return result


# ── API ───────────────────────────────────────────────────────────────────────

class Step(BaseModel):
    name: str
    meters: int
    speedMps: Optional[float] = None


class WorkoutRequest(BaseModel):
    name: str
    steps: list[Step]


@app.post("/generate-fit")
def generate_fit(req: WorkoutRequest):
    if not req.steps:
        raise HTTPException(status_code=422, detail="steps must not be empty")
    try:
        fit_bytes = build_fit(req.name, [s.model_dump() for s in req.steps])
    except Exception as e:
        print(f"[FIT] Error: {e}", flush=True)
        raise HTTPException(status_code=500, detail=str(e))

    safe = "".join(c if c.isalnum() or c in "-_" else "-" for c in req.name)[:40]
    return Response(
        content=fit_bytes,
        media_type="application/vnd.ant.fit",
        headers={"Content-Disposition": f'attachment; filename="{safe}.fit"'},
    )


@app.get("/health")
def health():
    return {"status": "ok"}


@app.get("/debug")
def debug():
    """Generate a sample workout FIT and decode it immediately to validate."""
    from garmin_fit_sdk import Decoder, Stream

    test_steps = [
        {"name": "Warmup",   "meters": 1000, "speedMps": None},
        {"name": "Main",     "meters": 5000, "speedMps": 3.33},
        {"name": "Cooldown", "meters": 1000, "speedMps": None},
    ]

    try:
        fit_bytes = build_fit("Debug Test", test_steps)
    except Exception as e:
        return {"error": f"Encode failed: {e}"}

    # Decode the generated FIT to verify it
    try:
        stream   = Stream.from_bytes(fit_bytes)
        decoder  = Decoder(stream)
        messages, errors = decoder.read()
        return {
            "bytes":    len(fit_bytes),
            "errors":   errors,
            "messages": messages,
        }
    except Exception as e:
        return {
            "bytes":          len(fit_bytes),
            "decode_error":   str(e),
            "hex_preview":    fit_bytes[:32].hex(),
        }
