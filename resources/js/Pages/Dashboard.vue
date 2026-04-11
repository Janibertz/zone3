<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WellbeingModal from '@/Components/WellbeingModal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Inertia } from '@inertiajs/inertia';
import { router } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import { useDarkMode } from '@/Composables/useDarkMode';

const { isDark } = useDarkMode();

const props = defineProps({
    stravaConnected: Boolean,
    stravaAccount: Object,
    events: { type: Array, default: () => [] },
    recentActivities: {
        type: Array,
        default: () => [],
    },
    suggestions: {
        type: Array,
        default: () => [],
    },
    thresholdPace: {
        type: String,
        default: null,
    },
    thresholdPaceCalculatedAt: {
        type: String,
        default: null,
    },
    paceZones: {
        type: Object,
        default: null,
    },
    thresholdPaceHistory: {
        type: Array,
        default: () => [],
    },
    racePredictions: {
        type: Object,
        default: null,
    },
    thresholdPaceCalculating: {
        type: Boolean,
        default: false,
    },
    syncResult: {
        type: String,
        default: null,
    },
    todayPlanSession: {
        type: Object,
        default: null,
    },
    hasActivePlan: {
        type: Boolean,
        default: false,
    },
    hasWellbeingToday: {
        type: Boolean,
        default: false,
    },
    unratedSessions: {
        type: Array,
        default: () => [],
    },
    weeklyReview: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const flash = page.props?.flash || {};
const errors = page.props?.errors || {};

const errorMessages = computed(() => {
    if (!errors) return [];
    if (typeof errors === 'string') return [errors];
    if (Array.isArray(errors)) return errors;
    if (typeof errors === 'object') {
        return Object.values(errors).flatMap((value) => {
            if (Array.isArray(value)) return value;
            return [String(value)];
        });
    }
    return [];
});

const fieldErrors = computed(() => {
    if (!errors || typeof errors !== 'object') return {};
    return errors;
});

const showFlash = ref(true);

const resetFlash = () => {
    showFlash.value = true;
    setTimeout(() => {
        showFlash.value = false;
    }, 4500);
};

watch(
    () => page.props.flash,
    () => {
        if (flash.success || flash.error) {
            resetFlash();
        }
    },
    { immediate: true }
);

function fieldError(field) {
    const value = fieldErrors.value[field];
    if (Array.isArray(value)) return value[0];
    return value || '';
}

const raceOptions = [
    { value: '5km',           label: '5 km',          hours: 0, minutes: 25 },
    { value: '10km',          label: '10 km',         hours: 0, minutes: 50 },
    { value: 'half_marathon', label: 'Halbmarathon',  hours: 1, minutes: 45 },
    { value: 'marathon',      label: 'Marathon',      hours: 3, minutes: 30 },
];

const quickEventForm = ref({
    race_distance:       'half_marathon',
    event_date:          '',
    target_time_hours:   1,
    target_time_minutes: 45,
    priority:            'A',
    name:                '',
});
const quickEventSaving  = ref(false);
const quickEventSuccess = ref(false);

watch([() => quickEventForm.value.race_distance, () => quickEventForm.value.event_date], () => {
    const opt = raceOptions.find(r => r.value === quickEventForm.value.race_distance);
    if (opt) {
        quickEventForm.value.target_time_hours   = opt.hours;
        quickEventForm.value.target_time_minutes = opt.minutes;
    }
    const dateLabel = quickEventForm.value.event_date
        ? new Date(quickEventForm.value.event_date).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })
        : '';
    quickEventForm.value.name = opt && dateLabel ? `${opt.label} ${dateLabel}` : (opt?.label ?? '');
});

async function saveQuickEvent() {
    quickEventSaving.value = true;
    try {
        await axios.post(route('events.store'), quickEventForm.value);
        quickEventSuccess.value = true;
        router.reload({ only: ['events'] });
        setTimeout(() => { quickEventSuccess.value = false; }, 3000);
        quickEventForm.value.event_date = '';
        quickEventForm.value.name = '';
    } finally {
        quickEventSaving.value = false;
    }
}

const plan = ref(null);
const planError = ref(null);
const aiAnalysis = ref(null);
const aiPlan = ref(null);
const aiLoading = ref(false);
const showAIModal = ref(false);
const aiModalType = ref(null);
const showWellbeingModal = ref(false);
const wellbeingEnteredToday = ref(props.hasWellbeingToday);
const wellbeingToast = ref(null);
let wellbeingToastTimer = null;

function onWellbeingSaved(data) {
    showWellbeingModal.value = false;
    wellbeingEnteredToday.value = true;
    if (data?.plan_adjusted) {
        wellbeingToast.value = {
            type: 'ai',
            message: 'KI passt deine heutige Trainingseinheit an deine Tagesform an…',
        };
    } else {
        wellbeingToast.value = {
            type: 'success',
            message: data?.message ?? 'Wellbeing gespeichert! 💪',
        };
    }
    clearTimeout(wellbeingToastTimer);
    wellbeingToastTimer = setTimeout(() => { wellbeingToast.value = null; }, 5000);
}
const syncing = ref(false);
const activitiesScrollRef = ref(null);

function scrollActivities(direction) {
    if (!activitiesScrollRef.value) return;
    activitiesScrollRef.value.scrollBy({ left: direction * 200, behavior: 'smooth' });
}

const trainingRecommendation = ref(null);
const recommendationLoading = ref(false);
const recommendationError = ref(null);
const showRecommendation = ref(false);
const recommendationHint = ref(null);

// Computed: total distance across all activities
const totalDistanceKm = computed(() => {
    const total = props.recentActivities.reduce((sum, a) => sum + (a.distance || 0), 0);
    return (total / 1000).toFixed(1);
});

// Helper: date key YYYY-M-D
function dayKey(d) { return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; }

// This week stats (Mon–today)
const weekStats = computed(() => {
    const now = new Date();
    const startOfWeek = new Date(now);
    const dow = (now.getDay() + 6) % 7; // Mon=0
    startOfWeek.setDate(now.getDate() - dow);
    startOfWeek.setHours(0, 0, 0, 0);

    const weekActs = props.recentActivities.filter(a => a.start_date && new Date(a.start_date) >= startOfWeek);
    const km = (weekActs.reduce((s, a) => s + (a.distance || 0), 0) / 1000).toFixed(1);
    const runs = weekActs.length;
    const speeds = weekActs.filter(a => a.average_speed > 0).map(a => a.average_speed);
    const avgPace = speeds.length
        ? formatPaceFromSpeed(speeds.reduce((s, v) => s + v, 0) / speeds.length)
        : '—';
    return { km, runs, avgPace };
});

// This month stats
const monthStats = computed(() => {
    const now = new Date();
    const acts = props.recentActivities.filter(a => {
        if (!a.start_date) return false;
        const d = new Date(a.start_date);
        return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    });
    return {
        km: (acts.reduce((s, a) => s + (a.distance || 0), 0) / 1000).toFixed(1),
        runs: acts.length,
    };
});

// Last 7 days bars (Mo..So labels, km per day)
const last7DaysBars = computed(() => {
    const labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    const kmMap = new Map();
    props.recentActivities.forEach(a => {
        if (!a.start_date) return;
        const k = dayKey(new Date(a.start_date));
        kmMap.set(k, (kmMap.get(k) || 0) + (a.distance || 0) / 1000);
    });
    const result = [];
    const todayKey = dayKey(new Date());
    for (let i = 6; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        const k = dayKey(d);
        const dow = (d.getDay() + 6) % 7; // Mon=0
        result.push({
            date: k,
            label: labels[dow],
            km: Math.round((kmMap.get(k) || 0) * 10) / 10,
            isToday: k === todayKey,
        });
    }
    return result;
});

const last7DaysMax = computed(() =>
    Math.max(...last7DaysBars.value.map(d => d.km), 1)
);

// Calendar state — navigable month
const today = new Date();
const calYear  = ref(today.getFullYear());
const calMonth = ref(today.getMonth()); // 0-indexed

