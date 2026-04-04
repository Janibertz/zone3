<script setup>
import { ref, computed, watch } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';

defineProps({ stravaConnectUrl: String });

const page = usePage();
const user = computed(() => page.props.auth.user);

// ── Steps ─────────────────────────────────────────────────────────────────
const currentStep = ref(1);
const totalSteps  = 4;
const steps = [
    { number: 1, label: 'Willkommen'   },
    { number: 2, label: 'Dein Profil'  },
    { number: 3, label: 'Dein Ziel'    },
    { number: 4, label: 'Strava'       },
];
function nextStep() { if (currentStep.value < totalSteps) currentStep.value++; }

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
// STEP 3 — Race goal with target time
// ════════════════════════════════════════════════════════════════════════
const raceOptions = [
    { value: '5km',           label: '5 km',         distance: 5,        icon: '🏃' },
    { value: '10km',          label: '10 km',         distance: 10,       icon: '🏅' },
    { value: 'half_marathon', label: 'Halbmarathon',  distance: 21.0975,  icon: '🥈' },
    { value: 'marathon',      label: 'Marathon',      distance: 42.195,   icon: '🏆' },
    { value: 'custom',        label: 'Andere Distanz',distance: null,     icon: '📍' },
];

const goalForm = ref({
    name:                '',
    race_distance:       'half_marathon',
    target_value:        21.0975,
    unit:                'km',
    target_time_hours:   1,
    target_time_minutes: 45,
    race_date:           '',
});
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
    if (opt.distance) {
        goalForm.value.target_value = opt.distance;
    } else {
        goalForm.value.target_value = '';
    }
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
// STEP 4 — Complete / Strava
// ════════════════════════════════════════════════════════════════════════
function complete() { router.post(route('onboarding.complete')); }
function completeAndConnectStrava() { router.post(route('onboarding.complete-strava')); }
</script>

