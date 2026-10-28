/**
 * Eine Pace-Zone als Text. Die Zonen sind an den Raendern offen:
 * Zone 1 hat kein langsames Ende (`∞`), Zone 5 kein schnelles (`0:00`).
 * „0:00–4:15" stand so im Builder und las sich wie ein Fehler
 * (Entscheidung von Jan, 28.10.2026: offen lassen, Anzeige reparieren).
 */
export function zoneRange(z) {
    if (!z) return null;
    if (z.max_pace === '∞') return `langsamer als ${z.min_pace}`;
    if (!z.min_pace || z.min_pace === '0:00') return `schneller als ${z.max_pace}`;

    return `${z.min_pace}–${z.max_pace}`;
}

/** Dieselbe Zone fuer enge Kacheln: „< 4:15" statt „schneller als 4:15". */
export function zoneRangeShort(z) {
    if (!z) return null;
    if (z.max_pace === '∞') return `> ${z.min_pace}`;
    if (!z.min_pace || z.min_pace === '0:00') return `< ${z.max_pace}`;

    return `${z.min_pace}–${z.max_pace}`;
}
