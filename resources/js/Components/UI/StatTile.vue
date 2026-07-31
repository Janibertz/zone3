<script setup>
/**
 * The number-first tile the dashboard is built from: big value, small unit,
 * quiet label. Keeps every metric in the app typographically identical.
 */
defineProps({
    label: { type: String, default: null },
    value: { type: [String, Number], default: '–' },
    unit:  { type: String, default: null },
    hint:  { type: String, default: null },
    /** neutral | success | warn | danger | accent — tints the value only. */
    tone:  { type: String, default: 'neutral' },
});

const tones = {
    neutral: 'text-ink',
    accent:  'text-accent',
    success: 'text-success',
    warn:    'text-warn',
    danger:  'text-danger',
};
</script>

<template>
    <div class="rounded-card border border-line bg-surface p-4 shadow-card">
        <p v-if="label" class="text-xs font-medium uppercase tracking-wide text-ink-3">{{ label }}</p>
        <p class="mt-1.5 flex items-baseline gap-1">
            <span class="text-2xl font-bold tabular-nums tracking-tight" :class="tones[tone] ?? tones.neutral">{{ value }}</span>
            <span v-if="unit" class="text-sm font-medium text-ink-3">{{ unit }}</span>
        </p>
        <p v-if="hint" class="mt-1 text-xs text-ink-3">{{ hint }}</p>
        <slot />
    </div>
</template>
