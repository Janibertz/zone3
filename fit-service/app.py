"""
Garmin FIT Workout File Generator — Python Microservice
POST /generate-fit  →  binary .fit file
GET  /health        →  {"status": "ok"}
"""

import struct
import time
from typing import Optional
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

app = FastAPI(title="FIT Workout Generator")

# ── Garmin CRC-16 ─────────────────────────────────────────────────────────────

_CRC_TABLE = [
    0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401,
    0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400,
]

def _crc(data: bytes) -> int:
    crc = 0
    for b in data:
        tmp = _CRC_TABLE[crc & 0xF]
        crc = (crc >> 4) & 0x0FFF
        crc ^= tmp ^ _CRC_TABLE[b & 0xF]
        tmp = _CRC_TABLE[crc & 0xF]
        crc = (crc >> 4) & 0x0FFF
        crc ^= tmp ^ _CRC_TABLE[(b >> 4) & 0xF]
    return crc

# ── FIT helpers ───────────────────────────────────────────────────────────────

_FIT_EPOCH = 631065600  # Dec 31 1989 00:00:00 UTC as Unix timestamp


def _fit_str(value: str, size: int) -> bytes:
    """ASCII null-padded FIT string. Normalises German umlauts."""
    for k, v in [("ä","ae"),("ö","oe"),("ü","ue"),("Ä","Ae"),("Ö","Oe"),("Ü","Ue"),("ß","ss")]:
        value = value.replace(k, v)
    ascii_str = "".join(c for c in value if 0x20 <= ord(c) <= 0x7E)
    encoded = ascii_str[: size - 1].encode("ascii")
    return encoded + b"\x00" * (size - len(encoded))


def _def_msg(local_num: int, global_num: int, fields: list[tuple]) -> bytes:
    """Build a FIT definition message."""
    result = bytes([0x40 | local_num, 0x00, 0x00])  # header, reserved, little-endian
    result += struct.pack("<H", global_num)
    result += bytes([len(fields)])
    for fn, fs, fb in fields:
        result += bytes([fn, fs, fb])
    return result


# ── Core FIT encoder ──────────────────────────────────────────────────────────

def build_fit(name: str, steps: list[dict]) -> bytes:
    fit_now = max(0, int(time.time()) - _FIT_EPOCH)
    num_steps = len(steps)
    data = b""

    # ── file_id (global 0): type, manufacturer, time_created ──────────────────
    data += _def_msg(0, 0, [(0, 1, 0x00), (1, 2, 0x84), (4, 4, 0x86)])
    data += bytes([0x00, 5])                  # record header + type = workout (5)
    data += struct.pack("<H", 255)            # manufacturer = development
    data += struct.pack("<I", fit_now)        # time_created

    # ── workout (global 26): sport, num_valid_steps, wkt_name ─────────────────
    data += _def_msg(1, 26, [(4, 1, 0x00), (6, 2, 0x84), (8, 16, 0x07)])
    data += bytes([0x01, 1])                  # record header + sport = running (1)
    data += struct.pack("<H", num_steps)      # num_valid_steps
    data += _fit_str(name or "Training", 16) # wkt_name

    # ── workout_step (global 27) ───────────────────────────────────────────────
    data += _def_msg(2, 27, [
        (254, 2, 0x84),  # message_index
        (  0,16, 0x07),  # wkt_step_name
        (  1, 1, 0x00),  # duration_type  (1 = distance)
        (  2, 4, 0x86),  # duration_value (centimeters)
        (  3, 1, 0x00),  # target_type    (0 = speed, 2 = open)
        (  4, 4, 0x86),  # target_value
        (  5, 4, 0x86),  # custom_target_value_low  (mm/s)
        (  6, 4, 0x86),  # custom_target_value_high (mm/s)
        (  7, 1, 0x00),  # intensity      (0=active, 2=warmup, 3=cooldown)
    ])

    for i, step in enumerate(steps):
        intensity = 2 if i == 0 else (3 if i == num_steps - 1 else 0)
        dist_cm = max(100, int(step.get("meters") or 1000)) * 100

        row = bytes([0x02])                         # record header
        row += struct.pack("<H", i)                 # message_index
        row += _fit_str(step.get("name") or f"Step {i+1}", 16)
        row += bytes([1])                           # duration_type = distance
        row += struct.pack("<I", dist_cm)           # duration_value

        speed = step.get("speedMps")
        if speed and speed > 0:
            lo = int(speed * 0.95 * 1000)
            hi = int(speed * 1.05 * 1000)
            row += bytes([0])                       # target_type = speed (custom)
            row += struct.pack("<I", 0)             # target_value = 0 → use custom
            row += struct.pack("<I", lo)
            row += struct.pack("<I", hi)
        else:
            row += bytes([2])                       # target_type = open
            row += struct.pack("<I", 0xFFFFFFFF) * 3

        row += bytes([intensity])
        data += row

    # ── File header (14 bytes) + file CRC ─────────────────────────────────────
    header_base = bytes([14, 0x20])              # header size, protocol version
    header_base += struct.pack("<H", 2132)       # profile version
    header_base += struct.pack("<I", len(data))  # data size
    header_base += b".FIT"

    header_crc = _crc(header_base)
    header = header_base + struct.pack("<H", header_crc)

    file_crc = _crc(header + data)
    return header + data + struct.pack("<H", file_crc)


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

    safe_name = "".join(c if c.isalnum() or c in "-_" else "-" for c in req.name)[:40]
    return Response(
        content=fit_bytes,
        media_type="application/vnd.ant.fit",
        headers={"Content-Disposition": f'attachment; filename="{safe_name}.fit"'},
    )


@app.get("/health")
def health():
    return {"status": "ok"}
