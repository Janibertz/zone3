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
        race_week: 'bg-danger',
        taper:     'bg-warn',
        peak:      'bg-warn',
        build:     'bg-info',
        base:      'bg-success',
    }[key] ?? 'bg-ink-3';
}
function priorityColor(p) {
    return { A: 'bg-danger', B: 'bg-warn', C: 'bg-ink-3' }[p] ?? 'bg-ink-3';
}

// Past days with Strava activities → Strava is the ground truth, don't show plan sessions
function visibleSessions(d) {
    if (!d.currentMonth) return [];
    const dayDate  = new Date(calYear.value, calMonth.value, d.day);
    const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const isPast   = dayDate < todayDate;
    // If there's a Strava activity on a past day, hide plan sessions (Strava = completed workout)
    if (isPast && d.activities.length > 0) return [];
    return d.sessions;
}

function selectDay(d) {
    if (!d.currentMonth) return;
    const sessions = visibleSessions(d);
    if (d.activities.length === 0 && !d.event && sessions.length === 0) return;
    selected.value = { day: d.day, activities: d.activities, event: d.event, sessions };
}

const sessionTypeLabels = {
    rest: 'Ruhetag', easy_run: 'Lockerer Lauf', tempo_run: 'Tempolauf',
    interval: 'Intervall', long_run: 'Langer Lauf', race_prep: 'Rennvorbereitung',
    progressive_run: 'Progressiver Lauf', test_run: 'Testlauf',
};
const sessionTypeColors = {
    rest:            'bg-surface-2 text-ink-3',
    easy_run:        'bg-success-soft text-success-ink',
    tempo_run:       'bg-warn-soft text-warn-ink',
    interval:        'bg-danger-soft text-danger-ink',
    long_run:        'bg-info-soft text-info-ink',
    race_prep:       'bg-accent-soft text-accent-ink',
    progressive_run: 'bg-success-soft text-success-ink',
    test_run:        'bg-accent-soft text-accent-ink',
};
const sessionDotColors = {
    rest: 'bg-ink-3', easy_run: 'bg-success', tempo_run: 'bg-warn',
    interval: 'bg-danger', long_run: 'bg-info', race_prep: 'bg-accent',
    progressive_run: 'bg-success', test_run: 'bg-accent',
};
</script>