function prevMonth() {
    if (calMonth.value === 0) { calMonth.value = 11; calYear.value--; }
    else calMonth.value--;
    calendarPickerDay.value = null;
}
function nextMonth() {
    if (calMonth.value === 11) { calMonth.value = 0; calYear.value++; }
    else calMonth.value++;
    calendarPickerDay.value = null;
}

// Map: day-number → array of activities for the displayed month
const activeDaysInMonth = computed(() => {
    const map = new Map();
    (props.recentActivities ?? []).forEach(a => {
        const d = new Date(a.start_date);
        if (d.getFullYear() === calYear.value && d.getMonth() === calMonth.value) {
            const day = d.getDate();
            if (!map.has(day)) map.set(day, []);
            map.get(day).push(a);
        }
    });
    return map;
});

// Events that fall in the displayed month
const eventsInMonth = computed(() => {
    const map = new Map();
    (props.events ?? []).forEach(e => {
        const d = new Date(e.event_date);
        if (d.getFullYear() === calYear.value && d.getMonth() === calMonth.value) {
            map.set(d.getDate(), e);
        }
    });
    return map;
});

const calendarPickerDay = ref(null);

function openCalendarDay(d) {
    if (!d.hasActivity) return;
    const activities = activeDaysInMonth.value.get(d.day);
    if (activities.length === 1) {
        openActivityDetail(activities[0]);
    } else {
        calendarPickerDay.value = calendarPickerDay.value === d.day ? null : d.day;
    }
}

function pickCalendarActivity(activity) {
    calendarPickerDay.value = null;
    openActivityDetail(activity);
}

// Monday-first calendar grid
const calendarDays = computed(() => {
    const firstDow = new Date(calYear.value, calMonth.value, 1).getDay(); // 0=Sun
    const leadingBlanks = (firstDow + 6) % 7; // Mon=0, Tue=1, ... Sun=6
    const daysInMonth = new Date(calYear.value, calMonth.value + 1, 0).getDate();
    const daysInPrevMonth = new Date(calYear.value, calMonth.value, 0).getDate();
    const isCurrentMonth = calYear.value === today.getFullYear() && calMonth.value === today.getMonth();
    const days = [];

    for (let i = leadingBlanks - 1; i >= 0; i--) {
        days.push({ day: daysInPrevMonth - i, currentMonth: false, isToday: false, hasActivity: false, hasEvent: false });
    }
    for (let i = 1; i <= daysInMonth; i++) {
        days.push({
            day: i,
            currentMonth: true,
            isToday: isCurrentMonth && i === today.getDate(),
            hasActivity: activeDaysInMonth.value.has(i),
            hasEvent: eventsInMonth.value.has(i),
            event: eventsInMonth.value.get(i) ?? null,
        });
    }
    const total = days.length;
    const remaining = total <= 35 ? 35 - total : 42 - total;
    for (let i = 1; i <= remaining; i++) {
        days.push({ day: i, currentMonth: false, isToday: false, hasActivity: false, hasEvent: false });
    }
    return days;
});

const currentMonthLabel = computed(() =>
    new Date(calYear.value, calMonth.value, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })
);

function openActivityDetail(activity) {
    router.visit(route('activities.show', activity.id));
}

function closeAIModal() {
    showAIModal.value = false;
    aiAnalysis.value = null;
    aiPlan.value = null;
    aiLoading.value = false;
}

async function getAIAnalysis(goalId) {
    aiLoading.value = true;
    aiModalType.value = 'analysis';
    showAIModal.value = true;
    try {
        const response = await axios.get(route('ai.analyze', goalId));
        aiAnalysis.value = response.data.analysis;
    } catch (error) {
        aiAnalysis.value = 'Fehler beim Abrufen der Analyse: ' + (error.response?.data?.message || error.message);
    } finally {
        aiLoading.value = false;
    }
}

async function getAIPlan(goalId) {
    aiLoading.value = true;
    aiModalType.value = 'plan';
    showAIModal.value = true;
    try {
        const response = await axios.get(route('ai.plan', goalId));
        aiPlan.value = response.data.plan;
    } catch (error) {
        aiPlan.value = 'Fehler beim Erstellen des Plans: ' + (error.response?.data?.message || error.message);
    } finally {
        aiLoading.value = false;
    }
}

function formatDistance(meters) {
    const km = meters / 1000;
    return km.toFixed(2);
}

