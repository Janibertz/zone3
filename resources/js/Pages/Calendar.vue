<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    activities:       { type: Array, default: () => [] },
    events:           { type: Array, default: () => [] },
    trainingSessions: { type: Array, default: () => [] },
});

// --- State ---
const today      = new Date();
const calYear    = ref(today.getFullYear());
const calMonth   = ref(today.getMonth());
const selected   = ref(null); // { type: 'activity'|'event', data }

// --- Navigation ---
function prevMonth() {
    if (calMonth.value === 0) { calMonth.value = 11; calYear.value--; }
    else calMonth.value--;
    selected.value = null;
}
function nextMonth() {
    if (calMonth.value === 11) { calMonth.value = 0; calYear.value++; }
    else calMonth.value++;
    selected.value = null;
}
function goToday() {
    calYear.value  = today.getFullYear();
    calMonth.value = today.getMonth();
    selected.value = null;
}

const monthLabel = computed(() =>
    new Date(calYear.value, calMonth.value, 1)
        .toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })
);

// --- Data maps for current month ---
const activityMap = computed(() => {
    const map = new Map();
    props.activities.forEach(a => {
        const d = new Date(a.start_date);
        if (d.getFullYear() === calYear.value && d.getMonth() === calMonth.value) {
            const day = d.getDate();
            if (!map.has(day)) map.set(day, []);
            map.get(day).push(a);
        }
    });
    return map;
});

const eventMap = computed(() => {
    const map = new Map();
    props.events.forEach(e => {
        const d = new Date(e.event_date);
        if (d.getFullYear() === calYear.value && d.getMonth() === calMonth.value) {
            map.set(d.getDate(), e);
        }
    });
    return map;
});

const sessionMap = computed(() => {
    const map = new Map();
    props.trainingSessions.forEach(s => {
        const d = new Date(s.planned_date + 'T00:00:00');
        if (d.getFullYear() === calYear.value && d.getMonth() === calMonth.value) {
            const day = d.getDate();
            if (!map.has(day)) map.set(day, []);
            map.get(day).push(s);
        }
    });
    return map;
});

// --- Calendar grid (Monday-first) ---
const calendarWeeks = computed(() => {
    const firstDow      = new Date(calYear.value, calMonth.value, 1).getDay();
    const leadingBlanks = (firstDow + 6) % 7;
    const daysInMonth   = new Date(calYear.value, calMonth.value + 1, 0).getDate();
    const daysInPrev    = new Date(calYear.value, calMonth.value, 0).getDate();
    const isCurrentMon  = calYear.value === today.getFullYear() && calMonth.value === today.getMonth();

    const days = [];
    for (let i = leadingBlanks - 1; i >= 0; i--) {
        days.push({ day: daysInPrev - i, currentMonth: false, isToday: false, activities: [], event: null, sessions: [] });
    }
    for (let i = 1; i <= daysInMonth; i++) {
        days.push({
            day: i,
            currentMonth: true,
            isToday: isCurrentMon && i === today.getDate(),
            activities: activityMap.value.get(i) ?? [],
            event:      eventMap.value.get(i) ?? null,
            sessions:   sessionMap.value.get(i) ?? [],
        });
    }
    const total     = days.length;
    const remaining = total % 7 === 0 ? 0 : 7 - (total % 7);
    for (let i = 1; i <= remaining; i++) {
        days.push({ day: i, currentMonth: false, isToday: false, activities: [], event: null, sessions: [] });
    }

    // Split into weeks
    const weeks = [];
    for (let i = 0; i < days.length; i += 7) weeks.push(days.slice(i, i + 7));
    return weeks;
});