<template>
    <Head title="Kalender – Zone3" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-surface-2 px-4 py-6 lg:px-8">

            <!-- Header -->
            <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black text-ink">Kalender</h1>
                    <p class="text-sm text-ink-3 mt-0.5">Trainingsübersicht & Events</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="prevMonth"
                        class="h-9 w-9 rounded-field flex items-center justify-center bg-surface text-ink-3 hover:text-ink hover:border-line-strong transition">
                        ‹
                    </button>
                    <button @click="goToday"
                        class="px-4 h-9 rounded-field text-sm font-semibold bg-surface text-ink-2 hover:border-accent transition min-w-[140px] text-center">
                        {{ monthLabel }}
                    </button>
                    <button @click="nextMonth"
                        class="h-9 w-9 rounded-field flex items-center justify-center bg-surface text-ink-3 hover:text-ink hover:border-line-strong transition">
                        ›
                    </button>
                    <button @click="goToday"
                        class="px-4 h-9 rounded-field text-sm font-semibold bg-accent hover:opacity-90 text-white transition">
                        Heute
                    </button>
                </div>
            </div>

            <div class="flex gap-6 items-start">

                <!-- Kalender Grid -->
                <div class="flex-1 bg-surface rounded-card border border-line overflow-hidden shadow-card">
                    <!-- Wochentage Header -->
                    <div class="grid grid-cols-7 border-b border-line">
                        <div v-for="day in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']" :key="day"
                            class="py-3 text-center text-xs font-semibold text-ink-3 uppercase tracking-wider">
                            {{ day }}
                        </div>
                    </div>

                    <!-- Wochen -->
                    <div>
                        <div v-for="(week, wi) in calendarWeeks" :key="wi"
                            class="grid grid-cols-7"
                            :class="wi < calendarWeeks.length - 1 ? 'border-b border-line' : ''">

                            <div v-for="(d, di) in week" :key="di"
                                class="min-h-[90px] sm:min-h-[110px] p-1.5 sm:p-2 border-r border-line last:border-r-0 transition-colors"
                                :class="{
                                    'bg-accent-soft/50': d.isToday,
                                    'opacity-40': !d.currentMonth,
                                    'cursor-pointer hover:bg-surface-2/50': d.currentMonth && (d.activities.length > 0 || d.event || d.sessions.length > 0),
                                }"
                                @click="selectDay(d)">

                                <!-- Tag Zahl -->
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold w-6 h-6 flex items-center justify-center rounded-full"
                                        :class="{
                                            'bg-accent text-white': d.isToday,
                                            'text-ink-3': !d.currentMonth,
                                            'text-ink-2': d.currentMonth && !d.isToday,
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
                                    class="px-1.5 py-1 rounded-lg bg-accent-soft mb-0.5 truncate">
                                    <p class="text-xs font-semibold text-accent-ink truncate">🏃 {{ formatDistance(act.distance) }} km</p>
                                    <p class="text-xs text-accent">{{ formatTime(act.moving_time) }} · {{ formatPace(act.average_speed) }}/km</p>
                                </div>
                                <div v-if="d.activities.length > 2"
                                    class="px-1.5 text-xs text-ink-3">
                                    +{{ d.activities.length - 2 }} weitere
                                </div>
                                <!-- Training sessions (past days with Strava: hidden) -->
                                <div v-for="s in visibleSessions(d).slice(0, 1)" :key="s.id"
                                    class="px-1.5 py-1 rounded-lg mb-0.5 truncate"
                                    :class="s.status === 'skipped' ? 'opacity-40 bg-surface-2' : 'bg-accent-soft'">
                                    <p class="text-xs font-semibold truncate"
                                        :class="s.status === 'skipped' ? 'text-ink-3' : 'text-accent-ink'">
                                        {{ s.status === 'skipped' ? '✗' : '●' }} {{ s.title }}
                                    </p>
                                    <p v-if="s.distance_km && s.status !== 'skipped'" class="text-xs text-accent">{{ s.distance_km }} km</p>
                                </div>
                                <div v-if="visibleSessions(d).length > 1" class="px-1.5 text-xs text-ink-3">
                                    +{{ visibleSessions(d).length - 1 }} weitere
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Detail Panel -->
                <div class="hidden lg:block w-72 flex-shrink-0">
                    <div v-if="!selected" class="bg-surface rounded-card border border-line p-5 text-center shadow-card">
                        <div class="text-3xl mb-3">📅</div>
                        <p class="text-sm font-semibold text-ink-2">Tag auswählen</p>
                        <p class="text-xs text-ink-3 mt-1">Klicke auf einen Tag mit Aktivitäten, Events oder Trainingseinheiten</p>
                    </div>

                    <div v-else class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
                        <!-- Event -->
                        <div v-if="selected.event" class="p-4 border-b border-line">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-xs px-2 py-0.5 rounded-md font-bold text-white" :class="priorityColor(selected.event.priority)">
                                    Priorität {{ selected.event.priority }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-md font-semibold"
                                    :class="{
                                        'bg-danger-soft text-danger': selected.event.training_phase?.key === 'race_week',
                                        'bg-warn-soft text-warn-ink': selected.event.training_phase?.key === 'taper',
                                        'bg-warn-soft text-warn': selected.event.training_phase?.key === 'peak',
                                        'bg-info-soft text-info': selected.event.training_phase?.key === 'build',
                                        'bg-success-soft text-success': selected.event.training_phase?.key === 'base',
                                    }">
                                    {{ selected.event.training_phase?.label }}
                                </span>
                            </div>
                            <h3 class="font-bold text-ink">🏁 {{ selected.event.name }}</h3>
                            <p class="text-sm text-ink-3 mt-1">{{ selected.event.distance_label }}</p>
                            <p v-if="selected.event.target_time_formatted" class="text-sm text-accent-ink font-semibold mt-1">
                                Ziel: {{ selected.event.target_time_formatted }}
                            </p>
                            <p class="text-xs text-ink-3 mt-1">Noch {{ selected.event.days_until }} Tage</p>
                        </div>

                        <!-- Training Sessions -->
                        <div v-if="selected.sessions?.length > 0" class="p-4 border-b border-line">
                            <h5 class="text-xs font-semibold text-ink-3 uppercase tracking-wider mb-3">Trainingsplan</h5>
                            <div v-for="s in selected.sessions" :key="s.id" class="mb-4 last:mb-0">
                                <div class="flex items-start gap-2">
                                    <span class="shrink-0 mt-0.5 h-4 w-4 rounded-full flex items-center justify-center text-xs"
                                        :class="s.status === 'completed' ? 'bg-success' : s.status === 'skipped' ? 'bg-ink-3' : (sessionDotColors[s.type] || 'bg-accent')">
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-ink leading-tight">{{ s.title }}</p>
                                        <p class="text-xs mt-0.5" :class="sessionTypeColors[s.type] || 'text-ink-3'">{{ sessionTypeLabels[s.type] || s.type }}</p>
                                        <div class="flex gap-2 mt-1 flex-wrap">
                                            <span v-if="s.distance_km" class="text-xs text-ink-3">{{ s.distance_km }} km</span>
                                            <span v-if="s.duration_min" class="text-xs text-ink-3">{{ s.duration_min }} min</span>
                                            <span v-if="s.pace_target && s.pace_target !== 'null'" class="text-xs text-ink-3">{{ s.pace_target }}/km</span>
                                        </div>
                                        <span v-if="s.status === 'completed'" class="text-xs text-success-ink font-medium">✓ Erledigt</span>
                                        <span v-else-if="s.status === 'skipped'" class="text-xs text-ink-3">Übersprungen<span v-if="s.skip_reason"> — {{ s.skip_reason }}</span></span>
                                        <!-- Description -->
                                        <p v-if="s.description && s.type !== 'rest'" class="mt-1.5 text-xs text-ink-3 leading-relaxed line-clamp-3">{{ s.description }}</p>
                                        <!-- Rating badge -->
                                        <div v-if="s.rating" class="mt-1 flex items-center gap-1">
                                            <span v-for="i in s.rating" :key="i" class="text-warn text-xs">★</span>
                                        </div>
                                        <!-- Link to plan -->
                                        <a v-if="s.event_id && s.type !== 'rest'"
                                            :href="`/events/${s.event_id}/plan`"
                                            class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink transition-colors"
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
                            <div v-if="selected.activities.length === 0" class="text-sm text-ink-3 text-center py-4">
                                Keine Aktivitäten an diesem Tag
                            </div>
                            <div v-for="act in selected.activities" :key="act.id" class="mb-4 last:mb-0">
                                <h4 class="font-semibold text-ink text-sm mb-2">🏃 {{ act.name }}</h4>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="bg-info-soft rounded-field p-2.5 text-center">
                                        <p class="text-lg font-black text-info-ink">{{ formatDistance(act.distance) }}</p>
                                        <p class="text-xs text-info">km</p>
                                    </div>
                                    <div class="bg-success-soft rounded-field p-2.5 text-center">
                                        <p class="text-lg font-black text-success-ink">{{ formatTime(act.moving_time) }}</p>
                                        <p class="text-xs text-success">Zeit</p>
                                    </div>
                                    <div class="bg-accent-soft rounded-field p-2.5 text-center">
                                        <p class="text-lg font-black text-accent-ink">{{ formatPace(act.average_speed) }}</p>
                                        <p class="text-xs text-accent">min/km</p>
                                    </div>
                                    <div v-if="act.average_heartrate" class="bg-danger-soft rounded-field p-2.5 text-center">
                                        <p class="text-lg font-black text-danger-ink">{{ Math.round(act.average_heartrate) }}</p>
                                        <p class="text-xs text-danger">bpm</p>
                                    </div>
                                    <div v-if="act.total_elevation_gain" class="bg-warn-soft rounded-field p-2.5 text-center">
                                        <p class="text-lg font-black text-warn-ink">{{ Math.round(act.total_elevation_gain) }}</p>
                                        <p class="text-xs text-warn">m Höhe</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Legende -->
                    <div class="mt-4 bg-surface rounded-card border border-line p-4 shadow-card">
                        <h5 class="text-xs font-semibold text-ink-3 uppercase tracking-wider mb-3">Trainingsphasen</h5>
                        <div class="space-y-1.5">
                            <div v-for="phase in [
                                { key: 'base', label: 'Base (>16 Wo.)', color: 'bg-success' },
                                { key: 'build', label: 'Build (12–16 Wo.)', color: 'bg-info' },
                                { key: 'peak', label: 'Peak (8–10 Wo.)', color: 'bg-warn' },
                                { key: 'taper', label: 'Taper (2–4 Wo.)', color: 'bg-warn' },
                                { key: 'race_week', label: 'Race Week (<2 Wo.)', color: 'bg-danger' },
                            ]" :key="phase.key" class="flex items-center gap-2">
                                <div class="h-2.5 w-2.5 rounded-full flex-shrink-0" :class="phase.color"></div>
                                <span class="text-xs text-ink-2">{{ phase.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile: selected day detail -->
            <div v-if="selected" class="mt-4 lg:hidden bg-surface rounded-card border border-line p-4 shadow-card">
                <div v-if="selected.event" class="mb-3 pb-3 border-b border-line">
                    <p class="font-bold text-ink">🏁 {{ selected.event.name }}</p>
                    <p class="text-sm text-ink-3">{{ selected.event.distance_label }} · Noch {{ selected.event.days_until }} Tage</p>
                </div>
                <div v-if="selected.sessions?.length > 0" class="mb-3 pb-3 border-b border-line">
                    <p class="text-xs font-semibold text-ink-3 uppercase tracking-wider mb-2">Trainingsplan</p>
                    <div v-for="s in selected.sessions" :key="s.id" class="mb-2 last:mb-0">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full shrink-0" :class="s.status === 'completed' ? 'bg-success' : s.status === 'skipped' ? 'bg-ink-3' : (sessionDotColors[s.type] || 'bg-accent')"></div>
                            <span class="text-sm font-medium text-ink flex-1">{{ s.title }}</span>
                            <span v-if="s.distance_km" class="text-xs text-ink-3">{{ s.distance_km }} km</span>
                        </div>
                        <p v-if="s.description && s.type !== 'rest'" class="mt-1 ml-5 text-xs text-ink-3 line-clamp-2">{{ s.description }}</p>
                        <div v-if="s.rating" class="mt-1 ml-5 flex items-center gap-0.5">
                            <span v-for="i in s.rating" :key="i" class="text-warn text-xs">★</span>
                        </div>
                        <a v-if="s.event_id && s.type !== 'rest'" :href="`/events/${s.event_id}/plan`"
                            class="mt-1 ml-5 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            Zum Plan
                        </a>
                    </div>
                </div>
                <div v-for="act in selected.activities" :key="act.id" class="mb-4 last:mb-0">
                    <p class="font-semibold text-ink text-sm mb-2">🏃 {{ act.name }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="bg-info-soft rounded-field p-2.5 text-center">
                            <p class="text-lg font-black text-info-ink">{{ formatDistance(act.distance) }}</p>
                            <p class="text-xs text-info">km</p>
                        </div>
                        <div class="bg-success-soft rounded-field p-2.5 text-center">
                            <p class="text-lg font-black text-success-ink">{{ formatTime(act.moving_time) }}</p>
                            <p class="text-xs text-success">Zeit</p>
                        </div>
                        <div class="bg-accent-soft rounded-field p-2.5 text-center">
                            <p class="text-lg font-black text-accent-ink">{{ formatPace(act.average_speed) }}</p>
                            <p class="text-xs text-accent">min/km</p>
                        </div>
                        <div v-if="act.average_heartrate" class="bg-danger-soft rounded-field p-2.5 text-center">
                            <p class="text-lg font-black text-danger-ink">{{ Math.round(act.average_heartrate) }}</p>
                            <p class="text-xs text-danger">bpm</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
