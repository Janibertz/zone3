<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WellbeingSheet from '@/Components/WellbeingSheet.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import GarminSendSheet from '@/Components/UI/GarminSendSheet.vue';
import GarminRecovery from '@/Components/GarminRecovery.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Inertia } from '@inertiajs/inertia';
import { router } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import { useCoachChat } from '@/Composables/useCoachChat';
import SessionCard from '@/Components/UI/SessionCard.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { sessionType } from '@/Composables/useSessionTypes';

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
    aiUsage: {
        type: Object,
        default: () => ({ used: 0, limit: 20 }),
    },
    returnToRun: {
        type: Object,
        default: null,
    },
    garminMetrics: {
        type: Object,
        default: null,
    },
    recoveryActivities: {
        type: Array,
        default: () => [],
    },
});

// PR banner dismissed state (local — dismissal is persisted server-side)
const prDismissed = ref(false);

async function dismissPr() {
    prDismissed.value = true;
    await axios.post(route('coach.pr.dismiss'));
}

// Return-to-run card dismissed state (local — dismissal is persisted server-side)
const returnToRunDismissed = ref(false);

async function dismissReturnToRun() {
    returnToRunDismissed.value = true;
    await axios.post(route('return-to-run.dismiss'));
}

const coachColors = {
    orange: { bg: 'bg-orange-500', light: 'bg-orange-50 dark:bg-orange-500/10', border: 'border-orange-200 dark:border-orange-500/30', text: 'text-orange-700 dark:text-orange-300' },
    blue:   { bg: 'bg-blue-600',   light: 'bg-blue-50 dark:bg-blue-500/10',   border: 'border-blue-200 dark:border-blue-500/30',   text: 'text-blue-700 dark:text-blue-300'   },
    green:  { bg: 'bg-green-600',  light: 'bg-green-50 dark:bg-green-500/10',  border: 'border-green-200 dark:border-green-500/30',  text: 'text-green-700 dark:text-green-300'  },
    purple: { bg: 'bg-purple-600', light: 'bg-purple-50 dark:bg-purple-500/10', border: 'border-purple-200 dark:border-purple-500/30', text: 'text-purple-700 dark:text-purple-300' },
};

const page = usePage();
const coach = computed(() => page.props.coach ?? null);
const coachColor = computed(() => coachColors[coach.value?.avatar_color] ?? coachColors.blue);
const flash = page.props?.flash || {};

// Flash-Meldungen blenden sich nach ein paar Sekunden selbst aus.
const showFlash = ref(true);

function resetFlash() {
    showFlash.value = true;
    setTimeout(() => { showFlash.value = false; }, 4500);
}

watch(
    () => page.props.flash,
    () => {
        if (flash.success || flash.error) resetFlash();
    },
    { immediate: true }
);

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

// Garmin send for recommendation session
const garminModal    = ref(false);
const garminSending  = ref(false);
const garminSuccess  = ref(false);
const garminError    = ref('');
const garminConnected  = computed(() => !!usePage().props.auth?.garminConnected);
const garminSavedEmail = computed(() => usePage().props.auth?.garminEmail);

function openGarminModal() {
    garminModal.value    = true;
    garminError.value    = '';
    garminSuccess.value  = false;
}

async function sendToGarminConnect({ email, password } = {}) {
    garminSending.value = true;
    garminError.value   = '';
    garminSuccess.value = false;
    try {
        const payload = garminConnected.value ? {} : { email, password };
        await axios.post(
            route('training-sessions.send-to-garmin', props.todayRecommendationSession.id),
            payload
        );
        garminSuccess.value = true;
        setTimeout(() => { garminModal.value = false; garminSuccess.value = false; }, 2500);
    } catch (err) {
        const detail = err.response?.data?.error || '';
        if (detail.startsWith('login_failed:')) {
            garminError.value = 'Login fehlgeschlagen: ' + detail.replace('login_failed:', '').trim();
        } else if (detail === 'session_expired') {
            garminError.value = 'Sitzung abgelaufen. Bitte erneut einloggen.';
        } else if (detail === '2fa_required') {
            garminError.value = 'Zwei-Faktor-Authentifizierung aktiv. Bitte deaktiviere 2FA temporär in deinem Garmin-Account.';
        } else {
            garminError.value = detail || 'Verbindung fehlgeschlagen';
        }
    } finally {
        garminSending.value = false;
    }
}

// ── Begrüßung ────────────────────────────────────────────────────────────────
const firstName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0] || 'Läufer');

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 5)  return 'Noch wach';
    if (h < 11) return 'Guten Morgen';
    if (h < 14) return 'Mahlzeit';
    if (h < 18) return 'Guten Tag';
    return 'Guten Abend';
});

const todayLabel = computed(() =>
    new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })
);

// ── Streak: aufeinanderfolgende Tage mit Aktivität, ab heute oder gestern ────
// Startet bei gestern, damit ein noch nicht gelaufener Tag die Serie nicht bricht.
const streakDays = computed(() => {
    const days = new Set(
        props.recentActivities
            .filter(a => a.start_date)
            .map(a => dayKey(new Date(a.start_date)))
    );
    if (!days.size) return 0;

    const cursor = new Date();
    if (!days.has(dayKey(cursor))) cursor.setDate(cursor.getDate() - 1);

    let count = 0;
    while (days.has(dayKey(cursor)) && count < 400) {
        count++;
        cursor.setDate(cursor.getDate() - 1);
    }
    return count;
});

// ── Vergleich zur Vorwoche ───────────────────────────────────────────────────
const lastWeekKm = computed(() => {
    const now = new Date();
    const dow = (now.getDay() + 6) % 7;

    const thisWeekStart = new Date(now);
    thisWeekStart.setDate(now.getDate() - dow);
    thisWeekStart.setHours(0, 0, 0, 0);

    const lastWeekStart = new Date(thisWeekStart);
    lastWeekStart.setDate(thisWeekStart.getDate() - 7);

    const meters = props.recentActivities
        .filter(a => {
            if (!a.start_date) return false;
            const d = new Date(a.start_date);
            return d >= lastWeekStart && d < thisWeekStart;
        })
        .reduce((s, a) => s + (a.distance || 0), 0);

    return meters / 1000;
});

/** Prozentuale Veränderung zur Vorwoche — null, wenn es keine Basis gibt. */
const weekTrend = computed(() => {
    const last = lastWeekKm.value;
    if (last < 0.5) return null;
    const diff = ((parseFloat(weekStats.value.km) - last) / last) * 100;
    return Math.round(diff);
});

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
    const runs = weekActs.filter(a => ['Run', 'VirtualRun', 'TrailRun'].includes(a.type)).length;
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
        runs: acts.filter(a => ['Run', 'VirtualRun', 'TrailRun'].includes(a.type)).length,
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
const recNutritionTips    = ref(null);
const recNutritionLoading = ref(false);
const recNutritionError   = ref('');

const recSteps        = ref(null);
const recStepsLoading = ref(false);
const recStepsError   = ref('');

const stepBarColor = {
    warmup:   'bg-success',
    work:     'bg-danger',
    rest:     'bg-ink-3',
    cooldown: 'bg-info',
};
const stepBgColor = {
    warmup:   'bg-success-soft border-success/20',
    work:     'bg-danger-soft border-danger/20',
    rest:     'bg-surface-2 border-line',
    cooldown: 'bg-info-soft border-info/20',
};
const stepLabel    = { warmup: 'Aufwärmen', work: 'Hauptteil', rest: 'Pause', cooldown: 'Auslaufen' };
const stepHeightPct = { warmup: 60, work: 100, rest: 28, cooldown: 60 };

