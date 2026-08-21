/**
 * Die Sportarten, die über Strava hereinkommen — an einer Stelle.
 *
 * Die Tabelle stand dreifach im Projekt: in der Aktivitätenliste mit
 * Pill-Farben, in der Aktivitäts-Detailseite als reine Label-Map, und im
 * Plan-Prompt noch einmal auf der PHP-Seite. Wer eine Sportart ergänzte,
 * ergänzte sie an einer Stelle und wunderte sich anderswo.
 *
 * `group` fasst die Varianten zusammen, die fachlich dasselbe sind: für die
 * Filter interessiert „Rad", nicht die Unterscheidung zwischen Rad, E-Bike
 * und Rolle.
 */
const TYPES = {
    Run:            { label: 'Laufen',       emoji: '🏃', group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    VirtualRun:     { label: 'Laufen',       emoji: '🏃', group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    TrailRun:       { label: 'Trail',        emoji: '⛰️', group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    Ride:           { label: 'Rad',          emoji: '🚴', group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    VirtualRide:    { label: 'Rad virtuell', emoji: '🚴', group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    EBikeRide:      { label: 'E-Bike',       emoji: '🚴', group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    Swim:           { label: 'Schwimmen',    emoji: '🏊', group: 'swim',     pill: 'bg-info-soft text-info-ink'       },
    Walk:           { label: 'Gehen',        emoji: '🚶', group: 'walk',     pill: 'bg-warn-soft text-warn-ink'       },
    Hike:           { label: 'Wandern',      emoji: '🥾', group: 'walk',     pill: 'bg-warn-soft text-warn-ink'       },
    Workout:        { label: 'Workout',      emoji: '💪', group: 'strength', pill: 'bg-warn-soft text-warn-ink'       },
    WeightTraining: { label: 'Kraft',        emoji: '💪', group: 'strength', pill: 'bg-warn-soft text-warn-ink'       },
    Yoga:           { label: 'Yoga',         emoji: '🧘', group: 'strength', pill: 'bg-success-soft text-success-ink' },
};

const FALLBACK = { label: 'Sonstiges', emoji: '🏅', group: 'other', pill: 'bg-surface-2 text-ink-2' };

/** Beschriftung, Emoji, Gruppe und Pill-Farbe zu einem Strava-Typ. */
export function activityType(type) {
    return TYPES[type] ?? { ...FALLBACK, label: type || FALLBACK.label };
}

/** Die Gruppen mit einer Beschriftung fürs Filtern. */
export const GROUPS = {
    run:      'Laufen',
    ride:     'Rad',
    swim:     'Schwimmen',
    walk:     'Gehen',
    strength: 'Kraft',
    other:    'Sonstiges',
};

/**
 * Filteroptionen aus dem, was der Athlet tatsächlich macht. Wer nie
 * schwimmt, braucht keinen Schwimm-Reiter — und auf einem Telefon ist der
 * Platz dafür ohnehin nicht da.
 *
 * @param {Array}  activities  Aktivitäten mit `type`
 * @param {number} max         Höchstzahl an Sportarten neben „Alle"
 */
export function sportOptions(activities, max = 3) {
    const counts = new Map();

    (activities ?? []).forEach(a => {
        const g = activityType(a.type).group;
        counts.set(g, (counts.get(g) ?? 0) + 1);
    });

    const sorted = [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, max)
        .map(([group]) => ({ value: group, label: GROUPS[group] ?? group }));

    // Ein einzelner Reiter neben „Alle" ist kein Filter, sondern Zierde.
    if (sorted.length < 2) return [];

    return [{ value: 'all', label: 'Alle' }, ...sorted];
}

/** Passt eine Aktivität zur gewählten Gruppe? */
export function matchesSport(activity, sport) {
    return sport === 'all' || activityType(activity?.type).group === sport;
}
