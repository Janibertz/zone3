<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import TrainingModelFields from '@/Components/Settings/TrainingModelFields.vue';
import BikeFields from '@/Components/Settings/BikeFields.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import Toggle from '@/Components/UI/Toggle.vue';

const props = defineProps({
    coaches:     Array,
    resumeStep:  { type: String, default: null },
    connections: { type: Object, default: () => ({ strava: null, garmin: null, garminAvailable: false, garminStatus: null, source: { current: 'strava', usable: [] } }) },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

// ── Steps ─────────────────────────────────────────────────────────────────
// Schritte über Schlüssel statt Nummern: mit Trainingsmodell und Rad kamen
// zwei Schritte in die Mitte, und jede feste Nummer im Template hätte sich
// verschoben.
const steps = [
    { key: 'welcome',      label: 'Willkommen'    },
    { key: 'coach',        label: 'Dein Coach'    },
    { key: 'profile',      label: 'Dein Profil'   },
    { key: 'model',        label: 'Trainingsmodell' },
    { key: 'availability', label: 'Verfügbarkeit' },
    { key: 'strength',     label: 'Kraft & Core'  },
    { key: 'bike',         label: 'Rad / Zwift'   },
    { key: 'goal',         label: 'Dein Ziel'     },
    { key: 'connect',      label: 'Verbindungen'  },
].map((s, i) => ({ ...s, number: i + 1 }));
// Zurück von Strava oder Garmin geht es beim Verbinden weiter, nicht vorn.
const currentStep = ref(steps.find(s => s.key === props.resumeStep)?.number ?? 1);
const totalSteps = steps.length;
const stepKey    = computed(() => steps[currentStep.value - 1].key);
function nextStep() { if (currentStep.value < totalSteps) currentStep.value++; }

// ════════════════════════════════════════════════════════════════════════
// STEP 2 — Coach selection
// ════════════════════════════════════════════════════════════════════════
const selectedCoachId = ref(props.coaches?.find(c => c.slug === 'max')?.id ?? null);
const coachLoading    = ref(false);
const coachError      = ref(null);

const coachColors = {
    orange: { bg: 'bg-warn', ring: 'ring-warn', light: 'bg-warn-soft', border: 'border-warn', badge: 'bg-warn-soft text-warn-ink' },
    blue:   { bg: 'bg-info',   ring: 'ring-info',   light: 'bg-info-soft',   border: 'border-info',   badge: 'bg-info-soft text-info-ink'   },
    green:  { bg: 'bg-success',  ring: 'ring-success',  light: 'bg-success-soft',  border: 'border-success',  badge: 'bg-success-soft text-success-ink'  },
    purple: { bg: 'bg-accent', ring: 'ring-accent', light: 'bg-accent-soft', border: 'border-accent', badge: 'bg-accent-soft text-accent-ink' },
};

const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

async function submitCoach() {
    if (!selectedCoachId.value) return;
    coachLoading.value = true;
    coachError.value   = null;
    try {
        await axios.post(route('onboarding.coach'), { coach_id: selectedCoachId.value });
        nextStep();
    } catch {
        coachError.value = 'Fehler beim Speichern. Bitte versuche es erneut.';
    } finally {
        coachLoading.value = false;
    }
}

// ════════════════════════════════════════════════════════════════════════
// STEP 2 — Athlete profile
// ════════════════════════════════════════════════════════════════════════
// Two modes: 'know' = user knows values, 'estimate' = AI estimates from race
const profileMode = ref(null); // null = choose, 'know', 'estimate'

// ── Mode: know ──────────────────────────────────────────────────────────
const knownForm = ref({ threshold_heart_rate: '', max_heart_rate: '', threshold_speed: '' });
const knownErrors  = ref({});
const knownLoading = ref(false);

async function submitKnown() {
    knownErrors.value  = {};
    knownLoading.value = true;
    try {
        await axios.post(route('onboarding.profile'), knownForm.value);
        nextStep();
    } catch (err) {
        if (err.response?.status === 422) knownErrors.value = err.response.data.errors;
    } finally {
        knownLoading.value = false;
    }
}

// ── Mode: estimate ───────────────────────────────────────────────────────
const raceDistances = [
    { value: '5km',           label: '5 km',           example: 'z.B. 25:00' },
    { value: '10km',          label: '10 km',           example: 'z.B. 52:00' },
    { value: 'half_marathon', label: 'Halbmarathon',   example: 'z.B. 1:50:00' },
    { value: 'marathon',      label: 'Marathon',        example: 'z.B. 3:45:00' },
];

const estimateForm = ref({
    age:           '',
    race_distance: 'half_marathon',
    race_time:     '',
    weekly_runs:   3,
});
const estimateErrors  = ref({});
const estimateLoading = ref(false);
const estimateResult  = ref(null); // AI-returned values shown for confirmation

const raceTimePlaceholder = computed(() =>
    raceDistances.find(d => d.value === estimateForm.value.race_distance)?.example ?? 'z.B. 1:50:00'
);

async function runEstimate() {
    estimateErrors.value  = {};
    estimateLoading.value = true;
    estimateResult.value  = null;
    try {
        const res = await axios.post(route('onboarding.estimate-profile'), estimateForm.value);
        estimateResult.value = res.data; // {threshold_heart_rate, max_heart_rate, threshold_speed}
    } catch (err) {
        if (err.response?.status === 422) estimateErrors.value = err.response.data.errors;
        else estimateErrors.value = { general: ['Schätzung fehlgeschlagen. Bitte versuche es erneut.'] };
    } finally {
        estimateLoading.value = false;
    }
}

async function confirmEstimate() {
    knownLoading.value = true;
    try {
        await axios.post(route('onboarding.profile'), estimateResult.value);
        nextStep();
    } catch (err) {
        estimateErrors.value = { general: ['Speichern fehlgeschlagen.'] };
    } finally {
        knownLoading.value = false;
    }
}

// ════════════════════════════════════════════════════════════════════════
// STEP 3 — Weekly availability
// ════════════════════════════════════════════════════════════════════════
const days = [
    { key: 'monday',    label: 'Mo', full: 'Montag'     },
    { key: 'tuesday',   label: 'Di', full: 'Dienstag'   },
    { key: 'wednesday', label: 'Mi', full: 'Mittwoch'   },
    { key: 'thursday',  label: 'Do', full: 'Donnerstag' },
    { key: 'friday',    label: 'Fr', full: 'Freitag'    },
    { key: 'saturday',  label: 'Sa', full: 'Samstag'    },
    { key: 'sunday',    label: 'So', full: 'Sonntag'    },
];

const durationOptions = [
    { value: 30,  label: '30 min' },
    { value: 45,  label: '45 min' },
    { value: 60,  label: '1 Std'  },
    { value: 90,  label: '1:30 Std' },
    { value: 120, label: '2 Std'  },
    { value: 180, label: '3 Std+' },
];

const availability = ref({
    monday:    { available: true,  duration_min: 60 },
    tuesday:   { available: false, duration_min: 0  },
    wednesday: { available: true,  duration_min: 60 },
    thursday:  { available: false, duration_min: 0  },
    friday:    { available: true,  duration_min: 60 },
    saturday:  { available: true,  duration_min: 90 },
    sunday:    { available: false, duration_min: 0  },
});

const availabilityLoading = ref(false);
const availabilityErrors  = ref({});

function toggleDay(key) {
    availability.value[key].available = !availability.value[key].available;
    if (!availability.value[key].available) {
        availability.value[key].duration_min = 0;
        delete availability.value[key].second_min;
    }
    else if (availability.value[key].duration_min === 0) availability.value[key].duration_min = 60;
}

// Zweites Zeitfenster (abends). Wer norwegisch trainiert, braucht es an den
// Doppel-Tagen; für alle anderen bleibt es aus.
const secondOptions = [30, 45, 60];
function toggleSecond(key) {
    if (availability.value[key].second_min) delete availability.value[key].second_min;
    else availability.value[key].second_min = 45;
}

// ════════════════════════════════════════════════════════════════════════
// Trainingsmodell
// ════════════════════════════════════════════════════════════════════════
const modelForm    = ref({ training_model: 'polarized', running_experience_years: null, has_lactate_meter: false });
const modelLoading = ref(false);
const modelError   = ref(null);

async function submitModel() {
    modelLoading.value = true;
    modelError.value   = null;
    try {
        await axios.post(route('onboarding.training-model'), modelForm.value);
        nextStep();
    } catch (err) {
        modelError.value = Object.values(err?.response?.data?.errors ?? {})[0]?.[0] ?? 'Speichern fehlgeschlagen.';
    } finally {
        modelLoading.value = false;
    }
}

// Die Readiness braucht den Wochenumfang aus Strava, und Strava kommt erst
// im letzten Schritt. Hier zählt deshalb nur die Erfahrung.
const modelReadiness = computed(() => {
    const years = modelForm.value.running_experience_years;
    return years !== null && years !== '' && years < 2
        ? { recommended: false, reasons: [`Du läufst seit ${years} ${years === 1 ? 'Jahr' : 'Jahren'}. Doppel-Schwelle ist für Läufer mit mindestens 2 Jahren Erfahrung gedacht.`] }
        : { recommended: true, reasons: [] };
});

// ════════════════════════════════════════════════════════════════════════
// Rad / Zwift
// ════════════════════════════════════════════════════════════════════════
const bikeForm = ref({
    bike_enabled: false, bike_season: 'winter', bike_purpose: 'both',
    bike_max_per_week: 2, bike_preferred_days: [], ftp: null,
});
const bikeLoading = ref(false);
const bikeError   = ref(null);

async function submitBike() {
    bikeLoading.value = true;
    bikeError.value   = null;
    try {
        await axios.post(route('onboarding.bike'), { ...bikeForm.value, ftp: bikeForm.value.ftp || null });
        nextStep();
    } catch (err) {
        bikeError.value = Object.values(err?.response?.data?.errors ?? {})[0]?.[0] ?? 'Speichern fehlgeschlagen.';
    } finally {
        bikeLoading.value = false;
    }
}

async function submitAvailability() {
    availabilityErrors.value  = {};
    availabilityLoading.value = true;
    try {
        await axios.post(route('onboarding.availability'), { availability: availability.value });
        nextStep();
    } catch (err) {
        if (err.response?.status === 422) availabilityErrors.value = err.response.data.errors;
    } finally {
        availabilityLoading.value = false;
    }
}

// ════════════════════════════════════════════════════════════════════════
// STEP 4 — Race goal with target time
// ════════════════════════════════════════════════════════════════════════
const raceOptions = [
    { value: '5km',            label: '5 km',          distance: 5,        icon: '🏃' },
    { value: '10km',           label: '10 km',         distance: 10,       icon: '🏅' },
    { value: 'half_marathon',  label: 'Halbmarathon',  distance: 21.0975,  icon: '🥈' },
    { value: 'marathon',       label: 'Marathon',      distance: 42.195,   icon: '🏆' },
    { value: 'custom',         label: 'Andere Distanz',distance: null,     icon: '📍' },
    { value: 'backyard_ultra', label: 'Backyard Ultra',distance: null,     icon: '🔁' },
];

const BACKYARD_LAP_KM = 6.706;

const goalForm = ref({
    name:                '',
    race_distance:       'half_marathon',
    distance_km:         null,
    target_time_hours:   1,
    target_time_minutes: 45,
    target_yards:        null,
    race_date:           '',
});

const isBackyardGoal = computed(() => goalForm.value.race_distance === 'backyard_ultra');
const backyardGoalKm = computed(() =>
    goalForm.value.target_yards ? (Number(goalForm.value.target_yards) * BACKYARD_LAP_KM).toFixed(1).replace('.', ',') : null
);
const goalErrors  = ref({});
const goalLoading = ref(false);

// Auto-fill name when race + date chosen
watch([() => goalForm.value.race_distance, () => goalForm.value.race_date], () => {
    const race = raceOptions.find(r => r.value === goalForm.value.race_distance);
    if (!race || goalForm.value.name) return; // don't overwrite if user typed something
    const dateLabel = goalForm.value.race_date
        ? new Date(goalForm.value.race_date).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })
        : '';
    goalForm.value.name = dateLabel ? `${race.label} ${dateLabel}` : race.label;
});