function formatTime(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m ${secs}s`;
}

function formatSpeed(metersPerSecond) {
    if (metersPerSecond <= 0) return '—';
    const secondsPerKm = 1000 / metersPerSecond;
    const minutes = Math.floor(secondsPerKm / 60);
    const seconds = Math.round(secondsPerKm % 60);
    return `${minutes}:${seconds.toString().padStart(2, '0')} min/km`;
}

function formatPaceFromSpeed(metersPerSecond) {
    if (!metersPerSecond || metersPerSecond <= 0) return '—';
    const secondsPerKm = 1000 / metersPerSecond;
    const minutes = Math.floor(secondsPerKm / 60);
    const seconds = Math.round(secondsPerKm % 60);
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

// Relative date: "Heute", "Gestern", "vor 3 Tagen", etc.
function relativeDate(dateString) {
    if (!dateString) return '—';
    const d = new Date(dateString);
    const now = new Date();
    const diffDays = Math.floor((now - d) / 86400000);
    if (diffDays === 0) return 'Heute';
    if (diffDays === 1) return 'Gestern';
    if (diffDays < 7)  return `vor ${diffDays} Tagen`;
    if (diffDays < 14) return 'vor 1 Woche';
    if (diffDays < 30) return `vor ${Math.floor(diffDays / 7)} Wochen`;
    return d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });
}

// Pace color: grün = schnell, orange = mittel, rot = langsam
function paceColor(mps) {
    if (!mps || mps <= 0) return 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400';
    const secPerKm = 1000 / mps;
    if (secPerKm < 270)  return 'bg-green-50 dark:bg-green-500/15 text-green-700 dark:text-green-300';  // < 4:30
    if (secPerKm < 330)  return 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300';      // < 5:30
    if (secPerKm < 390)  return 'bg-yellow-50 dark:bg-yellow-500/15 text-yellow-700 dark:text-yellow-300'; // < 6:30
    return 'bg-orange-50 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300';
}

function round2(value) {
    if (typeof value !== 'number') return value;
    return Math.round(value * 100) / 100;
}

function formatDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const year = date.getFullYear();
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    return `${day}.${month}.${year} ${hours}:${minutes}`;
}

function formatDateShort(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const year = date.getFullYear();
    return `${day}.${month}.${year}`;
}

function generateAIAnalysis(progress) {
    if (!progress) return 'Keine Daten verfügbar.';
    const completed = progress.completed_distance_km;
    const target = progress.target_distance_km;
    const percentage = progress.progress_percentage;
    const daysRemaining = progress.days_remaining;
    const activitiesCount = progress.activities_count;
    let analysis = [];
    if (percentage >= 100) {
        analysis.push('🏆 Du hast dein Ziel erreicht!');
    } else if (percentage >= 75) {
        analysis.push(`🎯 ${percentage}% — nur noch ${round2(target - completed)} km!`);
    } else if (percentage >= 50) {
        analysis.push(`💪 Halbzeit! ${round2(completed)} von ${target} km.`);
    } else {
        analysis.push(`⏳ ${percentage}% — ${round2(target - completed)} km verbleiben.`);
    }
    if (daysRemaining > 0) {
        const kmPerDay = round2((target - completed) / daysRemaining);
        const kmPerWeek = round2(kmPerDay * 7);
        if (kmPerDay > 0) analysis.push(`⏰ ${daysRemaining} Tage: ~${kmPerDay} km/Tag (${kmPerWeek} km/Wo.)`);
    }
    if (activitiesCount > 0) {
        const avgPerSession = round2(completed / activitiesCount);
        analysis.push(`📊 ${activitiesCount} Einheiten, Ø ${avgPerSession} km`);
    }
    return analysis.join(' • ');
}

function generatePlan(goalId) {
    axios.post(route('plans.generate'), { goal_id: goalId })
        .then(res => { plan.value = res.data.plan; planError.value = null; })
        .catch(err => { planError.value = err.response?.data?.message || 'Plan konnte nicht erstellt werden.'; plan.value = null; });
}


async function getTodayRecommendation() {
    recommendationLoading.value = true;
    recommendationError.value = null;
    showRecommendation.value = false;
    recommendationHint.value = null;
    try {
        const response = await axios.get(route('ai.recommendation.today'));
        const data = response.data;
        if (data.wellbeing_exists) {
            showRecommendation.value = true;
            trainingRecommendation.value = data.recommendation;
        } else {
            showRecommendation.value = false;
            recommendationHint.value = data.recommendation_message || 'Bitte Wellbeing für heute eintragen.';
        }
    } catch (error) {
        recommendationError.value = 'Fehler: ' + (error.response?.data?.message || error.message);
    } finally {
        recommendationLoading.value = false;
    }
}

// SVG chart for threshold pace history
// Parse "d.m.Y" date string to timestamp
function parseDMY(dateStr) {
    if (!dateStr) return null;
    const parts = dateStr.split('.');
    if (parts.length !== 3) return null;
    return new Date(parseInt(parts[2]), parseInt(parts[1]) - 1, parseInt(parts[0])).getTime();
}

const chartData = computed(() => {
    const history = props.thresholdPaceHistory;
    if (!history || history.length < 2) return null;

    // Only show chart when data spans more than 7 days
    const firstDate = parseDMY(history[0]?.date);
    const lastDate  = parseDMY(history[history.length - 1]?.date);
    if (!firstDate || !lastDate) return null;
    const spanDays = (lastDate - firstDate) / (1000 * 60 * 60 * 24);
    if (spanDays < 7) return null;

    const W = 560, H = 120, padX = 40, padY = 16;
    const plotW = W - padX * 2;
    const plotH = H - padY * 2;

    const paces = history.map(h => h.pace);
    const minPace = Math.min(...paces);
    const maxPace = Math.max(...paces);
    const range = maxPace - minPace || 0.5;

    const points = history.map((h, i) => {
        const x = padX + (i / (history.length - 1)) * plotW;
        // Invert Y: lower pace (faster) = higher on chart
        const y = padY + ((h.pace - minPace) / range) * plotH;
        return { x, y, pace: h.pace_formatted, date: h.date };
    });

    // Smooth polyline path
    const pathD = points.map((p, i) => {
        if (i === 0) return `M ${p.x} ${p.y}`;
        const prev = points[i - 1];
        const cpX = (prev.x + p.x) / 2;
        return `C ${cpX} ${prev.y}, ${cpX} ${p.y}, ${p.x} ${p.y}`;
    }).join(' ');

    // Fill area below curve
    const fillD = pathD + ` L ${points[points.length - 1].x} ${H - padY} L ${padX} ${H - padY} Z`;

    return { points, pathD, fillD, W, H, minPace, maxPace, padX, padY };
});

onMounted(() => {
    getTodayRecommendation();
});

function syncStrava() {
    syncing.value = true;
    router.post('/strava/sync', {}, {
        onFinish: () => { syncing.value = false; },
    });
}

</script>

<template>
    <Head title="Übersicht" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">Runner Dashboard</h2>
                <p class="text-sm text-gray-500 dark:text-slate-400">Dashboard / Übersicht</p>
            </div>
        </template>

        <div class="py-4 lg:py-6">
            <div class="mx-auto max-w-7xl px-3 sm:px-6 lg:px-8 space-y-3 lg:space-y-4">

                <!-- Sync result banner -->
                <div v-if="props.syncResult" class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
                    <svg class="h-5 w-5 shrink-0 text-indigo-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-indigo-800">{{ props.syncResult }}</p>
                </div>

                <!-- Flash Messages -->
                <div v-if="flash.success && showFlash" class="rounded-lg border border-green-200 bg-green-50 p-4">
                    <p class="text-sm font-medium text-green-800">{{ flash.success }}</p>
                </div>
                <div v-if="flash.error && showFlash" class="rounded-lg border border-red-200 bg-red-50 p-4">
                    <p class="text-sm font-medium text-red-800">{{ flash.error }}</p>
                </div>

                <!-- ─── Wellbeing-Erinnerung ─────────────────────────────────── -->
                <Transition
                    enter-active-class="transition-all duration-300 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <button
                        v-if="!wellbeingEnteredToday"
                        @click="showWellbeingModal = true"
                        class="w-full text-left flex items-center gap-4 rounded-2xl px-4 py-3.5 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-500/10 dark:to-orange-500/10 border border-amber-200 dark:border-amber-500/30 hover:from-amber-100 hover:to-orange-100 dark:hover:from-amber-500/20 dark:hover:to-orange-500/20 transition-colors shadow-sm"
                    >
                        <!-- Pulse dot -->
                        <div class="relative shrink-0">
                            <span class="absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75 animate-ping"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-amber-500"></span>
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Wie geht es dir heute?</p>
                            <p class="text-xs text-amber-600 dark:text-amber-400/80 mt-0.5">Tagesform eintragen — der KI-Plan passt sich automatisch an</p>
                        </div>

                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold px-3 py-1.5 transition-colors">
                            Eintragen
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                            </svg>
                        </span>
                    </button>
                </Transition>

                <!-- ═══ ROW 1: Profil + Stats + Kalender ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 lg:gap-4">

                    <!-- Profil-Karte -->
                    <div class="lg:col-span-5 rounded-2xl overflow-hidden shadow-sm">
                        <!-- Hero-Header -->
                        <div class="relative bg-gradient-to-br from-slate-800 via-slate-800 to-indigo-900 p-4 sm:p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="ring-4 ring-indigo-400 ring-opacity-30 rounded-full shrink-0">
                                        <UserAvatar :user="page.props.auth.user" size="lg" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Strava Konto</p>
                                        <h3 class="text-lg font-bold text-white truncate">{{ props.stravaAccount?.username || 'Nicht verbunden' }}</h3>
                                        <p class="text-xs text-slate-400">{{ props.stravaAccount?.last_synced_at ? 'Sync: ' + props.stravaAccount.last_synced_at : 'Noch nie synchronisiert' }}</p>
                                    </div>
                                </div>
                                <span v-if="props.stravaConnected"
                                    class="shrink-0 inline-flex items-center gap-1 rounded-full bg-green-500 bg-opacity-20 border border-green-500 border-opacity-30 px-2 py-0.5 text-xs font-medium text-green-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-green-400"></span> Live
                                </span>
                                <span v-else
                                    class="shrink-0 inline-flex items-center gap-1 rounded-full bg-red-500 bg-opacity-20 border border-red-500 border-opacity-30 px-2 py-0.5 text-xs font-medium text-red-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-red-400"></span> Getrennt
                                </span>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <a v-if="!props.stravaConnected" href="/strava/connect"
                                    class="flex-1 text-center rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 transition-colors">
                                    Mit Strava verbinden
                                </a>
                                <button v-else @click="syncStrava" :disabled="syncing || props.thresholdPaceCalculating"
                                    class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-indigo-500 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-400 disabled:opacity-60 transition-colors">
                                    <span v-if="syncing" class="h-3.5 w-3.5 rounded-full border-2 border-white border-t-transparent animate-spin" />
                                    <span>{{ syncing ? 'Läuft…' : '🔄 Sync' }}</span>
                                </button>
                                <a href="/profile/runner"
                                    class="flex-1 text-center rounded-xl bg-white bg-opacity-10 border border-white border-opacity-20 px-4 py-2 text-sm font-semibold text-white hover:bg-opacity-20 transition-colors">
                                    Profil
                                </a>
                            </div>
                        </div>
                        <!-- Unterer Teil: Letzte Aktivitäten -->
                        <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 border-t-0 rounded-b-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Letzte Läufe</h4>
                                <a href="/activities" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Alle →</a>
                            </div>
                            <div v-if="props.recentActivities.length === 0" class="text-sm text-gray-400 dark:text-slate-500 text-center py-6">
                                Noch keine Aktivitäten — Strava verbinden und synchronisieren
                            </div>
                            <div v-else class="space-y-2">
                                <button
                                    v-for="activity in props.recentActivities.slice(0, 4)"
                                    :key="activity.id"
                                    @click="openActivityDetail(activity)"
                                    class="w-full rounded-xl px-3 py-3 hover:bg-gray-50 dark:hover:bg-slate-800/70 text-left transition-colors group border border-transparent hover:border-gray-100 dark:hover:border-slate-700"
                                >
                                    <!-- Zeile 1: Name + Datum -->
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate leading-tight">{{ activity.name }}</p>
                                        <span class="text-[11px] text-gray-400 dark:text-slate-500 flex-shrink-0">{{ relativeDate(activity.start_date) }}</span>
                                    </div>
                                    <!-- Zeile 2: Metriken als Pills -->
                                    <div class="flex flex-wrap gap-1.5">
                                        <!-- Distanz -->
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                                            📍 {{ round2(formatDistance(activity.distance)) }} km
                                        </span>
                                        <!-- Zeit -->
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-medium">
                                            ⏱ {{ formatTime(activity.moving_time) }}
                                        </span>
                                        <!-- Pace -->
                                        <span v-if="activity.average_speed > 0"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-bold"
                                            :class="paceColor(activity.average_speed)">
                                            ⚡ {{ formatPaceFromSpeed(activity.average_speed) }}/km
                                        </span>
                                        <!-- Herzfrequenz -->
                                        <span v-if="activity.average_heartrate"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 text-xs font-medium">
                                            ❤️ {{ Math.round(activity.average_heartrate) }}
                                        </span>
                                        <!-- Höhenmeter -->
                                        <span v-if="activity.total_elevation_gain > 0"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 text-xs font-medium">
                                            ↑ {{ Math.round(activity.total_elevation_gain) }}m
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Stats-Karte -->
                    <div class="lg:col-span-3 bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5 flex flex-col gap-4">

                        <!-- Header -->
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Diese Woche</h4>
                            <a href="/statistics" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Statistiken →</a>
                        </div>

                        <!-- 3 KPIs: Woche -->
                        <div class="grid grid-cols-3 gap-2">
                            <div class="rounded-xl bg-indigo-50 dark:bg-indigo-500/10 p-3 text-center">
                                <p class="text-xl font-black text-indigo-700 dark:text-indigo-300">{{ weekStats.km }}</p>
                                <p class="text-[11px] text-indigo-500 dark:text-indigo-400 mt-0.5 font-medium">km</p>
                            </div>
                            <div class="rounded-xl bg-green-50 dark:bg-green-500/10 p-3 text-center">
                                <p class="text-xl font-black text-green-700 dark:text-green-300">{{ weekStats.runs }}</p>
                                <p class="text-[11px] text-green-500 dark:text-green-400 mt-0.5 font-medium">Läufe</p>
                            </div>
                            <div class="rounded-xl bg-purple-50 dark:bg-purple-500/10 p-3 text-center">
                                <p class="text-xl font-black text-purple-700 dark:text-purple-300">{{ weekStats.avgPace }}</p>
                                <p class="text-[11px] text-purple-500 dark:text-purple-400 mt-0.5 font-medium">Ø Pace</p>
                            </div>
                        </div>

                        <!-- Tagesbalken letzte 7 Tage -->
                        <div>
                            <p class="text-xs font-medium text-gray-400 dark:text-slate-500 mb-2">Letzte 7 Tage</p>
                            <div class="flex items-end gap-1 h-14">
                                <div v-for="day in last7DaysBars" :key="day.date"
                                    class="flex-1 flex flex-col items-center gap-1">
                                    <div class="w-full rounded-t-md transition-all"
                                        :class="day.km > 0 ? 'bg-indigo-500 dark:bg-indigo-400' : 'bg-gray-100 dark:bg-slate-700'"
                                        :style="{ height: day.km > 0 ? Math.max(6, (day.km / last7DaysMax) * 48) + 'px' : '4px' }"
                                        :title="day.km > 0 ? day.km + ' km' : 'Kein Lauf'">
                                    </div>
                                    <span class="text-[10px] font-medium"
                                        :class="day.isToday ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-gray-400 dark:text-slate-500'">
                                        {{ day.label }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Monats-Total -->
                        <div class="border-t border-gray-100 dark:border-slate-800 pt-3 flex items-center justify-between">
                            <div>
                                <p class="text-[11px] text-gray-400 dark:text-slate-500">Dieser Monat</p>
                                <p class="text-sm font-bold text-gray-800 dark:text-slate-200 mt-0.5">{{ monthStats.km }} km · {{ monthStats.runs }} Läufe</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[11px] text-gray-400 dark:text-slate-500">Gesamt</p>
                                <p class="text-sm font-bold text-gray-800 dark:text-slate-200 mt-0.5">{{ totalDistanceKm }} km</p>
                            </div>
                        </div>
                    </div>

                    <!-- Kalender + Events -->
                    <div class="lg:col-span-4 bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                        <!-- Header mit Navigation -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <button @click="prevMonth" class="h-7 w-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-slate-700 transition text-sm">‹</button>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200 min-w-[120px] text-center">{{ currentMonthLabel }}</h4>
                                <button @click="nextMonth" class="h-7 w-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-slate-700 transition text-sm">›</button>
                            </div>
                            <a href="/calendar" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Vollansicht →</a>
                        </div>

                        <!-- Mini-Kalender: Woche beginnt Montag -->
                        <div class="mb-4">
                            <div class="grid grid-cols-7 gap-0.5 text-center text-xs font-medium text-gray-400 dark:text-slate-500 mb-1">
                                <div>Mo</div><div>Di</div><div>Mi</div><div>Do</div><div>Fr</div><div>Sa</div><div>So</div>
                            </div>
                            <div class="grid grid-cols-7 gap-0.5 text-center">
                                <div v-for="(d, i) in calendarDays" :key="i"
                                    class="relative flex flex-col items-center justify-center h-7 w-7 mx-auto rounded-full text-xs transition-colors"
                                    :class="{
                                        'bg-indigo-600 text-white font-bold shadow-sm': d.isToday && !d.hasEvent,
                                        'bg-orange-500 text-white font-bold shadow-sm': d.hasEvent,
                                        'text-gray-300 dark:text-slate-600': !d.currentMonth,
                                        'text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700': d.currentMonth && !d.isToday && !d.hasEvent,
                                        'cursor-pointer': d.hasActivity || d.hasEvent,
                                        'cursor-default': !d.hasActivity && !d.hasEvent,
                                    }"
                                    :title="d.hasEvent ? d.event.name : ''"
                                    @click="openCalendarDay(d)">
                                    {{ d.day }}
                                    <!-- Aktivitäts-Dot -->
                                    <span v-if="d.hasActivity && !d.isToday && !d.hasEvent"
                                        class="absolute bottom-0.5 left-1/2 -translate-x-1/2 h-1 w-1 rounded-full bg-indigo-500">
                                    </span>
                                    <span v-if="d.hasActivity && d.isToday"
                                        class="absolute bottom-0.5 left-1/2 -translate-x-1/2 h-1 w-1 rounded-full bg-white opacity-80">
                                    </span>

                                    <!-- Multi-activity picker -->
                                    <div v-if="d.hasActivity && calendarPickerDay === d.day"
                                        class="absolute top-8 left-1/2 -translate-x-1/2 z-30 w-52 rounded-xl bg-white dark:bg-slate-800 shadow-lg border border-gray-100 dark:border-slate-700 py-1 text-left"
                                        @click.stop>
                                        <p class="px-3 py-1.5 text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider border-b border-gray-100 dark:border-slate-700">
                                            {{ activeDaysInMonth.get(d.day)?.length }} Aktivitäten
                                        </p>
                                        <button
                                            v-for="act in activeDaysInMonth.get(d.day)"
                                            :key="act.id"
                                            @click="pickCalendarActivity(act)"
                                            class="w-full px-3 py-2 text-left hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-colors">
                                            <p class="text-xs font-semibold text-gray-800 dark:text-slate-200 truncate">{{ act.name }}</p>
                                            <p class="text-xs text-gray-400 dark:text-slate-500">{{ formatDistance(act.distance) }} km · {{ formatTime(act.moving_time) }}</p>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Nächste Events -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Nächste Events</h5>
                                <a href="/events" class="text-xs text-indigo-500 hover:text-indigo-700 transition">+ Hinzufügen</a>
                            </div>
                            <div v-if="props.events.length === 0" class="text-sm text-gray-400 dark:text-slate-500">
                                Kein Event geplant
                            </div>
                            <div v-else class="space-y-2">
                                <a v-for="event in props.events.slice(0, 3)" :key="event.id"
                                    :href="`/events`"
                                    class="flex items-center gap-3 rounded-xl bg-gray-50 dark:bg-slate-800 p-2.5 border border-gray-100 dark:border-slate-700 hover:border-indigo-200 dark:hover:border-indigo-500/40 transition-colors group">
                                    <!-- Priority + Phase badge -->
                                    <div class="flex-shrink-0 text-center">
                                        <div class="h-9 w-9 rounded-lg flex items-center justify-center font-black text-sm"
                                            :class="{
                                                'bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-400': event.priority === 'A',
                                                'bg-yellow-100 dark:bg-yellow-500/15 text-yellow-600 dark:text-yellow-400': event.priority === 'B',
                                                'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400': event.priority === 'C',
                                            }">
                                            {{ event.priority }}
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold text-gray-800 dark:text-slate-200 truncate">{{ event.name }}</p>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="text-xs text-gray-400 dark:text-slate-500">{{ new Date(event.event_date).toLocaleDateString('de-DE', { day: 'numeric', month: 'short' }) }}</span>
                                            <span class="text-xs px-1.5 py-0.5 rounded-md font-medium"
                                                :class="{
                                                    'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400': event.training_phase.key === 'race_week',
                                                    'bg-yellow-50 dark:bg-yellow-500/10 text-yellow-600 dark:text-yellow-400': event.training_phase.key === 'taper',
                                                    'bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400': event.training_phase.key === 'peak',
                                                    'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400': event.training_phase.key === 'build',
                                                    'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400': event.training_phase.key === 'base',
                                                }">
                                                {{ event.training_phase.label }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-xs font-bold text-indigo-600 dark:text-indigo-400">{{ event.days_until }}d</p>
                                        <p class="text-xs text-gray-400 dark:text-slate-500">{{ event.distance_label }}</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ ROW 2: Quick Actions ═══ -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">
                    <button v-if="props.stravaConnected" @click="syncStrava" :disabled="syncing"
                        class="flex items-center gap-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 hover:shadow-md hover:border-blue-200 dark:hover:border-blue-500/40 transition-all group disabled:opacity-60">
                        <div class="h-10 w-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-xl group-hover:bg-blue-200 dark:group-hover:bg-blue-500/30 transition-colors flex-shrink-0">
                            <span v-if="syncing" class="h-5 w-5 rounded-full border-2 border-blue-600 border-t-transparent animate-spin inline-block"></span>
                            <span v-else>🔄</span>
                        </div>
                        <span class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-slate-200 leading-tight">{{ syncing ? 'Läuft…' : 'Strava Sync' }}</span>
                    </button>
                    <a v-else href="/strava/connect"
                        class="flex items-center gap-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 hover:shadow-md hover:border-orange-200 dark:hover:border-orange-500/40 transition-all group">
                        <div class="h-10 w-10 rounded-xl bg-orange-100 dark:bg-orange-500/20 flex items-center justify-center text-xl group-hover:bg-orange-200 dark:group-hover:bg-orange-500/30 transition-colors flex-shrink-0">🔗</div>
                        <span class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-slate-200 leading-tight">Strava verbinden</span>
                    </a>
                    <a href="/events"
                        class="flex items-center gap-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 hover:shadow-md hover:border-green-200 dark:hover:border-green-500/40 transition-all group">
                        <div class="h-10 w-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center text-xl group-hover:bg-green-200 dark:group-hover:bg-green-500/30 transition-colors flex-shrink-0">🎯</div>
                        <span class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-slate-200 leading-tight">Event planen</span>
                    </a>
                    <button @click="showWellbeingModal = true"
                        class="flex items-center gap-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 hover:shadow-md hover:border-purple-200 dark:hover:border-purple-500/40 transition-all group">
                        <div class="h-10 w-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center text-xl group-hover:bg-purple-200 dark:group-hover:bg-purple-500/30 transition-colors flex-shrink-0">💪</div>
                        <span class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-slate-200 leading-tight">Wellbeing</span>
                    </button>
                    <a href="/profile/runner"
                        class="flex items-center gap-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-500/40 transition-all group sm:col-span-1 col-span-2">
                        <div class="h-10 w-10 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center text-xl group-hover:bg-indigo-200 dark:group-hover:bg-indigo-500/30 transition-colors flex-shrink-0">👤</div>
                        <span class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-slate-200 leading-tight">Athletenprofil</span>
                    </a>
                </div>

                <!-- ═══ Schwellenpace-Karte ═══ -->
                <div class="rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 shadow-sm text-white overflow-hidden relative">

                    <!-- Background calculation indicator -->
                    <div v-if="props.thresholdPaceCalculating" class="absolute top-3 right-3 z-10 flex items-center gap-1.5 rounded-full bg-white bg-opacity-20 px-3 py-1">
                        <span class="h-2.5 w-2.5 rounded-full border-2 border-white border-t-transparent animate-spin inline-block"></span>
                        <span class="text-xs font-medium text-white">Berechnung läuft…</span>
                    </div>

                    <div class="p-5">
                        <!-- Header Row: Pace + Zones -->
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <div class="h-14 w-14 rounded-xl bg-white bg-opacity-20 flex items-center justify-center text-3xl flex-shrink-0">⚡</div>
                                <div>
                                    <p class="text-xs font-medium text-indigo-200 uppercase tracking-wider">KI-berechnete Schwellenpace</p>
                                    <p v-if="props.thresholdPace" class="text-4xl font-bold mt-0.5 tabular-nums">
                                        {{ props.thresholdPace }}
                                        <span class="text-lg font-normal text-indigo-200">min/km</span>
                                    </p>
                                    <p v-else class="text-xl font-semibold mt-0.5 text-indigo-200">Noch nicht berechnet</p>
                                    <p class="text-xs text-indigo-300 mt-1">
                                        <span v-if="props.thresholdPaceCalculating">KI analysiert letzte 20 Läufe im Hintergrund…</span>
                                        <span v-else-if="props.thresholdPaceCalculatedAt">Berechnet: {{ props.thresholdPaceCalculatedAt }} · Basis: letzte 20 Läufe</span>
                                        <span v-else>Wird automatisch nach dem nächsten Strava-Sync berechnet</span>
                                    </p>
                                </div>
                            </div>

                            <!-- Pace Zones -->
                            <div v-if="props.paceZones" class="grid grid-cols-5 gap-1 sm:gap-1.5 w-full sm:min-w-[320px]">
                                <div v-for="(zone, key) in props.paceZones" :key="key"
                                    class="rounded-lg bg-white bg-opacity-15 p-2 text-center">
                                    <p class="text-xs font-bold text-white uppercase tracking-wide">{{ key }}</p>
                                    <p class="text-xs text-indigo-100 mt-0.5 leading-tight font-mono">
                                        {{ zone.min_pace }}<br>
                                        <span class="text-indigo-300 text-[10px]">{{ zone.max_pace }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Race Predictions -->
                        <div v-if="props.racePredictions" class="mt-4 pt-4 border-t border-white border-opacity-20">
                            <p class="text-xs font-semibold text-indigo-200 uppercase tracking-wider mb-2">🏁 Wettkampf-Zeitvorhersagen</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div v-for="(pred, key) in props.racePredictions" :key="key"
                                    class="rounded-lg bg-white bg-opacity-10 px-3 py-2">
                                    <p class="text-xs text-indigo-300">{{ pred.label }}</p>
                                    <p class="text-lg font-bold text-white tabular-nums mt-0.5">{{ pred.total_time }}</p>
                                    <p class="text-xs text-indigo-200 font-mono">{{ pred.pace }} min/km</p>
                                </div>
                            </div>
                        </div>

                        <!-- SVG History Chart -->
                        <div v-if="chartData" class="mt-4 pt-4 border-t border-white border-opacity-20">
                            <p class="text-xs font-semibold text-indigo-200 uppercase tracking-wider mb-3">📈 Entwicklung Schwellenpace</p>
                            <div class="w-full overflow-hidden">
                                <svg :viewBox="`0 0 ${chartData.W} ${chartData.H}`" class="w-full" preserveAspectRatio="none" style="height:100px">
                                    <!-- Fill area -->
                                    <path :d="chartData.fillD" fill="rgba(255,255,255,0.08)" />
                                    <!-- Line -->
                                    <path :d="chartData.pathD" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" stroke-linecap="round" />
                                    <!-- Points + labels -->
                                    <g v-for="(p, i) in chartData.points" :key="i">
                                        <circle :cx="p.x" :cy="p.y" r="4" fill="white" opacity="0.9" />
                                        <!-- Show label for first, last, and every 3rd point to avoid clutter -->
                                        <text v-if="i === 0 || i === chartData.points.length - 1 || i % 3 === 0"
                                            :x="p.x" :y="p.y - 8"
                                            text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.85)"
                                            font-family="monospace">
                                            {{ p.pace }}
                                        </text>
                                        <text v-if="i === 0 || i === chartData.points.length - 1"
                                            :x="p.x" :y="chartData.H - 2"
                                            text-anchor="middle" font-size="8" fill="rgba(255,255,255,0.5)">
                                            {{ p.date }}
                                        </text>
                                    </g>
                                </svg>
                            </div>
                            <p class="text-xs text-indigo-300 mt-1 text-center">
                                {{ props.thresholdPaceHistory.length }} Messpunkte · oben = schneller
                            </p>
                        </div>
                        <div v-else-if="props.thresholdPace" class="mt-3 text-xs text-indigo-300">
                            Diagramm erscheint sobald Daten über mehr als 7 Tage vorliegen
                            <span v-if="props.thresholdPaceHistory.length > 0"> · {{ props.thresholdPaceHistory.length }} Messung(en) gespeichert</span>.
                        </div>

                        <!-- Footer -->
                        <div v-if="props.stravaConnected" class="mt-4 pt-4 border-t border-white border-opacity-20 flex items-center justify-between gap-3">
                            <p class="text-xs text-indigo-200">
                                Neuberechnung erfolgt automatisch nach einem Sync · max. 1× täglich
                            </p>
                            <button @click="syncStrava" :disabled="syncing || props.thresholdPaceCalculating"
                                class="rounded-lg bg-white bg-opacity-20 hover:bg-opacity-30 disabled:opacity-50 px-3 py-1.5 text-xs font-semibold text-white transition-colors flex-shrink-0 flex items-center gap-1.5">
                                <span v-if="syncing" class="h-3 w-3 rounded-full border-2 border-white border-t-transparent animate-spin inline-block"></span>
                                <span>{{ syncing ? 'Synchronisiert…' : '🔄 Strava Sync' }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ═══ ROW 3: Trainingsempfehlung + Aktive Ziele ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 lg:gap-4">

                    <!-- Heute: Plan-Session ODER KI-Empfehlung -->
                    <div class="lg:col-span-7 bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">

                        <!-- ── Aktiver Plan: heutige Session anzeigen ── -->
                        <template v-if="props.hasActivePlan">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">📅 Heutige Trainingseinheit</h4>
                                <a :href="props.todayPlanSession?.event_id ? `/events/${props.todayPlanSession.event_id}/plan` : '/events'"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 font-medium hover:text-indigo-800 bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 rounded-md px-2 py-1 transition-colors">
                                    Zum Plan →
                                </a>
                            </div>

                            <!-- Session card -->
                            <div v-if="props.todayPlanSession" class="rounded-xl border overflow-hidden" :class="{
                                'border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800': props.todayPlanSession.type === 'rest',
                                'border-green-100 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10': props.todayPlanSession.type === 'easy_run',
                                'border-amber-100 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10': props.todayPlanSession.type === 'tempo_run',
                                'border-red-100 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10': props.todayPlanSession.type === 'interval',
                                'border-blue-100 dark:border-blue-500/20 bg-blue-50 dark:bg-blue-500/10': props.todayPlanSession.type === 'long_run',
                                'border-indigo-100 dark:border-indigo-500/20 bg-indigo-50 dark:bg-indigo-500/10': props.todayPlanSession.type === 'race_prep',
                            }">
                                <div class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <p class="font-semibold text-sm" :class="{
                                            'text-gray-500 dark:text-slate-400': props.todayPlanSession.type === 'rest',
                                            'text-green-700 dark:text-green-400': props.todayPlanSession.type === 'easy_run',
                                            'text-amber-700 dark:text-amber-400': props.todayPlanSession.type === 'tempo_run',
                                            'text-red-700 dark:text-red-400': props.todayPlanSession.type === 'interval',
                                            'text-blue-700 dark:text-blue-400': props.todayPlanSession.type === 'long_run',
                                            'text-indigo-700 dark:text-indigo-400': props.todayPlanSession.type === 'race_prep',
                                        }">{{ props.todayPlanSession.title }}</p>
                                        <div class="flex gap-1.5 shrink-0">
                                            <span v-if="props.todayPlanSession.status === 'completed'" class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400">✓ Erledigt</span>
                                            <span v-else-if="props.todayPlanSession.status === 'skipped'" class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-500">Übersprungen</span>
                                            <span v-else class="text-xs font-medium px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400">Heute</span>
                                            <span v-if="props.todayPlanSession.activity_id" class="text-xs font-medium px-2 py-0.5 rounded-full bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-400">🔗 Strava</span>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 leading-relaxed">{{ props.todayPlanSession.description }}</p>
                                    <div v-if="props.todayPlanSession.type !== 'rest'" class="flex flex-wrap gap-3 mt-2.5">
                                        <span v-if="props.todayPlanSession.distance_km" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c-.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                            {{ props.todayPlanSession.distance_km }} km
                                        </span>
                                        <span v-if="props.todayPlanSession.duration_min" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
                                            {{ props.todayPlanSession.duration_min }} min
                                        </span>
                                        <span v-if="props.todayPlanSession.pace_target && props.todayPlanSession.pace_target !== 'null'" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                            {{ props.todayPlanSession.pace_target }} min/km
                                        </span>
                                        <span v-if="props.todayPlanSession.zone" class="text-xs font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">Zone {{ props.todayPlanSession.zone }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- No session today (rest day not in plan, or plan ended) -->
                            <div v-else class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-dashed border-gray-200 dark:border-slate-600 p-6 text-center">
                                <p class="text-sm text-gray-400 dark:text-slate-500">Für heute ist keine Trainingseinheit geplant.</p>
                                <a href="/events" class="mt-2 inline-block text-xs text-indigo-500 hover:underline">Zum Plan →</a>
                            </div>
                        </template>

                        <!-- ── Kein aktiver Plan: KI-Empfehlung ── -->
                        <template v-else>
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">🧭 Trainings-Empfehlung für heute</h4>
                                <button @click="getTodayRecommendation"
                                    class="text-xs text-indigo-600 font-medium hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 rounded-md px-2 py-1 transition-colors">
                                    Aktualisieren
                                </button>
                            </div>
                            <div v-if="recommendationLoading" class="flex items-center gap-3 text-gray-500 py-4">
                                <span class="text-xl">⏳</span>
                                <span class="text-sm">Empfehlung wird geladen...</span>
                            </div>
                            <div v-else-if="recommendationError" class="rounded-lg bg-red-50 border border-red-100 p-4 text-sm text-red-700">
                                {{ recommendationError }}
                            </div>
                            <div v-else-if="showRecommendation" class="rounded-lg bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-950/40 dark:to-indigo-950/40 border border-blue-100 dark:border-indigo-800/40 p-4 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                                {{ trainingRecommendation }}
                                <p class="mt-3 text-xs text-indigo-500 dark:text-indigo-400 italic">Diese Empfehlung wird automatisch in deinen Kalender eingetragen.</p>
                            </div>
                            <div v-else-if="recommendationHint" class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 p-4">
                                <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-1">Hinweis</p>
                                <p class="text-sm text-amber-700 dark:text-amber-400">{{ recommendationHint }}</p>
                                <button @click="showWellbeingModal = true"
                                    class="mt-3 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600 transition-colors">
                                    Jetzt Wellbeing eintragen
                                </button>
                            </div>
                            <div v-else class="text-sm text-gray-400 text-center py-8">
                                Noch keine Empfehlung verfügbar.
                            </div>
                        </template>
                    </div>

                    <!-- Nächste Events -->
                    <div class="lg:col-span-5 bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Nächste Events</h4>
                            <a href="/events" class="text-xs bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 rounded-md px-2 py-0.5 font-medium hover:bg-indigo-100 transition">+ Neu</a>
                        </div>
                        <div v-if="props.events.length === 0" class="text-sm text-gray-400 dark:text-slate-500 text-center py-8">
                            Kein Event geplant.<br>
                            <a href="/events" class="text-indigo-500 hover:underline text-xs mt-1 inline-block">Event hinzufügen →</a>
                        </div>
                        <div v-else class="space-y-3 max-h-96 overflow-y-auto">
                            <a v-for="event in props.events" :key="event.id" href="/events"
                                class="block rounded-xl border border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-3 hover:border-indigo-200 dark:hover:border-indigo-500/40 transition">
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <div class="flex items-start gap-2.5 flex-1 min-w-0">
                                        <div class="h-9 w-9 rounded-lg flex items-center justify-center font-black text-sm flex-shrink-0"
                                            :class="{
                                                'bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-400': event.priority === 'A',
                                                'bg-yellow-100 dark:bg-yellow-500/15 text-yellow-600 dark:text-yellow-400': event.priority === 'B',
                                                'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400': event.priority === 'C',
                                            }">
                                            {{ event.priority }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 leading-tight truncate">{{ event.name }}</p>
                                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ event.distance_label }} · {{ new Date(event.event_date).toLocaleDateString('de-DE') }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ event.days_until }}d</p>
                                        <p class="text-xs text-gray-400 dark:text-slate-500">{{ event.weeks_until }} Wo.</p>
                                    </div>
                                </div>
                                <!-- Trainingsphase + Zielzeit -->
                                <div class="flex items-center gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded-md font-semibold"
                                        :class="{
                                            'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400': event.training_phase.key === 'race_week',
                                            'bg-yellow-50 dark:bg-yellow-500/10 text-yellow-600 dark:text-yellow-400': event.training_phase.key === 'taper',
                                            'bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400': event.training_phase.key === 'peak',
                                            'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400': event.training_phase.key === 'build',
                                            'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400': event.training_phase.key === 'base',
                                        }">
                                        {{ event.training_phase.label }}
                                    </span>
                                    <span v-if="event.target_time_formatted" class="text-xs text-gray-400 dark:text-slate-500">
                                        Ziel: {{ event.target_time_formatted }}
                                    </span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ═══ ROW 4: Aktivitäten horizontal scroll ═══ -->
                <div v-if="props.recentActivities.length > 0" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-gray-800">Alle Aktivitäten</h4>
                        <div class="flex gap-1.5">
                            <button @click="scrollActivities(-1)" class="h-7 w-7 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 flex items-center justify-center text-sm transition-colors">‹</button>
                            <button @click="scrollActivities(1)" class="h-7 w-7 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 flex items-center justify-center text-sm transition-colors">›</button>
                        </div>
                    </div>
                    <div ref="activitiesScrollRef" class="activities-scroll flex gap-3 overflow-x-auto" style="-ms-overflow-style:none;scrollbar-width:none;">
                        <button
                            v-for="activity in props.recentActivities"
                            :key="activity.id"
                            @click="openActivityDetail(activity)"
                            class="flex-shrink-0 w-44 rounded-xl border border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 p-4 text-left hover:border-indigo-300 dark:hover:border-indigo-500/50 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-all hover:shadow-sm group"
                        >
                            <div class="h-10 w-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-xl mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-500/30 transition-colors">🏃</div>
                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">{{ activity.name }}</p>
                            <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ formatDateShort(activity.start_date) }}</p>
                            <div class="mt-3 flex items-center justify-between">
                                <span class="text-sm font-bold text-blue-600 dark:text-blue-400">{{ round2(formatDistance(activity.distance)) }} km</span>
                                <span class="text-xs text-gray-400 dark:text-slate-500">{{ formatTime(activity.moving_time) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">⚡ {{ formatSpeed(activity.average_speed) }}</p>
                        </button>
                    </div>
                </div>

                <!-- ═══ ROW 4b: Unrated Sessions + Weekly Review ═══ -->
                <div v-if="props.unratedSessions.length > 0 || props.weeklyReview" class="grid grid-cols-1 lg:grid-cols-2 gap-3 lg:gap-4">

                    <!-- Noch zu bewerten -->
                    <div v-if="props.unratedSessions.length > 0" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-amber-100 dark:border-amber-500/20 p-4 sm:p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-7 w-7 rounded-lg bg-amber-100 dark:bg-amber-500/15 flex items-center justify-center text-sm shrink-0">⭐</div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Noch zu bewerten</h4>
                                <p class="text-xs text-gray-400 dark:text-slate-500">Dein Feedback verbessert den KI-Plan</p>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <component
                                :is="session.activity_id ? 'a' : 'a'"
                                v-for="session in props.unratedSessions"
                                :key="session.id"
                                :href="session.activity_id
                                    ? route('activities.show', session.activity_id)
                                    : (session.event_id ? `/events/${session.event_id}/plan` : '#')"
                                class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-3 py-2.5 hover:border-amber-200 dark:hover:border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-500/5 transition-colors group"
                            >
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="shrink-0 text-sm">
                                        {{ {'easy_run':'🟢','tempo_run':'🟡','interval':'🔴','long_run':'🔵','race_prep':'🏁'}[session.type] ?? '🏃' }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200 truncate">{{ session.title || 'Einheit' }}</p>
                                        <p class="text-xs text-gray-400 dark:text-slate-500">
                                            {{ new Date(session.planned_date).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                            {{ session.distance_km ? `· ${session.distance_km} km` : '' }}
                                        </p>
                                    </div>
                                </div>
                                <span class="shrink-0 text-xs text-amber-600 dark:text-amber-400 font-medium group-hover:underline">Bewerten →</span>
                            </component>
                        </div>
                    </div>

                    <!-- Wochenrückblick -->
                    <div v-if="props.weeklyReview" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-indigo-100 dark:border-indigo-500/20 p-4 sm:p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-7 w-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 flex items-center justify-center text-sm shrink-0">🧠</div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">KI Wochenrückblick</h4>
                                <p class="text-xs text-gray-400 dark:text-slate-500">
                                    KW {{ new Date(props.weeklyReview.week_start).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                </p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-700 dark:text-slate-300 leading-relaxed">{{ props.weeklyReview.content }}</p>
                    </div>

                </div>

                <!-- ═══ ROW 5: Quick Event + Countdown + Tipps ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 lg:gap-4">

                    <!-- Quick Event erstellen -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Schnelles Event</h4>
                            <a href="/events" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Alle Events →</a>
                        </div>

                        <!-- Erfolgs-Banner -->
                        <div v-if="quickEventSuccess" class="mb-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 px-3 py-2.5 flex items-center gap-2">
                            <span class="text-green-600 dark:text-green-400 text-sm">✓</span>
                            <p class="text-sm text-green-700 dark:text-green-400 font-medium">Event gespeichert!</p>
                        </div>

                        <div class="space-y-3">
                            <!-- Distanz Buttons -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Distanz</label>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <button v-for="opt in raceOptions" :key="opt.value"
                                        @click="quickEventForm.race_distance = opt.value"
                                        class="py-2 px-3 rounded-xl text-xs font-semibold border transition"
                                        :class="quickEventForm.race_distance === opt.value
                                            ? 'bg-indigo-500 text-white border-indigo-500'
                                            : 'bg-gray-50 dark:bg-slate-800 text-gray-600 dark:text-slate-300 border-gray-200 dark:border-slate-700 hover:border-indigo-300'">
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Datum -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Renndatum *</label>
                                <input type="date" v-model="quickEventForm.event_date"
                                    class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 py-2 px-3 text-sm text-gray-800 dark:text-slate-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 focus:outline-none" />
                            </div>

                            <!-- Zielzeit -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Zielzeit</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="relative">
                                        <input type="number" v-model="quickEventForm.target_time_hours" min="0" max="23"
                                            class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 py-2 pl-3 pr-8 text-sm text-gray-800 dark:text-slate-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 focus:outline-none" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">h</span>
                                    </div>
                                    <div class="relative">
                                        <input type="number" v-model="quickEventForm.target_time_minutes" min="0" max="59"
                                            class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 py-2 pl-3 pr-8 text-sm text-gray-800 dark:text-slate-200 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 focus:outline-none" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">min</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Priorität -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Priorität</label>
                                <div class="flex gap-1.5">
                                    <button v-for="p in ['A','B','C']" :key="p"
                                        @click="quickEventForm.priority = p"
                                        class="flex-1 py-1.5 rounded-lg text-xs font-bold border transition"
                                        :class="quickEventForm.priority === p
                                            ? p === 'A' ? 'bg-red-500 text-white border-red-500'
                                              : p === 'B' ? 'bg-yellow-500 text-white border-yellow-500'
                                              : 'bg-gray-400 text-white border-gray-400'
                                            : 'bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700'">
                                        {{ p }}
                                    </button>
                                </div>
                            </div>

                            <button @click="saveQuickEvent" :disabled="!quickEventForm.event_date || quickEventSaving"
                                class="w-full rounded-xl py-2.5 text-sm font-bold text-white transition disabled:opacity-50"
                                :class="quickEventSaving ? 'bg-indigo-400' : 'bg-indigo-500 hover:bg-indigo-400'">
                                <span v-if="quickEventSaving">Speichern…</span>
                                <span v-else>Event erstellen</span>
                            </button>
                        </div>
                    </div>

                    <!-- Event Countdown -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                        <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200 mb-4">Event Countdown</h4>
                        <div v-if="props.events.length === 0" class="text-sm text-gray-400 dark:text-slate-500 text-center py-8">
                            Kein Event geplant
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="event in props.events.slice(0, 4)" :key="event.id">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs font-semibold text-gray-700 dark:text-slate-300 truncate max-w-[65%]">{{ event.name }}</span>
                                    <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">{{ event.days_until }}d</span>
                                </div>
                                <!-- Progress bar: % of training time elapsed -->
                                <div class="h-2 rounded-full bg-gray-100 dark:bg-slate-700 overflow-hidden">
                                    <div class="h-2 rounded-full transition-all duration-500"
                                        :class="{
                                            'bg-red-500': event.training_phase.key === 'race_week',
                                            'bg-yellow-500': event.training_phase.key === 'taper',
                                            'bg-orange-500': event.training_phase.key === 'peak',
                                            'bg-blue-500': event.training_phase.key === 'build',
                                            'bg-green-500': event.training_phase.key === 'base',
                                        }"
                                        :style="{ width: Math.max(5, 100 - Math.min(100, (event.days_until / 180) * 100)) + '%' }">
                                    </div>
                                </div>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-xs text-gray-400 dark:text-slate-500">{{ event.distance_label }}</span>
                                    <span class="text-xs font-medium"
                                        :class="{
                                            'text-red-500': event.training_phase.key === 'race_week',
                                            'text-yellow-600': event.training_phase.key === 'taper',
                                            'text-orange-600': event.training_phase.key === 'peak',
                                            'text-blue-600': event.training_phase.key === 'build',
                                            'text-green-600': event.training_phase.key === 'base',
                                        }">
                                        {{ event.training_phase.label }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trainings-Tipps / Suggestions -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-semibold text-gray-800">Trainings-Tipps</h4>
                            <span class="text-xs text-indigo-600 font-medium">KI-Empfehlungen</span>
                        </div>
                        <div v-if="!props.suggestions || props.suggestions.length === 0" class="text-sm text-gray-400 text-center py-8">
                            Keine Tipps verfügbar
                        </div>
                        <div v-else class="space-y-3">
                            <div v-for="(tip, i) in props.suggestions" :key="i"
                                class="flex items-start gap-3 pb-3 border-b border-gray-50 last:border-0">
                                <div class="h-8 w-8 rounded-lg bg-amber-100 flex items-center justify-center text-sm flex-shrink-0">💡</div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-800 leading-snug">{{ tip.title || tip }}</p>
                                    <p v-if="tip.description" class="text-xs text-gray-500 mt-0.5">{{ tip.description }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ═══ AI Modal ═══ -->
        <div v-if="showAIModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4">
            <div class="max-h-[92vh] w-full sm:max-w-2xl overflow-y-auto rounded-t-3xl sm:rounded-2xl bg-white dark:bg-slate-900 shadow-2xl">
                <div class="sticky top-0 flex items-center justify-between border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ aiModalType === 'analysis' ? '🤖 KI-Analyse' : '🎯 KI-Trainingsplan' }}
                    </h2>
                    <button @click="closeAIModal" class="h-8 w-8 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700 flex items-center justify-center transition-colors">✕</button>
                </div>
                <div class="p-6">
                    <div v-if="aiLoading" class="flex flex-col items-center justify-center py-16 gap-3">
                        <div class="text-5xl">🤖</div>
                        <p class="text-gray-500 dark:text-slate-400 text-sm">KI denkt nach...</p>
                    </div>
                    <div v-else>
                        <div v-if="aiModalType === 'analysis'" class="rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-100 dark:border-blue-800/30 p-5 text-sm text-gray-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">{{ aiAnalysis }}</div>
                        <div v-if="aiModalType === 'plan'" class="rounded-xl bg-purple-50 dark:bg-purple-500/10 border border-purple-100 dark:border-purple-800/30 p-5 text-sm text-gray-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">{{ aiPlan }}</div>
                    </div>
                    <div class="mt-5 border-t border-gray-100 dark:border-slate-800 pt-5">
                        <button @click="closeAIModal"
                            class="w-full rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                            Schließen
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wellbeing Modal -->
        <!-- Wellbeing / plan-adjust toast -->
        <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-4" leave-active-class="transition-all duration-200" leave-to-class="opacity-0 translate-y-4">
            <div v-if="wellbeingToast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-5 py-3 rounded-2xl shadow-xl text-sm font-medium"
                :class="wellbeingToast.type === 'ai'
                    ? 'bg-indigo-600 text-white'
                    : 'bg-green-600 text-white'">
                <svg v-if="wellbeingToast.type === 'ai'" class="h-4 w-4 animate-spin shrink-0" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                <svg v-else class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                {{ wellbeingToast.message }}
            </div>
        </Transition>

        <WellbeingModal
            :show="showWellbeingModal"
            @close="showWellbeingModal = false"
            @saved="onWellbeingSaved"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.activities-scroll::-webkit-scrollbar {
    display: none;
}
</style>
