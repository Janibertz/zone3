/**
 * Der Coach antwortet schon beim Eintragen (Jan, 10.11.2026, Idee B aus dem
 * Kotcha-Onboarding). Feste Regeln im Browser, kein Modellaufruf — die
 * Antwort muss sofort da sein, sonst ist sie keine Rückmeldung.
 *
 * Die Sätze versprechen nur, was der Plan wirklich tut: zweite harte
 * Einheit erst ab fünf Läufen (`SECOND_HARD_FROM_SESSIONS`), der lange Lauf
 * braucht einen Tag mit Zeit.
 */

const WEEK_MS = 7 * 24 * 3600 * 1000;

/** Zielpace abgerundet — eine Zielpace weist an, siehe PaceFormat::target(). */
function targetPace(totalSec, km) {
    const s = Math.floor(totalSec / km);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function clockOf(h, m) {
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}` : `${m} Minuten`;
}

export function goalFeedback(form, race) {
    if (!race || form.race_distance === 'backyard_ultra') return '';
    const km = race.distance ?? (Number(form.distance_km) || null);
    const parts = [];

    const total = (Number(form.target_time_hours) || 0) * 3600 + (Number(form.target_time_minutes) || 0) * 60;
    if (km && total > 0) {
        const name = ['half_marathon', 'marathon'].includes(race.value)
            ? `im ${race.label}`
            : `über ${km.toLocaleString('de-DE', { maximumFractionDigits: 1 })} km`;
        parts.push(`${clockOf(form.target_time_hours, form.target_time_minutes)} ${name} heißt ${targetPace(total, km)} pro Kilometer.`);
    }

    if (form.race_date) {
        const weeks = Math.floor((new Date(form.race_date) - new Date()) / WEEK_MS);
        if (weeks < 0) parts.push('Das Datum liegt in der Vergangenheit.');
        else if (weeks < 6) parts.push(`Bis dahin sind es nur ${weeks} Wochen. Ich halte deine Form und plane den Rennschliff.`);
        else if (weeks < 12) parts.push(`${weeks} Wochen bis zum Start: kurz, aber genug für einen gezielten Aufbau.`);
        else parts.push(`${weeks} Wochen bis zum Start: genug Zeit für Grundlage, Aufbau und Tapering.`);
    }

    return parts.join(' ');
}

export function availabilityFeedback(availability, days) {
    const open = days.filter(d => availability[d.key]?.available);
    if (!open.length) return '';

    const minutes = open.map(d => availability[d.key].duration_min || 0);
    const total = minutes.reduce((a, b) => a + b, 0);
    const longest = Math.max(...minutes);
    const hours = (total / 60).toLocaleString('de-DE', { maximumFractionDigits: 1 });

    let text = open.length >= 5
        ? `${open.length} Tage, zusammen ${hours} Stunden. Damit sind ein langer Lauf und, sobald der Aufbau beginnt, zwei härtere Einheiten pro Woche drin. Der Rest bleibt locker.`
        : open.length >= 3
            ? `${open.length} Tage, zusammen ${hours} Stunden. Das reicht für einen langen Lauf und eine härtere Einheit pro Woche.`
            : `Mit ${open.length === 1 ? 'einem Tag' : 'zwei Tagen'} wird es knapp. Ab drei Tagen kann ich einen richtigen Aufbau planen.`;

    if (open.length >= 3 && longest < 75) {
        text += ` Für den langen Lauf wäre ein Tag mit mindestens 75 Minuten gut, bisher sind es höchstens ${longest}.`;
    }

    return text;
}
