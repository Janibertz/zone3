<script setup>
/**
 * Kennzahl-Kachel wie in Health: farbiges Label oben, darunter die Zahl
 * groß in SF Rounded, die Einheit klein und grau daneben. Die Farbe sitzt
 * auf dem Label und sagt, WELCHE Kennzahl es ist; die Zahl bleibt in der
 * Schriftfarbe.
 */
defineProps({
    label: { type: String, default: null },
    value: { type: [String, Number], default: '–' },
    unit:  { type: String, default: null },
    hint:  { type: String, default: null },
    /** neutral | success | warn | danger | accent | info | form | fitness — färbt das Label. */
    tone:  { type: String, default: 'neutral' },
});

const tones = {
    neutral: 'text-ink-3',
    accent:  'text-accent',
    success: 'text-success',
    warn:    'text-warn',
    danger:  'text-danger',
    info:    'text-info',
    form:    'text-form',
    fitness: 'text-fitness',
};
</script>

<template>
    <div class="flex min-w-0 flex-col rounded-card bg-surface p-4">
        <p v-if="label" class="flex items-center gap-1.5 text-[15px] font-semibold" :class="tones[tone] ?? tones.neutral">
            <slot name="icon" />{{ label }}
        </p>
        <p class="mt-1.5 flex items-baseline gap-1">
            <span class="font-rounded text-[32px] font-bold leading-9 tabular-nums tracking-tight text-ink">{{ value }}</span>
            <span v-if="unit" class="text-[15px] font-semibold text-ink-3">{{ unit }}</span>
        </p>
        <p v-if="hint" class="mt-0.5 text-[13px] text-ink-3">{{ hint }}</p>
        <slot />
    </div>
</template>
