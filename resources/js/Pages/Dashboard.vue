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
import { useCoachChat } from '@/Composables/useCoachChat';

const { isDark } = useDarkMode();
const { open: openChat } = useCoachChat();

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
    todayRecommendationSession: {
        type: Object,
        default: null,
    },
    trainingLoad: {
        type: Object,
        default: null,
    },
    coachPrMessage: {
        type: String,
        default: null,
    },
});

// PR banner dismissed state (local — dismissal is persisted server-side)
const prDismissed = ref(false);

async function dismissPr() {
    prDismissed.value = true;
    await axios.post(route('coach.pr.dismiss'));
}

const coachColors = {
    orange: { bg: 'bg-orange-500', light: 'bg-orange-50 dark:bg-orange-500/10', border: 'border-orange-200 dark:border-orange-500/30', text: 'text-orange-700 dark:text-orange-300' },
    blue:   { bg: 'bg-blue-600',   light: 'bg-blue-50 dark:bg-blue-500/10',   border: 'border-blue-200 dark:border-blue-500/30',   text: 'text-blue-700 dark:text-blue-300'   },
    green:  { bg: 'bg-green-600',  light: 'bg-green-50 dark:bg-green-500/10',  border: 'border-green-200 dark:border-green-500/30',  text: 'text-green-700 dark:text-green-300'  },
};

const page = usePage();
const coach = computed(() => page.props.coach ?? null);
const coachColor = computed(() => coachColors[coach.value?.avatar_color] ?? coachColors.blue);
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

const showPaceDetails = ref(false);

// ── Inline-Rating (Noch zu bewerten) ─────────────────────────────────────────
const ratedIds       = ref(new Set());
const ratingOpenId   = ref(null);
const ratingStars    = ref(0);
const ratingEffort   = ref(0);
const ratingNotes    = ref('');
const ratingSavingId = ref(null);

const pendingRatingSessions = computed(() =>
    props.unratedSessions.filter(s => !ratedIds.value.has(s.id))
);

function openRating(session) {
    ratingOpenId.value = session.id;
    ratingStars.value  = 0;
    ratingEffort.value = 0;
    ratingNotes.value  = '';
}

async function submitRating(sessionId) {
    ratingSavingId.value = sessionId;
    try {
        await axios.patch(route('training-sessions.rate', sessionId), {
            rating:           ratingStars.value  || null,
            effort_perceived: ratingEffort.value || null,
            feeling_notes:    ratingNotes.value  || null,
        });
        ratedIds.value = new Set([...ratedIds.value, sessionId]);
        ratingOpenId.value = null;
        router.reload({ only: ['unratedSessions'] });
    } finally {
        ratingSavingId.value = null;
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
            message: (coach.value ? coach.value.name : 'Dein Coach') + ' passt deine heutige Trainingseinheit an deine Tagesform an…',
        };
    } else {
        wellbeingToast.value = {
            type: 'success',
            message: data?.message ?? 'Wellbeing gespeichert! 💪',
        };
    }
    clearTimeout(wellbeingToastTimer);
    wellbeingToastTimer = setTimeout(() => { wellbeingToast.value = null; }, 5000);

    // Immediately fetch the recommendation now that wellbeing is available
    getTodayRecommendation();
}
const syncing = ref(false);

const trainingRecommendation = ref(null); // structured object { type, title, description, distance_km, ... }
const recommendationLoading = ref(false);
const recommendationError = ref(null);
const showRecommendation = ref(false);
const recommendationHint = ref(null);
const recommendationAccepted = ref(false);
const adjustingDirection = ref(null); // 'harder' | 'softer' | null
const acceptingRecommendation = ref(false);

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
    const runSpeeds = weekActs.filter(a => a.type === 'Run' && a.average_speed > 0).map(a => a.average_speed);
    const avgPace = runSpeeds.length
        ? formatPaceFromSpeed(runSpeeds.reduce((s, v) => s + v, 0) / runSpeeds.length)
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