const recStepsWithReps = computed(() => {
    if (!recSteps.value) return [];
    const expanded = [];
    let i = 0;
    while (i < recSteps.value.length) {
        const s = recSteps.value[i];
        const reps = s.repetitions && s.repetitions > 1 ? s.repetitions : 1;
        if (s.type === 'work' && reps > 1) {
            const rest = recSteps.value[i + 1]?.type === 'rest' ? recSteps.value[i + 1] : null;
            for (let r = 0; r < reps; r++) { expanded.push(s); if (rest) expanded.push(rest); }
            i += rest ? 2 : 1;
        } else if (s.type === 'rest' && (s.repetitions || 1) > 1) {
            i++;
        } else {
            for (let r = 0; r < reps; r++) expanded.push(s);
            i++;
        }
    }
    return expanded;
});

const recTotalStepDuration = computed(() =>
    recStepsWithReps.value.reduce((sum, s) => sum + (s.duration_min || 0), 0)
);

const recGroupedSteps = computed(() => {
    if (!recSteps.value) return [];
    const result = [];
    let i = 0;
    while (i < recSteps.value.length) {
        const s = recSteps.value[i];
        if (s.type === 'work' && s.repetitions && s.repetitions > 1) {
            const rest = recSteps.value[i + 1]?.type === 'rest' ? recSteps.value[i + 1] : null;
            result.push({ ...s, pairedRest: rest, isGroup: true });
            if (rest) i += 2; else i++;
        } else {
            result.push({ ...s, isGroup: false });
            i++;
        }
    }
    return result;
});

const weather = ref(null);

onMounted(() => {
    // Weather chip — loaded async so it never blocks the dashboard render.
    axios.get(route('weather.today'))
        .then(({ data }) => { weather.value = data.weather; })
        .catch(() => {});

    const rec = props.todayRecommendationSession;
    if (rec && rec.type !== 'rest') {
        recNutritionLoading.value = true;
        axios.get(route('training-sessions.nutrition-tips', rec.id))
            .then(({ data }) => { recNutritionTips.value = data; })
            .catch(() => { recNutritionError.value = 'Verpflegungstipps konnten nicht geladen werden.'; })
            .finally(() => { recNutritionLoading.value = false; });

        if (rec.type !== 'race_prep') {
            recStepsLoading.value = true;
            axios.get(route('training-sessions.steps', rec.id))
                .then(({ data }) => { recSteps.value = data; })
                .catch(() => { recStepsError.value = 'Struktur konnte nicht geladen werden.'; })
                .finally(() => { recStepsLoading.value = false; });
        }
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
    // Je näher das Event, desto dringlicher die Farbe.
    let ringClass;
    if (daysUntil <= 14)       ringClass = 'text-danger';
    else if (daysUntil <= 60)  ringClass = 'text-warn';
    else if (daysUntil <= 120) ringClass = 'text-info';
    else                       ringClass = 'text-accent';
    return { circumference, dashOffset, ringClass };
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
    // Normalize to calendar days (local time) to avoid off-by-one from sub-24h differences
    const dDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const nowDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.round((nowDay - dDay) / 86400000);
    if (diffDays === 0) return 'Heute';
    if (diffDays === 1) return 'Gestern';
    if (diffDays < 7)  return `vor ${diffDays} Tagen`;
    if (diffDays < 14) return 'vor 1 Woche';
    if (diffDays < 30) return `vor ${Math.floor(diffDays / 7)} Wochen`;
    return d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });
}