function selectRace(opt) {
    goalForm.value.race_distance = opt.value;
    goalForm.value.name = ''; // reset so auto-fill kicks in
    goalForm.value.distance_km = opt.distance ?? null;
}

const targetTimeFormatted = computed(() => {
    const h = goalForm.value.target_time_hours;
    const m = goalForm.value.target_time_minutes;
    if (h > 0) return `${h} Std. ${m} Min.`;
    return `${m} Min.`;
});

const selectedRace = computed(() =>
    raceOptions.find(r => r.value === goalForm.value.race_distance)
);

// ── Strength & Core (Step 5) ─────────────────────────────────────────────
const equipmentOptions = [
    { value: 'kettlebell', label: 'Kettlebell',    icon: '🏋️' },
    { value: 'dumbbells',  label: 'Kurzhanteln',   icon: '💪' },
    { value: 'gym',        label: 'Gym',           icon: '🏟️' },
    { value: 'bodyweight', label: 'Körpergewicht', icon: '🤸' },
    { value: 'band',       label: 'Band',          icon: '➰' },
];
const strengthForm = ref({
    strength_enabled:       false,
    strength_days_per_week: 2,
    strength_equipment:     [],
    strength_experience:    'intermediate',
});
const strengthLoading = ref(false);
function toggleEquipment(value) {
    const arr = strengthForm.value.strength_equipment;
    const i = arr.indexOf(value);
    if (i === -1) arr.push(value); else arr.splice(i, 1);
}
async function submitStrength() {
    strengthLoading.value = true;
    try {
        await axios.post(route('onboarding.strength'), { ...strengthForm.value });
    } catch (e) {
        // non-blocking — strength is optional
    } finally {
        strengthLoading.value = false;
        nextStep();
    }
}

