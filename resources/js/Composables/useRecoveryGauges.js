/**
 * Die Kacheln unter „Körper“ — Garmin-Bögen statt einfacher Ringe.
 *
 * Zwei Sorten Bogen (Entscheidung von Jan, 28.10.2026, Vorbild Garmin Connect):
 *
 *  - `fill`: feste Skala (Schlafwert, Body Battery, VO2max, Tagesform). Der
 *    Bogen füllt sich bis zum Wert, jede Stufe in ihrer Farbe.
 *  - `mark`: keine feste Skala (HRV, Ruhepuls). Der Bogen zeigt den eigenen
 *    Normalbereich (60-Tage-Schnitt ± Streuung) grün, daneben orange; ein
 *    Punkt markiert den Wert.
 *
 * Die Farbe sitzt im Bogen und im Status, die Zahl bleibt schwarz.
 */

/** Farbe je Ton — für SVG-Striche und Statustext. */
export const TONE_COLOR = {
    danger:  'rgb(var(--z-danger))',
    warn:    'rgb(var(--z-warn))',
    success: 'rgb(var(--z-success))',
    accent:  'rgb(var(--z-accent))',
    sky:     'rgb(var(--z-accent) / 0.5)',
    fitness: 'rgb(var(--z-fitness))',
    none:    'rgb(var(--z-ink-3))',
};

/** Garmins Schlafwert-Stufen. */
export const SLEEP_SCORE_ZONES = [
    { from: 0,  to: 60,  tone: 'danger',  label: 'Schlecht' },
    { from: 60, to: 80,  tone: 'warn',    label: 'Ordentlich' },
    { from: 80, to: 90,  tone: 'success', label: 'Gut' },
    { from: 90, to: 100, tone: 'accent',  label: 'Hervorragend' },
];

const SLEEP_HOURS_ZONES = [
    { from: 0, to: 6,  tone: 'danger',  label: 'Kurze Nacht' },
    { from: 6, to: 7,  tone: 'warn',    label: 'Knapp' },
    { from: 7, to: 10, tone: 'success', label: 'Ausreichend' },
];

const BODY_BATTERY_ZONES = [
    { from: 0,  to: 25,  tone: 'danger', label: 'Sehr niedrig' },
    { from: 25, to: 50,  tone: 'warn',   label: 'Niedrig' },
    { from: 50, to: 75,  tone: 'sky',    label: 'Mittel' },
    { from: 75, to: 100, tone: 'accent', label: 'Hoch' },
];

/** Stufe, in die ein Wert fällt (obere Grenze gehört zur nächsten Stufe). */
export function zoneOf(zones, v) {
    if (v == null) return null;
    return zones.find((z, i) => v < z.to || i === zones.length - 1) ?? null;
}

function nf(v, digits = 0) {
    return Number(v).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function hm(hours) {
    const min = Math.round(hours * 60);
    return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')} h`;
}

function daysBetween(a, b) {
    return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000);
}

/**
 * Neuester Wert einer Kennzahl. Ein Tag kommt bei Garmin in Teilen — morgens
 * steht oft erst der Schlaf da, die HRV später. Deshalb pro Kennzahl der
 * jüngste Wert, höchstens `maxAge` Tage älter als der neueste Tag.
 */
function recent(series, key, maxAge = 1) {
    if (!series.length) return null;
    const newest = series[series.length - 1].date;
    for (let i = series.length - 1; i >= 0; i--) {
        const r = series[i];
        if (maxAge != null && daysBetween(r.date, newest) > maxAge) return null;
        if (r[key] != null) return { value: r[key], date: r.date };
    }
    return null;
}

function baseline(series, key) {
    const a = series.map(r => r[key]).filter(v => v != null);
    if (a.length < 7) return null;
    const mean = a.reduce((s, v) => s + v, 0) / a.length;
    const sd = Math.sqrt(a.reduce((s, v) => s + (v - mean) ** 2, 0) / a.length);
    return { mean, sd };
}

