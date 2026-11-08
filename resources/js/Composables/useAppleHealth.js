import { ref } from 'vue';
import axios from 'axios';
import { nativePlugin } from '@/Composables/useNativeApp';

/**
 * Apple Health in der iOS-App (@capgo/capacitor-health, JS-Name „Health“).
 *
 * Gelesen wird, was Jan freigegeben hat (08.11.2026, „Watch-Läufe +
 * Erholung“): Schlafdauer, HRV, Ruhepuls, VO2max, Blutsauerstoff,
 * Atemfrequenz — und die Trainings mit Herzfrequenz und Strecke. Kein
 * Gewicht, keine Schritte, nie Zyklusdaten; jede weitere Art steht im
 * iOS-Dialog und schreckt ab.
 *
 * HealthKit verrät nie, ob Lesen erlaubt wurde (Datenschutz): eine
 * Ablehnung sieht aus wie „keine Daten“. Deshalb merkt sich die Seite nur,
 * dass gefragt wurde, und liest dann einfach.
 */
export const APPLE_HEALTH_TYPES = [
    'sleep', 'heartRateVariability', 'restingHeartRate', 'vo2Max',
    'oxygenSaturation', 'respiratoryRate',
    'workouts', 'heartRate', 'distance',
];

// Mit jeder neuen Art eine neue Fassung: iOS fragt nur nach Arten, über die
// noch nicht entschieden ist, und die Karte soll dann wieder „Freigeben“ zeigen.
const ASKED_KEY = 'apple_health_asked_v2';
const SYNC_KEY = 'apple_health_sent_at';

/** Schlafphasen, die als Schlaf zählen; „im Bett“ und „wach“ nicht. */
const ASLEEP = new Set(['asleep', 'rem', 'deep', 'light']);

