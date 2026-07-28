"""
Garmin FIT Workout File Generator — Python Microservice
Uses the official garmin-fit-sdk (write_mesg API).

POST /garmin-login    →  authenticate and return garth session tokens
POST /generate-fit    →  binary .fit file
POST /send-to-garmin  →  sends structured workout to Garmin Connect + schedules in calendar
GET  /health          →  {"status": "ok"}
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

    encoder.write_mesg({
        "mesg_num":    Profile["mesg_num"]["FILE_ID"],
        "type":        "workout",
        "manufacturer":"development",
        "product":     0,
        "time_created": now_fit,
    })

    encoder.write_mesg({
        "mesg_num":        Profile["mesg_num"]["WORKOUT"],
        "sport":           "running",
        "num_valid_steps": n,
        "wkt_name":        _ascii(name or "Training"),
    })

    for i, step in enumerate(steps):
        intensity = "warmup" if i == 0 else ("cooldown" if i == n - 1 else "active")

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


# ── Garmin Connect JSON helpers ───────────────────────────────────────────────

_STEP_TYPES = {
    "warmup":   {"stepTypeId": 1, "stepTypeKey": "warmup",   "displayOrder": 1},
    "cooldown": {"stepTypeId": 2, "stepTypeKey": "cooldown", "displayOrder": 2},
    "work":     {"stepTypeId": 3, "stepTypeKey": "interval", "displayOrder": 3},
    "interval": {"stepTypeId": 3, "stepTypeKey": "interval", "displayOrder": 3},
    "active":   {"stepTypeId": 3, "stepTypeKey": "interval", "displayOrder": 3},
    "rest":     {"stepTypeId": 4, "stepTypeKey": "recovery", "displayOrder": 4},
    "recovery": {"stepTypeId": 4, "stepTypeKey": "recovery", "displayOrder": 4},
}


def _executable_step(step: dict, order: int) -> dict:
    stype = step.get("step_type") or "active"
    st    = _STEP_TYPES.get(stype, _STEP_TYPES["active"])
    speed = step.get("speedMps")

    if speed and speed > 0:
        target_type = {"workoutTargetTypeId": 6, "workoutTargetTypeKey": "pace.zone", "displayOrder": 6}
        tv1 = round(speed * 1.05, 4)
        tv2 = round(speed * 0.95, 4)
    else:
        target_type = {"workoutTargetTypeId": 1, "workoutTargetTypeKey": "no.target", "displayOrder": 1}
        tv1 = tv2 = None

    duration_sec = step.get("duration_sec")
    meters       = step.get("meters")
    lap_button   = step.get("lap_button")

    if lap_button:
        # LAP button explicitly enabled in zone3 → end condition is "press lap" regardless of any duration
        end_cond  = {"conditionTypeId": 1, "conditionTypeKey": "lap.button", "displayOrder": 1, "displayable": True}
        end_value = None
    elif duration_sec:
        end_cond  = {"conditionTypeId": 2, "conditionTypeKey": "time",     "displayOrder": 2, "displayable": True}
        end_value = float(duration_sec)
    elif meters:
        end_cond  = {"conditionTypeId": 3, "conditionTypeKey": "distance", "displayOrder": 3, "displayable": True}
        end_value = float(max(100, meters))
    else:
        end_cond  = {"conditionTypeId": 1, "conditionTypeKey": "lap.button", "displayOrder": 1, "displayable": True}
        end_value = None

    obj: dict = {
        "type":              "ExecutableStepDTO",
        "stepOrder":         order,
        "stepType":          st,
        "childStepId":       None,
        "endCondition":      end_cond,
        "endConditionValue": end_value,
        "targetType":        target_type,
        "strokeType":        {"strokeTypeId": 0, "displayOrder": 0},
        "equipmentType":     {"equipmentTypeId": 0, "displayOrder": 0},
    }
    if tv1 is not None:
        obj["targetValueOne"] = tv1
        obj["targetValueTwo"] = tv2
    return obj


def build_garmin_json(name: str, sport: str, steps: list[dict], description: str = None) -> dict:
    """Convert workout steps to Garmin Connect JSON.
    Consecutive steps sharing the same repetitions value become a RepeatGroupDTO.
    """
    sport_map = {
        "running": {"sportTypeId": 1, "sportTypeKey": "running",  "displayOrder": 1},
        "cycling": {"sportTypeId": 2, "sportTypeKey": "cycling",  "displayOrder": 2},
        "swimming":{"sportTypeId": 4, "sportTypeKey": "swimming", "displayOrder": 5},
    }
    sport_type    = sport_map.get(sport, sport_map["running"])
    workout_steps = []
    outer_order   = 1
    i = 0

    while i < len(steps):
        step  = steps[i]
        reps  = step.get("repetitions")
        stype = step.get("step_type", "active")

        if stype == "repeat" and reps and reps > 1:
            # Repeat block: extract and expand inner steps
            inner_steps = step.get("steps", [])
            inner = [_executable_step(gs, j + 1) for j, gs in enumerate(inner_steps)]
            workout_steps.append({
                "type":               "RepeatGroupDTO",
                "stepOrder":          outer_order,
                "numberOfIterations": reps,
                "smartRepeat":        False,
                "childStepId":        1,
                "workoutSteps":       inner,
            })
            i += 1
        elif reps and reps > 1 and stype in ("work", "interval", "active"):
            group: list[dict] = []
            while i < len(steps) and steps[i].get("repetitions") == reps:
                group.append(steps[i])
                i += 1
            inner = [_executable_step(gs, j + 1) for j, gs in enumerate(group)]
            workout_steps.append({
                "type":               "RepeatGroupDTO",
                "stepOrder":          outer_order,
                "numberOfIterations": reps,
                "smartRepeat":        False,
                "childStepId":        1,
                "workoutSteps":       inner,
            })
        else:
            workout_steps.append(_executable_step(step, outer_order))
            i += 1

        outer_order += 1

    result: dict = {
        "workoutName":             name or "Training",
        "sportType":               sport_type,
        "estimatedDurationInSecs": 0,
        "workoutSegments": [{
            "segmentOrder": 1,
            "sportType":    sport_type,
            "workoutSteps": workout_steps,
        }],
    }
    if description:
        result["description"] = description
    return result


class GarminMfaRequired(Exception):
    """The account needs a 2FA code — impossible in a headless server context."""


def _token_client(api):
    """Return the serialisable token client across garminconnect versions.
    v0.3+ exposes api.client; older v0.2 exposed api.garth (which is None on 0.3)."""
    for attr in ("client", "garth"):
        c = getattr(api, attr, None)
        if c is not None and hasattr(c, "dumps") and hasattr(c, "loads"):
            return c
    return None


def _garmin_api_from_session(session_str: str):
    """Restore a fully-initialised Garmin API from a saved token string.

    Uses login(tokenstore=<token>) which loads the tokens AND the user profile
    (display_name). Health endpoints require display_name, so a bare client.loads()
    is not enough. Raises on an expired/invalid token."""
    from garminconnect import Garmin
    api = Garmin()
    # A dumped token JSON is well over 512 chars, so garminconnect treats the
    # argument as inline token data (not a filesystem path) and calls loads().
    api.login(tokenstore=session_str)
    return api


def _garmin_api_from_credentials(email: str, password: str):
    """Log in once with credentials and return (api, session_str).

    The password is never stored or logged. return_on_mfa=True makes login()
    report a 2FA requirement instead of blocking on an interactive prompt the
    server cannot answer. The returned api is rebuilt from the fresh token so it
    has the profile loaded and is immediately usable for data/workout calls."""
    from garminconnect import Garmin
    api = Garmin(email=email, password=password, return_on_mfa=True)
    result = api.login()
    mfa_status = result[0] if isinstance(result, (tuple, list)) else result
    if mfa_status == "needs_mfa":
        raise GarminMfaRequired("mfa_required")

    client = _token_client(api)
    if client is None:
        return api, None
    session_str = client.dumps()

    # return_on_mfa login skips profile loading; rebuild from the token so the
    # api has display_name set (needed by upload_workout / health calls).
    try:
        api = _garmin_api_from_session(session_str)
    except Exception as e:
        print(f"[Garmin] Profile reload after login failed (non-fatal): {e}", flush=True)
    return api, session_str


# ── API models ────────────────────────────────────────────────────────────────

class Step(BaseModel):
    name: str
    meters: int
    speedMps: Optional[float] = None


class GarminStep(BaseModel):
    name: str
    step_type: str = "active"
    meters: Optional[int] = None
    duration_sec: Optional[int] = None
    speedMps: Optional[float] = None
    repetitions: Optional[int] = None
    steps: Optional[list] = None
    lap_button: Optional[bool] = None

    class Config:
        extra = "allow"  # Allow extra fields


class WorkoutRequest(BaseModel):
    name: str
    steps: list[Step]


class GarminLoginRequest(BaseModel):
    garmin_email:    str
    garmin_password: str


class GarminSendRequest(BaseModel):
    # Auth: either saved session OR fresh credentials
    garmin_session:  Optional[str] = None
    garmin_email:    Optional[str] = None
    garmin_password: Optional[str] = None
    name:            str
    description:     Optional[str] = None
    date:            Optional[str] = None
    sport:           str = "running"
    steps:           list[GarminStep]


# ── Endpoints ─────────────────────────────────────────────────────────────────

@app.post("/garmin-login")
def garmin_login(req: GarminLoginRequest):
    """Authenticate and return garth session tokens to be stored by the caller."""
    try:
        api, session_str = _garmin_api_from_credentials(req.garmin_email, req.garmin_password)
        print(f"[Garmin] Login OK for {req.garmin_email}", flush=True)
        return {"session": session_str}
    except Exception as e:
        msg = str(e)
        print(f"[Garmin] Login failed ({type(e).__name__}): {msg}", flush=True)
        if "MFA" in msg.upper() or "mfa" in msg or "two" in msg.lower():
            raise HTTPException(status_code=401, detail="mfa_required")
        raise HTTPException(status_code=401, detail=f"login_failed: {msg}")


@app.post("/send-to-garmin")
def send_to_garmin(req: GarminSendRequest):
    from garminconnect import Garmin

    try:
        print(f"[DEBUG] Incoming steps: {req.steps}", flush=True)
    except Exception as e:
        print(f"[DEBUG] Could not print steps: {e}", flush=True)

    new_session: Optional[str] = None

    if req.garmin_session:
        try:
            api = _garmin_api_from_session(req.garmin_session)
        except Exception as e:
            print(f"[Garmin] Session restore failed: {e}", flush=True)
            raise HTTPException(status_code=401, detail="session_expired")
    elif req.garmin_email and req.garmin_password:
        try:
            api, new_session = _garmin_api_from_credentials(req.garmin_email, req.garmin_password)
        except Exception as e:
            msg = str(e)
            print(f"[Garmin] Login failed: {msg}", flush=True)
            if "MFA" in msg.upper() or "mfa" in msg or "two" in msg.lower():
                raise HTTPException(status_code=401, detail="mfa_required")
            raise HTTPException(status_code=401, detail=f"login_failed: {msg}")
    else:
        raise HTTPException(status_code=422, detail="garmin_session or garmin_email+password required")

    workout_json = build_garmin_json(
        req.name, req.sport,
        [s.model_dump() for s in req.steps],
        req.description,
    )

    try:
        result     = api.upload_workout(workout_json)
        workout_id = result.get("workoutId") if isinstance(result, dict) else None
        print(f"[Garmin] Workout uploaded, id={workout_id}", flush=True)
    except Exception as e:
        print(f"[Garmin] Upload failed: {e}", flush=True)
        raise HTTPException(status_code=502, detail=str(e))

    if req.date and workout_id:
        try:
            if hasattr(api, "schedule_workout"):
                api.schedule_workout(workout_id, req.date)
            else:
                _token_client(api).post(
                    "connectapi",
                    f"/workout-service/schedule/{workout_id}",
                    json={"date": req.date},
                )
            print(f"[Garmin] Scheduled for {req.date}", flush=True)
        except Exception as e:
            print(f"[Garmin] Schedule failed (non-fatal): {e}", flush=True)

    # Return new_session so Laravel can save it (only set on fresh credential login)
    return {"success": True, "workoutId": workout_id, "session": new_session}


# ── Garmin health / recovery data (read-only) ─────────────────────────────────

class GarminHealthRequest(BaseModel):
    # Auth: either saved session OR fresh credentials
    garmin_session:  Optional[str] = None
    garmin_email:    Optional[str] = None
    garmin_password: Optional[str] = None
    days:            int = 60          # how many days back from `end` to fetch
    end:             Optional[str] = None  # YYYY-MM-DD, defaults to today


def _safe(fn):
    """Run a single Garmin call in isolation. A missing value never aborts the
    whole day — it becomes None ("keine Daten"), never 0."""
    try:
        return fn()
    except Exception:
        return None


def _num(v):
    """Coerce to a number or None. Empty/invalid → None (never 0)."""
    if v is None:
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    return f


def _fetch_day(api, cdate: str) -> dict:
    """Fetch one day's recovery metrics. Every field guarded individually."""
    summary = _safe(lambda: api.get_user_summary(cdate)) or {}

    # HRV — last night average
    hrv = None
    hrv_data = _safe(lambda: api.get_hrv_data(cdate))
    if isinstance(hrv_data, dict):
        s = hrv_data.get("hrvSummary") or {}
        hrv = _num(s.get("lastNightAvg"))

    # Resting heart rate
    resting_hr = _num(summary.get("restingHeartRate"))
    if resting_hr is None:
        rhr = _safe(lambda: api.get_rhr_day(cdate))
        if isinstance(rhr, dict):
            resting_hr = _num(rhr.get("restingHeartRate"))

    # Sleep — hours + score
    sleep_hours = None
    sleep_score = None
    sleep_data = _safe(lambda: api.get_sleep_data(cdate))
    if isinstance(sleep_data, dict):
        dto = sleep_data.get("dailySleepDTO") or {}
        secs = _num(dto.get("sleepTimeSeconds"))
        if secs is not None:
            sleep_hours = round(secs / 3600, 2)
        scores = dto.get("sleepScores") or {}
        overall = scores.get("overall") or {}
        sleep_score = _num(overall.get("value"))

    # Body Battery — daily low / high
    bb_low = None
    bb_high = None
    bb = _safe(lambda: api.get_body_battery(cdate, cdate))
    if isinstance(bb, list) and bb:
        arr = (bb[0] or {}).get("bodyBatteryValuesArray") or []
        vals = [_num(p[1]) for p in arr if isinstance(p, list) and len(p) > 1]
        vals = [v for v in vals if v is not None]
        if vals:
            bb_low, bb_high = min(vals), max(vals)

    # Stress — daily average
    stress_avg = _num(summary.get("averageStressLevel"))
    if stress_avg is not None and stress_avg < 0:
        stress_avg = None  # Garmin uses -1/-2 for "no data"

    # Steps
    steps = _num(summary.get("totalSteps"))

    # Training Readiness — score
    readiness = None
    tr = _safe(lambda: api.get_training_readiness(cdate))
    if isinstance(tr, list) and tr:
        readiness = _num((tr[0] or {}).get("score"))

    return {
        "date":                cdate,
        "hrv":                 hrv,
        "resting_hr":          resting_hr,
        "sleep_hours":         sleep_hours,
        "sleep_score":         sleep_score,
        "body_battery_low":    bb_low,
        "body_battery_high":   bb_high,
        "stress_avg":          stress_avg,
        "steps":               steps,
        "training_readiness":  readiness,
    }