async function submitGoal() {
    goalErrors.value  = {};
    goalLoading.value = true;
    try {
        await axios.post(route('onboarding.goal'), {
            ...goalForm.value,
            race_date: goalForm.value.race_date,
        });
        nextStep();
    } catch (err) {
        if (err.response?.status === 422) goalErrors.value = err.response.data.errors;
    } finally {
        goalLoading.value = false;
    }
}

// ════════════════════════════════════════════════════════════════════════
// Letzter Schritt — Verbindungen
// ════════════════════════════════════════════════════════════════════════
// Garmin und Strava gleichrangig, auch beide (Entscheidung von Jan,
// 28.10.2026). Beide Wege führen zum Anbieter und zurück ins Onboarding;
// abgeschlossen wird es erst mit „Fertig".
const stravaLinked = computed(() => !!props.connections?.strava);
const garminLinked = computed(() => !!props.connections?.garmin);
const anyLinked    = computed(() => stravaLinked.value || garminLinked.value);
const garminGranted = computed(() => {
    const c = props.connections?.garmin;
    if (!c) return '';
    return [c.activities && 'Aktivitäten', c.health && 'Erholungswerte', c.workouts && 'Workouts'].filter(Boolean).join(', ');
});

function connectStrava() { window.location.href = route('strava.connect'); }