// --- Helpers ---
function formatDistance(m) {
    return m ? (m / 1000).toFixed(1) : '0';
}
function formatTime(s) {
    if (!s) return '—';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
function formatPace(mps) {
    if (!mps || mps <= 0) return '—';
    const spk = 1000 / mps;
    return `${Math.floor(spk / 60)}:${String(Math.round(spk % 60)).padStart(2, '0')}`;
}
function phaseColor(key) {
    return {
        race_week: 'bg-red-500',
        taper:     'bg-yellow-500',
        peak:      'bg-orange-500',
        build:     'bg-blue-500',
        base:      'bg-green-500',
    }[key] ?? 'bg-gray-400';
}
function priorityColor(p) {
    return { A: 'bg-red-500', B: 'bg-yellow-500', C: 'bg-gray-400' }[p] ?? 'bg-gray-400';
}

function selectDay(d) {
    if (!d.currentMonth) return;
    if (d.activities.length === 0 && !d.event && d.sessions.length === 0) return;
    selected.value = { day: d.day, activities: d.activities, event: d.event, sessions: d.sessions };
}

const sessionTypeLabels = {
    rest: 'Ruhetag', easy_run: 'Lockerer Lauf', tempo_run: 'Tempolauf',
    interval: 'Intervall', long_run: 'Langer Lauf', race_prep: 'Rennvorbereitung',
};
const sessionTypeColors = {
    rest:      'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
    easy_run:  'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
    tempo_run: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
    interval:  'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
    long_run:  'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400',
    race_prep: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400',
};
const sessionDotColors = {
    rest: 'bg-gray-400', easy_run: 'bg-green-500', tempo_run: 'bg-amber-500',
    interval: 'bg-red-500', long_run: 'bg-blue-500', race_prep: 'bg-indigo-500',
};
</script>

<template>
    <Head title="Kalender – Zone3" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-gray-50 dark:bg-slate-950 px-4 py-6 lg:px-8">

            <!-- Header -->
            <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black text-gray-900 dark:text-white">Kalender</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">Trainingsübersicht & Events</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="prevMonth"
                        class="h-9 w-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-500 hover:text-gray-800 dark:hover:text-white hover:border-gray-300 transition">
                        ‹
                    </button>
                    <button @click="goToday"
                        class="px-4 h-9 rounded-xl text-sm font-semibold bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:border-indigo-300 transition min-w-[140px] text-center">
                        {{ monthLabel }}
                    </button>
                    <button @click="nextMonth"
                        class="h-9 w-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-500 hover:text-gray-800 dark:hover:text-white hover:border-gray-300 transition">
                        ›
                    </button>
                    <button @click="goToday"
                        class="px-4 h-9 rounded-xl text-sm font-semibold bg-indigo-500 hover:bg-indigo-400 text-white transition">
                        Heute
                    </button>
                </div>
            </div>

            <div class="flex gap-6 items-start">

                <!-- Kalender Grid -->
                <div class="flex-1 bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 overflow-hidden shadow-sm">
                    <!-- Wochentage Header -->
                    <div class="grid grid-cols-7 border-b border-gray-100 dark:border-slate-800">
                        <div v-for="day in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']" :key="day"
                            class="py-3 text-center text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider">
                            {{ day }}
                        </div>
                    </div>

                    <!-- Wochen -->
                    <div>
                        <div v-for="(week, wi) in calendarWeeks" :key="wi"
                            class="grid grid-cols-7"
                            :class="wi < calendarWeeks.length - 1 ? 'border-b border-gray-100 dark:border-slate-800' : ''">

                            <div v-for="(d, di) in week" :key="di"
                                class="min-h-[90px] sm:min-h-[110px] p-1.5 sm:p-2 border-r border-gray-100 dark:border-slate-800 last:border-r-0 transition-colors"
                                :class="{
                                    'bg-indigo-50/50 dark:bg-indigo-500/5': d.isToday,
                                    'opacity-40': !d.currentMonth,
                                    'cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800/50': d.currentMonth && (d.activities.length > 0 || d.event || d.sessions.length > 0),
                                }"
                                @click="selectDay(d)">

                                <!-- Tag Zahl -->
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold w-6 h-6 flex items-center justify-center rounded-full"
                                        :class="{
                                            'bg-indigo-600 text-white': d.isToday,
                                            'text-gray-400 dark:text-slate-500': !d.currentMonth,
                                            'text-gray-700 dark:text-slate-300': d.currentMonth && !d.isToday,
                                        }">
                                        {{ d.day }}
                                    </span>
                                    <!-- Event Marker -->
                                    <span v-if="d.event"
                                        class="text-xs px-1.5 py-0.5 rounded-md font-bold text-white leading-none"
                                        :class="priorityColor(d.event.priority)">
                                        {{ d.event.priority }}
                                    </span>
                                </div>

                                <!-- Event Label -->
                                <div v-if="d.event"
                                    class="mb-1 px-1.5 py-1 rounded-lg text-xs font-semibold text-white truncate"
                                    :class="phaseColor(d.event.training_phase?.key)">
                                    🏁 {{ d.event.name }}
                                </div>

                                <!-- Aktivitäten -->
                                <div v-for="act in d.activities.slice(0, 2)" :key="act.id"
                                    class="px-1.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 mb-0.5 truncate">
                                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300 truncate">🏃 {{ formatDistance(act.distance) }} km</p>
                                    <p class="text-xs text-indigo-500 dark:text-indigo-400">{{ formatTime(act.moving_time) }} · {{ formatPace(act.average_speed) }}/km</p>
                                </div>
                                <div v-if="d.activities.length > 2"
                                    class="px-1.5 text-xs text-gray-400 dark:text-slate-500">
                                    +{{ d.activities.length - 2 }} weitere
                                </div>
                                <!-- Training sessions -->
                                <div v-for="s in d.sessions.slice(0, 1)" :key="s.id"
                                    class="px-1.5 py-1 rounded-lg mb-0.5 truncate"
                                    :class="s.status === 'skipped' ? 'opacity-40 bg-gray-100 dark:bg-slate-700' : s.status === 'completed' ? 'bg-green-50 dark:bg-green-500/10' : 'bg-indigo-50 dark:bg-indigo-500/10'">
                                    <p class="text-xs font-semibold truncate"
                                        :class="s.status === 'completed' ? 'text-green-700 dark:text-green-400' : s.status === 'skipped' ? 'text-gray-500 dark:text-slate-400' : 'text-indigo-700 dark:text-indigo-300'">
                                        {{ s.status === 'completed' ? '✓' : s.status === 'skipped' ? '✗' : '●' }} {{ s.title }}
                                    </p>
                                    <p v-if="s.distance_km && s.status !== 'skipped'" class="text-xs text-indigo-500 dark:text-indigo-400">{{ s.distance_km }} km</p>
                                </div>
                                <div v-if="d.sessions.length > 1" class="px-1.5 text-xs text-gray-400 dark:text-slate-500">
                                    +{{ d.sessions.length - 1 }} weitere
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Detail Panel -->
                <div class="hidden lg:block w-72 flex-shrink-0">
                    <div v-if="!selected" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-5 text-center shadow-sm">
                        <div class="text-3xl mb-3">📅</div>
                        <p class="text-sm font-semibold text-gray-700 dark:text-slate-200">Tag auswählen</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Klicke auf einen Tag mit Aktivitäten, Events oder Trainingseinheiten</p>
                    </div>

                    <div v-else class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">
                        <!-- Event -->
                        <div v-if="selected.event" class="p-4 border-b border-gray-100 dark:border-slate-800">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-xs px-2 py-0.5 rounded-md font-bold text-white" :class="priorityColor(selected.event.priority)">
                                    Priorität {{ selected.event.priority }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-md font-semibold"
                                    :class="{
                                        'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400': selected.event.training_phase?.key === 'race_week',
                                        'bg-yellow-50 text-yellow-600 dark:bg-yellow-500/10 dark:text-yellow-400': selected.event.training_phase?.key === 'taper',
                                        'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400': selected.event.training_phase?.key === 'peak',
                                        'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400': selected.event.training_phase?.key === 'build',
                                        'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400': selected.event.training_phase?.key === 'base',
                                    }">
                                    {{ selected.event.training_phase?.label }}
                                </span>
                            </div>
                            <h3 class="font-bold text-gray-900 dark:text-white">🏁 {{ selected.event.name }}</h3>
                            <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">{{ selected.event.distance_label }}</p>
                            <p v-if="selected.event.target_time_formatted" class="text-sm text-indigo-600 dark:text-indigo-400 font-semibold mt-1">
                                Ziel: {{ selected.event.target_time_formatted }}
                            </p>
                            <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Noch {{ selected.event.days_until }} Tage</p>
                        </div>

                        <!-- Training Sessions -->
                        <div v-if="selected.sessions?.length > 0" class="p-4 border-b border-gray-100 dark:border-slate-800">
                            <h5 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">Trainingsplan</h5>
                            <div v-for="s in selected.sessions" :key="s.id" class="mb-4 last:mb-0">
                                <div class="flex items-start gap-2">
                                    <span class="shrink-0 mt-0.5 h-4 w-4 rounded-full flex items-center justify-center text-xs"
                                        :class="s.status === 'completed' ? 'bg-green-500' : s.status === 'skipped' ? 'bg-gray-400' : (sessionDotColors[s.type] || 'bg-indigo-500')">
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white leading-tight">{{ s.title }}</p>
                                        <p class="text-xs mt-0.5" :class="sessionTypeColors[s.type] || 'text-gray-500'">{{ sessionTypeLabels[s.type] || s.type }}</p>
                                        <div class="flex gap-2 mt-1 flex-wrap">
                                            <span v-if="s.distance_km" class="text-xs text-gray-500 dark:text-slate-400">{{ s.distance_km }} km</span>
                                            <span v-if="s.duration_min" class="text-xs text-gray-500 dark:text-slate-400">{{ s.duration_min }} min</span>
                                            <span v-if="s.pace_target && s.pace_target !== 'null'" class="text-xs text-gray-500 dark:text-slate-400">{{ s.pace_target }}/km</span>
                                        </div>
                                        <span v-if="s.status === 'completed'" class="text-xs text-green-600 dark:text-green-400 font-medium">✓ Erledigt</span>
                                        <span v-else-if="s.status === 'skipped'" class="text-xs text-gray-400">Übersprungen<span v-if="s.skip_reason"> — {{ s.skip_reason }}</span></span>
                                        <!-- Description -->
                                        <p v-if="s.description && s.type !== 'rest'" class="mt-1.5 text-xs text-gray-500 dark:text-slate-400 leading-relaxed line-clamp-3">{{ s.description }}</p>
                                        <!-- Rating badge -->
                                        <div v-if="s.rating" class="mt-1 flex items-center gap-1">
                                            <span v-for="i in s.rating" :key="i" class="text-yellow-400 text-xs">★</span>
                                        </div>
                                        <!-- Link to plan -->
                                        <a v-if="s.event_id && s.type !== 'rest'"
                                            :href="`/events/${s.event_id}/plan`"
                                            class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                            Zum Plan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Aktivitäten -->
                        <div class="p-4">
                            <div v-if="selected.activities.length === 0" class="text-sm text-gray-400 dark:text-slate-500 text-center py-4">
                                Keine Aktivitäten an diesem Tag
                            </div>
                            <div v-for="act in selected.activities" :key="act.id" class="mb-4 last:mb-0">
                                <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-2">🏃 {{ act.name }}</h4>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="bg-blue-50 dark:bg-blue-500/10 rounded-xl p-2.5 text-center">
                                        <p class="text-lg font-black text-blue-700 dark:text-blue-300">{{ formatDistance(act.distance) }}</p>
                                        <p class="text-xs text-blue-500 dark:text-blue-400">km</p>
                                    </div>
                                    <div class="bg-green-50 dark:bg-green-500/10 rounded-xl p-2.5 text-center">
                                        <p class="text-lg font-black text-green-700 dark:text-green-300">{{ formatTime(act.moving_time) }}</p>
                                        <p class="text-xs text-green-500 dark:text-green-400">Zeit</p>
                                    </div>
                                    <div class="bg-purple-50 dark:bg-purple-500/10 rounded-xl p-2.5 text-center">
                                        <p class="text-lg font-black text-purple-700 dark:text-purple-300">{{ formatPace(act.average_speed) }}</p>
                                        <p class="text-xs text-purple-500 dark:text-purple-400">min/km</p>
                                    </div>
                                    <div v-if="act.average_heartrate" class="bg-red-50 dark:bg-red-500/10 rounded-xl p-2.5 text-center">
                                        <p class="text-lg font-black text-red-700 dark:text-red-300">{{ Math.round(act.average_heartrate) }}</p>
                                        <p class="text-xs text-red-500 dark:text-red-400">bpm</p>
                                    </div>
                                    <div v-if="act.total_elevation_gain" class="bg-orange-50 dark:bg-orange-500/10 rounded-xl p-2.5 text-center">
                                        <p class="text-lg font-black text-orange-700 dark:text-orange-300">{{ Math.round(act.total_elevation_gain) }}</p>
                                        <p class="text-xs text-orange-500 dark:text-orange-400">m Höhe</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Legende -->
                    <div class="mt-4 bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 shadow-sm">
                        <h5 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">Trainingsphasen</h5>
                        <div class="space-y-1.5">
                            <div v-for="phase in [
                                { key: 'base', label: 'Base (>16 Wo.)', color: 'bg-green-500' },
                                { key: 'build', label: 'Build (12–16 Wo.)', color: 'bg-blue-500' },
                                { key: 'peak', label: 'Peak (8–10 Wo.)', color: 'bg-orange-500' },
                                { key: 'taper', label: 'Taper (2–4 Wo.)', color: 'bg-yellow-500' },
                                { key: 'race_week', label: 'Race Week (<2 Wo.)', color: 'bg-red-500' },
                            ]" :key="phase.key" class="flex items-center gap-2">
                                <div class="h-2.5 w-2.5 rounded-full flex-shrink-0" :class="phase.color"></div>
                                <span class="text-xs text-gray-600 dark:text-slate-400">{{ phase.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile: selected day detail -->
            <div v-if="selected" class="mt-4 lg:hidden bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 shadow-sm">
                <div v-if="selected.event" class="mb-3 pb-3 border-b border-gray-100 dark:border-slate-800">
                    <p class="font-bold text-gray-900 dark:text-white">🏁 {{ selected.event.name }}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400">{{ selected.event.distance_label }} · Noch {{ selected.event.days_until }} Tage</p>
                </div>
                <div v-if="selected.sessions?.length > 0" class="mb-3 pb-3 border-b border-gray-100 dark:border-slate-800">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Trainingsplan</p>
                    <div v-for="s in selected.sessions" :key="s.id" class="mb-2 last:mb-0">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full shrink-0" :class="s.status === 'completed' ? 'bg-green-500' : s.status === 'skipped' ? 'bg-gray-400' : (sessionDotColors[s.type] || 'bg-indigo-500')"></div>
                            <span class="text-sm font-medium text-gray-800 dark:text-slate-200 flex-1">{{ s.title }}</span>
                            <span v-if="s.distance_km" class="text-xs text-gray-500">{{ s.distance_km }} km</span>
                        </div>
                        <p v-if="s.description && s.type !== 'rest'" class="mt-1 ml-5 text-xs text-gray-500 dark:text-slate-400 line-clamp-2">{{ s.description }}</p>
                        <div v-if="s.rating" class="mt-1 ml-5 flex items-center gap-0.5">
                            <span v-for="i in s.rating" :key="i" class="text-yellow-400 text-xs">★</span>
                        </div>
                        <a v-if="s.event_id && s.type !== 'rest'" :href="`/events/${s.event_id}/plan`"
                            class="mt-1 ml-5 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            Zum Plan
                        </a>
                    </div>
                </div>
                <div v-for="act in selected.activities" :key="act.id" class="mb-4 last:mb-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm mb-2">🏃 {{ act.name }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="bg-blue-50 dark:bg-blue-500/10 rounded-xl p-2.5 text-center">
                            <p class="text-lg font-black text-blue-700 dark:text-blue-300">{{ formatDistance(act.distance) }}</p>
                            <p class="text-xs text-blue-500 dark:text-blue-400">km</p>
                        </div>
                        <div class="bg-green-50 dark:bg-green-500/10 rounded-xl p-2.5 text-center">
                            <p class="text-lg font-black text-green-700 dark:text-green-300">{{ formatTime(act.moving_time) }}</p>
                            <p class="text-xs text-green-500 dark:text-green-400">Zeit</p>
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-500/10 rounded-xl p-2.5 text-center">
                            <p class="text-lg font-black text-purple-700 dark:text-purple-300">{{ formatPace(act.average_speed) }}</p>
                            <p class="text-xs text-purple-500 dark:text-purple-400">min/km</p>
                        </div>
                        <div v-if="act.average_heartrate" class="bg-red-50 dark:bg-red-500/10 rounded-xl p-2.5 text-center">
                            <p class="text-lg font-black text-red-700 dark:text-red-300">{{ Math.round(act.average_heartrate) }}</p>
                            <p class="text-xs text-red-500 dark:text-red-400">bpm</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
