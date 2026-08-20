<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import { sessionType } from '@/Composables/useSessionTypes';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import GarminSendSheet from '@/Components/UI/GarminSendSheet.vue';
import SwipeRow from '@/Components/SwipeRow.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

import axios from 'axios';

const coach   = computed(() => usePage().props.coach ?? null);
const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
const coachName = computed(() => coach.value?.name ?? 'Dein Coach');

const coachAccentColors = {
    orange: { stripe: 'bg-warn', badge: 'bg-warn-soft text-warn-ink border-warn/25', avatar: 'bg-warn' },
    blue:   { stripe: 'bg-info',   badge: 'bg-info-soft text-info-ink border-info/25',             avatar: 'bg-info'   },
    green:  { stripe: 'bg-success',  badge: 'bg-success-soft text-success-ink border-success/25',       avatar: 'bg-success'  },
    purple: { stripe: 'bg-accent', badge: 'bg-accent-soft text-accent-ink border-accent/25', avatar: 'bg-accent' },
};
const coachAccent = computed(() => coachAccentColors[coach.value?.avatar_color] ?? coachAccentColors.blue);

const props = defineProps({
    event:        Object,
    plan:         Object,   // { id, is_active, generated_at, context, actual_time_hours, ... }
    sessions:     Array,    // TrainingSession records from DB
    backyard:     { type: Object, default: null }, // { readiness, rhythm } for Backyard Ultra events
    planIsRolling: { type: Boolean, default: false }, // race beyond the rolling window → plan extends over time
    isPastEvent:  { type: Boolean, default: false },
    // Aenderungsverlauf: was jede Neuberechnung am Plan geaendert hat.
    revisions:    { type: Array, default: () => [] },
});

const revisionsOpen = ref(false);

const revisionTone = {
    added:   { dot: 'bg-success' },
    removed: { dot: 'bg-ink-3' },
    changed: { dot: 'bg-info' },
};

// ── State ─────────────────────────────────────────────────────────────────────
const currentPlan     = ref(props.plan);
const currentSessions = ref(props.sessions ?? []);
const generating      = ref(props.event.plan_generating ?? false);
const errorMsg        = ref(props.event.plan_error ?? '');
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

const today = new Date().toISOString().slice(0, 10);

// ── Load today's wellbeing ────────────────────────────────────────────────────
onMounted(async () => {
    try {
        const res = await axios.get(route('wellbeing.today'));
        todayWellbeing.value = res.data;
    } catch { /* no wellbeing today — that's fine */ }
    finally { wellbeingLoaded.value = true; }

    // Auto-open session detail modal when linked from dashboard (?open=SESSION_ID)
    const params = new URLSearchParams(window.location.search);
    const openId = params.get('open');
    if (openId) {
        const target = currentSessions.value.find(s => String(s.id) === openId);
        if (target && target.type !== 'rest') {
            openDetail(target);
        }
        // Clean up URL without triggering navigation
        const url = new URL(window.location.href);
        url.searchParams.delete('open');
        window.history.replaceState({}, '', url.toString());
    }
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
let pollTimer = null;

async function generatePlan() {
    generating.value = true;
    errorMsg.value   = '';
    try {
        await axios.post(route('events.plan.generate', props.event.id));
        pollGenerateStatus();
    } catch (e) {
        generating.value = false;
        errorMsg.value = e?.response?.data?.error ?? 'Fehler beim Starten der Plan-Erstellung.';
    }
}

// Plan generation runs asynchronously in a queue job (so it never blocks the
// single-threaded web server). Poll the status until it's ready or fails.
function pollGenerateStatus() {
    const startedAt = Date.now();
    clearInterval(pollTimer);
    pollTimer = setInterval(async () => {
        // Hard stop after 6 minutes so the spinner never hangs forever
        if (Date.now() - startedAt > 6 * 60 * 1000) {
            clearInterval(pollTimer);
            generating.value = false;
            errorMsg.value = 'Zeitüberschreitung bei der Plan-Erstellung. Bitte versuche es erneut.';
            return;
        }
        try {
            const { data } = await axios.get(route('events.plan.generate-status', props.event.id));
            if (data.status === 'ready') {
                clearInterval(pollTimer);
                // Reload so the new plan, sessions and readiness card render fresh
                window.location.reload();
            } else if (data.status === 'failed') {
                clearInterval(pollTimer);
                generating.value = false;
                errorMsg.value = data.error ?? 'Plan konnte nicht erstellt werden. Bitte versuche es erneut.';
            }
            // status === 'generating' → keep polling
        } catch (e) {
            // transient network error → keep polling until the timeout guard fires
        }
    }, 4000);
}

// ── Complete session ──────────────────────────────────────────────────────────
const completingId = ref(null);
async function completeSession(session) {
    completingId.value = session.id;
    try {
        const res = await axios.patch(route('training-sessions.complete', session.id));
        updateSessionInList(res.data.session);
        // Reflect in the open detail modal (manual completion from there)
        if (detailSession.value?.id === session.id) detailSession.value = res.data.session;
    } catch (e) {
        errorMsg.value = 'Fehler beim Speichern.';
    } finally {
        completingId.value = null;
    }
}

// ── Admin: reset steps/nutrition cache ────────────────────────────────────────
async function resetSessionCache(session) {
    try {
        const { data } = await axios.post(route('training-sessions.reset-cache', session.id));
        // Reflect the reset (and a possibly rebuilt strength description) in local state
        if (data?.session) updateSessionInList(data.session);
        const s = currentSessions.value?.find(x => x.id === session.id) ?? session;
        s.steps          = null;
        s.nutrition_tips = null;
        delete stepsCache[session.id];
        delete nutritionCache[session.id];
    } catch {
        errorMsg.value = 'Fehler beim Zurücksetzen des Caches.';
    }
}

// ── Skip session ──────────────────────────────────────────────────────────────
function openSkipModal(session, preReason = '') {
    skipSession.value = session;
    skipReason.value  = preReason;
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
function updateSessionInList(updated) {
    const idx = currentSessions.value.findIndex(s => s.id === updated.id);
    if (idx !== -1) currentSessions.value[idx] = updated;
}

// ── Type config ───────────────────────────────────────────────────────────────
// Typ-Tabelle liegt in useSessionTypes — Dashboard und Plan teilen sie sich.
const typeOf = (t) => sessionType(t);

/**
 * Der geplante Zustand einer Einheit — er steht im Schnappschuss, weil der
 * Strava-Import distance_km, duration_min und pace_target mit den echten
 * Werten ueberschreibt.
 */
function plannedSummary(session) {
    const p = session.planned_snapshot;
    if (!p) return '';

    const bits = [];
    if (p.distance_km)  bits.push(`${p.distance_km} km`);
    if (p.duration_min) bits.push(`${p.duration_min} min`);
    if (p.pace_target)  bits.push(`${p.pace_target} /km`);

    return bits.join(' · ');
}

/** Nur zeigen, wenn der Plan tatsaechlich etwas anderes sagte. */
function plannedDiffers(session) {
    const p = session.planned_snapshot;
    if (!p || session.was_unplanned || session.status !== 'completed') return false;

    return (p.distance_km  && p.distance_km  !== session.distance_km)
        || (p.duration_min && p.duration_min !== session.duration_min);
}

// Strength / core / mobility sessions carry an "exercises" list instead of a run
// structure — they get a different detail view (no run steps, no Garmin export).
const STRENGTH_TYPES = ['strength', 'core', 'mobility'];
const isStrengthType = (t) => STRENGTH_TYPES.includes(t);

// YouTube search link so the athlete can look up how to perform an exercise.
function exerciseVideoUrl(name) {
    return 'https://www.youtube.com/results?search_query=' +
        encodeURIComponent((name || '') + ' Übung richtige Ausführung');
}

const priorityColors = { A: 'text-danger-ink bg-danger-soft', B: 'text-warn-ink bg-warn-soft', C: 'text-ink-2 bg-surface-2' };

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'short' });
}
const isToday  = (d) => d === today;

// A session can be skipped (swipe → "Kein Training") only while planned and not the race day.
const canSkip = (s) => s.status === 'planned' && s.planned_date !== props.event.event_date;

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

// ── Renntag-Strategie + Post-Race-Analyse ─────────────────────────────────────
const raceStrategy        = ref(null);   // { pace, splits[], strategy_text }
const raceStrategyLoading = ref(false);
const raceAnalysis        = ref(null);   // { found, analysis_text, actual_time }
const raceAnalysisLoading = ref(false);