// Pace color: schnell = grün, langsam = orange
function paceColor(mps) {
    if (!mps || mps <= 0) return 'bg-surface-2 text-ink-3';
    const secPerKm = 1000 / mps;
    if (secPerKm < 270) return 'bg-success-soft text-success-ink'; // < 4:30
    if (secPerKm < 330) return 'bg-info-soft text-info-ink';       // < 5:30
    if (secPerKm < 390) return 'bg-warn-soft text-warn-ink';       // < 6:30
    return 'bg-danger-soft text-danger-ink';
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

    // Show fewer pace labels when many points to avoid clutter
    const labelStep = points.length <= 8 ? 1 : points.length <= 15 ? 2 : points.length <= 25 ? 4 : 6;

    return { points, pathD, fillD, W, H, minPace, maxPace, padX, padY, labelStep };
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

// ── Garmin recovery data (HRV, sleep, RHR, Body Battery, stress, readiness) ──
const garminSyncing = ref(false);
const garminSeries  = computed(() => props.garminMetrics?.series ?? []);
const garminLatest  = computed(() => props.garminMetrics?.latest ?? null);
const hasGarminData = computed(() => garminSeries.value.length > 0);

function syncGarmin() {
    garminSyncing.value = true;
    axios.post('/profile/garmin-sync-health', { days: 7 })
        .catch(() => {})
        .finally(() => {
            // The sync runs in the queue; give it a moment, then refresh the prop.
            setTimeout(() => {
                garminSyncing.value = false;
                router.reload({ only: ['garminMetrics'] });
            }, 5000);
        });
}

// Build one metric tile: latest value, sparkline path (gaps preserved), and a
// period-over-period delta that is only highlighted on a meaningful move (>0.6 SD).
function buildGarminTile(key, opts) {
    const series = garminSeries.value;
    const vals = series.map(d => d[key]).filter(v => v !== null && v !== undefined);
    const base = { ...opts, value: vals.length ? vals[vals.length - 1] : null, spark: null, delta: null, highlight: false };
    if (vals.length < 2) return base;

    const mean = arr => arr.reduce((a, b) => a + b, 0) / arr.length;
    const half = Math.floor(vals.length / 2);
    const delta = mean(vals.slice(half)) - mean(vals.slice(0, half));
    const m = mean(vals);
    const sd = Math.sqrt(mean(vals.map(v => (v - m) ** 2)));
    const highlight = sd > 0 && Math.abs(delta) > 0.6 * sd;

    // Sparkline across the full series; a null value breaks the line (no interpolation).
    const W = 100, H = 28, pad = 2;
    const min = Math.min(...vals), max = Math.max(...vals);
    const range = (max - min) || 1;
    const n = series.length;
    let d = '', open = false;
    series.forEach((row, i) => {
        const v = row[key];
        if (v === null || v === undefined) { open = false; return; }
        const x = pad + (n > 1 ? (i / (n - 1)) : 0) * (W - pad * 2);
        const y = pad + (H - pad * 2) - ((v - min) / range) * (H - pad * 2);
        d += (open ? ' L ' : ' M ') + x.toFixed(1) + ' ' + y.toFixed(1);
        open = true;
    });

    return { ...base, delta, highlight, spark: d };
}

const garminTiles = computed(() => {
    if (!hasGarminData.value) return [];
    return [
        buildGarminTile('hrv',         { label: 'HRV',      color: '#3D7BFF', unit: ' ms',  digits: 0 }),
        buildGarminTile('resting_hr',  { label: 'Ruhepuls', color: '#F0402F', unit: ' bpm', digits: 0, invert: true }),
        buildGarminTile('sleep_hours', { label: 'Schlaf',   color: '#FFC400', unit: ' h',   digits: 1 }),
        buildGarminTile('stress_avg',  { label: 'Stress',   color: '#00C46A', unit: '',     digits: 0, invert: true }),
    ];
});

const readinessLabel = computed(() => {
    const r = garminLatest.value?.training_readiness;
    if (r == null) return '';
    if (r >= 75) return 'bereit für harte Reize';
    if (r >= 50) return 'moderat belastbar';
    if (r >= 25) return 'eher locker halten';
    return 'Erholung priorisieren';
});

const readinessColor = computed(() => {
    const r = garminLatest.value?.training_readiness ?? 0;
    if (r >= 75) return '#00C46A';
    if (r >= 50) return '#FFC400';
    if (r >= 25) return '#F59E0B';
    return '#F0402F';
});

function garminDeltaClass(t) {
    // invert=true → an increase is unfavourable (resting HR, stress)
    const good = t.invert ? t.delta < 0 : t.delta > 0;
    return good ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
}

</script>

<template>
    <Head title="Übersicht" />

    <AuthenticatedLayout>

        <!-- ══════════════════════════════════════════════════════════
             HERO — Begrüßung, Streak, Woche auf einen Blick
             ══════════════════════════════════════════════════════════ -->
        <section class="relative overflow-hidden bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600">
            <!-- Lichtakzent -->
            <div class="pointer-events-none absolute -right-16 -top-24 h-64 w-64 rounded-full bg-white/10 blur-2xl" />
            <div class="pointer-events-none absolute -bottom-32 -left-10 h-64 w-64 rounded-full bg-black/10 blur-2xl" />

            <div class="relative mx-auto max-w-7xl px-4 pb-6 pt-6 lg:px-8 lg:pb-8 lg:pt-10">

                <!-- Zeile 1: Gruß + Avatar -->
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-white/70">{{ todayLabel }}</p>
                        <h1 class="mt-0.5 truncate text-3xl font-black tracking-tight text-white lg:text-4xl">
                            {{ greeting }}, {{ firstName }}
                        </h1>

                        <!-- Wetter + Streak -->
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span v-if="weather" class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm">
                                <span>{{ weather.emoji }}</span>
                                <span class="tabular-nums">{{ weather.temp_c }}°</span>
                                <span class="font-medium text-white/75">{{ weather.description }}</span>
                                <span v-if="weather.precip_prob != null && weather.precip_prob >= 30" class="text-white/75">· 💧 {{ weather.precip_prob }}%</span>
                                <span v-if="weather.wind_kmh != null && weather.wind_kmh >= 25" class="text-white/75">· 💨 {{ weather.wind_kmh }}</span>
                            </span>
                            <span v-if="streakDays >= 2" class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm">
                                🔥 {{ streakDays }} Tage in Folge
                            </span>
                        </div>
                    </div>

                    <div class="shrink-0 rounded-full ring-4 ring-white/25">
                        <UserAvatar :user="page.props.auth.user" size="lg" />
                    </div>
                </div>

                <!-- Zeile 2: Woche in Zahlen -->
                <div class="mt-6 grid grid-cols-3 gap-3 lg:max-w-2xl">
                    <div class="rounded-card bg-white/12 px-3 py-3 backdrop-blur-sm">
                        <p class="text-3xl font-black leading-none tracking-tight text-white tabular-nums lg:text-4xl">{{ weekStats.km }}</p>
                        <p class="mt-1.5 text-[10px] font-bold uppercase tracking-widest text-white/60">km diese Woche</p>
                        <p v-if="weekTrend !== null" class="mt-1 text-[11px] font-semibold"
                            :class="weekTrend >= 0 ? 'text-emerald-200' : 'text-white/60'">
                            {{ weekTrend >= 0 ? '▲' : '▼' }} {{ Math.abs(weekTrend) }}% zur Vorwoche
                        </p>
                    </div>
                    <div class="rounded-card bg-white/12 px-3 py-3 backdrop-blur-sm">
                        <p class="text-3xl font-black leading-none tracking-tight text-white tabular-nums lg:text-4xl">{{ weekStats.runs }}</p>
                        <p class="mt-1.5 text-[10px] font-bold uppercase tracking-widest text-white/60">Einheiten</p>
                    </div>
                    <div class="rounded-card bg-white/12 px-3 py-3 backdrop-blur-sm">
                        <p class="text-3xl font-black leading-none tracking-tight text-white tabular-nums lg:text-4xl">{{ weekStats.avgPace }}</p>
                        <p class="mt-1.5 text-[10px] font-bold uppercase tracking-widest text-white/60">Ø Pace</p>
                    </div>
                </div>

                <!-- Zeile 3: 7-Tage-Balken + Gesamtzahlen -->
                <div class="mt-4 lg:max-w-2xl">
                    <div class="flex h-14 items-end gap-1.5">
                        <div v-for="day in last7DaysBars" :key="day.date" class="flex flex-1 flex-col items-center gap-1.5">
                            <div class="w-full rounded-t-md transition-all duration-300"
                                :class="day.km > 0 ? 'bg-white/80' : 'bg-white/15'"
                                :style="{ height: day.km > 0 ? Math.max(6, (day.km / last7DaysMax) * 44) + 'px' : '4px' }"
                                :title="day.km > 0 ? day.km + ' km' : 'Kein Lauf'" />
                            <span class="text-[10px] font-bold" :class="day.isToday ? 'text-white' : 'text-white/45'">{{ day.label }}</span>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-4 text-xs text-white/60">
                        <span>Monat <strong class="font-bold text-white/90 tabular-nums">{{ monthStats.km }} km</strong> · {{ monthStats.runs }} Einheiten</span>
                        <span class="ml-auto">Gesamt <strong class="font-bold text-white/90 tabular-nums">{{ totalDistanceKm }} km</strong></span>
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-4 lg:px-8 lg:py-6">

            <!-- ── Meldungen ─────────────────────────────────────────── -->
            <div v-if="props.syncResult" class="flex items-start gap-3 rounded-card border border-accent/25 bg-accent-soft px-4 py-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm text-accent-ink">{{ props.syncResult }}</p>
            </div>
            <div v-if="flash.success && showFlash" class="rounded-card border border-success/25 bg-success-soft p-4">
                <p class="text-sm font-medium text-success-ink">{{ flash.success }}</p>
            </div>
            <div v-if="flash.error && showFlash" class="rounded-card border border-danger/25 bg-danger-soft p-4">
                <p class="text-sm font-medium text-danger-ink">{{ flash.error }}</p>
            </div>

            <!-- ── PR-Glückwunsch ───────────────────────────────────── -->
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 scale-95"
                leave-active-class="transition-all duration-200 ease-in"
                leave-to-class="opacity-0 scale-95"
            >
                <div v-if="coachPrMessage && !prDismissed"
                    class="relative flex items-start gap-3 rounded-card border border-warn/30 bg-warn-soft p-4">
                    <div class="shrink-0 text-2xl">🏆</div>
                    <div class="min-w-0 flex-1">
                        <p class="mb-1 text-xs font-bold uppercase tracking-wide text-warn-ink">
                            {{ coach?.name ?? 'Dein Coach' }} hat eine Nachricht
                        </p>
                        <p class="text-sm leading-relaxed text-ink">{{ coachPrMessage }}</p>
                    </div>
                    <button @click="dismissPr" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-warn-ink transition-colors hover:bg-warn/15">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </Transition>

            <!-- ── Wiedereinstieg ───────────────────────────────────── -->
            <AppCard v-if="returnToRun && !returnToRunDismissed">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 text-2xl">🔄</div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-ink">Wiedereinstieg nach {{ returnToRun.trigger_label }}</p>
                            <span class="shrink-0 rounded-full bg-info-soft px-2 py-0.5 text-xs font-semibold text-info-ink">
                                Stufe {{ returnToRun.step }}/{{ returnToRun.total_steps }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-ink-3">Behutsam zurück — Erholung ist Training.</p>

                        <div class="mt-3 flex items-center gap-1.5">
                            <div v-for="s in returnToRun.steps" :key="s.n"
                                class="h-1.5 flex-1 rounded-full"
                                :class="s.n <= returnToRun.step ? 'bg-info' : 'bg-surface-3'" />
                        </div>

                        <div class="mt-3 rounded-field border border-line bg-surface-2 p-3">
                            <p class="text-xs font-semibold text-ink">{{ returnToRun.current.label }}</p>
                            <p class="mt-0.5 text-xs text-ink-2">{{ returnToRun.current.rule }}</p>
                            <div v-if="returnToRun.current.max_min" class="mt-2 flex flex-wrap gap-2 text-[11px] text-ink-3">
                                <span class="rounded bg-surface-3 px-2 py-0.5">⏱️ max. {{ returnToRun.current.max_min }} min</span>
                                <span v-if="returnToRun.current.zone" class="rounded bg-surface-3 px-2 py-0.5">❤️ Zone {{ returnToRun.current.zone }}</span>
                            </div>
                        </div>

                        <button @click="dismissReturnToRun" class="mt-3 text-xs font-semibold text-info hover:underline">
                            Wiedereinstieg abschließen
                        </button>
                    </div>
                </div>
            </AppCard>

            <!-- ── Coach-Nachricht des Tages ────────────────────────── -->
            <button v-if="coach" @click="openChat"
                class="flex w-full items-start gap-3 rounded-card border border-line bg-surface p-4 text-left shadow-card transition-all hover:border-line-strong active:scale-[0.99]">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-field text-sm font-bold text-white"
                    :style="`background-color: ${coach.avatar_color}`">
                    {{ coach.avatar_initials }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-bold uppercase tracking-wide text-ink-3">{{ coach.name }}</p>
                    <p class="mt-0.5 text-sm leading-relaxed text-ink">
                        <span v-if="dailyMessageLoading" class="animate-pulse text-ink-3">…</span>
                        <span v-else>„{{ dailyMessage ?? coach.tagline }}"</span>
                    </p>
                </div>
                <svg class="mt-1 h-4 w-4 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </button>

            <!-- ── Wellbeing-Erinnerung ─────────────────────────────── -->
            <button v-if="!wellbeingEnteredToday" @click="showWellbeingModal = true"
                class="flex w-full items-center gap-3 rounded-card border border-warn/30 bg-warn-soft p-4 text-left transition-all active:scale-[0.99]">
                <div class="shrink-0 text-2xl">💪</div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-warn-ink">Wie fühlst du dich heute?</p>
                    <p class="mt-0.5 text-xs text-ink-2">30 Sekunden — danach passt {{ coach ? coach.name : 'dein Coach' }} das Training an deine Tagesform an.</p>
                </div>
                <span class="shrink-0 rounded-full bg-warn px-3 py-1.5 text-xs font-bold text-white">Los</span>
            </button>

            <!-- ══════════════════════════════════════════════════════
                 HEUTE
                 ══════════════════════════════════════════════════════ -->
            <section>
                <div class="mb-2.5 flex items-end justify-between gap-3">
                    <h2 class="text-lg font-black tracking-tight text-ink">Heute</h2>
                    <a v-if="props.hasActivePlan"
                        :href="props.todayPlanSession?.event_id ? `/events/${props.todayPlanSession.event_id}/plan` : '/events'"
                        class="text-sm font-semibold text-accent hover:underline">Zum Plan →</a>
                    <span v-else class="text-xs text-ink-3">{{ props.aiUsage.used }}/{{ props.aiUsage.limit }} KI heute</span>
                </div>

                <!-- ── Aktiver Plan ─────────────────────────────────── -->
                <template v-if="props.hasActivePlan">
                    <SessionCard v-if="props.todayPlanSession"
                        :session="props.todayPlanSession"
                        :badge="props.todayPlanSession.status === 'completed' ? '✓ Erledigt'
                            : props.todayPlanSession.is_today ? 'Heute'
                            : new Date(props.todayPlanSession.session_date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: 'short' })"
                        :badge-tone="props.todayPlanSession.status === 'completed' ? 'success' : props.todayPlanSession.is_today ? 'accent' : 'neutral'"
                        :href="props.todayPlanSession.event_id ? `/events/${props.todayPlanSession.event_id}/plan?open=${props.todayPlanSession.id}` : '/events'"
                    />
                    <AppCard v-else>
                        <EmptyState title="Heute steht nichts an" description="Erholung gehört zum Training. Genieß den freien Tag.">
                            <AppButton href="/events" variant="secondary">Zum Plan</AppButton>
                        </EmptyState>
                    </AppCard>
                </template>

                <!-- ── Ohne Plan: Coach-Empfehlung ──────────────────── -->
                <template v-else>
                    <!-- Bereits eingeplant -->
                    <div v-if="props.todayRecommendationSession" class="space-y-3">
                        <SessionCard :session="props.todayRecommendationSession" badge="✓ Geplant" badge-tone="success" />

                        <!-- Trainingsstruktur -->
                        <AppCard
                            v-if="props.todayRecommendationSession.type !== 'rest' && props.todayRecommendationSession.type !== 'race_prep'"
                            title="Trainingsstruktur"
                        >
                            <div v-if="recStepsLoading" class="flex items-center gap-2 py-2 text-xs text-ink-3">
                                <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Struktur wird geladen…
                            </div>
                            <p v-else-if="recStepsError" class="text-xs text-danger">{{ recStepsError }}</p>
                            <div v-else-if="recSteps && recSteps.length">
                                <!-- Verlaufsbalken -->
                                <div class="mb-3 flex h-12 items-end gap-0.5">
                                    <div
                                        v-for="(s, i) in recStepsWithReps"
                                        :key="i"
                                        :style="{
                                            width:  ((s.duration_min || 0) / recTotalStepDuration * 100).toFixed(1) + '%',
                                            height: (stepHeightPct[s.type] ?? 60) + '%',
                                        }"
                                        :class="[stepBarColor[s.type] ?? 'bg-accent', 'rounded-t-sm']"
                                        :title="`${s.label}: ${s.duration_min} min`"
                                    />
                                </div>
                                <!-- Schritte -->
                                <div class="space-y-2">
                                    <div v-for="(step, idx) in recGroupedSteps" :key="idx"
                                        class="rounded-field border p-3" :class="stepBgColor[step.type] ?? 'border-line bg-surface-2'">
                                        <template v-if="step.isGroup">
                                            <div class="mb-2 flex items-center gap-2">
                                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-danger text-[10px] font-bold text-white">×{{ step.repetitions }}</span>
                                                <span class="text-sm font-bold text-ink">{{ step.repetitions }}× Intervall</span>
                                            </div>
                                            <div class="ml-7 space-y-1.5">
                                                <div class="flex items-center gap-3">
                                                    <span class="h-2 w-2 shrink-0 rounded-full bg-danger" />
                                                    <span class="text-xs font-medium text-ink-2">{{ step.label }}</span>
                                                    <span class="ml-auto text-xs text-ink-3">{{ step.duration_min }} min</span>
                                                    <span v-if="step.pace_target" class="text-xs font-bold text-ink">{{ step.pace_target }}/km</span>
                                                </div>
                                                <div v-if="step.pairedRest" class="flex items-center gap-3">
                                                    <span class="h-2 w-2 shrink-0 rounded-full bg-ink-3" />
                                                    <span class="text-xs text-ink-3">{{ step.pairedRest.label }}</span>
                                                    <span class="ml-auto text-xs text-ink-3">{{ step.pairedRest.duration_min }} min</span>
                                                </div>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="flex items-center gap-3">
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="stepBarColor[step.type]" />
                                                <div class="min-w-0 flex-1">
                                                    <span class="text-[11px] font-bold uppercase tracking-wide text-ink-3">{{ stepLabel[step.type] ?? step.type }}</span>
                                                    <span class="ml-1.5 text-sm font-medium text-ink">{{ step.label }}</span>
                                                </div>
                                                <span class="shrink-0 text-xs text-ink-3">{{ step.duration_min }} min</span>
                                                <span v-if="step.pace_target" class="shrink-0 text-xs font-bold text-ink">{{ step.pace_target }}/km</span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </AppCard>

                        <!-- Verpflegung -->
                        <AppCard v-if="props.todayRecommendationSession.type !== 'rest'" title="Verpflegungsplan">
                            <div v-if="recNutritionLoading" class="flex items-center gap-3 py-2 text-sm text-ink-3">
                                <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                {{ coach ? coach.name : 'Dein Coach' }} erstellt Verpflegungstipps…
                            </div>
                            <p v-else-if="recNutritionError" class="text-xs text-danger">{{ recNutritionError }}</p>
                            <div v-else-if="recNutritionTips" class="space-y-2">
                                <div v-for="block in [
                                        { key: 'before', icon: '🕐', title: 'Vor dem Training', cls: 'border-warn/25 bg-warn-soft',    ink: 'text-warn-ink'    },
                                        { key: 'during', icon: '🏃', title: 'Während',          cls: 'border-info/25 bg-info-soft',    ink: 'text-info-ink'    },
                                        { key: 'after',  icon: '✅', title: 'Nach dem Training', cls: 'border-success/25 bg-success-soft', ink: 'text-success-ink' },
                                    ]" :key="block.key"
                                    class="overflow-hidden rounded-field border" :class="block.cls">
                                    <div class="flex items-center gap-2 px-3.5 py-2">
                                        <span>{{ block.icon }}</span>
                                        <span class="text-[11px] font-bold uppercase tracking-wide" :class="block.ink">{{ block.title }}</span>
                                    </div>
                                    <ul class="space-y-1.5 px-3.5 pb-2.5">
                                        <li v-for="tip in recNutritionTips[block.key]" :key="tip.text" class="flex items-start gap-2 text-xs leading-relaxed text-ink-2">
                                            <span class="shrink-0">{{ tip.icon }}</span>
                                            <span>{{ tip.text }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </AppCard>

                        <AppButton v-if="props.todayRecommendationSession.type !== 'rest'" block @click="openGarminModal">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                            Zu Garmin senden
                        </AppButton>
                    </div>

                    <!-- Empfehlung laden / anzeigen -->
                    <template v-else>
                        <AppCard v-if="recommendationLoading">
                            <div class="flex items-center gap-3 py-4 text-sm text-ink-3">
                                <svg class="h-5 w-5 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                {{ coach ? coach.name + ' überlegt…' : 'Empfehlung wird geladen…' }}
                            </div>
                        </AppCard>

                        <div v-else-if="recommendationError" class="rounded-card border border-danger/25 bg-danger-soft p-4 text-sm text-danger-ink">
                            {{ recommendationError }}
                        </div>

                        <div v-else-if="recommendationAccepted" class="flex items-center gap-3 rounded-card border border-success/25 bg-success-soft p-4">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success text-base text-white">✓</div>
                            <div>
                                <p class="text-sm font-bold text-success-ink">Eingeplant!</p>
                                <p class="mt-0.5 text-xs text-ink-2"><strong>{{ trainingRecommendation?.title }}</strong> steht in deinem Kalender.</p>
                            </div>
                        </div>

                        <div v-else-if="showRecommendation && trainingRecommendation" class="space-y-3">
                            <SessionCard :session="trainingRecommendation" :badge="coach ? coach.name : 'Coach'" badge-tone="accent" />

                            <div class="grid grid-cols-3 gap-2">
                                <button @click="adjustRecommendation('softer')"
                                    :disabled="adjustingDirection !== null || acceptingRecommendation"
                                    class="flex flex-col items-center justify-center gap-1 rounded-field border border-line bg-surface px-3 py-3 text-xs font-bold text-ink-2 transition-all hover:border-line-strong active:scale-95 disabled:opacity-40">
                                    <span v-if="adjustingDirection === 'softer'" class="h-4 w-4 animate-spin rounded-full border-2 border-ink-3 border-t-transparent" />
                                    <span v-else class="text-lg leading-none">🧘</span>
                                    Lockerer
                                </button>
                                <button @click="acceptRecommendation()"
                                    :disabled="acceptingRecommendation || adjustingDirection !== null"
                                    class="flex flex-col items-center justify-center gap-1 rounded-field bg-accent px-3 py-3 text-xs font-bold text-white transition-all hover:opacity-90 active:scale-95 disabled:opacity-40">
                                    <span v-if="acceptingRecommendation" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                                    <span v-else class="text-lg leading-none">✓</span>
                                    Einplanen
                                </button>
                                <button @click="adjustRecommendation('harder')"
                                    :disabled="adjustingDirection !== null || acceptingRecommendation"
                                    class="flex flex-col items-center justify-center gap-1 rounded-field border border-warn/30 bg-warn-soft px-3 py-3 text-xs font-bold text-warn-ink transition-all active:scale-95 disabled:opacity-40">
                                    <span v-if="adjustingDirection === 'harder'" class="h-4 w-4 animate-spin rounded-full border-2 border-warn border-t-transparent" />
                                    <span v-else class="text-lg leading-none">🔥</span>
                                    Intensiver
                                </button>
                            </div>
                        </div>

                        <div v-else-if="recommendationHint" class="rounded-card border border-warn/30 bg-warn-soft p-4">
                            <p class="text-sm font-bold text-warn-ink">Fast geschafft</p>
                            <p class="mt-1 text-sm text-ink-2">{{ recommendationHint }}</p>
                            <AppButton size="sm" class="mt-3" @click="showWellbeingModal = true">Jetzt Wellbeing eintragen</AppButton>
                        </div>

                        <AppCard v-else>
                            <EmptyState title="Noch keine Empfehlung" description="Trag dein Wellbeing ein, dann schlägt dir dein Coach eine passende Einheit vor.">
                                <AppButton @click="getTodayRecommendation">Empfehlung holen</AppButton>
                            </EmptyState>
                        </AppCard>
                    </template>
                </template>
            </section>

            <!-- ── Kein Plan: Hinweis ───────────────────────────────── -->
            <AppCard v-if="!props.hasActivePlan" tappable href="/events">
                <div class="flex items-center gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-field bg-accent-soft text-xl">🎯</div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-ink">Noch kein Trainingsplan</p>
                        <p class="mt-0.5 text-xs text-ink-3">
                            {{ coach ? coach.name + ' erstellt' : 'Erstelle' }} dir einen Plan fürs nächste Event — auf deine Werte zugeschnitten.
                        </p>
                    </div>
                    <svg class="h-4 w-4 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                </div>
            </AppCard>

            <!-- ── Garmin-Erholung ──────────────────────────────────── -->
            <GarminRecovery v-if="props.garminMetrics" :metrics="props.garminMetrics" :activities="props.recoveryActivities" />

            <!-- ══════════════════════════════════════════════════════
                 FORM & BELASTUNG
                 ══════════════════════════════════════════════════════ -->
            <section v-if="props.trainingLoad">
                <div class="mb-2.5 flex items-end justify-between gap-3">
                    <h2 class="text-lg font-black tracking-tight text-ink">Form</h2>
                    <span class="rounded-full px-2.5 py-1 text-xs font-bold"
                        :class="{
                            'bg-danger-soft text-danger-ink':   props.trainingLoad.form_color === 'red',
                            'bg-warn-soft text-warn-ink':       props.trainingLoad.form_color === 'orange',
                            'bg-success-soft text-success-ink': props.trainingLoad.form_color === 'green',
                            'bg-info-soft text-info-ink':       props.trainingLoad.form_color === 'blue',
                            'bg-surface-2 text-ink-2':          props.trainingLoad.form_color === 'gray',
                        }">{{ props.trainingLoad.form_label }}</span>
                </div>

                <AppCard>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-field bg-accent-soft p-3 text-center">
                            <p class="text-2xl font-black tabular-nums text-accent-ink">{{ props.trainingLoad.ctl }}</p>
                            <p class="mt-0.5 text-[11px] font-bold text-accent-ink">CTL</p>
                            <p class="text-[10px] text-ink-3">Fitness</p>
                        </div>
                        <div class="rounded-field bg-warn-soft p-3 text-center">
                            <p class="text-2xl font-black tabular-nums text-warn-ink">{{ props.trainingLoad.atl }}</p>
                            <p class="mt-0.5 text-[11px] font-bold text-warn-ink">ATL</p>
                            <p class="text-[10px] text-ink-3">Ermüdung</p>
                        </div>
                        <div class="rounded-field p-3 text-center"
                            :class="{
                                'bg-danger-soft':  props.trainingLoad.form_color === 'red',
                                'bg-warn-soft':    props.trainingLoad.form_color === 'orange',
                                'bg-success-soft': props.trainingLoad.form_color === 'green',
                                'bg-info-soft':    props.trainingLoad.form_color === 'blue',
                                'bg-surface-2':    props.trainingLoad.form_color === 'gray',
                            }">
                            <p class="text-2xl font-black tabular-nums text-ink">{{ props.trainingLoad.tsb > 0 ? '+' : '' }}{{ props.trainingLoad.tsb }}</p>
                            <p class="mt-0.5 text-[11px] font-bold text-ink-2">TSB</p>
                            <p class="text-[10px] text-ink-3">Form</p>
                        </div>
                    </div>

                    <div v-if="loadChartData" class="mt-4 overflow-hidden rounded-field bg-surface-2 px-1 pt-1">
                        <svg :viewBox="`0 0 ${loadChartData.W} ${loadChartData.H}`" class="w-full" preserveAspectRatio="none">
                            <path :d="loadChartData.atlPath" fill="none" stroke="rgb(var(--z-warn))" stroke-width="1.5" stroke-linecap="round" opacity="0.8" />
                            <path :d="loadChartData.ctlPath" fill="none" stroke="rgb(var(--z-accent))" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <div class="flex items-center gap-3 px-2 pb-2 pt-1">
                            <span class="flex items-center gap-1 text-[10px] text-ink-3">
                                <span class="inline-block h-0.5 w-4 rounded bg-accent" /> Fitness
                            </span>
                            <span class="flex items-center gap-1 text-[10px] text-ink-3">
                                <span class="inline-block h-0.5 w-4 rounded bg-warn" /> Ermüdung
                            </span>
                        </div>
                    </div>

                    <p class="mt-3 text-[11px] leading-relaxed text-ink-3">
                        <strong class="text-ink-2">Form = Fitness − Ermüdung.</strong>
                        Optimal (−10 bis +5): wettkampfbereit. Belastet (−30 bis −10): Trainingsblock. Frisch (+5 bis +25): Tapering.
                    </p>
                </AppCard>
            </section>

            <!-- ══════════════════════════════════════════════════════
                 SCHWELLENPACE
                 ══════════════════════════════════════════════════════ -->
            <section>
                <h2 class="mb-2.5 text-lg font-black tracking-tight text-ink">Dein Tempo</h2>

                <AppCard>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-field bg-accent-soft text-3xl">⚡</div>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Schwellenpace</p>
                                <p v-if="props.thresholdPace" class="mt-0.5 flex items-baseline gap-1.5">
                                    <span class="text-4xl font-black tabular-nums tracking-tight text-ink">{{ props.thresholdPace }}</span>
                                    <span class="text-sm font-semibold text-ink-3">min/km</span>
                                </p>
                                <p v-else class="mt-0.5 text-lg font-bold text-ink-3">Noch nicht berechnet</p>
                                <p class="mt-1 text-xs text-ink-3">
                                    <span v-if="props.thresholdPaceCalculating">Analyse läuft im Hintergrund…</span>
                                    <span v-else-if="props.thresholdPaceCalculatedAt">{{ props.thresholdPaceCalculatedAt }} · letzte 20 Läufe</span>
                                    <span v-else>Wird nach dem nächsten Strava-Sync berechnet</span>
                                </p>
                            </div>
                        </div>

                        <div v-if="props.paceZones" class="grid w-full grid-cols-5 gap-1.5 sm:min-w-[300px] sm:max-w-sm">
                            <div v-for="(zone, key) in props.paceZones" :key="key" class="rounded-field bg-surface-2 p-2 text-center">
                                <p class="text-[11px] font-black uppercase text-ink-2">{{ key }}</p>
                                <p class="mt-0.5 font-mono text-[11px] leading-tight text-ink-3">
                                    {{ zone.min_pace }}<br><span class="opacity-70">{{ zone.max_pace }}</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-line pt-3">
                        <button @click="showPaceDetails = !showPaceDetails"
                            class="flex items-center gap-1.5 text-xs font-bold text-accent transition-colors hover:underline">
                            <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="showPaceDetails ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                            {{ showPaceDetails ? 'Weniger' : 'Prognosen & Verlauf' }}
                        </button>
                        <AppButton v-if="props.stravaConnected" variant="secondary" size="sm"
                            :loading="syncing" :disabled="props.thresholdPaceCalculating" @click="syncStrava">
                            {{ syncing ? 'Sync…' : 'Sync' }}
                        </AppButton>
                    </div>

                    <div v-if="showPaceDetails">
                        <div v-if="props.racePredictions" class="mt-4 border-t border-line pt-4">
                            <p class="mb-2 text-[11px] font-bold uppercase tracking-widest text-ink-3">🏁 Wettkampf-Prognosen</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div v-for="(pred, key) in props.racePredictions" :key="key" class="rounded-field bg-surface-2 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold text-ink-3">{{ pred.label }}</p>
                                    <p class="mt-0.5 text-xl font-black tabular-nums text-ink">{{ pred.total_time }}</p>
                                    <p class="font-mono text-[11px] text-ink-3">{{ pred.pace }} /km</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="chartData" class="mt-4 border-t border-line pt-4">
                            <p class="mb-3 text-[11px] font-bold uppercase tracking-widest text-ink-3">📈 Entwicklung</p>
                            <div class="w-full overflow-hidden rounded-field bg-surface-2" style="aspect-ratio: 560 / 120;">
                                <svg :viewBox="`0 0 ${chartData.W} ${chartData.H}`" class="h-full w-full" preserveAspectRatio="none">
                                    <path :d="chartData.fillD" fill="rgb(var(--z-accent) / 0.12)" />
                                    <path :d="chartData.pathD" fill="none" stroke="rgb(var(--z-accent))" stroke-width="2" stroke-linecap="round" />
                                    <g v-for="(p, i) in chartData.points" :key="i">
                                        <circle :cx="p.x" :cy="p.y" r="4" fill="rgb(var(--z-accent))" />
                                        <text v-if="i === 0 || i === chartData.points.length - 1 || i % chartData.labelStep === 0"
                                            :x="p.x" :y="p.y - 8" text-anchor="middle" font-size="9"
                                            fill="rgb(var(--z-ink-2))" font-family="monospace">{{ p.pace }}</text>
                                        <text v-if="i === 0 || i === chartData.points.length - 1"
                                            :x="p.x" :y="chartData.H - 2" text-anchor="middle" font-size="8"
                                            fill="rgb(var(--z-ink-3))">{{ p.date }}</text>
                                    </g>
                                </svg>
                            </div>
                            <p class="mt-1 text-center text-xs text-ink-3">{{ props.thresholdPaceHistory.length }} Messpunkte · oben = schneller</p>
                        </div>
                        <p v-else-if="props.thresholdPace" class="mt-3 text-xs text-ink-3">
                            Diagramm erscheint, sobald Daten über mehr als 7 Tage vorliegen<span v-if="props.thresholdPaceHistory.length > 0"> · {{ props.thresholdPaceHistory.length }} Messung(en)</span>.
                        </p>
                    </div>
                </AppCard>
            </section>

            <!-- ══════════════════════════════════════════════════════
                 BEWERTEN + WOCHENRÜCKBLICK
                 ══════════════════════════════════════════════════════ -->
            <div v-if="pendingRatingSessions.length > 0 || props.weeklyReview" class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                <section v-if="pendingRatingSessions.length > 0">
                    <h2 class="mb-2.5 text-lg font-black tracking-tight text-ink">Wie lief's?</h2>
                    <AppCard subtitle="Dein Feedback macht den nächsten Plan besser">
                        <div class="space-y-2">
                            <div v-for="session in pendingRatingSessions" :key="session.id">
                                <button v-if="ratingOpenId !== session.id" @click="openRating(session)"
                                    class="flex w-full items-center justify-between gap-3 rounded-field border border-line bg-surface-2 px-3 py-3 text-left transition-colors hover:border-warn/40">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <span class="shrink-0 text-base">{{ sessionType(session.type).emoji }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-ink">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                            <p class="text-xs text-ink-3">
                                                {{ new Date(session.planned_date).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                                {{ session.distance_km ? `· ${session.distance_km} km` : '' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-warn px-2.5 py-1 text-[11px] font-bold text-white">Bewerten</span>
                                </button>

                                <div v-else class="rounded-field border border-warn/30 bg-warn-soft p-3">
                                    <div class="mb-3 flex items-center justify-between">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="shrink-0 text-base">{{ sessionType(session.type).emoji }}</span>
                                            <p class="truncate text-sm font-bold text-ink">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                        </div>
                                        <button @click="ratingOpenId = null" class="ml-2 shrink-0 text-lg leading-none text-ink-3 hover:text-ink">✕</button>
                                    </div>

                                    <p class="mb-1.5 text-xs font-semibold text-ink-2">Wie war die Einheit?</p>
                                    <div class="mb-3 flex items-center gap-1">
                                        <button v-for="star in 5" :key="star"
                                            @click="ratingStars = ratingStars === star ? 0 : star"
                                            class="flex h-9 w-9 items-center justify-center rounded-field text-lg transition-all"
                                            :class="star <= ratingStars ? 'scale-110 bg-warn/20' : 'bg-surface-2 opacity-40 hover:opacity-70'"
                                        >⭐</button>
                                        <span class="ml-2 text-xs text-ink-3">{{ ['','Sehr schwer','Schwer','Okay','Gut','Top'][ratingStars] }}</span>
                                    </div>

                                    <p class="mb-1.5 text-xs font-semibold text-ink-2">Anstrengung (RPE)</p>
                                    <div class="mb-3 flex flex-wrap gap-1">
                                        <button v-for="n in 10" :key="n"
                                            @click="ratingEffort = ratingEffort === n ? 0 : n"
                                            class="h-8 w-8 rounded-field text-xs font-bold transition-all active:scale-90"
                                            :class="n === ratingEffort
                                                ? (n <= 3 ? 'bg-success text-white' : n <= 6 ? 'bg-warn text-white' : 'bg-danger text-white')
                                                : 'bg-surface-2 text-ink-3 hover:bg-surface-3'"
                                        >{{ n }}</button>
                                    </div>

                                    <textarea v-model="ratingNotes" rows="2" placeholder="Notizen (optional)…" class="z-input mb-2 resize-none text-xs" />

                                    <div class="flex items-center gap-2">
                                        <AppButton size="sm" block
                                            :loading="ratingSavingId === session.id"
                                            :disabled="!ratingStars && !ratingEffort && !ratingNotes"
                                            @click="submitRating(session.id)">Speichern</AppButton>
                                        <a v-if="session.activity_id" :href="route('activities.show', session.activity_id)" class="shrink-0 text-xs text-ink-3 hover:text-accent">Details →</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </AppCard>
                </section>

                <section v-if="props.weeklyReview">
                    <h2 class="mb-2.5 text-lg font-black tracking-tight text-ink">Wochenrückblick</h2>
                    <AppCard>
                        <div class="mb-3 flex items-center gap-3">
                            <div v-if="coach" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-field text-xs font-bold text-white"
                                :style="`background-color: ${coach.avatar_color}`">{{ coach.avatar_initials }}</div>
                            <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-field bg-accent-soft text-base">🧠</div>
                            <div>
                                <p class="text-sm font-bold text-ink">{{ coach ? coach.name : 'Dein Coach' }}</p>
                                <p class="text-xs text-ink-3">ab {{ new Date(props.weeklyReview.week_start).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}</p>
                            </div>
                        </div>
                        <p class="border-l-2 border-accent pl-3 text-sm italic leading-relaxed text-ink-2">{{ props.weeklyReview.content }}</p>
                    </AppCard>
                </section>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 LETZTE AKTIVITÄTEN
                 ══════════════════════════════════════════════════════ -->
            <section>
                <div class="mb-2.5 flex items-end justify-between gap-3">
                    <h2 class="text-lg font-black tracking-tight text-ink">Zuletzt gelaufen</h2>
                    <a href="/activities" class="text-sm font-semibold text-accent hover:underline">Alle →</a>
                </div>

                <AppCard v-if="props.recentActivities.length === 0">
                    <EmptyState title="Noch keine Aktivitäten" description="Verbinde Strava und synchronisiere, dann erscheinen deine Läufe hier.">
                        <AppButton v-if="!props.stravaConnected" href="/strava/connect">Strava verbinden</AppButton>
                        <AppButton v-else :loading="syncing" @click="syncStrava">Jetzt synchronisieren</AppButton>
                    </EmptyState>
                </AppCard>

                <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <button
                        v-for="activity in props.recentActivities.slice(0, 6)"
                        :key="activity.id"
                        @click="openActivityDetail(activity)"
                        class="rounded-card border border-line bg-surface p-3.5 text-left shadow-card transition-all hover:border-line-strong active:scale-[0.99]"
                    >
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="shrink-0 text-lg leading-none">{{ activityTypeIcon(activity.type) }}</span>
                                <p class="truncate text-sm font-bold leading-tight text-ink">{{ activity.name }}</p>
                            </div>
                            <span class="shrink-0 text-[11px] font-medium text-ink-3">{{ relativeDate(activity.start_date) }}</span>
                        </div>

                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span v-if="activity.distance > 0" class="flex items-baseline gap-1">
                                <span class="text-xl font-black tabular-nums leading-none text-ink">{{ round2(formatDistance(activity.distance)) }}</span>
                                <span class="text-[11px] font-semibold text-ink-3">km</span>
                            </span>
                            <span v-else-if="['WeightTraining','Workout'].includes(activity.type)" class="text-sm font-bold text-ink">💪 Kraft</span>

                            <span class="flex items-baseline gap-1">
                                <span class="text-sm font-bold tabular-nums text-ink-2">{{ formatTime(activity.moving_time) }}</span>
                            </span>
                            <span v-if="activity.average_speed > 0"
                                class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums"
                                :class="['Ride','VirtualRide'].includes(activity.type)
                                    ? 'bg-success-soft text-success-ink'
                                    : activity.type === 'Swim'
                                        ? 'bg-info-soft text-info-ink'
                                        : paceColor(activity.average_speed)">
                                {{ activityPaceLabel(activity) }}
                            </span>
                            <span v-if="activity.average_heartrate" class="text-xs font-semibold text-danger">❤️ {{ Math.round(activity.average_heartrate) }}</span>
                            <span v-if="activity.total_elevation_gain > 0" class="text-xs font-semibold text-warn-ink">↑ {{ Math.round(activity.total_elevation_gain) }}m</span>
                        </div>
                    </button>
                </div>
            </section>

            <!-- ══════════════════════════════════════════════════════
                 KALENDER + EVENTS
                 ══════════════════════════════════════════════════════ -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                <section>
                    <div class="mb-2.5 flex items-end justify-between gap-3">
                        <h2 class="text-lg font-black tracking-tight text-ink">Monat</h2>
                        <a href="/calendar" class="text-sm font-semibold text-accent hover:underline">Vollansicht →</a>
                    </div>

                    <AppCard>
                        <div class="mb-3 flex items-center justify-center gap-2">
                            <button @click="prevMonth" class="flex h-8 w-8 items-center justify-center rounded-field text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink">‹</button>
                            <h4 class="min-w-[140px] text-center text-sm font-bold text-ink">{{ currentMonthLabel }}</h4>
                            <button @click="nextMonth" class="flex h-8 w-8 items-center justify-center rounded-field text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink">›</button>
                        </div>

                        <div class="mb-1 grid grid-cols-7 gap-0.5 text-center text-[11px] font-bold text-ink-3">
                            <div>Mo</div><div>Di</div><div>Mi</div><div>Do</div><div>Fr</div><div>Sa</div><div>So</div>
                        </div>
                        <div class="grid grid-cols-7 gap-0.5 text-center">
                            <div v-for="(d, i) in calendarDays" :key="i"
                                class="relative mx-auto flex h-9 w-9 flex-col items-center justify-center rounded-full text-xs transition-colors"
                                :class="{
                                    'bg-accent font-bold text-white': d.isToday && !d.hasEvent,
                                    'bg-warn font-bold text-white':   d.hasEvent,
                                    'text-ink-3 opacity-40':          !d.currentMonth,
                                    'text-ink hover:bg-surface-2':    d.currentMonth && !d.isToday && !d.hasEvent,
                                    'cursor-pointer':                 d.hasActivity || d.hasEvent,
                                }"
                                :title="d.hasEvent ? d.event.name : ''"
                                @click="openCalendarDay(d)">
                                {{ d.day }}
                                <span v-if="d.hasActivity && !d.isToday && !d.hasEvent" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-accent" />
                                <span v-if="d.hasActivity && (d.isToday || d.hasEvent)" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-white/80" />

                                <div v-if="d.hasActivity && calendarPickerDay === d.day"
                                    class="absolute top-10 left-1/2 z-30 w-52 -translate-x-1/2 rounded-card border border-line bg-surface py-1 text-left shadow-sheet"
                                    @click.stop>
                                    <p class="border-b border-line px-3 py-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-3">
                                        {{ activeDaysInMonth.get(d.day)?.length }} Aktivitäten
                                    </p>
                                    <button v-for="act in activeDaysInMonth.get(d.day)" :key="act.id"
                                        @click="pickCalendarActivity(act)"
                                        class="w-full px-3 py-2 text-left transition-colors hover:bg-surface-2">
                                        <p class="truncate text-xs font-semibold text-ink">{{ act.name }}</p>
                                        <p class="text-xs text-ink-3"><template v-if="act.distance > 0">{{ formatDistance(act.distance) }} km · </template>{{ formatTime(act.moving_time) }}</p>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </AppCard>
                </section>

                <section>
                    <div class="mb-2.5 flex items-end justify-between gap-3">
                        <h2 class="text-lg font-black tracking-tight text-ink">Nächste Events</h2>
                        <a href="/events" class="text-sm font-semibold text-accent hover:underline">Alle →</a>
                    </div>

                    <AppCard v-if="props.events.length === 0">
                        <EmptyState title="Kein Event geplant" description="Ein Ziel im Kalender macht jedes Training konkreter.">
                            <AppButton href="/events">Event anlegen</AppButton>
                        </EmptyState>
                    </AppCard>

                    <div v-else class="space-y-2">
                        <a v-for="event in props.events.slice(0, 3)" :key="event.id" href="/events"
                            class="flex items-center gap-3 rounded-card border border-line bg-surface p-3 shadow-card transition-all hover:border-line-strong">
                            <div class="relative h-11 w-11 shrink-0">
                                <svg viewBox="0 0 36 36" class="h-11 w-11 -rotate-90">
                                    <circle cx="18" cy="18" r="14" fill="none" stroke="rgb(var(--z-surface-3))" stroke-width="3" />
                                    <circle cx="18" cy="18" r="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                        :stroke-dasharray="eventRingProps(event.days_until).circumference"
                                        :stroke-dashoffset="eventRingProps(event.days_until).dashOffset"
                                        :class="eventRingProps(event.days_until).ringClass" />
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-xs font-black"
                                        :class="{
                                            'text-danger': event.priority === 'A',
                                            'text-warn':   event.priority === 'B',
                                            'text-ink-3':  event.priority === 'C',
                                        }">{{ event.priority }}</span>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-ink">{{ event.name }}</p>
                                <div class="mt-0.5 flex items-center gap-1.5">
                                    <span class="text-xs text-ink-3">{{ new Date(event.event_date).toLocaleDateString('de-DE', { day: 'numeric', month: 'short' }) }}</span>
                                    <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                        :class="{
                                            'bg-danger-soft text-danger-ink':   event.training_phase.key === 'race_week',
                                            'bg-warn-soft text-warn-ink':       event.training_phase.key === 'taper' || event.training_phase.key === 'peak',
                                            'bg-info-soft text-info-ink':       event.training_phase.key === 'build',
                                            'bg-success-soft text-success-ink': event.training_phase.key === 'base',
                                        }">{{ event.training_phase.label }}</span>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-lg font-black tabular-nums leading-none text-accent">{{ event.days_until }}</p>
                                <p class="text-[11px] text-ink-3">Tage</p>
                            </div>
                        </a>
                    </div>
                </section>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SCHNELL EIN EVENT ANLEGEN
                 ══════════════════════════════════════════════════════ -->
            <section>
                <h2 class="mb-2.5 text-lg font-black tracking-tight text-ink">Neues Ziel setzen</h2>

                <AppCard>
                    <div v-if="quickEventSuccess" class="mb-3 flex items-center gap-2 rounded-field border border-success/25 bg-success-soft px-3 py-2.5">
                        <span class="text-success">✓</span>
                        <p class="text-sm font-semibold text-success-ink">Event gespeichert!</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
                        <div class="space-y-3">
                            <div>
                                <label class="z-label">Distanz</label>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <button v-for="opt in raceOptions" :key="opt.value"
                                        @click="quickEventForm.race_distance = opt.value"
                                        class="rounded-field border px-3 py-2.5 text-xs font-bold transition-all active:scale-[0.98]"
                                        :class="quickEventForm.race_distance === opt.value
                                            ? 'border-accent bg-accent text-white'
                                            : 'border-line bg-surface-2 text-ink-2 hover:border-line-strong'">
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="z-label">Renndatum *</label>
                                <input type="date" v-model="quickEventForm.event_date" class="z-input" />
                            </div>

                            <div>
                                <label class="z-label">Zielzeit</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="relative">
                                        <input type="number" v-model="quickEventForm.target_time_hours" min="0" max="23" class="z-input pr-8" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-ink-3">h</span>
                                    </div>
                                    <div class="relative">
                                        <input type="number" v-model="quickEventForm.target_time_minutes" min="0" max="59" class="z-input pr-10" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-ink-3">min</span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="z-label">Priorität</label>
                                <div class="flex gap-1.5">
                                    <button v-for="p in ['A','B','C']" :key="p"
                                        @click="quickEventForm.priority = p"
                                        class="flex-1 rounded-field border py-2 text-xs font-black transition-all active:scale-[0.98]"
                                        :class="quickEventForm.priority === p
                                            ? p === 'A' ? 'border-danger bg-danger text-white'
                                              : p === 'B' ? 'border-warn bg-warn text-white'
                                              : 'border-line-strong bg-surface-3 text-ink'
                                            : 'border-line bg-surface-2 text-ink-3'">
                                        {{ p }}
                                    </button>
                                </div>
                            </div>

                            <AppButton block :disabled="!quickEventForm.event_date" :loading="quickEventSaving" @click="saveQuickEvent">
                                Event erstellen
                            </AppButton>
                        </div>

                        <div class="rounded-field border border-accent/25 bg-accent-soft p-4">
                            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-accent-ink">Danach passiert automatisch</p>
                            <p class="text-sm leading-relaxed text-ink-2">
                                Sobald das Event steht, baut {{ coach ? coach.name : 'dein Coach' }} dir einen Trainingsplan —
                                abgestimmt auf deine Schwellenpace, deine Zielzeit und die Zeit bis zum Rennen.
                            </p>
                        </div>
                    </div>
                </AppCard>
            </section>

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

        <WellbeingSheet
            :show="showWellbeingModal"
            @close="showWellbeingModal = false"
            @saved="onWellbeingSaved"
        />

        <!-- Zu Garmin senden -->
        <GarminSendSheet
            :show="garminModal"
            :connected="garminConnected"
            :saved-email="garminSavedEmail"
            :sending="garminSending"
            :error="garminError"
            :success="garminSuccess"
            success-text="Es erscheint jetzt in Garmin Connect und ist im Kalender für heute eingetragen."
            @send="sendToGarminConnect"
            @close="garminModal = false"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.activities-scroll::-webkit-scrollbar {
    display: none;
}
</style>
