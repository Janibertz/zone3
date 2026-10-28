<script setup>
import { computed } from 'vue';
import { paceValue, sessionType, stepRows } from '@/Composables/useSessionTypes';

/**
 * Eine Trainingseinheit als Karte — geplant, empfohlen oder eingebucht.
 *
 * Aufbau wie eine Health-Karte: oben der Typ als farbiges Label mit Icon
 * (die Farbe sagt, welche Art Einheit), darunter der Titel, darunter die
 * Kennzahlen groß. Die Zahlen sind das, was man vor dem Loslaufen wissen
 * will — sie gingen als graue 15px-Zeile unter.
 *
 * Seit 28.10.2026 nach der Story-Karte: unter den Zahlen die Abschnitte als
 * Zeilen mit Farbbalken, und **keine Beschreibung mehr**. Die stand hier
 * als Absatz von sechs, sieben Zeilen und wiederholte, was die Zahlen schon
 * sagen. Sie steht in den Details der Einheit (Jan: „Man sieht auf den
 * ersten Blick was einen erwartet ohne viel Text").
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

const rows = computed(() => stepRows(props.session?.steps));
const barClass = (kind) => ({
    warmup:   'bg-success',
    cooldown: 'bg-success',
    rest:     'bg-ink-3',
    work:     meta.value.dot,
}[kind] ?? meta.value.dot);
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block rounded-card bg-surface p-5"
        :class="href ? 'transition-transform duration-150 ease-out-strong active:scale-[0.98]' : ''"
    >
        <div class="flex items-center justify-between gap-3">
            <span class="flex min-w-0 items-center gap-1.5 text-[15px] font-semibold" :class="meta.text">
                <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" v-html="meta.icon" />
                <span class="truncate">{{ meta.label }}</span>
            </span>
            <span v-if="badge" class="shrink-0 rounded-full px-2.5 py-0.5 text-[13px] font-semibold" :class="badgeClass">{{ badge }}</span>
        </div>

        <h3 class="mt-1.5 text-[22px] font-bold leading-7 tracking-tight text-ink">{{ session.title }}</h3>

        <!--
            Pace-Spannen („5:08–5:43") sind breit. Drei Spalten auf 358 Pixel
            lassen jeder gut 100 — die Zahl darf darum kleiner werden, statt
            umzubrechen: `truncate` waere hier falsch, die Pace ist die Ansage.
        -->
        <div v-if="metrics.length" class="mt-4 grid gap-2" :class="metrics.length === 1 ? 'grid-cols-1' : metrics.length === 2 ? 'grid-cols-2' : 'grid-cols-[1fr_1fr_1.4fr]'">
            <div v-for="m in metrics" :key="m.label" class="min-w-0">
                <p class="text-[13px] text-ink-3">{{ m.label }}</p>
                <p class="flex items-baseline gap-x-1 whitespace-nowrap">
                    <span class="font-rounded font-bold tabular-nums tracking-tight text-ink"
                        :class="m.value.length > 6 ? 'whitespace-nowrap text-[20px] leading-8' : 'text-[30px] leading-9'">{{ m.value }}</span>
                    <span v-if="m.unit" class="text-[15px] font-semibold text-ink-3">{{ m.unit }}</span>
                </p>
            </div>
        </div>

        <div v-if="rows.length" class="mt-4 space-y-2 border-t border-line pt-3">
            <div v-for="(r, i) in rows" :key="i" class="flex items-center gap-3 text-[15px] leading-5">
                <i class="h-[18px] w-1 shrink-0 rounded-full" :class="barClass(r.kind)" />
                <span class="min-w-0 flex-1 truncate text-ink-2">{{ r.name }}</span>
                <b v-if="r.value" class="shrink-0 font-semibold tabular-nums text-ink">{{ r.value }}</b>
            </div>
        </div>

        <slot />
    </component>
</template>