/**
 * Bogen für eine Kennzahl ohne feste Skala: Normalbereich ± 1 Streuung,
 * angezeigte Spanne ± 3. `minSd` verhindert einen Bereich, der bei sehr
 * gleichmäßigen Werten auf einen Strich schrumpft.
 */
function relativeZones(base, minSd) {
    const sd = Math.max(base.sd, minSd);
    const lo = base.mean - sd, hi = base.mean + sd;
    return {
        min: base.mean - 3 * sd,
        max: base.mean + 3 * sd,
        lo, hi,
        zones: [
            { from: base.mean - 3 * sd, to: lo, tone: 'warn' },
            { from: lo, to: hi, tone: 'success' },
            { from: hi, to: base.mean + 3 * sd, tone: 'warn' },
        ],
    };
}

function relativeStatus(v, r) {
    if (v < r.lo) return { text: 'Niedrig', tone: 'warn' };
    if (v > r.hi) return { text: 'Erhöht', tone: 'warn' };
    return null;
}

function sleepTile(series) {
    const score = recent(series, 'sleep_score');
    const hours = recent(series, 'sleep_hours');

    if (score) {
        const z = zoneOf(SLEEP_SCORE_ZONES, score.value);
        return {
            key: 'sleep', icon: 'sleep', label: 'Schlafwert', mode: 'fill',
            value: score.value, display: String(score.value), unit: null,
            min: 0, max: 100, zones: SLEEP_SCORE_ZONES,
            status: { text: z.label, tone: z.tone },
            hint: hours ? `${hm(hours.value)} Schlaf` : null,
        };
    }
    // Ohne Schlafwert (alter Login) bleibt die Dauer.
    const z = hours ? zoneOf(SLEEP_HOURS_ZONES, hours.value) : null;
    return {
        key: 'sleep', icon: 'sleep', label: 'Schlaf', mode: 'fill',
        value: hours?.value ?? null, display: hours ? nf(hours.value, 1) : '–', unit: 'h',
        min: 0, max: 10, zones: SLEEP_HOURS_ZONES,
        status: z ? { text: z.label, tone: z.tone } : null,
        hint: null,
    };
}

function bodyBatteryTile(series) {
    const high = recent(series, 'body_battery_high');
    const low  = high ? series.find(r => r.date === high.date)?.body_battery_low : null;
    const z = high ? zoneOf(BODY_BATTERY_ZONES, high.value) : null;
    return {
        key: 'body_battery', icon: 'battery', label: 'Body Battery', mode: 'fill',
        value: high?.value ?? null, display: high ? String(high.value) : '–', unit: null,
        min: 0, max: 100, zones: BODY_BATTERY_ZONES,
        status: z ? { text: z.label, tone: z.tone } : null,
        hint: low != null ? `Tief ${low}` : null,
    };
}

function hrvTile(series) {
    const last = recent(series, 'hrv');
    const base = baseline(series, 'hrv');
    const tile = {
        key: 'hrv', icon: 'hrv', label: 'HRV', mode: 'mark',
        value: last?.value ?? null, display: last ? nf(last.value) : '–', unit: 'ms',
        min: 0, max: 1, zones: [], status: null, hint: null,
    };
    if (!base) {
        return { ...tile, hint: 'Normalbereich nach 7 Nächten' };
    }

    const r = relativeZones(base, 3);
    // Status wie bei Garmin aus dem 7-Tage-Schnitt — eine einzelne Nacht
    // soll ihn nicht umwerfen. Der Punkt bleibt die letzte Nacht.
    const newest = series[series.length - 1].date;
    const week = series.filter(x => x.hrv != null && daysBetween(x.date, newest) < 7).map(x => x.hrv);
    const ref = week.length >= 3 ? week.reduce((s, v) => s + v, 0) / week.length : last?.value;

    return {
        ...tile,
        min: r.min, max: r.max, zones: r.zones,
        status: ref == null ? null : (relativeStatus(ref, r) ?? { text: 'Ausbalanciert', tone: 'success' }),
        hint: `Normal ${nf(r.lo)}–${nf(r.hi)}`,
    };
}

