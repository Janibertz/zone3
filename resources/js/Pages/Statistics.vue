<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { fmtNum, clock, hours } from '@/Composables/useFormat';

/**
 * Statistik im Story-Look (Redesign 28.10.2026).
 *
 * Vorher fünf gleich große Kacheln über alle Zeit, zwei Diagramme und vier
 * „Höhepunkte" — viel Fläche, keine Antwort auf die Frage, mit der man die
 * Seite öffnet: *wie läuft es gerade?* Jetzt eine Hauptzahl für den
 * laufenden Zeitraum mit dem Vergleich zum vorigen, die Balken davor,
 * vier Kacheln ohne Umbruch und die Bestzeiten als Liste.
 */
const props = defineProps({
    sport:        { type: String, default: 'all' },
    period:       { type: String, default: 'month' },
    sportOptions: { type: Array,  default: () => [] },
    buckets:      { type: Array,  default: () => [] },
    current:      { type: Object, default: () => ({}) },
    intensity:    { type: Object, default: null },
    bests:        { type: Array,  default: () => [] },
    totals:       { type: Object, default: () => ({}) },
});

const PERIODS = [
    { value: 'week',  label: 'Woche' },
    { value: 'month', label: 'Monat' },
    { value: 'year',  label: 'Jahr' },
];

function visit(params) {
    router.get(route('statistics.index'), {
        sport: props.sport,
        period: props.period,
        ...params,
    }, { preserveScroll: true, preserveState: true, replace: true });
}

const sportLabel = computed(() => props.sportOptions.find(o => o.value === props.sport)?.label ?? 'Alle');
const eyebrow = computed(() => (props.sport === 'all' ? 'Alle Sportarten' : sportLabel.value));

const hasData = computed(() => (props.totals?.runs ?? 0) > 0);

/* ── Hauptzahl ──────────────────────────────────────────────────── */

/** Gegen den vorigen Zeitraum bis zum selben Tag — nur wenn es dort etwas gab. */
const change = computed(() => {
    const { km, prev_km: prev } = props.current;
    if (!prev) return null;
    const pct = Math.round((km - prev) / prev * 100);

    return { pct, up: pct >= 0 };
});

/* ── Balken ─────────────────────────────────────────────────────── */

const picked = ref(null);
const activeIndex = computed(() => picked.value ?? props.buckets.length - 1);
const active = computed(() => props.buckets[activeIndex.value] ?? null);
const maxKm = computed(() => Math.max(...props.buckets.map(b => b.km), 1));

const barHeight = (km) => (km ? Math.max(km / maxKm.value * 100, 4) : 2);

/** Drei Beschriftungen reichen: erste, mittlere, letzte. */
const axis = computed(() => {
    const b = props.buckets;
    if (b.length < 3) return b.map(x => x.label);
    return [b[0].label, b[Math.floor((b.length - 1) / 2)].label, b[b.length - 1].label];
});

/* ── Kacheln ────────────────────────────────────────────────────── */

const time = computed(() => hours(props.current.seconds));

/** Tempo im Maß der Sportart: Pace je km, je 100 m oder km/h. */
function speedValue(mps) {
    if (!mps) return null;
    if (props.sport === 'ride') return { value: fmtNum(mps * 3.6), unit: 'km/h', sec: null };
    const per = props.sport === 'swim' ? 100 : 1000;
    const sec = Math.round(per / mps);

    return { value: clock(sec), unit: props.sport === 'swim' ? '/100 m' : '/km', sec };
}

const speed = computed(() => speedValue(props.current.speed));

/** Pace-Änderung in Sekunden — negativ heißt schneller. */
const speedDelta = computed(() => {
    const now = speedValue(props.current.speed);
    const before = speedValue(props.current.prev_speed);
    if (!now?.sec || !before?.sec) return null;

    return now.sec - before.sec;
});

const avgElevation = computed(() => (props.current.count ? Math.round(props.current.elevation / props.current.count) : 0));

const intensityOk = computed(() => props.intensity && props.intensity.hard_pct <= props.intensity.limit_pct);

