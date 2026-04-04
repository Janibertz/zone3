<script setup>
import { ref, computed } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    stravaConnectUrl: String,
});

const page = usePage();
const user = computed(() => page.props.auth.user);

// ── Step management ────────────────────────────────────────────────────────
const currentStep = ref(1);
const totalSteps  = 4;

const steps = [
    { number: 1, label: 'Willkommen' },
    { number: 2, label: 'Athletenprofil' },
    { number: 3, label: 'Erstes Ziel' },
    { number: 4, label: 'Strava' },
];

function nextStep() {
    if (currentStep.value < totalSteps) currentStep.value++;
}

// ── Step 2: Athlete profile ────────────────────────────────────────────────
const profileForm = ref({
    threshold_heart_rate: '',
    max_heart_rate: '',
    threshold_speed: '',
});
const profileErrors  = ref({});
const profileLoading = ref(false);

async function submitProfile() {
    profileErrors.value  = {};
    profileLoading.value = true;
    try {
        await axios.post(route('onboarding.profile'), profileForm.value);
        nextStep();
    } catch (err) {
        if (err.response?.status === 422) {
            profileErrors.value = err.response.data.errors;
        }
    } finally {
        profileLoading.value = false;
    }
}

// ── Step 3: First goal ─────────────────────────────────────────────────────
const today = new Date().toISOString().slice(0, 10);
const inSixMonths = (() => {
    const d = new Date();
    d.setMonth(d.getMonth() + 6);
    return d.toISOString().slice(0, 10);
})();

const goalForm = ref({
    name: '',
    type: 'distance',
    target_value: '',
    unit: 'km',
    target_time_hours: 0,
    target_time_minutes: 0,
    start_date: today,
    end_date: inSixMonths,
});
const goalErrors  = ref({});
const goalLoading = ref(false);

const goalTypes = [
    { value: 'distance',  label: 'Distanz',    unit: 'km'  },
    { value: 'duration',  label: 'Zeit',        unit: 'h'   },
    { value: 'frequency', label: 'Häufigkeit',  unit: 'Einheiten' },
];

function onGoalTypeChange() {
    const found = goalTypes.find(t => t.value === goalForm.value.type);
    if (found) goalForm.value.unit = found.unit;
    goalForm.value.target_value = '';
}

async function submitGoal() {
    goalErrors.value  = {};
    goalLoading.value = true;
    try {
        await axios.post(route('onboarding.goal'), goalForm.value);
        nextStep();
    } catch (err) {
        if (err.response?.status === 422) {
            goalErrors.value = err.response.data.errors;
        }
    } finally {
        goalLoading.value = false;
    }
}

// ── Complete ───────────────────────────────────────────────────────────────
function complete() {
    router.post(route('onboarding.complete'));
}

function completeAndConnectStrava() {
    router.post(route('onboarding.complete-strava'));
}
</script>

