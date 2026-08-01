/**
 * Single source of truth for how a training session type looks.
 *
 * The dashboard carried this mapping five times over (planned session,
 * recommendation, accepted recommendation, rating list, …), each with its
 * own eight-branch class object. One table instead.
 */
export const SESSION_TYPES = {
    easy_run:        { label: 'Locker',      emoji: '🟢', accent: 'success' },
    tempo_run:       { label: 'Tempo',       emoji: '🟡', accent: 'warn'    },
    interval:        { label: 'Intervalle',  emoji: '🔴', accent: 'danger'  },
    long_run:        { label: 'Langer Lauf', emoji: '🔵', accent: 'info'    },
    race_prep:       { label: 'Renntempo',   emoji: '🏁', accent: 'accent'  },
    progressive_run: { label: 'Steigerung',  emoji: '📈', accent: 'success' },
    test_run:        { label: 'Testlauf',    emoji: '⏱️', accent: 'accent'  },
    strength:        { label: 'Kraft',       emoji: '💪', accent: 'warn'    },
    rest:            { label: 'Ruhetag',     emoji: '😴', accent: 'neutral' },
};

/** Tailwind classes per accent, using the design tokens. */
const ACCENTS = {
    success: { bg: 'bg-success-soft', text: 'text-success-ink', dot: 'bg-success', pill: 'bg-success-soft text-success-ink' },
    warn:    { bg: 'bg-warn-soft',    text: 'text-warn-ink',    dot: 'bg-warn',    pill: 'bg-warn-soft text-warn-ink'       },
    danger:  { bg: 'bg-danger-soft',  text: 'text-danger-ink',  dot: 'bg-danger',  pill: 'bg-danger-soft text-danger-ink'   },
    info:    { bg: 'bg-info-soft',    text: 'text-info-ink',    dot: 'bg-info',    pill: 'bg-info-soft text-info-ink'       },
    accent:  { bg: 'bg-accent-soft',  text: 'text-accent-ink',  dot: 'bg-accent',  pill: 'bg-accent-soft text-accent-ink'   },
    neutral: { bg: 'bg-surface-2',    text: 'text-ink-2',       dot: 'bg-ink-3',   pill: 'bg-surface-2 text-ink-2'          },
};

export function sessionType(type) {
    const meta = SESSION_TYPES[type] ?? SESSION_TYPES.easy_run;
    return { ...meta, ...ACCENTS[meta.accent] };
}

export function useSessionTypes() {
    return { sessionType, SESSION_TYPES };
}
