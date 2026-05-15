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


def build_garmin_json(name: str, sport: str, steps: list[dict]) -> dict:
    """Convert workout steps to Garmin Connect JSON format.

    Step objects require "type": "ExecutableStepDTO" as Jackson polymorphic discriminator.
    endCondition conditionTypeId: 2=time, 3=distance (from Garmin API reverse-engineering).
    """
    sport_map = {
        "running": {"sportTypeId": 1, "sportTypeKey": "running",  "displayOrder": 1},
        "cycling": {"sportTypeId": 2, "sportTypeKey": "cycling",  "displayOrder": 2},
        "swimming":{"sportTypeId": 4, "sportTypeKey": "swimming",  "displayOrder": 5},
    }
    step_type_map = {
        "warmup":   {"stepTypeId": 1, "stepTypeKey": "warmup",   "displayOrder": 1},
        "cooldown": {"stepTypeId": 2, "stepTypeKey": "cooldown",  "displayOrder": 2},
        "interval": {"stepTypeId": 3, "stepTypeKey": "interval",  "displayOrder": 3},
    }
    sport_type = sport_map.get(sport, sport_map["running"])
    n = len(steps)
    workout_steps = []

    for i, step in enumerate(steps):
        if i == 0:
            st = step_type_map["warmup"]
        elif i == n - 1:
            st = step_type_map["cooldown"]
        else:
            st = step_type_map["interval"]

        meters = max(100, int(step.get("meters") or 1000))
        speed  = step.get("speedMps")

        if speed and speed > 0:
            # pace.zone: targetValueOne = faster speed (m/s), targetValueTwo = slower speed (m/s)
            target_type = {"workoutTargetTypeId": 6, "workoutTargetTypeKey": "pace.zone", "displayOrder": 6}
            target_one  = round(speed * 1.05, 4)
            target_two  = round(speed * 0.95, 4)
        else:
            target_type = {"workoutTargetTypeId": 1, "workoutTargetTypeKey": "no.target", "displayOrder": 1}
            target_one  = None
            target_two  = None

        step_obj: dict = {
            "type":               "ExecutableStepDTO",  # Jackson polymorphic type discriminator
            "stepOrder":          i + 1,
            "stepType":           st,
            "childStepId":        None,
            "endCondition":       {"conditionTypeId": 3, "conditionTypeKey": "distance",
                                   "displayOrder": 3, "displayable": True},
            "endConditionValue":  float(meters),
            "targetType":         target_type,
            "strokeType":         {"strokeTypeId": 0, "displayOrder": 0},
            "equipmentType":      {"equipmentTypeId": 0, "displayOrder": 0},
        }
        if target_one is not None:
            step_obj["targetValueOne"] = target_one
            step_obj["targetValueTwo"] = target_two

        workout_steps.append(step_obj)

    total_meters = sum(max(100, int(s.get("meters") or 1000)) for s in steps)

    return {
        "workoutName":            name or "Training",
        "sportType":              sport_type,
        "estimatedDurationInSecs":0,
        "workoutSegments": [{
            "segmentOrder": 1,
            "sportType":    sport_type,
            "workoutSteps": workout_steps,
        }],
    }


# ── API ───────────────────────────────────────────────────────────────────────

class Step(BaseModel):
    name: str
    meters: int
    speedMps: Optional[float] = None


class WorkoutRequest(BaseModel):
    name: str
    steps: list[Step]


class GarminSendRequest(BaseModel):
    garmin_email:    str
    garmin_password: str
    name:            str
    sport:           str = "running"
    steps:           list[Step]


@app.post("/send-to-garmin")
def send_to_garmin(req: GarminSendRequest):
    from garminconnect import Garmin

    workout_json = build_garmin_json(req.name, req.sport, [s.model_dump() for s in req.steps])

    try:
        api = Garmin(req.garmin_email, req.garmin_password)
        api.login()
    except Exception as e:
        msg = str(e)
        print(f"[Garmin] Login failed: {msg}", flush=True)
        if "MFA" in msg.upper() or "mfa" in msg or "two" in msg.lower():
            raise HTTPException(status_code=401, detail="mfa_required")
        raise HTTPException(status_code=401, detail=f"login_failed: {msg}")

    try:
        result = api.upload_workout(workout_json)
        workout_id = result.get("workoutId") if isinstance(result, dict) else None
        print(f"[Garmin] Workout uploaded, id={workout_id}", flush=True)
        return {"success": True, "workoutId": workout_id}
    except Exception as e:
        print(f"[Garmin] Upload failed: {e}", flush=True)
        raise HTTPException(status_code=502, detail=str(e))


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
        # Find correct Stream factory method at runtime
        stream = None
        for method in ["from_byte_array", "from_bytes_array", "from_bytes"]:
            fn = getattr(Stream, method, None)
            if fn:
                try:
                    stream = fn(bytearray(fit_bytes))
                    break
                except Exception:
                    continue

        if stream is None:
            return {"bytes": len(fit_bytes), "error": "no valid Stream factory found",
                    "stream_methods": [m for m in dir(Stream) if not m.startswith("_")]}

        decoder  = Decoder(stream)
        messages, errors = decoder.read()
        return {
            "bytes":    len(fit_bytes),
            "errors":   errors,
            "messages": messages,
        }
    except Exception as e:
        return {
            "bytes":        len(fit_bytes),
            "decode_error": str(e),
            "hex_preview":  fit_bytes[:64].hex(),
            "stream_api":   [m for m in dir(Stream) if not m.startswith("_")],
        }
