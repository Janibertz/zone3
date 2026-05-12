<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

import axios from 'axios';

const coach = computed(() => usePage().props.coach ?? null);
const coachName = computed(() => coach.value?.name ?? 'Dein Coach');

const coachAccentColors = {
    orange: { stripe: 'bg-orange-400', badge: 'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-500/30', avatar: 'bg-orange-500' },
    blue:   { stripe: 'bg-blue-500',   badge: 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-500/30',             avatar: 'bg-blue-600'   },
    green:  { stripe: 'bg-green-500',  badge: 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300 border-green-200 dark:border-green-500/30',       avatar: 'bg-green-600'  },
};
const coachAccent = computed(() => coachAccentColors[coach.value?.avatar_color] ?? coachAccentColors.blue);

const props = defineProps({
    event:        Object,
    plan:         Object,   // { id, is_active, generated_at, context, actual_time_hours, ... }
    sessions:     Array,    // TrainingSession records from DB
    isPastEvent:  { type: Boolean, default: false },
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

// Adjust state per session id (wellbeing-based)
const adjustingId = ref(null);

// Intensity adjust state: { id: sessionId, direction: 'harder'|'softer' } | null
const adjustingIntensity = ref(null);

// ── Availability overrides ────────────────────────────────────────────────────
const availabilityOverrides = ref({});
const overrideSaving = ref(null); // date string being saved

async function setAvailabilityOverride(date, available, durationMin = 60) {
    overrideSaving.value = date;
    try {
        const res = await axios.patch(route('events.plan.availability', props.event.id), {
            date,
            available,
            duration_min: available ? durationMin : 0,
        });
        availabilityOverrides.value = res.data.overrides ?? {};
    } catch { /* ignore */ }
    finally { overrideSaving.value = null; }
}

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
            tip: `${coachName.value} kann die Einheit an deinen Zustand anpassen.`,
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

async function adjustIntensity(session, direction) {
    adjustingIntensity.value = { id: session.id, direction };
    errorMsg.value = '';
    try {
        const res = await axios.post(route('training-sessions.adjust-intensity', session.id), { direction });
        updateSessionInList(res.data.session);
    } catch (e) {
        errorMsg.value = e?.response?.data?.error ?? 'Anpassung fehlgeschlagen.';
    } finally {
        adjustingIntensity.value = null;
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

// Past events: show all sessions as history; future: only today + forward
const visibleSessions = computed(() =>
    props.isPastEvent
        ? currentSessions.value
        : currentSessions.value.filter(s => s.planned_date >= today)
);

// ── Race result state ─────────────────────────────────────────────────────────
const resultHours   = ref(props.plan?.actual_time_hours   ?? null);
const resultMinutes = ref(props.plan?.actual_time_minutes ?? null);
const resultRating  = ref(props.plan?.overall_rating      ?? 0);
const resultNotes   = ref(props.plan?.result_notes        ?? '');
const resultSaving  = ref(false);
const resultSaved   = ref(false);

// Compare target vs actual (both in total minutes)
const goalAchieved = computed(() => {
    if (resultHours.value === null && resultMinutes.value === null) return null;
    const targetMin = (props.event.target_time_hours ?? 0) * 60 + (props.event.target_time_minutes ?? 0);
    const actualMin = (resultHours.value ?? 0) * 60 + (resultMinutes.value ?? 0);
    if (!targetMin || !actualMin) return null;
    return actualMin <= targetMin;
});

const goalDeltaText = computed(() => {
    if (goalAchieved.value === null) return null;
    const targetMin = (props.event.target_time_hours ?? 0) * 60 + (props.event.target_time_minutes ?? 0);
    const actualMin = (resultHours.value ?? 0) * 60 + (resultMinutes.value ?? 0);
    const delta = Math.abs(actualMin - targetMin);
    const h = Math.floor(delta / 60), m = delta % 60;
    const fmt = h > 0 ? `${h}:${String(m).padStart(2,'0')} Std` : `${m} Min`;
    return goalAchieved.value ? `${fmt} schneller als Ziel` : `${fmt} langsamer als Ziel`;
});

async function saveResult() {
    resultSaving.value = true;
    resultSaved.value  = false;
    try {
        await axios.patch(route('events.plan.result', props.event.id), {
            actual_time_hours:   resultHours.value,
            actual_time_minutes: resultMinutes.value,
            overall_rating:      resultRating.value || null,
            result_notes:        resultNotes.value  || null,
        });
        resultSaved.value = true;
        setTimeout(() => resultSaved.value = false, 3000);
    } finally {
        resultSaving.value = false;
    }
}

const weeklyLoad = computed(() => {
    const all  = currentSessions.value; // stats over full plan
    const runs = all.filter(s => s.type !== 'rest' && s.status !== 'skipped');
    const total = runs.reduce((s, x) => s + (x.distance_km || 0), 0);
    const done    = all.filter(s => s.status === 'completed').length;
    const skipped = all.filter(s => s.status === 'skipped').length;
    return { total: Math.round(total * 10) / 10, runs: runs.length, done, skipped };
});

const skipReasons = ['Keine Zeit', 'Krank', 'Verletzt', 'Erschöpft', 'Sonstiges'];

// ── Session detail modal ──────────────────────────────────────────────────────
const detailSession      = ref(null);
const aiNutritionTips    = ref(null);
const nutritionLoading   = ref(false);
const nutritionError     = ref('');
const nutritionCache     = {};

// ── Rating ───────────────────────────────────────────────────────────────────
const ratingValue  = ref(0);
const effortValue  = ref(0);
const feelingNotes = ref('');
const ratingSaving = ref(false);
const ratingSaved  = ref(false);

async function saveRating() {
    if (!detailSession.value) return;
    ratingSaving.value = true;
    ratingSaved.value  = false;
    try {
        const { data } = await axios.patch(route('training-sessions.rate', detailSession.value.id), {
            rating:           ratingValue.value  || null,
            effort_perceived: effortValue.value  || null,
            feeling_notes:    feelingNotes.value || null,
        });
        const idx = sessionList.value.findIndex(s => s.id === data.session.id);
        if (idx !== -1) sessionList.value[idx] = data.session;
        detailSession.value = data.session;
        ratingSaved.value = true;
        setTimeout(() => ratingSaved.value = false, 2500);
    } finally {
        ratingSaving.value = false;
    }
}

const isRaceSession = computed(() => {
    const s = detailSession.value;
    if (!s) return false;
    return s.type === 'race' || s.planned_date === props.event.event_date;
});

async function openDetail(session) {
    if (session.type === 'rest') return;
    detailSession.value   = session;
    aiNutritionTips.value = null;
    nutritionError.value  = '';
    ratingValue.value     = session.rating          || 0;
    effortValue.value     = session.effort_perceived || 0;
    feelingNotes.value    = session.feeling_notes    || '';
    ratingSaved.value     = false;

    // Serve from cache if already loaded
    if (nutritionCache[session.id]) {
        aiNutritionTips.value = nutritionCache[session.id];
        return;
    }

    nutritionLoading.value = true;
    try {
        const { data } = await axios.get(route('training-sessions.nutrition-tips', session.id));
        nutritionCache[session.id] = data;
        aiNutritionTips.value = data;
    } catch {
        nutritionError.value = 'Ernährungstipps konnten nicht geladen werden.';
    } finally {
        nutritionLoading.value = false;
    }
}

// Compute Aufwärmen / Hauptteil / Auslaufen from session data
function parsePaceToSec(paceStr) {
    if (!paceStr || paceStr === 'null') return null;
    const parts = paceStr.split(':');
    if (parts.length !== 2) return null;
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
}

function secToPaceStr(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.round(sec % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}

function estimatedTime(meters, paceStr) {
    const sec = parsePaceToSec(paceStr);
    if (!sec || !meters) return null;
    const totalSec = Math.round((meters / 1000) * sec);
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;
    return `~${m}:${String(s).padStart(2, '0')} min`;
}

const workoutSteps = computed(() => {
    const s = detailSession.value;
    if (!s || s.type === 'rest' || isRaceSession.value) return [];

    const distKm = s.distance_km || 5;
    const paceSecPerKm = parsePaceToSec(s.pace_target);
    const easySecPerKm = paceSecPerKm ? paceSecPerKm + 60 : null;
    const easyPaceStr  = easySecPerKm ? secToPaceStr(easySecPerKm) : null;

    const configs = {
        easy_run:  { wF: 0.10, wMax: 1.0, cF: 0.10, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Leichtes Einlaufen',              mName: 'Hauptteil',  mLabel: 'Lockeres Dauertempo',                     cName: 'Auslaufen', cLabel: 'Leichtes Auslaufen' },
        tempo_run: { wF: 0.25, wMax: 2.0, cF: 0.12, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Lockeres Einlaufen',              mName: 'Hauptteil',  mLabel: 'Tempodauerlauf',                          cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen' },
        interval:  { wF: 0.20, wMax: 2.0, cF: 0.10, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Lockeres Einlaufen',              mName: 'Intervalle', mLabel: 'Intervallarbeit (Details in Beschreibung)',cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen' },
        long_run:  { wF: 0.05, wMax: 1.0, cF: 0.05, cMax: 1.0, wName: 'Einlaufen',  wLabel: 'Leichtes Einlaufen',              mName: 'Hauptteil',  mLabel: 'Langer gleichmäßiger Lauf',               cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen' },
        race_prep: { wF: 0.30, wMax: 2.0, cF: 0.15, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Lockeres Einlaufen + Strides',    mName: 'Hauptteil',  mLabel: 'Renntempo-Abschnitte',                    cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen' },
    };

    const cfg = configs[s.type] || configs['easy_run'];
    const warmupKm   = Math.min(cfg.wMax, distKm * cfg.wF);
    const cooldownKm = Math.min(cfg.cMax, distKm * cfg.cF);
    const mainKm     = Math.max(0, distKm - warmupKm - cooldownKm);

    return [
        { phase: cfg.wName, label: cfg.wLabel, km: Math.round(warmupKm * 10) / 10,   pace: easyPaceStr, color: 'green'  },
        { phase: cfg.mName, label: cfg.mLabel, km: Math.round(mainKm * 10) / 10,     pace: s.pace_target && s.pace_target !== 'null' ? s.pace_target : easyPaceStr, color: 'main' },
        { phase: cfg.cName, label: cfg.cLabel, km: Math.round(cooldownKm * 10) / 10, pace: easyPaceStr, color: 'blue'   },
    ];
});

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
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden mb-5">
                <!-- Coach-Akzentstreifen -->
                <div v-if="coach" class="h-1 w-full" :class="coachAccent.stripe"></div>
                <div class="p-4 sm:p-5">
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
                <!-- Coach-Badge -->
                <div v-if="coach && currentPlan?.is_active" class="mx-4 mb-4 sm:mx-5">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-lg border" :class="coachAccent.badge">
                        <span class="h-4 w-4 rounded flex items-center justify-center text-white text-[9px] font-bold" :class="coachAccent.avatar">{{ coach.avatar_initials }}</span>
                        Plan von {{ coachName }}
                    </span>
                </div>
                </div>
            </div>

            <!-- ── Race Result Form (past events only) ──────────────────────── -->
            <div v-if="isPastEvent && plan" class="mb-5 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-100 dark:border-indigo-500/20 shadow-sm p-4 sm:p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="h-8 w-8 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 flex items-center justify-center shrink-0 text-base">🏅</div>
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">Rennergebnis eintragen</h3>
                        <p class="text-xs text-gray-400 dark:text-slate-500">Dein Ergebnis hilft {{ coachName }}, den nächsten Plan gezielter zu gestalten</p>
                    </div>
                </div>

                <!-- Target vs Actual -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <!-- Ziel -->
                    <div class="rounded-xl bg-gray-50 dark:bg-slate-800/60 border border-gray-100 dark:border-slate-700 p-3">
                        <p class="text-[10px] font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Zielzeit</p>
                        <p class="text-xl font-bold text-gray-700 dark:text-slate-200 tabular-nums">{{ event.target_time_formatted || '—' }}</p>
                    </div>
                    <!-- Ergebnis -->
                    <div class="rounded-xl border p-3 transition-colors"
                        :class="goalAchieved === true  ? 'bg-green-50 dark:bg-green-500/10 border-green-100 dark:border-green-500/20'
                              : goalAchieved === false ? 'bg-red-50 dark:bg-red-500/10 border-red-100 dark:border-red-500/20'
                              : 'bg-gray-50 dark:bg-slate-800/60 border-gray-100 dark:border-slate-700'"
                    >
                        <p class="text-[10px] font-semibold uppercase tracking-wider mb-1"
                            :class="goalAchieved === true ? 'text-green-500' : goalAchieved === false ? 'text-red-400' : 'text-gray-400 dark:text-slate-500'">
                            Dein Ergebnis
                        </p>
                        <div class="flex items-center gap-1">
                            <input v-model.number="resultHours" type="number" min="0" max="23" placeholder="0"
                                class="w-10 text-xl font-bold bg-transparent border-none outline-none tabular-nums p-0 text-gray-800 dark:text-white placeholder-gray-300"
                            />
                            <span class="text-lg font-bold text-gray-400">:</span>
                            <input v-model.number="resultMinutes" type="number" min="0" max="59" placeholder="00"
                                class="w-10 text-xl font-bold bg-transparent border-none outline-none tabular-nums p-0 text-gray-800 dark:text-white placeholder-gray-300"
                            />
                            <span class="text-xs text-gray-400 dark:text-slate-500 ml-1">Std:Min</span>
                        </div>
                    </div>
                </div>

                <!-- Goal achieved banner -->
                <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95">
                    <div v-if="goalAchieved !== null" class="mb-4 rounded-xl px-3 py-2.5 flex items-center gap-2"
                        :class="goalAchieved ? 'bg-green-100 dark:bg-green-500/15' : 'bg-red-50 dark:bg-red-500/10'"
                    >
                        <span class="text-lg">{{ goalAchieved ? '🎯' : '📉' }}</span>
                        <div>
                            <p class="text-sm font-semibold" :class="goalAchieved ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
                                {{ goalAchieved ? 'Ziel erreicht!' : 'Ziel knapp verfehlt' }}
                            </p>
                            <p class="text-xs" :class="goalAchieved ? 'text-green-600/70 dark:text-green-500' : 'text-red-500/70 dark:text-red-500'">
                                {{ goalDeltaText }}
                            </p>
                        </div>
                    </div>
                </Transition>

                <!-- Plan rating -->
                <div class="mb-3">
                    <p class="text-xs font-semibold text-gray-600 dark:text-slate-400 mb-2">Wie gut hat der Plan funktioniert?</p>
                    <div class="flex gap-2">
                        <button v-for="n in 5" :key="n" @click="resultRating = n"
                            class="h-9 w-9 rounded-xl flex items-center justify-center text-lg transition-all"
                            :class="n <= resultRating ? 'bg-amber-100 dark:bg-amber-500/20 scale-110' : 'bg-gray-100 dark:bg-slate-800 opacity-40 hover:opacity-70'"
                        >⭐</button>
                        <button v-if="resultRating" @click="resultRating = 0" class="ml-2 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-slate-300">zurücksetzen</button>
                    </div>
                </div>

                <!-- Notes -->
                <textarea v-model="resultNotes" rows="2" placeholder="Was hat gut geklappt? Was sollte beim nächsten Plan anders sein?"
                    class="w-full rounded-xl px-3 py-2.5 text-sm border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 text-gray-800 dark:text-slate-200 placeholder-gray-400 dark:placeholder-slate-500 resize-none focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:focus:ring-indigo-500/50 mb-3"
                />

                <!-- Save -->
                <button @click="saveResult" :disabled="resultSaving"
                    class="w-full flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold transition-colors disabled:opacity-60"
                    :class="resultSaved ? 'bg-green-500 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'"
                >
                    <svg v-if="resultSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    <svg v-else-if="resultSaved" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    {{ resultSaved ? 'Gespeichert!' : resultSaving ? 'Speichern…' : 'Ergebnis speichern' }}
                </button>
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
                            Anpassen
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
            <div v-if="currentPlan?.needs_plan_update && !isPastEvent"
                class="mb-4 flex items-start gap-3 rounded-2xl bg-orange-50 dark:bg-orange-500/10 border border-orange-200 dark:border-orange-500/30 px-4 py-3">
                <span class="text-xl leading-none mt-0.5">🔗</span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-orange-700 dark:text-orange-400">Neue Strava-Aktivität importiert</p>
                    <p class="text-xs text-orange-600/70 dark:text-orange-400/70 mt-0.5">Der verbleibende Plan sollte neu berechnet werden, damit {{ coachName }} deinen aktuellen Trainingsstand berücksichtigt.</p>
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
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">{{ isPastEvent ? 'Trainings-Auswertung' : '10-Tages-Trainingsplan' }}</h2>
                    <p v-if="currentPlan" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                        Erstellt am {{ currentPlan.generated_at }}
                        <span v-if="currentPlan.context"> · {{ currentPlan.context.activities_used }} Aktivitäten</span>
                    </p>
                </div>
                <div class="flex gap-2">
                    <!-- Cancel plan (only when active and event not past) -->
                    <button v-if="currentPlan?.is_active && !isPastEvent"
                        @click="cancelModal = true"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        Abbrechen
                    </button>

                    <!-- Generate/update (always shown when event not past) -->
                    <button v-if="!isPastEvent"
                        @click="generatePlan" :disabled="generating"
                        class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors shadow-sm disabled:opacity-60"
                        :class="currentPlan?.is_active ? 'bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700' : 'bg-indigo-600 text-white hover:bg-indigo-700'"
                    >
                        <svg v-if="generating" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        {{ generating ? coachName + ' analysiert...' : currentPlan?.is_active ? 'Plan aktualisieren' : 'Neuen Plan erstellen' }}
                    </button>
                </div>
            </div>

            <!-- Error -->
            <div v-if="errorMsg" class="mb-4 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-sm text-red-700 dark:text-red-400">{{ errorMsg }}</div>

            <!-- Generating -->
            <div v-if="generating" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-10 text-center mb-4">
                <svg class="h-10 w-10 animate-spin mx-auto text-indigo-500 mb-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">{{ coachName }} analysiert deine Daten...</p>
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Aktivitäten, Wellbeing und Athletenprofil werden ausgewertet</p>
            </div>

            <!-- Empty -->
            <div v-else-if="!currentPlan || visibleSessions.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 p-10 text-center">
                <svg class="h-12 w-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                <template v-if="isPastEvent">
                    <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Kein Trainingsplan vorhanden</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-slate-500 max-w-xs mx-auto">Für dieses Event wurde kein Plan erstellt.</p>
                </template>
                <template v-else>
                    <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Noch kein Trainingsplan</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-slate-500 max-w-xs mx-auto">{{ coachName }} analysiert Aktivitäten, Wellbeing und Athletenprofil für einen optimalen 10-Tages-Plan.</p>
                    <button @click="generatePlan" :disabled="generating" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        Plan erstellen
                    </button>
                </template>
            </div>

            <!-- Sessions list -->
            <template v-else>
                <!-- Summary -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                    <div v-for="(val, lbl) in { 'km geplant': weeklyLoad.total, 'Einheiten': weeklyLoad.runs, 'Erledigt': weeklyLoad.done, 'Übersprungen': weeklyLoad.skipped }" :key="lbl"
                        class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 px-3 py-2.5 text-center">
                        <div class="text-xl font-bold text-gray-900 dark:text-white">{{ val }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ lbl }}</div>
                    </div>
                </div>

                <div class="space-y-2">
                    <div v-for="session in visibleSessions" :key="session.id"
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

                                <!-- Action buttons -->
                                <div class="flex gap-2 mt-3 flex-wrap">
                                    <!-- Details (all non-rest sessions) -->
                                    <button v-if="session.type !== 'rest'"
                                        @click="openDetail(session)"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" /></svg>
                                        Details
                                    </button>

                                    <template v-if="session.status === 'planned'">
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
                                        <!-- Intensity: softer -->
                                        <button
                                            @click="adjustIntensity(session, 'softer')"
                                            :disabled="adjustingIntensity !== null"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-400 hover:bg-sky-100 dark:hover:bg-sky-500/20 disabled:opacity-50 transition-colors"
                                            title="Einheit lockerer machen"
                                        >
                                            <svg v-if="adjustingIntensity?.id === session.id && adjustingIntensity?.direction === 'softer'" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                            <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" /></svg>
                                            Lockerer
                                        </button>
                                        <!-- Intensity: harder -->
                                        <button
                                            @click="adjustIntensity(session, 'harder')"
                                            :disabled="adjustingIntensity !== null"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-400 hover:bg-orange-100 dark:hover:bg-orange-500/20 disabled:opacity-50 transition-colors"
                                            title="Einheit schwerer machen"
                                        >
                                            <svg v-if="adjustingIntensity?.id === session.id && adjustingIntensity?.direction === 'harder'" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                            <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" /></svg>
                                            Schwerer
                                        </button>
                                        <!-- Availability override: mark day as unavailable -->
                                        <button
                                            v-if="!isPast(session.planned_date) && session.type !== 'rest'"
                                            @click="setAvailabilityOverride(session.planned_date, false)"
                                            :disabled="overrideSaving === session.planned_date"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-500/20 disabled:opacity-50 transition-colors"
                                            title="Tag als nicht verfügbar markieren — beim nächsten Plan-Update berücksichtigt"
                                        >
                                            <svg v-if="overrideSaving === session.planned_date" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                            <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                            Kein Training
                                        </button>
                                    </template>
                                    <!-- Show override badge if set -->
                                    <span
                                        v-if="availabilityOverrides[session.planned_date] && !availabilityOverrides[session.planned_date].available"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400"
                                    >
                                        Nicht verfügbar — Plan neu generieren
                                        <button @click="delete availabilityOverrides[session.planned_date]; setAvailabilityOverride(session.planned_date, true, session.duration_min || 60)" class="ml-1 hover:text-amber-900">✕</button>
                                    </span>
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

        <!-- ── Session Detail Modal ───────────────────────────────────────── -->
        <Modal :show="!!detailSession" @close="detailSession = null">
            <div v-if="detailSession" class="bg-white dark:bg-slate-900 max-h-[90vh] overflow-y-auto">

                <!-- Header -->
                <div class="flex items-start justify-between px-5 pt-5 pb-4 border-b border-gray-100 dark:border-slate-800">
                    <div class="flex-1 min-w-0 pr-3">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full" :class="typeOf(detailSession.type).badge">
                            {{ typeOf(detailSession.type).label }}
                        </span>
                        <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-white leading-snug">{{ detailSession.title }}</h2>
                        <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">{{ formatDate(detailSession.planned_date) }}</p>
                    </div>
                    <button @click="detailSession = null" class="shrink-0 h-8 w-8 flex items-center justify-center rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                    </button>
                </div>

                <div class="px-5 py-4 space-y-4">

                    <!-- Description -->
                    <p v-if="detailSession.description" class="text-sm text-gray-600 dark:text-slate-400 leading-relaxed">{{ detailSession.description }}</p>

                    <!-- Overall metrics -->
                    <div class="grid grid-cols-3 gap-2">
                        <div v-if="detailSession.distance_km" class="bg-gray-50 dark:bg-slate-800 rounded-xl p-3 text-center">
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ detailSession.distance_km }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">km</p>
                        </div>
                        <div v-if="detailSession.duration_min" class="bg-gray-50 dark:bg-slate-800 rounded-xl p-3 text-center">
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ detailSession.duration_min }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">min</p>
                        </div>
                        <div v-if="detailSession.pace_target && detailSession.pace_target !== 'null'" class="bg-gray-50 dark:bg-slate-800 rounded-xl p-3 text-center">
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ detailSession.pace_target }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">min/km</p>
                        </div>
                    </div>

                    <!-- Warmup / Hauptteil / Cooldown -->
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500 mb-2">Trainingsstruktur</h3>
                        <div class="space-y-2">
                            <div v-for="(step, idx) in workoutSteps" :key="idx"
                                class="flex items-start gap-3 rounded-xl p-3.5"
                                :class="{
                                    'bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20': step.color === 'green',
                                    'bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20': step.color === 'main',
                                    'bg-blue-50 dark:bg-blue-500/10 border border-blue-100 dark:border-blue-500/20': step.color === 'blue',
                                }"
                            >
                                <!-- Phase number -->
                                <div class="shrink-0 h-7 w-7 rounded-lg flex items-center justify-center text-xs font-bold"
                                    :class="{
                                        'bg-green-200 dark:bg-green-500/30 text-green-800 dark:text-green-300': step.color === 'green',
                                        'bg-indigo-200 dark:bg-indigo-500/30 text-indigo-800 dark:text-indigo-300': step.color === 'main',
                                        'bg-blue-200 dark:bg-blue-500/30 text-blue-800 dark:text-blue-300': step.color === 'blue',
                                    }">
                                    {{ idx + 1 }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ step.phase }}</p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ step.label }}</p>
                                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300">{{ step.km }} km</span>
                                        <span v-if="step.pace && step.pace !== 'null'" class="text-xs text-gray-500 dark:text-slate-400">
                                            {{ step.pace }} min/km
                                        </span>
                                        <span v-if="step.pace && step.km" class="text-xs text-gray-400 dark:text-slate-500">
                                            {{ estimatedTime(step.km * 1000, step.pace) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Verpflegungsplan -->
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500 mb-2">Verpflegungsplan</h3>

                        <!-- Loading -->
                        <div v-if="nutritionLoading" class="flex items-center gap-3 py-4 text-sm text-gray-500 dark:text-slate-400">
                            <svg class="h-4 w-4 animate-spin shrink-0 text-indigo-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            {{ coachName }} erstellt Verpflegungstipps…
                        </div>

                        <!-- Error -->
                        <p v-else-if="nutritionError" class="text-xs text-red-500 dark:text-red-400">{{ nutritionError }}</p>

                        <!-- Tips -->
                        <div v-else-if="aiNutritionTips" class="space-y-2">

                            <div class="rounded-xl border border-amber-100 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 overflow-hidden">
                                <div class="flex items-center gap-2 px-3.5 py-2 border-b border-amber-100 dark:border-amber-500/20">
                                    <span class="text-sm">🕐</span>
                                    <span class="text-xs font-bold text-amber-800 dark:text-amber-300 uppercase tracking-wide">{{ isRaceSession ? 'Vor dem Rennen' : 'Vor dem Training' }}</span>
                                </div>
                                <ul class="px-3.5 py-2.5 space-y-1.5">
                                    <li v-for="tip in aiNutritionTips.before" :key="tip.text" class="flex items-start gap-2 text-xs text-amber-900 dark:text-amber-200">
                                        <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                        <span class="leading-relaxed">{{ tip.text }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div class="rounded-xl border border-blue-100 dark:border-blue-500/20 bg-blue-50 dark:bg-blue-500/10 overflow-hidden">
                                <div class="flex items-center gap-2 px-3.5 py-2 border-b border-blue-100 dark:border-blue-500/20">
                                    <span class="text-sm">🏃</span>
                                    <span class="text-xs font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wide">{{ isRaceSession ? 'Während des Rennens' : 'Während des Trainings' }}</span>
                                </div>
                                <ul class="px-3.5 py-2.5 space-y-1.5">
                                    <li v-for="tip in aiNutritionTips.during" :key="tip.text" class="flex items-start gap-2 text-xs text-blue-900 dark:text-blue-200">
                                        <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                        <span class="leading-relaxed">{{ tip.text }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div class="rounded-xl border border-green-100 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 overflow-hidden">
                                <div class="flex items-center gap-2 px-3.5 py-2 border-b border-green-100 dark:border-green-500/20">
                                    <span class="text-sm">✅</span>
                                    <span class="text-xs font-bold text-green-800 dark:text-green-300 uppercase tracking-wide">{{ isRaceSession ? 'Nach dem Rennen' : 'Nach dem Training' }}</span>
                                </div>
                                <ul class="px-3.5 py-2.5 space-y-1.5">
                                    <li v-for="tip in aiNutritionTips.after" :key="tip.text" class="flex items-start gap-2 text-xs text-green-900 dark:text-green-200">
                                        <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                        <span class="leading-relaxed">{{ tip.text }}</span>
                                    </li>
                                </ul>
                            </div>

                        </div>
                    </div>

                    <!-- Zone info -->
                    <div v-if="detailSession.zone" class="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full" :class="typeOf(detailSession.type).badge">Zone {{ detailSession.zone }}</span>
                        <span v-if="detailSession.intensity" class="text-xs text-gray-400 dark:text-slate-500 capitalize">· {{ detailSession.intensity }}</span>
                    </div>
                </div>

                <!-- Rating (nur für abgeschlossene Sessions) -->
                <div v-if="detailSession.status === 'completed'" class="px-5 pb-4 border-t border-gray-100 dark:border-slate-800 pt-4 space-y-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500">Einheit bewerten</h3>

                    <!-- Sterne -->
                    <div>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Wie gut ist die Einheit gelaufen?</p>
                        <div class="flex gap-1.5">
                            <button v-for="n in 5" :key="n" @click="ratingValue = ratingValue === n ? 0 : n"
                                class="h-9 w-9 rounded-xl flex items-center justify-center text-xl transition-all"
                                :class="n <= ratingValue ? 'bg-amber-100 dark:bg-amber-500/20 scale-110' : 'bg-gray-100 dark:bg-slate-800 opacity-40 hover:opacity-70'">
                                ⭐
                            </button>
                        </div>
                    </div>

                    <!-- RPE -->
                    <div>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Empfundene Anstrengung (RPE 1–10): <span class="font-semibold text-gray-700 dark:text-slate-300">{{ effortValue || '–' }}</span></p>
                        <input type="range" min="0" max="10" step="1" v-model.number="effortValue"
                            class="w-full h-2 rounded-full accent-indigo-600 cursor-pointer" />
                        <div class="flex justify-between text-xs text-gray-400 dark:text-slate-500 mt-1 px-0.5">
                            <span>Sehr leicht</span><span>Maximal</span>
                        </div>
                    </div>

                    <!-- Notiz -->
                    <div>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Notiz (optional)</p>
                        <textarea v-model="feelingNotes" rows="2" maxlength="300" placeholder="Wie hat sich die Einheit angefühlt?"
                            class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none">
                        </textarea>
                    </div>

                    <button @click="saveRating" :disabled="ratingSaving || (!ratingValue && !effortValue && !feelingNotes)"
                        class="w-full rounded-xl py-2.5 text-sm font-semibold transition-colors disabled:opacity-50"
                        :class="ratingSaved ? 'bg-green-500 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'">
                        <svg v-if="ratingSaving" class="inline h-4 w-4 animate-spin mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        {{ ratingSaved ? '✓ Gespeichert' : ratingSaving ? 'Speichern…' : 'Bewertung speichern' }}
                    </button>
                </div>

                <!-- Download footer -->
                <div class="px-5 pb-5 border-t border-gray-100 dark:border-slate-800 pt-4">
                    <p class="text-xs text-gray-400 dark:text-slate-500 mb-3">Workout herunterladen für Garmin Connect</p>
                    <div class="flex items-center gap-2">
                        <a :href="route('training-sessions.download', detailSession.id)"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 transition-colors"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            .fit
                        </a>
                        <a :href="route('training-sessions.download-tcx', detailSession.id)"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 text-sm font-semibold px-4 py-2.5 transition-colors"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            .tcx (Fallback)
                        </a>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Cancel plan modal -->
        <Modal :show="cancelModal" @close="cancelModal = false">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Plan wirklich abbrechen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                    Der Plan wird deaktiviert. Deine bereits absolvierten Einheiten bleiben erhalten.
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