function restingHrTile(series) {
    const last = recent(series, 'resting_hr');
    const base = baseline(series, 'resting_hr');
    const tile = {
        key: 'resting_hr', icon: 'heart', label: 'Ruhepuls', mode: 'mark',
        value: last?.value ?? null, display: last ? nf(last.value) : '–', unit: 'bpm',
        min: 0, max: 1, zones: [], status: null, hint: null,
    };
    if (!base) {
        return { ...tile, hint: 'Normalbereich nach 7 Tagen' };
    }

    const r = relativeZones(base, 1.5);
    return {
        ...tile,
        min: r.min, max: r.max, zones: r.zones,
        status: last ? (relativeStatus(last.value, r) ?? { text: 'Normal', tone: 'success' }) : null,
        hint: `Schnitt ${nf(base.mean)} bpm`,
    };
}

/** Garmin schickt die VO2max nur bei Änderung — ohne Wert im Zeitraum keine Kachel. */
function vo2maxTile(series) {
    const last = recent(series, 'vo2max', null);
    if (!last) return null;

    // Veränderung zum Stand vor 30 Tagen (oder zum ältesten Wert danach).
    const before = series.filter(r => r.vo2max != null && daysBetween(r.date, last.date) >= 30).pop()
        ?? series.find(r => r.vo2max != null && r.date !== last.date);
    const delta = before ? last.value - before.vo2max : null;

    return {
        key: 'vo2max', icon: 'lungs', label: 'VO2max', mode: 'fill',
        value: last.value, display: nf(last.value, 1), unit: 'ml/kg/min',
        min: 30, max: 70, zones: [{ from: 30, to: 70, tone: 'fitness' }],
        status: null,
        hint: delta == null ? null
            : Math.abs(delta) < 0.05 ? 'unverändert'
            : `${delta > 0 ? '+' : '−'}${nf(Math.abs(delta), 1)} / 30 Tage`,
    };
}

/** Die Garmin-Kacheln. `metrics` = { latest, series } aus der Dashboard-Route. */
export function garminGauges(metrics) {
    const series = metrics?.series ?? [];
    if (!series.length) return [];

    return [
        sleepTile(series),
        bodyBatteryTile(series),
        hrvTile(series),
        restingHrTile(series),
        vo2maxTile(series),
    ].filter(Boolean);
}

const WELLBEING_ZONES = [
    { from: 0, to: 4,  tone: 'danger' },
    { from: 4, to: 7,  tone: 'warn' },
    { from: 7, to: 10, tone: 'success' },
];

/**
 * Ohne Garmin: der Check-in, dieselbe Darstellung. Muskelkater und Stress
 * sind invertiert — der Bogen zeigt, wie gut es geht, die Zahl bleibt der
 * eingetragene Wert.
 */
export function wellbeingGauges(w) {
    if (!w) return [];

    const tile = (key, icon, label, v, words, invert = false) => {
        const g = v == null ? null : (invert ? 11 - v : v);
        const zi = g == null ? -1 : WELLBEING_ZONES.indexOf(zoneOf(WELLBEING_ZONES, g));
        return {
            key, icon, label, mode: 'fill',
            value: g, display: v == null ? '–' : String(v), unit: '/ 10',
            min: 0, max: 10, zones: WELLBEING_ZONES,
            status: zi < 0 ? null : { text: words[zi], tone: WELLBEING_ZONES[zi].tone },
            hint: invert ? 'niedrig = gut' : null,
        };
    };

    return [
        tile('energy', 'energy', 'Energie', w.energy_level,    ['Schwach', 'Mittel', 'Gut']),
        tile('sleep',  'sleep',  'Schlaf',  w.sleep_quality,   ['Schlecht', 'Mittel', 'Gut']),
        tile('muscle', 'muscle', 'Muskeln', w.muscle_soreness, ['Stark', 'Spürbar', 'Locker'], true),
        tile('stress', 'stress', 'Stress',  w.stress_level,    ['Hoch', 'Mittel', 'Niedrig'], true),
    ];
}