// ── Workout-Struktur für angenommene Empfehlung ──────────────────────────────
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
function recommendationWorkoutSteps(session) {
    if (!session || session.type === 'rest') return [];
    const distKm = session.distance_km || 5;
    const paceSecPerKm = parsePaceToSec(session.pace_target);
    const easySecPerKm = paceSecPerKm ? paceSecPerKm + 60 : null;
    const easyPaceStr  = easySecPerKm ? secToPaceStr(easySecPerKm) : null;
    const configs = {
        easy_run:  { wF: 0.10, wMax: 1.0, cF: 0.10, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Leichtes Einlaufen',           mName: 'Hauptteil',  mLabel: 'Lockeres Dauertempo',      cName: 'Auslaufen', cLabel: 'Leichtes Auslaufen'  },
        tempo_run: { wF: 0.25, wMax: 2.0, cF: 0.12, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Lockeres Einlaufen',           mName: 'Hauptteil',  mLabel: 'Tempodauerlauf',           cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen'  },
        interval:  { wF: 0.20, wMax: 2.0, cF: 0.10, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Lockeres Einlaufen',           mName: 'Intervalle', mLabel: 'Intervallarbeit',          cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen'  },
        long_run:  { wF: 0.05, wMax: 1.0, cF: 0.05, cMax: 1.0, wName: 'Einlaufen',  wLabel: 'Leichtes Einlaufen',           mName: 'Hauptteil',  mLabel: 'Langer gleichmäßiger Lauf',cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen'  },
        race_prep: { wF: 0.30, wMax: 2.0, cF: 0.15, cMax: 1.0, wName: 'Aufwärmen',  wLabel: 'Einlaufen + Strides',          mName: 'Hauptteil',  mLabel: 'Renntempo-Abschnitte',     cName: 'Auslaufen', cLabel: 'Lockeres Auslaufen'  },
    };
    const cfg = configs[session.type] || configs['easy_run'];
    const warmupKm   = Math.min(cfg.wMax, distKm * cfg.wF);
    const cooldownKm = Math.min(cfg.cMax, distKm * cfg.cF);
    const mainKm     = Math.max(0, distKm - warmupKm - cooldownKm);
    return [
        { phase: cfg.wName, label: cfg.wLabel, km: Math.round(warmupKm * 10) / 10,   pace: easyPaceStr, color: 'green' },
        { phase: cfg.mName, label: cfg.mLabel, km: Math.round(mainKm * 10) / 10,     pace: session.pace_target && session.pace_target !== 'null' ? session.pace_target : easyPaceStr, color: 'main' },
        { phase: cfg.cName, label: cfg.cLabel, km: Math.round(cooldownKm * 10) / 10, pace: easyPaceStr, color: 'blue'  },
    ];
}

const showRecommendationDetail = ref(false);

const recNutritionTips    = ref(null);
const recNutritionLoading = ref(false);
const recNutritionError   = ref('');

onMounted(() => {
    if (props.todayRecommendationSession && props.todayRecommendationSession.type !== 'rest') {
        recNutritionLoading.value = true;
        axios.get(route('training-sessions.nutrition-tips', props.todayRecommendationSession.id))
            .then(({ data }) => { recNutritionTips.value = data; })
            .catch(() => { recNutritionError.value = 'Verpflegungstipps konnten nicht geladen werden.'; })
            .finally(() => { recNutritionLoading.value = false; });
    }
});

function activityTypeIcon(type) {
    const icons = {
        Run: '🏃', VirtualRun: '🏃',
        Ride: '🚴', VirtualRide: '🚴', EBikeRide: '🚴',
        Swim: '🏊',
        Walk: '🚶', Hike: '🥾',
        Workout: '💪', WeightTraining: '💪',
        Yoga: '🧘',
        Ski: '⛷️', AlpineSki: '⛷️', NordicSki: '🎿',
        Rowing: '🚣', Kayaking: '🚣',
        Soccer: '⚽', Tennis: '🎾',
    };
    return icons[type] ?? '🏃';
}

function eventRingProps(daysUntil) {
    const progress = Math.min(1, Math.max(0, (180 - daysUntil) / 180));
    const circumference = 2 * Math.PI * 14; // r=14
    const dashOffset = circumference * (1 - progress);
    let ringClass, labelClass;
    if (daysUntil <= 14)  { ringClass = 'text-red-400';    labelClass = 'text-red-300'; }
    else if (daysUntil <= 60)  { ringClass = 'text-orange-400'; labelClass = 'text-orange-300'; }
    else if (daysUntil <= 120) { ringClass = 'text-amber-400';  labelClass = 'text-amber-300'; }
    else                       { ringClass = 'text-indigo-300'; labelClass = 'text-indigo-200'; }
    return { circumference, dashOffset, ringClass, labelClass };
}


function formatPaceFromSpeed(metersPerSecond) {
    if (!metersPerSecond || metersPerSecond <= 0) return '—';
    const secondsPerKm = 1000 / metersPerSecond;
    const minutes = Math.floor(secondsPerKm / 60);
    const seconds = Math.round(secondsPerKm % 60);
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

function formatSwimPace(metersPerSecond) {
    if (!metersPerSecond || metersPerSecond <= 0) return '—';
    const secondsPer100m = 100 / metersPerSecond;
    const minutes = Math.floor(secondsPer100m / 60);
    const seconds = Math.round(secondsPer100m % 60);
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

function activityPaceLabel(activity) {
    if (!activity.average_speed || activity.average_speed <= 0) return null;
    if (activity.type === 'Swim') return `${formatSwimPace(activity.average_speed)}/100m`;
    if (['Ride', 'VirtualRide'].includes(activity.type)) return `${(activity.average_speed * 3.6).toFixed(1)} km/h`;
    return `${formatPaceFromSpeed(activity.average_speed)}/km`;
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
    recommendationAccepted.value = false;
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

async function adjustRecommendation(direction) {
    adjustingDirection.value = direction;
    try {
        const response = await axios.post(route('ai.recommendation.adjust'), {
            direction,
            current: trainingRecommendation.value,
        });
        if (response.data.recommendation) {
            trainingRecommendation.value = response.data.recommendation;
        }
    } catch (error) {
        recommendationError.value = 'Fehler beim Anpassen: ' + (error.response?.data?.message || error.message);
    } finally {
        adjustingDirection.value = null;
    }
}

async function acceptRecommendation() {
    acceptingRecommendation.value = true;
    try {
        await axios.post(route('ai.recommendation.accept'), trainingRecommendation.value);
        recommendationAccepted.value = true;
    } catch (error) {
        recommendationError.value = 'Fehler beim Speichern: ' + (error.response?.data?.message || error.message);
    } finally {
        acceptingRecommendation.value = false;
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

// CTL/ATL chart — dual-line SVG over last 60 days
const loadChartData = computed(() => {
    const history = props.trainingLoad?.history;
    if (!history || history.length < 5) return null;

    const W = 560, H = 80, padX = 4, padY = 6;
    const plotW = W - padX * 2;
    const plotH = H - padY * 2;

    const maxVal = Math.max(...history.map(d => Math.max(d.ctl, d.atl)), 1);

    const toX = (i) => padX + (i / (history.length - 1)) * plotW;
    const toY = (v) => padY + plotH - (v / maxVal) * plotH;

    const ctlPoints = history.map((d, i) => ({ x: toX(i), y: toY(d.ctl) }));
    const atlPoints = history.map((d, i) => ({ x: toX(i), y: toY(d.atl) }));

    const smooth = (pts) => pts.map((p, i) => {
        if (i === 0) return `M ${p.x} ${p.y}`;
        const prev = pts[i - 1];
        const cpX = (prev.x + p.x) / 2;
        return `C ${cpX} ${prev.y}, ${cpX} ${p.y}, ${p.x} ${p.y}`;
    }).join(' ');

    return { ctlPath: smooth(ctlPoints), atlPath: smooth(atlPoints), W, H };
});

const dailyMessage = ref(null);
const dailyMessageLoading = ref(false);

async function fetchDailyMessage() {
    if (!coach.value) return;
    dailyMessageLoading.value = true;
    try {
        const res = await axios.get('/api/ai/daily-message');
        dailyMessage.value = res.data.message ?? null;
    } catch {
        // fallback to tagline
    } finally {
        dailyMessageLoading.value = false;
    }
}

onMounted(() => {
    getTodayRecommendation();
    fetchDailyMessage();
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

                <!-- ─── PR-Glückwunsch vom Coach ─────────────────────────── -->
                <Transition
                    enter-active-class="transition-all duration-400 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="coachPrMessage && !prDismissed"
                        class="relative flex items-start gap-3 rounded-2xl border border-yellow-300 dark:border-yellow-500/40 bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-500/10 dark:to-amber-500/10 p-4"
                    >
                        <div class="text-2xl shrink-0">🏆</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-yellow-800 dark:text-yellow-300 mb-1">
                                {{ coach?.name ?? 'Dein Coach' }} hat eine Nachricht für dich
                            </p>
                            <p class="text-sm text-yellow-900 dark:text-yellow-100 leading-relaxed">{{ coachPrMessage }}</p>
                        </div>
                        <button
                            @click="dismissPr"
                            class="shrink-0 h-6 w-6 flex items-center justify-center rounded-full text-yellow-600 dark:text-yellow-400 hover:bg-yellow-200 dark:hover:bg-yellow-500/20 transition-colors"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </Transition>

                <!-- ─── Coach Badge ──────────────────────────────────────────── -->
                <button
                    v-if="coach"
                    @click="openChat"
                    class="w-full text-left flex items-center gap-3 rounded-2xl border p-3.5 hover:opacity-90 active:scale-[0.99] transition-all"
                    :class="[coachColor.light, coachColor.border]"
                >
                    <div class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm font-bold" :class="coachColor.bg">
                        {{ coach.avatar_initials }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold" :class="coachColor.text">Dein Coach: {{ coach.name }}</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400 italic line-clamp-2">
                            <span v-if="dailyMessageLoading" class="animate-pulse">…</span>
                            <span v-else>„{{ dailyMessage ?? coach.tagline }}"</span>
                        </p>
                    </div>
                    <svg class="shrink-0 h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </button>

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
                            <p class="text-xs text-amber-600 dark:text-amber-400/80 mt-0.5">Tagesform eintragen — {{ coach?.name ?? 'dein Coach' }} passt den Plan automatisch an</p>
                        </div>

                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold px-3 py-1.5 transition-colors">
                            Eintragen
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                            </svg>
                        </span>
                    </button>
                </Transition>

                <!-- ═══ Kein aktiver Plan Banner ═══ -->
                <div v-if="!props.hasActivePlan" class="bg-gradient-to-r from-indigo-50 to-blue-50 dark:from-indigo-500/10 dark:to-blue-500/10 border border-indigo-100 dark:border-indigo-500/20 rounded-2xl p-4 sm:p-5 flex items-center gap-4">
                    <div class="shrink-0 h-11 w-11 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center text-xl">🎯</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Kein aktiver Trainingsplan</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ coach ? coach.name + ' erstellt' : 'Erstelle' }} jetzt einen Trainingsplan für dein nächstes Event – individuell auf deine Daten zugeschnitten.</p>
                    </div>
                    <a href="/events" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2.5 transition-colors shadow-sm">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        Plan erstellen
                    </a>
                </div>

                <!-- ═══ Heute: Trainingseinheit / Coach-Empfehlung ═══ -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">

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

                        <!-- No session today -->
                        <div v-else class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-dashed border-gray-200 dark:border-slate-600 p-6 text-center">
                            <p class="text-sm text-gray-400 dark:text-slate-500">Für heute ist keine Trainingseinheit geplant.</p>
                            <a href="/events" class="mt-2 inline-block text-xs text-indigo-500 hover:underline">Zum Plan →</a>
                        </div>
                    </template>

                    <!-- ── Kein aktiver Plan: Coach-Empfehlung ── -->
                    <template v-else>
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-2">
                                <div v-if="coach" class="shrink-0 h-7 w-7 rounded-lg flex items-center justify-center text-white text-xs font-bold" :class="coachColor.bg">{{ coach.avatar_initials }}</div>
                                <div v-else class="shrink-0 h-7 w-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 flex items-center justify-center text-sm">🧭</div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">{{ coach ? coach.name + 's Empfehlung für heute' : 'Trainings-Empfehlung für heute' }}</h4>
                            </div>
                            <button v-if="!props.todayRecommendationSession" @click="getTodayRecommendation"
                                class="text-xs text-indigo-600 dark:text-indigo-400 font-medium hover:text-indigo-800 bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 rounded-md px-2 py-1 transition-colors">
                                Aktualisieren
                            </button>
                        </div>

                        <!-- ══ Bereits angenommene Empfehlung (nach Reload) ══ -->
                        <div v-if="props.todayRecommendationSession" class="space-y-3">
                            <!-- Session-Karte -->
                            <div class="rounded-xl border overflow-hidden" :class="{
                                'border-green-100 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10':    props.todayRecommendationSession.type === 'easy_run',
                                'border-amber-100 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10':   props.todayRecommendationSession.type === 'tempo_run',
                                'border-red-100 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10':           props.todayRecommendationSession.type === 'interval',
                                'border-blue-100 dark:border-blue-500/20 bg-blue-50 dark:bg-blue-500/10':       props.todayRecommendationSession.type === 'long_run',
                                'border-indigo-100 dark:border-indigo-500/20 bg-indigo-50 dark:bg-indigo-500/10': props.todayRecommendationSession.type === 'race_prep',
                                'border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800':           props.todayRecommendationSession.type === 'rest',
                            }">
                                <div class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <p class="font-semibold text-sm" :class="{
                                            'text-green-700 dark:text-green-400':    props.todayRecommendationSession.type === 'easy_run',
                                            'text-amber-700 dark:text-amber-400':   props.todayRecommendationSession.type === 'tempo_run',
                                            'text-red-700 dark:text-red-400':        props.todayRecommendationSession.type === 'interval',
                                            'text-blue-700 dark:text-blue-400':      props.todayRecommendationSession.type === 'long_run',
                                            'text-indigo-700 dark:text-indigo-400':  props.todayRecommendationSession.type === 'race_prep',
                                            'text-gray-500 dark:text-slate-400':     props.todayRecommendationSession.type === 'rest',
                                        }">{{ props.todayRecommendationSession.title }}</p>
                                        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 shrink-0">✓ Geplant</span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 leading-relaxed mb-2.5">{{ props.todayRecommendationSession.description }}</p>
                                    <div v-if="props.todayRecommendationSession.type !== 'rest'" class="flex flex-wrap gap-3">
                                        <span v-if="props.todayRecommendationSession.distance_km" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c-.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                            {{ props.todayRecommendationSession.distance_km }} km
                                        </span>
                                        <span v-if="props.todayRecommendationSession.duration_min" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
                                            {{ props.todayRecommendationSession.duration_min }} min
                                        </span>
                                        <span v-if="props.todayRecommendationSession.pace_target" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                            {{ props.todayRecommendationSession.pace_target }} min/km
                                        </span>
                                        <span v-if="props.todayRecommendationSession.zone" class="text-xs font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">Zone {{ props.todayRecommendationSession.zone }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Workout-Struktur (aufklappbar) -->
                            <div v-if="props.todayRecommendationSession.type !== 'rest'" class="rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <button
                                    @click="showRecommendationDetail = !showRecommendationDetail"
                                    class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-slate-800 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors text-left"
                                >
                                    <span class="text-xs font-semibold text-gray-600 dark:text-slate-300 uppercase tracking-wider">Trainingsstruktur</span>
                                    <svg class="h-4 w-4 text-gray-400 transition-transform duration-200" :class="showRecommendationDetail ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                <div v-if="showRecommendationDetail" class="divide-y divide-gray-100 dark:divide-slate-700/50">
                                    <div v-for="step in recommendationWorkoutSteps(props.todayRecommendationSession)" :key="step.phase"
                                        class="flex items-center gap-3 px-4 py-2.5">
                                        <div class="h-2 w-2 rounded-full shrink-0"
                                            :class="{
                                                'bg-green-400': step.color === 'green',
                                                'bg-indigo-500': step.color === 'main',
                                                'bg-blue-400': step.color === 'blue',
                                            }"></div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-xs font-semibold text-gray-700 dark:text-slate-200">{{ step.phase }}</p>
                                            <p class="text-xs text-gray-400 dark:text-slate-500">{{ step.label }}</p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-xs font-semibold text-gray-700 dark:text-slate-200 tabular-nums">{{ step.km }} km</p>
                                            <p v-if="step.pace" class="text-xs text-gray-400 dark:text-slate-500 tabular-nums">{{ step.pace }} /km</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Verpflegungsplan -->
                            <div v-if="props.todayRecommendationSession.type !== 'rest'" class="rounded-xl border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <div class="px-4 py-2.5 bg-gray-50 dark:bg-slate-800 border-b border-gray-100 dark:border-slate-700">
                                    <span class="text-xs font-semibold text-gray-600 dark:text-slate-300 uppercase tracking-wider">Verpflegungsplan</span>
                                </div>
                                <div class="px-4 py-3">
                                    <div v-if="recNutritionLoading" class="flex items-center gap-3 py-2 text-sm text-gray-500 dark:text-slate-400">
                                        <svg class="h-4 w-4 animate-spin shrink-0 text-indigo-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                        {{ coach ? coach.name : 'Dein Coach' }} erstellt Verpflegungstipps…
                                    </div>
                                    <p v-else-if="recNutritionError" class="text-xs text-red-500 dark:text-red-400">{{ recNutritionError }}</p>
                                    <div v-else-if="recNutritionTips" class="space-y-2">
                                        <div class="rounded-xl border border-amber-100 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 overflow-hidden">
                                            <div class="flex items-center gap-2 px-3.5 py-2 border-b border-amber-100 dark:border-amber-500/20">
                                                <span>🕐</span>
                                                <span class="text-xs font-bold text-amber-800 dark:text-amber-300 uppercase tracking-wide">Vor dem Training</span>
                                            </div>
                                            <ul class="px-3.5 py-2.5 space-y-1.5">
                                                <li v-for="tip in recNutritionTips.before" :key="tip.text" class="flex items-start gap-2 text-xs text-amber-900 dark:text-amber-200">
                                                    <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                                    <span class="leading-relaxed">{{ tip.text }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="rounded-xl border border-blue-100 dark:border-blue-500/20 bg-blue-50 dark:bg-blue-500/10 overflow-hidden">
                                            <div class="flex items-center gap-2 px-3.5 py-2 border-b border-blue-100 dark:border-blue-500/20">
                                                <span>🏃</span>
                                                <span class="text-xs font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wide">Während des Trainings</span>
                                            </div>
                                            <ul class="px-3.5 py-2.5 space-y-1.5">
                                                <li v-for="tip in recNutritionTips.during" :key="tip.text" class="flex items-start gap-2 text-xs text-blue-900 dark:text-blue-200">
                                                    <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                                    <span class="leading-relaxed">{{ tip.text }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="rounded-xl border border-green-100 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 overflow-hidden">
                                            <div class="flex items-center gap-2 px-3.5 py-2 border-b border-green-100 dark:border-green-500/20">
                                                <span>✅</span>
                                                <span class="text-xs font-bold text-green-800 dark:text-green-300 uppercase tracking-wide">Nach dem Training</span>
                                            </div>
                                            <ul class="px-3.5 py-2.5 space-y-1.5">
                                                <li v-for="tip in recNutritionTips.after" :key="tip.text" class="flex items-start gap-2 text-xs text-green-900 dark:text-green-200">
                                                    <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                                    <span class="leading-relaxed">{{ tip.text }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Aktions-Buttons: Detail + Export -->
                            <div class="flex items-center gap-2">
                                <a :href="route('training-sessions.download', props.todayRecommendationSession.id)"
                                    class="flex-1 flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-3 py-2.5 text-xs font-semibold text-white transition-colors">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                    .fit herunterladen
                                </a>
                            </div>
                        </div>

                        <!-- ══ Empfehlung wird geladen / angezeigt ══ -->
                        <template v-else>
                            <div v-if="recommendationLoading" class="flex items-center gap-3 text-gray-500 dark:text-slate-400 py-4">
                                <span class="text-xl">⏳</span>
                                <span class="text-sm">Empfehlung wird geladen...</span>
                            </div>
                            <div v-else-if="recommendationError" class="rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 p-4 text-sm text-red-700 dark:text-red-400">
                                {{ recommendationError }}
                            </div>

                            <!-- Erfolg nach Annehmen (noch auf gleicher Seite) -->
                            <div v-else-if="recommendationAccepted" class="rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 p-4 flex items-center gap-3">
                                <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center shrink-0 text-white text-base">✓</div>
                                <div>
                                    <p class="text-sm font-semibold text-green-800 dark:text-green-300">Einheit gespeichert!</p>
                                    <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">
                                        <strong>{{ trainingRecommendation?.title }}</strong> wurde in deinen Kalender eingetragen.
                                    </p>
                                </div>
                            </div>

                            <!-- Strukturierte Empfehlungs-Karte -->
                            <div v-else-if="showRecommendation && trainingRecommendation" class="space-y-3">
                                <div class="rounded-xl border overflow-hidden" :class="{
                                    'border-green-100 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10':    trainingRecommendation.type === 'easy_run',
                                    'border-amber-100 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10':   trainingRecommendation.type === 'tempo_run',
                                    'border-red-100 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10':           trainingRecommendation.type === 'interval',
                                    'border-blue-100 dark:border-blue-500/20 bg-blue-50 dark:bg-blue-500/10':       trainingRecommendation.type === 'long_run',
                                    'border-indigo-100 dark:border-indigo-500/20 bg-indigo-50 dark:bg-indigo-500/10': trainingRecommendation.type === 'race_prep',
                                    'border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800':           trainingRecommendation.type === 'rest',
                                }">
                                    <div class="px-4 py-3">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <p class="font-semibold text-sm" :class="{
                                                'text-green-700 dark:text-green-400':    trainingRecommendation.type === 'easy_run',
                                                'text-amber-700 dark:text-amber-400':   trainingRecommendation.type === 'tempo_run',
                                                'text-red-700 dark:text-red-400':        trainingRecommendation.type === 'interval',
                                                'text-blue-700 dark:text-blue-400':      trainingRecommendation.type === 'long_run',
                                                'text-indigo-700 dark:text-indigo-400':  trainingRecommendation.type === 'race_prep',
                                                'text-gray-500 dark:text-slate-400':     trainingRecommendation.type === 'rest',
                                            }">{{ trainingRecommendation.title }}</p>
                                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 shrink-0">{{ coach ? coach.name : 'Coach' }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-slate-400 leading-relaxed mb-2.5">{{ trainingRecommendation.description }}</p>
                                        <div v-if="trainingRecommendation.type !== 'rest'" class="flex flex-wrap gap-3">
                                            <span v-if="trainingRecommendation.distance_km" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c-.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                                {{ trainingRecommendation.distance_km }} km
                                            </span>
                                            <span v-if="trainingRecommendation.duration_min" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
                                                {{ trainingRecommendation.duration_min }} min
                                            </span>
                                            <span v-if="trainingRecommendation.pace_target" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                                {{ trainingRecommendation.pace_target }} min/km
                                            </span>
                                            <span v-if="trainingRecommendation.zone" class="text-xs font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">Zone {{ trainingRecommendation.zone }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Aktions-Buttons -->
                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        @click="adjustRecommendation('softer')"
                                        :disabled="adjustingDirection !== null || acceptingRecommendation"
                                        class="flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-xs font-semibold text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40 transition-colors"
                                    >
                                        <span v-if="adjustingDirection === 'softer'" class="h-3.5 w-3.5 rounded-full border-2 border-gray-400 border-t-transparent animate-spin"></span>
                                        <span v-else class="text-base leading-none">🧘</span>
                                        Lockerer
                                    </button>
                                    <button
                                        @click="acceptRecommendation()"
                                        :disabled="acceptingRecommendation || adjustingDirection !== null"
                                        class="flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 px-3 py-2.5 text-xs font-semibold text-white transition-colors"
                                    >
                                        <span v-if="acceptingRecommendation" class="h-3.5 w-3.5 rounded-full border-2 border-white border-t-transparent animate-spin"></span>
                                        <span v-else>✓</span>
                                        Einplanen
                                    </button>
                                    <button
                                        @click="adjustRecommendation('harder')"
                                        :disabled="adjustingDirection !== null || acceptingRecommendation"
                                        class="flex items-center justify-center gap-1.5 rounded-xl border border-orange-200 dark:border-orange-500/30 bg-orange-50 dark:bg-orange-500/10 px-3 py-2.5 text-xs font-semibold text-orange-700 dark:text-orange-400 hover:bg-orange-100 dark:hover:bg-orange-500/20 disabled:opacity-40 transition-colors"
                                    >
                                        <span v-if="adjustingDirection === 'harder'" class="h-3.5 w-3.5 rounded-full border-2 border-orange-400 border-t-transparent animate-spin"></span>
                                        <span v-else class="text-base leading-none">🔥</span>
                                        Intensiver
                                    </button>
                                </div>
                            </div>

                            <div v-else-if="recommendationHint" class="rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 p-4">
                                <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-1">Hinweis</p>
                                <p class="text-sm text-amber-700 dark:text-amber-400">{{ recommendationHint }}</p>
                                <button @click="showWellbeingModal = true"
                                    class="mt-3 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600 transition-colors">
                                    Jetzt Wellbeing eintragen
                                </button>
                            </div>
                            <div v-else class="text-sm text-gray-400 dark:text-slate-500 text-center py-8">
                                Noch keine Empfehlung verfügbar.
                            </div>
                        </template>
                    </template>
                </div>

                <!-- ═══ ROW 1: Profil + Stats + Kalender ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 lg:gap-4">

                    <!-- ═══ Hero: Profil + Wochenstats (kombiniert) ═══ -->
                    <div class="lg:col-span-8 rounded-2xl overflow-hidden shadow-sm">

                        <!-- Dunkler Header -->
                        <div class="relative bg-gradient-to-br from-slate-800 via-slate-800 to-indigo-900 p-4 sm:p-5">

                            <!-- Zeile 1: Avatar + Name + Status + Buttons -->
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="ring-4 ring-indigo-400 ring-opacity-30 rounded-full shrink-0">
                                        <UserAvatar :user="page.props.auth.user" size="lg" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Strava Konto</p>
                                        <h3 class="text-lg font-bold text-white truncate">{{ props.stravaAccount?.username || 'Nicht verbunden' }}</h3>
                                        <p class="text-xs text-slate-400">{{ props.stravaAccount?.last_synced_at ? 'Sync: ' + props.stravaAccount.last_synced_at : 'Noch nie synchronisiert' }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 flex-wrap justify-end">
                                    <span v-if="props.stravaConnected"
                                        class="inline-flex items-center gap-1 rounded-full bg-green-500 bg-opacity-20 border border-green-500 border-opacity-30 px-2 py-0.5 text-xs font-medium text-green-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-400"></span> Live
                                    </span>
                                    <span v-else
                                        class="inline-flex items-center gap-1 rounded-full bg-red-500 bg-opacity-20 border border-red-500 border-opacity-30 px-2 py-0.5 text-xs font-medium text-red-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-400"></span> Getrennt
                                    </span>
                                    <a v-if="!props.stravaConnected" href="/strava/connect"
                                        class="rounded-xl bg-orange-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-600 transition-colors">
                                        Verbinden
                                    </a>
                                    <button v-else @click="syncStrava" :disabled="syncing || props.thresholdPaceCalculating"
                                        class="rounded-xl bg-indigo-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-400 disabled:opacity-60 transition-colors flex items-center gap-1.5">
                                        <span v-if="syncing" class="h-3 w-3 rounded-full border-2 border-white border-t-transparent animate-spin" />
                                        {{ syncing ? 'Läuft…' : '🔄 Sync' }}
                                    </button>
                                    <a href="/profile/runner"
                                        class="rounded-xl bg-white bg-opacity-10 border border-white border-opacity-20 px-3 py-1.5 text-xs font-semibold text-white hover:bg-opacity-20 transition-colors">
                                        Profil
                                    </a>
                                </div>
                            </div>

                            <!-- Zeile 2: Wochenstats + 7-Tage-Balken -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                <!-- KPIs + Monats-Total -->
                                <div class="space-y-2">
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="rounded-xl bg-white/10 p-3 text-center">
                                            <p class="text-3xl font-black text-white tabular-nums leading-none">{{ weekStats.km }}</p>
                                            <p class="text-[10px] text-indigo-200 font-semibold uppercase tracking-wider mt-1">km / Wo.</p>
                                        </div>
                                        <div class="rounded-xl bg-white/10 p-3 text-center">
                                            <p class="text-3xl font-black text-white leading-none">{{ weekStats.runs }}</p>
                                            <p class="text-[10px] text-indigo-200 font-semibold uppercase tracking-wider mt-1">Läufe</p>
                                        </div>
                                        <div class="rounded-xl bg-white/10 p-3 text-center">
                                            <p class="text-3xl font-black text-white tabular-nums leading-none">{{ weekStats.avgPace }}</p>
                                            <p class="text-[10px] text-indigo-200 font-semibold uppercase tracking-wider mt-1">Ø Pace</p>
                                        </div>
                                    </div>
                                    <div class="rounded-xl bg-white/5 px-3 py-2 flex items-center justify-between">
                                        <div>
                                            <p class="text-[10px] text-indigo-400">Dieser Monat</p>
                                            <p class="text-xs font-semibold text-white">{{ monthStats.km }} km · {{ monthStats.runs }} Läufe</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[10px] text-indigo-400">Gesamt</p>
                                            <p class="text-xs font-semibold text-white">{{ totalDistanceKm }} km</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- 7-Tage-Balken -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-xs font-medium text-indigo-300">Letzte 7 Tage</p>
                                        <a href="/statistics" class="text-xs text-indigo-400 hover:text-indigo-200 transition">Statistiken →</a>
                                    </div>
                                    <div class="flex items-end gap-1.5 h-12">
                                        <div v-for="day in last7DaysBars" :key="day.date"
                                            class="flex-1 flex flex-col items-center gap-1">
                                            <div class="w-full rounded-t-md transition-all"
                                                :class="day.km > 0 ? 'bg-indigo-400' : 'bg-white/10'"
                                                :style="{ height: day.km > 0 ? Math.max(5, (day.km / last7DaysMax) * 40) + 'px' : '3px' }"
                                                :title="day.km > 0 ? day.km + ' km' : 'Kein Lauf'">
                                            </div>
                                            <span class="text-[10px] font-medium"
                                                :class="day.isToday ? 'text-white font-bold' : 'text-indigo-400'">
                                                {{ day.label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Heller Footer: Letzte Läufe -->
                        <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 border-t-0 rounded-b-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Letzte Aktivitäten</h4>
                                <a href="/activities" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Alle →</a>
                            </div>
                            <div v-if="props.recentActivities.length === 0" class="text-sm text-gray-400 dark:text-slate-500 text-center py-4">
                                Noch keine Aktivitäten — Strava verbinden und synchronisieren
                            </div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1">
                                <button
                                    v-for="activity in props.recentActivities.slice(0, 6)"
                                    :key="activity.id"
                                    @click="openActivityDetail(activity)"
                                    class="rounded-xl px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-slate-800/70 text-left transition-colors group border border-transparent hover:border-gray-100 dark:hover:border-slate-700"
                                >
                                    <div class="flex items-start justify-between gap-2 mb-1.5">
                                        <div class="flex items-center gap-1.5 min-w-0">
                                            <span class="text-base leading-none shrink-0">{{ activityTypeIcon(activity.type) }}</span>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate leading-tight">{{ activity.name }}</p>
                                        </div>
                                        <span class="text-[11px] text-gray-400 dark:text-slate-500 flex-shrink-0">{{ relativeDate(activity.start_date) }}</span>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                                            📍 {{ round2(formatDistance(activity.distance)) }} km
                                        </span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-medium">
                                            ⏱ {{ formatTime(activity.moving_time) }}
                                        </span>
                                        <span v-if="activity.average_speed > 0"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-bold"
                                            :class="['Ride','VirtualRide'].includes(activity.type)
                                                ? 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400'
                                                : activity.type === 'Swim'
                                                    ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400'
                                                    : paceColor(activity.average_speed)">
                                            ⚡ {{ activityPaceLabel(activity) }}
                                        </span>
                                        <span v-if="activity.average_heartrate"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 text-xs font-medium">
                                            ❤️ {{ Math.round(activity.average_heartrate) }}
                                        </span>
                                        <span v-if="activity.total_elevation_gain > 0"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 text-xs font-medium">
                                            ↑ {{ Math.round(activity.total_elevation_gain) }}m
                                        </span>
                                    </div>
                                </button>
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
                                    href="/events"
                                    class="flex items-center gap-3 rounded-xl bg-gray-50 dark:bg-slate-800 p-2.5 border border-gray-100 dark:border-slate-700 hover:border-indigo-200 dark:hover:border-indigo-500/40 transition-colors">
                                    <div class="flex-shrink-0 relative h-10 w-10">
                                        <svg viewBox="0 0 36 36" class="h-10 w-10 -rotate-90">
                                            <circle cx="18" cy="18" r="14" fill="none" stroke="currentColor"
                                                stroke-width="3" class="text-gray-200 dark:text-slate-700" />
                                            <circle cx="18" cy="18" r="14" fill="none" stroke="currentColor"
                                                stroke-width="3" stroke-linecap="round"
                                                :stroke-dasharray="eventRingProps(event.days_until).circumference"
                                                :stroke-dashoffset="eventRingProps(event.days_until).dashOffset"
                                                :class="eventRingProps(event.days_until).ringClass"
                                            />
                                        </svg>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-[11px] font-black"
                                                :class="{
                                                    'text-red-500 dark:text-red-400':    event.priority === 'A',
                                                    'text-yellow-500 dark:text-yellow-400': event.priority === 'B',
                                                    'text-gray-400 dark:text-slate-500':  event.priority === 'C',
                                                }">{{ event.priority }}</span>
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
                                    <p class="text-xs font-medium text-indigo-200 uppercase tracking-wider">Berechnete Schwellenpace</p>
                                    <p v-if="props.thresholdPace" class="text-4xl font-bold mt-0.5 tabular-nums">
                                        {{ props.thresholdPace }}
                                        <span class="text-lg font-normal text-indigo-200">min/km</span>
                                    </p>
                                    <p v-else class="text-xl font-semibold mt-0.5 text-indigo-200">Noch nicht berechnet</p>
                                    <p class="text-xs text-indigo-300 mt-1">
                                        <span v-if="props.thresholdPaceCalculating">Analyse läuft im Hintergrund…</span>
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

                        <!-- Toggle: Details anzeigen/verstecken -->
                        <div class="mt-4 pt-3 border-t border-white border-opacity-20 flex items-center justify-between gap-3">
                            <button @click="showPaceDetails = !showPaceDetails"
                                class="flex items-center gap-1.5 text-xs font-medium text-indigo-200 hover:text-white transition-colors">
                                <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="showPaceDetails ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                                {{ showPaceDetails ? 'Weniger anzeigen' : 'Vorhersagen & Verlauf' }}
                            </button>
                            <button v-if="props.stravaConnected" @click="syncStrava" :disabled="syncing || props.thresholdPaceCalculating"
                                class="rounded-lg bg-white bg-opacity-20 hover:bg-opacity-30 disabled:opacity-50 px-3 py-1.5 text-xs font-semibold text-white transition-colors flex-shrink-0 flex items-center gap-1.5">
                                <span v-if="syncing" class="h-3 w-3 rounded-full border-2 border-white border-t-transparent animate-spin inline-block"></span>
                                <span>{{ syncing ? 'Sync…' : '🔄 Sync' }}</span>
                            </button>
                        </div>

                        <!-- Accordion: Race Predictions + Chart -->
                        <Transition
                            enter-active-class="transition-all duration-300 ease-out overflow-hidden"
                            enter-from-class="opacity-0 max-h-0"
                            enter-to-class="opacity-100 max-h-[600px]"
                            leave-active-class="transition-all duration-200 ease-in overflow-hidden"
                            leave-from-class="opacity-100 max-h-[600px]"
                            leave-to-class="opacity-0 max-h-0"
                        >
                        <div v-if="showPaceDetails">
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
                                    <path :d="chartData.fillD" fill="rgba(255,255,255,0.08)" />
                                    <path :d="chartData.pathD" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" stroke-linecap="round" />
                                    <g v-for="(p, i) in chartData.points" :key="i">
                                        <circle :cx="p.x" :cy="p.y" r="4" fill="white" opacity="0.9" />
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
                        </div>
                        </Transition>

                    </div>
                </div>

                <!-- ═══ ROW 3b: Trainingsbelastung (CTL / ATL / TSB) ═══ -->
                <div v-if="props.trainingLoad" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">

                    <!-- Header -->
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="h-7 w-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 flex items-center justify-center shrink-0">
                                <svg class="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Trainingsbelastung</h4>
                                <p class="text-xs text-gray-400 dark:text-slate-500">CTL · ATL · Form (60 Tage)</p>
                            </div>
                        </div>
                        <!-- Form badge -->
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full"
                            :class="{
                                'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400':    props.trainingLoad.form_color === 'red',
                                'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400': props.trainingLoad.form_color === 'orange',
                                'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400':  props.trainingLoad.form_color === 'green',
                                'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400':    props.trainingLoad.form_color === 'blue',
                                'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-400':     props.trainingLoad.form_color === 'gray',
                            }"
                        >{{ props.trainingLoad.form_label }}</span>
                    </div>

                    <!-- Three metric tiles -->
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <!-- CTL -->
                        <div class="rounded-xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20 p-3 text-center">
                            <p class="text-2xl font-bold text-indigo-700 dark:text-indigo-300 tabular-nums">{{ props.trainingLoad.ctl }}</p>
                            <p class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 mt-0.5">CTL</p>
                            <p class="text-[10px] text-indigo-400 dark:text-indigo-500 leading-tight mt-0.5">Fitness</p>
                        </div>
                        <!-- ATL -->
                        <div class="rounded-xl bg-orange-50 dark:bg-orange-500/10 border border-orange-100 dark:border-orange-500/20 p-3 text-center">
                            <p class="text-2xl font-bold text-orange-600 dark:text-orange-400 tabular-nums">{{ props.trainingLoad.atl }}</p>
                            <p class="text-[11px] font-semibold text-orange-600 dark:text-orange-400 mt-0.5">ATL</p>
                            <p class="text-[10px] text-orange-400 dark:text-orange-500 leading-tight mt-0.5">Ermüdung</p>
                        </div>
                        <!-- TSB -->
                        <div class="rounded-xl p-3 text-center border"
                            :class="{
                                'bg-red-50 border-red-100 dark:bg-red-500/10 dark:border-red-500/20':         props.trainingLoad.form_color === 'red',
                                'bg-orange-50 border-orange-100 dark:bg-orange-500/10 dark:border-orange-500/20': props.trainingLoad.form_color === 'orange',
                                'bg-green-50 border-green-100 dark:bg-green-500/10 dark:border-green-500/20':   props.trainingLoad.form_color === 'green',
                                'bg-blue-50 border-blue-100 dark:bg-blue-500/10 dark:border-blue-500/20':      props.trainingLoad.form_color === 'blue',
                                'bg-gray-50 border-gray-100 dark:bg-slate-800 dark:border-slate-700':          props.trainingLoad.form_color === 'gray',
                            }"
                        >
                            <p class="text-2xl font-bold tabular-nums"
                                :class="{
                                    'text-red-600 dark:text-red-400':    props.trainingLoad.form_color === 'red',
                                    'text-orange-600 dark:text-orange-400': props.trainingLoad.form_color === 'orange',
                                    'text-green-600 dark:text-green-400':   props.trainingLoad.form_color === 'green',
                                    'text-blue-600 dark:text-blue-400':     props.trainingLoad.form_color === 'blue',
                                    'text-gray-500 dark:text-slate-400':    props.trainingLoad.form_color === 'gray',
                                }"
                            >{{ props.trainingLoad.tsb > 0 ? '+' : '' }}{{ props.trainingLoad.tsb }}</p>
                            <p class="text-[11px] font-semibold mt-0.5"
                                :class="{
                                    'text-red-600 dark:text-red-400':    props.trainingLoad.form_color === 'red',
                                    'text-orange-600 dark:text-orange-400': props.trainingLoad.form_color === 'orange',
                                    'text-green-600 dark:text-green-400':   props.trainingLoad.form_color === 'green',
                                    'text-blue-600 dark:text-blue-400':     props.trainingLoad.form_color === 'blue',
                                    'text-gray-500 dark:text-slate-400':    props.trainingLoad.form_color === 'gray',
                                }"
                            >TSB</p>
                            <p class="text-[10px] leading-tight mt-0.5"
                                :class="{
                                    'text-red-400 dark:text-red-500':    props.trainingLoad.form_color === 'red',
                                    'text-orange-400 dark:text-orange-500': props.trainingLoad.form_color === 'orange',
                                    'text-green-400 dark:text-green-500':   props.trainingLoad.form_color === 'green',
                                    'text-blue-400 dark:text-blue-500':     props.trainingLoad.form_color === 'blue',
                                    'text-gray-400 dark:text-slate-500':    props.trainingLoad.form_color === 'gray',
                                }"
                            >Form</p>
                        </div>
                    </div>

                    <!-- 60-day CTL / ATL chart -->
                    <div v-if="loadChartData" class="rounded-xl overflow-hidden bg-gray-50 dark:bg-slate-800/50 px-1 pt-1 pb-0">
                        <svg :viewBox="`0 0 ${loadChartData.W} ${loadChartData.H}`" class="w-full" preserveAspectRatio="none">
                            <!-- ATL line (orange) -->
                            <path :d="loadChartData.atlPath" fill="none" stroke="rgb(249,115,22)" stroke-width="1.5" stroke-linecap="round" opacity="0.7" />
                            <!-- CTL line (indigo) -->
                            <path :d="loadChartData.ctlPath" fill="none" stroke="rgb(99,102,241)" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <!-- Legend -->
                        <div class="flex items-center gap-3 px-2 pb-2 pt-1">
                            <span class="flex items-center gap-1 text-[10px] text-gray-500 dark:text-slate-400">
                                <span class="inline-block h-0.5 w-4 rounded bg-indigo-500"></span> Fitness (CTL)
                            </span>
                            <span class="flex items-center gap-1 text-[10px] text-gray-500 dark:text-slate-400">
                                <span class="inline-block h-0.5 w-4 rounded bg-orange-400 opacity-70"></span> Ermüdung (ATL)
                            </span>
                        </div>
                    </div>

                    <!-- Explanation -->
                    <p class="mt-3 text-[11px] text-gray-400 dark:text-slate-500 leading-relaxed">
                        <strong class="text-gray-500 dark:text-slate-400">Form = Fitness − Ermüdung.</strong>
                        Optimal (−10 bis +5): Wettkampfbereit. Belastet (−30 bis −10): Trainingsblock. Frisch (+5 bis +25): Tapering.
                    </p>
                </div>

                <!-- ═══ ROW 4b: Unrated Sessions + Weekly Review ═══ -->
                <div v-if="pendingRatingSessions.length > 0 || props.weeklyReview" class="grid grid-cols-1 lg:grid-cols-2 gap-3 lg:gap-4">

                    <!-- Noch zu bewerten -->
                    <div v-if="pendingRatingSessions.length > 0" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-amber-100 dark:border-amber-500/20 p-4 sm:p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-7 w-7 rounded-lg bg-amber-100 dark:bg-amber-500/15 flex items-center justify-center text-sm shrink-0">⭐</div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Noch zu bewerten</h4>
                                <p class="text-xs text-gray-400 dark:text-slate-500">Dein Feedback verbessert den Plan</p>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div v-for="session in pendingRatingSessions" :key="session.id">
                                <!-- Collapsed row -->
                                <button
                                    v-if="ratingOpenId !== session.id"
                                    @click="openRating(session)"
                                    class="w-full flex items-center justify-between gap-3 rounded-xl border border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-3 py-2.5 hover:border-amber-200 dark:hover:border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-500/5 transition-colors group text-left"
                                >
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span class="shrink-0 text-sm">
                                            {{ {'easy_run':'🟢','tempo_run':'🟡','interval':'🔴','long_run':'🔵','race_prep':'🏁'}[session.type] ?? '🏃' }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-800 dark:text-slate-200 truncate">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                            <p class="text-xs text-gray-400 dark:text-slate-500">
                                                {{ new Date(session.planned_date).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                                {{ session.distance_km ? `· ${session.distance_km} km` : '' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-xs text-amber-600 dark:text-amber-400 font-medium group-hover:underline">Bewerten ↓</span>
                                </button>

                                <!-- Expanded inline rating form -->
                                <div v-else class="rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/5 p-3">
                                    <!-- Session title + close -->
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span class="text-sm shrink-0">{{ {'easy_run':'🟢','tempo_run':'🟡','interval':'🔴','long_run':'🔵','race_prep':'🏁'}[session.type] ?? '🏃' }}</span>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                        </div>
                                        <button @click="ratingOpenId = null" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 text-lg leading-none shrink-0 ml-2">✕</button>
                                    </div>

                                    <!-- Stars -->
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Wie war die Einheit?</p>
                                        <div class="flex items-center gap-1">
                                            <button
                                                v-for="star in 5" :key="star"
                                                @click="ratingStars = ratingStars === star ? 0 : star"
                                                class="h-8 w-8 rounded-lg flex items-center justify-center text-lg transition-all"
                                                :class="star <= ratingStars ? 'bg-amber-100 dark:bg-amber-500/20 scale-110' : 'bg-gray-100 dark:bg-slate-700 opacity-40 hover:opacity-70'"
                                            >⭐</button>
                                            <span class="ml-2 text-xs text-gray-400 dark:text-slate-500">
                                                {{ ['','Sehr schwer','Schwer','Okay','Gut','Top'][ratingStars] }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- RPE -->
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Anstrengung (RPE)</p>
                                        <div class="flex gap-1 flex-wrap">
                                            <button
                                                v-for="n in 10" :key="n"
                                                @click="ratingEffort = ratingEffort === n ? 0 : n"
                                                class="h-7 w-7 rounded-lg text-xs font-bold transition-all"
                                                :class="n === ratingEffort
                                                    ? (n <= 3 ? 'bg-green-500 text-white' : n <= 6 ? 'bg-amber-500 text-white' : 'bg-red-500 text-white')
                                                    : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-600'"
                                            >{{ n }}</button>
                                        </div>
                                    </div>

                                    <!-- Notes (optional) -->
                                    <textarea
                                        v-model="ratingNotes"
                                        rows="2"
                                        placeholder="Notizen (optional)…"
                                        class="w-full rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-2 text-xs text-gray-800 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-200 dark:focus:ring-amber-500/30 resize-none mb-2"
                                    />

                                    <!-- Save -->
                                    <div class="flex items-center gap-2">
                                        <button
                                            @click="submitRating(session.id)"
                                            :disabled="ratingSavingId === session.id || (!ratingStars && !ratingEffort && !ratingNotes)"
                                            class="flex-1 rounded-lg bg-amber-500 hover:bg-amber-600 disabled:opacity-40 px-3 py-1.5 text-xs font-semibold text-white transition-colors flex items-center justify-center gap-1.5"
                                        >
                                            <svg v-if="ratingSavingId === session.id" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                            Speichern
                                        </button>
                                        <a v-if="session.activity_id" :href="route('activities.show', session.activity_id)" class="text-xs text-gray-400 hover:text-indigo-500 transition-colors">Details →</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wochenrückblick -->
                    <div v-if="props.weeklyReview" class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-indigo-100 dark:border-indigo-500/20 p-4 sm:p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div v-if="coach" class="shrink-0 h-9 w-9 rounded-xl flex items-center justify-center text-white text-xs font-bold shadow-sm" :class="coachColor.bg">{{ coach.avatar_initials }}</div>
                            <div v-else class="shrink-0 h-9 w-9 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 flex items-center justify-center text-base">🧠</div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">{{ coach ? coach.name + ' – Wochenrückblick' : 'Wochenrückblick' }}</h4>
                                <p class="text-xs text-gray-400 dark:text-slate-500">
                                    KW {{ new Date(props.weeklyReview.week_start).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                </p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-700 dark:text-slate-300 leading-relaxed italic border-l-2 pl-3" :class="coachColor.border">{{ props.weeklyReview.content }}</p>
                    </div>

                </div>

                <!-- ═══ ROW 5: Quick Event ═══ -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Schnelles Event erstellen</h4>
                        <a href="/events" class="text-xs text-indigo-500 hover:text-indigo-700 font-medium transition">Alle Events →</a>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                    <!-- Formular -->
                    <div>
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

                    <!-- Rechts: Hinweise & Nächste Events -->
                    <div class="flex flex-col gap-4">
                        <!-- Nächster Schritt nach dem Event -->
                        <div class="rounded-xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20 p-4">
                            <p class="text-xs font-bold text-indigo-700 dark:text-indigo-300 mb-2">Nach dem Event: Trainingsplan</p>
                            <p class="text-xs text-indigo-600 dark:text-indigo-400 leading-relaxed">
                                Sobald du ein Event angelegt hast, erstellt {{ coach ? coach.name : 'dein Coach' }} automatisch einen 10-Tages-Trainingsplan — abgestimmt auf deine Schwellenpace und Zielzeit.
                            </p>
                        </div>

                    </div>

                    </div><!-- /inner grid -->
                </div><!-- /outer card -->

            </div>
        </div>

        <!-- ═══ AI Modal ═══ -->
        <div v-if="showAIModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4">
            <div class="max-h-[92vh] w-full sm:max-w-2xl overflow-y-auto rounded-t-3xl sm:rounded-2xl bg-white dark:bg-slate-900 shadow-2xl">
                <div class="sticky top-0 flex items-center justify-between border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ aiModalType === 'analysis' ? '📊 Analyse' : ('🎯 ' + (coach ? coach.name + 's Trainingsplan' : 'Trainingsplan')) }}
                    </h2>
                    <button @click="closeAIModal" class="h-8 w-8 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700 flex items-center justify-center transition-colors">✕</button>
                </div>
                <div class="p-6">
                    <div v-if="aiLoading" class="flex flex-col items-center justify-center py-16 gap-3">
                        <div v-if="coach" class="w-16 h-16 rounded-2xl flex items-center justify-center text-white font-bold text-xl" :class="coachColor.bg">{{ coach.avatar_initials }}</div>
                        <div v-else class="text-5xl">🏃</div>
                        <p class="text-gray-500 dark:text-slate-400 text-sm">{{ coach ? coach.name + ' analysiert…' : 'Wird berechnet…' }}</p>
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