function localDate(iso) {
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Schlaf je Nacht in Minuten, dem Tag des Aufwachens zugeordnet (wie bei
 * Garmin). iPhone und Watch schreiben oft beide — die Abschnitte werden
 * deshalb vereinigt statt addiert, sonst zählte eine Nacht doppelt.
 */
export function sleepMinutesByDay(samples) {
    const byDay = {};
    for (const s of samples) {
        const segments = s.stages?.length
            ? s.stages.filter((st) => ASLEEP.has(st.stage)).map((st) => [st.startDate, st.endDate])
            : (ASLEEP.has(s.sleepState) ? [[s.startDate, s.endDate]] : []);
        for (const [start, end] of segments) {
            const a = Date.parse(start);
            const b = Date.parse(end);
            if (!(b > a)) continue;
            (byDay[localDate(end)] ??= []).push([a, b]);
        }
    }

    const out = {};
    for (const [day, ranges] of Object.entries(byDay)) {
        ranges.sort((x, y) => x[0] - y[0]);
        let total = 0;
        let [curA, curB] = ranges[0];
        for (const [a, b] of ranges.slice(1)) {
            if (a <= curB) { curB = Math.max(curB, b); continue; }
            total += curB - curA;
            [curA, curB] = [a, b];
        }
        total += curB - curA;
        out[day] = Math.round(total / 60000);
    }
    return out;
}

/** Mittelwert je Tag (HRV, Ruhepuls, Atemfrequenz, Blutsauerstoff). */
export function averageByDay(samples, map = (v) => v) {
    const sums = {};
    for (const s of samples) {
        if (typeof s.value !== 'number' || !(s.value > 0)) continue;
        const day = localDate(s.startDate);
        (sums[day] ??= [0, 0]);
        sums[day][0] += map(s.value);
        sums[day][1] += 1;
    }
    return Object.fromEntries(Object.entries(sums).map(([d, [sum, n]]) => [d, Math.round((sum / n) * 10) / 10]));
}

/** Jüngster Wert je Tag (VO2max kommt höchstens einmal am Tag). */
export function latestByDay(samples) {
    const out = {};
    const at = {};
    for (const s of samples) {
        if (typeof s.value !== 'number' || !(s.value > 0)) continue;
        const day = localDate(s.startDate);
        const t = Date.parse(s.startDate);
        if (at[day] === undefined || t > at[day]) { at[day] = t; out[day] = Math.round(s.value * 10) / 10; }
    }
    return out;
}

/** HealthKit liefert Blutsauerstoff als Anteil (0,97), Zone3 rechnet in Prozent. */
export const asPercent = (v) => (v <= 1.5 ? v * 100 : v);

/** Tageszeilen, neueste zuerst: {date, sleep_min, hrv_ms, resting_hr, vo2max, spo2, respiration}. */
export function combineDays({ sleep = {}, hrv = {}, resting = {}, vo2 = {}, spo2 = {}, respiration = {} }) {
    const sources = [sleep, hrv, resting, vo2, spo2, respiration];
    const days = new Set(sources.flatMap((o) => Object.keys(o)));
    return [...days].sort().reverse().map((date) => ({
        date,
        sleep_min: sleep[date] ?? null,
        hrv_ms: hrv[date] ?? null,
        resting_hr: resting[date] ?? null,
        vo2max: vo2[date] ?? null,
        spo2: spo2[date] != null ? Math.round(spo2[date] * 10) / 10 : null,
        respiration: respiration[date] ?? null,
    }));
}

function inWindow(samples, from, to) {
    return samples.filter((s) => {
        const t = Date.parse(s.startDate);
        return t >= from && t < to;
    });
}

function hrStats(samples) {
    const values = samples.map((s) => s.value).filter((v) => typeof v === 'number' && v > 0);
    if (!values.length) return { avg: null, max: null };
    return {
        avg: Math.round((values.reduce((a, b) => a + b, 0) / values.length) * 10) / 10,
        max: Math.round(Math.max(...values)),
    };
}

/**
 * Strecke als Zeitreihe [Sekunden ab Start, Meter gesamt] aus den
 * Streckenabschnitten der Watch — daraus rechnet der Server die Bestzeiten.
 * Höchstens `max` Punkte, der letzte bleibt immer.
 */
export function distanceStream(samples, startMs, max = 2000) {
    const sorted = [...samples].sort((a, b) => Date.parse(a.endDate) - Date.parse(b.endDate));
    const points = [[0, 0]];
    let total = 0;
    for (const s of sorted) {
        if (typeof s.value !== 'number' || !(s.value > 0)) continue;
        total += s.value;
        points.push([Math.round((Date.parse(s.endDate) - startMs) / 1000), Math.round(total * 10) / 10]);
    }
    if (points.length <= max) return points.length > 1 ? points : [];
    const step = Math.ceil(points.length / max);
    const kept = points.filter((_, i) => i % step === 0);
    if (kept[kept.length - 1] !== points[points.length - 1]) kept.push(points[points.length - 1]);
    return kept;
}

/**
 * Ein Training aus HealthKit, so wie `POST /health/apple/workouts` es
 * erwartet: Summen, Herzfrequenz, Runden (aus den Rundenmarken) und für
 * Läufe die Streckenreihe.
 */
export function workoutPayload(w, heartRate = [], distance = []) {
    const start = Date.parse(w.startDate);
    const end = Date.parse(w.endDate);
    const hr = hrStats(inWindow(heartRate, start, end));
    const isRun = ['running', 'runningTreadmill', 'trackAndField'].includes(w.workoutType);

    const laps = (w.workoutEvents ?? [])
        .filter((e) => e.type === 'lap' && e.durationSeconds > 0)
        .map((e) => {
            const from = Date.parse(e.date);
            const to = from + e.durationSeconds * 1000;
            const lapHr = hrStats(inWindow(heartRate, from, to));
            const lapDistance = e.distanceMeters
                ?? inWindow(distance, from, to).reduce((sum, s) => sum + (s.value > 0 ? s.value : 0), 0);
            return {
                duration_s: Math.round(e.durationSeconds),
                distance_m: lapDistance ? Math.round(lapDistance * 10) / 10 : null,
                avg_hr: lapHr.avg,
                max_hr: lapHr.max,
            };
        });

    return {
        start: new Date(start).toISOString(),
        end: new Date(end).toISOString(),
        type: w.workoutType,
        duration_s: Math.round(w.duration ?? (end - start) / 1000),
        distance_m: w.totalDistance ?? null,
        avg_hr: hr.avg,
        max_hr: hr.max,
        source_name: w.sourceName ?? null,
        laps,
        stream: isRun ? distanceStream(inWindow(distance, start, end + 1000), start) : [],
    };
}

export function useAppleHealth() {
    const plugin = nativePlugin('Health');
    const supported = ref(!!plugin);
    const available = ref(false);
    const asked = ref(false);
    const busy = ref(false);
    const error = ref(null);
    const days = ref([]);

    try { asked.value = !!localStorage.getItem(ASKED_KEY); } catch { /* privat */ }

    async function check() {
        if (!plugin) return false;
        try {
            available.value = !!(await plugin.isAvailable())?.available;
        } catch {
            available.value = false;
        }
        return available.value;
    }

    async function read(dataType, start, end, limit = 5000) {
        try {
            const { samples = [] } = await plugin.readSamples({
                dataType,
                startDate: start.toISOString(),
                endDate: end.toISOString(),
                limit,
                ascending: true,
            });
            return samples;
        } catch {
            // Eine nicht freigegebene oder auf dem Gerät fehlende Art soll
            // die anderen nicht mitreißen.
            return [];
        }
    }

    function dayStart(n) {
        const start = new Date();
        start.setHours(0, 0, 0, 0);
        start.setDate(start.getDate() - (n - 1));
        return start;
    }

    /** Tageswerte der letzten `n` Tage (Schlaf bis heute Morgen), ohne Zustand. */
    async function readDays(n = 7) {
        const end = new Date();
        const start = dayStart(n);
        // Die Nacht zum ersten Tag beginnt am Abend davor.
        const sleepStart = new Date(start.getTime() - 12 * 3600 * 1000);

        const [sleep, hrv, resting, vo2, spo2, respiration] = await Promise.all([
            read('sleep', sleepStart, end),
            read('heartRateVariability', start, end),
            read('restingHeartRate', start, end),
            read('vo2Max', start, end),
            read('oxygenSaturation', start, end, 20000),
            read('respiratoryRate', start, end, 20000),
        ]);

        const startDay = localDate(start.toISOString());
        const sleepDays = Object.fromEntries(Object.entries(sleepMinutesByDay(sleep)).filter(([d]) => d >= startDay));

        return combineDays({
            sleep: sleepDays,
            hrv: averageByDay(hrv),
            resting: averageByDay(resting),
            vo2: latestByDay(vo2),
            spo2: averageByDay(spo2, asPercent),
            respiration: averageByDay(respiration),
        });
    }

    /** Trainings der letzten `n` Tage samt Herzfrequenz und Strecke, als Nutzlast für den Server. */
    async function readWorkouts(n = 3) {
        const end = new Date();
        const start = dayStart(n);
        let workouts = [];
        try {
            ({ workouts = [] } = await plugin.queryWorkouts({
                startDate: start.toISOString(),
                endDate: end.toISOString(),
                limit: 200,
                ascending: true,
            }));
        } catch {
            return [];
        }

        const out = [];
        // Nacheinander: ein Rückblick über sechs Wochen sind sonst hunderte
        // gleichzeitige HealthKit-Abfragen.
        for (const w of workouts) {
            const from = new Date(Date.parse(w.startDate) - 1000);
            const to = new Date(Date.parse(w.endDate) + 1000);
            const [heartRate, distance] = await Promise.all([
                read('heartRate', from, to, 20000),
                read('distance', from, to, 20000),
            ]);
            out.push(workoutPayload(w, heartRate, distance));
        }
        return out;
    }

    /** Liest die letzten `n` Tage für die Vorschau im Profil. */
    async function load(n = 7) {
        if (!plugin) return [];
        busy.value = true;
        error.value = null;
        try {
            days.value = await readDays(n);
            return days.value;
        } catch (e) {
            error.value = e?.message || 'Apple Health antwortet nicht.';
            return [];
        } finally {
            busy.value = false;
        }
    }

    /** Zeigt den iOS-Dialog (nur beim ersten Mal; danach entscheiden die Einstellungen). */
    async function connect() {
        if (!plugin) return false;
        busy.value = true;
        error.value = null;
        try {
            await plugin.requestAuthorization({ read: APPLE_HEALTH_TYPES, write: [] });
            try { localStorage.setItem(ASKED_KEY, new Date().toISOString()); } catch { /* privat */ }
            asked.value = true;
        } catch (e) {
            error.value = e?.message || 'Apple Health hat die Anfrage abgelehnt.';
            return false;
        } finally {
            busy.value = false;
        }
        await load();
        return true;
    }

    return { supported, available, asked, busy, error, days, check, connect, load, readDays, readWorkouts };
}

/**
 * Schickt Erholungswerte und Trainings an den Server — beim Öffnen und
 * beim Zurückkehren in die App, höchstens alle 15 Minuten. `state` ist
 * `auth.appleHealth` (null = keine Einwilligung → nichts lesen).
 *
 * Beim ersten Mal liest die App weiter zurück: 30 Tage Erholung (für die
 * Grundlinie), 42 Tage Trainings (Schwelle, Umfang und VDOT brauchen sechs
 * Wochen). Danach reichen drei Tage — der Schlaf eines Tages kommt erst
 * nach dem Aufwachen, und die Watch gibt ein Training manchmal später ab.
 */
export async function syncAppleHealth(state, { force = false } = {}) {
    if (!state || (!state.metrics && !state.workouts)) return null;
    const health = useAppleHealth();
    if (!health.supported.value || !health.asked.value) return null;

    try {
        const last = parseInt(localStorage.getItem(SYNC_KEY) ?? '0', 10);
        if (!force && Date.now() - last < 15 * 60 * 1000) return null;
        localStorage.setItem(SYNC_KEY, String(Date.now()));
    } catch { /* privat */ }

    if (!(await health.check())) return null;

    const result = {};
    if (state.metrics) {
        const days = await health.readDays(state.metrics_synced ? 3 : 30);
        result.metrics = (await axios.post(route('apple-health.metrics'), { days })).data;
    }
    if (state.workouts) {
        const workouts = await health.readWorkouts(state.workouts_synced ? 3 : 42);
        result.workouts = (await axios.post(route('apple-health.workouts'), { workouts })).data;
    }
    return result;
}