<template>
    <Head title="Willkommen" />

    <div class="min-h-screen bg-gray-50 dark:bg-slate-950 flex flex-col">

        <!-- Header bar -->
        <header class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900">
            <div class="h-8 w-8 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                <span class="text-white text-sm font-bold">Z3</span>
            </div>
            <span class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
        </header>

        <!-- Progress bar -->
        <div class="w-full h-1 bg-gray-200 dark:bg-slate-800">
            <div
                class="h-1 bg-indigo-600 transition-all duration-500"
                :style="{ width: ((currentStep - 1) / (totalSteps - 1) * 100) + '%' }"
            ></div>
        </div>

        <!-- Step indicators -->
        <div class="flex justify-center gap-2 pt-6 px-4">
            <div
                v-for="step in steps"
                :key="step.number"
                class="flex items-center gap-1.5"
            >
                <div
                    class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300"
                    :class="currentStep > step.number
                        ? 'bg-indigo-600 text-white'
                        : currentStep === step.number
                            ? 'bg-indigo-600 text-white ring-4 ring-indigo-600/20'
                            : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'"
                >
                    <svg v-if="currentStep > step.number" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span v-else>{{ step.number }}</span>
                </div>
                <span
                    class="text-xs hidden sm:block transition-colors"
                    :class="currentStep === step.number ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400 dark:text-slate-500'"
                >{{ step.label }}</span>
                <div v-if="step.number < totalSteps" class="w-8 sm:w-12 h-px bg-gray-200 dark:bg-slate-700 mx-1"></div>
            </div>
        </div>

        <!-- Main content -->
        <main class="flex-1 flex items-center justify-center p-6">
            <div class="w-full max-w-lg">

                <!-- ── Step 1: Welcome ──────────────────────────────── -->
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
                            Lass uns dein Profil einrichten, damit Zone3 dir persönliche Trainingsempfehlungen geben kann.
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center py-2">
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🏃</div>
                            <div class="text-xs text-gray-500 dark:text-slate-400">Athletenprofil</div>
                        </div>
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🎯</div>
                            <div class="text-xs text-gray-500 dark:text-slate-400">Erstes Ziel</div>
                        </div>
                        <div class="p-4 rounded-xl bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700">
                            <div class="text-2xl mb-1">🔗</div>
                            <div class="text-xs text-gray-500 dark:text-slate-400">Strava verbinden</div>
                        </div>
                    </div>
                    <button
                        @click="nextStep"
                        class="w-full py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-colors shadow-sm"
                    >
                        Lass uns starten
                    </button>
                </div>

                <!-- ── Step 2: Athlete profile ──────────────────────── -->
                <div v-else-if="currentStep === 2" class="space-y-6">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Athletenprofil</h2>
                        <p class="mt-2 text-gray-500 dark:text-slate-400">
                            Diese Werte werden verwendet, um deine Trainingszonen zu berechnen.
                        </p>
                    </div>

                    <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-6 space-y-5">
                        <!-- Threshold HR -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                Schwellenherzzfrequenz (LTHR) <span class="text-gray-400 dark:text-slate-500 font-normal">bpm</span>
                            </label>
                            <input
                                v-model="profileForm.threshold_heart_rate"
                                type="number"
                                min="100" max="220"
                                placeholder="z.B. 168"
                                class="w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                :class="profileErrors.threshold_heart_rate
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400'"
                            />
                            <p v-if="profileErrors.threshold_heart_rate" class="mt-1 text-xs text-red-500">
                                {{ profileErrors.threshold_heart_rate[0] }}
                            </p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Herzfrequenz beim maximalen Dauertempo (Laktatschwelle)</p>
                        </div>

                        <!-- Max HR -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                Maximale Herzfrequenz <span class="text-gray-400 dark:text-slate-500 font-normal">bpm</span>
                            </label>
                            <input
                                v-model="profileForm.max_heart_rate"
                                type="number"
                                min="100" max="220"
                                placeholder="z.B. 192"
                                class="w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                :class="profileErrors.max_heart_rate
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400'"
                            />
                            <p v-if="profileErrors.max_heart_rate" class="mt-1 text-xs text-red-500">
                                {{ profileErrors.max_heart_rate[0] }}
                            </p>
                        </div>

                        <!-- Threshold pace -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                Schwellentempo <span class="text-gray-400 dark:text-slate-500 font-normal">min/km (MM:SS)</span>
                            </label>
                            <input
                                v-model="profileForm.threshold_speed"
                                type="text"
                                placeholder="z.B. 5:30"
                                class="w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                :class="profileErrors.threshold_speed
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400'"
                            />
                            <p v-if="profileErrors.threshold_speed" class="mt-1 text-xs text-red-500">
                                {{ profileErrors.threshold_speed[0] }}
                            </p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Tempo das du ~60 min halten kannst</p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="nextStep"
                            class="flex-1 py-2.5 px-4 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 border border-gray-200 dark:border-slate-700 rounded-xl transition-colors"
                        >
                            Überspringen
                        </button>
                        <button
                            @click="submitProfile"
                            :disabled="profileLoading"
                            class="flex-1 py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors"
                        >
                            {{ profileLoading ? 'Speichern…' : 'Speichern & weiter' }}
                        </button>
                    </div>
                </div>

                <!-- ── Step 3: First goal ───────────────────────────── -->
                <div v-else-if="currentStep === 3" class="space-y-6">
                    <div class="text-center">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Erstes Ziel setzen</h2>
                        <p class="mt-2 text-gray-500 dark:text-slate-400">
                            Definiere dein erstes Trainingsziel. Du kannst es später anpassen.
                        </p>
                    </div>

                    <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-6 space-y-5">
                        <!-- Goal name -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Zielname</label>
                            <input
                                v-model="goalForm.name"
                                type="text"
                                placeholder="z.B. Halbmarathon im Herbst"
                                class="w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                :class="goalErrors.name
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400'"
                            />
                            <p v-if="goalErrors.name" class="mt-1 text-xs text-red-500">{{ goalErrors.name[0] }}</p>
                        </div>

                        <!-- Goal type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Art des Ziels</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button
                                    v-for="type in goalTypes"
                                    :key="type.value"
                                    type="button"
                                    @click="goalForm.type = type.value; onGoalTypeChange()"
                                    class="py-2 px-3 text-sm rounded-xl border transition-colors text-center"
                                    :class="goalForm.type === type.value
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'border-gray-200 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:border-indigo-400'"
                                >
                                    {{ type.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Target value -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                Zielwert
                                <span class="text-gray-400 dark:text-slate-500 font-normal">({{ goalForm.unit }})</span>
                            </label>
                            <input
                                v-model="goalForm.target_value"
                                type="number"
                                min="0"
                                :placeholder="goalForm.type === 'distance' ? 'z.B. 500' : goalForm.type === 'duration' ? 'z.B. 50' : 'z.B. 20'"
                                class="w-full px-4 py-2.5 rounded-xl border text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                :class="goalErrors.target_value
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400'"
                            />
                            <p v-if="goalErrors.target_value" class="mt-1 text-xs text-red-500">{{ goalErrors.target_value[0] }}</p>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Startdatum</label>
                                <input
                                    v-model="goalForm.start_date"
                                    type="date"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400 text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Enddatum</label>
                                <input
                                    v-model="goalForm.end_date"
                                    type="date"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 focus:border-indigo-500 dark:focus:border-indigo-400 text-gray-900 dark:text-white bg-white dark:bg-slate-900 transition-colors"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="nextStep"
                            class="flex-1 py-2.5 px-4 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 border border-gray-200 dark:border-slate-700 rounded-xl transition-colors"
                        >
                            Überspringen
                        </button>
                        <button
                            @click="submitGoal"
                            :disabled="goalLoading"
                            class="flex-1 py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors"
                        >
                            {{ goalLoading ? 'Speichern…' : 'Speichern & weiter' }}
                        </button>
                    </div>
                </div>

                <!-- ── Step 4: Strava ───────────────────────────────── -->
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
                            Verbinde Strava, damit Zone3 deine Aktivitäten automatisch importiert und analysiert.
                        </p>
                    </div>

                    <div class="bg-white dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 text-left space-y-3">
                        <div v-for="benefit in ['Automatischer Import deiner Laufaktivitäten', 'Herzfrequenz- und Tempodaten', 'Fortschrittsverfolgung über Zeit']"
                            :key="benefit"
                            class="flex items-center gap-3"
                        >
                            <div class="w-5 h-5 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center shrink-0">
                                <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </div>
                            <span class="text-sm text-gray-600 dark:text-slate-300">{{ benefit }}</span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <button
                            @click="completeAndConnectStrava"
                            class="w-full py-3 px-6 bg-orange-500 hover:bg-orange-600 text-white font-semibold rounded-xl transition-colors shadow-sm flex items-center justify-center gap-2"
                        >
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066l-2.084 4.116z"/>
                                <path d="M11.094 13.828l.716 1.416.773-1.416H14.2L11.807 9l-2.39 4.828h1.677z"/>
                            </svg>
                            Mit Strava verbinden
                        </button>
                        <button
                            @click="complete"
                            class="w-full py-2.5 px-6 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 border border-gray-200 dark:border-slate-700 rounded-xl transition-colors"
                        >
                            Später verbinden — Zum Dashboard
                        </button>
                    </div>
                </div>

            </div>
        </main>
    </div>
</template>
