<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

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

function formatDistance(m) {
    return (m / 1000).toFixed(2) + ' km';
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
    const min = Math.floor(secPerKm / 60);
    const sec = Math.floor(secPerKm % 60);
    return `${min}:${String(sec).padStart(2,'0')}`;
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateShort(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

const typeLabel = { Run: 'Laufen', Ride: 'Radfahren', VirtualRide: 'Virtual Ride', Swim: 'Schwimmen', Walk: 'Gehen', Workout: 'Workout' };
const typeColors = {
    Run:         'bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    Ride:        'bg-green-100 dark:bg-green-500/15 text-green-700 dark:text-green-300',
    VirtualRide: 'bg-green-100 dark:bg-green-500/15 text-green-700 dark:text-green-300',
    Swim:        'bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300',
    Workout:     'bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300',
    Walk:        'bg-yellow-100 dark:bg-yellow-500/15 text-yellow-700 dark:text-yellow-300',
};
function typeColor(t) {
    return typeColors[t] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300';
}

const monthOptions = computed(() => {
    const opts = [];
    for (let i = 0; i < 12; i++) {
        const d = new Date();
        d.setDate(1);
        d.setMonth(d.getMonth() - i);
        const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2,'0')}`;
        const label = d.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
        opts.push({ value, label });
    }
    return opts;
});

const activeFilterCount = computed(() =>
    [selectedType.value, selectedMonth.value, search.value].filter(Boolean).length
);
</script>

<template>
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">Aktivitäten</h1>
                    <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {{ activities.total }} {{ activities.total === 1 ? 'Aktivität' : 'Aktivitäten' }}
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="mb-4 space-y-2">
                <!-- Search -->
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 dark:text-slate-500 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                    <input
                        v-model="search"
                        @keyup.enter="applyFilters"
                        type="text"
                        placeholder="Aktivität suchen…"
                        class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                </div>

                <!-- Type + Month in a row -->
                <div class="grid grid-cols-2 gap-2">
                    <select
                        v-model="selectedType"
                        @change="applyFilters"
                        class="w-full py-2.5 px-3 text-sm rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        <option value="">Alle Typen</option>
                        <option value="Run">Laufen</option>
                        <option value="Ride">Radfahren (inkl. Virtual)</option>
                        <option value="Swim">Schwimmen</option>
                        <option value="Walk">Gehen</option>
                        <option value="Workout">Workout</option>
                    </select>
                    <select
                        v-model="selectedMonth"
                        @change="applyFilters"
                        class="w-full py-2.5 px-3 text-sm rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        <option value="">Alle Monate</option>
                        <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <!-- Active filters badge + clear -->
                <div v-if="activeFilterCount > 0" class="flex items-center gap-2">
                    <span class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">{{ activeFilterCount }} Filter aktiv</span>
                    <button
                        @click="search=''; selectedType=''; selectedMonth=''; applyFilters()"
                        class="text-xs text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                    >
                        ✕ Zurücksetzen
                    </button>
                </div>
            </div>

            <!-- Activity list -->
            <div class="space-y-2">
                <Link
                    v-for="activity in activities.data"
                    :key="activity.id"
                    :href="route('activities.show', activity.id)"
                    class="block w-full text-left bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 active:scale-[0.99] hover:border-indigo-200 dark:hover:border-indigo-500/40 hover:shadow-sm transition-all duration-150"
                >
                    <!-- Top row: type badge + date + chevron -->
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full" :class="typeColor(activity.type)">
                                {{ typeLabel[activity.type] ?? activity.type }}
                            </span>
                            <span class="text-xs text-gray-400 dark:text-slate-500 truncate">{{ formatDateShort(activity.start_date) }}</span>
                            <span v-if="activity.location_city" class="hidden sm:inline text-xs text-gray-400 dark:text-slate-500 truncate">· {{ activity.location_city }}</span>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </div>

                    <!-- Name -->
                    <p class="font-semibold text-gray-900 dark:text-white text-sm truncate mb-3">{{ activity.name }}</p>

                    <!-- Stats row -->
                    <div class="flex items-center gap-4 text-sm">
                        <div class="flex items-center gap-1.5">
                            <span class="text-gray-400 dark:text-slate-500 text-xs">↗</span>
                            <span class="font-semibold text-gray-900 dark:text-white">{{ formatDistance(activity.distance) }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-gray-400 dark:text-slate-500 text-xs">⏱</span>
                            <span class="font-semibold text-gray-900 dark:text-white">{{ formatDuration(activity.moving_time) }}</span>
                        </div>
                        <div v-if="activity.type === 'Run' && activity.average_speed" class="flex items-center gap-1.5">
                            <span class="text-gray-400 dark:text-slate-500 text-xs">⚡</span>
                            <span class="font-semibold text-gray-900 dark:text-white">{{ formatPace(activity.average_speed) }}<span class="text-xs font-normal text-gray-400 dark:text-slate-500">/km</span></span>
                        </div>
                        <div v-if="activity.average_heartrate" class="flex items-center gap-1.5 ml-auto">
                            <span class="text-red-400 text-xs">♥</span>
                            <span class="text-xs font-medium text-gray-600 dark:text-slate-400">{{ Math.round(activity.average_heartrate) }}</span>
                        </div>
                    </div>
                </Link>

                <!-- Empty state -->
                <div v-if="activities.data.length === 0" class="flex flex-col items-center justify-center py-16 gap-3">
                    <div class="h-16 w-16 rounded-2xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                        <svg class="h-8 w-8 text-gray-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75z" />
                        </svg>
                    </div>
                    <p class="text-gray-500 dark:text-slate-400 font-medium">Keine Aktivitäten gefunden</p>
                    <button v-if="activeFilterCount > 0" @click="search=''; selectedType=''; selectedMonth=''; applyFilters()" class="text-sm text-indigo-600 dark:text-indigo-400">Filter zurücksetzen</button>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="activities.last_page > 1" class="mt-6 flex items-center justify-center gap-1.5 flex-wrap">
                <button
                    v-for="link in activities.links"
                    :key="link.label"
                    v-html="link.label"
                    :disabled="!link.url"
                    class="px-3 py-1.5 text-sm rounded-xl transition-colors"
                    :class="link.active
                        ? 'bg-indigo-600 text-white font-semibold'
                        : link.url
                            ? 'bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700'
                            : 'text-gray-300 dark:text-slate-600 cursor-default'"
                    @click="link.url && router.visit(link.url)"
                />
            </div>
        </div>

    </AuthenticatedLayout>
</template>
