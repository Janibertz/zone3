<script setup>
/**
 * Kleine Status-Kachel für die Zeile ganz oben: winziges
 * Großbuchstaben-Label, darunter der Wert mit optionalem Icon.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    /** neutral | success | warn | danger — färbt nur den Wert. */
    tone:  { type: String, default: 'neutral' },
});

const tones = {
    neutral: 'text-ink',
    success: 'text-success',
    warn:    'text-warn',
    danger:  'text-danger',
};
</script>

<template>
    <div class="min-w-0 rounded-card bg-surface px-3.5 py-3 shadow-card">
        <p class="truncate text-[10px] font-bold uppercase tracking-wider text-ink">{{ label }}</p>
        <p class="mt-1.5 flex min-w-0 items-center gap-1.5 text-[17px] font-bold leading-none tabular-nums"
            :class="tones[tone] ?? tones.neutral">
            <slot name="icon" />
            <span class="truncate"><slot>{{ value }}</slot></span>
        </p>
    </div>
</template>
