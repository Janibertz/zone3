<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps({
    monthlyStats: Array,
    weeklyStats:  Array,
    paceTrend:    Array,
    totals:       Object,
    sport:        { type: String, default: 'all' },
    sportOptions: { type: Array,  default: () => [] },
});

/**
 * Die Seite zaehlte fest nur Laeufe. Ueber Strava kommt aber alles herein —
 * wer Rad faehrt, sah seine Kilometer nirgends. Gefiltert wird auf dem
 * Server, damit auch die Summen stimmen und nicht nur die Anzeige.
 */
const sportFilter = ref(props.sport);

watch(sportFilter, (value) => {
    if (value === props.sport) return;
    router.get(route('statistics.index'), { sport: value }, { preserveScroll: true, preserveState: true });
});

/**
 * „Läufe" stimmt nur, solange auch nur Laeufe gezaehlt werden. Steht der
 * Filter auf Rad, sind es Fahrten — und unter „Alle" ist es beides, also
 * weder das eine noch das andere.
 */
const COUNT_WORDS = {
    run:  ['Lauf', 'Läufe'],
    ride: ['Fahrt', 'Fahrten'],
    swim: ['Einheit', 'Einheiten'],
    all:  ['Aktivität', 'Aktivitäten'],
};

const countWords = computed(() => COUNT_WORDS[props.sport] ?? COUNT_WORDS.all);
const countLabel = computed(() => countWords.value[1]);
const timeLabel  = computed(() => (props.sport === 'run' ? 'Laufzeit' : 'Zeit'));

/** „8 Fahrten", „1 Lauf" — mit der Einzahl, wo sie hingehoert. */
function counted(n) {
    const [one, many] = countWords.value;
    return `${n ?? 0} ${(n ?? 0) === 1 ? one : many}`;
}

const hasData = computed(() => (props.totals?.runs ?? 0) > 0);

/* ── Formatierung ──────────────────────────────────────────────── */

