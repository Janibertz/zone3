#!/usr/bin/env node
/**
 * Garmin FIT workout file generator — pure binary encoding, no SDK required.
 *
 * Input:  JSON via stdin  { name: string, steps: [{ name, meters, speedMps }] }
 * Output: FIT binary to stdout
 */

// ── Garmin CRC-16 ────────────────────────────────────────────────────────────

const CRC_TABLE = [
    0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401,
    0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400,
];

function calcCrc(buf) {
    let crc = 0;
    for (let i = 0; i < buf.length; i++) {
        const b = buf[i];
        let tmp = CRC_TABLE[crc & 0xF];
        crc = (crc >> 4) & 0x0FFF;
        crc ^= tmp ^ CRC_TABLE[b & 0xF];
        tmp = CRC_TABLE[crc & 0xF];
        crc = (crc >> 4) & 0x0FFF;
        crc ^= tmp ^ CRC_TABLE[(b >> 4) & 0xF];
    }
    return crc;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Null-padded ASCII string of exactly `size` bytes (strips non-ASCII chars). */
function fitStr(str, size) {
    const buf = Buffer.alloc(size, 0);
    // FIT strings must be ASCII — replace umlauts with ASCII equivalents
    const ascii = str
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue')
        .replace(/Ä/g, 'Ae').replace(/Ö/g, 'Oe').replace(/Ü/g, 'Ue')
        .replace(/ß/g, 'ss')
        .replace(/[^\x20-\x7E]/g, '');
    const bytes = Buffer.from(ascii.slice(0, size - 1), 'ascii');
    bytes.copy(buf);
    return buf;
}

/** FIT definition message for a local message type. */
function defMsg(localNum, globalNum, fields) {
    // fields: [[fieldNum, byteSize, baseType], ...]
    const buf = Buffer.alloc(6 + fields.length * 3);
    let o = 0;
    buf[o++] = 0x40 | localNum;         // definition record header
    buf[o++] = 0x00;                     // reserved
    buf[o++] = 0x00;                     // architecture: little-endian
    buf.writeUInt16LE(globalNum, o); o += 2;
    buf[o++] = fields.length;
    for (const [fn, fs, fb] of fields) {
        buf[o++] = fn; buf[o++] = fs; buf[o++] = fb;
    }
    return buf;
}

// ── FIT epoch ─────────────────────────────────────────────────────────────────

const FIT_EPOCH_UNIX = 631065600; // Dec 31 1989 00:00:00 UTC as Unix timestamp

// ── Main ──────────────────────────────────────────────────────────────────────

const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const workout = JSON.parse(Buffer.concat(chunks).toString('utf8'));

const fitNow  = Math.max(0, Math.floor(Date.now() / 1000) - FIT_EPOCH_UNIX);
const steps   = workout.steps ?? [];
const numSteps = steps.length;
const parts   = [];

// ── 1. file_id (global message 0) ─────────────────────────────────────────────
// Fields: type(0), manufacturer(1), time_created(4)
parts.push(defMsg(0, 0, [
    [0, 1, 0x00],  // type:         enum (1 byte)
    [1, 2, 0x84],  // manufacturer: uint16
    [4, 4, 0x86],  // time_created: uint32
]));
{
    const buf = Buffer.alloc(1 + 1 + 2 + 4);
    let o = 0;
    buf[o++] = 0x00;                      // record header: local msg 0
    buf[o++] = 5;                         // type = workout (5)
    buf.writeUInt16LE(255, o); o += 2;    // manufacturer = development (255)
    buf.writeUInt32LE(fitNow, o);         // time_created
    parts.push(buf);
}

// ── 2. workout (global message 26) ────────────────────────────────────────────
// Fields: sport(4), num_valid_steps(6), wkt_name(8)
parts.push(defMsg(1, 26, [
    [4,  1,  0x00],  // sport:          enum
    [6,  2,  0x84],  // num_valid_steps: uint16
    [8, 16,  0x07],  // wkt_name:       string (16 bytes)
]));
{
    const nameBuf = fitStr(workout.name || 'Training', 16);
    const buf = Buffer.alloc(1 + 1 + 2 + 16);
    let o = 0;
    buf[o++] = 0x01;                         // record header: local msg 1
    buf[o++] = 1;                            // sport = running (1)
    buf.writeUInt16LE(numSteps, o); o += 2;  // num_valid_steps
    nameBuf.copy(buf, o);                    // wkt_name
    parts.push(buf);
}

// ── 3. workout_step (global message 27) ───────────────────────────────────────
// Fields: message_index(254), wkt_step_name(0), duration_type(1),
//         duration_value(2), target_type(3), target_value(4),
//         custom_target_value_low(5), custom_target_value_high(6), intensity(7)
parts.push(defMsg(2, 27, [
    [254, 2, 0x84],  // message_index:           uint16
    [  0,16, 0x07],  // wkt_step_name:           string
    [  1, 1, 0x00],  // duration_type:           enum  (1 = distance)
    [  2, 4, 0x86],  // duration_value:          uint32 (cm when distance)
    [  3, 1, 0x00],  // target_type:             enum  (0=speed, 2=open)
    [  4, 4, 0x86],  // target_value:            uint32
    [  5, 4, 0x86],  // custom_target_value_low: uint32 (mm/s)
    [  6, 4, 0x86],  // custom_target_value_high:uint32 (mm/s)
    [  7, 1, 0x00],  // intensity:               enum  (0=active,2=warmup,3=cooldown)
]));

for (let i = 0; i < numSteps; i++) {
    const step = steps[i];
    // intensity: first step = warmup, last step = cooldown, rest = active
    const intensity = i === 0 ? 2 : (i === numSteps - 1 ? 3 : 0);
    const distanceCm = Math.max(100, step.meters ?? 1000) * 100; // FIT: centimeters

    const stepNameBuf = fitStr(step.name || `Step ${i + 1}`, 16);
    const buf = Buffer.alloc(1 + 2 + 16 + 1 + 4 + 1 + 4 + 4 + 4 + 1);
    let o = 0;
    buf[o++] = 0x02;                             // record header: local msg 2
    buf.writeUInt16LE(i, o); o += 2;             // message_index
    stepNameBuf.copy(buf, o); o += 16;           // wkt_step_name
    buf[o++] = 1;                                // duration_type = distance
    buf.writeUInt32LE(distanceCm, o); o += 4;    // duration_value (cm)

    if (step.speedMps && step.speedMps > 0) {
        const lo = Math.round(step.speedMps * 0.95 * 1000); // mm/s
        const hi = Math.round(step.speedMps * 1.05 * 1000); // mm/s
        buf[o++] = 0;                            // target_type = speed (custom range)
        buf.writeUInt32LE(0, o); o += 4;         // target_value = 0 (use custom fields)
        buf.writeUInt32LE(lo, o); o += 4;        // custom_target_value_low
        buf.writeUInt32LE(hi, o); o += 4;        // custom_target_value_high
    } else {
        buf[o++] = 2;                            // target_type = open
        buf.writeUInt32LE(0xFFFFFFFF, o); o += 4;
        buf.writeUInt32LE(0xFFFFFFFF, o); o += 4;
        buf.writeUInt32LE(0xFFFFFFFF, o); o += 4;
    }

    buf[o++] = intensity;
    parts.push(buf);
}

// ── Assemble data + header + file CRC ─────────────────────────────────────────

const data = Buffer.concat(parts);

// 14-byte file header
const headerBase = Buffer.alloc(12);
headerBase[0] = 14;                                  // header size
headerBase[1] = 0x20;                                // protocol version 2.0
headerBase.writeUInt16LE(2132, 2);                   // profile version
headerBase.writeUInt32LE(data.length, 4);            // data size
Buffer.from('.FIT').copy(headerBase, 8);             // magic

const headerCrc = calcCrc(headerBase);
const header = Buffer.alloc(14);
headerBase.copy(header);
header.writeUInt16LE(headerCrc, 12);

// File CRC over header + data
const fileCrc = calcCrc(Buffer.concat([header, data]));
const fileCrcBuf = Buffer.alloc(2);
fileCrcBuf.writeUInt16LE(fileCrc);

process.stdout.write(Buffer.concat([header, data, fileCrcBuf]));
