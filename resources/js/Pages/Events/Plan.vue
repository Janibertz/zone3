<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    event:    Object,
    plan:     Object,   // { id, is_active, generated_at, context }
    sessions: Array,    // TrainingSession records from DB
});

// ── State ─────────────────────────────────────────────────────────────────────
const currentPlan     = ref(props.plan);
const currentSessions = ref(props.sessions ?? []);
const generating      = ref(false);
const errorMsg        = ref('');
const todayWellbeing  = ref(null);
const wellbeingLoaded = ref(false);

// Cancel plan modal
const cancelModal   = ref(false);
const cancelLoading = ref(false);

async function cancelPlan() {
    cancelLoading.value = true;
    try {
        await axios.post(route('events.plan.cancel', props.event.id));
        currentPlan.value = { ...currentPlan.value, is_active: false };
        cancelModal.value = false;
    } catch {
        errorMsg.value = 'Fehler beim Abbrechen des Plans.';
    } finally {
        cancelLoading.value = false;
    }
}

// Skip modal
const skipModal   = ref(false);
const skipSession = ref(null);
const skipReason  = ref('');
const skipLoading = ref(false);

// Adjust state per session id
const adjustingId = ref(null);

const today = new Date().toISOString().slice(0, 10);

// ── Load today's wellbeing ────────────────────────────────────────────────────
onMounted(async () => {
    try {
        const res = await axios.get(route('wellbeing.today'));
        todayWellbeing.value = res.data;
    } catch { /* no wellbeing today — that's fine */ }
    finally { wellbeingLoaded.value = true; }
});

// ── Wellbeing banner for today's session ─────────────────────────────────────
const todaySession = computed(() =>
    currentSessions.value.find(s => s.planned_date === today && s.status === 'planned')
);

const wellbeingBanner = computed(() => {
    const w = todayWellbeing.value;
    if (!w || !todaySession.value) return null;

    if (w.is_sick || w.is_injured) {
        return {
            level: 'danger',
            icon: '🤕',
            text: w.is_sick ? 'Du bist krank. Kein Training heute!' : 'Du bist verletzt. Kein Training heute!',
            tip: 'Überspringen und erholen ist jetzt das Wichtigste.',
            canAdjust: false,
        };
    }
    const energy   = w.energy_level ?? 10;
    const soreness = w.muscle_soreness ?? 0;
    const stress   = w.stress_level ?? 0;

    if (energy <= 3 || soreness >= 8) {
        return {
            level: 'warning',
            icon: '😴',
            text: energy <= 3 ? 'Sehr wenig Energie heute.' : 'Starker Muskelkater.',
            tip: 'KI kann die Einheit an deinen Zustand anpassen.',
            canAdjust: true,
        };
    }
    if (stress >= 8) {
        return {
            level: 'warning',
            icon: '😤',
            text: 'Hoher Stress heute.',
            tip: 'Eine kürzere, ruhigere Einheit könnte helfen.',
            canAdjust: true,
        };
    }
    if (energy >= 8 && soreness <= 2) {
        return {
            level: 'good',
            icon: '🚀',
            text: 'Top-Zustand! Perfekt für heute.',
            tip: null,
            canAdjust: false,
        };
    }
    return null;
});

// ── Generate plan ─────────────────────────────────────────────────────────────
async function generatePlan() {
    generating.value = true;
    errorMsg.value   = '';
    try {
        const res = await axios.post(route('events.plan.generate', props.event.id));
        currentPlan.value     = res.data.plan;
        currentSessions.value = res.data.sessions;
    } catch (e) {
        errorMsg.value = e?.response?.data?.error ?? 'Fehler beim Erstellen des Plans.';
    } finally {
        generating.value = false;
    }
}

// ── Complete session ──────────────────────────────────────────────────────────
async function completeSession(session) {
    try {
        const res = await axios.patch(route('training-sessions.complete', session.id));
        updateSessionInList(res.data.session);
    } catch (e) {
        errorMsg.value = 'Fehler beim Speichern.';
    }
}

