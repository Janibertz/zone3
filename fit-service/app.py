"""
Garmin FIT Workout File Generator — Python Microservice
Uses the official garmin-fit-sdk for encoding.

POST /generate-fit  →  binary .fit file
GET  /health        →  {"status": "ok"}
"""

import time
import struct
from typing import Optional
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

app = FastAPI(title="FIT Workout Generator")

FIT_EPOCH = 631065600  # Dec 31 1989 00:00:00 UTC

# ── Garmin FIT SDK encoder ────────────────────────────────────────────────────

def _fit_str_ascii(value: str) -> str:
    """Normalize German umlauts and strip non-ASCII for FIT string fields."""
    for k, v in [("ä","ae"),("ö","oe"),("ü","ue"),("Ä","Ae"),("Ö","Oe"),("Ü","Ue"),("ß","ss")]:
        value = value.replace(k, v)
    return "".join(c for c in value if 0x20 <= ord(c) <= 0x7E)


def build_fit_with_sdk(name: str, steps: list[dict]) -> bytes:
    """Encode workout using the official garmin-fit-sdk Encoder."""
    from garmin_fit_sdk import Encoder

    encoder = Encoder()
    fit_now = max(0, int(time.time()) - FIT_EPOCH)
    num_steps = len(steps)
    safe_name = _fit_str_ascii(name or "Training")[:15]

    # file_id (global message 0)
    encoder.on_mesg(0, {
        "type":         5,    # workout
        "manufacturer": 255,  # development
        "time_created": fit_now,
    })

    # workout (global message 26)
    encoder.on_mesg(26, {
        "sport":           1,          # running
        "num_valid_steps": num_steps,
        "wkt_name":        safe_name,
    })

    # workout_step (global message 27)
    for i, step in enumerate(steps):
        intensity = 2 if i == 0 else (3 if i == num_steps - 1 else 0)
        dist_cm   = max(100, int(step.get("meters") or 1000)) * 100
        step_name = _fit_str_ascii(step.get("name") or f"Step {i+1}")[:15]

        msg: dict = {
            "message_index":  i,
            "wkt_step_name":  step_name,
            "duration_type":  1,       # distance
            "duration_value": dist_cm,
            "intensity":      intensity,
        }

        speed = step.get("speedMps")
        if speed and speed > 0:
            msg["target_type"]              = 0   # speed (custom range)
            msg["target_value"]             = 0   # use custom fields
            msg["custom_target_value_low"]  = round(speed * 0.95 * 1000)
            msg["custom_target_value_high"] = round(speed * 1.05 * 1000)
        else:
            msg["target_type"] = 2  # open

        encoder.on_mesg(27, msg)

    return bytes(encoder.close())


# ── Binary fallback (no SDK dependency) ──────────────────────────────────────

_CRC_TABLE = [
    0x0000,0xCC01,0xD801,0x1400,0xF001,0x3C00,0x2800,0xE401,
    0xA001,0x6C00,0x7800,0xB401,0x5000,0x9C01,0x8801,0x4400,
]

def _crc(data: bytes) -> int:
    crc = 0
    for b in data:
        tmp = _CRC_TABLE[crc & 0xF]; crc = (crc >> 4) & 0x0FFF; crc ^= tmp ^ _CRC_TABLE[b & 0xF]
        tmp = _CRC_TABLE[crc & 0xF]; crc = (crc >> 4) & 0x0FFF; crc ^= tmp ^ _CRC_TABLE[(b >> 4) & 0xF]
    return crc

def _fit_str(value: str, size: int) -> bytes:
    buf = Buffer = bytearray(size)
    encoded = _fit_str_ascii(value)[: size - 1].encode("ascii")
    bytearray(buf)[:len(encoded)] = encoded
    return bytes(buf)

def _def_msg(local: int, global_num: int, fields: list) -> bytes:
    r = bytes([0x40 | local, 0, 0]) + struct.pack("<H", global_num) + bytes([len(fields)])
    for fn, fs, fb in fields:
        r += bytes([fn, fs, fb])
    return r

def build_fit_binary(name: str, steps: list[dict]) -> bytes:
    fit_now    = max(0, int(time.time()) - FIT_EPOCH)
    num_steps  = len(steps)
    safe_name  = _fit_str_ascii(name or "Training")
    data       = b""

    data += _def_msg(0, 0, [(0,1,0x00),(1,2,0x84),(4,4,0x86)])
    data += bytes([0,5]) + struct.pack("<H",255) + struct.pack("<I",fit_now)

    data += _def_msg(1, 26, [(4,1,0x00),(6,2,0x84),(8,16,0x07)])
    name_buf = bytearray(16); enc = safe_name[:15].encode("ascii"); name_buf[:len(enc)] = enc
    data += bytes([1,1]) + struct.pack("<H",num_steps) + bytes(name_buf)

    data += _def_msg(2, 27, [(254,2,0x84),(0,16,0x07),(1,1,0x00),(2,4,0x86),
                              (3,1,0x00),(4,4,0x86),(5,4,0x86),(6,4,0x86),(7,1,0x00)])
    for i, step in enumerate(steps):
        intensity = 2 if i == 0 else (3 if i == num_steps-1 else 0)
        dist_cm   = max(100, int(step.get("meters") or 1000)) * 100
        sname     = bytearray(16); senc = _fit_str_ascii(step.get("name") or f"Step {i+1}")[:15].encode("ascii"); sname[:len(senc)] = senc
        row = bytes([2]) + struct.pack("<H",i) + bytes(sname) + bytes([1]) + struct.pack("<I",dist_cm)
        speed = step.get("speedMps")
        if speed and speed > 0:
            row += bytes([0]) + struct.pack("<I",0) + struct.pack("<I",round(speed*0.95*1000)) + struct.pack("<I",round(speed*1.05*1000))
        else:
            row += bytes([2]) + struct.pack("<I",0xFFFFFFFF)*3
        row += bytes([intensity])
        data += row

    hb = bytes([14,0x20]) + struct.pack("<H",2132) + struct.pack("<I",len(data)) + b".FIT"
    hc = _crc(hb)
    header = hb + struct.pack("<H",hc)
    fc = _crc(header + data)
    return header + data + struct.pack("<H",fc)


def build_fit(name: str, steps: list[dict]) -> bytes:
    try:
        result = build_fit_with_sdk(name, steps)
        print(f"[FIT] Generated via garmin-fit-sdk: {len(result)} bytes", flush=True)
        return result
    except Exception as e:
        print(f"[FIT] SDK failed ({e}), using binary fallback", flush=True)
        return build_fit_binary(name, steps)


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
    fit_bytes = build_fit(req.name, [s.model_dump() for s in req.steps])
    safe = "".join(c if c.isalnum() or c in "-_" else "-" for c in req.name)[:40]
    return Response(
        content=fit_bytes,
        media_type="application/vnd.ant.fit",
        headers={"Content-Disposition": f'attachment; filename="{safe}.fit"'},
    )


@app.get("/health")
def health():
    return {"status": "ok"}
