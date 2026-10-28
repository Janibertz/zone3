/**
 * Zahlen so, wie sie in der App stehen: deutsch und wie eine Uhr.
 *
 * Die Seiten hatten je eigene Formatierer, und sie stimmten nicht überein:
 * „10.50 km" auf der einen, „10,5 km" auf der anderen, „46m 22s" neben
 * „46:22". Seit dem Redesign (28.10.2026) gilt eine Schreibweise — 10,5 km,
 * 1.160 m, 46:22, 1:02:10 — und sie steht hier.
 */

/** 10,5 · 1.160 — höchstens `digits` Nachkommastellen. */
export function fmtNum(value, digits = 1) {
    return Number(value || 0).toLocaleString('de-DE', { maximumFractionDigits: digits });
}

/** Sekunden wie eine Uhr: 46:22 oder 1:02:10. */
export function clock(seconds) {
    if (!seconds && seconds !== 0) return '–';
    const s = Math.round(seconds);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const pad = (n) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s % 60)}` : `${m}:${pad(s % 60)}`;
}

/** Stunden für Summen: 17:02 h. Unter einer Stunde in Minuten. */
export function hours(seconds) {
    const min = Math.round((seconds || 0) / 60);
    if (min < 60) return { value: String(min), unit: 'min' };

    return { value: `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`, unit: 'h' };
}

/** Sekunden je Strecke als M:SS — einmal auf ganze Sekunden runden, dann teilen. */
function mss(sec) {
    const s = Math.round(sec);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** m/s → Pace je km. */
export function paceFromSpeed(mps) {
    return mps > 0 ? mss(1000 / mps) : null;
}

const RIDES = ['Ride', 'VirtualRide', 'EBikeRide'];

/**
 * Das Tempo einer Aktivität in der Einheit, die zu ihrem Sport passt:
 * Pace je km beim Laufen, je 100 m beim Schwimmen, km/h auf dem Rad.
 *
 * @returns {{ label: string, value: string, unit: string } | null}
 */
export function speedOf(activity) {
    const v = activity?.average_speed;
    if (!v || v <= 0) return null;

    if (RIDES.includes(activity.type)) return { label: 'Tempo', value: fmtNum(v * 3.6), unit: 'km/h' };
    if (activity.type === 'Swim')      return { label: 'Pace',  value: mss(100 / v), unit: '/100 m' };

    return { label: 'Pace', value: mss(1000 / v), unit: '/km' };
}

/** Strecke einer Aktivität: Schwimmen in Metern, sonst Kilometer. */
export function distanceOf(activity) {
    const m = activity?.distance || 0;
    if (m <= 0) return null;

    return activity.type === 'Swim'
        ? { value: fmtNum(m, 0), unit: 'm' }
        : { value: fmtNum(m / 1000), unit: 'km' };
}
