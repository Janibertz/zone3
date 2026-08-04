<script setup>
import { ref, computed, watch } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({ stravaConnectUrl: String, coaches: Array });

const page = usePage();
const user = computed(() => page.props.auth.user);

// ── Steps ─────────────────────────────────────────────────────────────────
const currentStep = ref(1);
const totalSteps  = 7;
const steps = [
    { number: 1, label: 'Willkommen'    },
    { number: 2, label: 'Dein Coach'    },
    { number: 3, label: 'Dein Profil'   },
    { number: 4, label: 'Verfügbarkeit' },
    { number: 5, label: 'Kraft & Core'  },
    { number: 6, label: 'Dein Ziel'     },
    { number: 7, label: 'Strava'        },
];
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
    if (!availability.value[key].available) availability.value[key].duration_min = 0;
    else if (availability.value[key].duration_min === 0) availability.value[key].duration_min = 60;
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
// STEP 5 — Complete / Strava
// ════════════════════════════════════════════════════════════════════════
function complete() { router.post(route('onboarding.complete')); }
function completeAndConnectStrava() { router.post(route('onboarding.complete-strava')); }
</script>

<template>
    <Head title="Willkommen bei Zone3" />

    <div class="min-h-screen bg-surface-2 flex flex-col">

        <!-- Header -->
        <header class="flex items-center gap-3 px-6 py-4 border-b border-line bg-surface">
            <div class="h-8 w-8 rounded-field bg-gradient-to-br from-accent to-accent flex items-center justify-center shadow-card">
                <span class="text-white text-sm font-bold">Z3</span>
            </div>
            <span class="text-lg font-bold text-ink tracking-tight">Zone3</span>
        </header>

        <!-- Progress bar -->
        <div class="w-full h-1 bg-surface-3">
            <div class="h-1 bg-accent transition-all duration-500"
                :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"></div>
        </div>

        <!-- Step indicators -->
        <div class="flex justify-center gap-2 pt-6 px-4">
            <div v-for="step in steps" :key="step.number" class="flex items-center gap-1.5">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300"
                    :class="currentStep > step.number
                        ? 'bg-accent text-white'
                        : currentStep === step.number
                            ? 'bg-accent text-white ring-4 ring-accent/20'
                            : 'bg-surface-3 text-ink-3'">
                    <svg v-if="currentStep > step.number" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span v-else>{{ step.number }}</span>
                </div>
                <span class="text-xs hidden sm:block transition-colors"
                    :class="currentStep === step.number ? 'text-accent-ink font-medium' : 'text-ink-3'">
                    {{ step.label }}
                </span>
                <div v-if="step.number < totalSteps" class="w-8 sm:w-12 h-px bg-surface-3 mx-1"></div>
            </div>
        </div>

        <!-- Main content -->
        <main class="flex-1 flex items-start justify-center p-6 pt-8">
            <div class="w-full max-w-lg">

                <!-- ══════════════════════════════════════════
                     STEP 1 — Welcome
                     ══════════════════════════════════════════ -->
                <div v-if="currentStep === 1" class="text-center space-y-6">
                    <div class="w-20 h-20 mx-auto rounded-3xl bg-gradient-to-br from-accent to-accent flex items-center justify-center shadow-lg">
                        <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-ink">
                            Willkommen, {{ user?.name?.split(' ')[0] }}!
                        </h1>
                        <p class="mt-3 text-ink-3 text-lg leading-relaxed">
                            In 3 kurzen Schritten richtest du Zone3 auf dich ein.
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center py-2">
                        <div class="p-4 rounded-field bg-surface/50 border border-line">
                            <div class="text-2xl mb-1">🏃</div>
                            <div class="text-xs font-medium text-ink-2">Dein Profil</div>
                            <div class="text-[10px] text-ink-3 mt-0.5">Werte bekannt oder berechnen lassen</div>
                        </div>
                        <div class="p-4 rounded-field bg-surface/50 border border-line">
                            <div class="text-2xl mb-1">🎯</div>
                            <div class="text-xs font-medium text-ink-2">Dein Ziel</div>
                            <div class="text-[10px] text-ink-3 mt-0.5">Rennen + Zielzeit</div>
                        </div>
                        <div class="p-4 rounded-field bg-surface/50 border border-line">
                            <div class="text-2xl mb-1">🔗</div>
                            <div class="text-xs font-medium text-ink-2">Strava</div>
                            <div class="text-[10px] text-ink-3 mt-0.5">Optional verbinden</div>
                        </div>
                    </div>
                    <button @click="nextStep"
                        class="w-full py-3 px-6 bg-accent hover:opacity-90 text-white font-semibold rounded-field transition-colors shadow-card">
                        Lass uns starten
                    </button>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 2 — Coach selection
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 2" class="space-y-5">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-ink">Wähle deinen Coach</h2>
                        <p class="mt-2 text-ink-3">
                            Dein Coach begleitet dich durch jedes Training. Du kannst ihn später wechseln.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <button
                            v-for="coach in coaches" :key="coach.id"
                            type="button"
                            @click="selectedCoachId = coach.id"
                            class="w-full flex items-start gap-4 p-5 rounded-card border-2 text-left transition-all duration-200"
                            :class="selectedCoachId === coach.id
                                ? [coachColors[coach.avatar_color]?.light, coachColors[coach.avatar_color]?.border, 'shadow-card']
                                : 'bg-surface/50 border-line hover:border-line-strong'"
                        >
                            <!-- Avatar -->
                            <div class="shrink-0 w-14 h-14 rounded-card flex items-center justify-center text-white font-bold text-lg shadow-card"
                                :class="[coachColors[coach.avatar_color]?.bg, selectedCoachId === coach.id ? 'ring-2 ring-offset-2 ' + coachColors[coach.avatar_color]?.ring : '']">
                                {{ coach.avatar_initials }}
                            </div>

                            <!-- Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-bold text-ink">{{ coach.name }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="coachColors[coach.avatar_color]?.badge">
                                        {{ specialtyLabels[coach.specialty] }}
                                    </span>
                                </div>
                                <p class="text-xs font-medium text-ink-3 italic mb-1.5">„{{ coach.tagline }}"</p>
                                <p class="text-sm text-ink-2 leading-relaxed">{{ coach.description }}</p>
                            </div>

                            <!-- Check -->
                            <div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all mt-0.5"
                                :class="selectedCoachId === coach.id
                                    ? [coachColors[coach.avatar_color]?.bg, 'border-transparent']
                                    : 'border-line-strong'">
                                <svg v-if="selectedCoachId === coach.id" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                        </button>
                    </div>

                    <p v-if="coachError" class="text-xs text-danger text-center">{{ coachError }}</p>

                    <button
                        @click="submitCoach"
                        :disabled="!selectedCoachId || coachLoading"
                        class="w-full py-3 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors shadow-card">
                        {{ coachLoading ? 'Speichern…' : selectedCoachId ? 'Mit ' + (coaches.find(c => c.id === selectedCoachId)?.name) + ' trainieren' : 'Coach auswählen' }}
                    </button>

                    <button @click="nextStep" class="w-full py-2 text-sm text-ink-3 hover:text-ink-2 transition-colors">
                        Jetzt überspringen
                    </button>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 3 — Athlete profile
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 3" class="space-y-5">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-ink">Dein Athletenprofil</h2>
                        <p class="mt-2 text-ink-3">
                            Zone3 nutzt deine Trainingszonen für persönliche Empfehlungen.
                        </p>
                    </div>

                    <!-- Mode selection -->
                    <div v-if="!profileMode" class="space-y-3">
                        <button @click="profileMode = 'know'"
                            class="w-full flex items-start gap-4 p-5 rounded-card border-2 border-line hover:border-accent bg-surface/50 text-left transition-colors group">
                            <div class="h-10 w-10 rounded-field bg-accent-soft flex items-center justify-center shrink-0 group-hover:opacity-90 transition-colors">
                                <svg class="h-5 w-5 text-accent-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-ink">Ich kenne meine Werte</p>
                                <p class="text-sm text-ink-3 mt-0.5">Ich trage Herzfrequenz-Schwelle und Schwellentempo direkt ein.</p>
                            </div>
                        </button>

                        <button @click="profileMode = 'estimate'"
                            class="w-full flex items-start gap-4 p-5 rounded-card border-2 border-line hover:border-accent bg-surface/50 text-left transition-colors group">
                            <div class="h-10 w-10 rounded-field bg-accent-soft flex items-center justify-center shrink-0 group-hover:opacity-90 transition-colors">
                                <svg class="h-5 w-5 text-accent-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-ink">Werte berechnen lassen</p>
                                <p class="text-sm text-ink-3 mt-0.5">Ich gebe meine beste Laufzeit an und Zone3 schätzt meine Zonen.</p>
                            </div>
                        </button>

                        <button @click="nextStep"
                            class="w-full py-2.5 text-sm text-ink-3 hover:text-ink-2 transition-colors">
                            Jetzt überspringen — später in den Einstellungen eintragen
                        </button>
                    </div>

                    <!-- Mode: know my values -->
                    <div v-else-if="profileMode === 'know'" class="space-y-5">
                        <button @click="profileMode = null" class="flex items-center gap-1.5 text-sm text-ink-3 hover:text-ink transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Zurück
                        </button>

                        <div class="bg-surface/50 border border-line rounded-card p-6 space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">
                                    Schwellenherzfrequenz (LTHR) <span class="text-ink-3 font-normal">bpm</span>
                                </label>
                                <input v-model="knownForm.threshold_heart_rate" type="number" min="100" max="220" placeholder="z.B. 168"
                                    class="input-field" :class="knownErrors.threshold_heart_rate ? 'border-danger' : 'border-line'" />
                                <p v-if="knownErrors.threshold_heart_rate" class="mt-1 text-xs text-danger">{{ knownErrors.threshold_heart_rate[0] }}</p>
                                <p class="mt-1 text-xs text-ink-3">Herzfrequenz, die du ~60 Minuten maximal halten kannst (Laktatschwelle)</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">
                                    Maximale Herzfrequenz <span class="text-ink-3 font-normal">bpm</span>
                                </label>
                                <input v-model="knownForm.max_heart_rate" type="number" min="100" max="220" placeholder="z.B. 192"
                                    class="input-field" :class="knownErrors.max_heart_rate ? 'border-danger' : 'border-line'" />
                                <p v-if="knownErrors.max_heart_rate" class="mt-1 text-xs text-danger">{{ knownErrors.max_heart_rate[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">
                                    Schwellentempo <span class="text-ink-3 font-normal">min/km (MM:SS)</span>
                                </label>
                                <input v-model="knownForm.threshold_speed" type="text" placeholder="z.B. 5:30"
                                    class="input-field" :class="knownErrors.threshold_speed ? 'border-danger' : 'border-line'" />
                                <p v-if="knownErrors.threshold_speed" class="mt-1 text-xs text-danger">{{ knownErrors.threshold_speed[0] }}</p>
                                <p class="mt-1 text-xs text-ink-3">Tempo, das du ~60 Minuten maximal halten kannst</p>
                            </div>
                        </div>

                        <button @click="submitKnown" :disabled="knownLoading"
                            class="w-full py-3 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors">
                            {{ knownLoading ? 'Speichern…' : 'Speichern & weiter' }}
                        </button>
                    </div>

                    <!-- Mode: AI estimate -->
                    <div v-else-if="profileMode === 'estimate'" class="space-y-5">
                        <button @click="profileMode = null" class="flex items-center gap-1.5 text-sm text-ink-3 hover:text-ink transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Zurück
                        </button>

                        <!-- Input form (hidden once result is shown) -->
                        <div v-if="!estimateResult" class="bg-surface/50 border border-line rounded-card p-6 space-y-5">
                            <div class="flex items-start gap-3 p-3 rounded-field bg-accent-soft border border-accent/25">
                                <svg class="h-4 w-4 text-accent mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                                </svg>
                                <p class="text-xs text-accent-ink">Zone3 berechnet deine Trainingszonen basierend auf deiner besten Wettkampfzeit. Du kannst die Werte danach noch anpassen.</p>
                            </div>

                            <!-- Age -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Alter</label>
                                <input v-model="estimateForm.age" type="number" min="14" max="90" placeholder="z.B. 32"
                                    class="input-field" :class="estimateErrors.age ? 'border-danger' : 'border-line'" />
                                <p v-if="estimateErrors.age" class="mt-1 text-xs text-danger">{{ estimateErrors.age[0] }}</p>
                            </div>

                            <!-- Race distance -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-2">Beste Wettkampfdistanz</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button v-for="d in raceDistances" :key="d.value"
                                        type="button" @click="estimateForm.race_distance = d.value"
                                        class="py-2.5 px-3 text-sm rounded-field border transition-colors text-center"
                                        :class="estimateForm.race_distance === d.value
                                            ? 'bg-accent text-white border-accent'
                                            : 'border-line text-ink-2 hover:border-accent'">
                                        {{ d.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Race time -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Beste Zeit über diese Distanz</label>
                                <input v-model="estimateForm.race_time" type="text" :placeholder="raceTimePlaceholder"
                                    class="input-field" :class="estimateErrors.race_time ? 'border-danger' : 'border-line'" />
                                <p v-if="estimateErrors.race_time" class="mt-1 text-xs text-danger">{{ estimateErrors.race_time[0] }}</p>
                                <p class="mt-1 text-xs text-ink-3">Format: H:MM:SS oder MM:SS — kein Wettkampf? Einfach deine schnellste Trainingszeit</p>
                            </div>

                            <!-- Weekly runs -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-2">
                                    Läufe pro Woche: <span class="text-accent-ink font-bold">{{ estimateForm.weekly_runs }}×</span>
                                </label>
                                <input v-model.number="estimateForm.weekly_runs" type="range" min="1" max="14" step="1"
                                    class="w-full accent-indigo-600" />
                                <div class="flex justify-between text-xs text-ink-3 mt-1">
                                    <span>1× (Einsteiger)</span>
                                    <span>7× (Fortgeschritten)</span>
                                    <span>14× (Profi)</span>
                                </div>
                            </div>

                            <p v-if="estimateErrors.general" class="text-xs text-danger">{{ estimateErrors.general[0] }}</p>

                            <button @click="runEstimate" :disabled="estimateLoading"
                                class="w-full py-3 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors flex items-center justify-center gap-2">
                                <svg v-if="estimateLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ estimateLoading ? 'Berechnung läuft…' : 'Zonen berechnen lassen' }}
                            </button>
                        </div>

                        <!-- Result confirmation -->
                        <div v-if="estimateResult" class="space-y-4">
                            <div class="bg-surface/50 border border-line rounded-card p-6 space-y-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-6 w-6 rounded-full bg-success-soft flex items-center justify-center">
                                        <svg class="h-3.5 w-3.5 text-success-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    </div>
                                    <p class="text-sm font-semibold text-ink">Schätzung abgeschlossen</p>
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div class="text-center p-3 rounded-field bg-surface-2">
                                        <p class="text-xs text-ink-3 mb-1">LTHR</p>
                                        <p class="text-xl font-bold text-ink">{{ estimateResult.threshold_heart_rate }}</p>
                                        <p class="text-xs text-ink-3">bpm</p>
                                    </div>
                                    <div class="text-center p-3 rounded-field bg-surface-2">
                                        <p class="text-xs text-ink-3 mb-1">Max HF</p>
                                        <p class="text-xl font-bold text-ink">{{ estimateResult.max_heart_rate }}</p>
                                        <p class="text-xs text-ink-3">bpm</p>
                                    </div>
                                    <div class="text-center p-3 rounded-field bg-surface-2">
                                        <p class="text-xs text-ink-3 mb-1">Schwellentempo</p>
                                        <p class="text-xl font-bold text-ink">{{ estimateResult.threshold_speed }}</p>
                                        <p class="text-xs text-ink-3">min/km</p>
                                    </div>
                                </div>

                                <p class="text-xs text-ink-3 text-center">
                                    Diese Werte sind Schätzungen. Du kannst sie jederzeit im Profil anpassen.
                                </p>
                            </div>

                            <div class="flex gap-3">
                                <button @click="estimateResult = null"
                                    class="flex-1 py-2.5 text-sm border border-line text-ink-2 rounded-field hover:bg-surface-2 transition-colors">
                                    Neu berechnen
                                </button>
                                <button @click="confirmEstimate" :disabled="knownLoading"
                                    class="flex-1 py-2.5 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors">
                                    {{ knownLoading ? 'Speichern…' : 'Übernehmen & weiter' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 4 — Availability
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 4" class="space-y-6">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-ink">Wann kannst du trainieren?</h2>
                        <p class="mt-2 text-ink-3">
                            Wähle deine verfügbaren Tage und wie viel Zeit du hast.<br>
                            Dein Coach passt den Plan automatisch daran an.
                        </p>
                    </div>

                    <!-- Day grid -->
                    <div class="grid grid-cols-7 gap-2">
                        <div v-for="day in days" :key="day.key" class="flex flex-col items-center gap-2">
                            <!-- Day toggle -->
                            <button
                                type="button"
                                @click="toggleDay(day.key)"
                                class="w-full aspect-square rounded-card flex flex-col items-center justify-center text-sm font-bold transition-all duration-150 border-2"
                                :class="availability[day.key].available
                                    ? 'bg-accent border-accent text-white shadow-md scale-105'
                                    : 'bg-surface border-line text-ink-3 hover:border-line-strong'"
                            >
                                {{ day.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Duration per available day -->
                    <div class="space-y-3">
                        <p class="text-sm font-semibold text-ink-2">Trainingszeit pro Tag</p>
                        <div v-for="day in days.filter(d => availability[d.key].available)" :key="day.key + '_dur'" class="flex items-center gap-3">
                            <span class="w-24 text-sm text-ink-2 font-medium">{{ day.full }}</span>
                            <div class="flex flex-wrap gap-1.5 flex-1">
                                <button
                                    v-for="opt in durationOptions"
                                    :key="opt.value"
                                    type="button"
                                    @click="availability[day.key].duration_min = opt.value"
                                    class="px-2.5 py-1 rounded-field text-xs font-semibold border transition-colors"
                                    :class="availability[day.key].duration_min === opt.value
                                        ? 'bg-accent border-accent text-white'
                                        : 'bg-surface border-line text-ink-2 hover:border-accent'"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>
                        </div>
                        <p v-if="!days.some(d => availability[d.key].available)" class="text-sm text-warn-ink">
                            Bitte wähle mindestens einen Tag aus.
                        </p>
                    </div>

                    <!-- Summary -->
                    <div class="rounded-card bg-accent-soft border border-accent/25 px-4 py-3">
                        <p class="text-sm font-semibold text-accent-ink mb-1">Deine Wochenverfügbarkeit</p>
                        <p class="text-sm text-accent-ink">
                            {{ days.filter(d => availability[d.key].available).length }} Trainingstage ·
                            {{ days.filter(d => availability[d.key].available).reduce((s, d) => s + availability[d.key].duration_min, 0) }} Min. pro Woche
                        </p>
                    </div>

                    <button
                        @click="submitAvailability"
                        :disabled="availabilityLoading || !days.some(d => availability[d.key].available)"
                        class="w-full rounded-card bg-accent px-6 py-3.5 text-base font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card"
                    >
                        <svg v-if="availabilityLoading" class="inline h-5 w-5 animate-spin mr-2" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        Weiter
                    </button>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 5 — Race goal
                     ══════════════════════════════════════════ -->
                <!-- ══════════════════════════════════════════
                     STEP 5 — Kraft & Core
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 5" class="space-y-5">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto rounded-card bg-gradient-to-br from-danger to-danger flex items-center justify-center shadow-lg text-3xl">💪</div>
                        <h2 class="mt-4 text-2xl font-bold text-ink">Kraft & Core</h2>
                        <p class="mt-2 text-ink-3">Läufer:innen vernachlässigen oft Kraft & Rumpf — dabei beugt es Verletzungen vor und macht dich schneller. Soll dein Coach Kraft- & Core-Einheiten in den Plan einbauen?</p>
                    </div>

                    <!-- Toggle -->
                    <div class="bg-surface/50 border border-line rounded-card p-5 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-ink-2">Kraft & Core einplanen</p>
                            <p class="text-xs text-ink-3 mt-0.5">Ergänzend zum Lauftraining</p>
                        </div>
                        <button type="button" @click="strengthForm.strength_enabled = !strengthForm.strength_enabled"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0"
                            :class="strengthForm.strength_enabled ? 'bg-danger' : 'bg-surface-3'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-surface transition-transform"
                                :class="strengthForm.strength_enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>

                    <template v-if="strengthForm.strength_enabled">
                        <!-- Equipment -->
                        <div class="bg-surface/50 border border-line rounded-card p-5 space-y-3">
                            <label class="block text-sm font-semibold text-ink-2">Welches Equipment hast du?</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button v-for="opt in equipmentOptions" :key="opt.value" type="button" @click="toggleEquipment(opt.value)"
                                    class="py-3 px-2 rounded-field border-2 transition-all text-center"
                                    :class="strengthForm.strength_equipment.includes(opt.value)
                                        ? 'bg-accent-soft border-danger text-danger-ink'
                                        : 'border-line text-ink-2 hover:border-danger'">
                                    <div class="text-xl mb-1">{{ opt.icon }}</div>
                                    <div class="text-xs font-semibold">{{ opt.label }}</div>
                                </button>
                            </div>
                            <p class="text-[11px] text-ink-3">Mehrfachauswahl möglich. Keine Auswahl = nur Körpergewicht.</p>
                        </div>

                        <!-- Frequency + experience -->
                        <div class="bg-surface/50 border border-line rounded-card p-5 grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-ink-3 mb-1.5">Einheiten / Woche</label>
                                <select v-model.number="strengthForm.strength_days_per_week" class="input-field border-line">
                                    <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-ink-3 mb-1.5">Erfahrung</label>
                                <select v-model="strengthForm.strength_experience" class="input-field border-line">
                                    <option value="beginner">Anfänger</option>
                                    <option value="intermediate">Mittel</option>
                                    <option value="advanced">Fortgeschritten</option>
                                </select>
                            </div>
                        </div>
                    </template>

                    <div class="flex gap-3">
                        <button @click="nextStep"
                            class="flex-1 py-2.5 px-4 text-sm text-ink-3 hover:text-ink border border-line rounded-field transition-colors">
                            Überspringen
                        </button>
                        <button @click="submitStrength" :disabled="strengthLoading"
                            class="flex-1 py-2.5 px-4 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors">
                            {{ strengthLoading ? 'Speichern…' : 'Weiter' }}
                        </button>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 6 — Ziel
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 6" class="space-y-5">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-ink">Was ist dein Ziel?</h2>
                        <p class="mt-2 text-ink-3">
                            Wähle dein Rennen, das Datum und deine Wunschzeit.
                        </p>
                    </div>

                    <div class="space-y-5">
                        <!-- Race type selection -->
                        <div class="bg-surface/50 border border-line rounded-card p-5 space-y-3">
                            <label class="block text-sm font-semibold text-ink-2">Welches Rennen?</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <button v-for="opt in raceOptions" :key="opt.value"
                                    type="button" @click="selectRace(opt)"
                                    class="py-3 px-3 rounded-field border-2 transition-all text-center"
                                    :class="goalForm.race_distance === opt.value
                                        ? 'bg-accent-soft border-accent text-accent-ink'
                                        : 'border-line text-ink-2 hover:border-accent'">
                                    <div class="text-xl mb-1">{{ opt.icon }}</div>
                                    <div class="text-xs font-semibold">{{ opt.label }}</div>
                                    <div v-if="opt.distance" class="text-[10px] text-ink-3">{{ opt.distance }} km</div>
                                </button>
                            </div>

                            <!-- Custom distance -->
                            <div v-if="goalForm.race_distance === 'custom'" class="mt-1">
                                <label class="block text-xs text-ink-3 mb-1.5">Distanz in km</label>
                                <input v-model="goalForm.distance_km" type="number" min="0.1" step="0.1" placeholder="z.B. 15"
                                    class="input-field border-line" />
                            </div>

                            <!-- Backyard goal: hours/yards -->
                            <div v-if="isBackyardGoal" class="mt-1">
                                <label class="block text-xs text-ink-3 mb-1.5">Ziel: Anzahl Stunden (Yards)</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="goalForm.target_yards" type="number" min="1" max="100" step="1" placeholder="z.B. 24"
                                        class="input-field border-line w-32"
                                        :class="goalErrors.target_yards ? 'border-danger' : ''" />
                                    <span class="text-sm text-ink-3">
                                        Std<template v-if="backyardGoalKm"> · ≈ {{ backyardGoalKm }} km</template>
                                    </span>
                                </div>
                                <p class="mt-1.5 text-[11px] text-ink-3">1 Yard = 1 Stunde = eine 6,706-km-Runde, jede Runde startet zur vollen Stunde.</p>
                                <p v-if="goalErrors.target_yards" class="mt-1 text-xs text-danger">{{ goalErrors.target_yards[0] }}</p>
                            </div>
                        </div>

                        <!-- Race details -->
                        <div class="bg-surface/50 border border-line rounded-card p-5 space-y-4">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Name des Rennens <span class="text-ink-3 font-normal">(optional)</span></label>
                                <input v-model="goalForm.name" type="text" placeholder="z.B. Frankfurt Halbmarathon"
                                    class="input-field" :class="goalErrors.name ? 'border-danger' : 'border-line'" />
                                <p v-if="goalErrors.name" class="mt-1 text-xs text-danger">{{ goalErrors.name[0] }}</p>
                            </div>

                            <!-- Race date -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Datum des Rennens</label>
                                <input v-model="goalForm.race_date" type="date"
                                    class="input-field" :class="goalErrors.race_date ? 'border-danger' : 'border-line'" />
                                <p v-if="goalErrors.race_date" class="mt-1 text-xs text-danger">{{ goalErrors.race_date[0] }}</p>
                            </div>

                            <!-- Target time (not for Backyard) -->
                            <div v-if="!isBackyardGoal">
                                <label class="block text-sm font-medium text-ink-2 mb-2">
                                    Zielzeit:
                                    <span class="text-accent-ink font-bold">{{ targetTimeFormatted }}</span>
                                </label>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs text-ink-3 mb-1.5">Stunden</label>
                                        <select v-model.number="goalForm.target_time_hours"
                                            class="input-field border-line">
                                            <option v-for="h in 24" :key="h-1" :value="h-1">{{ h-1 }} Std.</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-ink-3 mb-1.5">Minuten</label>
                                        <select v-model.number="goalForm.target_time_minutes"
                                            class="input-field border-line">
                                            <option v-for="m in 60" :key="m-1" :value="m-1">{{ m-1 }} Min.</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Summary card -->
                        <div v-if="selectedRace && goalForm.race_date"
                            class="p-4 rounded-card bg-accent-soft border border-accent/25">
                            <p class="text-sm text-accent-ink font-medium">
                                {{ selectedRace.icon }} {{ goalForm.name || selectedRace.label }}
                                <template v-if="isBackyardGoal">
                                    <template v-if="goalForm.target_yards">— Ziel {{ goalForm.target_yards }} Std<template v-if="backyardGoalKm"> (≈ {{ backyardGoalKm }} km)</template></template>
                                </template>
                                <template v-else>in {{ targetTimeFormatted }}</template>
                                am {{ new Date(goalForm.race_date).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' }) }}
                            </p>
                        </div>

                        <p v-if="goalErrors.general" class="text-xs text-danger text-center">{{ goalErrors.general[0] }}</p>

                        <div class="flex gap-3">
                            <button @click="nextStep"
                                class="flex-1 py-2.5 px-4 text-sm text-ink-3 hover:text-ink border border-line rounded-field transition-colors">
                                Überspringen
                            </button>
                            <button @click="submitGoal" :disabled="goalLoading"
                                class="flex-1 py-2.5 px-4 bg-accent hover:opacity-90 disabled:opacity-50 text-white font-semibold rounded-field transition-colors">
                                {{ goalLoading ? 'Speichern…' : 'Ziel speichern & weiter' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 6 — Strava
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 7" class="space-y-6 text-center">
                    <div class="w-20 h-20 mx-auto rounded-3xl bg-warn flex items-center justify-center shadow-lg">
                        <svg class="w-10 h-10 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066l-2.084 4.116z"/>
                            <path d="M11.094 13.828l.716 1.416.773-1.416H14.2L11.807 9l-2.39 4.828h1.677z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-ink">Strava verbinden</h2>
                        <p class="mt-2 text-ink-3 leading-relaxed">
                            Importiere deine Aktivitäten automatisch und verfolge deinen Fortschritt.
                        </p>
                    </div>

                    <div class="bg-surface/50 border border-line rounded-card p-5 text-left space-y-3">
                        <div v-for="benefit in [
                            'Alle Laufaktivitäten werden automatisch importiert',
                            'Herzfrequenz, Tempo und Höhenmeter direkt verfügbar',
                            'Zone3 lernt aus deinen echten Daten'
                        ]" :key="benefit" class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-success-soft flex items-center justify-center shrink-0">
                                <svg class="w-3 h-3 text-success-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                            <span class="text-sm text-ink-2">{{ benefit }}</span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <button @click="completeAndConnectStrava"
                            class="w-full py-3 px-6 bg-warn hover:opacity-90 text-white font-semibold rounded-field transition-colors shadow-card flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066l-2.084 4.116z"/>
                                <path d="M11.094 13.828l.716 1.416.773-1.416H14.2L11.807 9l-2.39 4.828h1.677z"/>
                            </svg>
                            Mit Strava verbinden
                        </button>
                        <button @click="complete"
                            class="w-full py-2.5 text-sm text-ink-3 hover:text-ink border border-line rounded-field transition-colors">
                            Später verbinden — Zum Dashboard
                        </button>
                    </div>
                </div>

            </div>
        </main>
    </div>
</template>

<style scoped>
.input-field {
    @apply w-full px-4 py-2.5 rounded-field border text-ink bg-surface outline-none transition-colors focus:border-accent;
}
</style>
