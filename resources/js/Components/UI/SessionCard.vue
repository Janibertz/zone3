<script setup>
import { computed } from 'vue';
import { sessionType } from '@/Composables/useSessionTypes';

/**
 * Eine Trainingseinheit als Karte — geplant, empfohlen oder eingebucht.
 * Weiß und randlos; der Typ wird über ein farbiges Pill rechts oben
 * gekennzeichnet, nicht über die Kartenfarbe.
 */
const props = defineProps({
    session: { type: Object, required: true },
    /** Zusätzliches Pill, z. B. „Heute" oder „✓ Geplant". */
    badge:   { type: String, default: null },
    badgeTone: { type: String, default: 'accent' },
    href:    { type: String, default: null },
});

const meta = computed(() => sessionType(props.session?.type));

const badgeClass = computed(() => ({
    accent:  'bg-accent-soft text-accent-ink',
    success: 'bg-success-soft text-success-ink',
    neutral: 'bg-surface-2 text-ink-2',
}[props.badgeTone] ?? 'bg-accent-soft text-accent-ink'));

const tag = computed(() => (props.href ? 'a' : 'div'));

const metrics = computed(() => {
    const s = props.session;
    if (!s || s.type === 'rest') return [];

    const out = [];
    if (s.duration_min) out.push(`${s.duration_min} min`);
    if (s.distance_km)  out.push(`${s.distance_km} km`);
    if (s.pace_target && s.pace_target !== 'null') out.push(`${s.pace_target} /km`);
    if (s.zone)         out.push(`Zone ${s.zone}`);
    return out;
});
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block rounded-card bg-surface p-5 shadow-card"
        :class="href ? 'transition-transform duration-150 active:scale-[0.99]' : ''"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="shrink-0 text-xl leading-none">{{ meta.emoji }}</span>
                <h3 class="truncate text-[17px] font-bold leading-tight text-ink">{{ session.title }}</h3>
            </div>
            <div class="flex shrink-0 gap-1.5">
                <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="meta.pill">{{ meta.label }}</span>
                <span v-if="badge" class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="badgeClass">{{ badge }}</span>
            </div>
        </div>

        <!-- Kennzahlen in einer ruhigen grauen Zeile, wie in der Vorlage -->
        <p v-if="metrics.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[15px] text-ink-3">
            <span v-for="m in metrics" :key="m" class="tabular-nums">{{ m }}</span>
        </p>

        <p v-if="session.description" class="mt-2 text-[15px] leading-relaxed text-ink-3">{{ session.description }}</p>

        <slot />
    </component>
</template>