const garminConsent = ref(false);
const garminBusy    = ref(false);
function connectGarmin() {
    if (!garminConsent.value) return;
    garminBusy.value = true;
    router.post(route('garmin.connect'), { ai_consent: true }, {
        onFinish: () => { garminBusy.value = false; },
    });
}

// Aus der Homescreen-App öffnet iOS den Rückweg von Garmin in Safari. Wer
// danach in die App zurückwechselt, sieht diese Seite im alten Stand —
// beim Zurückkommen deshalb die Verbindungen neu laden.
function refreshConnections() {
    if (!document.hidden && stepKey.value === 'connect') router.reload({ only: ['connections'] });
}
onMounted(() => document.addEventListener('visibilitychange', refreshConnections));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', refreshConnections));

// Eine Quelle pro Athlet; wählbar, sobald beide liefern können.
const sourceOptions    = [{ value: 'strava', label: 'Strava' }, { value: 'garmin', label: 'Garmin' }];
const sourceChoosable  = computed(() => (props.connections?.source?.usable ?? []).length > 1);
const sourceValue      = ref(props.connections?.source?.current ?? 'strava');
watch(() => props.connections?.source?.current, (current) => { if (current) sourceValue.value = current; });
watch(sourceValue, (source, previous) => {
    if (source === props.connections?.source?.current) return;
    router.patch(route('profile.activity-source'), { source }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => { sourceValue.value = previous; },
    });
});

function complete() { router.post(route('onboarding.complete')); }

function back() {
    if (stepKey.value === 'profile' && profileMode.value) {
        profileMode.value = null;
        estimateResult.value = null;
        return;
    }
    if (currentStep.value > 1) currentStep.value--;
}

/** Der Knopf unten: je Schritt eine Hauptaktion, wo sinnvoll „Überspringen“ darunter. */
const bar = computed(() => {
    switch (stepKey.value) {
        case 'welcome':
            return { label: 'Los geht’s', run: nextStep };
        case 'coach': {
            const name = props.coaches?.find(c => c.id === selectedCoachId.value)?.name;
            return { label: name ? `Mit ${name} trainieren` : 'Coach wählen', run: submitCoach,
                disabled: !selectedCoachId.value, loading: coachLoading.value, skip: nextStep };
        }
        case 'profile':
            if (!profileMode.value) return { skip: nextStep, skipLabel: 'Später eintragen' };
            if (profileMode.value === 'know') return { label: 'Weiter', run: submitKnown, loading: knownLoading.value };
            return estimateResult.value
                ? { label: 'Übernehmen', run: confirmEstimate, loading: knownLoading.value }
                : { label: 'Zonen schätzen', run: runEstimate, loading: estimateLoading.value };
        case 'model':
            return { label: 'Weiter', run: submitModel, loading: modelLoading.value };
        case 'availability':
            return { label: 'Weiter', run: submitAvailability, loading: availabilityLoading.value,
                disabled: !days.some(d => availability.value[d.key].available) };
        case 'strength':
            return { label: 'Weiter', run: submitStrength, loading: strengthLoading.value, skip: nextStep };
        case 'bike':
            return { label: 'Weiter', run: submitBike, loading: bikeLoading.value, skip: nextStep };
        case 'goal':
            return { label: 'Ziel sichern', run: submitGoal, loading: goalLoading.value, skip: nextStep };
        case 'connect':
            return anyLinked.value
                ? { label: 'Fertig', run: complete }
                : { skip: complete, skipLabel: 'Später verbinden' };
        default:
            return {};
    }
});
</script>

