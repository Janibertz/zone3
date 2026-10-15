<script setup>
import { computed } from 'vue';
import { paceValue, sessionType } from '@/Composables/useSessionTypes';

/**
 * Eine Trainingseinheit als Karte — geplant, empfohlen oder eingebucht.
 *
 * Aufbau wie eine Health-Karte: oben der Typ als farbiges Label mit Icon
 * (die Farbe sagt, welche Art Einheit), darunter der Titel, darunter die
 * Kennzahlen groß in SF Rounded. Die Zahlen sind das, was man vor dem
 * Loslaufen wissen will — sie gingen als graue 15px-Zeile unter.
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
    if (s.distance_km)  out.push({ label: 'Distanz', value: String(s.distance_km).replace('.', ','), unit: 'km' });
    if (s.duration_min) out.push({ label: 'Dauer',   value: String(s.duration_min), unit: 'min' });
    const pace = paceValue(s.pace_target);
    if (pace)           out.push({ label: 'Tempo',   value: pace, unit: '/km' });
    if (s.zone && out.length < 3) out.push({ label: 'Zone', value: String(s.zone), unit: null });
    return out.slice(0, 3);
});
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block rounded-card bg-surface p-4"
        :class="href ? 'transition-transform duration-150 ease-out-strong active:scale-[0.98]' : ''"
    >
        <div class="flex items-center justify-between gap-3">
            <span class="flex min-w-0 items-center gap-1.5 text-[15px] font-semibold" :class="meta.text">
                <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" v-html="meta.icon" />
                <span class="truncate">{{ meta.label }}</span>
            </span>
            <span v-if="badge" class="shrink-0 rounded-full px-2.5 py-0.5 text-[13px] font-semibold" :class="badgeClass">{{ badge }}</span>
        </div>

        <h3 class="mt-1.5 text-[22px] font-bold leading-7 text-ink">{{ session.title }}</h3>

        <!--
            Pace-Spannen („5:08–5:43") sind breit. Drei Spalten auf 358 Pixel
            lassen jeder gut 100 — die Zahl darf darum kleiner werden, statt
            umzubrechen: `truncate` waere hier falsch, die Pace ist die Ansage.
        -->
        <div v-if="metrics.length" class="mt-3 grid gap-2" :class="metrics.length === 1 ? 'grid-cols-1' : metrics.length === 2 ? 'grid-cols-2' : 'grid-cols-3'">
            <div v-for="m in metrics" :key="m.label" class="min-w-0">
                <p class="text-[13px] text-ink-3">{{ m.label }}</p>
                <p class="flex flex-wrap items-baseline gap-x-1">
                    <span class="font-rounded font-bold tabular-nums tracking-tight text-ink"
                        :class="m.value.length > 6 ? 'text-[20px] leading-7' : 'text-[28px] leading-8'">{{ m.value }}</span>
                    <span v-if="m.unit" class="text-[15px] font-semibold text-ink-3">{{ m.unit }}</span>
                </p>
            </div>
        </div>

        <p v-if="session.description" class="mt-3 text-[15px] leading-5 text-ink-3">{{ session.description }}</p>

        <slot />
    </component>
</template>
