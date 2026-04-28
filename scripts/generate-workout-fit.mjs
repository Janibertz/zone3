#!/usr/bin/env node
/**
 * Garmin-compatible FIT workout file generator using the official @garmin/fitsdk.
 *
 * Usage: echo '<json>' | node scripts/generate-workout-fit.mjs
 * Output: binary FIT file on stdout
 *
 * Expected JSON input:
 * {
 *   "name": "Workout Name",      // max 16 chars
 *   "steps": [
 *     { "name": "Aufwärmen", "meters": 700, "speedMps": null },
 *     { "name": "Hauptteil", "meters": 12600, "speedMps": 3.03 },
 *     { "name": "Auslaufen",  "meters": 700, "speedMps": null }
 *   ]
 * }
 */

import { Encoder, Profile } from '@garmin/fitsdk';

// Read JSON from stdin
const chunks = [];
for await (const chunk of process.stdin) {
    chunks.push(chunk);
}
const workout = JSON.parse(Buffer.concat(chunks).toString('utf8'));

const encoder = new Encoder();

// ── 1. File ID ────────────────────────────────────────────────
encoder.onMesg(Profile.MesgNum.FILE_ID, {
    type:        'workout',
    manufacturer: 'development',
    product:     0,
    timeCreated: new Date(),
});

// ── 2. Workout ────────────────────────────────────────────────
encoder.onMesg(Profile.MesgNum.WORKOUT, {
    sport:         'running',
    numValidSteps: workout.steps.length,
    wktName:       workout.name.slice(0, 15),
});

// ── 3. Workout Steps ──────────────────────────────────────────
const numSteps = workout.steps.length;
workout.steps.forEach((step, i) => {
    const stepData = {
        messageIndex:  i,
        wktStepName:   step.name.slice(0, 15),
        durationType:  'distance',
        durationValue: Math.max(100, step.meters) * 100, // FIT unit: m × 100 (cm)
        intensity:     i === 0 ? 'warmup'
                      : i === numSteps - 1 ? 'cooldown'
                      : 'active',
    };

    if (step.speedMps && step.speedMps > 0) {
        // Custom speed range ±5 % around target, stored in mm/s
        stepData.targetType             = 'speed';
        stepData.customTargetValueLow   = Math.round(step.speedMps * 0.95 * 1000);
        stepData.customTargetValueHigh  = Math.round(step.speedMps * 1.05 * 1000);
    } else {
        stepData.targetType = 'open';
    }

    encoder.onMesg(Profile.MesgNum.WORKOUT_STEP, stepData);
});

// ── Output binary FIT to stdout ───────────────────────────────
const fitBytes = encoder.close();
process.stdout.write(Buffer.from(fitBytes));