<template>
    <Head title="Willkommen bei Zone3" />

    <div class="min-h-screen bg-gray-50 dark:bg-slate-950 flex flex-col">

        <!-- Header -->
        <header class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900">
            <div class="h-8 w-8 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                <span class="text-white text-sm font-bold">Z3</span>
            </div>
            <span class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
        </header>

        <!-- Progress bar -->
        <div class="w-full h-1 bg-gray-200 dark:bg-slate-800">
            <div class="h-1 bg-indigo-600 transition-all duration-500"
                :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"></div>
        </div>

        <!-- Step indicators -->
        <div class="flex justify-center gap-2 pt-6 px-4">
            <div v-for="step in steps" :key="step.number" class="flex items-center gap-1.5">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300"
                    :class="currentStep > step.number
                        ? 'bg-indigo-600 text-white'
                        : currentStep === step.number
                            ? 'bg-indigo-600 text-white ring-4 ring-indigo-600/20'
                            : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'">
                    <svg v-if="currentStep > step.number" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span v-else>{{ step.number }}</span>
                </div>
                <span class="text-xs hidden sm:block transition-colors"
                    :class="currentStep === step.number ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400 dark:text-slate-500'">
                    {{ step.label }}
                </span>
                <div v-if="step.number < totalSteps" class="w-8 sm:w-12 h-px bg-gray-200 dark:bg-slate-700 mx-1"></div>
            </div>
        </div>

        <!-- Main content -->
        <main class="flex-1 flex items-start justify-center p-6 pt-8">
            <div class="w-full max-w-lg">

                <!-- ══════════════════════════════════════════
                     STEP 1 — Welcome
                     ══════════════════════════════════════════ -->
                <div v-if="currentStep === 1" class="text-center space-y-6">
                    <div class="w-20 h-20 mx-auto rounded-3xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-lg">
                        <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
                            Willkommen, {{ user?.name?.split(' ')[0] }}!
                        </h1>
                        <p class="mt-3 text-gray-500 dark:text-slate-400 text-lg leading-relaxed">
                            In 3 kurzen Schritten richtest du Zone3 auf dich ein.
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center py-2">
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🏃</div>
                            <div class="text-xs font-medium text-gray-700 dark:text-slate-300">Dein Profil</div>
                            <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5">Werte bekannt oder berechnen lassen</div>
                        </div>
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🎯</div>
                            <div class="text-xs font-medium text-gray-700 dark:text-slate-300">Dein Ziel</div>
                            <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5">Rennen + Zielzeit</div>
                        </div>
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🔗</div>
                            <div class="text-xs font-medium text-gray-700 dark:text-slate-300">Strava</div>
                            <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5">Optional verbinden</div>
                        </div>
                    </div>
                    <button @click="nextStep"
                        class="w-full py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-colors shadow-sm">
                        Lass uns starten
                    </button>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 2 — Athlete profile
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 2" class="space-y-5">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Dein Athletenprofil</h2>
                        <p class="mt-2 text-gray-500 dark:text-slate-400">
                            Zone3 nutzt deine Trainingszonen für persönliche Empfehlungen.
                        </p>
                    </div>

                    <!-- Mode selection -->
                    <div v-if="!profileMode" class="space-y-3">
                        <button @click="profileMode = 'know'"
                            class="w-full flex items-start gap-4 p-5 rounded-2xl border-2 border-gray-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 bg-white dark:bg-slate-800/50 text-left transition-colors group">
                            <div class="h-10 w-10 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center shrink-0 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-500/30 transition-colors">
                                <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">Ich kenne meine Werte</p>
                                <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">Ich trage Herzfrequenz-Schwelle und Schwellentempo direkt ein.</p>
                            </div>
                        </button>

                        <button @click="profileMode = 'estimate'"
                            class="w-full flex items-start gap-4 p-5 rounded-2xl border-2 border-gray-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 bg-white dark:bg-slate-800/50 text-left transition-colors group">
                            <div class="h-10 w-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center shrink-0 group-hover:bg-purple-200 dark:group-hover:bg-purple-500/30 transition-colors">
                                <svg class="h-5 w-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">Werte berechnen lassen <span class="ml-1 text-xs bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300 px-1.5 py-0.5 rounded-full">KI</span></p>
                                <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">Ich gebe meine beste Laufzeit an und Zone3 schätzt meine Zonen.</p>
                            </div>
                        </button>

                        <button @click="nextStep"
                            class="w-full py-2.5 text-sm text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 transition-colors">
                            Jetzt überspringen — später in den Einstellungen eintragen
                        </button>
                    </div>

                    <!-- Mode: know my values -->
                    <div v-else-if="profileMode === 'know'" class="space-y-5">
                        <button @click="profileMode = null" class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Zurück
                        </button>

                        <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-6 space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                    Schwellenherzfrequenz (LTHR) <span class="text-gray-400 font-normal">bpm</span>
                                </label>
                                <input v-model="knownForm.threshold_heart_rate" type="number" min="100" max="220" placeholder="z.B. 168"
                                    class="input-field" :class="knownErrors.threshold_heart_rate ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="knownErrors.threshold_heart_rate" class="mt-1 text-xs text-red-500">{{ knownErrors.threshold_heart_rate[0] }}</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Herzfrequenz, die du ~60 Minuten maximal halten kannst (Laktatschwelle)</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                    Maximale Herzfrequenz <span class="text-gray-400 font-normal">bpm</span>
                                </label>
                                <input v-model="knownForm.max_heart_rate" type="number" min="100" max="220" placeholder="z.B. 192"
                                    class="input-field" :class="knownErrors.max_heart_rate ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="knownErrors.max_heart_rate" class="mt-1 text-xs text-red-500">{{ knownErrors.max_heart_rate[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                    Schwellentempo <span class="text-gray-400 font-normal">min/km (MM:SS)</span>
                                </label>
                                <input v-model="knownForm.threshold_speed" type="text" placeholder="z.B. 5:30"
                                    class="input-field" :class="knownErrors.threshold_speed ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="knownErrors.threshold_speed" class="mt-1 text-xs text-red-500">{{ knownErrors.threshold_speed[0] }}</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Tempo, das du ~60 Minuten maximal halten kannst</p>
                            </div>
                        </div>

                        <button @click="submitKnown" :disabled="knownLoading"
                            class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors">
                            {{ knownLoading ? 'Speichern…' : 'Speichern & weiter' }}
                        </button>
                    </div>

                    <!-- Mode: AI estimate -->
                    <div v-else-if="profileMode === 'estimate'" class="space-y-5">
                        <button @click="profileMode = null" class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Zurück
                        </button>

                        <!-- Input form (hidden once result is shown) -->
                        <div v-if="!estimateResult" class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-6 space-y-5">
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-purple-50 dark:bg-purple-500/10 border border-purple-100 dark:border-purple-500/20">
                                <svg class="h-4 w-4 text-purple-500 dark:text-purple-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                                </svg>
                                <p class="text-xs text-purple-700 dark:text-purple-300">Zone3 berechnet deine Trainingszonen basierend auf deiner besten Wettkampfzeit via KI. Du kannst die Werte danach noch anpassen.</p>
                            </div>

                            <!-- Age -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Alter</label>
                                <input v-model="estimateForm.age" type="number" min="14" max="90" placeholder="z.B. 32"
                                    class="input-field" :class="estimateErrors.age ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="estimateErrors.age" class="mt-1 text-xs text-red-500">{{ estimateErrors.age[0] }}</p>
                            </div>

                            <!-- Race distance -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Beste Wettkampfdistanz</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button v-for="d in raceDistances" :key="d.value"
                                        type="button" @click="estimateForm.race_distance = d.value"
                                        class="py-2.5 px-3 text-sm rounded-xl border transition-colors text-center"
                                        :class="estimateForm.race_distance === d.value
                                            ? 'bg-purple-600 text-white border-purple-600'
                                            : 'border-gray-200 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:border-purple-400'">
                                        {{ d.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Race time -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Beste Zeit über diese Distanz</label>
                                <input v-model="estimateForm.race_time" type="text" :placeholder="raceTimePlaceholder"
                                    class="input-field" :class="estimateErrors.race_time ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="estimateErrors.race_time" class="mt-1 text-xs text-red-500">{{ estimateErrors.race_time[0] }}</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Format: H:MM:SS oder MM:SS — kein Wettkampf? Einfach deine schnellste Trainingszeit</p>
                            </div>

                            <!-- Weekly runs -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">
                                    Läufe pro Woche: <span class="text-indigo-600 dark:text-indigo-400 font-bold">{{ estimateForm.weekly_runs }}×</span>
                                </label>
                                <input v-model.number="estimateForm.weekly_runs" type="range" min="1" max="14" step="1"
                                    class="w-full accent-indigo-600" />
                                <div class="flex justify-between text-xs text-gray-400 dark:text-slate-500 mt-1">
                                    <span>1× (Einsteiger)</span>
                                    <span>7× (Fortgeschritten)</span>
                                    <span>14× (Profi)</span>
                                </div>
                            </div>

                            <p v-if="estimateErrors.general" class="text-xs text-red-500">{{ estimateErrors.general[0] }}</p>

                            <button @click="runEstimate" :disabled="estimateLoading"
                                class="w-full py-3 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors flex items-center justify-center gap-2">
                                <svg v-if="estimateLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ estimateLoading ? 'KI berechnet…' : 'Zonen berechnen lassen' }}
                            </button>
                        </div>

                        <!-- Result confirmation -->
                        <div v-if="estimateResult" class="space-y-4">
                            <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-6 space-y-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-6 w-6 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
                                        <svg class="h-3.5 w-3.5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">KI-Schätzung abgeschlossen</p>
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800">
                                        <p class="text-xs text-gray-400 dark:text-slate-500 mb-1">LTHR</p>
                                        <p class="text-xl font-bold text-gray-900 dark:text-white">{{ estimateResult.threshold_heart_rate }}</p>
                                        <p class="text-xs text-gray-400">bpm</p>
                                    </div>
                                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800">
                                        <p class="text-xs text-gray-400 dark:text-slate-500 mb-1">Max HF</p>
                                        <p class="text-xl font-bold text-gray-900 dark:text-white">{{ estimateResult.max_heart_rate }}</p>
                                        <p class="text-xs text-gray-400">bpm</p>
                                    </div>
                                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800">
                                        <p class="text-xs text-gray-400 dark:text-slate-500 mb-1">Schwellentempo</p>
                                        <p class="text-xl font-bold text-gray-900 dark:text-white">{{ estimateResult.threshold_speed }}</p>
                                        <p class="text-xs text-gray-400">min/km</p>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-400 dark:text-slate-500 text-center">
                                    Diese Werte sind Schätzungen. Du kannst sie jederzeit im Profil anpassen.
                                </p>
                            </div>

                            <div class="flex gap-3">
                                <button @click="estimateResult = null"
                                    class="flex-1 py-2.5 text-sm border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                                    Neu berechnen
                                </button>
                                <button @click="confirmEstimate" :disabled="knownLoading"
                                    class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors">
                                    {{ knownLoading ? 'Speichern…' : 'Übernehmen & weiter' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 3 — Race goal
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 3" class="space-y-5">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Was ist dein Ziel?</h2>
                        <p class="mt-2 text-gray-500 dark:text-slate-400">
                            Wähle dein Rennen, das Datum und deine Wunschzeit.
                        </p>
                    </div>

                    <div class="space-y-5">
                        <!-- Race type selection -->
                        <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 space-y-3">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-slate-300">Welches Rennen?</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <button v-for="opt in raceOptions" :key="opt.value"
                                    type="button" @click="selectRace(opt)"
                                    class="py-3 px-3 rounded-xl border-2 transition-all text-center"
                                    :class="goalForm.race_distance === opt.value
                                        ? 'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-500 text-indigo-700 dark:text-indigo-300'
                                        : 'border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:border-indigo-300 dark:hover:border-indigo-600'">
                                    <div class="text-xl mb-1">{{ opt.icon }}</div>
                                    <div class="text-xs font-semibold">{{ opt.label }}</div>
                                    <div v-if="opt.distance" class="text-[10px] text-gray-400 dark:text-slate-500">{{ opt.distance }} km</div>
                                </button>
                            </div>

                            <!-- Custom distance -->
                            <div v-if="goalForm.race_distance === 'custom'" class="mt-1">
                                <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1.5">Distanz in km</label>
                                <input v-model="goalForm.target_value" type="number" min="0.1" step="0.1" placeholder="z.B. 15"
                                    class="input-field border-gray-200 dark:border-slate-600" />
                            </div>
                        </div>

                        <!-- Race details -->
                        <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 space-y-4">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Name des Rennens <span class="text-gray-400 font-normal">(optional)</span></label>
                                <input v-model="goalForm.name" type="text" placeholder="z.B. Frankfurt Halbmarathon"
                                    class="input-field" :class="goalErrors.name ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="goalErrors.name" class="mt-1 text-xs text-red-500">{{ goalErrors.name[0] }}</p>
                            </div>

                            <!-- Race date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Datum des Rennens</label>
                                <input v-model="goalForm.race_date" type="date"
                                    class="input-field" :class="goalErrors.race_date ? 'border-red-400' : 'border-gray-200 dark:border-slate-600'" />
                                <p v-if="goalErrors.race_date" class="mt-1 text-xs text-red-500">{{ goalErrors.race_date[0] }}</p>
                            </div>

                            <!-- Target time -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">
                                    Zielzeit:
                                    <span class="text-indigo-600 dark:text-indigo-400 font-bold">{{ targetTimeFormatted }}</span>
                                </label>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs text-gray-400 dark:text-slate-500 mb-1.5">Stunden</label>
                                        <select v-model.number="goalForm.target_time_hours"
                                            class="input-field border-gray-200 dark:border-slate-600">
                                            <option v-for="h in 24" :key="h-1" :value="h-1">{{ h-1 }} Std.</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 dark:text-slate-500 mb-1.5">Minuten</label>
                                        <select v-model.number="goalForm.target_time_minutes"
                                            class="input-field border-gray-200 dark:border-slate-600">
                                            <option v-for="m in 60" :key="m-1" :value="m-1">{{ m-1 }} Min.</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Summary card -->
                        <div v-if="selectedRace && goalForm.race_date"
                            class="p-4 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20">
                            <p class="text-sm text-indigo-700 dark:text-indigo-300 font-medium">
                                {{ selectedRace.icon }} {{ goalForm.name || selectedRace.label }}
                                in {{ targetTimeFormatted }}
                                am {{ new Date(goalForm.race_date).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' }) }}
                            </p>
                        </div>

                        <p v-if="goalErrors.general" class="text-xs text-red-500 text-center">{{ goalErrors.general[0] }}</p>

                        <div class="flex gap-3">
                            <button @click="nextStep"
                                class="flex-1 py-2.5 px-4 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 border border-gray-200 dark:border-slate-700 rounded-xl transition-colors">
                                Überspringen
                            </button>
                            <button @click="submitGoal" :disabled="goalLoading"
                                class="flex-1 py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors">
                                {{ goalLoading ? 'Speichern…' : 'Ziel speichern & weiter' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     STEP 4 — Strava
                     ══════════════════════════════════════════ -->
                <div v-else-if="currentStep === 4" class="space-y-6 text-center">
                    <div class="w-20 h-20 mx-auto rounded-3xl bg-orange-500 flex items-center justify-center shadow-lg">
                        <svg class="w-10 h-10 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066l-2.084 4.116z"/>
                            <path d="M11.094 13.828l.716 1.416.773-1.416H14.2L11.807 9l-2.39 4.828h1.677z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Strava verbinden</h2>
                        <p class="mt-2 text-gray-500 dark:text-slate-400 leading-relaxed">
                            Importiere deine Aktivitäten automatisch und verfolge deinen Fortschritt.
                        </p>
                    </div>

                    <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 text-left space-y-3">
                        <div v-for="benefit in [
                            'Alle Laufaktivitäten werden automatisch importiert',
                            'Herzfrequenz, Tempo und Höhenmeter direkt verfügbar',
                            'Zone3 lernt aus deinen echten Daten'
                        ]" :key="benefit" class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center shrink-0">
                                <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                            <span class="text-sm text-gray-600 dark:text-slate-300">{{ benefit }}</span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <button @click="completeAndConnectStrava"
                            class="w-full py-3 px-6 bg-orange-500 hover:bg-orange-600 text-white font-semibold rounded-xl transition-colors shadow-sm flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066l-2.084 4.116z"/>
                                <path d="M11.094 13.828l.716 1.416.773-1.416H14.2L11.807 9l-2.39 4.828h1.677z"/>
                            </svg>
                            Mit Strava verbinden
                        </button>
                        <button @click="complete"
                            class="w-full py-2.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 border border-gray-200 dark:border-slate-700 rounded-xl transition-colors">
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
    @apply w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 outline-none transition-colors focus:border-indigo-500 dark:focus:border-indigo-400;
}
</style>