function formatTime(minutes) {
    if (!minutes) return '0m';
    if (minutes < 60) return `${minutes}m`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function fmtPace(sec) {
    if (!Number.isFinite(sec)) return '–';
    return `${Math.floor(sec / 60)}:${String(Math.round(sec % 60)).padStart(2, '0')}`;
}

function km(v) {
    return Number(v ?? 0).toLocaleString('de-DE', { maximumFractionDigits: 1 });
}

/* ── Volumen: eine Karte, zwei Zeiträume ───────────────────────── */

const range = ref('weeks');
const rangeOptions = [
    { value: 'weeks',  label: '8 Wochen' },
    { value: 'months', label: '12 Monate' },
];

const series = computed(() =>
    range.value === 'weeks' ? (props.weeklyStats ?? []) : (props.monthlyStats ?? [])
);

// Angetippter Balken. Standard ist der aktuellste — der interessiert am meisten.
const picked = ref(null);
watch(range, () => { picked.value = null; });

const activeIndex = computed(() => picked.value ?? series.value.length - 1);
const active = computed(() => series.value[activeIndex.value] ?? null);

const seriesMax = computed(() => Math.max(...series.value.map(d => d.km), 1));

const seriesSummary = computed(() => {
    const data = series.value;
    if (!data.length) return null;

    const total = data.reduce((sum, d) => sum + d.km, 0);

    return {
        total: Math.round(total * 10) / 10,
        avg:   Math.round((total / data.length) * 10) / 10,
    };
});

/**
 * Der letzte Balken ist die laufende Woche beziehungsweise der laufende
 * Monat und damit unvollständig. Ihn gegen einen fertigen Zeitraum zu
 * stellen ergibt am Wochenanfang immer „−100 %", ganz gleich wie trainiert
 * wurde. Verglichen werden deshalb die beiden letzten *abgeschlossenen*
 * Zeiträume.
 */
const momentum = computed(() => {
    const data = series.value;
    if (data.length < 3) return null;

    const current  = data[data.length - 2].km;
    const previous = data[data.length - 3].km;
    if (!previous) return null;

    const diff = current - previous;
    return {
        diff:    Math.round(diff * 10) / 10,
        percent: Math.round((diff / previous) * 100),
        up:      diff >= 0,
        label:   range.value === 'weeks' ? 'letzte volle Woche' : 'letzter voller Monat',
    };
});

function barHeight(value) {
    if (!value) return 2;
    return Math.max((value / seriesMax.value) * 100, 4);
}

/* ── Pace-Trend ────────────────────────────────────────────────── */

const paceRuns = computed(() => props.paceTrend ?? []);

const paceStats = computed(() => {
    const runs = paceRuns.value;
    if (!runs.length) return null;

    const secs = runs.map(r => r.pace_sec);
    const fastest = Math.min(...secs);
    const slowest = Math.max(...secs);

    // Erste gegen zweite Hälfte — grob, aber ehrlich.
    const half  = Math.floor(runs.length / 2);
    const mean  = arr => arr.reduce((a, b) => a + b, 0) / arr.length;
    const shift = half > 0 ? mean(secs.slice(0, half)) - mean(secs.slice(half)) : 0;

    return {
        fastest,
        slowest,
        avg:   mean(secs),
        shift: Math.round(shift),
        span:  slowest - fastest || 1,
    };
});

const pickedRun = ref(null);
const activeRunIndex = computed(() => pickedRun.value ?? paceRuns.value.length - 1);
const activeRun = computed(() => paceRuns.value[activeRunIndex.value] ?? null);

/** 0 = langsamster Lauf (unten), 100 = schnellster (oben). */
function paceY(sec) {
    const s = paceStats.value;
    if (!s) return 50;
    return ((s.slowest - sec) / s.span) * 100;
}

function pointX(index) {
    const n = paceRuns.value.length;
    if (n < 2) return 50;
    return (index / (n - 1)) * 100;
}

/** Kurve im 0–100-Koordinatenraum; der Pfad wird per CSS gestreckt. */
const paceLine = computed(() =>
    paceRuns.value
        .map((r, i) => `${i === 0 ? 'M' : 'L'}${pointX(i).toFixed(2)},${(100 - paceY(r.pace_sec)).toFixed(2)}`)
        .join(' ')
);

const paceArea = computed(() => {
    if (paceRuns.value.length < 2) return '';
    return `${paceLine.value} L100,100 L0,100 Z`;
});

/* ── Höhepunkte ────────────────────────────────────────────────── */

function peak(list) {
    if (!list?.length) return null;
    const best = list.reduce((a, b) => (b.km > a.km ? b : a), list[0]);
    return best.km > 0 ? best : null;
}

const bestWeek  = computed(() => peak(props.weeklyStats));
const bestMonth = computed(() => peak(props.monthlyStats));

const longestRun = computed(() => {
    const runs = paceRuns.value;
    if (!runs.length) return null;
    return runs.reduce((a, b) => (b.distance > a.distance ? b : a), runs[0]);
});

const avgDistance = computed(() => {
    const t = props.totals;
    if (!t?.runs) return null;
    return Math.round((t.km / t.runs) * 10) / 10;
});
</script>

<template>
    <Head title="Statistiken" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <div class="space-y-6 px-4 py-4 lg:px-6 lg:py-6">

                <header class="flex items-end justify-between gap-3 px-1">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Statistiken</h1>
                        <p class="mt-1 text-[15px] text-ink-3">Deine Trainingsanalyse auf einen Blick</p>
                    </div>
                    <AppButton :href="route('wrapped.index')" variant="secondary" size="sm" class="shrink-0">
                        <span aria-hidden="true">🎁</span> Rückblick
                    </AppButton>
                </header>

                <SegmentedControl v-if="sportOptions.length" v-model="sportFilter" :options="sportOptions" />

                <template v-if="hasData">
                    <!-- ── Gesamtbilanz ───────────────────────────── -->
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <StatTile :label="countLabel" :value="totals.runs" />
                        <StatTile label="Distanz" :value="km(totals.km)" unit="km" />
                        <StatTile :label="timeLabel" :value="formatTime(totals.time_min)" />
                        <StatTile
                            label="Ø Pace"
                            :value="totals.avg_pace ?? '–'"
                            :unit="totals.avg_pace ? '/km' : null"
                            hint="letzte 20 Läufe"
                            tone="accent"
                        />
                        <StatTile
                            label="Höhenmeter"
                            :value="(totals.elevation ?? 0).toLocaleString('de-DE')"
                            unit="hm"
                            class="col-span-2 sm:col-span-1"
                        />
                    </div>

                    <!-- ── Verlauf ────────────────────────────────── -->
                    <section>
                        <SectionHeader title="Verlauf" />

                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

                            <!-- Volumen -->
                            <AppCard flush class="xl:col-span-2">
                                <div class="flex flex-col gap-4 p-5 pb-0 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-medium text-ink-3">{{ active?.label ?? 'Volumen' }}</p>
                                        <p class="mt-1 flex items-baseline gap-1.5">
                                            <span class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ km(active?.km ?? 0) }}</span>
                                            <span class="text-sm font-medium text-ink-3">km</span>
                                        </p>
                                        <p class="mt-1 text-[13px] text-ink-3">
                                            {{ counted(active?.runs) }}
                                            <template v-if="active?.time_min"> · {{ formatTime(active.time_min) }}</template>
                                        </p>
                                    </div>

                                    <div class="shrink-0 sm:w-56">
                                        <SegmentedControl v-model="range" :options="rangeOptions" />
                                    </div>
                                </div>

                                <div class="flex h-44 items-end gap-1.5 px-5 pt-5 sm:gap-2">
                                    <button
                                        v-for="(bucket, i) in series"
                                        :key="bucket.label"
                                        type="button"
                                        class="group flex h-full min-w-0 flex-1 flex-col justify-end rounded-t-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                        :aria-label="`${bucket.label}: ${bucket.km} km`"
                                        :aria-pressed="i === activeIndex"
                                        @click="picked = i"
                                        @mouseenter="picked = i"
                                    >
                                        <span
                                            class="w-full rounded-t-lg transition-all duration-300"
                                            :class="bucket.km === 0
                                                ? 'bg-surface-2'
                                                : i === activeIndex
                                                    ? 'bg-accent'
                                                    : 'bg-accent/25 group-hover:bg-accent/50'"
                                            :style="{ height: barHeight(bucket.km) + '%' }"
                                        />
                                    </button>
                                </div>

                                <div class="flex gap-1.5 px-5 pt-2 sm:gap-2">
                                    <span
                                        v-for="(bucket, i) in series"
                                        :key="bucket.label"
                                        class="min-w-0 flex-1 truncate text-center text-[10px] leading-none transition-colors sm:text-[11px]"
                                        :class="i === activeIndex ? 'font-semibold text-ink' : 'text-ink-3'"
                                    >{{ bucket.label }}</span>
                                </div>

                                <div v-if="seriesSummary" class="mt-5 grid grid-cols-3 divide-x divide-line border-t border-line">
                                    <div class="px-5 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Gesamt</p>
                                        <p class="mt-1 text-[15px] font-semibold tabular-nums text-ink">{{ km(seriesSummary.total) }} km</p>
                                    </div>
                                    <div class="px-5 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">
                                            Ø {{ range === 'weeks' ? 'Woche' : 'Monat' }}
                                        </p>
                                        <p class="mt-1 text-[15px] font-semibold tabular-nums text-ink">{{ km(seriesSummary.avg) }} km</p>
                                    </div>
                                    <div class="px-5 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Trend</p>
                                        <p v-if="momentum"
                                            class="mt-1 text-[15px] font-semibold tabular-nums"
                                            :class="momentum.up ? 'text-success' : 'text-ink-2'">
                                            {{ momentum.up ? '+' : '' }}{{ momentum.percent }}%
                                        </p>
                                        <p v-else class="mt-1 text-[15px] font-semibold text-ink-3">–</p>
                                        <p v-if="momentum" class="mt-0.5 text-[11px] text-ink-3">{{ momentum.label }}</p>
                                    </div>
                                </div>
                            </AppCard>

                            <!-- Pace-Trend -->
                            <AppCard flush>
                                <div class="flex items-start justify-between gap-3 p-5 pb-0">
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-medium text-ink-3">Pace-Trend</p>
                                        <p class="mt-1 flex items-baseline gap-1.5">
                                            <span class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ activeRun?.pace_label ?? '–' }}</span>
                                            <span class="text-sm font-medium text-ink-3">/km</span>
                                        </p>
                                        <p v-if="activeRun" class="mt-1 truncate text-[13px] text-ink-3">
                                            {{ activeRun.date }} · {{ activeRun.distance }} km
                                        </p>
                                    </div>
                                    <span v-if="paceStats && paceStats.shift !== 0"
                                        class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="paceStats.shift > 0 ? 'bg-success-soft text-success-ink' : 'bg-surface-2 text-ink-2'"
                                        :title="paceStats.shift > 0 ? 'Zuletzt schneller unterwegs' : 'Zuletzt langsamer unterwegs'">
                                        {{ paceStats.shift > 0 ? '−' : '+' }}{{ Math.abs(paceStats.shift) }}s
                                    </span>
                                </div>

                                <div v-if="paceRuns.length > 1" class="relative mt-4 h-44 px-5">
                                    <svg class="h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                                        <defs>
                                            <linearGradient id="paceFade" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="rgb(var(--z-success))" stop-opacity="0.28" />
                                                <stop offset="100%" stop-color="rgb(var(--z-success))" stop-opacity="0" />
                                            </linearGradient>
                                        </defs>
                                        <path :d="paceArea" fill="url(#paceFade)" />
                                        <path
                                            :d="paceLine"
                                            fill="none"
                                            stroke="rgb(var(--z-success))"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            vector-effect="non-scaling-stroke"
                                        />
                                    </svg>

                                    <!-- Marker liegt darüber, damit er rund bleibt -->
                                    <span
                                        v-if="activeRun"
                                        class="pointer-events-none absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-success transition-all duration-200"
                                        :style="{
                                            left: `calc(1.25rem + (100% - 2.5rem) * ${pointX(activeRunIndex) / 100})`,
                                            top:  `${100 - paceY(activeRun.pace_sec)}%`,
                                        }"
                                    />

                                    <div class="absolute inset-0 flex px-5">
                                        <button
                                            v-for="(run, i) in paceRuns"
                                            :key="run.date + run.name + i"
                                            type="button"
                                            class="h-full min-w-0 flex-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-success"
                                            :aria-label="`${run.date}: ${run.pace_label} pro Kilometer`"
                                            @click="pickedRun = i"
                                            @mouseenter="pickedRun = i"
                                        />
                                    </div>
                                </div>

                                <p v-else class="px-5 py-12 text-center text-sm text-ink-3">
                                    Noch zu wenig Daten für einen Trend
                                </p>

                                <div v-if="paceStats" class="mt-5 grid grid-cols-3 divide-x divide-line border-t border-line">
                                    <div class="px-4 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Schnellste</p>
                                        <p class="mt-1 text-[15px] font-semibold tabular-nums text-success">{{ fmtPace(paceStats.fastest) }}</p>
                                    </div>
                                    <div class="px-4 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Ø</p>
                                        <p class="mt-1 text-[15px] font-semibold tabular-nums text-ink">{{ fmtPace(paceStats.avg) }}</p>
                                    </div>
                                    <div class="px-4 py-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Langsamste</p>
                                        <p class="mt-1 text-[15px] font-semibold tabular-nums text-ink-2">{{ fmtPace(paceStats.slowest) }}</p>
                                    </div>
                                </div>
                            </AppCard>
                        </div>
                    </section>

                    <!-- ── Höhepunkte ─────────────────────────────── -->
                    <section>
                        <SectionHeader title="Höhepunkte" />

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <StatTile
                                v-if="bestWeek"
                                label="Beste Woche"
                                :value="km(bestWeek.km)"
                                unit="km"
                                :hint="`${bestWeek.label} · ${counted(bestWeek.runs)}`"
                            />
                            <StatTile
                                v-if="bestMonth"
                                label="Bester Monat"
                                :value="km(bestMonth.km)"
                                unit="km"
                                :hint="`${bestMonth.label} · ${counted(bestMonth.runs)}`"
                            />
                            <StatTile
                                v-if="longestRun"
                                label="Längster Lauf"
                                :value="longestRun.distance"
                                unit="km"
                                :hint="`${longestRun.date} · ${longestRun.pace_label} /km`"
                            />
                            <StatTile
                                v-if="avgDistance"
                                label="Ø pro Lauf"
                                :value="km(avgDistance)"
                                unit="km"
                                :hint="`über ${counted(totals.runs)}`"
                            />
                        </div>
                    </section>
                </template>

                <!-- ── Ohne Daten ─────────────────────────────────── -->
                <AppCard v-else>
                    <EmptyState
                        title="Noch keine Aktivitäten"
                        description="Sobald deine ersten Aktivitäten da sind, entsteht hier deine Auswertung."
                    >
                        <template #icon>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                            </svg>
                        </template>
                        <AppButton :href="route('activities.index')" variant="secondary">Aktivitäten ansehen</AppButton>
                    </EmptyState>
                </AppCard>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
