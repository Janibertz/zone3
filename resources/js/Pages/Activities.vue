<script setup>
import { ref, computed } from 'vue';
import { router, Link, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps({
    activities: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');
const selectedType = ref(props.filters?.type ?? '');
const selectedMonth = ref(props.filters?.month ?? '');

function applyFilters() {
    router.get(route('activities.index'), {
        search: search.value || undefined,
        type: selectedType.value || undefined,
        month: selectedMonth.value || undefined,
    }, { preserveState: true, replace: true });
}

function resetFilters() {
    search.value = '';
    selectedType.value = '';
    selectedMonth.value = '';
    applyFilters();
}

function formatDistance(m) {
    return (m / 1000).toFixed(2);
}

function formatDuration(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    return `${m}:${String(s).padStart(2,'0')}`;
}

function formatPace(averageSpeed) {
    if (!averageSpeed || averageSpeed === 0) return '–';
    const secPerKm = 1000 / averageSpeed;
    return `${Math.floor(secPerKm / 60)}:${String(Math.floor(secPerKm % 60)).padStart(2,'0')}`;
}

function formatDateShort(dateStr) {
    return new Date(dateStr).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: 'short' });
}

const TYPES = {
    Run:            { label: 'Laufen',       emoji: '🏃', pill: 'bg-accent-soft text-accent-ink'   },
    VirtualRun:     { label: 'Laufen',       emoji: '🏃', pill: 'bg-accent-soft text-accent-ink'   },
    TrailRun:       { label: 'Trail',        emoji: '⛰️', pill: 'bg-accent-soft text-accent-ink'   },
    Ride:           { label: 'Rad',          emoji: '🚴', pill: 'bg-success-soft text-success-ink' },
    VirtualRide:    { label: 'Rad virtuell', emoji: '🚴', pill: 'bg-success-soft text-success-ink' },
    EBikeRide:      { label: 'E-Bike',       emoji: '🚴', pill: 'bg-success-soft text-success-ink' },
    Swim:           { label: 'Schwimmen',    emoji: '🏊', pill: 'bg-info-soft text-info-ink'       },
    Walk:           { label: 'Gehen',        emoji: '🚶', pill: 'bg-warn-soft text-warn-ink'       },
    Hike:           { label: 'Wandern',      emoji: '🥾', pill: 'bg-warn-soft text-warn-ink'       },
    Workout:        { label: 'Workout',      emoji: '💪', pill: 'bg-warn-soft text-warn-ink'       },
    WeightTraining: { label: 'Kraft',        emoji: '💪', pill: 'bg-warn-soft text-warn-ink'       },
    Yoga:           { label: 'Yoga',         emoji: '🧘', pill: 'bg-success-soft text-success-ink' },
};
const typeOf = (t) => TYPES[t] ?? { label: t, emoji: '🏅', pill: 'bg-surface-2 text-ink-2' };

const monthOptions = computed(() => {
    const opts = [];
    for (let i = 0; i < 12; i++) {
        const d = new Date();
        d.setDate(1);
        d.setMonth(d.getMonth() - i);
        opts.push({
            value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2,'0')}`,
            label: d.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }),
        });
    }
    return opts;
});

const activeFilterCount = computed(() =>
    [selectedType.value, selectedMonth.value, search.value].filter(Boolean).length
);

/** Kennzahlen einer Aktivität, je nach Sportart unterschiedlich sinnvoll. */
function metricsOf(a) {
    const out = [];
    if (a.distance > 0) out.push({ value: formatDistance(a.distance), unit: 'km', strong: true });
    out.push({ value: formatDuration(a.moving_time), unit: null });

    if (['Ride', 'VirtualRide', 'EBikeRide'].includes(a.type)) {
        if (a.average_speed)  out.push({ value: (a.average_speed * 3.6).toFixed(1), unit: 'km/h' });
        if (a.average_watts)  out.push({ value: Math.round(a.average_watts), unit: 'W' });
    } else if (a.type === 'Swim') {
        if (a.average_speed)  out.push({ value: formatPace(a.average_speed * 10), unit: '/100m' });
    } else if (a.average_speed) {
        out.push({ value: formatPace(a.average_speed), unit: '/km' });
    }
    return out;
}
</script>

<template>
    <Head title="Aktivitäten" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <div class="space-y-5 px-4 py-4 lg:px-6 lg:py-6">

                <header class="px-1">
                    <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Aktivitäten</h1>
                    <p class="mt-1 text-[15px] text-ink-3">
                        {{ activities.total }} {{ activities.total === 1 ? 'Einheit' : 'Einheiten' }} insgesamt
                    </p>
                </header>

                <!-- ── Filter ─────────────────────────────────────── -->
                <AppCard>
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[2fr_1fr_1fr]">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803a7.5 7.5 0 0010.607 10.607z"/>
                            </svg>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Aktivität suchen…"
                                class="z-input pl-11"
                                @keyup.enter="applyFilters"
                            />
                        </div>

                        <select v-model="selectedType" class="z-input" @change="applyFilters">
                            <option value="">Alle Typen</option>
                            <option value="Run">Laufen</option>
                            <option value="Ride">Radfahren</option>
                            <option value="Swim">Schwimmen</option>
                            <option value="Walk">Gehen</option>
                            <option value="Workout">Workout</option>
                        </select>

                        <select v-model="selectedMonth" class="z-input" @change="applyFilters">
                            <option value="">Alle Monate</option>
                            <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>

                    <div v-if="activeFilterCount > 0" class="mt-3 flex items-center gap-3">
                        <span class="text-[13px] font-semibold text-ink-2">
                            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'Filter' : 'Filter' }} aktiv
                        </span>
                        <button class="text-[13px] font-semibold text-danger hover:underline" @click="resetFilters">
                            Zurücksetzen
                        </button>
                    </div>
                </AppCard>

                <!-- ── Liste ──────────────────────────────────────── -->
                <AppCard v-if="activities.data.length === 0">
                    <EmptyState
                        title="Keine Aktivitäten gefunden"
                        :description="activeFilterCount > 0
                            ? 'Mit diesen Filtern gibt es nichts. Setz sie zurück, um alles zu sehen.'
                            : 'Verbinde Strava und synchronisiere, dann erscheinen deine Einheiten hier.'"
                    >
                        <AppButton v-if="activeFilterCount > 0" variant="secondary" @click="resetFilters">Filter zurücksetzen</AppButton>
                        <AppButton v-else href="/profile">Strava verbinden</AppButton>
                    </EmptyState>
                </AppCard>

                <div v-else class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                    <Link
                        v-for="activity in activities.data"
                        :key="activity.id"
                        :href="route('activities.show', activity.id)"
                        class="block min-w-0 rounded-card bg-surface p-4 shadow-card transition-transform active:scale-[0.99]"
                    >
                        <div class="mb-2.5 flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="shrink-0 text-lg leading-none">{{ typeOf(activity.type).emoji }}</span>
                                <p class="truncate text-[15px] font-semibold leading-tight text-ink">{{ activity.name }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeOf(activity.type).pill">
                                {{ typeOf(activity.type).label }}
                            </span>
                        </div>

                        <p class="mb-2 text-[12px] text-ink-3">
                            {{ formatDateShort(activity.start_date) }}
                            <template v-if="activity.location_city"> · {{ activity.location_city }}</template>
                        </p>

                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span v-for="(m, i) in metricsOf(activity)" :key="i" class="flex items-baseline gap-1">
                                <span class="tabular-nums" :class="m.strong ? 'text-xl font-black leading-none text-ink' : 'text-[15px] font-semibold text-ink-2'">{{ m.value }}</span>
                                <span v-if="m.unit" class="text-[11px] font-semibold text-ink-3">{{ m.unit }}</span>
                            </span>
                            <span v-if="activity.average_heartrate" class="ml-auto text-[13px] font-semibold text-danger">
                                ♥ {{ Math.round(activity.average_heartrate) }}
                            </span>
                        </div>
                    </Link>
                </div>

                <!-- ── Seitenwechsel ──────────────────────────────── -->
                <div v-if="activities.last_page > 1" class="flex flex-wrap items-center justify-center gap-1.5 pt-2">
                    <button
                        v-for="link in activities.links"
                        :key="link.label"
                        v-html="link.label"
                        :disabled="!link.url"
                        class="min-w-[2.5rem] rounded-full px-3 py-2 text-[13px] font-semibold transition-colors"
                        :class="link.active
                            ? 'bg-ink text-canvas'
                            : link.url
                                ? 'bg-surface text-ink-2 hover:bg-surface-2'
                                : 'text-ink-3 cursor-default opacity-50'"
                        @click="link.url && router.visit(link.url)"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
