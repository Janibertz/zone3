<script setup>
import { zoneRangeShort } from '@/Composables/usePaceZones';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WellbeingSheet from '@/Components/WellbeingSheet.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import WatchButton from '@/Components/Native/WatchButton.vue';
import GarminSendSheet from '@/Components/UI/GarminSendSheet.vue';
import GarminRecovery from '@/Components/GarminRecovery.vue';
import StarterChecklistCard from '@/Components/StarterChecklistCard.vue';
import NotificationChoice from '@/Components/Settings/NotificationChoice.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { activityType, matchesSport } from '@/Composables/useActivityTypes';
import { useCoachChat } from '@/Composables/useCoachChat';
import { Inertia } from '@inertiajs/inertia';
import { router } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import SessionCard from '@/Components/UI/SessionCard.vue';
import UpcomingSessions from '@/Components/Plan/UpcomingSessions.vue';
import TodayHero from '@/Components/Today/TodayHero.vue';
import RaceCard from '@/Components/Today/RaceCard.vue';
import CoachLine from '@/Components/Today/CoachLine.vue';
import LastRunCard from '@/Components/Today/LastRunCard.vue';
import PersonalRecordMoment from '@/Components/Moments/PersonalRecordMoment.vue';
import WeekDoneMoment from '@/Components/Moments/WeekDoneMoment.vue';
import RaceDoneMoment from '@/Components/Moments/RaceDoneMoment.vue';
import { activityHref, completedActivityHref } from '@/Composables/useActivityLink';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatChip from '@/Components/UI/StatChip.vue';
import GaugeTile from '@/Components/UI/GaugeTile.vue';
import { garminGauges, wellbeingGauges } from '@/Composables/useRecoveryGauges.js';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { paceValue, sessionType, stepAmount } from '@/Composables/useSessionTypes';


