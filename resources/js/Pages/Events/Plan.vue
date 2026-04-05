<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    event: Object,
    plan:  Object,
});

// ── State ────────────────────────────────────────────────────────────────────
const currentPlan  = ref(props.plan);
const generating   = ref(false);
const errorMsg     = ref('');

// ── Generate plan ────────────────────────────────────────────────────────────
async function generatePlan() {
    generating.value = true;
    errorMsg.value   = '';
    try {
        const res = await axios.post(route('events.plan.generate', props.event.id));
        currentPlan.value = res.data.plan;
    } catch (e) {
        errorMsg.value = e?.response?.data?.error ?? 'Fehler beim Erstellen des Plans. Bitte versuche es erneut.';
    } finally {
        generating.value = false;
    }
}

// ── Session type config ──────────────────────────────────────────────────────
const typeConfig = {
    rest: {
        label: 'Ruhetag',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />`,
        bg: 'bg-gray-50 dark:bg-slate-800',
        text: 'text-gray-500 dark:text-slate-400',
        badge: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        border: 'border-gray-100 dark:border-slate-700',
    },
    easy_run: {
        label: 'Lockerer Lauf',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.493 4.493 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />`,
        bg: 'bg-green-50 dark:bg-green-500/10',
        text: 'text-green-700 dark:text-green-400',
        badge: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
        border: 'border-green-100 dark:border-green-500/20',
    },
    tempo_run: {
        label: 'Tempolauf',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />`,
        bg: 'bg-amber-50 dark:bg-amber-500/10',
        text: 'text-amber-700 dark:text-amber-400',
        badge: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
        border: 'border-amber-100 dark:border-amber-500/20',
    },
    interval: {
        label: 'Intervall',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605" />`,
        bg: 'bg-red-50 dark:bg-red-500/10',
        text: 'text-red-700 dark:text-red-400',
        badge: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
        border: 'border-red-100 dark:border-red-500/20',
    },
    long_run: {
        label: 'Langer Lauf',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />`,
        bg: 'bg-blue-50 dark:bg-blue-500/10',
        text: 'text-blue-700 dark:text-blue-400',
        badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400',
        border: 'border-blue-100 dark:border-blue-500/20',
    },
    race_prep: {
        label: 'Rennvorbereitung',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />`,
        bg: 'bg-indigo-50 dark:bg-indigo-500/10',
        text: 'text-indigo-700 dark:text-indigo-400',
        badge: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400',
        border: 'border-indigo-100 dark:border-indigo-500/20',
    },
};

function typeOf(t) {
    return typeConfig[t] ?? typeConfig['easy_run'];
}

const priorityColors = {
    A: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10',
    B: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10',
    C: 'text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-700',
};

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'short' });
}

function isToday(dateStr) {
    return dateStr === new Date().toISOString().slice(0, 10);
}

function isPast(dateStr) {
    return dateStr < new Date().toISOString().slice(0, 10);
}

const weeklyLoad = computed(() => {
    if (!currentPlan.value) return null;
    const sessions = currentPlan.value.sessions;
    const total = sessions.reduce((s, x) => s + (x.distance_km || 0), 0);
    const runs  = sessions.filter(x => x.type !== 'rest').length;
    return { total: Math.round(total * 10) / 10, runs };
});
</script>

