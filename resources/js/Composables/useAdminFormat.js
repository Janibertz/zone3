/** Kleine Formatierer für den Admin-Bereich (18.11.2026). */

/** 0,42 — immer zwei Stellen, wie Euro-Beträge gelesen werden. */
export function eur(value, digits = 2) {
    return Number(value || 0).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

/** Kleine Beträge (0,004) mit drei Stellen, sonst zwei. */
export function eurSmall(value) {
    const v = Number(value || 0);
    return v > 0 && v < 0.01 ? eur(v, 3) : eur(v, 2);
}

/** „vor 40 min“, „vor 3 Std.“, „gestern“, „Mo“, „12.11.“ */
export function ago(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const min = Math.round((Date.now() - d.getTime()) / 60000);
    if (min < 1) return 'gerade eben';
    if (min < 60) return `vor ${min} min`;
    if (min < 60 * 24 && d.toDateString() === new Date().toDateString()) return `vor ${Math.floor(min / 60)} Std.`;
    const yesterday = new Date(); yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'gestern';
    if (min < 60 * 24 * 6) return d.toLocaleDateString('de-DE', { weekday: 'short' }).replace('.', '');
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
}

/** 11:05 */
export function hm(iso) {
    return iso ? new Date(iso).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '';
}

/** „heute 11:05“ / „17.11. 21:10“ */
export function when(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString()
        ? `heute ${hm(iso)}`
        : `${d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })} ${hm(iso)}`;
}

/** „Anna Weber“ → „Anna“ */
export function firstName(name) {
    return String(name || '').trim().split(/\s+/)[0] || '';
}
