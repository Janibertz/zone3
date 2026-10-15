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
    <div class="min-w-0 rounded-card bg-surface px-3.5 py-3">
        <p class="truncate text-[13px] font-medium text-ink-3">{{ label }}</p>
        <p class="mt-1 flex min-w-0 items-center gap-1.5 font-rounded text-[17px] font-bold leading-6 tabular-nums"
            :class="tones[tone] ?? tones.neutral">
            <slot name="icon" />
            <span class="truncate"><slot>{{ value }}</slot></span>
        </p>
    </div>
</template>