const dateLabel = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <Head title="Statistik" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader title="Statistik" :eyebrow="eyebrow">
                <template #action>
                    <AppButton :href="route('wrapped.index')" variant="plain" size="sm">Rückblick</AppButton>
                </template>
            </PageHeader>

            <div class="mx-auto max-w-3xl space-y-6 px-4 pb-8 pt-3 lg:px-8">
                <div class="space-y-2.5">
                    <SegmentedControl :model-value="period" :options="PERIODS" @update:model-value="v => { picked = null; visit({ period: v }); }" />
                    <SegmentedControl :model-value="sport" :options="sportOptions" @update:model-value="v => { picked = null; visit({ sport: v }); }" />
                </div>

                <template v-if="hasData">
                    <!-- ── Hauptzahl + Balken ─────────────────────── -->
                    <AppCard>
                        <p class="text-[13px] text-ink-3">{{ picked === null ? current.label : active?.label }}</p>
                        <p class="mt-0.5 font-rounded text-[44px] font-extrabold leading-[48px] tracking-[-0.035em] tabular-nums text-ink">
                            {{ fmtNum(picked === null ? current.km : active?.km) }}<span class="ml-1 text-[17px] font-semibold tracking-normal text-ink-3">km</span>
                        </p>
                        <p v-if="picked !== null" class="mt-0.5 text-[13px] text-ink-3">
                            {{ active?.count }} {{ active?.count === 1 ? 'Einheit' : 'Einheiten' }} · {{ hours(active?.seconds).value }} {{ hours(active?.seconds).unit }}
                        </p>
                        <p v-else-if="change" class="mt-0.5 text-[13px] text-ink-3">
                            <span class="font-semibold" :class="change.up ? 'text-success' : 'text-ink-2'">{{ change.up ? '+' : '−' }}{{ Math.abs(change.pct) }} %</span>
                            gegenüber {{ current.prev_label }}, bis zum selben Tag
                        </p>
                        <p v-else class="mt-0.5 text-[13px] text-ink-3">{{ current.count }} {{ current.count === 1 ? 'Einheit' : 'Einheiten' }}</p>

                        <div class="mt-5 flex h-[140px] items-end gap-1.5 sm:gap-2" role="group" aria-label="Kilometer je Zeitraum">
                            <button v-for="(b, i) in buckets" :key="b.label + i" type="button"
                                class="flex h-full min-w-0 flex-1 flex-col justify-end focus-visible:outline-none"
                                :aria-label="`${b.label}: ${fmtNum(b.km)} km`" :aria-pressed="i === activeIndex"
                                @click="picked = (i === buckets.length - 1 || picked === i) ? null : i">
                                <span class="block w-full rounded-[5px] transition-[height,background-color] duration-300 ease-out-strong"
                                    :class="i === activeIndex ? 'bg-accent' : b.km ? 'bg-ink-3/40' : 'bg-surface-2'"
                                    :style="{ height: barHeight(b.km) + '%' }" />
                            </button>
                        </div>
                        <div class="mt-1.5 flex justify-between text-[13px] text-ink-3">
                            <span v-for="(l, i) in axis" :key="i">{{ l }}</span>
                        </div>
                    </AppCard>

                    <!-- ── Kacheln ────────────────────────────────── -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-card bg-surface px-4 py-3.5">
                            <p class="text-[13px] text-ink-3">Zeit</p>
                            <p class="mt-0.5 whitespace-nowrap font-rounded text-[28px] font-extrabold leading-8 tracking-[-0.035em] tabular-nums text-ink">{{ time.value }}<span class="ml-1 text-[15px] font-semibold tracking-normal text-ink-3">{{ time.unit }}</span></p>
                            <p class="mt-0.5 text-[13px] text-ink-3">{{ current.count }} {{ current.count === 1 ? 'Einheit' : 'Einheiten' }}</p>
                        </div>
                        <div class="rounded-card bg-surface px-4 py-3.5">
                            <p class="text-[13px] text-ink-3">{{ sport === 'ride' ? 'Tempo Ø' : 'Pace Ø' }}</p>
                            <p class="mt-0.5 whitespace-nowrap font-rounded text-[28px] font-extrabold leading-8 tracking-[-0.035em] tabular-nums text-ink">{{ speed?.value ?? '–' }}<span v-if="speed" class="ml-1 text-[15px] font-semibold tracking-normal text-ink-3">{{ speed.unit }}</span></p>
                            <p v-if="speedDelta" class="mt-0.5 text-[13px] tabular-nums" :class="speedDelta < 0 ? 'text-success' : 'text-ink-3'">
                                {{ speedDelta < 0 ? '−' : '+' }}{{ Math.abs(speedDelta) }} s
                            </p>
                            <p v-else class="mt-0.5 text-[13px] text-ink-3">{{ sport === 'all' ? 'nur Läufe' : ' ' }}</p>
                        </div>
                        <div class="rounded-card bg-surface px-4 py-3.5">
                            <p class="text-[13px] text-ink-3">Höhenmeter</p>
                            <p class="mt-0.5 whitespace-nowrap font-rounded text-[28px] font-extrabold leading-8 tracking-[-0.035em] tabular-nums text-ink">{{ fmtNum(current.elevation, 0) }}<span class="ml-1 text-[15px] font-semibold tracking-normal text-ink-3">m</span></p>
                            <p class="mt-0.5 text-[13px] text-ink-3">Ø {{ fmtNum(avgElevation, 0) }} m</p>
                        </div>
                        <div v-if="intensity" class="rounded-card bg-surface px-4 py-3.5">
                            <p class="text-[13px] text-ink-3">Locker / Hart</p>
                            <p class="mt-0.5 whitespace-nowrap font-rounded text-[28px] font-extrabold leading-8 tracking-[-0.035em] tabular-nums text-ink">{{ 100 - intensity.hard_pct }}<span class="ml-1 text-[15px] font-semibold tracking-normal text-ink-3">/ {{ intensity.hard_pct }}</span></p>
                            <p class="mt-0.5 text-[13px]" :class="intensityOk ? 'text-success' : 'text-warn'">{{ intensityOk ? 'im Ziel' : 'zu viel hart' }} · 4 Wochen</p>
                        </div>
                        <div v-else class="rounded-card bg-surface px-4 py-3.5">
                            <p class="text-[13px] text-ink-3">Insgesamt</p>
                            <p class="mt-0.5 whitespace-nowrap font-rounded text-[28px] font-extrabold leading-8 tracking-[-0.035em] tabular-nums text-ink">{{ fmtNum(totals.km, 0) }}<span class="ml-1 text-[15px] font-semibold tracking-normal text-ink-3">km</span></p>
                            <p class="mt-0.5 text-[13px] text-ink-3">{{ fmtNum(totals.runs, 0) }} Einheiten</p>
                        </div>
                    </div>

                    <!-- ── Bestzeiten ─────────────────────────────── -->
                    <section v-if="bests.length">
                        <SectionHeader title="Bestzeiten" />
                        <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                            <component :is="b.activity_id ? Link : 'div'" v-for="b in bests" :key="b.label"
                                :href="b.activity_id ? route('activities.show', b.activity_id) : undefined"
                                class="flex items-center gap-3 px-4 py-3 transition-colors active:bg-surface-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">{{ b.label }}</span>
                                    <span class="block text-[13px] text-ink-3">{{ dateLabel(b.date) }}</span>
                                </span>
                                <span class="shrink-0 font-rounded text-[17px] font-bold tabular-nums text-ink">{{ clock(b.seconds) }}</span>
                            </component>
                        </div>
                    </section>
                </template>

                <AppCard v-else>
                    <EmptyState
                        title="Noch keine Aktivitäten"
                        description="Sobald deine ersten Aktivitäten da sind, entsteht hier deine Auswertung."
                    >
                        <AppButton :href="route('activities.index')" variant="secondary">Aktivitäten ansehen</AppButton>
                    </EmptyState>
                </AppCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
