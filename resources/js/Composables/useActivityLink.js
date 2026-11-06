/**
 * Eine erledigte Einheit hat genau eine Detailseite: die der Aktivität
 * (Jan, 06.11.2026: „zwei unterschiedliche Detailseiten einer Aktivität
 * finde ich blöd"). Dashboard, Planseite und Kalender führen dorthin,
 * sobald eine Aktivität an der Einheit hängt. Geplante Einheiten behalten
 * ihre Einheit-Details.
 *
 * `back` sagt der Aktivitätsseite, wohin ihr Zurück-Knopf führt — sonst
 * landet man vom Dashboard aus in der Aktivitätsliste.
 */
export function activityHref(activityId, back = null) {
    const base = route('activities.show', activityId);
    const target = safeBack(back);

    return target ? `${base}?back=${encodeURIComponent(target)}` : base;
}

/** Ziel der Session, die zur Aktivität führt — oder null, wenn sie keine hat. */
export function completedActivityHref(session, back = null) {
    if (!session || session.status !== 'completed' || !session.activity_id) return null;

    return activityHref(session.activity_id, back);
}

/** Nur Pfade innerhalb der App — kein `//host`, kein `https://`. */
export function safeBack(value) {
    if (typeof value !== 'string') return null;
    if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return null;

    return value;
}
