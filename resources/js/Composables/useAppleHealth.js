import { ref } from 'vue';
import { nativePlugin } from '@/Composables/useNativeApp';

/**
 * Apple Health in der iOS-App (@capgo/capacitor-health, JS-Name „Health“).
 *
 * Gelesen werden genau vier Werte (Jan, 08.11.2026): Schlafdauer, HRV,
 * Ruhepuls, VO2max. Keine Schritte, kein Gewicht, nie Zyklusdaten — jede
 * weitere Art steht im iOS-Dialog und schreckt ab.
 *
 * HealthKit verrät nie, ob Lesen erlaubt wurde (Datenschutz): eine
 * Ablehnung sieht aus wie „keine Daten“. Deshalb merkt sich die Seite nur,
 * dass gefragt wurde, und liest dann einfach.
 */
export const APPLE_HEALTH_TYPES = ['sleep', 'heartRateVariability', 'restingHeartRate', 'vo2Max'];

const ASKED_KEY = 'apple_health_asked_at';

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

/** Mittelwert je Tag (HRV, Ruhepuls). */
export function averageByDay(samples) {
    const sums = {};
    for (const s of samples) {
        if (typeof s.value !== 'number' || !(s.value > 0)) continue;
        const day = localDate(s.startDate);
        (sums[day] ??= [0, 0]);
        sums[day][0] += s.value;
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

/** Tageszeilen, neueste zuerst: {date, sleep_min, hrv_ms, resting_hr, vo2max}. */
export function combineDays({ sleep = {}, hrv = {}, resting = {}, vo2 = {} }) {
    const days = new Set([...Object.keys(sleep), ...Object.keys(hrv), ...Object.keys(resting), ...Object.keys(vo2)]);
    return [...days].sort().reverse().map((date) => ({
        date,
        sleep_min: sleep[date] ?? null,
        hrv_ms: hrv[date] ?? null,
        resting_hr: resting[date] ?? null,
        vo2max: vo2[date] ?? null,
    }));
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

    async function read(dataType, start, end) {
        const { samples = [] } = await plugin.readSamples({
            dataType,
            startDate: start.toISOString(),
            endDate: end.toISOString(),
            limit: 2000,
            ascending: true,
        });
        return samples;
    }

    /** Liest die letzten `n` Tage (Schlaf bis heute morgen). */
    async function load(n = 7) {
        if (!plugin) return [];
        busy.value = true;
        error.value = null;
        try {
            const end = new Date();
            const start = new Date();
            start.setHours(0, 0, 0, 0);
            start.setDate(start.getDate() - (n - 1));
            // Die Nacht zum ersten Tag beginnt am Abend davor.
            const sleepStart = new Date(start.getTime() - 12 * 3600 * 1000);

            const [sleep, hrv, resting, vo2] = await Promise.all([
                read('sleep', sleepStart, end),
                read('heartRateVariability', start, end),
                read('restingHeartRate', start, end),
                read('vo2Max', start, end),
            ]);

            const startDay = localDate(start.toISOString());
            const sleepDays = Object.fromEntries(Object.entries(sleepMinutesByDay(sleep)).filter(([d]) => d >= startDay));

            days.value = combineDays({
                sleep: sleepDays,
                hrv: averageByDay(hrv),
                resting: averageByDay(resting),
                vo2: latestByDay(vo2),
            });
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

    return { supported, available, asked, busy, error, days, check, connect, load };
}