const props = defineProps({
    stravaConnected: Boolean,
    stravaAccount: Object,
    events: { type: Array, default: () => [] },
    goalCheck:        { type: Object, default: null },
    starterChecklist: { type: Object, default: null },
    notifyOptions:    { type: Object, default: () => ({ vapidPublicKey: '', telegram: { available: false, linked: false } }) },
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
    thresholdDisplay: {
        type: Object,
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
    coachRecommendation: {
        type: Object,
        default: null,
    },
    // Plan beginnt später: bis dahin der Tagesvorschlag ({race, date, days}).
    bridgeToPlan: { type: Object, default: null },
    hasActivePlan: {
        type: Boolean,
        default: false,
    },
    upcomingSessions: { type: Array, default: () => [] },
    raceCard: { type: Object, default: null },
    coachLine: { type: String, default: null },
    lastRun: { type: Object, default: null },
    weekPlan: {
        type: Array,
        default: () => [],
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
    intensityDistribution: {
        type: Object,
        default: null,
    },
    // Neue Bestzeit als Vollbild — siehe PersonalRecordMoment.
    // „Woche geschafft“ und „Rennen gelaufen“ — siehe MomentService.
    weekDone: { type: Object, default: null },
    raceRun:  { type: Object, default: null },
    personalRecord: {
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
    dashboardLayout: {
        type: Array,
        default: () => [],
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

// Immer nur ein Moment: Bestzeit, dann Rennen, dann Woche.
const raceSeen = ref(false);
const weekSeen = ref(false);
const showPr   = computed(() => props.personalRecord && !prDismissed.value);
const showRace = computed(() => !showPr.value && props.raceRun && !raceSeen.value);
const showWeek = computed(() => !showPr.value && !showRace.value && props.weekDone && !weekSeen.value);
function closeRace() {
    raceSeen.value = true;
    axios.post(route('moments.seen'), { kind: 'race', event_id: props.raceRun.event_id });
}
function closeWeek() {
    weekSeen.value = true;
    axios.post(route('moments.seen'), { kind: 'week', week_start: props.weekDone.week_start });
}

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

/**
 * Der Vorschlag des Coaches zur heutigen Einheit.
 *
 * Vorher hat der Wellbeing-Job die Einheit direkt umgeschrieben. Wer sich
 * auf ein Schwellentraining eingestellt hatte und zwanzig lockere Minuten
 * vorfand, erlebte seinen Plan als etwas, das ihm zustösst. Jetzt steht
 * hier ein Vorschlag, und die Entscheidung gehört dem Athleten.
 */

/**
 * Einheit tauschen — gegen ein geteiltes Workout.
 *
 * Gemeldet: „Ich hatte 30 Min Zone2 auf dem Plan, ich habe aber Bock auf
 * Intervalle." Der Plan sagt, was sinnvoll ist; die Tagesform sagt manchmal
 * etwas anderes, und darauf gab es bisher nur die Antwort „machen oder
 * lassen".
 *
 * Die Workouts kommen von Athleten, die sie freigegeben haben. Geteilt wird
 * die Struktur — die Paces rechnet der Server aus der Schwellenpace DIESES
 * Läufers.
 */
const swapOpen    = ref(false);
const swapLoading = ref(false);
const swapBusy    = ref(null);
const swapGroups  = ref({});
const swapTypes   = ref({});
const swapHasPaces = ref(true);
const swapCategory = ref(null);

async function openSwap() {
    swapOpen.value = true;
    swapDetail.value = null;

    if (Object.keys(swapGroups.value).length) return;

    swapLoading.value = true;
    try {
        const { data } = await axios.get(route('workouts.shared'));
        swapGroups.value   = data.workouts ?? {};
        swapTypes.value    = data.types ?? {};
        swapHasPaces.value = data.has_paces;
        swapCategory.value = Object.keys(swapGroups.value)[0] ?? null;
    } finally {
        swapLoading.value = false;
    }
}

/**
 * Erst ansehen, dann entscheiden.
 *
 * Gemeldet: „Ich kann mir die Workout-Struktur nicht angucken und ob in
 * der Beschreibung noch etwas steht." Eine Einheit zu übernehmen, ohne zu
 * wissen, was drinsteht, ist ein Blindkauf — und sie steht danach im Plan.
 */
const swapDetail = ref(null);

function applyWorkout(workout) {
    const session = props.todayPlanSession;
    if (!session || swapBusy.value) return;

    swapBusy.value = workout.id;
    router.post(route('workouts.apply-to-session', [session.id, workout.id]), {}, {
        preserveScroll: true,
        onSuccess: () => { swapOpen.value = false; swapDetail.value = null; },
        onFinish:  () => { swapBusy.value = null; },
    });
}

/**
 * Ein Abschnitt in einer Zeile: „1000 m · 4:10 /km · Zone 5".
 *
 * Die Ansage zuerst — Strecke, wenn das Workout eine vorgibt, sonst Zeit.
 * Bei einem Streckenabschnitt steht die geschaetzte Dauer dahinter in
 * Klammern, damit man die Einheit trotzdem einordnen kann.
 */
function stepLine(step) {
    const byDistance = Number(step.distance_m ?? 0) > 0;

    return [
        stepAmount(step),
        byDistance && step.duration_min ? `ca. ${step.duration_min} min` : null,
        step.pace_target ? `${step.pace_target} /km` : null,
        step.zone ? `Zone ${step.zone}` : null,
    ].filter(Boolean).join(' · ');
}

const stepTone = {
    warmup:   'bg-info-soft text-info-ink',
    work:     'bg-accent-soft text-accent-ink',
    rest:     'bg-surface-2 text-ink-3',
    cooldown: 'bg-success-soft text-success-ink',
};

const stepLabel = { warmup: 'Aufwärmen', work: 'Belastung', rest: 'Pause', cooldown: 'Auslaufen' };

/** „48 min · 9,4 km · 4:22 /km" — nur was gerechnet werden konnte. */
function workoutSummary(w) {
    return [
        w.duration_min ? `${w.duration_min} min` : null,
        w.distance_km ? `${w.distance_km} km` : null,
        w.pace_target ? `${w.pace_target} /km` : null,
    ].filter(Boolean).join(' · ');
}

const recommendationBusy = ref(false);

function decideRecommendation(action) {
    if (!props.coachRecommendation || recommendationBusy.value) return;

    recommendationBusy.value = true;
    router.post(route(`recommendations.${action}`, props.coachRecommendation.id), {}, {
        preserveScroll: true,
        onFinish: () => { recommendationBusy.value = false; },
    });
}

/** Nur die Grössen zeigen, die sich wirklich unterscheiden. */
const recommendationChanges = computed(() => {
    const r = props.coachRecommendation;
    if (!r) return [];

    const labels = {
        type: 'Art', title: 'Einheit', distance_km: 'Distanz',
        duration_min: 'Dauer', pace_target: 'Pace', zone: 'Zone', intensity: 'Intensität',
    };

    return Object.keys(labels)
        .filter((k) => String(r.before?.[k] ?? '') !== String(r.after?.[k] ?? ''))
        .map((k) => ({ label: labels[k], from: r.before?.[k] ?? '—', to: r.after?.[k] ?? '—' }));
});

/** Die Kennzahlen aus `recommendationChanges` — Art und Titel stehen schon in der Zeile darüber. */
/**
 * Wohin „Details" an der Trainingskarte führt: ist die Einheit erledigt und
 * hängt eine Aktivität daran, auf deren Seite (dieselbe wie unter
 * Aktivitäten), sonst auf die Einheit in der Planseite.
 */
const todaySessionHref = computed(() => {
    const s = props.todayPlanSession;
    if (!s) return null;

    const done = completedActivityHref(s, '/dashboard');
    if (done) return done;

    return s.event_id ? `/events/${s.event_id}/plan?open=${s.id}` : route('calendar');
});

const recommendationNumbers = computed(() => {
    const units = { Distanz: 'km', Dauer: 'min', Pace: '/km' };
    const fmt = (label, v) => (v === '—' || v == null ? '—'
        : label === 'Pace' ? paceValue(v) ?? '—'
        : String(v).replace('.', ','));

    // Wie die Story-Karte: neuer Wert groß, der alte klein durchgestrichen
    // am Label. Höchstens drei Spalten — mehr passen auf 390 Pixel nicht.
    return recommendationChanges.value
        .filter((c) => ['Distanz', 'Dauer', 'Pace', 'Zone'].includes(c.label))
        .slice(0, 3)
        .map((c) => ({
            label: c.label,
            from:  fmt(c.label, c.from),
            to:    fmt(c.label, c.to),
            unit:  units[c.label] ?? null,
        }));
});

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

const showPaceDetails = ref(false);
const shownThreshold = computed(() => props.thresholdDisplay?.pace ?? props.thresholdPace);

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
// „Nicht bewerten“ (09.11.2026): einzeln oder alle offenen auf einmal.
async function skipRating(ids) {
    ratedIds.value = new Set([...ratedIds.value, ...ids]);
    if (ids.includes(ratingOpenId.value)) ratingOpenId.value = null;
    await axios.patch(route('training-sessions.skip-rating'), { ids });
    router.reload({ only: ['unratedSessions'] });
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
            message: data?.message ?? 'Tagesform gespeichert!',
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

function openGarminModal() {
    garminModal.value    = true;
    garminError.value    = '';
    garminSuccess.value  = false;
}

async function sendToGarminConnect() {
    garminSending.value = true;
    garminError.value   = '';
    garminSuccess.value = false;
    try {
        await axios.post(route('training-sessions.send-to-garmin', props.todayRecommendationSession.id));
        garminSuccess.value = true;
        setTimeout(() => { garminModal.value = false; garminSuccess.value = false; }, 2500);
    } catch (err) {
        garminError.value = err.response?.data?.error || 'Verbindung fehlgeschlagen';
    } finally {
        garminSending.value = false;
    }
}

// ── Begrüßung ────────────────────────────────────────────────────────────────
const firstName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0] || 'Athlet');

const todayLabel = computed(() =>
    new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })
);

/** Datumszeile über „Heute", mit dem Wetter am Trainingsort, sobald es da ist. */
const headerLine = computed(() => {
    const w = weather.value;
    if (!w || w.temp_c == null) return todayLabel.value;

    return `${todayLabel.value} · ${Math.round(w.temp_c)}° ${w.description ?? ''}`.trim();
});

// ── Kennzahl-Kacheln ganz oben ───────────────────────────────────────────────
const checkinDone = computed(() => wellbeingEnteredToday.value);

/** Letzter Garmin-Tag mit Werten. */
const garminLatest = computed(() => props.garminMetrics?.latest ?? null);

/**
 * Die Kacheln unter „Körper“: Garmin-Bögen, ohne Garmin der Check-in in
 * derselben Darstellung. Die Rechnung steht in useRecoveryGauges.js.
 */
const metricTiles = computed(() => (garminLatest.value
    ? garminGauges(props.garminMetrics)
    : wellbeingGauges(props.wellbeingToday)));

/** Woher die Kacheln stammen — für die Beschriftung darüber. */
const metricSource = computed(() => {
    if (garminLatest.value) return props.garminMetrics?.source ?? 'Garmin';
    if (props.wellbeingToday) return 'Tagesform';
    return null;
});

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
const { open: openChat, isOpen: chatOpen } = useCoachChat();

// Einsteiger-Checkliste: Punkte ohne eigene Seite öffnen hier etwas.
const notifySheet = ref(false);
function starterAction(action) {
    if (action === 'chat') openChat();
    else if (action === 'tagesform') showWellbeingModal.value = true;
    else if (action === 'notify') notifySheet.value = true;
}
function refreshStarter() {
    if (props.starterChecklist) router.reload({ only: ['starterChecklist', 'notifyOptions'] });
}
// Nach dem ersten „Hallo“ im Chat steht der Haken beim Schliessen.
watch(chatOpen, (open) => { if (!open) refreshStarter(); });

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
// Vorbelegt mit dem Vorschlag, sonst mit dem heutigen Ziel — „0 : 0“ war
// ein Feld, das man erst leeren musste.
const goalHours     = ref(props.goalCheck?.suggested_hours ?? props.goalCheck?.target_hours ?? 0);
const goalMinutes   = ref(props.goalCheck?.suggested_minutes ?? props.goalCheck?.target_minutes ?? 0);

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

/**
 * „Erklär mir das" — die Frage wandert in den Chat, wo man sie besprechen
 * kann. Die Karte bleibt: Nachfragen ist keine Entscheidung, und sie steht
 * beim nächsten Laden wieder da, bis eine gefallen ist.
 */
async function goalDiscuss() {
    goalBusy.value = true;
    try {
        await axios.post('/api/goal-check/discuss');
        const g = props.goalCheck ?? {};
        // Die Frage passt zum Urteil der Karte. Vorher fragte sie immer
        // „Warum passt meine Zielzeit nicht mehr?“, auch wenn die Karte
        // gerade gesagt hatte, das Tempo passe.
        const question = {
            too_ambitious:     `Warum ist meine Zielzeit ${g.target} für ${g.event_name} zu ehrgeizig?`,
            too_conservative:  `Warum traust du mir für ${g.event_name} mehr zu als ${g.target}?`,
            pace_ok_base_thin: `Mein Tempo passt für ${g.event_name}, aber mein Umfang nicht. Was heißt das für mein Ziel ${g.target}?`,
        }[g.kind] ?? `Wie realistisch ist meine Zielzeit ${g.target} für ${g.event_name}?`;
        openChat(question);
    } finally {
        goalBusy.value = false;
    }
}

const sportFilter  = ref('all');
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

/** Eine Zahl auf deutsch: 10,5 statt 10.5, 1.160 statt 1160. */
const fmtNum = (v, digits = 1) => Number(v || 0).toLocaleString('de-DE', { maximumFractionDigits: digits });

/**
 * Die Woche als Leiste wie im Mockup: je Tag ein Punkt in der Farbe der
 * geplanten Einheit — voll, wenn erledigt, blass, wenn sie noch kommt, als
 * Ring für heute. Darunter die Kilometer: gelaufene, wenn es sie gibt,
 * sonst geplante. Ohne Plan zeigen die Punkte nur, an welchen Tagen
 * gelaufen wurde.
 */
const weekDots = computed(() => {
    const labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    const plan = new Map();
    (props.weekPlan || []).forEach((p) => {
        // Pro Tag zählt die erste Einheit, die kein Kraft-/Ruhe-Beiwerk ist.
        const prev = plan.get(p.date);
        if (!prev || (prev.type === 'rest' && p.type !== 'rest')) plan.set(p.date, p);
    });

    return weekStrip.value.map((d, i) => {
        const date = new Date();
        date.setDate(date.getDate() - ((date.getDay() + 6) % 7) + i);
        const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const p = plan.get(iso);
        const isRest = p?.type === 'rest';
        const done = d.km > 0 || p?.status === 'completed';

        return {
            key: d.key,
            label: labels[i],
            isToday: d.isToday,
            dot: p ? sessionType(p.type).dot : (d.km > 0 ? 'bg-accent' : null),
            rest: isRest,
            state: d.isToday && !done ? 'today' : done ? 'done' : p?.status === 'skipped' ? 'skipped' : 'open',
            km: d.km > 0 ? fmtNum(d.km) : (p?.distance_km && !isRest ? fmtNum(p.distance_km) : null),
        };
    });
});

// ── „Mehr Leben“ (Mockup vom 10.11.2026) ─────────────────────────────────
/** Der Aufmacher zeigt die Planeinheit; ohne Plan bleibt es bei der Empfehlung. */
/**
 * Was oben im Foto steht (Mockup „mehr Leben“): die Planeinheit von heute,
 * sonst die eingeplante Empfehlung, sonst der Vorschlag des Coaches — so
 * bekommt auch die Zeit vor einem späteren Planstart den Aufmacher.
 */
const heroKind = computed(() => {
    if (props.hasActivePlan && !props.bridgeToPlan && props.todayPlanSession) return 'plan';
    if (props.todayRecommendationSession) return 'scheduled';
    if (showRecommendation.value && trainingRecommendation.value) return 'proposal';
    return null;
});
const heroSession = computed(() => ({
    plan: props.todayPlanSession,
    scheduled: props.todayRecommendationSession,
    proposal: trainingRecommendation.value,
})[heroKind.value] ?? null);
const heroBadge = computed(() => {
    const s = heroSession.value;
    if (!s) return null;
    if (heroKind.value === 'proposal') return coach.value ? `Vorschlag von ${coach.value.name}` : 'Vorschlag';
    if (heroKind.value === 'scheduled') return 'Eingeplant';
    if (s.status === 'completed') return 'Erledigt';
    if (s.is_today) return null;
    return new Date(s.session_date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });
});
/** Kurze Datumszeile im Foto: „Di, 10. Nov · 8°“. */
const heroDateLine = computed(() => {
    const d = new Date().toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' }).replace(/\.(?=,)/, '').replace(/\.$/, '');
    const t = weather.value?.temp_c;
    return t == null ? d : `${d} · ${Math.round(t)}°`;
});
const greeting = computed(() => {
    const h = new Date().getHours();
    const part = h < 11 ? 'Guten Morgen' : h < 18 ? 'Hallo' : 'Guten Abend';
    return `${part}, ${firstName.value}`;
});
/** Woche, Als Nächstes und Zuletzt stehen fest im Aufbau des Mockups — sofern nicht ausgeblendet. */
const initials = (name) => (name ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || 'Z';
const LEBEN_KEYS = ['woche', 'naechste', 'zuletzt'];
const shows = (key) => layout.value.some(m => m.key === key && !m.hidden);
const heroContext = computed(() => {
    const r = props.raceCard;
    const p = r?.progress;
    if (!p || p.starts_in > 0) return null;
    return r.open ? `Block ${r.block} · Woche ${p.week} von ${p.weeks}` : `Woche ${p.week} von ${p.weeks} · ${p.phase}`;
});
const sideCards = computed(() => (props.coachLine ? 1 : 0) + (props.raceCard ? 1 : 0));

/** Säulenhöhe je Tag: geplante Minuten (ohne Ruhetag), sonst gelaufene Kilometer. */
const weekColumns = computed(() => {
    const minutes = new Map();
    (props.weekPlan || []).forEach((p) => {
        if (p.type === 'rest') return;
        minutes.set(p.date, (minutes.get(p.date) ?? 0) + (p.duration_min || (p.distance_km ? p.distance_km * 6 : 0)));
    });
    const days = weekDots.value.map((d, i) => {
        const date = new Date();
        date.setDate(date.getDate() - ((date.getDay() + 6) % 7) + i);
        const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const km = weekStrip.value[i]?.km ?? 0;
        // Vor dem Planstart: heute steht der Vorschlag des Coaches als
        // blasse Säule da, sonst wäre die Woche leer, obwohl etwas ansteht.
        if (d.isToday && !minutes.has(iso) && km <= 0 && heroKind.value === 'proposal' && heroSession.value?.type !== 'rest') {
            const s = heroSession.value;
            return { ...d, dot: sessionType(s.type).dot, state: 'today', size: s.duration_min || (s.distance_km ? s.distance_km * 6 : 0) };
        }
        return { ...d, size: minutes.get(iso) ?? (km > 0 ? km * 6 : 0) };
    });
    const max = Math.max(1, ...days.map(d => d.size));
    return days.map(d => ({ ...d, height: d.size > 0 ? Math.max(14, Math.round(d.size / max * 100)) : 10 }));
});

/** Legende: nur die Farben, die diese Woche vorkommen. */
const weekLegend = computed(() => {
    const seen = new Map();
    (props.weekPlan || []).forEach((p) => {
        if (p.type === 'rest') return;
        const meta = sessionType(p.type);
        if (!seen.has(meta.dot)) seen.set(meta.dot, { dot: meta.dot, label: meta.label });
    });
    return [...seen.values()];
});

/** Ring für „heute" in derselben Farbe — ausgeschrieben, damit Tailwind die Klassen findet. */
const RING = {
    'bg-success': 'border-success', 'bg-warn': 'border-warn', 'bg-danger': 'border-danger',
    'bg-info': 'border-info', 'bg-accent': 'border-accent', 'bg-ink-3': 'border-ink-3',
};

/** Dasselbe als Ring um die Säule von heute. */
const TODAY_RING = {
    'bg-success': 'ring-success', 'bg-warn': 'ring-warn', 'bg-danger': 'ring-danger',
    'bg-info': 'ring-info', 'bg-accent': 'ring-accent', 'bg-ink-3': 'ring-ink-3',
};

/** Gelaufene Kilometer dieser Woche — nur Läufe, denn der Plan zählt nur Läufe. */
const weekRunKm = computed(() => {
    const monday = new Date();
    monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
    monday.setHours(0, 0, 0, 0);

    // Beginnt der Plan mitten in der Woche, zählen nur Läufe ab Planbeginn.
    // Sonst stand „18,3 von 22,5 km“ (81 %) da, bevor die erste geplante
    // Einheit gelaufen war — die Kilometer stammten aus der Zeit davor.
    const planDates = (props.weekPlan || []).map((p) => p.date).filter(Boolean).sort();
    const from = planDates.length ? new Date(planDates[0] + 'T00:00:00') : monday;
    const start = from > monday ? from : monday;

    return props.recentActivities
        .filter((a) => a.start_date && new Date(a.start_date) >= start && ['Run', 'TrailRun', 'VirtualRun'].includes(a.type))
        .reduce((s, a) => s + (a.distance || 0), 0) / 1000;
});

const weekPlannedKm = computed(() => (props.weekPlan || [])
    .filter((p) => p.type !== 'rest' && p.status !== 'skipped' && !p.sport_type)
    .reduce((s, p) => s + (p.distance_km || 0), 0));

const weekProgressPct = computed(() => {
    if (!weekPlannedKm.value) return 0;

    return Math.min(100, Math.round(weekRunKm.value / weekPlannedKm.value * 100));
});

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


function openActivityDetail(activity) {
    router.visit(activityHref(activity.id, '/dashboard'));
}


/** 10,5 statt 10.50 — eine Nachkommastelle reicht für eine Liste. */
function formatDistance(meters) {
    return fmtNum(meters / 1000, 1);
}

function formatTime(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    // Wie eine Uhr: 46:22 oder 1:02:10 statt „46m 22s".
    const pad = (n) => String(n).padStart(2, '0');
    if (hours > 0) return `${hours}:${pad(minutes)}:${pad(secs)}`;
    return `${minutes}:${pad(secs)}`;
}

// ── Workout-Struktur für angenommene Empfehlung ──────────────────────────────
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

/**
 * Balkenbreite in Prozent. Ein Workout, das nur nach Strecke vorgibt, hat
 * ohne Schwellenpace keine geschaetzte Dauer — die Summe waere 0 und jede
 * Breite NaN. Dann teilen sich die Abschnitte die Leiste gleichmaessig.
 */
function recStepWidthPct(step) {
    if (!recTotalStepDuration.value) {
        return (100 / recStepsWithReps.value.length).toFixed(1);
    }

    return ((step.duration_min || 0) / recTotalStepDuration.value * 100).toFixed(1);
}

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
        if (rec.type !== 'race_prep') {
            recStepsLoading.value = true;
            axios.get(route('training-sessions.steps', rec.id))
                .then(({ data }) => { recSteps.value = data.steps ?? null; })
                .catch(() => { recStepsError.value = 'Struktur konnte nicht geladen werden.'; })
                .finally(() => { recStepsLoading.value = false; });
        }
    }
});

