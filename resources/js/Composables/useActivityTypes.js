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
 * Die Filterreiter: Laufen, Rad, Schwimmen — fest, nicht aus den Daten
 * abgeleitet.
 *
 * Anfangs standen hier nur die Sportarten, die der Athlet auch betreibt.
 * Das war als Aufräumen gedacht, nimmt der App aber ihre Form: die drei
 * Disziplinen sind die Struktur, auf die Zone3 zulaufen soll, und ein
 * leerer Schwimm-Reiter ist eine Einladung, keine Lücke.
 *
 * Gehen, Kraft und alles Übrige bleiben klassifiziert — sie zählen unter
 * „Alle" mit, bekommen aber keinen eigenen Reiter. Die Summe der drei
 * Disziplinen ist deshalb kleiner als „Alle", und das ist richtig so.
 */
export const SPORT_FILTERS = [
    { value: 'all',  label: 'Alle' },
    { value: 'run',  label: 'Laufen' },
    { value: 'ride', label: 'Rad' },
    { value: 'swim', label: 'Schwimmen' },
];

export function sportOptions() {
    return SPORT_FILTERS;
}

/** Passt eine Aktivität zur gewählten Gruppe? */
export function matchesSport(activity, sport) {
    return sport === 'all' || activityType(activity?.type).group === sport;
}