<template>
    <Head :title="`Plan – ${event.name}`" />
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 py-4 sm:py-8">

            <!-- Back link -->
            <Link :href="route('events.index')" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-white transition-colors mb-4">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Zurück zu Events
            </Link>

            <!-- Event header -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm p-4 sm:p-5 mb-5">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 h-10 w-10 rounded-xl flex items-center justify-center font-bold text-lg"
                        :class="priorityColors[event.priority]">
                        {{ event.priority }}
                    </span>
                    <div class="flex-1">
                        <h1 class="text-lg font-bold text-gray-900 dark:text-white">{{ event.name }}</h1>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-sm text-gray-500 dark:text-slate-400">
                            <span>{{ new Date(event.event_date).toLocaleDateString('de-DE', { day: '2-digit', month: 'long', year: 'numeric' }) }}</span>
                            <span class="text-indigo-600 dark:text-indigo-400 font-medium">{{ event.distance_label }}</span>
                            <span v-if="event.target_time_formatted">Ziel: {{ event.target_time_formatted }}</span>
                            <span :class="event.days_until <= 7 ? 'text-red-600 dark:text-red-400 font-semibold' : ''">
                                {{ event.days_until > 0 ? `noch ${event.days_until} Tage` : event.days_until === 0 ? 'Heute!' : 'Vorbei' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Plan header / generate button -->
            <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">10-Tages-Trainingsplan</h2>
                    <p v-if="currentPlan" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                        Erstellt am {{ currentPlan.generated_at }}
                        <span v-if="currentPlan.context">
                            · {{ currentPlan.context.activities_used }} Aktivitäten analysiert
                            <template v-if="!currentPlan.context.has_runner_profile"> · Kein Athletenprofil</template>
                        </span>
                    </p>
                </div>
                <button
                    @click="generatePlan"
                    :disabled="generating"
                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors shadow-sm disabled:opacity-60"
                    :class="currentPlan ? 'bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700' : 'bg-indigo-600 text-white hover:bg-indigo-700'"
                >
                    <svg v-if="generating" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                    {{ generating ? 'KI analysiert...' : currentPlan ? 'Plan aktualisieren' : 'KI-Plan erstellen' }}
                </button>
            </div>

            <!-- Error -->
            <div v-if="errorMsg" class="mb-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                {{ errorMsg }}
            </div>

            <!-- Generating spinner overlay -->
            <div v-if="generating" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-10 text-center mb-4">
                <svg class="h-10 w-10 animate-spin mx-auto text-indigo-500 mb-3" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                </svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">KI analysiert deine Daten...</p>
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Aktivitäten, Wellbeing und Athletenprofil werden ausgewertet</p>
            </div>

            <!-- No plan yet -->
            <div v-else-if="!currentPlan" class="bg-white dark:bg-slate-900 rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 p-10 text-center">
                <svg class="h-12 w-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Noch kein Trainingsplan</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500 max-w-xs mx-auto">
                    Die KI analysiert deine vergangenen Aktivitäten, Wellbeing-Daten und dein Athletenprofil um einen optimalen 10-Tages-Plan zu erstellen.
                </p>
                <button @click="generatePlan" :disabled="generating"
                    class="mt-5 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                    KI-Plan erstellen
                </button>
            </div>

            <!-- Plan sessions -->
            <template v-else>
                <!-- Summary strip -->
                <div v-if="weeklyLoad" class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 px-4 py-3 text-center">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ weeklyLoad.total }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">km geplant</div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 px-4 py-3 text-center">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ weeklyLoad.runs }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Trainingseinheiten</div>
                    </div>
                </div>

                <!-- Sessions -->
                <div class="space-y-2">
                    <div
                        v-for="(session, idx) in currentPlan.sessions"
                        :key="idx"
                        class="rounded-2xl border overflow-hidden transition-all"
                        :class="[typeOf(session.type).border, isToday(session.date) ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-950' : '', isPast(session.date) ? 'opacity-60' : '']"
                    >
                        <div class="flex gap-3 p-3 sm:p-4" :class="typeOf(session.type).bg">
                            <!-- Icon -->
                            <div class="shrink-0 h-10 w-10 rounded-xl flex items-center justify-center" :class="typeOf(session.type).badge">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                                    v-html="typeOf(session.type).icon" />
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-medium text-gray-400 dark:text-slate-500">{{ formatDate(session.date) }}</span>
                                            <span v-if="isToday(session.date)" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full">Heute</span>
                                        </div>
                                        <p class="font-semibold text-sm mt-0.5" :class="typeOf(session.type).text">{{ session.title }}</p>
                                    </div>
                                    <!-- Type badge -->
                                    <span class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full" :class="typeOf(session.type).badge">
                                        {{ typeOf(session.type).label }}
                                    </span>
                                </div>

                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-1.5 leading-relaxed">{{ session.description }}</p>

                                <!-- Metrics -->
                                <div v-if="session.type !== 'rest'" class="flex flex-wrap gap-3 mt-2">
                                    <span v-if="session.distance_km" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                        {{ session.distance_km }} km
                                    </span>
                                    <span v-if="session.duration_min" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
                                        {{ session.duration_min }} min
                                    </span>
                                    <span v-if="session.pace_target && session.pace_target !== 'null'" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                        {{ session.pace_target }} min/km
                                    </span>
                                    <span v-if="session.zone" class="inline-flex items-center gap-1 text-xs font-medium px-1.5 py-0.5 rounded" :class="typeOf(session.type).badge">
                                        Zone {{ session.zone }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Regenerate hint -->
                <p class="mt-5 text-center text-xs text-gray-400 dark:text-slate-500">
                    Der Plan passt sich an deine aktuellen Daten an. Klicke auf "Plan aktualisieren" um ihn neu zu berechnen.
                </p>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