@app.post("/garmin-health")
def garmin_health(req: GarminHealthRequest):
    """Read-only pull of daily recovery metrics for the last `days` days.
    Never writes to Garmin. Returns one record per day; missing values are null."""
    from datetime import date as _date, timedelta

    new_session: Optional[str] = None

    if req.garmin_session:
        try:
            api = _garmin_api_from_session(req.garmin_session)
        except Exception as e:
            print(f"[Garmin] Session restore failed: {e}", flush=True)
            raise HTTPException(status_code=401, detail="session_expired")
    elif req.garmin_email and req.garmin_password:
        try:
            api, new_session = _garmin_api_from_credentials(req.garmin_email, req.garmin_password)
        except Exception as e:
            msg = str(e)
            print(f"[Garmin] Login failed: {msg}", flush=True)
            if "MFA" in msg.upper() or "mfa" in msg or "two" in msg.lower():
                raise HTTPException(status_code=401, detail="mfa_required")
            raise HTTPException(status_code=401, detail=f"login_failed: {msg}")
    else:
        raise HTTPException(status_code=422, detail="garmin_session or garmin_email+password required")

    try:
        end = _date.fromisoformat(req.end) if req.end else _date.today()
    except ValueError:
        end = _date.today()
    span = max(1, min(req.days, 400))

    days = []
    filled = 0
    for i in range(span):
        cdate = (end - timedelta(days=i)).isoformat()
        rec = _fetch_day(api, cdate)
        days.append(rec)
        if any(rec[k] is not None for k in (
            "hrv", "resting_hr", "sleep_hours", "body_battery_high",
            "stress_avg", "steps", "training_readiness")):
            filled += 1

    print(f"[Garmin] Health sync: {filled}/{span} days with data", flush=True)
    return {"days": days, "days_with_data": filled, "session": new_session}


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

    try:
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
        return {"bytes": len(fit_bytes), "errors": errors, "messages": messages}
    except Exception as e:
        return {
            "bytes":        len(fit_bytes),
            "decode_error": str(e),
            "hex_preview":  fit_bytes[:64].hex(),
            "stream_api":   [m for m in dir(Stream) if not m.startswith("_")],
        }
