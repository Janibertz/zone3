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
/**
 * Strich-Icons statt Emoji — eine Familie, eine Strichstaerke.
 *
 * Hier standen zwoelf Emoji aus drei verschiedenen Schriftsystemen; auf
 * jedem Geraet sahen sie anders aus, und nebeneinander in einer Liste war
 * keine gemeinsame Linie zu erkennen. Emoji in der Oberflaeche sind der
 * lauteste „das hat eine Maschine zusammengestellt"-Hinweis, den es gibt.
 *
 * Die Pfade sind auf 24×24 gezeichnet und werden mit `stroke-width="1.7"`
 * gesetzt — dieselbe Staerke wie die Typ-Icons in `useSessionTypes.js` und
 * die Leisten-Icons im Layout.
 */
const ICONS = {
    run:      `<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 5.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 21 2.4-5.1-2.4-2.4 1.2-5.4 3 1.8 2.55 2.1h3.15M8.25 9.9 5.1 11.4l-1.35 3.3" /><path stroke-linecap="round" stroke-linejoin="round" d="m12.45 13.5 1.8 3.3 3 2.4" />`,
    ride:     `<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 19.5a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5ZM18.75 19.5a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 15.75h4.5l3.75-6.75h3M12 9 9.75 5.25h-2.25M15 9h3.75l1.5 6.75" />`,
    swim:     `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 17.25c1.5 0 1.5 1.5 3 1.5s1.5-1.5 3-1.5 1.5 1.5 3 1.5 1.5-1.5 3-1.5 1.5 1.5 3 1.5 1.5-1.5 3-1.5M2.25 12.75c1.5 0 1.5 1.5 3 1.5" /><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 13.5 3.75-3.75 3 2.25" />`,
    walk:     `<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 5.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 21.75 12 15l-2.25-2.25.75-4.5 2.25 1.5 2.25 1.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m12 15 2.25 2.25.75 4.5M9.75 10.5 7.5 12.75" />`,
    strength: `<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.75v4.5M6.75 7.5v9M17.25 7.5v9M20.25 9.75v4.5M6.75 12h10.5" />`,
    other:    `<path stroke-linecap="round" stroke-linejoin="round" d="M12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 2.25h7.5l-1.5 6M9.75 8.25l-1.5-6M9 15l-1.5 6.75L12 19.5l4.5 2.25L15 15" />`,
};

const TYPES = {
    Run:            { label: 'Laufen',       group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    VirtualRun:     { label: 'Laufen',       group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    TrailRun:       { label: 'Trail',        group: 'run',      pill: 'bg-accent-soft text-accent-ink'   },
    Ride:           { label: 'Rad',          group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    VirtualRide:    { label: 'Rad virtuell', group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    EBikeRide:      { label: 'E-Bike',       group: 'ride',     pill: 'bg-success-soft text-success-ink' },
    Swim:           { label: 'Schwimmen',    group: 'swim',     pill: 'bg-info-soft text-info-ink'       },
    Walk:           { label: 'Gehen',        group: 'walk',     pill: 'bg-warn-soft text-warn-ink'       },
    Hike:           { label: 'Wandern',      group: 'walk',     pill: 'bg-warn-soft text-warn-ink'       },
    Workout:        { label: 'Workout',      group: 'strength', pill: 'bg-warn-soft text-warn-ink'       },
    WeightTraining: { label: 'Kraft',        group: 'strength', pill: 'bg-warn-soft text-warn-ink'       },
    Yoga:           { label: 'Yoga',         group: 'strength', pill: 'bg-success-soft text-success-ink' },
};

const FALLBACK = { label: 'Sonstiges', group: 'other', pill: 'bg-surface-2 text-ink-2' };

/**
 * Beschriftung, Icon, Gruppe und Pill-Farbe zu einem Strava-Typ.
 *
 * Das Icon haengt an der GRUPPE, nicht am einzelnen Typ: Rad, E-Bike und
 * Rolle sind dasselbe Bild, und eine eigene Zeichnung je Variante waere
 * Unterscheidung ohne Unterschied. `swim` hat keine eigene Gruppenzeile im
 * Filter, aber ein eigenes Icon — darum der Zugriff ueber `group`.
 */
export function activityType(type) {
    const base = TYPES[type] ?? { ...FALLBACK, label: type || FALLBACK.label };

    return { ...base, icon: ICONS[base.group] ?? ICONS.other };
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
