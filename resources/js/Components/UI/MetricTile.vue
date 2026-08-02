<script setup>
import { computed } from 'vue';

/**
 * Kennzahl mit Ring — die Kacheln ganz oben auf dem Dashboard.
 *
 * `pct` (0–100) füllt den Ring, `value`/`unit` stehen darin. Die Farbe
 * kommt aus `tone` und sagt, wie der Wert einzuordnen ist, nicht welche
 * Kennzahl es ist.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '–' },
    unit:  { type: String, default: null },
    /** Ringfüllung in Prozent. null = leerer Ring (keine Daten). */
    pct:   { type: Number, default: null },
    /** good | ok | weak | none */
    tone:  { type: String, default: 'none' },
    /** Kurze Einordnung unter dem Ring, z. B. „über Schnitt". */
    hint:  { type: String, default: null },
});

const TONES = {
    good: 'text-success',
    ok:   'text-warn',
    weak: 'text-danger',
    none: 'text-ink-3',
};

const R = 26;                       // Radius im 64er-viewBox
const CIRC = 2 * Math.PI * R;

const dash = computed(() => {
    const p = Math.max(0, Math.min(100, props.pct ?? 0));
    return `${(CIRC * p) / 100} ${CIRC}`;
});

const toneClass = computed(() => TONES[props.tone] ?? TONES.none);
</script>

<template>
    <div class="flex flex-col items-center rounded-card bg-surface px-2 py-4 shadow-card">
        <div class="relative h-16 w-16 shrink-0">
            <svg viewBox="0 0 64 64" class="h-16 w-16 -rotate-90">
                <circle cx="32" cy="32" :r="R" fill="none" stroke="rgb(var(--z-surface-3))" stroke-width="5" />
                <circle
                    v-if="pct !== null"
                    cx="32" cy="32" :r="R"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="5"
                    stroke-linecap="round"
                    :stroke-dasharray="dash"
                    :class="toneClass"
                    class="transition-[stroke-dasharray] duration-500"
                />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-[15px] font-bold leading-none tabular-nums text-ink">{{ value }}</span>
                <span v-if="unit" class="mt-0.5 text-[9px] font-medium leading-none text-ink-3">{{ unit }}</span>
            </div>
        </div>

        <p class="mt-2.5 w-full truncate text-center text-[11px] font-bold uppercase tracking-wide text-ink">{{ label }}</p>
        <p v-if="hint" class="mt-0.5 w-full truncate text-center text-[11px] text-ink-3">{{ hint }}</p>
    </div>
</template>