onMounted(() => {
    // Resume polling if a plan generation is already running (e.g. started on another device).
    if (props.event.plan_generating) {
        pollGenerateStatus();
    }
    // Race-day strategy: auto-loaded in the race week (next ~10 days).
    if (!props.isPastEvent && props.event.days_until >= 0 && props.event.days_until <= 10) {
        raceStrategyLoading.value = true;
        axios.get(route('events.plan.strategy', props.event.id))
            .then(({ data }) => { if (data.available) raceStrategy.value = data; })
            .catch(() => {})
            .finally(() => { raceStrategyLoading.value = false; });
    }
    // Post-race analysis: auto-loaded once the event is in the past.
    if (props.isPastEvent && props.plan) {
        raceAnalysisLoading.value = true;
        axios.get(route('events.plan.analysis', props.event.id))
            .then(({ data }) => { raceAnalysis.value = data; })
            .catch(() => {})
            .finally(() => { raceAnalysisLoading.value = false; });
    }
});

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

const aiSteps        = ref(null);
const stepsLoading   = ref(false);
const stepsError     = ref('');
const stepsCache     = {};

// ── Rating ───────────────────────────────────────────────────────────────────
const ratingValue  = ref(0);
const effortValue  = ref(0);
const feelingNotes = ref('');
const ratingSaving = ref(false);
const ratingSaved  = ref(false);

// ── Coach review feedback ─────────────────────────────────────────────────────
const reviewFeedbackText   = ref('');
const reviewFeedbackSaving = ref(false);

async function submitReviewFeedback(answer) {
    if (!detailSession.value || reviewFeedbackSaving.value) return;
    const value = (answer ?? reviewFeedbackText.value ?? '').trim();
    if (!value) return;
    reviewFeedbackSaving.value = true;
    try {
        const { data } = await axios.patch(
            route('training-sessions.review-feedback', detailSession.value.id),
            { feedback: value },
        );
        updateSessionInList(data.session);
        detailSession.value = data.session;
        reviewFeedbackText.value = '';
    } catch {
        errorMsg.value = 'Antwort konnte nicht gespeichert werden.';
    } finally {
        reviewFeedbackSaving.value = false;
    }
}

// Garmin Connect sheet
const garminModal    = ref(false);
const garminSending  = ref(false);
const garminSuccess  = ref(false);
const garminError    = ref('');

const garminConnected = computed(() => !!usePage().props.auth.garminConnected);
const garminSavedEmail = computed(() => usePage().props.auth.garminEmail);

function openGarminModal() {
    garminModal.value    = true;
    garminError.value    = '';
    garminSuccess.value  = false;
}

async function sendToGarminConnect({ email, password } = {}) {
    if (!detailSession.value) return;
    garminSending.value = true;
    garminError.value   = '';
    garminSuccess.value = false;
    try {
        const payload = garminConnected.value ? {} : { email, password };

        const { data } = await axios.post(
            route('training-sessions.send-to-garmin', detailSession.value.id),
            payload
        );
        if (data.success) {
            garminSuccess.value = true;
            // Reload page props to reflect newly saved garmin connection
            router.reload({ only: [] });
            setTimeout(() => { garminModal.value = false; garminSuccess.value = false; }, 2500);
        } else {
            const err = data.error || 'Unbekannter Fehler';
            garminError.value = err.startsWith('login_failed:')
                ? 'Login fehlgeschlagen: ' + err.replace('login_failed:', '').trim()
                : err;
        }
    } catch (e) {
        const detail = e.response?.data?.error || e.response?.data?.detail || e.message;
        if (detail === 'session_expired') {
            router.reload({ only: [] });
            garminError.value = 'Sitzung abgelaufen. Bitte erneut einloggen.';
        } else if (detail === 'mfa_required') {
            garminError.value = 'Zwei-Faktor-Authentifizierung aktiv. Bitte deaktiviere 2FA temporär in deinem Garmin-Account.';
        } else if (detail?.startsWith('login_failed:')) {
            garminError.value = 'Login fehlgeschlagen: ' + detail.replace('login_failed:', '').trim();
        } else {
            garminError.value = detail || 'Verbindung fehlgeschlagen';
        }
    } finally {
        garminSending.value = false;
    }
}

async function garminDisconnect() {
    try {
        await axios.delete(route('garmin.disconnect'));
        router.reload({ only: [] });
    } catch {}
}

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
        updateSessionInList(data.session);
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

const detailIsStrength = computed(() => isStrengthType(detailSession.value?.type));