/*
 * Dritte Kopie derselben Tabelle, hier als Emoji-Map. Ersetzt durch den
 * gemeinsamen Zugriff; das Icon ist jetzt ein Strich-Pfad.
 */
const activityIcon = (type) => activityType(type).icon;

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
    if (activity.type === 'Swim') return `${formatSwimPace(activity.average_speed)} /100 m`;
    if (['Ride', 'VirtualRide'].includes(activity.type)) return `${fmtNum(activity.average_speed * 3.6)} km/h`;
    return `${formatPaceFromSpeed(activity.average_speed)} /km`;
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
            recommendationHint.value = data.recommendation_message || 'Bitte trage deine Tagesform für heute ein.';
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

    const W = 560, H = 120, padX = 6, padY = 10;
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
        return { x, y, pace: h.pace_formatted, date: String(h.date ?? '').slice(0, 6) };
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

    const fastest = history.reduce((a, b) => (b.pace < a.pace ? b : a)).pace_formatted;

    return { points, pathD, fillD, W, H, minPace, maxPace, padX, padY, fastest };
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

onMounted(() => {
    getTodayRecommendation();

    // Telegram-Knopf und Push öffnen /dashboard?tagesform=1 — direkt der
    // Check-in, sofern er noch fehlt. Der Parameter fliegt wieder raus,
    // sonst öffnet ein Neuladen das Sheet erneut.
    const url = new URL(window.location.href);
    if (url.searchParams.has('tagesform')) {
        if (!wellbeingEnteredToday.value) showWellbeingModal.value = true;
        url.searchParams.delete('tagesform');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
    // „Übersicht anpassen" auf der Mehr-Seite.
    if (url.searchParams.has('layout')) {
        openLayoutSheet();
        url.searchParams.delete('layout');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
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

// ── Übersicht anpassen ───────────────────────────────────────────────────────
// Reihenfolge und Sichtbarkeit der Module unter „Heute". Die Liste kommt
// vollständig vom Server (App\Services\DashboardLayout) — auch Module, die
// gerade nichts zu zeigen haben, damit man sie im Sheet findet.
const layout       = ref(props.dashboardLayout);
const layoutSheet  = ref(false);
const layoutDraft  = ref([]);
const layoutSaving = ref(false);
const layoutError  = ref('');

// Ein Modul ohne Daten bleibt weg, statt eine leere Zelle ins Raster zu legen.
const moduleHasData = computed(() => ({
    naechste:    (props.upcomingSessions || []).length > 0,
    zuletzt:     !!props.lastRun,
    form:        !!props.trainingLoad,
    intensitaet: !!props.intensityDistribution,
    rueckblick:  !!props.weeklyReview,
    erholung:    !!props.garminMetrics,
}));

const visibleModules = computed(() =>
    layout.value.filter(m => !m.hidden && !LEBEN_KEYS.includes(m.key) && moduleHasData.value[m.key] !== false));

/**
 * Die halben Module stehen ab lg zu zweit, ab 2xl zu dritt nebeneinander,
 * und `grid-flow-row-dense` zieht sie über die breiten hinweg zusammen.
 * Geht die Zahl nicht auf, blieb am Ende ein Loch neben einer einzelnen
 * kleinen Karte (Jan, 29.10.2026: „kleine Boxen, die das Design auf dem
 * Desktop brechen"). Das letzte halbe Modul nimmt deshalb den Rest der
 * Zeile ein.
 */
const lastHalfSpan = computed(() => {
    const halves = visibleModules.value.filter(m => !m.wide);
    const last = halves.at(-1)?.key ?? null;
    // Volle Klassennamen, damit Tailwind sie findet. Ab 2xl muss die
    // lg-Spanne gegebenenfalls zurückgesetzt werden (col-span-1).
    const lg = halves.length % 2 === 1 ? 'lg:col-span-2' : '';
    const xl = ['2xl:col-span-1', '2xl:col-span-3', '2xl:col-span-2'][halves.length % 3];
    return { key: last, cls: `${lg} ${xl}` };
});

function moduleClass(m) {
    if (m.wide) return 'min-w-0 lg:col-span-full';
    return m.key === lastHalfSpan.value.key ? `min-w-0 ${lastHalfSpan.value.cls}` : 'min-w-0';
}

function moduleHint(m) {
    if (moduleHasData.value[m.key] !== false) return null;
    return { form: 'erscheint mit genug Trainingsdaten', intensitaet: 'erscheint mit genug Läufen',
        rueckblick: 'erscheint montags', erholung: 'nur mit Garmin', naechste: 'erscheint mit einem Plan', zuletzt: 'erscheint nach einem Lauf mit Strecke' }[m.key];
}

function openLayoutSheet() {
    layoutDraft.value = layout.value.map(m => ({ ...m }));
    layoutError.value = '';
    layoutSheet.value = true;
}

function moveModule(index, delta) {
    const to = index + delta;
    if (to < 0 || to >= layoutDraft.value.length) return;
    const list = [...layoutDraft.value];
    [list[index], list[to]] = [list[to], list[index]];
    layoutDraft.value = list;
}

async function saveLayout() {
    layoutSaving.value = true;
    try {
        const { data } = await axios.put(route('dashboard.layout'), {
            order:  layoutDraft.value.map(m => m.key),
            hidden: layoutDraft.value.filter(m => m.hidden).map(m => m.key),
        });
        layout.value = data.layout;
        layoutSheet.value = false;
    } catch (e) {
        layoutError.value = 'Speichern fehlgeschlagen.';
    } finally {
        layoutSaving.value = false;
    }
}

async function resetLayout() {
    layoutSaving.value = true;
    try {
        const { data } = await axios.delete(route('dashboard.layout.reset'));
        layout.value = data.layout;
        layoutDraft.value = data.layout.map(m => ({ ...m }));
    } catch (e) {
        layoutError.value = 'Zurücksetzen fehlgeschlagen.';
    } finally {
        layoutSaving.value = false;
    }
}
</script>

<template>
    <Head title="Übersicht" />

    <AuthenticatedLayout :immersive="!!heroSession">

        <!--
            Grosser Titel mit dem Datum darueber, wie „Übersicht" in Health.
            Der Farbschleier der enduco-Richtung ist weg: Farbe steht nur
            noch dort, wo sie etwas bedeutet.
        -->
        <div class="min-h-screen">
            <PageHeader v-if="!heroSession" :eyebrow="headerLine" title="Heute" class="lg:hidden" />
            <PageHeader :eyebrow="headerLine" :title="greeting" class="hidden lg:block">
                <template #action>
                    <button type="button" @click="openChat()"
                        class="inline-flex h-11 items-center gap-2 rounded-full bg-accent px-[18px] text-[15px] font-semibold text-white transition active:scale-95">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" /></svg>
                        Coach
                    </button>
                </template>
            </PageHeader>

            <div class="space-y-7 px-4 pb-8 pt-2 lg:space-y-6 lg:px-8" :class="heroSession ? 'max-lg:!pt-0 max-lg:!space-y-[22px]' : ''">
                <!-- ══════════════════════════════════════════════════
                     REIHE 1: HEUTE MIT FOTO + RENNEN (Mockup „Zone3 mit mehr
                     Leben", 10.11.2026). Am Telefon randlos bis unter die
                     Statusleiste, mit Datum, „Heute" und Avatar im Foto.
                     ══════════════════════════════════════════════════ -->
                <div v-if="heroSession" class="grid gap-5 lg:grid-cols-3">
                    <TodayHero :session="heroSession" :badge="heroBadge" :context="heroContext" immersive
                        :class="props.raceCard ? 'lg:col-span-2' : 'lg:col-span-3'">
                        <template #top>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-[13px] font-semibold text-white/85">{{ heroDateLine }}</p>
                                    <h1 class="text-[28px] font-extrabold leading-tight tracking-[-0.03em]">Heute</h1>
                                </div>
                                <!-- Weiße Scheibe mit Schatten: hinter dem Avatar liegt „Mehr",
                                     auf einem hellen Foto ging der Glaskreis unter (Jan, 10.11.2026). -->
                                <Link :href="route('more')" aria-label="Mehr" class="rounded-full shadow-[0_2px_14px_rgba(0,0,0,0.45)] transition active:scale-95">
                                    <UserAvatar v-if="page.props.auth.user.avatar" :user="page.props.auth.user" size="md" class="ring-[2.5px] ring-white" />
                                    <span v-else class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-[15px] font-extrabold tracking-[-0.02em] text-black">{{ initials(page.props.auth.user.name) }}</span>
                                </Link>
                            </div>
                        </template>

                        <div v-if="heroSession.type !== 'rest'" class="flex flex-wrap items-center gap-2.5">
                            <!-- Vorschlag des Coaches: Einplanen ist die Entscheidung -->
                            <template v-if="heroKind === 'proposal'">
                                <button type="button" @click="acceptRecommendation()" :disabled="acceptingRecommendation || adjustingDirection !== null"
                                    class="inline-flex h-11 items-center gap-2 rounded-full bg-white px-5 text-[15px] font-bold text-black transition active:scale-95 disabled:opacity-50">
                                    <span v-if="acceptingRecommendation" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Einplanen
                                </button>
                                <button type="button" @click="adjustRecommendation('softer')" :disabled="adjustingDirection !== null || acceptingRecommendation"
                                    class="inline-flex h-11 items-center gap-2 rounded-full bg-white/20 px-4 text-[15px] font-semibold text-white backdrop-blur-md transition active:scale-95 disabled:opacity-50">
                                    <span v-if="adjustingDirection === 'softer'" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Leichter
                                </button>
                                <button type="button" @click="adjustRecommendation('harder')" :disabled="adjustingDirection !== null || acceptingRecommendation"
                                    class="inline-flex h-11 items-center gap-2 rounded-full bg-white/20 px-4 text-[15px] font-semibold text-white backdrop-blur-md transition active:scale-95 disabled:opacity-50">
                                    <span v-if="adjustingDirection === 'harder'" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Schwerer
                                </button>
                            </template>
                            <template v-else>
                                <Link v-if="heroKind === 'plan'" :href="todaySessionHref"
                                    class="inline-flex h-11 items-center rounded-full bg-white px-5 text-[15px] font-bold text-black transition active:scale-95">
                                    Details
                                </Link>
                                <button v-if="heroKind === 'plan' && heroSession.status === 'planned'" type="button" @click="openSwap"
                                    class="inline-flex h-11 items-center rounded-full bg-white/20 px-5 text-[15px] font-semibold text-white backdrop-blur-md transition active:scale-95">
                                    Tauschen
                                </button>
                                <a v-if="heroSession.zwo_url && heroSession.status === 'planned'" :href="heroSession.zwo_url" download
                                    class="inline-flex h-11 items-center rounded-full bg-white/20 px-5 text-[15px] font-semibold text-white backdrop-blur-md transition active:scale-95">
                                    Für Zwift
                                </a>
                                <span v-else-if="$page.props.auth.garminSync && heroSession.status === 'planned'"
                                    class="inline-flex h-11 items-center gap-2 rounded-full bg-white/20 px-4 text-[15px] font-semibold text-white backdrop-blur-md">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="3" /><path d="M9 6l.6-3h4.8L15 6M9 18l.6 3h4.8l.6-3" /></svg>
                                    Steht auf deiner Garmin
                                </span>
                                <WatchButton v-else-if="heroSession.watch_url && heroSession.status === 'planned'"
                                    :session-id="heroSession.id" :href="heroSession.watch_url" variant="secondary"
                                    class="!h-11 !rounded-full !border-0 !bg-white/20 !px-4 !text-white backdrop-blur-md" v-slot="{ done }">
                                    <span class="inline-flex items-center gap-2 text-[15px] font-semibold">
                                        <svg v-if="done" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                                        <svg v-else class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="3" /><path d="M9 6l.6-3h4.8L15 6M9 18l.6 3h4.8l.6-3" /></svg>
                                        {{ done ? 'Auf der Watch' : 'Auf die Watch' }}
                                    </span>
                                </WatchButton>
                            </template>
                        </div>
                    </TodayHero>
                    <RaceCard v-if="props.raceCard" :race="props.raceCard" class="max-lg:hidden" />
                </div>

                <!-- Einsteiger-Checkliste — fest oben, bis alles erledigt ist. -->
                <StarterChecklistCard v-if="starterChecklist" :checklist="starterChecklist" @action="starterAction" />

                <!-- ══════════════════════════════════════════════════
                     ZIELPRÜFUNG — wöchentlich, nur bei echter Abweichung
                     ══════════════════════════════════════════════════ -->
                <AppCard v-if="goalCheck && !goalAnswered">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path v-if="goalCheck.kind === 'too_conservative'" stroke-linecap="round" stroke-linejoin="round" d="M12 19.5V4.5m0 0L6.75 9.75M12 4.5l5.25 5.25" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0 0 5.25-5.25M12 19.5 6.75 14.25" /></svg>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[17px] font-semibold text-ink">{{ goalCheck.headline }}</h2>
                            <p class="mt-1 text-[13px] leading-relaxed text-ink-3">{{ goalCheck.detail }}</p>

                            <!-- Die beiden Zahlen nebeneinander: worauf der Plan
                                 zielt, und was die Form derzeit trägt. -->
                            <div class="mt-3 flex gap-2">
                                <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                    <p class="text-[13px] text-ink-3">Dein Ziel</p>
                                    <p class="mt-0.5 font-rounded text-[22px] font-bold tabular-nums text-ink">{{ goalCheck.target_full ?? goalCheck.target }}</p>
                                </div>
                                <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                    <p class="text-[13px] text-ink-3">Deine Form</p>
                                    <p class="mt-0.5 font-rounded text-[22px] font-bold tabular-nums text-ink">{{ goalCheck.predicted }}</p>
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
                            <span class="text-[13px] text-ink-3">h : min</span>
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
                            {{ goalCheck.suggested ? `Ziel ${goalCheck.suggested}` : 'Ziel anpassen' }}
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
                            <h2 class="text-[17px] font-semibold text-ink">Passt deine kommende Woche?</h2>
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

                <!--
                    Hier stand eine Begrüßungskarte („Guten Abend, Viel Spaß bei
                    deinem Training" plus ein KI-Spruch auf Englisch). Sie kostete
                    jeden Tag einen Modellaufruf und sagte nichts, was die Seite
                    nicht ohnehin zeigt. Geblieben ist sie nur dort, wo sie etwas
                    will: eine neue Bestzeit feiern oder nach der Tagesform fragen.
                    Das Wetter steht in der Datumszeile darüber.
                -->
                <AppCard v-if="(coachPrMessage && !personalRecord && !prDismissed) || !checkinDone">
                    <template v-if="coachPrMessage && !personalRecord && !prDismissed">
                        <h2 class="text-[22px] font-bold leading-7 tracking-tight text-ink">Stark gelaufen, {{ firstName }}!</h2>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-2">{{ coachPrMessage }}</p>
                        <AppButton size="md" variant="secondary" class="mt-4" @click="dismissPr">Danke, gesehen</AppButton>
                    </template>
                    <template v-else>
                        <h2 class="text-[22px] font-bold leading-7 tracking-tight text-ink">Wie geht's dir heute?</h2>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-2">
                            Trag kurz deine Tagesform ein, danach prüft {{ coach ? coach.name : 'dein Coach' }} das heutige Training.
                        </p>
                        <AppButton size="md" class="mt-4" @click="showWellbeingModal = true">Tagesform eintragen</AppButton>
                    </template>
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
                     VORSCHLAG DES COACHES
                     ═══════════════════════════════════════════════════
                     Kein Automatismus mehr: der Coach schlägt vor, der
                     Athlet entscheidet. -->
                <!--
                    Aufbau wie die Story-Karte (28.10.2026): vorher → nachher
                    in einer Zeile, ein Satz Begründung, zwei gleich breite
                    Knöpfe. Vorher eine gelbe Warnbox mit drei Textblöcken —
                    ein Vorschlag ist keine Warnung.
                -->
                <div v-if="props.coachRecommendation" class="rounded-card bg-surface p-5">
                    <p class="flex items-center gap-2 text-[15px] font-semibold text-accent-ink">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3M12 7.5c.6 2.4 2.1 3.9 4.5 4.5-2.4.6-3.9 2.1-4.5 4.5-.6-2.4-2.1-3.9-4.5-4.5 2.4-.6 3.9-2.1 4.5-4.5Z" />
                        </svg>
                        Vorschlag vom Coach
                    </p>

                    <div class="mt-2.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
                        <span class="text-[17px] text-ink-3 line-through decoration-[1.5px]">{{ props.coachRecommendation.before?.title }}</span>
                        <span class="text-ink-3" aria-hidden="true">→</span>
                        <span class="text-[20px] font-bold leading-7 tracking-tight text-ink">{{ props.coachRecommendation.after?.title }}</span>
                    </div>

                    <!-- Nur die Zahlen, die sich ändern: neu groß, alt am Label -->
                    <div v-if="recommendationNumbers.length" class="mt-4 grid gap-2"
                        :class="recommendationNumbers.length === 3 ? 'grid-cols-[1fr_1fr_1.4fr]' : 'grid-cols-2'">
                        <div v-for="c in recommendationNumbers" :key="c.label" class="min-w-0">
                            <p class="text-[13px] text-ink-3">
                                {{ c.label }} <span class="tabular-nums line-through">{{ c.from }}</span>
                            </p>
                            <p class="whitespace-nowrap">
                                <span class="font-rounded font-bold tabular-nums tracking-tight text-ink"
                                    :class="c.to.length > 6 ? 'text-[20px] leading-8' : 'text-[30px] leading-9'">{{ c.to }}</span>
                                <span v-if="c.unit && c.to !== '—'" class="ml-0.5 text-[15px] font-semibold text-ink-3">{{ c.unit }}</span>
                            </p>
                        </div>
                    </div>

                    <p v-if="props.coachRecommendation.reason" class="mt-3 text-[15px] leading-snug text-ink-2">
                        {{ props.coachRecommendation.reason }}
                    </p>

                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <AppButton variant="secondary" block :disabled="recommendationBusy" @click="decideRecommendation('reject')">
                            Ablehnen
                        </AppButton>
                        <AppButton block :disabled="recommendationBusy" @click="decideRecommendation('accept')">
                            Annehmen
                        </AppButton>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     HEUTIGES TRAINING (ohne Plan oder ohne Einheit)
                     ══════════════════════════════════════════════════ -->
                <section v-if="!heroSession">
                    <SectionHeader title="Training">
                        <template #action>
                            <AppButton v-if="props.hasActivePlan" variant="plain"
                                :href="props.todayPlanSession?.event_id ? `/events/${props.todayPlanSession.event_id}/plan` : '/events'">
                                Plan
                            </AppButton>
                            <span v-else-if="props.aiUsage.limit - props.aiUsage.used <= 5" class="text-[13px] text-ink-3"
                                :title="`${props.aiUsage.used} von ${props.aiUsage.limit} Coach-Anfragen heute verbraucht`">
                                noch {{ Math.max(0, props.aiUsage.limit - props.aiUsage.used) }} Coach-Anfragen
                            </span>
                        </template>
                    </SectionHeader>

                    <template v-if="props.hasActivePlan && !props.bridgeToPlan">
                        <AppCard>
                            <EmptyState title="Heute steht nichts an" description="Erholung gehört zum Training. Genieß den freien Tag.">
                                <AppButton href="/events" variant="secondary">Zum Plan</AppButton>
                            </EmptyState>
                        </AppCard>
                    </template>

                    <!-- ── Ohne Plan: Empfehlung ────────────────────── -->
                    <template v-else>
                        <p v-if="props.bridgeToPlan" class="mb-3 px-1 text-[15px] leading-snug text-ink-2">
                            Dein Plan startet am {{ props.bridgeToPlan.date }}. Bis dahin schlägt dir der Coach jeden Tag etwas vor, das die Form hält.
                        </p>
                        <div v-if="props.todayRecommendationSession" class="space-y-3">
                            <SessionCard :session="props.todayRecommendationSession" badge="Geplant" badge-tone="success" />

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
                                                width:  recStepWidthPct(s) + '%',
                                                height: (stepHeightPct[s.type] ?? 60) + '%',
                                            }"
                                            :class="[stepBarColor[s.type] ?? 'bg-accent', 'rounded-md']"
                                            :title="`${s.label}: ${stepAmount(s) ?? ''}`"
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
                                                    dazwischen {{ step.pairedRest.label }} · {{ stepAmount(step.pairedRest) }}
                                                </p>
                                            </div>
                                            <span class="shrink-0 text-[13px] tabular-nums text-ink-3">{{ stepAmount(step) }}</span>
                                            <span v-if="step.pace_target" class="shrink-0 text-[13px] font-semibold tabular-nums text-ink">{{ step.pace_target }}</span>
                                        </div>
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
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success text-white" aria-hidden="true"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <div>
                                        <p class="text-[15px] font-bold text-ink">Eingeplant</p>
                                        <p class="mt-0.5 text-[13px] text-ink-3">{{ trainingRecommendation?.title }} steht in deinem Kalender.</p>
                                    </div>
                                </div>
                            </AppCard>

                            <div v-else-if="showRecommendation && trainingRecommendation" class="space-y-3">
                                <SessionCard :session="trainingRecommendation" :badge="coach ? coach.name : 'Coach'" badge-tone="accent" />

                                <!--
                                    Drei Alternativen sind keine drei gleichen
                                    Knoepfe. Hier standen „🧘 Lockerer / ✓
                                    Einplanen / 🔥 Intensiver" nebeneinander im
                                    Drittelraster — gleich gross, gleich
                                    wichtig, mit Emoji als Ikonografie. Das
                                    liest sich wie eine Umfrage, nicht wie eine
                                    Entscheidung.

                                    „Einplanen" IST die Entscheidung und
                                    bekommt den gefuellten Knopf ueber die
                                    volle Breite. Leichter und schwerer sind
                                    Nebenwege und stehen als Textaktionen
                                    darunter.
                                -->
                                <!-- Ein Ruhetag (krank, Pause) wird nicht eingeplant und nicht „schwerer" gemacht. -->
                                <div v-if="trainingRecommendation.type !== 'rest'" class="space-y-1">
                                    <button @click="acceptRecommendation()"
                                        :disabled="acceptingRecommendation || adjustingDirection !== null"
                                        class="flex h-[50px] w-full items-center justify-center gap-2 rounded-field bg-accent text-[17px] font-semibold tracking-[-0.2px] text-white transition-transform duration-150 ease-out-strong active:scale-[0.97] disabled:opacity-40">
                                        <span v-if="acceptingRecommendation" class="h-5 w-5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        Einplanen
                                    </button>

                                    <div class="flex items-center justify-center gap-2.5 pt-0.5">
                                        <button @click="adjustRecommendation('softer')"
                                            :disabled="adjustingDirection !== null || acceptingRecommendation"
                                            class="inline-flex items-center gap-1.5 px-1 py-1.5 text-[15px] font-medium text-accent transition-transform duration-150 ease-out-strong active:scale-[0.97] disabled:opacity-40">
                                            <span v-if="adjustingDirection === 'softer'" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                            Leichter
                                        </button>
                                        <span aria-hidden="true" class="text-ink-3">·</span>
                                        <button @click="adjustRecommendation('harder')"
                                            :disabled="adjustingDirection !== null || acceptingRecommendation"
                                            class="inline-flex items-center gap-1.5 px-1 py-1.5 text-[15px] font-medium text-accent transition-transform duration-150 ease-out-strong active:scale-[0.97] disabled:opacity-40">
                                            <span v-if="adjustingDirection === 'harder'" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                            Schwerer
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Fehlt nur die Tagesform, fragt die Karte oben danach. -->
                            <AppCard v-else-if="recommendationHint && checkinDone">
                                <p class="text-[15px] font-bold text-ink">Fast geschafft</p>
                                <p class="mt-1 text-[15px] text-ink-3">{{ recommendationHint }}</p>
                                <AppButton size="sm" class="mt-4" @click="showWellbeingModal = true">Tagesform eintragen</AppButton>
                            </AppCard>

                            <AppCard v-else-if="checkinDone">
                                <EmptyState title="Noch keine Empfehlung" description="Dein Coach schlägt dir eine Einheit passend zu deiner Tagesform vor.">
                                    <AppButton @click="getTodayRecommendation">Empfehlung holen</AppButton>
                                </EmptyState>
                            </AppCard>
                        </template>
                    </template>
                </section>

                <!-- Ohne Aufmacher: das Rennen am Desktop als eigene Reihe -->
                <RaceCard v-if="!heroSession && props.raceCard" :race="props.raceCard" class="max-lg:hidden lg:!h-auto" />

                <!-- ══════════════════════════════════════════════════
                     REIHE 2: WOCHE + COACH. Am Telefon: Coach, Woche, Rennen.
                     ══════════════════════════════════════════════════ -->
                <div v-if="props.coachLine || (shows('woche') && weekPlan.length) || props.raceCard" class="grid gap-[22px] lg:grid-cols-3 lg:gap-5">
                    <CoachLine v-if="props.coachLine" :text="props.coachLine" :coach="coach" class="lg:col-start-3 lg:row-start-1" @reply="openChat()" />
                    <section v-if="shows('woche')" aria-label="Deine Woche" class="rounded-[20px] bg-surface p-[18px] lg:col-span-2 lg:col-start-1 lg:row-start-1 lg:rounded-[24px] lg:px-7 lg:py-[26px]"
                        :class="props.coachLine ? '' : 'lg:col-span-3'">
                        <div class="flex items-baseline justify-between gap-4">
                            <Link href="/statistics" class="text-[20px] font-extrabold tracking-[-0.02em] text-ink lg:text-[22px]">
                                <span class="lg:hidden">Woche</span><span class="hidden lg:inline">Deine Woche</span>
                            </Link>
                            <p class="whitespace-nowrap text-[13px] tabular-nums text-ink-3 lg:text-[15px]">
                                <span class="text-[22px] font-extrabold tracking-[-0.03em] text-ink lg:text-[28px]">{{ fmtNum(weekPlannedKm && !props.bridgeToPlan ? weekRunKm : weekStats.km) }}</span>
                                <template v-if="weekPlannedKm && !props.bridgeToPlan"> von {{ fmtNum(weekPlannedKm) }} km</template><template v-else> km</template>
                            </p>
                        </div>
                        <!-- Säulen: Höhe = geplante Minuten, voll = erledigt, blass = geplant, Ring = heute -->
                        <div class="mt-3.5 grid h-24 grid-cols-7 items-end gap-2 lg:mt-[22px] lg:h-[150px] lg:gap-3" role="list" aria-label="Diese Woche">
                            <div v-for="d in weekColumns" :key="d.key" role="listitem" class="flex h-full flex-col justify-end"
                                :aria-label="`${d.label}: ${d.km ?? 'nichts'}`">
                                <div class="rounded-[7px] transition-[height] duration-500 ease-out-strong lg:rounded-[10px]"
                                    :style="{ height: d.height + '%' }"
                                    :class="[
                                        !d.dot || d.rest ? 'bg-surface-3' : d.dot,
                                        d.dot && !d.rest && d.state !== 'done' && d.state !== 'today' ? 'opacity-25' : '',
                                        d.state === 'today' && d.dot && !d.rest ? 'ring-2 ring-offset-2 ring-offset-surface ' + (TODAY_RING[d.dot] ?? 'ring-accent') : '',
                                    ]" />
                            </div>
                        </div>
                        <div class="mt-2 grid grid-cols-7 gap-2 text-center text-[12px] text-ink-3 lg:mt-2.5 lg:gap-3 lg:text-[13px]">
                            <span v-for="d in weekColumns" :key="d.key" :class="d.isToday ? 'font-extrabold text-ink' : ''">
                                <span class="lg:hidden">{{ d.label.charAt(0) }}</span><span class="hidden lg:inline">{{ d.label }}</span>
                            </span>
                        </div>
                        <div v-if="weekLegend.length" class="mt-4 hidden flex-wrap gap-x-4 gap-y-1.5 lg:flex">
                            <span v-for="l in weekLegend" :key="l.dot" class="inline-flex items-center gap-1.5 text-[13px] text-ink-2">
                                <span class="h-2 w-2 rounded-full" :class="l.dot" />{{ l.label }}
                            </span>
                        </div>
                    </section>
                    <RaceCard v-if="props.raceCard" :race="props.raceCard" class="lg:hidden" />
                </div>

                <!-- ══════════════════════════════════════════════════
                     REIHE 3: ALS NÄCHSTES + ZULETZT
                     ══════════════════════════════════════════════════ -->
                <div v-if="(shows('naechste') && props.upcomingSessions.length) || (shows('zuletzt') && props.lastRun)" class="grid gap-[22px] lg:grid-cols-3 lg:gap-5">
                    <section v-if="shows('naechste') && props.upcomingSessions.length" aria-label="Als Nächstes" class="min-w-0"
                        :class="shows('zuletzt') && props.lastRun ? 'lg:col-span-2' : 'lg:col-span-3'">
                        <div class="mx-1 mb-2.5 flex items-baseline justify-between lg:mx-0 lg:mb-3">
                            <h2 class="text-[20px] font-extrabold tracking-[-0.02em] text-ink lg:text-[22px]">Als Nächstes</h2>
                            <Link :href="props.todayPlanSession?.event_id ? `/events/${props.todayPlanSession.event_id}/plan` : '/events'" class="text-[15px] font-semibold text-accent">Plan</Link>
                        </div>
                        <UpcomingSessions :sessions="props.upcomingSessions" />
                    </section>
                    <section v-if="shows('zuletzt') && props.lastRun" aria-label="Zuletzt"
                        :class="shows('naechste') && props.upcomingSessions.length ? '' : 'lg:col-span-3'">
                        <div class="mx-1 mb-2.5 flex items-baseline justify-between lg:mx-0 lg:mb-3">
                            <h2 class="text-[20px] font-extrabold tracking-[-0.02em] text-ink lg:text-[22px]">Zuletzt</h2>
                            <Link href="/activities" class="text-[15px] font-semibold text-accent">Alle</Link>
                        </div>
                        <LastRunCard :run="props.lastRun" />
                    </section>
                </div>

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
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-3"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m3.75-9.75H6.75A2.25 2.25 0 0 0 4.5 6v13.5A2.25 2.25 0 0 0 6.75 21h10.5A2.25 2.25 0 0 0 19.5 19.5V6a2.25 2.25 0 0 0-2.25-2.25H12.75Z" /></svg></div>
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
                    <SectionHeader title="Wie lief's?">
                        <template v-if="pendingRatingSessions.length > 1" #action>
                            <AppButton variant="plain" size="sm" @click="skipRating(pendingRatingSessions.map(s => s.id))">Alle überspringen</AppButton>
                        </template>
                    </SectionHeader>
                    <AppCard flush>
                        <div class="divide-y divide-line">
                            <div v-for="session in pendingRatingSessions" :key="session.id">
                                <div v-if="ratingOpenId !== session.id" class="flex items-center">
                                <button @click="openRating(session)"
                                    class="flex min-w-0 flex-1 items-center justify-between gap-3 py-4 pl-5 pr-2 text-left transition-colors hover:bg-surface-2">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <svg class="h-5 w-5 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true" v-html="sessionType(session.type).icon" />
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
                                <button type="button" :aria-label="'Nicht bewerten: ' + (session.activity_name || session.title || 'Einheit')"
                                    class="mr-3 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-3 transition active:scale-90 hover:text-ink"
                                    @click="skipRating([session.id])">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                                </div>

                                <div v-else class="px-5 py-4">
                                    <div class="mb-4 flex items-center justify-between">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <svg class="h-5 w-5 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true" v-html="sessionType(session.type).icon" />
                                            <p class="truncate text-[15px] font-bold text-ink">{{ session.activity_name || session.title || 'Einheit' }}</p>
                                        </div>
                                        <button @click="ratingOpenId = null" class="ml-2 shrink-0 text-lg leading-none text-ink-3 hover:text-ink"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
                                    </div>

                                    <p class="mb-2 text-[13px] font-semibold text-ink-2">Wie war die Einheit?</p>
                                    <div class="mb-4 flex items-center gap-1.5">
                                        <button v-for="star in 5" :key="star"
                                            @click="ratingStars = ratingStars === star ? 0 : star"
                                            class="flex h-10 w-10 items-center justify-center rounded-full text-lg transition"
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
                                            class="h-11 rounded-full text-[13px] font-bold transition active:scale-90 sm:h-9"
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
                                        <button type="button" class="shrink-0 text-[13px] font-semibold text-ink-3 hover:text-ink" @click="skipRating([session.id])">Nicht bewerten</button>
                                        <a v-if="session.activity_id" :href="activityHref(session.activity_id, '/dashboard')" class="shrink-0 text-[13px] text-ink-3 hover:text-ink">Details</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </AppCard>
                </section>

                <!--
                    Ab hier ordnet jeder Athlet selbst: Reihenfolge und Sichtbarkeit
                    kommen aus `dashboardLayout` (App\Services\DashboardLayout). Alles
                    darueber bleibt fest — Heute, und die Karten, die eine Antwort
                    verlangen. Ein ausgeblendeter Coach-Vorschlag waere eine
                    Entscheidung, die nie getroffen wird.

                    Die Bloecke stehen im DOM in der gewaehlten Reihenfolge (v-for
                    statt CSS `order`), damit Vorlesen und Tabulator dieselbe Folge
                    sehen wie das Auge. Halbe Module liegen ab lg nebeneinander.
                -->
                <div class="grid grid-cols-1 gap-x-5 gap-y-7 lg:grid-flow-row-dense lg:grid-cols-2 2xl:grid-cols-3">
                    <template v-for="m in visibleModules" :key="m.key">
                        <div v-if="m.key === 'koerper'" :class="moduleClass(m)">
                            <!-- ══════════════════════════════════════════════════
                                 KÖRPERWERTE — Garmin, sonst der Check-in
                                 ══════════════════════════════════════════════════ -->
                            <!--
                                Stand frueher ganz oben, vor dem Training. Was heute
                                ansteht, ist die erste Frage beim Oeffnen; die Koerperwerte
                                sind die zweite — sie erklaeren das Training, sie ersetzen
                                es nicht.
                            -->
                            <section v-if="metricTiles.length">
                                <SectionHeader title="Körper">
                                    <template #action>
                                        <span class="text-[15px] text-ink-3">{{ metricSource }}</span>
                                    </template>
                                </SectionHeader>
                                <!-- Eine Reihe (Jan): am iPhone wischbar, ab lg alle nebeneinander -->
                                <div class="no-scrollbar -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-2.5 overflow-x-auto px-4 lg:mx-0 lg:grid lg:overflow-visible lg:px-0"
                                    :class="metricTiles.length === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
                                    <GaugeTile v-for="t in metricTiles" :key="t.key" v-bind="t"
                                        class="w-[28%] min-w-[104px] shrink-0 snap-start lg:w-auto lg:min-w-0" />
                                </div>
                            </section>

                            <!-- Ohne Garmin und ohne Check-in stand hier eine dritte
                                 Aufforderung zur Tagesform. Es fragt jetzt nur noch
                                 die Karte oben. -->
                        </div>

                        <div v-if="m.key === 'form'" :class="moduleClass(m)">
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
                                    <!-- Je Kennzahl eine Farbe, wie in Health — dieselbe
                                         Farbe traegt die Linie im Verlauf darunter. -->
                                    <div class="grid grid-cols-3 gap-4">
                                        <div>
                                            <p class="text-[15px] font-semibold text-fitness">Fitness</p>
                                            <p class="font-rounded text-[28px] font-bold leading-8 tabular-nums text-ink">{{ fmtNum(props.trainingLoad.ctl, 0) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[15px] font-semibold text-warn">Ermüdung</p>
                                            <p class="font-rounded text-[28px] font-bold leading-8 tabular-nums text-ink">{{ fmtNum(props.trainingLoad.atl, 0) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[15px] font-semibold text-form">Form</p>
                                            <p class="font-rounded text-[28px] font-bold leading-8 tabular-nums text-ink">{{ props.trainingLoad.tsb > 0 ? '+' : props.trainingLoad.tsb < 0 ? '−' : '' }}{{ fmtNum(Math.abs(props.trainingLoad.tsb), 0) }}</p>
                                        </div>
                                    </div>

                                    <div v-if="loadChartData" class="mt-4 overflow-hidden">
                                        <svg :viewBox="`0 0 ${loadChartData.W} ${loadChartData.H}`" class="w-full" preserveAspectRatio="none">
                                            <path :d="loadChartData.atlPath" fill="none" stroke="rgb(var(--z-warn))" stroke-width="1.5" stroke-linecap="round" opacity="0.85" />
                                            <path :d="loadChartData.ctlPath" fill="none" stroke="rgb(var(--z-fitness))" stroke-width="2" stroke-linecap="round" />
                                        </svg>
                                        <div class="flex items-center gap-3 px-1 pb-2 pt-1.5">
                                            <span class="flex items-center gap-1.5 text-[12px] text-ink-3">
                                                <span class="inline-block h-0.5 w-4 rounded bg-fitness" /> Fitness
                                            </span>
                                            <span class="flex items-center gap-1.5 text-[12px] text-ink-3">
                                                <span class="inline-block h-0.5 w-4 rounded bg-warn" /> Ermüdung
                                            </span>
                                        </div>
                                    </div>

                                    <p v-if="props.trainingLoad.enough_data === false" class="mt-3 text-[13px] leading-relaxed text-ink-3">
                                        Die Form ist ein Schnitt über sechs Wochen. Nach etwa vier Wochen mit Läufen sagt sie etwas aus.
                                    </p>
                                    <p v-else class="mt-3 text-[13px] leading-relaxed text-ink-3">
                                        Form ist Fitness minus Ermüdung. −10 bis +5 heißt wettkampfbereit,
                                        darunter steckst du in einem Trainingsblock.
                                    </p>
                                </AppCard>
                            </section>
                        </div>

                        <div v-if="m.key === 'intensitaet'" :class="moduleClass(m)">
                            <!-- 80/20 — was tatsächlich gelaufen wurde -->
                            <section v-if="props.intensityDistribution" class="min-w-0">
                                <SectionHeader title="Locker / Hart">
                                    <template #action>
                                        <span class="rounded-full px-3 py-1.5 text-[13px] font-semibold"
                                            :class="props.intensityDistribution.hard_pct > (props.intensityDistribution.limit_pct ?? 25)
                                                ? 'bg-warn-soft text-warn-ink'
                                                : 'bg-success-soft text-success-ink'">
                                            {{ props.intensityDistribution.hard_pct > (props.intensityDistribution.limit_pct ?? 25)
                                                ? 'Zu viel hart'
                                                : (props.intensityDistribution.model === 'norwegian' ? 'Passt zum Modell' : 'Passt zu 80/20') }}
                                        </span>
                                    </template>
                                </SectionHeader>

                                <AppCard>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-[15px] font-semibold text-success">Locker</p>
                                            <p class="font-rounded text-[28px] font-bold leading-8 tabular-nums text-ink">
                                                {{ 100 - props.intensityDistribution.hard_pct }}<span class="ml-0.5 text-[15px] font-semibold text-ink-3">%</span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-[15px] font-semibold text-warn">Hart</p>
                                            <p class="font-rounded text-[28px] font-bold leading-8 tabular-nums text-ink">
                                                {{ props.intensityDistribution.hard_pct }}<span class="ml-0.5 text-[15px] font-semibold text-ink-3">%</span>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Je Woche ein Balken, der harte Anteil vorn. -->
                                    <div class="mt-4 space-y-2">
                                        <div v-for="w in props.intensityDistribution.weeks" :key="w.label" class="flex items-center gap-3">
                                            <span class="w-12 shrink-0 text-[13px] tabular-nums text-ink-3">{{ w.label }}</span>
                                            <div class="flex h-2 flex-1 overflow-hidden rounded-full bg-surface-2">
                                                <template v-if="w.hard_pct !== null">
                                                    <div class="h-full bg-warn" :style="{ width: w.hard_pct + '%' }" />
                                                    <div class="h-full bg-success" :style="{ width: (100 - w.hard_pct) + '%' }" />
                                                </template>
                                            </div>
                                            <span class="w-12 shrink-0 whitespace-nowrap text-right text-[13px] tabular-nums text-ink-3">{{ w.hard_pct !== null ? w.hard_pct + ' %' : '–' }}</span>
                                        </div>
                                    </div>

                                    <p class="mt-3 text-[13px] leading-relaxed text-ink-3">
                                        Laufzeit der letzten vier Wochen nach Pace, Runde für Runde.
                                        Hart ist alles ab Zone 3. Höchstens 20 % sollten es sein, sonst
                                        sind meist die lockeren Läufe zu schnell.
                                    </p>
                                </AppCard>
                            </section>
                        </div>

                        <div v-if="m.key === 'tempo'" :class="moduleClass(m)">
                            <!-- Tempo -->
                            <section class="min-w-0">
                                <SectionHeader title="Tempo">
                                    <template #action>
                                        <AppButton v-if="props.stravaConnected" variant="plain"
                                            :loading="syncing" :disabled="props.thresholdPaceCalculating" @click="syncStrava">
                                            {{ syncing ? 'Sync…' : 'Sync' }}
                                        </AppButton>
                                    </template>
                                </SectionHeader>

                                <AppCard>
                                    <p class="text-[15px] font-semibold text-accent">Schwellenpace</p>
                                    <!--
                                        Die Schwelle aus dem VDOT, mit Herkunft (Befund N10, Jan
                                        03.11.2026): dieselbe Zahl, aus der Zonen und Tempoläufe
                                        rechnen. Die gemessene steht daneben, wenn sie abweicht.
                                    -->
                                    <p v-if="shownThreshold" class="mt-0.5 flex items-baseline gap-1.5">
                                        <span class="font-rounded text-[40px] font-bold leading-[44px] tabular-nums tracking-tight text-ink">{{ shownThreshold }}</span>
                                        <span class="text-[17px] font-semibold text-ink-3">/km</span>
                                    </p>
                                    <p v-else class="mt-1 text-xl font-bold text-ink-3">Noch nicht berechnet</p>
                                    <p class="mt-1 text-[13px] text-ink-3">
                                        <span v-if="props.thresholdPaceCalculating">Analyse läuft im Hintergrund…</span>
                                        <template v-else-if="props.thresholdDisplay">
                                            <span class="first-letter:uppercase inline-block">{{ props.thresholdDisplay.label }}</span>
                                            <span v-if="props.thresholdDisplay.measured"> · gemessen {{ props.thresholdDisplay.measured }}</span>
                                        </template>
                                        <span v-else-if="props.thresholdPaceCalculatedAt">{{ props.thresholdPaceCalculatedAt }} · letzte 20 Läufe</span>
                                        <span v-else>Wird aus deinen ersten Läufen berechnet</span>
                                    </p>

                                    <div v-if="props.paceZones" class="mt-4 grid grid-cols-5 gap-1.5">
                                        <div v-for="(zone, key) in props.paceZones" :key="key" class="rounded-field bg-surface-2 p-2 text-center">
                                            <p class="flex items-center justify-center gap-1 text-[13px] font-semibold text-ink">
                                                <span class="h-2 w-2 rounded-full" :class="{ z1: 'bg-ink-3', z2: 'bg-success', z3: 'bg-accent', z4: 'bg-warn', z5: 'bg-danger' }[key]" />{{ key.toUpperCase() }}
                                            </p>
                                            <p class="mt-0.5 text-[12px] leading-tight tabular-nums text-ink-3">
                                                <template v-if="zone.max_pace === '∞' || zone.min_pace === '0:00'">{{ zoneRangeShort(zone) }}</template>
                                                <template v-else>{{ zone.min_pace }}<br><span class="opacity-70">{{ zone.max_pace }}</span></template>
                                            </p>
                                        </div>
                                    </div>

                                    <button @click="showPaceDetails = !showPaceDetails"
                                        class="mt-4 flex items-center gap-1.5 text-[13px] font-semibold text-ink-2 transition-colors hover:text-ink">
                                        <svg class="h-4 w-4 transition-transform duration-200 ease-in-out-strong" :class="showPaceDetails ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                        {{ showPaceDetails ? 'Weniger' : 'Prognosen & Verlauf' }}
                                    </button>

                                    <div v-if="showPaceDetails">
                                        <!--
                                            Immer zwei Spalten: das Modul ist auch am Desktop oft nur
                                            ~400 px breit, und vier Spalten ließen „Halbmarathon“ und
                                            „1:33:22“ aus dem Kasten laufen (Jan, 03.11.2026).
                                        -->
                                        <div v-if="props.racePredictions" class="mt-4 grid grid-cols-2 gap-2">
                                            <div v-for="(pred, key) in props.racePredictions" :key="key" class="min-w-0 rounded-field bg-surface-2 px-3 py-2.5">
                                                <p class="truncate text-[13px] text-ink-3">{{ pred.label }}</p>
                                                <p class="mt-0.5 font-rounded text-[22px] font-bold tabular-nums text-ink">{{ pred.total_time }}</p>
                                                <p class="text-[13px] tabular-nums text-ink-3">{{ pred.pace }} /km</p>
                                            </div>
                                        </div>

                                        <div v-if="chartData" class="mt-4">
                                            <div class="w-full overflow-hidden rounded-field bg-surface-2" style="aspect-ratio: 560 / 120;">
                                                <svg :viewBox="`0 0 ${chartData.W} ${chartData.H}`" class="h-full w-full" preserveAspectRatio="none">
                                                    <path :d="chartData.fillD" fill="rgb(var(--z-accent) / 0.12)" />
                                                    <path :d="chartData.pathD" fill="none" stroke="rgb(var(--z-accent))" stroke-width="2" stroke-linecap="round" />
                                                </svg>
                                            </div>
                                            <!--
                                                Beschriftung als Text statt im SVG: das SVG wird gestreckt,
                                                Schrift darin landete bei 6 px (Jan, 03.11.2026).
                                            -->
                                            <div class="mt-2 grid grid-cols-3 text-[13px] tabular-nums">
                                                <p class="text-ink-3">{{ chartData.points[0].date }}<br><span class="font-semibold text-ink">{{ chartData.points[0].pace }}</span></p>
                                                <p class="text-center text-ink-3">Schnellste<br><span class="font-semibold text-ink">{{ chartData.fastest }}</span></p>
                                                <p class="text-right text-ink-3">{{ chartData.points[chartData.points.length - 1].date }}<br><span class="font-semibold text-ink">{{ chartData.points[chartData.points.length - 1].pace }}</span></p>
                                            </div>
                                            <p class="mt-1.5 text-center text-[12px] text-ink-3">Gemessene Schwelle · oben = schneller</p>
                                        </div>
                                        <p v-else-if="props.thresholdPace" class="mt-3 text-[13px] text-ink-3">
                                            Der Verlauf erscheint, sobald Daten über mehr als 7 Tage vorliegen.
                                        </p>
                                    </div>
                                </AppCard>
                            </section>
                        </div>

                        <div v-if="m.key === 'rueckblick'" :class="moduleClass(m)">
                            <!-- Wochenrückblick -->
                            <section v-if="props.weeklyReview" class="min-w-0">
                                <SectionHeader title="Wochenrückblick" />
                                <AppCard>
                                    <div class="mb-3 flex items-center gap-3">
                                        <div v-if="coach" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[13px] font-bold text-white"
                                            :style="`background-color: ${coach.avatar_color}`">{{ coach.avatar_initials }}</div>
                                        <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-3"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0v.15a24 24 0 0 1-15 0v-.15Z" /></svg></div>
                                        <div>
                                            <p class="text-[15px] font-bold text-ink">{{ coach ? coach.name : 'Dein Coach' }}</p>
                                            <p class="text-[13px] text-ink-3">ab {{ new Date(props.weeklyReview.week_start).toLocaleDateString('de-DE', {day:'2-digit', month:'short'}) }}</p>
                                        </div>
                                    </div>
                                    <p class="text-[15px] leading-relaxed text-ink-2">{{ props.weeklyReview.content }}</p>
                                </AppCard>
                            </section>
                        </div>

                        <div v-if="m.key === 'erholung'" :class="moduleClass(m)">
                            <!-- Garmin-Erholung -->
                            <GarminRecovery
                                v-if="props.garminMetrics"
                                :metrics="props.garminMetrics"
                                :activities="props.recoveryActivities"
                            />
                        </div>

                        <div v-if="m.key === 'aktivitaeten'" :class="moduleClass(m)">
                            <!-- ══════════════════════════════════════════════════
                                 ZULETZT GELAUFEN
                                 ══════════════════════════════════════════════════ -->
                            <section>
                                <SectionHeader title="Aktivitäten">
                                    <template #action>
                                        <AppButton variant="plain" href="/activities">Alle</AppButton>
                                    </template>
                                </SectionHeader>

                                <AppCard v-if="props.recentActivities.length === 0">
                                    <!-- Garmin zuerst (Jan, 03.11.2026): Strava nur, wenn es schon verbunden ist. -->
                                    <EmptyState v-if="props.stravaConnected" title="Noch keine Aktivitäten" description="Synchronisiere Strava, dann erscheinen deine Einheiten hier.">
                                        <AppButton :loading="syncing" @click="syncStrava">Jetzt synchronisieren</AppButton>
                                    </EmptyState>
                                    <EmptyState v-else-if="$page.props.auth.garminLinked" title="Noch keine Aktivitäten"
                                        description="Garmin schickt jeden Lauf, sobald die Uhr synchronisiert. Dein nächster Lauf erscheint hier." />
                                    <EmptyState v-else title="Noch keine Aktivitäten" description="Verbinde deine Uhr, dann erscheinen deine Einheiten hier.">
                                        <AppButton :href="route('profile.edit', { section: 'connections' })">Uhr verbinden</AppButton>
                                    </EmptyState>
                                </AppCard>

                                <!--
                                    Eine gruppierte Liste wie in Health statt acht einzelner
                                    Karten: links Name und Tag, rechts die eine Zahl groß
                                    und die Pace darunter. Puls, Höhenmeter und Pillen
                                    stehen in der Aktivität selbst.
                                -->
                                <div v-else class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                                    <button
                                        v-for="activity in props.recentActivities.slice(0, 5)"
                                        :key="activity.id"
                                        type="button"
                                        @click="openActivityDetail(activity)"
                                        class="flex w-full min-w-0 items-center gap-3 px-4 py-3 text-left transition-colors duration-150 active:bg-surface-2"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-2">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true" v-html="activityIcon(activity.type)" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-[17px] text-ink">{{ activity.name }}</span>
                                            <span class="block text-[13px] text-ink-3">{{ relativeDate(activity.start_date) }}</span>
                                        </span>
                                        <span class="shrink-0 text-right">
                                            <span class="block font-rounded text-[17px] font-bold tabular-nums text-ink">
                                                <template v-if="activity.distance > 0">{{ formatDistance(activity.distance) }} km</template>
                                                <template v-else>{{ formatTime(activity.moving_time) }}</template>
                                            </span>
                                            <span v-if="activity.average_speed > 0 && activity.distance > 0" class="block text-[13px] tabular-nums text-ink-3">{{ activityPaceLabel(activity) }}</span>
                                            <span v-else-if="activity.distance > 0" class="block text-[13px] tabular-nums text-ink-3">{{ formatTime(activity.moving_time) }}</span>
                                        </span>
                                    </button>
                                </div>
                            </section>
                        </div>

                        <div v-if="m.key === 'events'" :class="moduleClass(m)">
                            <section class="min-w-0">
                                <SectionHeader title="Rennen">
                                    <template #action>
                                        <AppButton variant="plain" href="/events">Alle</AppButton>
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
                                                <p class="font-rounded text-[22px] font-bold tabular-nums leading-none text-ink">{{ event.days_until }}</p>
                                                <p class="text-[12px] text-ink-3">Tage</p>
                                            </div>
                                        </a>
                                    </div>
                                </AppCard>
                            </section>
                        </div>

                    </template>
                </div>

                <div class="flex justify-center">
                    <AppButton variant="plain" @click="openLayoutSheet">Übersicht anpassen</AppButton>
                </div>

            </div>
        </div>

        <!-- Wellbeing Modal -->
        <!-- Wellbeing / plan-adjust toast -->
        <Transition enter-active-class="transition duration-300" enter-from-class="opacity-0 translate-y-4" leave-active-class="transition duration-200" leave-to-class="opacity-0 translate-y-4">
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
            :sending="garminSending"
            :error="garminError"
            :success="garminSuccess"
            @send="sendToGarminConnect"
            @close="garminModal = false"
        />
        <!-- ══════════════════════════════════════════════════
             ÜBERSICHT ANPASSEN
             ══════════════════════════════════════════════════
             Pfeile statt Ziehen: im Sheet kollidiert Ziehen mit dem
             Wischen, das das Sheet schliesst, und mit dem Scrollen. -->
        <AppSheet dialog :show="notifySheet" title="Benachrichtigungen" subtitle="Telegram, Push oder beides" @close="notifySheet = false; refreshStarter()">
            <NotificationChoice :vapid-public-key="notifyOptions.vapidPublicKey" :telegram="notifyOptions.telegram" @changed="refreshStarter" />
        </AppSheet>

        <AppSheet :show="layoutSheet" title="Übersicht anpassen" subtitle="Gilt auf allen deinen Geräten" @close="layoutSheet = false">
            <p class="z-hint mb-4">
                „Heute“ und Hinweise, die eine Antwort brauchen, stehen immer oben.
            </p>

            <div class="divide-y divide-line rounded-card bg-surface-2">
                <div v-for="(m, i) in layoutDraft" :key="m.key" class="flex min-h-[52px] items-center gap-2 py-1.5 pl-4 pr-2">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[17px]" :class="m.hidden ? 'text-ink-3' : 'text-ink'">{{ m.label }}</p>
                        <p v-if="moduleHint(m)" class="text-[13px] text-ink-3">{{ moduleHint(m) }}</p>
                    </div>

                    <button type="button" :aria-label="`${m.label} nach oben`" :disabled="i === 0"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-2 transition active:bg-surface-3 disabled:opacity-30"
                        @click="moveModule(i, -1)">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                    </button>
                    <button type="button" :aria-label="`${m.label} nach unten`" :disabled="i === layoutDraft.length - 1"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-2 transition active:bg-surface-3 disabled:opacity-30"
                        @click="moveModule(i, 1)">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="!m.hidden"
                        :aria-label="`${m.label} anzeigen`"
                        class="relative ml-1 inline-flex h-[31px] w-[51px] shrink-0 rounded-full p-0.5 transition-colors duration-200 ease-out-strong"
                        :class="m.hidden ? 'bg-surface-3' : 'bg-success'"
                        @click="m.hidden = !m.hidden"
                    >
                        <span
                            class="h-[27px] w-[27px] rounded-full bg-white shadow-[0_2px_4px_rgb(0_0_0/0.2)] transition-transform duration-200 ease-out-strong"
                            :class="m.hidden ? 'translate-x-0' : 'translate-x-5'"
                        />
                    </button>
                </div>
            </div>

            <p v-if="layoutError" class="z-error">{{ layoutError }}</p>

            <template #footer>
                <div class="space-y-2">
                    <AppButton block :loading="layoutSaving" @click="saveLayout">Fertig</AppButton>
                    <AppButton block variant="plain" :disabled="layoutSaving" @click="resetLayout">Standard wiederherstellen</AppButton>
                </div>
            </template>
        </AppSheet>


        <!-- ══════════════════════════════════════════════════
             WOCHE ANPASSEN
             ══════════════════════════════════════════════════ -->
        <AppSheet dialog :show="weekSheet" title="Deine Woche" subtitle="Nur für diese eine Woche" @close="weekSheet = false">
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
                            :class="day.duration_min === dur ? 'bg-accent text-white' : 'bg-surface text-ink-3 hover:text-ink-2'"
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


        <!-- ══════════════════════════════════════════════════
             EINHEIT TAUSCHEN
             ═══════════════════════════════════════════════════
             Von Athleten gebaut und freigegeben. Geteilt wird die
             Struktur; die Paces stehen für DEINE Schwellenpace. -->
        <AppSheet :show="swapOpen" title="Einheit tauschen" @close="swapOpen = false">
            <div class="space-y-4">
                <p class="text-[13px] text-ink-3">
                    Workouts, die andere Athleten freigegeben haben. Die Paces sind für dich gerechnet.
                </p>

                <p v-if="!swapHasPaces" class="rounded-field bg-warn-soft px-3 py-2 text-[13px] text-warn-ink">
                    Ohne Schwellenpace lassen sich keine Zielzeiten berechnen — die Struktur stimmt, die
                    Paces fehlen.
                </p>

                <p v-if="swapLoading" class="text-[13px] text-ink-3">Wird geladen …</p>

                <p v-else-if="!Object.keys(swapGroups).length" class="text-[13px] text-ink-3">
                    Noch hat niemand ein Workout freigegeben. Unter „Workouts" kannst du eines bauen und
                    für alle sichtbar machen.
                </p>

                <!-- ── Detailansicht: erst ansehen, dann entscheiden ── -->
                <template v-if="swapDetail">
                    <button class="text-[13px] text-ink-3 hover:text-ink" @click="swapDetail = null">
                        ← Zurück zur Auswahl
                    </button>

                    <div>
                        <h3 class="text-[17px] font-semibold text-ink">{{ swapDetail.name }}</h3>
                        <p class="mt-0.5 text-[13px] text-ink-3">
                            {{ swapTypes[swapDetail.type] ?? swapDetail.type }} · von {{ swapDetail.author }}
                            <span v-if="swapDetail.times_used"> · {{ swapDetail.times_used }}× genutzt</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3 rounded-field bg-surface-2 px-3 py-2 text-[13px]">
                        <span v-if="swapDetail.duration_min">
                            <span class="font-semibold text-ink">{{ swapDetail.duration_min }}</span> min
                        </span>
                        <span v-if="swapDetail.distance_km">
                            <span class="font-semibold text-ink">{{ swapDetail.distance_km }}</span> km
                        </span>
                        <span v-if="swapDetail.pace_target">
                            <span class="font-semibold text-ink">{{ swapDetail.pace_target }}</span> /km
                        </span>
                    </div>

                    <p v-if="swapDetail.description" class="whitespace-pre-line text-[14px] text-ink-2">
                        {{ swapDetail.description }}
                    </p>
                    <p v-else class="text-[13px] text-ink-3 italic">Keine Beschreibung hinterlegt.</p>

                    <!-- Die Struktur, Abschnitt für Abschnitt. -->
                    <div v-if="swapDetail.steps?.length" class="space-y-1.5">
                        <p class="text-[13px] font-medium text-ink-2">Ablauf</p>

                        <div v-for="(st, i) in swapDetail.steps" :key="i"
                            class="flex items-baseline gap-2 rounded-field bg-surface px-3 py-2">
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="stepTone[st.type] ?? 'bg-surface-2 text-ink-3'">
                                {{ stepLabel[st.type] ?? st.type }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-[14px] text-ink">
                                    <span v-if="st.repetitions" class="font-semibold">{{ st.repetitions }}× </span>{{ st.label }}
                                </p>
                                <p class="text-[12px] text-ink-3">{{ stepLine(st) }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="swapDetail.tags?.length" class="flex flex-wrap gap-1">
                        <span v-for="t in swapDetail.tags" :key="t"
                            class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] text-ink-3">{{ t }}</span>
                    </div>

                    <div class="flex gap-2 pt-1">
                        <AppButton :disabled="swapBusy !== null" @click="applyWorkout(swapDetail)">
                            Für heute übernehmen
                        </AppButton>
                        <AppButton variant="secondary" :disabled="swapBusy !== null" @click="swapDetail = null">
                            Doch nicht
                        </AppButton>
                    </div>

                    <p class="text-[12px] text-ink-3">
                        Ersetzt deine heutige Einheit „{{ props.todayPlanSession?.title }}“ und bleibt dann stehen —
                        auch wenn der Plan neu berechnet wird.
                    </p>
                </template>

                <template v-else>
                    <!-- Nach Lauftyp: die Frage lautet „ich habe Bock auf Intervalle", nicht
                         „ich suche Workout 47". -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(list, type) in swapGroups" :key="type"
                            class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                            :class="swapCategory === type
                                ? 'bg-accent text-white'
                                : 'bg-surface-2 text-ink-2 hover:bg-surface-3'"
                            @click="swapCategory = type">
                            {{ swapTypes[type] ?? type }} ({{ list.length }})
                        </button>
                    </div>

                    <div class="space-y-2">
                        <button
                            v-for="w in (swapGroups[swapCategory] ?? [])" :key="w.id"
                            class="w-full rounded-card border border-line bg-surface px-4 py-3 text-left transition-colors hover:bg-surface-2"
                            @click="swapDetail = w">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-[15px] font-medium text-ink">{{ w.name }}</span>
                                <span class="shrink-0 text-[12px] text-ink-3">von {{ w.author }}</span>
                            </div>

                            <p class="mt-0.5 text-[13px] text-ink-2">{{ workoutSummary(w) }}</p>
                            <p v-if="w.description" class="mt-1 text-[12px] text-ink-3">{{ w.description }}</p>

                            <p v-if="w.steps?.length" class="mt-2 text-[12px] text-ink-3">
                                <span v-for="(st, i) in w.steps" :key="i">
                                    <span v-if="st.repetitions">{{ st.repetitions }}× </span><span>{{ st.label }}</span><span
                                        v-if="stepAmount(st)">, {{ stepAmount(st) }}</span><span v-if="i < w.steps.length - 1"> · </span>
                                </span>
                            </p>
                        </button>
                    </div>
                </template>
            </div>
        </AppSheet>

        <PersonalRecordMoment v-if="personalRecord && !prDismissed"
            :record="personalRecord" :message="coachPrMessage" @close="dismissPr" />
        <RaceDoneMoment v-if="showRace" :race="raceRun" @close="closeRace" />
        <WeekDoneMoment v-if="showWeek" :week="weekDone" @close="closeWeek" />
    </AuthenticatedLayout>
</template>

<style scoped>
.activities-scroll::-webkit-scrollbar {
    display: none;
}
</style>
