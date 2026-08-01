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
    success: { bg: 'bg-success-soft', border: 'border-success/25', text: 'text-success-ink', dot: 'bg-success' },
    warn:    { bg: 'bg-warn-soft',    border: 'border-warn/25',    text: 'text-warn-ink',    dot: 'bg-warn'    },
    danger:  { bg: 'bg-danger-soft',  border: 'border-danger/25',  text: 'text-danger-ink',  dot: 'bg-danger'  },
    info:    { bg: 'bg-info-soft',    border: 'border-info/25',    text: 'text-info-ink',    dot: 'bg-info'    },
    accent:  { bg: 'bg-accent-soft',  border: 'border-accent/25',  text: 'text-accent-ink',  dot: 'bg-accent'  },
    neutral: { bg: 'bg-surface-2',    border: 'border-line',       text: 'text-ink-2',       dot: 'bg-ink-3'   },
};

export function sessionType(type) {
    const meta = SESSION_TYPES[type] ?? SESSION_TYPES.easy_run;
    return { ...meta, ...ACCENTS[meta.accent] };
}

export function useSessionTypes() {
    return { sessionType, SESSION_TYPES };
}