<template>
    <Head title="Willkommen" />

    <!--
        Redesign 29.10.2026: Vollbild wie das Plan-Interview — Fortschritt als
        Segmente oben, eine große Frage je Schritt, Eingaben als gruppierte
        Listen, der Knopf unten fest. Vorher Karten mit Rahmen, Emoji-Kacheln
        und neun Kreise als Schrittanzeige.
    -->
    <div class="min-h-screen bg-canvas text-ink">
        <div class="mx-auto flex max-w-xl items-center gap-3 px-4 pt-[max(env(safe-area-inset-top),16px)]">
            <button type="button" aria-label="Zurück" :disabled="currentStep === 1 && !profileMode"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-2 transition active:opacity-60 disabled:invisible"
                @click="back">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </button>
            <div class="grid flex-1 gap-1" :style="{ gridTemplateColumns: `repeat(${totalSteps}, minmax(0, 1fr))` }">
                <i v-for="s in steps" :key="s.key" class="block h-1 rounded-full transition-colors duration-200"
                    :class="s.number <= currentStep ? 'bg-accent' : 'bg-surface-2'" />
            </div>
            <span class="w-8 shrink-0 text-right text-[13px] tabular-nums text-ink-3">{{ currentStep }}/{{ totalSteps }}</span>
        </div>

        <main class="mx-auto max-w-xl space-y-7 px-4 pb-44 pt-8">

            <!-- ── Willkommen ── -->
            <template v-if="stepKey === 'welcome'">
                <header>
                    <div class="flex h-14 w-14 items-center justify-center rounded-[14px] bg-accent text-[20px] font-bold text-white">Z3</div>
                    <h1 class="mt-6 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Willkommen, {{ user?.name?.split(' ')[0] }}</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Ein paar Fragen, dann baut dein Coach den ersten Plan. Alles lässt sich später im Profil ändern.</p>
                </header>
                <FormGroup>
                    <div v-for="(row, i) in [
                            ['Coach und Trainingsmodell', 'Wer dich begleitet und wie du trainierst'],
                            ['Deine Werte', 'Puls und Schwellenpace, bekannt oder geschätzt'],
                            ['Zeit und Ziel', 'Wann du trainieren kannst und worauf'],
                            ['Garmin oder Strava', 'Damit Zone3 deine Läufe sieht'],
                        ]" :key="i" class="flex items-center gap-3 px-4 py-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[13px] font-bold text-accent-ink">{{ i + 1 }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[17px]">{{ row[0] }}</span>
                            <span class="block text-[13px] text-ink-3">{{ row[1] }}</span>
                        </span>
                    </div>
                </FormGroup>
            </template>

            <!-- ── Coach ── -->
            <template v-else-if="stepKey === 'coach'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Wähle deinen Coach</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Er begleitet dich durch jedes Training. Wechseln geht jederzeit.</p>
                </header>
                <FormGroup :footer="coaches.find(c => c.id === selectedCoachId)?.description">
                    <button v-for="coach in coaches" :key="coach.id" type="button" role="radio" :aria-checked="selectedCoachId === coach.id"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                        @click="selectedCoachId = coach.id">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[15px] font-bold text-white"
                            :class="coachColors[coach.avatar_color]?.bg ?? 'bg-accent'">{{ coach.avatar_initials }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[17px]">{{ coach.name }} <span class="text-ink-3">· {{ specialtyLabels[coach.specialty] ?? coach.specialty }}</span></span>
                            <span class="block text-[13px] leading-snug text-ink-3">„{{ coach.tagline }}“</span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-accent" :class="selectedCoachId === coach.id ? '' : 'invisible'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </button>
                </FormGroup>
                <p v-if="coachError" class="z-error mx-4">{{ coachError }}</p>
            </template>

            <!-- ── Werte ── -->
            <template v-else-if="stepKey === 'profile'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Deine Werte</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Aus Puls und Schwellenpace rechnet Zone3 deine Trainingszonen.</p>
                </header>

                <FormGroup v-if="!profileMode">
                    <button v-for="opt in [
                            { mode: 'know', title: 'Ich kenne meine Werte', text: 'Schwellenpuls, Maximalpuls und Schwellenpace eintragen' },
                            { mode: 'estimate', title: 'Aus einer Bestzeit schätzen', text: 'Eine Wettkampf- oder schnelle Trainingszeit genügt' },
                        ]" :key="opt.mode" type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                        @click="profileMode = opt.mode">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[17px]">{{ opt.title }}</span>
                            <span class="block text-[13px] leading-snug text-ink-3">{{ opt.text }}</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>
                    <template #footer>Ohne Angaben schätzt Zone3 die Werte später aus deinen Läufen.</template>
                </FormGroup>

                <template v-else-if="profileMode === 'know'">
                    <FormGroup footer="Schwelle heißt: das, was du etwa 60 Minuten halten kannst.">
                        <FormRow as="label" label="Schwellenpuls">
                            <input v-model="knownForm.threshold_heart_rate" type="number" inputmode="numeric" min="100" max="220" placeholder="168" class="z-inline w-16" />
                            <span class="text-[17px] text-ink-3">bpm</span>
                        </FormRow>
                        <FormRow as="label" label="Maximalpuls">
                            <input v-model="knownForm.max_heart_rate" type="number" inputmode="numeric" min="100" max="220" placeholder="192" class="z-inline w-16" />
                            <span class="text-[17px] text-ink-3">bpm</span>
                        </FormRow>
                        <FormRow as="label" label="Schwellenpace">
                            <input v-model="knownForm.threshold_speed" type="text" inputmode="numeric" placeholder="5:30" class="z-inline w-16" />
                            <span class="text-[17px] text-ink-3">/km</span>
                        </FormRow>
                    </FormGroup>
                    <p v-for="k in ['threshold_heart_rate', 'max_heart_rate', 'threshold_speed']" v-show="knownErrors[k]" :key="k" class="z-error mx-4">{{ knownErrors[k]?.[0] }}</p>
                </template>

                <template v-else-if="profileMode === 'estimate' && !estimateResult">
                    <FormGroup title="Beste Distanz" plain>
                        <SegmentedControl v-model="estimateForm.race_distance" :options="raceDistances.map(d => ({ value: d.value, label: d.value === 'half_marathon' ? 'HM' : d.label }))" />
                    </FormGroup>
                    <FormGroup footer="Kein Wettkampf? Dann deine schnellste Trainingszeit über die Strecke.">
                        <FormRow as="label" label="Zeit">
                            <input v-model="estimateForm.race_time" type="text" inputmode="numeric" :placeholder="raceTimePlaceholder.replace('z.B. ', '')" class="z-inline w-28" />
                        </FormRow>
                        <FormRow as="label" label="Alter">
                            <input v-model="estimateForm.age" type="number" inputmode="numeric" min="14" max="90" placeholder="32" class="z-inline w-16" />
                        </FormRow>
                        <FormRow label="Läufe pro Woche" stacked>
                            <div class="flex items-center gap-3">
                                <input v-model.number="estimateForm.weekly_runs" type="range" min="1" max="14" step="1" class="z-range flex-1" />
                                <span class="w-10 text-right text-[17px] tabular-nums text-ink-3">{{ estimateForm.weekly_runs }}×</span>
                            </div>
                        </FormRow>
                    </FormGroup>
                    <p v-for="k in ['age', 'race_time', 'general']" v-show="estimateErrors[k]" :key="k" class="z-error mx-4">{{ estimateErrors[k]?.[0] }}</p>
                </template>

                <template v-else-if="profileMode === 'estimate'">
                    <div class="grid grid-cols-3 gap-3">
                        <div v-for="v in [
                                ['Schwellenpuls', estimateResult.threshold_heart_rate, 'bpm'],
                                ['Maximalpuls', estimateResult.max_heart_rate, 'bpm'],
                                ['Schwelle', estimateResult.threshold_speed, '/km'],
                            ]" :key="v[0]" class="rounded-card bg-surface px-3 py-3">
                            <p class="text-[13px] text-ink-3">{{ v[0] }}</p>
                            <p class="font-rounded text-[24px] font-bold">{{ v[1] }}<span class="ml-0.5 text-[13px] font-semibold text-ink-3">{{ v[2] }}</span></p>
                        </div>
                    </div>
                    <p class="mx-4 text-[13px] text-ink-3">Geschätzt. Du kannst die Werte jederzeit im Profil ändern.</p>
                    <AppButton variant="plain" class="mx-4" @click="estimateResult = null">Neu berechnen</AppButton>
                </template>
            </template>

            <!-- ── Trainingsmodell ── -->
            <template v-else-if="stepKey === 'model'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Wie willst du trainieren?</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Wechseln geht später im Profil.</p>
                </header>
                <TrainingModelFields v-model="modelForm" :readiness="modelReadiness" />
                <p v-if="modelError" class="z-error mx-4">{{ modelError }}</p>
            </template>

            <!-- ── Zeit ── -->
            <template v-else-if="stepKey === 'availability'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Wann kannst du trainieren?</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Der Plan hält sich an diese Tage und Zeiten.</p>
                </header>
                <FormGroup :footer="days.some(d => availability[d.key].available)
                        ? `${days.filter(d => availability[d.key].available).length} Tage · ${days.filter(d => availability[d.key].available).reduce((s, d) => s + availability[d.key].duration_min, 0)} min pro Woche`
                            + (modelForm.training_model === 'norwegian' ? '. Für die Doppel-Schwelle braucht es mindestens zwei Tage mit Abendfenster.' : '')
                        : 'Wähle mindestens einen Tag.'">
                    <div v-for="day in days" :key="day.key" class="px-4 py-2">
                        <div class="flex min-h-[32px] items-center gap-3">
                            <span class="flex-1 text-[17px]" :class="availability[day.key].available ? 'text-ink' : 'text-ink-3'">{{ day.full }}</span>
                            <Toggle :model-value="availability[day.key].available" :label="`${day.full} verfügbar`" @update:model-value="toggleDay(day.key)" />
                        </div>
                        <div v-if="availability[day.key].available" class="mt-2 space-y-2 pb-1">
                            <SegmentedControl v-model="availability[day.key].duration_min"
                                :options="durationOptions.map(o => ({ value: o.value, label: o.value < 60 ? `${o.value}′` : `${(o.value / 60).toLocaleString('de-DE')} h` }))" />
                            <template v-if="modelForm.training_model === 'norwegian'">
                                <button type="button" @click="toggleSecond(day.key)"
                                    class="h-8 rounded-full px-3 text-[13px] font-semibold transition active:scale-[0.97]"
                                    :class="availability[day.key].second_min ? 'bg-accent-soft text-accent-ink' : 'bg-surface-2 text-ink-2'">
                                    {{ availability[day.key].second_min ? 'Abends ✓' : '+ Abends' }}
                                </button>
                                <SegmentedControl v-if="availability[day.key].second_min" v-model="availability[day.key].second_min"
                                    :options="secondOptions.map(m => ({ value: m, label: `abends ${m}′` }))" />
                            </template>
                        </div>
                    </div>
                </FormGroup>
            </template>

            <!-- ── Kraft ── -->
            <template v-else-if="stepKey === 'strength'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Kraft und Rumpf</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Beugt Verletzungen vor und macht dich schneller. Soll dein Coach Kraft-Einheiten einplanen?</p>
                </header>
                <FormGroup>
                    <FormRow label="Kraft einplanen" hint="Ergänzend zum Laufen">
                        <Toggle v-model="strengthForm.strength_enabled" label="Kraft einplanen" />
                    </FormRow>
                    <template v-if="strengthForm.strength_enabled">
                        <FormRow as="label" label="Pro Woche">
                            <select v-model.number="strengthForm.strength_days_per_week" class="z-inline appearance-none text-ink-3">
                                <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                            </select>
                        </FormRow>
                        <FormRow as="label" label="Erfahrung">
                            <select v-model="strengthForm.strength_experience" class="z-inline appearance-none text-ink-3">
                                <option value="beginner">Anfänger</option>
                                <option value="intermediate">Mittel</option>
                                <option value="advanced">Fortgeschritten</option>
                            </select>
                        </FormRow>
                        <FormRow label="Ausrüstung" hint="Keine Auswahl heißt Körpergewicht." stacked>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="opt in equipmentOptions" :key="opt.value" type="button" @click="toggleEquipment(opt.value)"
                                    class="h-8 rounded-full px-3 text-[15px] font-semibold transition active:scale-[0.97]"
                                    :class="strengthForm.strength_equipment.includes(opt.value) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-2'">
                                    {{ opt.label }}
                                </button>
                            </div>
                        </FormRow>
                    </template>
                </FormGroup>
            </template>

            <!-- ── Rad ── -->
            <template v-else-if="stepKey === 'bike'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Rad als Abwechslung?</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Gerade im Winter ein guter Reiz. Zwift-Fahrten kommen über Strava ohnehin an.</p>
                </header>
                <BikeFields v-model="bikeForm" />
                <p v-if="bikeError" class="z-error mx-4">{{ bikeError }}</p>
            </template>

            <!-- ── Ziel ── -->
            <template v-else-if="stepKey === 'goal'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Was ist dein Ziel?</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Das Rennen, auf das dein Plan hinarbeitet.</p>
                </header>
                <FormGroup title="Rennen">
                    <button v-for="opt in raceOptions" :key="opt.value" type="button" role="radio" :aria-checked="goalForm.race_distance === opt.value"
                        class="flex min-h-[48px] w-full items-center gap-3 px-4 py-2 text-left transition-colors active:bg-surface-2"
                        @click="selectRace(opt)">
                        <span class="flex-1 text-[17px]">{{ opt.label }}</span>
                        <span v-if="opt.distance" class="text-[15px] text-ink-3">{{ opt.distance.toLocaleString('de-DE', { maximumFractionDigits: 1 }) }} km</span>
                        <svg class="h-5 w-5 shrink-0 text-accent" :class="goalForm.race_distance === opt.value ? '' : 'invisible'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </button>
                </FormGroup>
                <FormGroup :footer="isBackyardGoal ? '1 Yard = 1 Stunde = eine Runde von 6,706 km, jede startet zur vollen Stunde.' : null">
                    <FormRow v-if="goalForm.race_distance === 'custom'" as="label" label="Distanz">
                        <input v-model="goalForm.distance_km" type="number" inputmode="decimal" min="0.1" step="0.1" placeholder="15" class="z-inline w-20" />
                        <span class="text-[17px] text-ink-3">km</span>
                    </FormRow>
                    <FormRow as="label" label="Name">
                        <input v-model="goalForm.name" type="text" placeholder="optional" class="z-inline w-3/5" />
                    </FormRow>
                    <FormRow as="label" label="Datum">
                        <input v-model="goalForm.race_date" type="date" class="z-inline w-40" />
                    </FormRow>
                    <FormRow v-if="isBackyardGoal" as="label" label="Ziel">
                        <input v-model="goalForm.target_yards" type="number" inputmode="numeric" min="1" max="100" placeholder="24" class="z-inline w-14" />
                        <span class="text-[17px] text-ink-3">Std<template v-if="backyardGoalKm"> · {{ backyardGoalKm }} km</template></span>
                    </FormRow>
                    <FormRow v-else label="Zielzeit">
                        <select v-model.number="goalForm.target_time_hours" class="z-inline appearance-none" aria-label="Stunden">
                            <option v-for="h in 24" :key="h - 1" :value="h - 1">{{ h - 1 }} h</option>
                        </select>
                        <select v-model.number="goalForm.target_time_minutes" class="z-inline appearance-none" aria-label="Minuten">
                            <option v-for="m in 60" :key="m - 1" :value="m - 1">{{ String(m - 1).padStart(2, '0') }} min</option>
                        </select>
                    </FormRow>
                </FormGroup>
                <p v-for="k in ['name', 'race_date', 'target_yards', 'general']" v-show="goalErrors[k]" :key="k" class="z-error mx-4">{{ goalErrors[k]?.[0] }}</p>
            </template>

            <!-- ── Verbindungen ── -->
            <template v-else-if="stepKey === 'connect'">
                <header>
                    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Woher kommen deine Läufe?</h1>
                    <p class="mt-2 text-[17px] leading-snug text-ink-3">Garmin, Strava oder beides. Zone3 lernt aus deinen echten Läufen und gleicht sie mit dem Plan ab.</p>
                </header>
                <FormGroup>
                    <template v-if="connections.garminAvailable">
                        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas">
                                <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="7" y="6" width="10" height="12" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l.5-2.5h5L15 6M9 18l.5 2.5h5L15 18" /></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[17px]">Garmin Connect</span>
                                <span class="block text-[13px] leading-snug" :class="garminLinked && !garminGranted ? 'text-warn-ink' : 'text-ink-3'">
                                    <template v-if="garminLinked && garminGranted">Verbunden · {{ garminGranted }}</template>
                                    <template v-else-if="garminLinked">Verbunden, aber keine Daten freigegeben</template>
                                    <template v-else>Läufe, Schlaf, HRV und Ruhepuls von der Uhr</template>
                                </span>
                            </span>
                            <svg v-if="garminLinked" class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </div>
                        <div v-if="!garminLinked" class="space-y-3 px-4 py-3">
                            <label class="flex items-start gap-3 text-[15px] leading-snug text-ink-2">
                                <input v-model="garminConsent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40" />
                                <span>
                                    Ich willige ein, dass Zone3 meine Garmin-Daten für Plan, Auswertungen und Coach an OpenAI übermittelt.
                                    Die Daten werden nicht zum Training von KI-Modellen verwendet. Widerruf jederzeit durch Trennen.
                                    <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                                </span>
                            </label>
                            <AppButton variant="secondary" block :disabled="!garminConsent" :loading="garminBusy" @click="connectGarmin">Mit Garmin verbinden</AppButton>
                            <p class="text-[13px] leading-snug text-ink-3">Die Anmeldung läuft direkt bei Garmin. Aktiviere dort alle Freigaben. Landest du danach in Safari, wechsle einfach zurück in die App.</p>
                        </div>
                        <p v-if="connections.garminStatus?.type === 'error'" class="px-4 py-3 text-[13px] text-danger">{{ connections.garminStatus.message }}</p>
                    </template>

                    <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                        <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-warn text-white">
                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" /></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[17px]">Strava</span>
                            <span class="block text-[13px] leading-snug text-ink-3">
                                <template v-if="stravaLinked">Verbunden<template v-if="connections.strava.username"> · {{ connections.strava.username }}</template></template>
                                <template v-else>Läufe von jeder Uhr und App, auch die bisherigen</template>
                            </span>
                        </span>
                        <svg v-if="stravaLinked" class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <button v-else type="button" class="shrink-0 text-[17px] text-accent active:opacity-60" @click="connectStrava">Verbinden</button>
                    </div>
                    <template v-if="garminLinked && !stravaLinked" #footer>
                        Garmin schickt nur neue Läufe. Verbinde zusätzlich Strava, damit Zone3 auch deine bisherigen kennt — daraus kommen Schwellenpace, Wochenumfang und Bestzeiten vom ersten Tag an.
                    </template>
                </FormGroup>

                <FormGroup v-if="sourceChoosable" title="Neue Aktivitäten kommen von" plain
                    footer="Eine Quelle, damit kein Lauf doppelt zählt. Änderbar im Profil.">
                    <SegmentedControl v-model="sourceValue" :options="sourceOptions" />
                </FormGroup>
            </template>
        </main>

        <!-- Knopf unten fest -->
        <div class="fixed inset-x-0 bottom-0 z-10 bg-canvas/90 px-4 pb-[max(env(safe-area-inset-bottom),16px)] pt-3 backdrop-blur-xl">
            <div class="mx-auto max-w-xl space-y-1">
                <AppButton v-if="bar.label" size="lg" block :disabled="bar.disabled" :loading="bar.loading" @click="bar.run">{{ bar.label }}</AppButton>
                <button v-if="bar.skip" type="button" class="flex h-11 w-full items-center justify-center text-[17px] text-accent active:opacity-60" @click="bar.skip">
                    {{ bar.skipLabel ?? 'Überspringen' }}
                </button>
            </div>
        </div>
    </div>
</template>