// ── Skip session ──────────────────────────────────────────────────────────────
function openSkipModal(session) {
    skipSession.value = session;
    skipReason.value  = '';
    skipModal.value   = true;
}

async function confirmSkip() {
    if (!skipSession.value) return;
    skipLoading.value = true;
    try {
        const res = await axios.patch(route('training-sessions.skip', skipSession.value.id), {
            reason: skipReason.value,
        });
        updateSessionInList(res.data.session);
        skipModal.value = false;
    } catch {
        errorMsg.value = 'Fehler beim Überspringen.';
    } finally {
        skipLoading.value = false;
    }
}

// ── AI adjust session ─────────────────────────────────────────────────────────
async function adjustSession(session) {
    adjustingId.value = session.id;
    errorMsg.value    = '';
    try {
        const res = await axios.post(route('training-sessions.adjust', session.id));
        updateSessionInList(res.data.session);
    } catch (e) {
        errorMsg.value = e?.response?.data?.error ?? 'Anpassung fehlgeschlagen.';
    } finally {
        adjustingId.value = null;
    }
}

function updateSessionInList(updated) {
    const idx = currentSessions.value.findIndex(s => s.id === updated.id);
    if (idx !== -1) currentSessions.value[idx] = updated;
}

// ── Type config ───────────────────────────────────────────────────────────────
const typeConfig = {
    rest:       { label: 'Ruhetag',          bg: 'bg-gray-50 dark:bg-slate-800',      text: 'text-gray-500 dark:text-slate-400',  badge: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300', border: 'border-gray-100 dark:border-slate-700', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />` },
    easy_run:   { label: 'Lockerer Lauf',    bg: 'bg-green-50 dark:bg-green-500/10',  text: 'text-green-700 dark:text-green-400', badge: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400', border: 'border-green-100 dark:border-green-500/20', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.493 4.493 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />` },
    tempo_run:  { label: 'Tempolauf',        bg: 'bg-amber-50 dark:bg-amber-500/10',  text: 'text-amber-700 dark:text-amber-400', badge: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400', border: 'border-amber-100 dark:border-amber-500/20', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />` },
    interval:   { label: 'Intervall',        bg: 'bg-red-50 dark:bg-red-500/10',      text: 'text-red-700 dark:text-red-400',     badge: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400', border: 'border-red-100 dark:border-red-500/20', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5" />` },
    long_run:   { label: 'Langer Lauf',      bg: 'bg-blue-50 dark:bg-blue-500/10',    text: 'text-blue-700 dark:text-blue-400',   badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400', border: 'border-blue-100 dark:border-blue-500/20', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />` },
    race_prep:  { label: 'Rennvorbereitung', bg: 'bg-indigo-50 dark:bg-indigo-500/10', text: 'text-indigo-700 dark:text-indigo-400', badge: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400', border: 'border-indigo-100 dark:border-indigo-500/20', icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />` },
};
const typeOf = (t) => typeConfig[t] ?? typeConfig['easy_run'];

const priorityColors = { A: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10', B: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10', C: 'text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-700' };

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'short' });
}
const isToday  = (d) => d === today;
const isPast   = (d) => d < today;

const weeklyLoad = computed(() => {
    const runs  = currentSessions.value.filter(s => s.type !== 'rest' && s.status !== 'skipped');
    const total = runs.reduce((s, x) => s + (x.distance_km || 0), 0);
    const done  = currentSessions.value.filter(s => s.status === 'completed').length;
    const skipped = currentSessions.value.filter(s => s.status === 'skipped').length;
    return { total: Math.round(total * 10) / 10, runs: runs.length, done, skipped };
});

const skipReasons = ['Keine Zeit', 'Krank', 'Verletzt', 'Erschöpft', 'Sonstiges'];
</script>

<template>
    <Head :title="`Plan – ${event.name}`" />
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 py-4 sm:py-8">

            <!-- Back -->
            <Link :href="route('events.index')" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-white transition-colors mb-4">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Zurück zu Events
            </Link>

            <!-- Event header -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm p-4 sm:p-5 mb-5">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 h-10 w-10 rounded-xl flex items-center justify-center font-bold text-lg" :class="priorityColors[event.priority]">{{ event.priority }}</span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-lg font-bold text-gray-900 dark:text-white">{{ event.name }}</h1>
                            <span v-if="currentPlan?.is_active" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-700 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full">
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                Aktiver Plan
                            </span>
                        </div>
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

            <!-- Wellbeing banner for today's session -->
            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" leave-to-class="opacity-0">
                <div v-if="wellbeingBanner && todaySession"
                    class="mb-4 rounded-2xl border px-4 py-3 flex items-start gap-3"
                    :class="{
                        'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30': wellbeingBanner.level === 'danger',
                        'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/30': wellbeingBanner.level === 'warning',
                        'bg-green-50 dark:bg-green-500/10 border-green-200 dark:border-green-500/30': wellbeingBanner.level === 'good',
                    }"
                >
                    <span class="text-xl leading-none mt-0.5">{{ wellbeingBanner.icon }}</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold"
                            :class="{ 'text-red-700 dark:text-red-400': wellbeingBanner.level === 'danger', 'text-amber-700 dark:text-amber-400': wellbeingBanner.level === 'warning', 'text-green-700 dark:text-green-400': wellbeingBanner.level === 'good' }">
                            {{ wellbeingBanner.text }}
                        </p>
                        <p v-if="wellbeingBanner.tip" class="text-xs mt-0.5 text-gray-500 dark:text-slate-400">{{ wellbeingBanner.tip }}</p>
                    </div>
                    <div v-if="wellbeingBanner.canAdjust" class="flex gap-2 shrink-0">
                        <button @click="adjustSession(todaySession)" :disabled="adjustingId === todaySession.id"
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50 transition-colors"
                        >
                            <svg v-if="adjustingId === todaySession.id" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            KI anpassen
                        </button>
                    </div>
                    <div v-else-if="wellbeingBanner.level === 'danger'" class="shrink-0">
                        <button @click="openSkipModal(todaySession)"
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-red-600 text-white hover:bg-red-700 transition-colors"
                        >
                            Überspringen
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Strava update banner -->
            <div v-if="currentPlan?.needs_plan_update"
                class="mb-4 flex items-start gap-3 rounded-2xl bg-orange-50 dark:bg-orange-500/10 border border-orange-200 dark:border-orange-500/30 px-4 py-3">
                <span class="text-xl leading-none mt-0.5">🔗</span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-orange-700 dark:text-orange-400">Neue Strava-Aktivität importiert</p>
                    <p class="text-xs text-orange-600/70 dark:text-orange-400/70 mt-0.5">Der verbleibende Plan sollte neu berechnet werden, damit die KI deinen aktuellen Trainingsstand berücksichtigt.</p>
                </div>
                <button @click="generatePlan" :disabled="generating"
                    class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-xs font-semibold px-3 py-2 transition-colors disabled:opacity-50">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                    Plan aktualisieren
                </button>
            </div>

            <!-- Plan header -->
            <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">10-Tages-Trainingsplan</h2>
                    <p v-if="currentPlan" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                        Erstellt am {{ currentPlan.generated_at }}
                        <span v-if="currentPlan.context"> · {{ currentPlan.context.activities_used }} Aktivitäten</span>
                    </p>
                </div>
                <div class="flex gap-2">
                    <!-- Cancel plan (only when active) -->
                    <button v-if="currentPlan?.is_active"
                        @click="cancelModal = true"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        Abbrechen
                    </button>

                    <!-- Generate/update (only when plan is active or no plan yet) -->
                    <button v-if="!currentPlan || currentPlan.is_active"
                        @click="generatePlan" :disabled="generating"
                        class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors shadow-sm disabled:opacity-60"
                        :class="currentPlan ? 'bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700' : 'bg-indigo-600 text-white hover:bg-indigo-700'"
                    >
                        <svg v-if="generating" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        {{ generating ? 'KI analysiert...' : currentPlan ? 'Plan aktualisieren' : 'KI-Plan erstellen' }}
                    </button>

                    <!-- Cancelled state info -->
                    <span v-if="currentPlan && !currentPlan.is_active"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-400 dark:text-slate-500 bg-gray-100 dark:bg-slate-800">
                        Plan abgebrochen
                    </span>
                </div>
            </div>

            <!-- Error -->
            <div v-if="errorMsg" class="mb-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-sm text-red-700 dark:text-red-400">{{ errorMsg }}</div>

            <!-- Generating -->
            <div v-if="generating" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-10 text-center mb-4">
                <svg class="h-10 w-10 animate-spin mx-auto text-indigo-500 mb-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">KI analysiert deine Daten...</p>
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Aktivitäten, Wellbeing und Athletenprofil werden ausgewertet</p>
            </div>

            <!-- Empty -->
            <div v-else-if="!currentPlan || currentSessions.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 p-10 text-center">
                <svg class="h-12 w-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Noch kein Trainingsplan</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500 max-w-xs mx-auto">Die KI analysiert Aktivitäten, Wellbeing und Athletenprofil für einen optimalen 10-Tages-Plan.</p>
                <button @click="generatePlan" :disabled="generating" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                    KI-Plan erstellen
                </button>
            </div>

            <!-- Sessions list -->
            <template v-else>
                <!-- Summary -->
                <div class="grid grid-cols-4 gap-2 mb-4">
                    <div v-for="(val, lbl) in { 'km geplant': weeklyLoad.total, 'Einheiten': weeklyLoad.runs, 'Erledigt': weeklyLoad.done, 'Übersprungen': weeklyLoad.skipped }" :key="lbl"
                        class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 px-3 py-2.5 text-center">
                        <div class="text-xl font-bold text-gray-900 dark:text-white">{{ val }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ lbl }}</div>
                    </div>
                </div>

                <div class="space-y-2">
                    <div v-for="session in currentSessions" :key="session.id"
                        class="rounded-2xl border overflow-hidden transition-all"
                        :class="[
                            typeOf(session.type).border,
                            isToday(session.planned_date) && session.status === 'planned' ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-950' : '',
                            session.status === 'completed' ? 'opacity-60' : '',
                            session.status === 'skipped' ? 'opacity-40' : '',
                        ]"
                    >
                        <!-- Unplanned banner -->
                        <div v-if="session.title === 'Ungeplante Einheit'" class="px-3 pt-2.5 pb-0 flex items-center gap-1.5 text-xs text-orange-600 dark:text-orange-400 font-medium">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            Nicht im Plan – automatisch aus Strava importiert
                        </div>
                        <div class="flex gap-3 p-3 sm:p-4" :class="typeOf(session.type).bg">
                            <!-- Icon -->
                            <div class="shrink-0 h-10 w-10 rounded-xl flex items-center justify-center" :class="typeOf(session.type).badge">
                                <svg v-if="session.status === 'completed'" class="h-5 w-5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                <svg v-else-if="session.status === 'skipped'" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M6.225 6.225A9 9 0 0 0 21 12a9 9 0 0 1-15.098 4.672M3.161 7.573A9 9 0 0 0 3 12a9 9 0 0 0 9 9" /></svg>
                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="typeOf(session.type).icon" />
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-medium text-gray-400 dark:text-slate-500">{{ formatDate(session.planned_date) }}</span>
                                            <span v-if="isToday(session.planned_date) && session.status === 'planned'" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full">Heute</span>
                                        </div>
                                        <p class="font-semibold text-sm mt-0.5" :class="typeOf(session.type).text">{{ session.title }}</p>
                                    </div>
                                    <!-- Status / type badges -->
                                    <div class="flex gap-1.5 shrink-0 flex-wrap justify-end">
                                        <span v-if="session.status === 'completed'" class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400">✓ Erledigt</span>
                                        <span v-else-if="session.status === 'skipped'" class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400">Übersprungen</span>
                                        <span v-else class="text-xs font-medium px-2 py-0.5 rounded-full" :class="typeOf(session.type).badge">{{ typeOf(session.type).label }}</span>
                                        <span v-if="session.activity_id" class="text-xs font-medium px-2 py-0.5 rounded-full bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-400">🔗 Strava</span>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-1.5 leading-relaxed">{{ session.description }}</p>

                                <!-- Skip reason -->
                                <p v-if="session.skip_reason" class="text-xs text-gray-400 dark:text-slate-500 mt-1 italic">Grund: {{ session.skip_reason }}</p>

                                <!-- Metrics -->
                                <div v-if="session.type !== 'rest' && session.status !== 'skipped'" class="flex flex-wrap gap-3 mt-2">
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
                                    <span v-if="session.zone" class="inline-flex items-center gap-1 text-xs font-medium px-1.5 py-0.5 rounded" :class="typeOf(session.type).badge">Zone {{ session.zone }}</span>
                                </div>

                                <!-- Action buttons (only for planned sessions) -->
                                <div v-if="session.status === 'planned'" class="flex gap-2 mt-3 flex-wrap">
                                    <button @click="completeSession(session)"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-green-600 text-white hover:bg-green-700 transition-colors"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Abgeschlossen
                                    </button>
                                    <button @click="openSkipModal(session)"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M6.225 6.225A9 9 0 0 0 21 12a9 9 0 0 1-15.098 4.672" /></svg>
                                        Überspringen
                                    </button>
                                    <button v-if="!isPast(session.planned_date) || isToday(session.planned_date)"
                                        @click="adjustSession(session)"
                                        :disabled="adjustingId === session.id"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 disabled:opacity-50 transition-colors"
                                    >
                                        <svg v-if="adjustingId === session.id" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                        <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                        KI anpassen
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="mt-5 text-center text-xs text-gray-400 dark:text-slate-500">
                    "Plan aktualisieren" berücksichtigt übersprungene Einheiten und aktualisiert verbleibende Sessions.
                </p>
            </template>
        </div>

        <!-- Skip modal -->
        <Modal :show="skipModal" @close="skipModal = false">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Einheit überspringen</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">{{ skipSession?.title }}</p>
                <div class="mt-4">
                    <p class="text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Grund (optional)</p>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <button v-for="r in skipReasons" :key="r" @click="skipReason = r"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium border transition-colors"
                            :class="skipReason === r ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 hover:border-gray-300 dark:hover:border-slate-600'"
                        >{{ r }}</button>
                    </div>
                    <input v-model="skipReason" type="text" placeholder="Oder eigenen Grund eingeben..."
                        class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                    />
                </div>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="skipModal = false" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">Abbrechen</button>
                    <button @click="confirmSkip" :disabled="skipLoading" class="rounded-xl bg-gray-700 dark:bg-slate-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 dark:hover:bg-slate-500 disabled:opacity-50 transition-colors">
                        <svg v-if="skipLoading" class="inline h-4 w-4 animate-spin mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        Überspringen
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Cancel plan modal -->
        <Modal :show="cancelModal" @close="cancelModal = false">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Plan wirklich abbrechen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                    Der KI-Plan wird deaktiviert. Deine bereits absolvierten Einheiten bleiben erhalten.
                    Du kannst danach für jedes Event einen neuen Plan erstellen.
                </p>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="cancelModal = false"
                        class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                        Nicht abbrechen
                    </button>
                    <button @click="cancelPlan" :disabled="cancelLoading"
                        class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 transition-colors">
                        <svg v-if="cancelLoading" class="inline h-4 w-4 animate-spin mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        Ja, Plan abbrechen
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