async function openDetail(session) {
    if (session.type === 'rest') return;
    detailSession.value   = session;
    aiNutritionTips.value = null;
    aiSteps.value         = null;
    nutritionError.value  = '';
    stepsError.value      = '';
    ratingValue.value     = session.rating          || 0;
    effortValue.value     = session.effort_perceived || 0;
    feelingNotes.value    = session.feeling_notes    || '';
    ratingSaved.value     = false;
    reviewFeedbackText.value = '';

    // Completed sessions show the real Strava splits instead of planned
    // structure/nutrition — skip all AI generation for them.
    if (session.status === 'completed') return;

    // Load nutrition tips
    if (nutritionCache[session.id]) {
        aiNutritionTips.value = nutritionCache[session.id];
    } else {
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

    // Load workout steps (not for race_prep or strength/core/mobility — those
    // have their own exercise list, not a run structure).
    if (session.type !== 'race_prep' && !isStrengthType(session.type)) {
        if (stepsCache[session.id]) {
            const cached = stepsCache[session.id];
            aiSteps.value = cached.steps ?? cached;
            if (cached.description) detailSession.value.description = cached.description;
        } else {
            stepsLoading.value = true;
            try {
                const { data } = await axios.get(route('training-sessions.steps', session.id));
                stepsCache[session.id] = data;
                // Backend returns { steps, description }; update both local state and session list
                const steps = data.steps ?? data;
                aiSteps.value = steps;
                if (data.description) {
                    detailSession.value.description = data.description;
                    const s = currentSessions.value?.find(x => x.id === session.id);
                    if (s) s.description = data.description;
                }
            } catch {
                stepsError.value = '';
            } finally {
                stepsLoading.value = false;
            }
        }
    }
}

// ── Workout Picker ────────────────────────────────────────────────────────────
const workoutPickerModal    = ref(false);
const workoutPickerSession  = ref(null);
const workoutLibrary        = ref([]);
const workoutLibraryLoaded  = ref(false);
const workoutLibraryLoading = ref(false);
const workoutPickerSelected = ref(null);
const workoutApplying       = ref(false);
const workoutPickerError    = ref('');

async function openWorkoutPicker(session) {
    workoutPickerSession.value  = session;
    workoutPickerSelected.value = null;
    workoutPickerError.value    = '';
    workoutPickerModal.value    = true;

    if (!workoutLibraryLoaded.value) {
        workoutLibraryLoading.value = true;
        try {
            const { data } = await axios.get(route('workouts.list'));
            workoutLibrary.value       = data.workouts ?? [];
            workoutLibraryLoaded.value = true;
        } catch {
            workoutPickerError.value = 'Workouts konnten nicht geladen werden.';
        } finally {
            workoutLibraryLoading.value = false;
        }
    }
}

async function applyWorkout() {
    if (!workoutPickerSession.value || !workoutPickerSelected.value) return;
    workoutApplying.value    = true;
    workoutPickerError.value = '';
    try {
        const { data } = await axios.patch(
            route('training-sessions.apply-workout', workoutPickerSession.value.id),
            { workout_id: workoutPickerSelected.value.id }
        );
        updateSessionInList(data.session);
        workoutPickerModal.value = false;
    } catch (e) {
        workoutPickerError.value = e?.response?.data?.error ?? 'Fehler beim Anwenden.';
    } finally {
        workoutApplying.value = false;
    }
}

const workoutTypeLabel = {
    easy_run:          'Lockerer Lauf',
    tempo_run:         'Tempolauf',
    interval:          'Intervall',
    long_run:          'Langer Lauf',
    progressive_run:   'Progressiver Lauf',
    test_run:          'Testlauf',
    back_to_back_long: 'Back-to-Back',
    time_on_feet:      'Time on Feet',
    yard_simulation:   'Yard-Simulation',
    night_run:         'Nachtlauf',
    strength:          'Kraft',
    core:              'Core',
    mobility:          'Mobility',
};

// ── Race Prediction ───────────────────────────────────────────────────────────
const prediction = computed(() => {
    const p = currentPlan.value;
    if (!p || !p.predicted_finish_time) return null;
    return {
        time:   p.predicted_finish_time,
        pace:   p.predicted_pace,
        delta:  p.prediction_target_delta_sec,
        text:   p.prediction_text,
        source: p.prediction_source ?? 'threshold',
    };
});

const predictionDeltaText = computed(() => {
    if (!prediction.value || prediction.value.delta === null || prediction.value.delta === undefined) return null;
    const abs = Math.abs(prediction.value.delta);
    const h = Math.floor(abs / 3600);
    const m = Math.floor((abs % 3600) / 60);
    const s = abs % 60;
    const fmt = h > 0
        ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${m}:${String(s).padStart(2,'0')}`;
    return prediction.value.delta >= 0
        ? { label: `${fmt} unter Zielzeit`, positive: true }
        : { label: `${fmt} über Zielzeit`, positive: false };
});

function secToPaceStr(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.round(sec % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}

// Step bar colors by type
const stepBarColor = {
    warmup:   'bg-success',
    work:     'bg-danger',
    rest:     'bg-surface-3',
    cooldown: 'bg-info',
};
const stepLabel = { warmup: 'Aufwärmen', work: 'Hauptteil', rest: 'Pause', cooldown: 'Auslaufen' };

// Bar height % by step type (bars grow from bottom)
const stepHeightPct = { warmup: 60, work: 100, rest: 28, cooldown: 60 };

// Expand steps for bar chart — work+rest pairs are interleaved per repetition
const stepsWithReps = computed(() => {
    if (!aiSteps.value) return [];
    const expanded = [];
    let i = 0;
    while (i < aiSteps.value.length) {
        const s = aiSteps.value[i];
        const reps = s.repetitions && s.repetitions > 1 ? s.repetitions : 1;
        if (s.type === 'work' && reps > 1) {
            const rest = aiSteps.value[i + 1]?.type === 'rest' ? aiSteps.value[i + 1] : null;
            for (let r = 0; r < reps; r++) {
                expanded.push(s);
                if (rest) expanded.push(rest);
            }
            i += rest ? 2 : 1;
        } else if (s.type === 'rest' && (s.repetitions || 1) > 1) {
            i++; // already consumed by the preceding work block
        } else {
            for (let r = 0; r < reps; r++) expanded.push(s);
            i++;
        }
    }
    return expanded;
});

const totalStepDuration = computed(() =>
    stepsWithReps.value.reduce((sum, s) => sum + (s.duration_min || 0), 0)
);

// Total distance estimated from step duration × pace (skips steps without pace)
const totalStepDistanceKm = computed(() => {
    if (!stepsWithReps.value.length) return null;
    let km = 0;
    for (const s of stepsWithReps.value) {
        if (!s.pace_target || !s.duration_min) continue;
        const paceStr = String(s.pace_target);
        // Handle range like "5:30-6:00" → take midpoint
        const parts = paceStr.includes('-') ? paceStr.split('-') : [paceStr, paceStr];
        const toMin = p => { const [m, sec] = p.trim().split(':').map(Number); return m + (sec || 0) / 60; };
        const paceMin = (toMin(parts[0]) + toMin(parts[1])) / 2;
        if (paceMin > 0) km += s.duration_min / paceMin;
    }
    return km > 0 ? Math.round(km * 10) / 10 : null;
});

// Grouped steps for list display (collapse repetitions)
const groupedSteps = computed(() => {
    if (!aiSteps.value) return [];
    const result = [];
    let i = 0;
    while (i < aiSteps.value.length) {
        const s = aiSteps.value[i];
        if (s.type === 'work' && s.repetitions && s.repetitions > 1) {
            // Look ahead for matching rest step
            const rest = aiSteps.value[i + 1]?.type === 'rest' ? aiSteps.value[i + 1] : null;
            result.push({ ...s, pairedRest: rest, isGroup: true });
            if (rest) i += 2;
            else i++;
        } else {
            result.push({ ...s, isGroup: false });
            i++;
        }
    }
    return result;
});

// ── Splits (real laps from the matched Strava activity, completed sessions) ─────
const isCompletedSession = computed(() => detailSession.value?.status === 'completed');

const detailLaps = computed(() => {
    const laps = detailSession.value?.laps;
    return Array.isArray(laps) && laps.length > 1 ? laps : [];
});

const totalLapTime = computed(() =>
    detailLaps.value.reduce((s, l) => s + (l.moving_time || l.elapsed_time || 0), 0)
);

function lapPace(lap) {
    if (!lap.average_speed || lap.average_speed <= 0) return '–';
    return secToPaceStr(1000 / lap.average_speed);
}
function lapDist(lap) {
    return ((lap.distance || 0) / 1000).toFixed(2);
}
function lapTime(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

// Lap bar height as % of container: faster lap = taller bar (40%–100%).
const lapHeightPct = computed(() => {
    const laps = detailLaps.value;
    if (!laps.length) return [];
    const speeds = laps.map(l => l.average_speed || 0).filter(s => s > 0);
    if (!speeds.length) return laps.map(() => 70);
    const min = Math.min(...speeds), max = Math.max(...speeds);
    return laps.map(l => {
        if (!l.average_speed || max === min) return 70;
        return 40 + ((l.average_speed - min) / (max - min)) * 60;
    });
});

</script>

<template>
    <Head :title="`Plan – ${event.name}`" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <div class="space-y-5 px-4 py-4 lg:px-6 lg:py-6">

                <!-- ══ KOPF ══════════════════════════════════════════ -->
                <header class="px-1">
                    <Link :href="route('events.index')"
                        class="-ml-2 mb-2 inline-flex items-center gap-1 rounded-field px-2 py-1 text-sm font-medium text-ink-3 transition-colors hover:text-ink">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                        </svg>
                        Events
                    </Link>

                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-bold"
                                    :class="priorityColors[event.priority]">{{ event.priority }}</span>
                                <span v-if="currentPlan?.is_active"
                                    class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-[11px] font-semibold text-success-ink">
                                    <span class="h-1.5 w-1.5 rounded-full bg-success" />
                                    Aktiver Plan
                                </span>
                                <span v-if="coach && currentPlan?.is_active"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 py-1 pl-1 pr-2.5 text-[11px] font-medium text-ink-2">
                                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[8px] font-bold text-white"
                                        :class="coachAccent.avatar">{{ coach.avatar_initials }}</span>
                                    {{ coachName }}
                                </span>
                            </div>

                            <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink lg:text-3xl">{{ event.name }}</h1>

                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[15px] text-ink-3">
                                <span>{{ new Date(event.event_date).toLocaleDateString('de-DE', { day: '2-digit', month: 'long', year: 'numeric' }) }}</span>
                                <span aria-hidden="true">·</span>
                                <span class="font-medium text-ink-2">{{ event.distance_label }}</span>
                                <template v-if="event.target_time_formatted">
                                    <span aria-hidden="true">·</span>
                                    <span>Ziel {{ event.target_time_formatted }}</span>
                                </template>
                                <span aria-hidden="true">·</span>
                                <span :class="event.days_until <= 7 && event.days_until >= 0 ? 'font-semibold text-danger' : ''">
                                    {{ event.days_until > 0 ? `noch ${event.days_until} Tage` : event.days_until === 0 ? 'Heute!' : 'Vorbei' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="!isPastEvent" class="flex shrink-0 gap-2">
                            <AppButton v-if="currentPlan?.is_active" variant="ghost" size="sm" @click="cancelModal = true">
                                Abbrechen
                            </AppButton>
                            <AppButton
                                :variant="currentPlan?.is_active ? 'secondary' : 'primary'"
                                size="sm"
                                :loading="generating"
                                @click="generatePlan"
                            >
                                {{ generating ? coachName + ' rechnet…' : currentPlan?.is_active ? 'Plan aktualisieren' : 'Plan erstellen' }}
                            </AppButton>
                        </div>
                    </div>
                </header>

                <!-- ══ HINWEISE ══════════════════════════════════════ -->
                <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" leave-to-class="opacity-0">
                    <div v-if="wellbeingBanner && todaySession"
                        class="flex items-start gap-3 rounded-card p-4 shadow-card"
                        :class="{
                            'bg-danger-soft':  wellbeingBanner.level === 'danger',
                            'bg-warn-soft':    wellbeingBanner.level === 'warning',
                            'bg-success-soft': wellbeingBanner.level === 'good',
                        }"
                    >
                        <span class="mt-0.5 shrink-0 text-xl leading-none">{{ wellbeingBanner.icon }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold"
                                :class="{
                                    'text-danger-ink':  wellbeingBanner.level === 'danger',
                                    'text-warn-ink':    wellbeingBanner.level === 'warning',
                                    'text-success-ink': wellbeingBanner.level === 'good',
                                }">
                                {{ wellbeingBanner.text }}
                            </p>
                            <p v-if="wellbeingBanner.tip" class="mt-0.5 text-[13px] text-ink-2">{{ wellbeingBanner.tip }}</p>
                        </div>
                        <AppButton v-if="wellbeingBanner.level === 'danger'" variant="danger" size="sm" class="shrink-0"
                            @click="openSkipModal(todaySession, 'Krank')">
                            Kein Training
                        </AppButton>
                    </div>
                </Transition>

                <div v-if="currentPlan?.needs_plan_update && !isPastEvent"
                    class="flex items-start gap-3 rounded-card bg-warn-soft p-4 shadow-card">
                    <span class="mt-0.5 shrink-0 text-xl leading-none">🔗</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-warn-ink">Neue Strava-Aktivität importiert</p>
                        <p class="mt-0.5 text-[13px] text-ink-2">
                            {{ coachName }} sollte den verbleibenden Plan neu berechnen.
                        </p>
                    </div>
                    <AppButton variant="secondary" size="sm" class="shrink-0" :loading="generating" @click="generatePlan">
                        Aktualisieren
                    </AppButton>
                </div>

                <div v-if="errorMsg" class="rounded-card bg-danger-soft p-4 text-sm text-danger-ink shadow-card">
                    {{ errorMsg }}
                </div>

                <!-- ══ GENERIERUNG LÄUFT ═════════════════════════════ -->
                <AppCard v-if="generating">
                    <div class="flex flex-col items-center px-6 py-12 text-center">
                        <svg class="mb-4 h-9 w-9 animate-spin text-accent" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                        </svg>
                        <p class="text-base font-semibold text-ink">{{ coachName }} analysiert deine Daten</p>
                        <p class="mt-1.5 max-w-sm text-sm text-ink-3">
                            Aktivitäten, Wellbeing, Erholungswerte und Athletenprofil fließen in den Plan ein.
                        </p>
                    </div>
                </AppCard>

                <!-- ══ NOCH KEIN PLAN ════════════════════════════════ -->
                <AppCard v-else-if="!currentPlan || visibleSessions.length === 0">
                    <EmptyState
                        :title="isPastEvent ? 'Kein Trainingsplan vorhanden' : 'Noch kein Trainingsplan'"
                        :description="isPastEvent
                            ? 'Für dieses Event wurde kein Plan erstellt.'
                            : `${coachName} baut aus Aktivitäten, Wellbeing und Athletenprofil einen Plan bis zum Renntag.`"
                    >
                        <template #icon>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                            </svg>
                        </template>
                        <AppButton v-if="!isPastEvent" :loading="generating" @click="generatePlan">Plan erstellen</AppButton>
                    </EmptyState>
                </AppCard>

                <!-- ══ PLAN ══════════════════════════════════════════ -->
                <template v-else>
                    <!-- Bilanz -->
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatTile label="Kilometer" :value="weeklyLoad.total" unit="km" />
                        <StatTile label="Einheiten" :value="weeklyLoad.runs" />
                        <StatTile label="Erledigt"  :value="weeklyLoad.done" tone="success" />
                        <StatTile label="Ausgelassen" :value="weeklyLoad.skipped" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">

                        <!-- ── Einheiten ───────────────────────────── -->
                        <section class="min-w-0">
                            <SectionHeader :title="isPastEvent ? 'Trainings-Auswertung' : 'Trainingsplan'">
                                <template #action>
                                    <div class="flex items-center gap-3">
                                        <button
                                            v-if="revisions.length"
                                            type="button"
                                            @click="revisionsOpen = true"
                                            class="text-[13px] font-medium text-accent hover:underline"
                                        >
                                            Verlauf
                                        </button>
                                        <span v-if="currentPlan" class="text-[13px] text-ink-3">
                                            {{ currentPlan.generated_at }}
                                        </span>
                                    </div>
                                </template>
                            </SectionHeader>

                            <div v-if="planIsRolling && currentPlan"
                                class="mb-3 flex items-start gap-2.5 rounded-card bg-surface px-4 py-3 shadow-card">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                </svg>
                                <p class="text-[13px] leading-relaxed text-ink-2">
                                    Bis zum Renntag ist es noch eine Weile — geplant werden zunächst zwei Wochen,
                                    danach verlängert sich der Plan automatisch.
                                </p>
                            </div>

                            <div class="space-y-2.5">
                                <SwipeRow
                                    v-for="session in visibleSessions"
                                    :key="session.id"
                                    content-class="bg-surface-2 rounded-card"
                                    :right-width="canSkip(session) ? 88 : 0"
                                    :disabled="!canSkip(session)"
                                    class="rounded-card"
                                >
                                    <template #right="{ close }">
                                        <button
                                            @click="openSkipModal(session); close()"
                                            class="flex w-full flex-col items-center justify-center gap-1 bg-ink-3 text-[11px] font-semibold text-canvas"
                                        >
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                            Kein Training
                                        </button>
                                    </template>

                                    <article
                                        class="overflow-hidden rounded-card bg-surface shadow-card transition-opacity"
                                        :class="[
                                            session.status === 'completed' ? 'opacity-75' : '',
                                            session.status === 'skipped'   ? 'opacity-50' : '',
                                            isToday(session.planned_date) && session.status === 'planned'
                                                ? 'ring-2 ring-accent' : '',
                                        ]"
                                    >
                                        <p v-if="session.was_unplanned"
                                            class="flex items-center gap-1.5 bg-warn-soft px-4 py-2 text-[12px] font-medium text-warn-ink">
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                            </svg>
                                            {{ session.planned_snapshot?.type === 'rest'
                                                ? 'Nicht im Plan — für diesen Tag war Ruhe vorgesehen'
                                                : 'Nicht im Plan — aus Strava importiert' }}
                                        </p>

                                        <div class="flex gap-3.5 p-4">
                                            <!-- Typ-Marke -->
                                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-field"
                                                :class="typeOf(session.type).pill">
                                                <svg v-if="session.status === 'completed'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                                <svg v-else-if="session.status === 'skipped'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                </svg>
                                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="typeOf(session.type).icon" />
                                            </span>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="text-[13px] font-medium text-ink-3">{{ formatDate(session.planned_date) }}</span>
                                                    <span v-if="isToday(session.planned_date) && session.status === 'planned'"
                                                        class="rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-bold text-accent-ink">Heute</span>
                                                    <span v-if="session.status === 'completed'"
                                                        class="rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success-ink">Erledigt</span>
                                                    <span v-else-if="session.status === 'skipped'"
                                                        class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-semibold text-ink-3">Ausgelassen</span>
                                                    <span v-if="session.activity_id"
                                                        class="rounded-full bg-warn-soft px-2 py-0.5 text-[11px] font-semibold text-warn-ink">Strava</span>
                                                </div>

                                                <h3 class="mt-1 text-[15px] font-semibold text-ink">{{ session.title }}</h3>

                                                <p v-if="session.description" class="mt-1 text-[13px] leading-relaxed text-ink-3">
                                                    {{ session.description }}
                                                </p>
                                                <p v-if="session.skip_reason" class="mt-1 text-[13px] italic text-ink-3">
                                                    Grund: {{ session.skip_reason }}
                                                </p>

                                                <!-- Was ursprünglich geplant war. Die Felder daneben
                                                     tragen nach dem Strava-Import die Ist-Werte. -->
                                                <p v-if="plannedDiffers(session)" class="mt-1 text-[13px] text-ink-3">
                                                    Geplant war: {{ plannedSummary(session) }}
                                                </p>

                                                <!-- Kennzahlen -->
                                                <div v-if="session.type !== 'rest' && session.status !== 'skipped'"
                                                    class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] tabular-nums text-ink-2">
                                                    <span v-if="session.distance_km"><strong class="font-semibold text-ink">{{ session.distance_km }}</strong> km</span>
                                                    <span v-if="session.duration_min"><strong class="font-semibold text-ink">{{ session.duration_min }}</strong> min</span>
                                                    <span v-if="session.pace_target && session.pace_target !== 'null'">
                                                        <strong class="font-semibold text-ink">{{ session.pace_target }}</strong> /km
                                                    </span>
                                                    <span v-if="session.zone" class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                        :class="typeOf(session.type).pill">Zone {{ session.zone }}</span>
                                                </div>

                                                <!-- Übungen -->
                                                <ul v-if="session.exercises && session.exercises.length && session.status !== 'skipped'"
                                                    class="mt-2.5 space-y-1">
                                                    <li v-for="(ex, i) in session.exercises" :key="i"
                                                        class="flex flex-wrap items-baseline gap-1.5 text-[13px]">
                                                        <span class="font-medium text-ink-2">{{ ex.name }}</span>
                                                        <span v-if="ex.sets || ex.reps" class="tabular-nums text-ink-3">{{ [ex.sets, ex.reps].filter(Boolean).join('×') }}</span>
                                                        <span v-if="ex.load" class="text-ink-3">· {{ ex.load }}</span>
                                                    </li>
                                                </ul>

                                                <!-- Aktionen -->
                                                <div v-if="session.type !== 'rest'" class="mt-3 flex flex-wrap gap-2">
                                                    <AppButton variant="secondary" size="sm" @click="openDetail(session)">Details</AppButton>

                                                    <AppButton
                                                        v-if="session.status === 'planned' && isStrengthType(session.type) && session.planned_date <= today && !isPastEvent"
                                                        variant="secondary" size="sm"
                                                        :loading="completingId === session.id"
                                                        @click="completeSession(session)"
                                                    >
                                                        Erledigt
                                                    </AppButton>

                                                    <AppButton
                                                        v-if="session.status === 'planned' && !isPastEvent && !isStrengthType(session.type)"
                                                        variant="ghost" size="sm"
                                                        @click="openWorkoutPicker(session)"
                                                    >
                                                        Eigenes Workout
                                                    </AppButton>

                                                    <AppButton
                                                        v-if="session.status === 'planned' && session.planned_date !== event.event_date"
                                                        variant="ghost" size="sm"
                                                        @click="openSkipModal(session)"
                                                    >
                                                        Kein Training
                                                    </AppButton>

                                                    <AppButton v-if="isAdmin" variant="ghost" size="sm"
                                                        title="Admin: Steps- und Nutrition-Cache leeren"
                                                        @click="resetSessionCache(session)">
                                                        AI Reset
                                                    </AppButton>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                </SwipeRow>
                            </div>

                            <p class="mt-4 px-1 text-[13px] text-ink-3">
                                „Plan aktualisieren" berücksichtigt ausgelassene Einheiten und rechnet die verbleibenden neu.
                            </p>
                        </section>

                        <!-- ── Seitenspalte ────────────────────────── -->
                        <aside class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:self-start">

                            <!-- Prognose -->
                            <AppCard v-if="prediction && !isPastEvent && !backyard" title="Leistungsprognose">
                                <div class="flex items-end justify-between gap-4">
                                    <div>
                                        <p class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ prediction.time }}</p>
                                        <p class="mt-0.5 text-[13px] text-ink-3">Pace {{ prediction.pace }} /km</p>
                                    </div>
                                    <span v-if="predictionDeltaText"
                                        class="rounded-full px-2.5 py-1 text-[13px] font-semibold"
                                        :class="predictionDeltaText.positive ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink'">
                                        {{ predictionDeltaText.positive ? '−' : '+' }}{{ predictionDeltaText.label }}
                                    </span>
                                </div>

                                <div v-if="prediction.text" class="mt-4 border-t border-line pt-3.5">
                                    <div class="mb-1.5 flex items-center gap-1.5">
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                            :class="coachAccent.avatar">{{ coachName[0] }}</span>
                                        <span class="text-[13px] font-semibold text-ink-3">{{ coachName }}</span>
                                    </div>
                                    <p class="text-[13px] leading-relaxed text-ink-2">{{ prediction.text }}</p>
                                </div>

                                <p class="mt-3 text-[11px] text-ink-3">Aus der Schwellenpace · Jack Daniels T-Pace</p>
                            </AppCard>

                            <!-- Backyard: Yard-Readiness -->
                            <AppCard v-if="backyard && !isPastEvent" title="Yard-Readiness">
                                <p v-if="!backyard.readiness.has_data" class="text-[15px] leading-relaxed text-ink-2">
                                    {{ backyard.readiness.advice }}
                                </p>

                                <template v-else>
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ backyard.readiness.estimated_yards }}</span>
                                        <span class="text-[13px] tabular-nums text-ink-3">({{ backyard.readiness.range_low }}–{{ backyard.readiness.range_high }})</span>
                                    </div>
                                    <p class="mt-0.5 text-[13px] text-ink-3">Ziel: {{ backyard.readiness.target_yards }} Yards</p>

                                    <div class="mt-4 grid grid-cols-2 gap-4 border-t border-line pt-3.5">
                                        <div>
                                            <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Längster Lauf</p>
                                            <p class="mt-0.5 text-[15px] font-semibold tabular-nums text-ink">{{ backyard.readiness.longest_run_km }} km</p>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Peak-Woche</p>
                                            <p class="mt-0.5 text-[15px] font-semibold tabular-nums text-ink">{{ backyard.readiness.peak_weekly_km }} km</p>
                                        </div>
                                    </div>

                                    <p class="mt-3 text-[13px] leading-relaxed text-ink-2">{{ backyard.readiness.advice }}</p>
                                </template>
                            </AppCard>

                            <!-- Backyard: Rundenrhythmus -->
                            <AppCard v-if="backyard && !isPastEvent" flush title="Runden-Rhythmus" subtitle="6,706 km pro Stunde">
                                <div class="overflow-x-auto px-5 pb-5">
                                    <table class="w-full text-[13px]">
                                        <thead>
                                            <tr class="text-left text-[11px] font-medium uppercase tracking-wide text-ink-3">
                                                <th class="pb-2 pr-3 font-medium">Pace</th>
                                                <th class="pb-2 pr-3 font-medium">Rundenzeit</th>
                                                <th class="pb-2 font-medium">Pause</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            <tr v-for="(r, i) in backyard.rhythm" :key="i">
                                                <td class="py-2 pr-3 tabular-nums text-ink-2">{{ r.pace }}</td>
                                                <td class="py-2 pr-3 tabular-nums text-ink-2">{{ r.lap_time }}</td>
                                                <td class="py-2 font-semibold tabular-nums text-accent-ink">{{ r.rest_min }} min</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p class="mt-3 text-[12px] leading-relaxed text-ink-3">
                                        Langsamer laufen heißt mehr Pause. Konstanz schlägt Tempo — jede gesparte Minute ist Erholung.
                                    </p>
                                </div>
                            </AppCard>

                            <!-- Renntag-Strategie -->
                            <AppCard v-if="!isPastEvent && (raceStrategyLoading || raceStrategy)"
                                title="Renntag-Strategie"
                                :subtitle="`Pacing für ${event.target_time_formatted || 'dein Ziel'}`">

                                <div v-if="raceStrategyLoading" class="flex items-center gap-2 py-3 text-[13px] text-ink-3">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                                    </svg>
                                    Strategie wird erstellt…
                                </div>

                                <template v-else-if="raceStrategy">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-3xl font-bold tabular-nums tracking-tight text-ink">{{ raceStrategy.pace }}</span>
                                        <span class="text-[13px] text-ink-3">/km Zielpace</span>
                                    </div>

                                    <div class="mt-4 divide-y divide-line border-t border-line">
                                        <div v-for="(s, i) in raceStrategy.splits" :key="i"
                                            class="flex items-center justify-between py-2 text-[13px]"
                                            :class="s.is_finish ? 'font-bold text-ink' : 'text-ink-2'">
                                            <span>{{ s.is_finish ? '🏁 ' : '' }}{{ s.label }}</span>
                                            <span class="tabular-nums">{{ s.cumulative_time }}</span>
                                        </div>
                                    </div>

                                    <p v-if="raceStrategy.strategy_text"
                                        class="mt-4 whitespace-pre-line rounded-field bg-surface-2 p-3.5 text-[13px] leading-relaxed text-ink-2">
                                        {{ raceStrategy.strategy_text }}
                                    </p>
                                </template>
                            </AppCard>

                            <!-- Ergebnis eintragen -->
                            <AppCard v-if="isPastEvent && plan" title="Rennergebnis"
                                :subtitle="`Hilft ${coachName} beim nächsten Plan`">

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-field bg-surface-2 p-3.5">
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Zielzeit</p>
                                        <p class="mt-1 text-xl font-bold tabular-nums text-ink-2">{{ event.target_time_formatted || '—' }}</p>
                                    </div>
                                    <div class="rounded-field p-3.5 transition-colors"
                                        :class="goalAchieved === true ? 'bg-success-soft'
                                              : goalAchieved === false ? 'bg-danger-soft'
                                              : 'bg-surface-2'">
                                        <p class="text-[11px] font-medium uppercase tracking-wide"
                                            :class="goalAchieved === true ? 'text-success-ink' : goalAchieved === false ? 'text-danger-ink' : 'text-ink-3'">
                                            Dein Ergebnis
                                        </p>
                                        <div class="mt-1 flex items-baseline gap-1">
                                            <input v-model.number="resultHours" type="number" min="0" max="23" placeholder="0"
                                                class="w-9 border-none bg-transparent p-0 text-xl font-bold tabular-nums text-ink outline-none placeholder-ink-3" />
                                            <span class="text-lg font-bold text-ink-3">:</span>
                                            <input v-model.number="resultMinutes" type="number" min="0" max="59" placeholder="00"
                                                class="w-9 border-none bg-transparent p-0 text-xl font-bold tabular-nums text-ink outline-none placeholder-ink-3" />
                                        </div>
                                    </div>
                                </div>

                                <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95">
                                    <div v-if="goalAchieved !== null"
                                        class="mt-3 flex items-center gap-2.5 rounded-field px-3.5 py-2.5"
                                        :class="goalAchieved ? 'bg-success-soft' : 'bg-danger-soft'">
                                        <span class="text-lg">{{ goalAchieved ? '🎯' : '📉' }}</span>
                                        <div class="min-w-0">
                                            <p class="text-[13px] font-semibold"
                                                :class="goalAchieved ? 'text-success-ink' : 'text-danger-ink'">
                                                {{ goalAchieved ? 'Ziel erreicht!' : 'Ziel knapp verfehlt' }}
                                            </p>
                                            <p class="text-[12px] text-ink-2">{{ goalDeltaText }}</p>
                                        </div>
                                    </div>
                                </Transition>

                                <p class="z-label mt-4">Wie gut hat der Plan funktioniert?</p>
                                <div class="flex items-center gap-2">
                                    <button v-for="n in 5" :key="n" @click="resultRating = n"
                                        class="flex h-9 w-9 items-center justify-center rounded-field text-lg transition-all"
                                        :class="n <= resultRating ? 'scale-110 bg-warn-soft' : 'bg-surface-2 opacity-40 hover:opacity-70'">⭐</button>
                                    <button v-if="resultRating" class="ml-1 text-[12px] text-ink-3 hover:text-ink-2"
                                        @click="resultRating = 0">zurücksetzen</button>
                                </div>

                                <textarea v-model="resultNotes" rows="2"
                                    placeholder="Was hat gut geklappt? Was sollte anders sein?"
                                    class="z-input mt-4 resize-none" />

                                <AppButton block class="mt-3" :loading="resultSaving" @click="saveResult">
                                    {{ resultSaved ? 'Gespeichert' : 'Ergebnis speichern' }}
                                </AppButton>
                            </AppCard>

                            <!-- Renn-Analyse -->
                            <AppCard v-if="isPastEvent && plan && (raceAnalysisLoading || raceAnalysis)"
                                :title="`Analyse von ${coachName}`"
                                :subtitle="raceAnalysis?.found ? `Strava-Lauf · ${raceAnalysis.actual_time}` : null">

                                <div v-if="raceAnalysisLoading" class="flex items-center gap-2 py-3 text-[13px] text-ink-3">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                                    </svg>
                                    Analyse wird erstellt…
                                </div>
                                <p v-else-if="raceAnalysis?.found && raceAnalysis.analysis_text"
                                    class="whitespace-pre-line text-[13px] leading-relaxed text-ink-2">
                                    {{ raceAnalysis.analysis_text }}
                                </p>
                                <p v-else class="text-[13px] text-ink-3">
                                    Kein Renn-Lauf von Strava gefunden. Sobald die Aktivität da ist, erstellt
                                    {{ coachName }} hier die Auswertung.
                                </p>
                            </AppCard>
                        </aside>
                    </div>
                </template>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             ÄNDERUNGSVERLAUF
             ══════════════════════════════════════════════════════ -->
        <AppSheet :show="revisionsOpen" tall title="Änderungsverlauf" subtitle="Was sich am Plan geändert hat" @close="revisionsOpen = false">
            <div class="space-y-5">
                <article v-for="rev in revisions" :key="rev.id" class="rounded-card bg-surface-2 p-4">
                    <header class="mb-3 flex items-baseline justify-between gap-3">
                        <span class="text-[13px] font-semibold text-ink">{{ rev.label }}</span>
                        <span class="shrink-0 text-[12px] text-ink-3">{{ rev.at }}</span>
                    </header>

                    <ul v-if="rev.changes.length" class="space-y-2.5">
                        <li v-for="(c, i) in rev.changes" :key="i" class="flex gap-2.5">
                            <span class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full"
                                  :class="(revisionTone[c.kind] || revisionTone.changed).dot" />
                            <div class="min-w-0 text-[13px] leading-relaxed">
                                <span class="font-medium text-ink">{{ c.label }}</span>
                                <span class="mx-1.5 text-ink-3">·</span>
                                <template v-if="c.kind === 'added'">
                                    <span class="text-ink-2">neu: {{ c.to }}</span>
                                </template>
                                <template v-else-if="c.kind === 'removed'">
                                    <span class="text-ink-3 line-through">{{ c.from }}</span>
                                </template>
                                <template v-else>
                                    <span class="text-ink-3 line-through">{{ c.from }}</span>
                                    <span class="mx-1.5 text-ink-3">→</span>
                                    <span class="text-ink-2">{{ c.to }}</span>
                                </template>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-[13px] text-ink-3">Keine Änderungen an den geplanten Einheiten.</p>

                    <!-- Was der Prüfer an der Antwort des Modells korrigieren musste.
                         Stand bisher nur im Log. -->
                    <div v-if="rev.corrections.length" class="mt-3.5 border-t border-line pt-3">
                        <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-3">Automatisch korrigiert</p>
                        <ul class="space-y-1">
                            <li v-for="(corr, i) in rev.corrections" :key="i" class="text-[12px] leading-relaxed text-ink-3">
                                {{ corr }}
                            </li>
                        </ul>
                    </div>
                </article>
            </div>
        </AppSheet>

        <!-- ══════════════════════════════════════════════════════
             KEIN TRAINING
             ══════════════════════════════════════════════════════ -->
        <AppSheet :show="skipModal" title="Kein Training" :subtitle="skipSession?.title" @close="skipModal = false">
            <p class="z-label">Grund (optional)</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="r in skipReasons" :key="r" @click="skipReason = r"
                    class="rounded-full px-3.5 py-2 text-[13px] font-medium transition-colors active:scale-95"
                    :class="skipReason === r ? 'bg-ink text-canvas' : 'bg-surface-2 text-ink-2 hover:bg-surface-3'">
                    {{ r }}
                </button>
            </div>
            <p class="z-hint">Der Grund fließt in die nächste Planberechnung ein.</p>

            <template #footer>
                <AppButton block :loading="skipLoading" @click="confirmSkip">Einheit auslassen</AppButton>
            </template>
        </AppSheet>

        <!-- ══════════════════════════════════════════════════════
             EIGENES WORKOUT
             ══════════════════════════════════════════════════════ -->
        <AppSheet
            :show="workoutPickerModal"
            title="Eigenes Workout"
            :subtitle="workoutPickerSession?.title"
            @close="workoutPickerModal = false"
        >
            <div v-if="workoutLibraryLoading" class="flex items-center gap-2 py-6 text-sm text-ink-3">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                </svg>
                Workouts werden geladen…
            </div>

            <EmptyState
                v-else-if="!workoutLibrary.length"
                title="Noch keine Workouts"
                description="Lege dir im Workout-Builder eigene Einheiten an, dann kannst du sie hier einsetzen."
            />

            <div v-else class="space-y-2">
                <button
                    v-for="w in workoutLibrary"
                    :key="w.id"
                    @click="workoutPickerSelected = w.id"
                    class="flex w-full items-center gap-3 rounded-card p-3.5 text-left transition-colors"
                    :class="workoutPickerSelected === w.id ? 'bg-accent-soft' : 'bg-surface-2 hover:bg-surface-3'"
                >
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                        :class="workoutPickerSelected === w.id ? 'border-accent bg-accent' : 'border-line-strong'">
                        <span v-if="workoutPickerSelected === w.id" class="h-1.5 w-1.5 rounded-full bg-white" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold text-ink">{{ w.name }}</span>
                        <span class="mt-0.5 block text-[13px] text-ink-3">
                            {{ workoutTypeLabel[w.type] ?? w.type }}
                            <template v-if="w.total_duration_min"> · {{ w.total_duration_min }} min</template>
                        </span>
                    </span>
                </button>
            </div>

            <p v-if="workoutPickerError" class="z-error">{{ workoutPickerError }}</p>

            <template #footer>
                <AppButton block :disabled="!workoutPickerSelected" :loading="workoutApplying" @click="applyWorkout">
                    Workout übernehmen
                </AppButton>
            </template>
        </AppSheet>

        <!-- ══════════════════════════════════════════════════════
             EINHEIT IM DETAIL
             ══════════════════════════════════════════════════════ -->
        <AppSheet :show="!!detailSession" tall max-width="2xl" @close="detailSession = null">
            <template #header>
                <div v-if="detailSession">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="typeOf(detailSession.type).pill">
                            {{ typeOf(detailSession.type).label }}
                        </span>
                        <span v-if="detailSession.zone"
                            class="rounded-full bg-surface-2 px-2.5 py-1 text-[11px] font-semibold text-ink-2">
                            Zone {{ detailSession.zone }}
                        </span>
                        <span v-if="detailSession.intensity"
                            class="rounded-full bg-surface-2 px-2.5 py-1 text-[11px] font-semibold capitalize text-ink-2">
                            {{ detailSession.intensity }}
                        </span>
                    </div>
                    <h2 class="mt-2 text-xl font-bold leading-snug tracking-tight text-ink">{{ detailSession.title }}</h2>
                    <p class="mt-0.5 text-[13px] text-ink-3">{{ formatDate(detailSession.planned_date) }}</p>
                </div>
            </template>

            <div v-if="detailSession" class="space-y-6">

                <!-- Eckdaten -->
                <div class="grid grid-cols-3 gap-3">
                    <div v-if="totalStepDistanceKm || detailSession.distance_km" class="rounded-card bg-surface-2 p-4">
                        <p class="text-xl font-bold tabular-nums text-ink">{{ totalStepDistanceKm ?? detailSession.distance_km }}</p>
                        <p class="mt-0.5 text-[12px] font-medium uppercase tracking-wide text-ink-3">km</p>
                    </div>
                    <div v-if="totalStepDuration || detailSession.duration_min" class="rounded-card bg-surface-2 p-4">
                        <p class="text-xl font-bold tabular-nums text-ink">{{ totalStepDuration || detailSession.duration_min }}</p>
                        <p class="mt-0.5 text-[12px] font-medium uppercase tracking-wide text-ink-3">min</p>
                    </div>
                    <div v-if="detailSession.pace_target && detailSession.pace_target !== 'null'" class="rounded-card bg-surface-2 p-4">
                        <p class="text-xl font-bold tabular-nums text-ink">{{ detailSession.pace_target }}</p>
                        <p class="mt-0.5 text-[12px] font-medium uppercase tracking-wide text-ink-3">min/km</p>
                    </div>
                </div>

                <p v-if="detailSession.description" class="text-[15px] leading-relaxed text-ink-2">
                    {{ detailSession.description }}
                </p>

                <!-- Ab lg zweispaltig: Struktur links, Verpflegung rechts -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                    <!-- Übungen -->
                    <section v-if="detailIsStrength && detailSession.exercises && detailSession.exercises.length" class="lg:col-span-2">
                        <h3 class="mb-3 text-[15px] font-semibold text-ink">Übungen</h3>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div v-for="(ex, i) in detailSession.exercises" :key="i" class="rounded-card bg-surface-2 p-4">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <span class="text-[15px] font-semibold text-ink">{{ ex.name }}</span>
                                    <span v-if="ex.sets || ex.reps" class="text-[13px] font-semibold tabular-nums text-accent-ink">
                                        {{ [ex.sets, ex.reps].filter(Boolean).join('×') }}
                                    </span>
                                    <span v-if="ex.load" class="text-[13px] text-ink-3">· {{ ex.load }}</span>
                                </div>
                                <p v-if="ex.note" class="mt-0.5 text-[13px] italic text-ink-3">{{ ex.note }}</p>
                                <a :href="exerciseVideoUrl(ex.name)" target="_blank" rel="noopener noreferrer"
                                    class="mt-2 inline-flex items-center gap-1.5 text-[13px] font-semibold text-accent-ink hover:underline">
                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M21.582 6.186a2.506 2.506 0 0 0-1.768-1.768C18.254 4 12 4 12 4s-6.254 0-7.814.418c-.86.23-1.538.908-1.768 1.768C2 7.746 2 12 2 12s0 4.254.418 5.814c.23.86.908 1.538 1.768 1.768C5.746 20 12 20 12 20s6.254 0 7.814-.418a2.506 2.506 0 0 0 1.768-1.768C22 16.254 22 12 22 12s0-4.254-.418-5.814ZM10 15.464V8.536L16 12l-6 3.464Z" />
                                    </svg>
                                    Video ansehen
                                </a>
                            </div>
                        </div>
                    </section>

                    <!-- Trainingsstruktur -->
                    <section v-if="!isRaceSession && !isCompletedSession && !detailIsStrength">
                        <h3 class="mb-3 text-[15px] font-semibold text-ink">Trainingsstruktur</h3>

                        <div v-if="stepsLoading" class="flex items-center gap-2 py-4 text-[13px] text-ink-3">
                            <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            Struktur wird erstellt…
                        </div>

                        <p v-else-if="stepsError" class="text-[13px] text-danger">{{ stepsError }}</p>

                        <div v-else-if="aiSteps && aiSteps.length">
                            <!-- Verlaufsbalken: Breite = Zeitanteil, Höhe = Intensität -->
                            <div class="mb-4 flex h-14 items-end gap-0.5">
                                <div
                                    v-for="(s, i) in stepsWithReps"
                                    :key="i"
                                    :style="{
                                        width:  ((s.duration_min || 0) / totalStepDuration * 100).toFixed(1) + '%',
                                        height: (stepHeightPct[s.type] ?? 60) + '%',
                                    }"
                                    :class="[stepBarColor[s.type] ?? 'bg-accent', 'rounded-t']"
                                    :title="`${s.label}: ${s.duration_min} min`"
                                />
                            </div>

                            <div class="space-y-2">
                                <div v-for="(step, idx) in groupedSteps" :key="idx" class="rounded-card bg-surface-2 p-3.5">
                                    <template v-if="step.isGroup">
                                        <div class="mb-2.5 flex items-center gap-2">
                                            <span class="flex h-6 items-center rounded-full bg-danger-soft px-2 text-[11px] font-bold text-danger-ink">
                                                ×{{ step.repetitions }}
                                            </span>
                                            <span class="text-[15px] font-semibold text-ink">{{ step.group_label || 'Intervall' }}</span>
                                        </div>
                                        <div class="space-y-2 pl-1">
                                            <div class="flex items-center gap-2.5">
                                                <span class="h-2 w-2 shrink-0 rounded-full bg-danger" />
                                                <span class="text-[13px] font-medium text-ink-2">{{ step.label }}</span>
                                                <span class="ml-auto text-[13px] tabular-nums text-ink-3">{{ step.duration_min }} min</span>
                                                <span v-if="step.pace_target" class="w-16 text-right text-[13px] font-semibold tabular-nums text-ink">
                                                    {{ step.pace_target }}
                                                </span>
                                            </div>
                                            <div v-if="step.pairedRest" class="flex items-center gap-2.5">
                                                <span class="h-2 w-2 shrink-0 rounded-full bg-surface-3" />
                                                <span class="text-[13px] text-ink-3">{{ step.pairedRest.label }}</span>
                                                <span class="ml-auto text-[13px] tabular-nums text-ink-3">{{ step.pairedRest.duration_min }} min</span>
                                                <span class="w-16" />
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else>
                                        <div class="flex items-center gap-2.5">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="stepBarColor[step.type]" />
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">
                                                    {{ stepLabel[step.type] ?? step.type }}
                                                </p>
                                                <p class="text-[13px] font-medium text-ink">{{ step.label }}</p>
                                            </div>
                                            <span class="shrink-0 text-[13px] tabular-nums text-ink-3">{{ step.duration_min }} min</span>
                                            <span v-if="step.pace_target" class="w-16 shrink-0 text-right text-[13px] font-semibold tabular-nums text-ink">
                                                {{ step.pace_target }}
                                            </span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Verpflegung -->
                    <section v-if="!isCompletedSession">
                        <h3 class="mb-3 text-[15px] font-semibold text-ink">Verpflegung</h3>

                        <div v-if="nutritionLoading" class="flex items-center gap-2 py-4 text-[13px] text-ink-3">
                            <svg class="h-4 w-4 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            {{ coachName }} stellt die Verpflegung zusammen…
                        </div>

                        <p v-else-if="nutritionError" class="text-[13px] text-danger">{{ nutritionError }}</p>

                        <div v-else-if="aiNutritionTips" class="space-y-3">
                            <div v-for="block in [
                                { key: 'before', icon: '🕐', label: isRaceSession ? 'Vor dem Rennen'      : 'Vorher', tips: aiNutritionTips.before },
                                { key: 'during', icon: detailIsStrength ? '💪' : '🏃', label: isRaceSession ? 'Während des Rennens' : 'Unterwegs', tips: aiNutritionTips.during },
                                { key: 'after',  icon: '✅', label: isRaceSession ? 'Nach dem Rennen'     : 'Danach',  tips: aiNutritionTips.after },
                            ]" :key="block.key" class="rounded-card bg-surface-2 p-4">
                                <div class="mb-2.5 flex items-center gap-2">
                                    <span class="text-base leading-none">{{ block.icon }}</span>
                                    <span class="text-[11px] font-bold uppercase tracking-wide text-ink-3">{{ block.label }}</span>
                                </div>
                                <ul class="space-y-2">
                                    <li v-for="tip in block.tips" :key="tip.text" class="flex items-start gap-2.5">
                                        <span class="shrink-0 leading-relaxed">{{ tip.icon }}</span>
                                        <span class="text-[13px] leading-relaxed text-ink-2">{{ tip.text }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <!-- Coach-Review -->
                    <section v-if="isCompletedSession && detailSession.coach_review" class="lg:col-span-2">
                        <h3 class="mb-3 text-[15px] font-semibold text-ink">{{ coachName }} über diese Einheit</h3>

                        <div class="rounded-card bg-accent-soft p-4">
                            <p class="whitespace-pre-line text-[15px] leading-relaxed text-accent-ink">{{ detailSession.coach_review }}</p>

                            <div v-if="detailSession.review_question && !detailSession.review_feedback" class="mt-4 border-t border-accent/20 pt-3.5">
                                <p class="mb-2.5 text-[15px] font-semibold text-accent-ink">{{ detailSession.review_question }}</p>
                                <div v-if="detailSession.review_options && detailSession.review_options.length" class="mb-2.5 flex flex-wrap gap-2">
                                    <button v-for="opt in detailSession.review_options" :key="opt"
                                        :disabled="reviewFeedbackSaving"
                                        class="rounded-full bg-surface px-3.5 py-2 text-[13px] font-semibold text-accent-ink transition-opacity hover:opacity-90 disabled:opacity-50"
                                        @click="submitReviewFeedback(opt)">
                                        {{ opt }}
                                    </button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input v-model="reviewFeedbackText" type="text" maxlength="300"
                                        placeholder="…oder eigene Antwort" class="z-input"
                                        @keyup.enter="submitReviewFeedback()" />
                                    <AppButton size="sm" :loading="reviewFeedbackSaving"
                                        :disabled="!reviewFeedbackText.trim()" @click="submitReviewFeedback()">
                                        Senden
                                    </AppButton>
                                </div>
                            </div>

                            <div v-else-if="detailSession.review_feedback" class="mt-4 border-t border-accent/20 pt-3.5">
                                <p v-if="detailSession.review_question" class="text-[15px] font-semibold text-accent-ink">
                                    {{ detailSession.review_question }}
                                </p>
                                <p class="mt-1 flex items-center gap-1.5 text-[13px] text-accent-ink">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Deine Antwort: <span class="font-semibold">{{ detailSession.review_feedback }}</span>
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Splits -->
                    <section v-if="isCompletedSession && detailLaps.length" class="lg:col-span-2">
                        <h3 class="mb-3 text-[15px] font-semibold text-ink">
                            Splits <span class="font-normal text-ink-3">· {{ detailLaps.length }} Runden</span>
                        </h3>

                        <div class="mb-4 flex h-14 items-end gap-0.5">
                            <div
                                v-for="(lap, i) in detailLaps"
                                :key="i"
                                :style="{
                                    width:  ((lap.moving_time || lap.elapsed_time || 0) / totalLapTime * 100).toFixed(2) + '%',
                                    height: lapHeightPct[i] + '%',
                                }"
                                class="rounded-t bg-warn"
                                :title="`Runde ${lap.index ?? i + 1}: ${lapDist(lap)} km · ${lapPace(lap)} min/km`"
                            />
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-[13px]">
                                <thead>
                                    <tr class="text-left text-[11px] font-medium uppercase tracking-wide text-ink-3">
                                        <th class="pb-2 pr-3 font-medium">#</th>
                                        <th class="pb-2 pr-3 font-medium">Zeit</th>
                                        <th class="pb-2 pr-3 font-medium">Distanz</th>
                                        <th class="pb-2 pr-3 text-right font-medium">Pace</th>
                                        <th class="pb-2 text-right font-medium">Puls</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr v-for="(lap, i) in detailLaps" :key="i">
                                        <td class="py-2 pr-3 font-semibold text-ink-3">{{ lap.index ?? i + 1 }}</td>
                                        <td class="py-2 pr-3 tabular-nums text-ink-2">{{ lapTime(lap.moving_time || lap.elapsed_time || 0) }}</td>
                                        <td class="py-2 pr-3 tabular-nums text-ink-2">{{ lapDist(lap) }} km</td>
                                        <td class="py-2 pr-3 text-right font-semibold tabular-nums text-ink">{{ lapPace(lap) }}</td>
                                        <td class="py-2 text-right tabular-nums text-ink-3">
                                            {{ lap.average_heartrate ? Math.round(lap.average_heartrate) + ' bpm' : '–' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- Bewertung -->
                <section v-if="detailSession.status === 'completed'" class="border-t border-line pt-5">
                    <h3 class="mb-4 text-[15px] font-semibold text-ink">Einheit bewerten</h3>

                    <p class="z-label">Wie gut ist die Einheit gelaufen?</p>
                    <div class="flex gap-2">
                        <button v-for="n in 5" :key="n" @click="ratingValue = ratingValue === n ? 0 : n"
                            class="flex h-10 w-10 items-center justify-center rounded-field text-xl transition-all"
                            :class="n <= ratingValue ? 'scale-110 bg-warn-soft' : 'bg-surface-2 opacity-40 hover:opacity-70'">
                            ⭐
                        </button>
                    </div>

                    <p class="z-label mt-5">
                        Empfundene Anstrengung
                        <span class="font-normal text-ink-3">RPE {{ effortValue || '–' }}/10</span>
                    </p>
                    <input type="range" min="0" max="10" step="1" v-model.number="effortValue" class="z-range w-full" />
                    <div class="mt-1 flex justify-between text-[12px] text-ink-3">
                        <span>Sehr leicht</span><span>Maximal</span>
                    </div>

                    <p class="z-label mt-5">Notiz</p>
                    <textarea v-model="feelingNotes" rows="2" maxlength="300"
                        placeholder="Wie hat sich die Einheit angefühlt?" class="z-input resize-none" />

                    <AppButton block class="mt-4" :loading="ratingSaving"
                        :disabled="!ratingValue && !effortValue && !feelingNotes" @click="saveRating">
                        {{ ratingSaved ? 'Gespeichert' : 'Bewertung speichern' }}
                    </AppButton>
                </section>
            </div>

            <template v-if="detailSession" #footer>
                <AppButton
                    v-if="detailIsStrength && detailSession.status === 'planned' && !isPastEvent"
                    block
                    :loading="completingId === detailSession.id"
                    @click="completeSession(detailSession)"
                >
                    Als erledigt markieren
                </AppButton>

                <AppButton v-else-if="!detailIsStrength" block @click="openGarminModal">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                    Zu Garmin Connect senden
                </AppButton>
            </template>
        </AppSheet>

        <!-- Plan abbrechen -->
        <ConfirmSheet
            :show="cancelModal"
            title="Plan wirklich abbrechen?"
            message="Der Plan wird deaktiviert. Deine bereits absolvierten Einheiten bleiben erhalten. Du kannst danach für jedes Event einen neuen Plan erstellen."
            cancel-label="Nicht abbrechen"
            confirm-label="Ja, Plan abbrechen"
            :loading="cancelLoading"
            @confirm="cancelPlan"
            @close="cancelModal = false"
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
            with-disconnect
            @send="sendToGarminConnect"
            @disconnect="garminDisconnect"
            @close="garminModal = false"
        />
    </AuthenticatedLayout>
</template>
