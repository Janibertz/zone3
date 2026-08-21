<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WellbeingSheet from '@/Components/WellbeingSheet.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import GarminSendSheet from '@/Components/UI/GarminSendSheet.vue';
import GarminRecovery from '@/Components/GarminRecovery.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { matchesSport, sportOptions } from '@/Composables/useActivityTypes';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import { Inertia } from '@inertiajs/inertia';
import { router } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import SessionCard from '@/Components/UI/SessionCard.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatChip from '@/Components/UI/StatChip.vue';
import MetricTile from '@/Components/UI/MetricTile.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { sessionType } from '@/Composables/useSessionTypes';


const props = defineProps({
    stravaConnected: Boolean,
    stravaAccount: Object,
    events: { type: Array, default: () => [] },
    goalCheck:        { type: Object, default: null },
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
    wellbeingToday: {
        type: Object,
        default: null,
    },
    recoveryActivities: {
        type: Array,
        default: () => [],
    },
    // Sonntag und Montag: passt die kommende Woche zum Raster im Profil?
    weekCheck: {
        type: Object,
        default: null,
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

const page  = usePage();
const coach = computed(() => page.props.coach ?? null);
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

    // Der Check-in stoesst einen Garmin-Abruf an, wenn die heutigen Werte
    // fehlen. Der laeuft in der Queue — deshalb kurz warten und dann die
    // Kacheln nachladen, statt sie bis zum naechsten Oeffnen leer zu lassen.
    if (data?.garmin_queued) {
        setTimeout(() => {
            router.reload({ only: ['garminMetrics', 'recoveryActivities'] });
        }, 12000);
    }

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
const firstName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0] || 'Athlet');

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

// ── Garmin-Gesundheitsdaten nachladen ────────────────────────────────────────
// Der Button in GarminRecovery hat bisher ein Event geworfen, das niemand
// abgefangen hat — er tat schlicht nichts.
const garminSyncing = ref(false);

async function syncGarminHealth() {
    garminSyncing.value = true;
    try {
        await axios.post(route('profile.garmin-sync-health'));
        router.reload({ only: ['garminMetrics', 'recoveryActivities'] });
    } finally {
        garminSyncing.value = false;
    }
}

// ── Kennzahl-Kacheln ganz oben ───────────────────────────────────────────────
const checkinDone = computed(() => wellbeingEnteredToday.value);

/** Letzter Garmin-Tag mit Werten. */
const garminLatest = computed(() => props.garminMetrics?.latest ?? null);

/** Mittelwert einer Garmin-Kennzahl über die Serie — als persönliche Basislinie. */
function garminBaseline(key) {
    const vals = (props.garminMetrics?.series ?? [])
        .map(r => r[key])
        .filter(v => v !== null && v !== undefined);
    if (vals.length < 3) return null;
    return vals.reduce((s, v) => s + v, 0) / vals.length;
}

/** 0–100 skalieren und begrenzen. */
const clamp = (v) => Math.max(0, Math.min(100, v));

/** Höher ist besser → good ab 75 %, ok ab 45 %. */
function toneFromPct(pct) {
    if (pct == null) return 'none';
    return pct >= 75 ? 'good' : pct >= 45 ? 'ok' : 'weak';
}

/** Abweichung von der Basislinie einordnen; `invert` für Werte, bei denen weniger besser ist. */
function toneFromBaseline(value, base, invert = false) {
    if (value == null || base == null) return 'none';
    const diff = ((value - base) / base) * 100 * (invert ? -1 : 1);
    return diff >= -2 ? 'good' : diff >= -10 ? 'ok' : 'weak';
}

function baselineHint(value, base, unit = '') {
    if (value == null || base == null) return null;
    const d = Math.round(value - base);
    if (d === 0) return 'im Schnitt';
    return `${d > 0 ? '+' : ''}${d}${unit} zum Schnitt`;
}

/**
 * Vier Kacheln, gespeist aus Garmin. Ohne Garmin-Verbindung übernimmt der
 * Check-in — dieselbe Darstellung, andere Quelle.
 */
const metricTiles = computed(() => {
    const g = garminLatest.value;

    // ── Garmin ──────────────────────────────────────────────────────────
    if (g) {
        const hrvBase = garminBaseline('hrv');
        const rhrBase = garminBaseline('resting_hr');

        const readinessPct = g.training_readiness ?? null;
        const sleepPct     = g.sleep_hours != null ? clamp((g.sleep_hours / 8) * 100) : null;

        return [
            {
                label: 'Readiness',
                value: g.training_readiness ?? '–',
                unit:  g.training_readiness != null ? '/ 100' : null,
                pct:   readinessPct,
                tone:  toneFromPct(readinessPct),
                hint:  null,
            },
            {
                label: 'Schlaf',
                value: g.sleep_hours != null ? g.sleep_hours.toFixed(1) : '–',
                unit:  'h',
                pct:   sleepPct,
                tone:  toneFromPct(sleepPct),
                hint:  g.sleep_hours != null ? (g.sleep_hours >= 7 ? 'ausreichend' : 'kurze Nacht') : null,
            },
            {
                label: 'HRV',
                value: g.hrv ?? '–',
                unit:  'ms',
                // Basislinie sitzt bei 70 % Ringfüllung, damit Abweichungen sichtbar werden.
                pct:   g.hrv != null && hrvBase ? clamp((g.hrv / hrvBase) * 70) : null,
                tone:  toneFromBaseline(g.hrv, hrvBase),
                hint:  baselineHint(g.hrv, hrvBase, ' ms'),
            },
            {
                label: 'Ruhepuls',
                value: g.resting_hr ?? '–',
                unit:  'bpm',
                pct:   g.resting_hr != null && rhrBase ? clamp((rhrBase / g.resting_hr) * 70) : null,
                tone:  toneFromBaseline(g.resting_hr, rhrBase, true),
                hint:  baselineHint(g.resting_hr, rhrBase),
            },
        ];
    }

    // ── Check-in als Rückfallebene ──────────────────────────────────────
    const w = props.wellbeingToday;
    if (!w) return [];

    // Muskelkater und Stress sind invertiert: hoher Wert ist schlecht.
    const scale = (v, invert = false) => (v == null ? null : clamp(((invert ? 11 - v : v) / 10) * 100));

    return [
        { label: 'Energie',     value: w.energy_level    ?? '–', unit: '/ 10', pct: scale(w.energy_level),          tone: toneFromPct(scale(w.energy_level)) },
        { label: 'Schlaf',      value: w.sleep_quality   ?? '–', unit: '/ 10', pct: scale(w.sleep_quality),         tone: toneFromPct(scale(w.sleep_quality)) },
        { label: 'Muskeln',     value: w.muscle_soreness ?? '–', unit: '/ 10', pct: scale(w.muscle_soreness, true), tone: toneFromPct(scale(w.muscle_soreness, true)), hint: 'weniger ist besser' },
        { label: 'Stress',      value: w.stress_level    ?? '–', unit: '/ 10', pct: scale(w.stress_level, true),    tone: toneFromPct(scale(w.stress_level, true)),    hint: 'weniger ist besser' },
    ];
});

/** Woher die Kacheln stammen — für die Beschriftung darüber. */
const metricSource = computed(() => {
    if (garminLatest.value) return 'Garmin';
    if (props.wellbeingToday) return 'Check-in';
    return null;
});

/** Trainingsstatus aus der Belastungsrechnung, kurz gefasst. */
const statusChip = computed(() => {
    const load = props.trainingLoad;
    if (!load) return { label: 'Noch keine Daten', tone: 'neutral' };

    const tone = {
        green:  'success',
        blue:   'success',
        orange: 'warn',
        red:    'danger',
        gray:   'neutral',
    }[load.form_color] ?? 'neutral';

    return { label: load.form_label, tone };
});

/**
 * Der Farbschleier hinter dem Seitenkopf: grün, solange nichts im Weg steht,
 * sonst der neutrale Lavendel-Verlauf.
 */
const washTone = computed(() =>
    checkinDone.value && statusChip.value.tone !== 'danger' ? 'z-wash-good' : 'z-wash-calm'
);

// ── Wochenstreifen ───────────────────────────────────────────────────────────
/** Sieben Tage ab Montag mit Kilometern und Trainings-Flag. */
/**
 * Sportart-Filter fuer den Wochenblock.
 *
 * Das Dashboard bekommt alle Aktivitaeten aus Strava und rechnete sie
 * zusammen, als waeren es Laeufe: eine 40-km-Radfahrt landete in den
 * Wochenkilometern und faerbte den Tagesbalken, waehrend die Pace daneben
 * nur aus Laeufen kam. Jetzt zaehlt alles mit — aber sichtbar, und
 * umschaltbar.
 */
/**
 * Die wöchentliche Zielprüfung.
 *
 * Ein Trainingsplan steht auf der Annahme, dass das Ziel erreichbar ist —
 * und die hat bisher niemand nachgerechnet. Die Karte fragt nur, wenn es
 * etwas zu entscheiden gibt; alles Übrige entscheidet der Server.
 */
const goalBusy      = ref(false);
const goalAnswered  = ref(false);
const goalEditing   = ref(false);
const goalHours     = ref(props.goalCheck?.suggested_hours ?? 0);
const goalMinutes   = ref(props.goalCheck?.suggested_minutes ?? 0);

async function goalConfirm() {
    goalBusy.value = true;
    try {
        await axios.post('/api/goal-check/confirm');
        goalAnswered.value = true;
    } finally {
        goalBusy.value = false;
    }
}

async function goalAdjust() {
    goalBusy.value = true;
    try {
        await axios.post('/api/goal-check/adjust', {
            hours: Number(goalHours.value), minutes: Number(goalMinutes.value),
        });
        goalAnswered.value = true;
        // Der Plan rechnet im Hintergrund neu; die Seite holt sich den
        // neuen Stand, sobald der Job durch ist.
        setTimeout(() => router.reload({ preserveScroll: true }), 1500);
    } finally {
        goalBusy.value = false;
    }
}

/** „Erklär mir das" — die Frage wandert in den Chat, wo man sie besprechen kann. */
async function goalDiscuss() {
    goalBusy.value = true;
    try {
        await axios.post('/api/goal-check/discuss');
        goalAnswered.value = true;
        openChat(`Warum passt meine Zielzeit ${props.goalCheck?.target} für ${props.goalCheck?.event_name} nicht mehr?`);
    } finally {
        goalBusy.value = false;
    }
}

const sportFilter  = ref('all');
const sportChoices = sportOptions();
const sportedActivities = computed(
    () => props.recentActivities.filter(a => matchesSport(a, sportFilter.value)),
);

const weekStrip = computed(() => {
    const labels = ['M', 'D', 'M', 'D', 'F', 'S', 'S'];
    const now = new Date();
    const dow = (now.getDay() + 6) % 7;
    const monday = new Date(now);
    monday.setDate(now.getDate() - dow);
    monday.setHours(0, 0, 0, 0);

    const kmPerDay = new Map();
    sportedActivities.value.forEach(a => {
        if (!a.start_date) return;
        const k = dayKey(new Date(a.start_date));
        kmPerDay.set(k, (kmPerDay.get(k) || 0) + (a.distance || 0) / 1000);
    });

    const todayK = dayKey(now);
    return labels.map((label, i) => {
        const d = new Date(monday);
        d.setDate(monday.getDate() + i);
        const k = dayKey(d);
        const km = Math.round((kmPerDay.get(k) || 0) * 10) / 10;
        return { label, key: k, km, isToday: k === todayK, isPast: d < now && k !== todayK };
    });
});

const weekSessionCount = computed(() => weekStrip.value.filter(d => d.km > 0).length);

// ── Vergleich zur Vorwoche ───────────────────────────────────────────────────
/**
 * Bis zum selben Wochentag gerechnet.
 *
 * Vorher stand die laufende Woche gegen die komplette Vorwoche — am Montag
 * und Dienstag kam damit zwangsläufig „−100 %" heraus, egal wie das
 * Training lief. Verglichen wird jetzt derselbe Ausschnitt: Montag bis
 * heute gegen Montag bis zum selben Wochentag der Vorwoche.
 */
const lastWeekKm = computed(() => {
    const now = new Date();
    const dow = (now.getDay() + 6) % 7;

    const thisWeekStart = new Date(now);
    thisWeekStart.setDate(now.getDate() - dow);
    thisWeekStart.setHours(0, 0, 0, 0);

    const lastWeekStart = new Date(thisWeekStart);
    lastWeekStart.setDate(thisWeekStart.getDate() - 7);

    // Ende des Vergleichsfensters: gleich viele Tage wie in dieser Woche.
    const lastWeekCutoff = new Date(lastWeekStart);
    lastWeekCutoff.setDate(lastWeekStart.getDate() + dow + 1);

    const meters = sportedActivities.value
        .filter(a => {
            if (!a.start_date) return false;
            const d = new Date(a.start_date);
            return d >= lastWeekStart && d < lastWeekCutoff;
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
    const total = sportedActivities.value.reduce((sum, a) => sum + (a.distance || 0), 0);
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

    const weekActs = sportedActivities.value.filter(a => a.start_date && new Date(a.start_date) >= startOfWeek);
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
    const acts = sportedActivities.value.filter(a => {
        if (!a.start_date) return false;
        const d = new Date(a.start_date);
        return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    });
    return {
        km: (acts.reduce((s, a) => s + (a.distance || 0), 0) / 1000).toFixed(1),
        // Gezaehlt wird, was der Filter durchlaesst — sonst stuende neben
        // Radkilometern eine Zahl, die nur Laeufe meint.
        runs: acts.length,
    };
});

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
    // Ein Renntag ist im Raster orange hervorgehoben, war aber nur ueber das
    // title-Attribut zu identifizieren — und das gibt es auf einem Telefon
    // nicht. Man sah einen markierten Tag und kam nicht heran. Ohne eigene
    // Aktivitaet fuehrt der Tag jetzt zum Event.
    if (d.hasEvent && !d.hasActivity) {
        router.visit(route('events.plan.show', d.event.id));
        return;
    }

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


// ── Wochenabfrage ────────────────────────────────────────────────────────────
// Das Raster im Profil beschreibt die normale Woche. Urlaub, Schichtdienst
// oder eine volle Woche waren bisher nirgends eintragbar — die Ausnahmen je
// Datum gab es zwar im Backend, aber ohne Bedienung.
const weekCheck        = ref(props.weekCheck);
const weekSheet        = ref(false);
const weekSaving       = ref(false);
const weekDays         = ref([]);
const weekError        = ref('');

function openWeekSheet() {
    weekDays.value = (weekCheck.value?.days ?? []).map(d => ({ ...d }));
    weekSheet.value = true;
}

function toggleWeekDay(day) {
    day.available = !day.available;
    if (!day.available) day.duration_min = 0;
    else if (!day.duration_min) day.duration_min = 60;
}

async function confirmWeek() {
    weekSaving.value = true;
    try {
        await axios.post(route('week-availability.confirm'));
        weekCheck.value = null;
    } finally {
        weekSaving.value = false;
    }
}

async function saveWeek() {
    weekSaving.value = true;
    try {
        await axios.post(route('week-availability.store'), { days: weekDays.value });
        weekSheet.value = false;
        weekCheck.value = null;
        weekError.value = '';
    } catch (e) {
        weekError.value = e?.response?.data?.error ?? 'Speichern fehlgeschlagen.';
    } finally {
        weekSaving.value = false;
    }
}
</script>

<template>
    <Head title="Übersicht" />

    <AuthenticatedLayout>

        <!-- Ambient-Wash hinter dem Seitenkopf, blendet nach unten aus.
             Inhalt laeuft ueber die volle Breite. -->
        <div class="z-wash min-h-screen" :class="washTone">
            <div class="space-y-5 px-4 py-4 lg:px-6 lg:py-6">

                <!-- ══════════════════════════════════════════════════
                     ZIELPRÜFUNG — wöchentlich, nur bei echter Abweichung
                     ══════════════════════════════════════════════════ -->
                <AppCard v-if="goalCheck && !goalAnswered">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 shrink-0 text-xl leading-none">
                            {{ goalCheck.kind === 'too_conservative' ? '🚀' : '🎯' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[15px] font-semibold text-ink">{{ goalCheck.headline }}</h2>
                            <p class="mt-1 text-[13px] leading-relaxed text-ink-3">{{ goalCheck.detail }}</p>

                            <!-- Die beiden Zahlen nebeneinander: worauf der Plan
                                 zielt, und was die Form derzeit trägt. -->
                            <div class="mt-3 flex gap-2">
                                <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Dein Ziel</p>
                                    <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">{{ goalCheck.target }}</p>
                                </div>
                                <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Deine Form</p>
                                    <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">{{ goalCheck.predicted }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Anpassen: der Vorschlag steht drin, änderbar bleibt er. -->
                    <div v-if="goalEditing" class="mt-4 rounded-field bg-surface-2 p-3">
                        <p class="text-[13px] font-medium text-ink-2">Neue Zielzeit</p>
                        <div class="mt-2 flex items-center gap-2">
                            <input v-model="goalHours" type="number" min="0" max="23" inputmode="numeric"
                                class="z-input w-20 text-center" aria-label="Stunden" />
                            <span class="text-lg font-bold text-ink-3">:</span>
                            <input v-model="goalMinutes" type="number" min="0" max="59" inputmode="numeric"
                                class="z-input w-20 text-center" aria-label="Minuten" />
                            <span class="text-[13px] text-ink-3">Std</span>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <AppButton variant="ghost" size="md" class="flex-1" @click="goalEditing = false">
                                Zurück
                            </AppButton>
                            <AppButton size="md" class="flex-1" :loading="goalBusy" @click="goalAdjust">
                                Ziel setzen
                            </AppButton>
                        </div>
                        <p class="mt-2 text-[12px] text-ink-3">
                            Der Plan wird danach neu berechnet. Von dir gesetzte Einheiten bleiben erhalten.
                        </p>
                    </div>

                    <div v-else class="mt-4 flex flex-wrap gap-2">
                        <AppButton size="md" class="flex-1" :loading="goalBusy" @click="goalEditing = true">
                            {{ goalCheck.suggested ? `Auf ${goalCheck.suggested} ändern` : 'Ziel anpassen' }}
                        </AppButton>
                        <AppButton variant="secondary" size="md" class="flex-1" :loading="goalBusy" @click="goalConfirm">
                            Ziel bleibt
                        </AppButton>
                        <AppButton variant="ghost" size="md" class="w-full sm:w-auto" :loading="goalBusy" @click="goalDiscuss">
                            Erklär mir das
                        </AppButton>
                    </div>
                </AppCard>

                <!-- ══════════════════════════════════════════════════
                     WOCHENABFRAGE — Sonntag und Montag
                     ══════════════════════════════════════════════════ -->
                <AppCard v-if="weekCheck">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[15px] font-semibold text-ink">Passt deine kommende Woche?</h2>
                            <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                                Der Plan geht von deinem üblichen Wochenraster aus. Urlaub, Schichten oder
                                eine volle Woche kannst du hier einmalig eintragen.
                            </p>
                        </div>
                        <!-- Volle Breite auf dem Telefon, 44 Pixel hoch statt 36. -->
                        <div class="flex w-full gap-2 sm:w-auto sm:shrink-0">
                            <AppButton variant="ghost" size="md" class="flex-1 sm:flex-none" :loading="weekSaving" @click="confirmWeek">
                                Wie immer
                            </AppButton>
                            <AppButton variant="secondary" size="md" class="flex-1 sm:flex-none" @click="openWeekSheet">
                                Anpassen
                            </AppButton>
                        </div>
                    </div>
                </AppCard>

                <!-- ══════════════════════════════════════════════════
                     KÖRPERWERTE — Garmin, sonst der Check-in
                     ══════════════════════════════════════════════════ -->
                <section v-if="metricTiles.length">
                    <div class="mb-3 flex items-center justify-between gap-3 px-1">
                        <h2 class="text-[17px] font-medium text-ink-2">Deine Werte</h2>
                        <span class="rounded-full bg-surface-2 px-3 py-1 text-[12px] font-semibold text-ink-3">{{ metricSource }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                        <MetricTile v-for="t in metricTiles" :key="t.label" v-bind="t" />
                    </div>
                </section>

                <!-- Ohne Garmin und ohne Check-in bleibt nur die Aufforderung -->
                <button v-else type="button" class="w-full text-left" @click="showWellbeingModal = true">
                    <AppCard>
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xl">💪</div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[15px] font-bold text-ink">Wie fühlst du dich heute?</p>
                                <p class="mt-0.5 text-[13px] text-ink-3">Ein kurzer Check-in füllt deine Werte — 30 Sekunden.</p>
                            </div>
                            <svg class="h-5 w-5 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </div>
                    </AppCard>
                </button>

                <!-- Streak · Form · Check-in als schmale Zeile darunter -->
                <div class="grid grid-cols-3 gap-2.5">
                    <StatChip label="Streak">
                        <template #icon><span class="text-base leading-none">🔥</span></template>
                        {{ streakDays }}
                    </StatChip>

                    <StatChip label="Form" :tone="statusChip.tone">
                        <span class="truncate text-[15px]">{{ statusChip.label }}</span>
                    </StatChip>

                    <button type="button" class="min-w-0 text-left" @click="showWellbeingModal = true">
                        <StatChip label="Check-in" :tone="checkinDone ? 'success' : 'warn'">
                            <span class="text-[15px]">{{ checkinDone ? 'Erledigt' : 'Offen' }}</span>
                        </StatChip>
                    </button>
                </div>

                <!-- ══════════════════════════════════════════════════
                     BEGRÜSSUNG
                     ══════════════════════════════════════════════════ -->
                <AppCard>
                    <p class="text-[15px] text-ink">{{ greeting }},</p>
                    <h1 class="mt-1 text-2xl font-bold leading-tight tracking-tight text-ink">
                        <template v-if="coachPrMessage && !prDismissed">Stark gelaufen, {{ firstName }}! 🏆</template>
                        <template v-else-if="!checkinDone">Wie fühlst du dich heute?</template>
                        <template v-else-if="props.todayPlanSession || props.todayRecommendationSession">Viel Spaß bei deinem Training</template>
                        <template v-else>Schön, dass du da bist, {{ firstName }}</template>
                    </h1>

                    <p class="mt-2.5 text-[15px] leading-relaxed text-ink-3">
                        <span v-if="coachPrMessage && !prDismissed">{{ coachPrMessage }}</span>
                        <span v-else-if="dailyMessageLoading" class="animate-pulse">…</span>
                        <span v-else-if="dailyMessage">{{ dailyMessage }}</span>
                        <span v-else-if="!checkinDone">
                            Ein kurzer Check-in — danach passt {{ coach ? coach.name : 'dein Coach' }} das
                            heutige Training an deine Tagesform an.
                        </span>
                        <span v-else>{{ coach?.tagline ?? 'Heute ist ein guter Tag zum Laufen.' }}</span>
                    </p>

                    <!-- Wetter am Trainingsort -->
                    <div v-if="weather" class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-3">
                        <span class="flex items-center gap-1.5">
                            <span class="text-base">{{ weather.emoji }}</span>
                            <span class="font-semibold tabular-nums text-ink">{{ weather.temp_c }}°</span>
                            {{ weather.description }}
                        </span>
                        <span v-if="weather.precip_prob != null && weather.precip_prob >= 30">💧 {{ weather.precip_prob }} %</span>
                        <span v-if="weather.wind_kmh != null && weather.wind_kmh >= 25">💨 {{ weather.wind_kmh }} km/h</span>
                    </div>

                    <div v-if="coachPrMessage && !prDismissed" class="mt-4">
                        <AppButton size="sm" variant="secondary" @click="dismissPr">Danke, gesehen</AppButton>
                    </div>
                </AppCard>

                <!-- Meldungen -->
                <AppCard v-if="props.syncResult">
                    <p class="text-[15px] text-ink-2">{{ props.syncResult }}</p>
                </AppCard>
                <AppCard v-if="flash.success && showFlash">
                    <p class="text-[15px] font-medium text-success-ink">{{ flash.success }}</p>
                </AppCard>
                <AppCard v-if="flash.error && showFlash">
                    <p class="text-[15px] font-medium text-danger-ink">{{ flash.error }}</p>
                </AppCard>

                <!-- ══════════════════════════════════════════════════
                     HEUTIGES TRAINING
                     ══════════════════════════════════════════════════ -->
                <section>
                    <SectionHeader title="Heutiges Training">
                        <template #action>
                            <AppButton v-if="props.hasActivePlan" size="sm" variant="secondary"
                                :href="props.todayPlanSession?.event_id ? `/events/${props.todayPlanSession.event_id}/plan` : '/events'">
                                Zum Plan
                            </AppButton>
                            <span v-else class="text-[13px] text-ink-3">{{ props.aiUsage.used }}/{{ props.aiUsage.limit }} KI</span>
                        </template>
                    </SectionHeader>

                    <!-- ── Mit aktivem Plan ─────────────────────────── -->
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

                    <!-- ── Ohne Plan: Empfehlung ────────────────────── -->
                    <template v-else>
                        <div v-if="props.todayRecommendationSession" class="space-y-3">
                            <SessionCard :session="props.todayRecommendationSession" badge="✓ Geplant" badge-tone="success" />

                            <!-- Trainingsstruktur als Zonenbalken -->
                            <AppCard
                                v-if="props.todayRecommendationSession.type !== 'rest' && props.todayRecommendationSession.type !== 'race_prep'"
                                title="Trainingsstruktur"
                            >
                                <div v-if="recStepsLoading" class="flex items-center gap-2 py-2 text-[13px] text-ink-3">
                                    <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Struktur wird geladen…
                                </div>
                                <p v-else-if="recStepsError" class="text-[13px] text-danger">{{ recStepsError }}</p>
                                <div v-else-if="recSteps && recSteps.length">
                                    <div class="mb-4 flex h-14 items-end gap-1">
                                        <div
                                            v-for="(s, i) in recStepsWithReps"
                                            :key="i"
                                            :style="{
                                                width:  ((s.duration_min || 0) / recTotalStepDuration * 100).toFixed(1) + '%',
                                                height: (stepHeightPct[s.type] ?? 60) + '%',
                                            }"
                                            :class="[stepBarColor[s.type] ?? 'bg-accent', 'rounded-md']"
                                            :title="`${s.label}: ${s.duration_min} min`"
                                        />
                                    </div>
                                    <div class="space-y-1">
                                        <div v-for="(step, idx) in recGroupedSteps" :key="idx"
                                            class="flex items-center gap-3 rounded-field bg-surface-2 px-3.5 py-3">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="stepBarColor[step.type]" />
                                            <div class="min-w-0 flex-1">
                                                <!--
                                                    Ohne truncate: neben Dauer und Pace
                                                    blieben auf dem Telefon knapp 180
                                                    Pixel fuer den Text, und genau der
                                                    sagt, was zu tun ist. Lieber zwei
                                                    Zeilen als abgeschnitten.
                                                -->
                                                <p class="text-[15px] font-medium leading-snug text-ink">
                                                    <template v-if="step.isGroup">{{ step.repetitions }}× </template>{{ step.label }}
                                                </p>
                                                <p v-if="step.isGroup && step.pairedRest" class="text-[13px] text-ink-3">
                                                    dazwischen {{ step.pairedRest.label }} · {{ step.pairedRest.duration_min }} min
                                                </p>
                                            </div>
                                            <span class="shrink-0 text-[13px] tabular-nums text-ink-3">{{ step.duration_min }} min</span>
                                            <span v-if="step.pace_target" class="shrink-0 text-[13px] font-semibold tabular-nums text-ink">{{ step.pace_target }}</span>
                                        </div>
                                    </div>
                                </div>
                            </AppCard>

                            <!-- Verpflegung -->
                            <AppCard v-if="props.todayRecommendationSession.type !== 'rest'" title="Verpflegung">
                                <div v-if="recNutritionLoading" class="flex items-center gap-3 py-2 text-[15px] text-ink-3">
                                    <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    {{ coach ? coach.name : 'Dein Coach' }} stellt Tipps zusammen…
                                </div>
                                <p v-else-if="recNutritionError" class="text-[13px] text-danger">{{ recNutritionError }}</p>
                                <div v-else-if="recNutritionTips" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                    <div v-for="block in [
                                            { key: 'before', icon: '🕐', title: 'Vorher' },
                                            { key: 'during', icon: '🏃', title: 'Währenddessen' },
                                            { key: 'after',  icon: '✅', title: 'Danach' },
                                        ]" :key="block.key"
                                        class="rounded-field bg-surface-2 p-3.5">
                                        <p class="mb-2 flex items-center gap-1.5 text-[13px] font-bold text-ink">
                                            <span>{{ block.icon }}</span>{{ block.title }}
                                        </p>
                                        <ul class="space-y-1.5">
                                            <li v-for="tip in recNutritionTips[block.key]" :key="tip.text" class="flex items-start gap-2 text-[13px] leading-relaxed text-ink-3">
                                                <span class="shrink-0">{{ tip.icon }}</span>
                                                <span>{{ tip.text }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </AppCard>

                            <AppButton v-if="props.todayRecommendationSession.type !== 'rest'" block @click="openGarminModal">
                                Zu Garmin senden
                            </AppButton>
                        </div>

                        <template v-else>
                            <AppCard v-if="recommendationLoading">
                                <div class="flex items-center gap-3 py-3 text-[15px] text-ink-3">
                                    <svg class="h-5 w-5 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    {{ coach ? coach.name + ' überlegt…' : 'Empfehlung wird geladen…' }}
                                </div>
                            </AppCard>

                            <AppCard v-else-if="recommendationError">
                                <p class="text-[15px] text-danger-ink">{{ recommendationError }}</p>
                            </AppCard>

                            <AppCard v-else-if="recommendationAccepted">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success text-white">✓</div>
                                    <div>
                                        <p class="text-[15px] font-bold text-ink">Eingeplant</p>
                                        <p class="mt-0.5 text-[13px] text-ink-3">{{ trainingRecommendation?.title }} steht in deinem Kalender.</p>
                                    </div>
                                </div>
                            </AppCard>

                            <div v-else-if="showRecommendation && trainingRecommendation" class="space-y-3">
                                <SessionCard :session="trainingRecommendation" :badge="coach ? coach.name : 'Coach'" badge-tone="accent" />

                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="adjustRecommendation('softer')"
                                        :disabled="adjustingDirection !== null || acceptingRecommendation"
                                        class="flex flex-col items-center justify-center gap-1 rounded-card bg-surface py-3.5 text-[13px] font-semibold text-ink-2 shadow-card transition-transform active:scale-95 disabled:opacity-40">
                                        <span v-if="adjustingDirection === 'softer'" class="h-5 w-5 animate-spin rounded-full border-2 border-ink-3 border-t-transparent" />
                                        <span v-else class="text-xl leading-none">🧘</span>
                                        Lockerer
                                    </button>
                                    <button @click="acceptRecommendation()"
                                        :disabled="acceptingRecommendation || adjustingDirection !== null"
                                        class="flex flex-col items-center justify-center gap-1 rounded-card bg-ink py-3.5 text-[13px] font-semibold text-canvas transition-transform active:scale-95 disabled:opacity-40">
                                        <span v-if="acceptingRecommendation" class="h-5 w-5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        <span v-else class="text-xl leading-none">✓</span>
                                        Einplanen
                                    </button>
                                    <button @click="adjustRecommendation('harder')"
                                        :disabled="adjustingDirection !== null || acceptingRecommendation"
                                        class="flex flex-col items-center justify-center gap-1 rounded-card bg-surface py-3.5 text-[13px] font-semibold text-ink-2 shadow-card transition-transform active:scale-95 disabled:opacity-40">
                                        <span v-if="adjustingDirection === 'harder'" class="h-5 w-5 animate-spin rounded-full border-2 border-warn border-t-transparent" />
                                        <span v-else class="text-xl leading-none">🔥</span>
                                        Intensiver
                                    </button>
                                </div>
                            </div>

                            <AppCard v-else-if="recommendationHint">
                                <p class="text-[15px] font-bold text-ink">Fast geschafft</p>
                                <p class="mt-1 text-[15px] text-ink-3">{{ recommendationHint }}</p>
                                <AppButton size="sm" class="mt-4" @click="showWellbeingModal = true">Check-in machen</AppButton>
                            </AppCard>

                            <AppCard v-else>
                                <EmptyState title="Noch keine Empfehlung" description="Mach deinen Check-in, dann schlägt dir dein Coach eine passende Einheit vor.">
                                    <AppButton @click="getTodayRecommendation">Empfehlung holen</AppButton>
                                </EmptyState>
                            </AppCard>
                        </template>
                    </template>
                </section>

                <!-- Wiedereinstieg -->
                <AppCard v-if="returnToRun && !returnToRunDismissed" title="Wiedereinstieg" :subtitle="`nach ${returnToRun.trigger_label} · Stufe ${returnToRun.step} von ${returnToRun.total_steps}`">
                    <div class="mb-3 flex items-center gap-1.5">
                        <div v-for="s in returnToRun.steps" :key="s.n"
                            class="h-1.5 flex-1 rounded-full"
                            :class="s.n <= returnToRun.step ? 'bg-success' : 'bg-surface-3'" />
                    </div>
                    <p class="text-[15px] font-semibold text-ink">{{ returnToRun.current.label }}</p>
                    <p class="mt-0.5 text-[15px] text-ink-3">{{ returnToRun.current.rule }}</p>
                    <div v-if="returnToRun.current.max_min" class="mt-3 flex flex-wrap gap-2 text-[13px] text-ink-3">
                        <span class="rounded-full bg-surface-2 px-3 py-1">max. {{ returnToRun.current.max_min }} min</span>
                        <span v-if="returnToRun.current.zone" class="rounded-full bg-surface-2 px-3 py-1">Zone {{ returnToRun.current.zone }}</span>
                    </div>
                    <AppButton size="sm" variant="secondary" class="mt-4" @click="dismissReturnToRun">Wiedereinstieg abschließen</AppButton>
                </AppCard>

                <!-- Kein Plan -->
                <AppCard v-if="!props.hasActivePlan" tappable href="/events">
                    <div class="flex items-center gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xl">🎯</div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-bold text-ink">Noch kein Trainingsplan</p>
                            <p class="mt-0.5 text-[13px] text-ink-3">
                                {{ coach ? coach.name + ' erstellt' : 'Erstelle' }} dir einen Plan fürs nächste Event.
                            </p>
                        </div>
                        <svg class="h-5 w-5 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </div>
                </AppCard>

                <!-- ══════════════════════════════════════════════════
                     NOCH ZU BEWERTEN
                     ══════════════════════════════════════════════════ -->
                <section v-if="pendingRatingSessions.length > 0">
                    <SectionHeader title="Wie lief's?" />
                    <AppCard flush>
                        <div class="divide-y divide-line">
                            <div v-for="session in pendingRatingSessions" :key="session.id">
                                <button v-if="ratingOpenId !== session.id" @click="openRating(session)"
                                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-surface-2">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="shrink-0 text-lg">{{ sessionType(session.type).emoji }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate text-[15px] font-semibold text-ink">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                            <p class="text-[13px] text-ink-3">
                                                {{ new Date(session.planned_date).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}
                                                {{ session.distance_km ? `· ${session.distance_km} km` : '' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-surface-2 px-3 py-1.5 text-[13px] font-semibold text-ink">Bewerten</span>
                                </button>

                                <div v-else class="px-5 py-4">
                                    <div class="mb-4 flex items-center justify-between">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="shrink-0 text-lg">{{ sessionType(session.type).emoji }}</span>
                                            <p class="truncate text-[15px] font-bold text-ink">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                        </div>
                                        <button @click="ratingOpenId = null" class="ml-2 shrink-0 text-lg leading-none text-ink-3 hover:text-ink">✕</button>
                                    </div>

                                    <p class="mb-2 text-[13px] font-semibold text-ink-2">Wie war die Einheit?</p>
                                    <div class="mb-4 flex items-center gap-1.5">
                                        <button v-for="star in 5" :key="star"
                                            @click="ratingStars = ratingStars === star ? 0 : star"
                                            class="flex h-10 w-10 items-center justify-center rounded-full text-lg transition-all"
                                            :class="star <= ratingStars ? 'scale-110 bg-warn-soft' : 'bg-surface-2 opacity-40 hover:opacity-70'"
                                        >⭐</button>
                                        <span class="ml-2 text-[13px] text-ink-3">{{ ['','Sehr schwer','Schwer','Okay','Gut','Top'][ratingStars] }}</span>
                                    </div>

                                    <p class="mb-2 text-[13px] font-semibold text-ink-2">Anstrengung (RPE)</p>
                                    <!--
                                        Zehn Knoepfe zu 36 Pixel passen auf dem
                                        Telefon nicht in eine Zeile: die Skala
                                        brach als 7 + 3 um und war als Skala
                                        nicht mehr lesbar. Zwei Reihen zu fuenf
                                        sind geordnet und besser zu treffen.
                                    -->
                                    <div class="mb-4 grid grid-cols-5 gap-1.5 sm:grid-cols-10">
                                        <button v-for="n in 10" :key="n"
                                            @click="ratingEffort = ratingEffort === n ? 0 : n"
                                            class="h-11 rounded-full text-[13px] font-bold transition-all active:scale-90 sm:h-9"
                                            :class="n === ratingEffort
                                                ? (n <= 3 ? 'bg-success text-white' : n <= 6 ? 'bg-warn text-white' : 'bg-danger text-white')
                                                : 'bg-surface-2 text-ink-3 hover:bg-surface-3'"
                                        >{{ n }}</button>
                                    </div>

                                    <textarea v-model="ratingNotes" rows="2" placeholder="Notizen (optional)…" class="z-input mb-3 resize-none" />

                                    <div class="flex items-center gap-3">
                                        <AppButton size="sm" block
                                            :loading="ratingSavingId === session.id"
                                            :disabled="!ratingStars && !ratingEffort && !ratingNotes"
                                            @click="submitRating(session.id)">Speichern</AppButton>
                                        <a v-if="session.activity_id" :href="route('activities.show', session.activity_id)" class="shrink-0 text-[13px] text-ink-3 hover:text-ink">Details</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </AppCard>
                </section>

                <!-- ══════════════════════════════════════════════════
                     DEINE WOCHE
                     ══════════════════════════════════════════════════ -->
                <section>
                    <SectionHeader title="Deine Woche">
                        <template #action>
                            <AppButton size="sm" variant="secondary" href="/statistics">Statistiken</AppButton>
                        </template>
                    </SectionHeader>

                    <AppCard>
                        <!--
                            Der Filter steht ueber dem Streifen und nicht in der
                            Kopfzeile: dort teilt er sich den Platz mit der
                            Schaltflaeche und wird auf dem Telefon zum Gedraenge.
                            Er erscheint nur, wenn es ueberhaupt mehr als eine
                            Sportart zu unterscheiden gibt.
                        -->
                        <SegmentedControl
                            v-if="sportChoices.length"
                            v-model="sportFilter"
                            :options="sportChoices"
                            class="mb-4"
                        />

                        <!-- Wochenstreifen -->
                        <div class="grid grid-cols-7 gap-1.5">
                            <div v-for="d in weekStrip" :key="d.key" class="flex flex-col items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full text-[13px] font-bold"
                                    :class="d.isToday ? 'bg-warn text-white' : 'text-ink-3'">{{ d.label }}</span>
                                <span class="flex h-8 w-full items-center justify-center rounded-full text-[12px] font-semibold tabular-nums"
                                    :class="d.km > 0 ? 'bg-surface-2 text-ink' : 'text-ink-3'">
                                    <template v-if="d.km > 0">{{ d.km }}</template>
                                    <template v-else>·</template>
                                </span>
                                <span class="h-1.5 w-full rounded-full"
                                    :class="d.km > 0 ? 'bg-success' : d.isPast ? 'bg-surface-3' : 'bg-surface-2'" />
                            </div>
                        </div>

                        <!-- Fusszeile -->
                        <div class="mt-5 grid grid-cols-3 gap-4 border-t border-line pt-4">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Einheiten</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ weekSessionCount }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Kilometer</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ weekStats.km }}</p>
                                <p v-if="weekTrend !== null" class="text-[12px] font-semibold"
                                    :class="weekTrend >= 0 ? 'text-success' : 'text-ink-3'">
                                    {{ weekTrend >= 0 ? '↗' : '↘' }} {{ Math.abs(weekTrend) }} % zur Vorwoche
                                </p>
                            </div>
                            <div>
                                <!--
                                    Die Kilometer daneben summieren alles, was aus
                                    Strava kommt — auch Radfahren. Die Pace kann das
                                    nicht: sie ergibt nur ueber Laeufe einen Sinn.
                                    Also steht jetzt dran, worauf sie sich bezieht.
                                -->
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Ø Pace · Läufe</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ weekStats.avgPace }}</p>
                            </div>
                        </div>

                        <p class="mt-3 flex flex-wrap gap-x-4 text-[13px] text-ink-3">
                            <span>Monat <span class="font-semibold tabular-nums text-ink-2">{{ monthStats.km }} km</span> · {{ monthStats.runs }} Einheiten</span>
                            <span>Gesamt <span class="font-semibold tabular-nums text-ink-2">{{ totalDistanceKm }} km</span></span>
                        </p>
                    </AppCard>
                </section>

                <!-- ══════════════════════════════════════════════════
                     FORM · TEMPO  (nebeneinander ab lg)
                     ══════════════════════════════════════════════════ -->
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2 2xl:grid-cols-3">

                    <!-- Form -->
                    <section v-if="props.trainingLoad" class="min-w-0">
                        <SectionHeader title="Form">
                            <template #action>
                                <span class="rounded-full px-3 py-1.5 text-[13px] font-semibold"
                                    :class="{
                                        'bg-danger-soft text-danger-ink':   props.trainingLoad.form_color === 'red',
                                        'bg-warn-soft text-warn-ink':       props.trainingLoad.form_color === 'orange',
                                        'bg-success-soft text-success-ink': props.trainingLoad.form_color === 'green',
                                        'bg-info-soft text-info-ink':       props.trainingLoad.form_color === 'blue',
                                        'bg-surface-2 text-ink-2':          props.trainingLoad.form_color === 'gray',
                                    }">{{ props.trainingLoad.form_label }}</span>
                            </template>
                        </SectionHeader>

                        <AppCard>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <p class="text-2xl font-bold tabular-nums text-ink">{{ props.trainingLoad.ctl }}</p>
                                    <p class="mt-0.5 text-[13px] text-ink-3">Fitness</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold tabular-nums text-ink">{{ props.trainingLoad.atl }}</p>
                                    <p class="mt-0.5 text-[13px] text-ink-3">Ermüdung</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold tabular-nums text-ink">{{ props.trainingLoad.tsb > 0 ? '+' : '' }}{{ props.trainingLoad.tsb }}</p>
                                    <p class="mt-0.5 text-[13px] text-ink-3">Form</p>
                                </div>
                            </div>

                            <div v-if="loadChartData" class="mt-4 overflow-hidden rounded-field bg-surface-2 px-2 pt-2">
                                <svg :viewBox="`0 0 ${loadChartData.W} ${loadChartData.H}`" class="w-full" preserveAspectRatio="none">
                                    <path :d="loadChartData.atlPath" fill="none" stroke="rgb(var(--z-warn))" stroke-width="1.5" stroke-linecap="round" opacity="0.85" />
                                    <path :d="loadChartData.ctlPath" fill="none" stroke="rgb(var(--z-accent))" stroke-width="2" stroke-linecap="round" />
                                </svg>
                                <div class="flex items-center gap-3 px-1 pb-2 pt-1.5">
                                    <span class="flex items-center gap-1.5 text-[12px] text-ink-3">
                                        <span class="inline-block h-0.5 w-4 rounded bg-accent" /> Fitness
                                    </span>
                                    <span class="flex items-center gap-1.5 text-[12px] text-ink-3">
                                        <span class="inline-block h-0.5 w-4 rounded bg-warn" /> Ermüdung
                                    </span>
                                </div>
                            </div>

                            <p class="mt-3 text-[13px] leading-relaxed text-ink-3">
                                Form ist Fitness minus Ermüdung. −10 bis +5 heißt wettkampfbereit,
                                darunter steckst du in einem Trainingsblock.
                            </p>
                        </AppCard>
                    </section>

                    <!-- Tempo -->
                    <section class="min-w-0">
                        <SectionHeader title="Dein Tempo">
                            <template #action>
                                <AppButton v-if="props.stravaConnected" size="sm" variant="secondary"
                                    :loading="syncing" :disabled="props.thresholdPaceCalculating" @click="syncStrava">
                                    {{ syncing ? 'Sync…' : 'Sync' }}
                                </AppButton>
                            </template>
                        </SectionHeader>

                        <AppCard>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Schwellenpace</p>
                            <p v-if="props.thresholdPace" class="mt-1 flex items-baseline gap-2">
                                <span class="text-4xl font-bold tabular-nums tracking-tight text-ink">{{ props.thresholdPace }}</span>
                                <span class="text-[15px] text-ink-3">min/km</span>
                            </p>
                            <p v-else class="mt-1 text-xl font-bold text-ink-3">Noch nicht berechnet</p>
                            <p class="mt-1 text-[13px] text-ink-3">
                                <span v-if="props.thresholdPaceCalculating">Analyse läuft im Hintergrund…</span>
                                <span v-else-if="props.thresholdPaceCalculatedAt">{{ props.thresholdPaceCalculatedAt }} · letzte 20 Läufe</span>
                                <span v-else>Wird nach dem nächsten Strava-Sync berechnet</span>
                            </p>

                            <div v-if="props.paceZones" class="mt-4 grid grid-cols-5 gap-1.5">
                                <div v-for="(zone, key) in props.paceZones" :key="key" class="rounded-field bg-surface-2 p-2 text-center">
                                    <p class="text-[11px] font-bold uppercase text-ink-2">{{ key }}</p>
                                    <p class="mt-0.5 font-mono text-[11px] leading-tight text-ink-3">
                                        {{ zone.min_pace }}<br><span class="opacity-70">{{ zone.max_pace }}</span>
                                    </p>
                                </div>
                            </div>

                            <button @click="showPaceDetails = !showPaceDetails"
                                class="mt-4 flex items-center gap-1.5 text-[13px] font-semibold text-ink-2 transition-colors hover:text-ink">
                                <svg class="h-4 w-4 transition-transform duration-200" :class="showPaceDetails ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                                {{ showPaceDetails ? 'Weniger' : 'Prognosen & Verlauf' }}
                            </button>

                            <div v-if="showPaceDetails">
                                <div v-if="props.racePredictions" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <div v-for="(pred, key) in props.racePredictions" :key="key" class="rounded-field bg-surface-2 px-3 py-2.5">
                                        <p class="text-[12px] text-ink-3">{{ pred.label }}</p>
                                        <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">{{ pred.total_time }}</p>
                                        <p class="font-mono text-[11px] text-ink-3">{{ pred.pace }} /km</p>
                                    </div>
                                </div>

                                <div v-if="chartData" class="mt-4">
                                    <div class="w-full overflow-hidden rounded-field bg-surface-2" style="aspect-ratio: 560 / 120;">
                                        <svg :viewBox="`0 0 ${chartData.W} ${chartData.H}`" class="h-full w-full" preserveAspectRatio="none">
                                            <path :d="chartData.fillD" fill="rgb(var(--z-accent) / 0.12)" />
                                            <path :d="chartData.pathD" fill="none" stroke="rgb(var(--z-accent))" stroke-width="2" stroke-linecap="round" />
                                            <g v-for="(p, i) in chartData.points" :key="i">
                                                <circle :cx="p.x" :cy="p.y" r="4" fill="rgb(var(--z-accent))" />
                                                <text v-if="i === 0 || i === chartData.points.length - 1 || i % chartData.labelStep === 0"
                                                    :x="p.x" :y="p.y - 8" text-anchor="middle" font-size="9"
                                                    fill="rgb(var(--z-ink-2))" font-family="monospace">{{ p.pace }}</text>
                                            </g>
                                        </svg>
                                    </div>
                                    <p class="mt-1.5 text-center text-[12px] text-ink-3">{{ props.thresholdPaceHistory.length }} Messpunkte · oben = schneller</p>
                                </div>
                                <p v-else-if="props.thresholdPace" class="mt-3 text-[13px] text-ink-3">
                                    Der Verlauf erscheint, sobald Daten über mehr als 7 Tage vorliegen.
                                </p>
                            </div>
                        </AppCard>
                    </section>

                    <!-- Wochenrückblick -->
                    <section v-if="props.weeklyReview" class="min-w-0">
                        <SectionHeader title="Wochenrückblick" />
                        <AppCard>
                            <div class="mb-3 flex items-center gap-3">
                                <div v-if="coach" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[13px] font-bold text-white"
                                    :style="`background-color: ${coach.avatar_color}`">{{ coach.avatar_initials }}</div>
                                <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-2 text-base">🧠</div>
                                <div>
                                    <p class="text-[15px] font-bold text-ink">{{ coach ? coach.name : 'Dein Coach' }}</p>
                                    <p class="text-[13px] text-ink-3">ab {{ new Date(props.weeklyReview.week_start).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}</p>
                                </div>
                            </div>
                            <p class="text-[15px] leading-relaxed text-ink-2">{{ props.weeklyReview.content }}</p>
                        </AppCard>
                    </section>
                </div>

                <!-- Garmin-Erholung -->
                <GarminRecovery
                    v-if="props.garminMetrics"
                    :metrics="props.garminMetrics"
                    :activities="props.recoveryActivities"
                    :syncing="garminSyncing"
                    @refresh="syncGarminHealth"
                />

                <!-- ══════════════════════════════════════════════════
                     ZULETZT GELAUFEN
                     ══════════════════════════════════════════════════ -->
                <section>
                    <SectionHeader title="Letzte Aktivitäten">
                        <template #action>
                            <AppButton size="sm" variant="secondary" href="/activities">Alle</AppButton>
                        </template>
                    </SectionHeader>

                    <AppCard v-if="props.recentActivities.length === 0">
                        <EmptyState title="Noch keine Aktivitäten" description="Verbinde Strava und synchronisiere, dann erscheinen deine Einheiten hier.">
                            <AppButton v-if="!props.stravaConnected" href="/strava/connect">Strava verbinden</AppButton>
                            <AppButton v-else :loading="syncing" @click="syncStrava">Jetzt synchronisieren</AppButton>
                        </EmptyState>
                    </AppCard>

                    <!-- min-w-0 auf dem Grid-Item: sonst steht dessen min-width auf auto,
                         ein langer Name wird nicht gekuerzt und sprengt die Spalte. -->
                    <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        <button
                            v-for="activity in props.recentActivities.slice(0, 8)"
                            :key="activity.id"
                            @click="openActivityDetail(activity)"
                            class="min-w-0 rounded-card bg-surface p-4 text-left shadow-card transition-transform active:scale-[0.99]"
                        >
                            <div class="mb-2.5 flex items-start justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="shrink-0 text-lg leading-none">{{ activityTypeIcon(activity.type) }}</span>
                                    <p class="truncate text-[15px] font-semibold leading-tight text-ink">{{ activity.name }}</p>
                                </div>
                                <span class="shrink-0 text-[12px] text-ink-3">{{ relativeDate(activity.start_date) }}</span>
                            </div>

                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[15px] text-ink-3">
                                <span v-if="activity.distance > 0" class="font-bold tabular-nums text-ink">
                                    {{ round2(formatDistance(activity.distance)) }} km
                                </span>
                                <span v-else-if="['WeightTraining','Workout'].includes(activity.type)" class="font-bold text-ink">Kraft</span>
                                <span class="tabular-nums">{{ formatTime(activity.moving_time) }}</span>
                                <span v-if="activity.average_speed > 0"
                                    class="rounded-full px-2 py-0.5 text-[12px] font-bold tabular-nums"
                                    :class="['Ride','VirtualRide'].includes(activity.type)
                                        ? 'bg-success-soft text-success-ink'
                                        : activity.type === 'Swim'
                                            ? 'bg-info-soft text-info-ink'
                                            : paceColor(activity.average_speed)">
                                    {{ activityPaceLabel(activity) }}
                                </span>
                                <span v-if="activity.average_heartrate" class="text-[13px]">❤️ {{ Math.round(activity.average_heartrate) }}</span>
                                <span v-if="activity.total_elevation_gain > 0" class="text-[13px]">↑ {{ Math.round(activity.total_elevation_gain) }} m</span>
                            </div>
                        </button>
                    </div>
                </section>

                <!-- ══════════════════════════════════════════════════
                     MONAT · EVENTS · NEUES ZIEL
                     ══════════════════════════════════════════════════ -->
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2 2xl:grid-cols-3">

                    <section class="min-w-0">
                        <SectionHeader title="Monat">
                            <template #action>
                                <AppButton size="sm" variant="secondary" href="/calendar">Vollansicht</AppButton>
                            </template>
                        </SectionHeader>

                        <AppCard>
                            <div class="mb-4 flex items-center justify-center gap-2">
                                <button @click="prevMonth" class="flex h-11 w-11 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink sm:h-9 sm:w-9">‹</button>
                                <h4 class="min-w-[150px] text-center text-[15px] font-semibold text-ink">{{ currentMonthLabel }}</h4>
                                <button @click="nextMonth" class="flex h-11 w-11 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink sm:h-9 sm:w-9">›</button>
                            </div>

                            <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[12px] font-semibold text-ink-3">
                                <div>Mo</div><div>Di</div><div>Mi</div><div>Do</div><div>Fr</div><div>Sa</div><div>So</div>
                            </div>
                            <div class="grid grid-cols-7 gap-1 text-center">
                                <div v-for="(d, i) in calendarDays" :key="i"
                                    class="relative mx-auto flex h-9 w-9 items-center justify-center rounded-full text-[13px] transition-colors"
                                    :class="{
                                        'bg-ink font-bold text-canvas': d.isToday && !d.hasEvent,
                                        'bg-warn font-bold text-canvas': d.hasEvent,
                                        'text-ink-3 opacity-40':        !d.currentMonth,
                                        'text-ink hover:bg-surface-2':  d.currentMonth && !d.isToday && !d.hasEvent,
                                        'cursor-pointer':               d.hasActivity || d.hasEvent,
                                    }"
                                    :title="d.hasEvent ? d.event.name : ''"
                                    @click="openCalendarDay(d)">
                                    {{ d.day }}
                                    <span v-if="d.hasActivity && !d.isToday && !d.hasEvent" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-success" />
                                    <!-- Auf gefuellten Zellen muss der Punkt gegen die Fuellung kontrastieren:
                                         Heute ist bg-ink (im Dark Mode hell), das Event bg-warn (immer dunkel genug). -->
                                    <span v-if="d.hasActivity && d.hasEvent" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-surface/80" />
                                    <span v-else-if="d.hasActivity && d.isToday" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-canvas/80" />
                                </div>
                            </div>

                            <!-- Mehrere Aktivitäten an einem Tag: als Liste unter dem Raster.
                                 Als schwebende Sprechblase ragte sie an den Randspalten aus dem
                                 Bildschirm und liess die ganze Seite seitlich scrollen. -->
                            <div v-if="calendarPickerDay && activeDaysInMonth.get(calendarPickerDay)?.length"
                                class="mt-4 rounded-field bg-surface-2 p-1.5">
                                <div class="flex items-center justify-between px-3 py-1.5">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-ink-3">
                                        {{ calendarPickerDay }}. {{ currentMonthLabel.split(' ')[0] }} · {{ activeDaysInMonth.get(calendarPickerDay).length }} Aktivitäten
                                    </p>
                                    <button class="text-lg leading-none text-ink-3 hover:text-ink" @click="calendarPickerDay = null">✕</button>
                                </div>
                                <button v-for="act in activeDaysInMonth.get(calendarPickerDay)" :key="act.id"
                                    @click="pickCalendarActivity(act)"
                                    class="w-full rounded-field px-3 py-2.5 text-left transition-colors hover:bg-surface">
                                    <p class="truncate text-[13px] font-semibold text-ink">{{ act.name }}</p>
                                    <p class="text-[12px] text-ink-3"><template v-if="act.distance > 0">{{ formatDistance(act.distance) }} km · </template>{{ formatTime(act.moving_time) }}</p>
                                </button>
                            </div>
                        </AppCard>
                    </section>

                    <section class="min-w-0">
                        <SectionHeader title="Nächste Events">
                            <template #action>
                                <AppButton size="sm" variant="secondary" href="/events">Alle</AppButton>
                            </template>
                        </SectionHeader>

                        <AppCard v-if="props.events.length === 0">
                            <EmptyState title="Kein Event geplant" description="Ein Ziel im Kalender macht jedes Training konkreter.">
                                <AppButton href="/events">Event anlegen</AppButton>
                            </EmptyState>
                        </AppCard>

                        <AppCard v-else flush>
                            <div class="divide-y divide-line">
                                <a v-for="event in props.events.slice(0, 4)" :key="event.id" href="/events"
                                    class="flex items-center gap-3.5 px-5 py-4 transition-colors hover:bg-surface-2">
                                    <div class="relative h-11 w-11 shrink-0">
                                        <svg viewBox="0 0 36 36" class="h-11 w-11 -rotate-90">
                                            <circle cx="18" cy="18" r="14" fill="none" stroke="rgb(var(--z-surface-3))" stroke-width="3" />
                                            <circle cx="18" cy="18" r="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                                :stroke-dasharray="eventRingProps(event.days_until).circumference"
                                                :stroke-dashoffset="eventRingProps(event.days_until).dashOffset"
                                                :class="eventRingProps(event.days_until).ringClass" />
                                        </svg>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-[12px] font-black"
                                                :class="{
                                                    'text-danger': event.priority === 'A',
                                                    'text-warn':   event.priority === 'B',
                                                    'text-ink-3':  event.priority === 'C',
                                                }">{{ event.priority }}</span>
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[15px] font-semibold text-ink">{{ event.name }}</p>
                                        <div class="mt-0.5 flex items-center gap-2">
                                            <span class="text-[13px] text-ink-3">{{ new Date(event.event_date).toLocaleDateString('de-DE', { day: 'numeric', month: 'short' }) }}</span>
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                :class="{
                                                    'bg-danger-soft text-danger-ink':   event.training_phase.key === 'race_week',
                                                    'bg-warn-soft text-warn-ink':       event.training_phase.key === 'taper' || event.training_phase.key === 'peak',
                                                    'bg-info-soft text-info-ink':       event.training_phase.key === 'build',
                                                    'bg-success-soft text-success-ink': event.training_phase.key === 'base',
                                                }">{{ event.training_phase.label }}</span>
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-lg font-bold tabular-nums leading-none text-ink">{{ event.days_until }}</p>
                                        <p class="text-[12px] text-ink-3">Tage</p>
                                    </div>
                                </a>
                            </div>
                        </AppCard>
                    </section>

                    <section class="min-w-0">
                        <SectionHeader title="Neues Ziel" />

                        <AppCard>
                            <div v-if="quickEventSuccess" class="mb-4 rounded-field bg-success-soft px-4 py-3">
                                <p class="text-[15px] font-semibold text-success-ink">Event gespeichert</p>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="z-label">Distanz</label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button v-for="opt in raceOptions" :key="opt.value"
                                            @click="quickEventForm.race_distance = opt.value"
                                            class="rounded-full px-3 py-2.5 text-[13px] font-semibold transition-all active:scale-[0.97]"
                                            :class="quickEventForm.race_distance === opt.value
                                                ? 'bg-ink text-canvas'
                                                : 'bg-surface-2 text-ink-2 hover:bg-surface-3'">
                                            {{ opt.label }}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label class="z-label">Renndatum</label>
                                    <input type="date" v-model="quickEventForm.event_date" class="z-input" />
                                </div>

                                <div>
                                    <label class="z-label">Zielzeit</label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="relative">
                                            <input type="number" v-model="quickEventForm.target_time_hours" min="0" max="23" class="z-input pr-9" />
                                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[13px] text-ink-3">h</span>
                                        </div>
                                        <div class="relative">
                                            <input type="number" v-model="quickEventForm.target_time_minutes" min="0" max="59" class="z-input pr-12" />
                                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[13px] text-ink-3">min</span>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="z-label">Priorität</label>
                                    <div class="flex gap-2">
                                        <button v-for="p in ['A','B','C']" :key="p"
                                            @click="quickEventForm.priority = p"
                                            class="flex-1 rounded-full py-2.5 text-[13px] font-bold transition-all active:scale-[0.97]"
                                            :class="quickEventForm.priority === p
                                                ? p === 'A' ? 'bg-danger text-white'
                                                  : p === 'B' ? 'bg-warn text-canvas'
                                                  : 'bg-ink text-canvas'
                                                : 'bg-surface-2 text-ink-3 hover:bg-surface-3'">
                                            {{ p }}
                                        </button>
                                    </div>
                                </div>

                                <AppButton block :disabled="!quickEventForm.event_date" :loading="quickEventSaving" @click="saveQuickEvent">
                                    Event erstellen
                                </AppButton>

                                <p class="text-[13px] leading-relaxed text-ink-3">
                                    Sobald das Event steht, baut {{ coach ? coach.name : 'dein Coach' }} dir einen
                                    Trainingsplan — abgestimmt auf Schwellenpace, Zielzeit und die Zeit bis zum Rennen.
                                </p>
                            </div>
                        </AppCard>
                    </section>
                </div>

            </div>
        </div>

        <!-- Wellbeing Modal -->
        <!-- Wellbeing / plan-adjust toast -->
        <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-4" leave-active-class="transition-all duration-200" leave-to-class="opacity-0 translate-y-4">
            <div v-if="wellbeingToast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-5 py-3 rounded-card shadow-xl text-sm font-medium"
                :class="wellbeingToast.type === 'ai'
                    ? 'bg-accent text-white'
                    : 'bg-success text-white'">
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
        <!-- ══════════════════════════════════════════════════
             WOCHE ANPASSEN
             ══════════════════════════════════════════════════ -->
        <AppSheet :show="weekSheet" title="Deine Woche" subtitle="Nur für diese eine Woche" @close="weekSheet = false">
            <p class="z-hint mb-4">
                Änderungen gelten einmalig und überschreiben dein Wochenraster im Profil nicht.
            </p>

            <div class="space-y-2">
                <div v-for="day in weekDays" :key="day.date" class="rounded-card bg-surface-2 p-3.5">
                    <div class="flex items-center gap-3">
                        <button type="button"
                            class="flex h-6 w-11 shrink-0 items-center rounded-full px-0.5 transition-colors"
                            :class="day.available ? 'bg-accent' : 'bg-surface-3'"
                            :aria-pressed="day.available"
                            @click="toggleWeekDay(day)">
                            <span class="h-5 w-5 rounded-full bg-white transition-transform"
                                :class="day.available ? 'translate-x-5' : ''" />
                        </button>

                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-medium text-ink">{{ day.label }}</p>
                            <p v-if="day.fixed" class="text-[12px] text-accent-ink">{{ day.fixed }}</p>
                        </div>

                        <span v-if="!day.available" class="text-[13px] text-ink-3">keine Zeit</span>
                    </div>

                    <div v-if="day.available" class="mt-2.5 flex flex-wrap gap-1.5 pl-14">
                        <button v-for="dur in [30, 45, 60, 90, 120, 180]" :key="dur" type="button"
                            class="rounded-full px-2.5 py-1 text-[12px] font-medium transition-colors"
                            :class="day.duration_min === dur ? 'bg-ink text-canvas' : 'bg-surface text-ink-3 hover:text-ink-2'"
                            @click="day.duration_min = dur">
                            {{ dur }} min
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="weekError" class="z-error">{{ weekError }}</p>

            <template #footer>
                <AppButton block :loading="weekSaving" @click="saveWeek">Woche übernehmen</AppButton>
            </template>
        </AppSheet>

    </AuthenticatedLayout>
</template>

<style scoped>
.activities-scroll::-webkit-scrollbar {
    display: none;
}
</style>
