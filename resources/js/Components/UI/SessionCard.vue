<script setup>
import { computed } from 'vue';
import { sessionType } from '@/Composables/useSessionTypes';

/**
 * One training session as a card — planned, recommended or already booked.
 * Replaces the five hand-built copies that lived on the dashboard.
 */
const props = defineProps({
    session: { type: Object, required: true },
    /** Optional pill on the top right, e.g. "Heute" or "✓ Geplant". */
    badge:   { type: String, default: null },
    /** Tone of that pill: accent | success | neutral. */
    badgeTone: { type: String, default: 'accent' },
    href:    { type: String, default: null },
});

const meta = computed(() => sessionType(props.session?.type));

const badgeClass = computed(() => ({
    accent:  'bg-accent text-white',
    success: 'bg-success text-white',
    neutral: 'bg-surface-3 text-ink-2',
}[props.badgeTone] ?? 'bg-accent text-white'));

const tag = computed(() => (props.href ? 'a' : 'div'));

const metrics = computed(() => {
    const s = props.session;
    if (!s || s.type === 'rest') return [];

    const out = [];
    if (s.distance_km)  out.push({ value: s.distance_km, unit: 'km' });
    if (s.duration_min) out.push({ value: s.duration_min, unit: 'min' });
    if (s.pace_target && s.pace_target !== 'null') out.push({ value: s.pace_target, unit: '/km' });
    return out;
});
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block rounded-card border p-4 transition-all"
        :class="[meta.bg, meta.border, href ? 'hover:border-line-strong active:scale-[0.99]' : '']"
    >
        <div class="mb-2 flex items-start justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <span class="shrink-0 text-lg leading-none">{{ meta.emoji }}</span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wider" :class="meta.text">{{ meta.label }}</p>
                    <p class="truncate text-base font-bold leading-tight text-ink">{{ session.title }}</p>
                </div>
            </div>
            <div class="flex shrink-0 gap-1.5">
                <span v-if="badge" class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="badgeClass">{{ badge }}</span>
                <span v-if="session.activity_id" class="rounded-full bg-warn-soft px-2 py-0.5 text-[11px] font-semibold text-warn-ink">Strava</span>
            </div>
        </div>

        <p v-if="session.description" class="text-sm leading-relaxed text-ink-2">{{ session.description }}</p>

        <!-- Numbers get the room they deserve -->
        <div v-if="metrics.length" class="mt-3 flex flex-wrap items-baseline gap-x-5 gap-y-2">
            <span v-for="m in metrics" :key="m.unit" class="flex items-baseline gap-1">
                <span class="text-2xl font-black tabular-nums leading-none tracking-tight text-ink">{{ m.value }}</span>
                <span class="text-xs font-semibold text-ink-3">{{ m.unit }}</span>
            </span>
            <span v-if="session.zone" class="rounded-full bg-surface px-2 py-1 text-[11px] font-semibold text-ink-2">
                Zone {{ session.zone }}
            </span>
        </div>

        <slot />
    </component>
</template>
