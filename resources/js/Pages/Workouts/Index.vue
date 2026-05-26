<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    workouts:  Array,
    paceZones: Object,
});

const workouts    = ref(props.workouts ?? []);
const filterType  = ref('all');
const searchQuery = ref('');
const deleting    = ref(null);

const TYPE_LABELS = {
    easy_run:  'Lockerer Lauf',
    tempo_run: 'Tempolauf',
    interval:  'Intervall',
    long_run:  'Langer Lauf',
};
const TYPE_COLORS = {
    easy_run:  'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
    tempo_run: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
    interval:  'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
    long_run:  'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400',
};

const ZONE_BAR_COLORS = ['#94a3b8','#22c55e','#eab308','#f97316','#ef4444'];

const filteredWorkouts = computed(() => {
    let list = workouts.value;
    if (filterType.value !== 'all') list = list.filter(w => w.type === filterType.value);
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(w =>
            w.name.toLowerCase().includes(q) ||
            (w.tags ?? []).some(t => t.toLowerCase().includes(q))
        );
    }
    return list;
});

// ── Pace bar visualization ────────────────────────────────────────────────────
function workoutBarSegments(workout) {
    const segments = [];
    const blocks = workout.blocks ?? [];

    function addSeg(zone, weight) {
        if (weight <= 0) return;
        const color = ZONE_BAR_COLORS[(zone ?? 1) - 1] ?? ZONE_BAR_COLORS[0];
        if (segments.length && segments[segments.length-1].color === color) {
            segments[segments.length-1].weight += weight;
        } else {
            segments.push({ color, weight });
        }
    }

    let totalWeight = 0;
    for (const b of blocks) {
        const w = blockWeight(b);
        totalWeight += w;
        if (b.type === 'repeat') {
            const reps = b.repetitions ?? 1;
            for (const sub of b.steps ?? []) {
                const sw = blockWeight(sub) * reps;
                addSeg(sub.pace_zone ?? 3, sw);
            }
        } else if (b.type === 'ramp_up' || b.type === 'ramp_down') {
            for (const step of b.steps ?? []) {
                const sw = step.duration_sec ?? 180;
                addSeg(step.zone ?? 2, sw);
            }
        } else {
            addSeg(b.pace_zone ?? zoneForType(b.type), w);
        }
    }

    if (!totalWeight) return [{ color: ZONE_BAR_COLORS[1], weight: 1 }];

    return segments.map(s => ({ ...s, pct: Math.round(s.weight / totalWeight * 100) }));
}

function blockWeight(b) {
    if ((b.duration_mode ?? 'time') === 'distance') return b.distance_m ?? 0;
    return b.duration_sec ?? 0;
}

function zoneForType(type) {
    return { warmup: 2, cooldown: 2, recovery: 1, rest: 1, free: 2, active: 3, ramp_up: 2, ramp_down: 2 }[type] ?? 2;
}

function formatDuration(min) {
    if (!min) return '—';
    const h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? `${h}:${String(m).padStart(2,'0')} h` : `${m} min`;
}

// ── Actions ───────────────────────────────────────────────────────────────────
async function deleteWorkout(w) {
    if (!confirm(`„${w.name}" wirklich löschen?`)) return;
    deleting.value = w.id;
    try {
        await axios.delete(route('workouts.destroy', w.id));
        workouts.value = workouts.value.filter(x => x.id !== w.id);
    } finally {
        deleting.value = null;
    }
}

async function duplicateWorkout(w) {
    const { data } = await axios.post(route('workouts.duplicate', w.id));
    workouts.value.unshift(data.workout);
}

// ── Garmin ────────────────────────────────────────────────────────────────────
const garminModal     = ref(false);
const garminWorkout   = ref(null);
const garminEmail     = ref('');
const garminPassword  = ref('');
const garminDate      = ref(new Date().toISOString().slice(0, 10));
const garminSending   = ref(false);
const garminSuccess   = ref(false);
const garminError     = ref('');
import { usePage } from '@inertiajs/vue3';
const garminConnected  = computed(() => !!usePage().props.auth.garminConnected);
const garminSavedEmail = computed(() => usePage().props.auth.garminEmail);

function openGarminModal(w) {
    garminWorkout.value  = w;
    garminModal.value    = true;
    garminError.value    = '';
    garminSuccess.value  = false;
    garminEmail.value    = garminSavedEmail.value || '';
    garminPassword.value = '';
    garminDate.value     = new Date().toISOString().slice(0, 10);
}

async function sendToGarmin() {
    garminSending.value = true;
    garminError.value   = '';
    try {
        const payload = garminConnected.value
            ? { date: garminDate.value }
            : { date: garminDate.value, email: garminEmail.value, password: garminPassword.value };
        const { data } = await axios.post(route('workouts.send-to-garmin', garminWorkout.value.id), payload);
        if (data.success) {
            garminSuccess.value = true;
            setTimeout(() => { garminModal.value = false; garminSuccess.value = false; }, 2500);
        } else {
            garminError.value = data.error || 'Fehler beim Übertragen.';
        }
    } catch(e) {
        garminError.value = e.response?.data?.error || e.message || 'Fehler';
    } finally {
        garminSending.value = false;
    }
}
</script>

<template>
    <Head title="Workout-Bibliothek" />
    <AuthenticatedLayout>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white">Workout-Bibliothek</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">Deine gespeicherten Lauf-Workouts</p>
                </div>
                <Link :href="route('workouts.create')"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Neues Workout
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex gap-2 mb-5 flex-wrap">
                <input v-model="searchQuery" type="search" placeholder="Suchen…"
                    class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 w-44" />
                <button v-for="t in ['all','easy_run','tempo_run','interval','long_run']" :key="t"
                    @click="filterType = t"
                    class="rounded-xl px-3 py-2 text-xs font-semibold transition-colors"
                    :class="filterType === t
                        ? 'bg-indigo-600 text-white'
                        : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-300 border border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700'">
                    {{ t === 'all' ? 'Alle' : TYPE_LABELS[t] }}
                </button>
            </div>

            <!-- Empty -->
            <div v-if="filteredWorkouts.length === 0"
                class="bg-white dark:bg-slate-900 rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 p-12 text-center">
                <svg class="h-12 w-12 mx-auto text-gray-300 dark:text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Noch keine Workouts</p>
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1 mb-4">Erstelle dein erstes Workout im Baukasten.</p>
                <Link :href="route('workouts.create')"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Workout erstellen
                </Link>
            </div>

            <!-- Grid -->
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <div v-for="w in filteredWorkouts" :key="w.id"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden hover:shadow-md transition-shadow">

                    <!-- Zone bar -->
                    <div class="h-2 flex w-full">
                        <div v-for="(seg, i) in workoutBarSegments(w)" :key="i"
                            :style="{ width: seg.pct + '%', backgroundColor: seg.color }" />
                    </div>

                    <div class="p-4">
                        <!-- Title row -->
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate">{{ w.name }}</h3>
                                <span class="inline-block mt-1 text-[11px] font-medium px-2 py-0.5 rounded-full" :class="TYPE_COLORS[w.type]">
                                    {{ TYPE_LABELS[w.type] ?? w.type }}
                                </span>
                            </div>
                            <!-- Actions -->
                            <div class="flex gap-1 shrink-0">
                                <button @click="openGarminModal(w)" title="Zu Garmin senden"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                                </button>
                                <button @click="duplicateWorkout(w)" title="Duplizieren"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                                </button>
                                <Link :href="route('workouts.edit', w.id)" title="Bearbeiten"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                </Link>
                                <button @click="deleteWorkout(w)" :disabled="deleting === w.id" title="Löschen"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors disabled:opacity-40">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="flex gap-4 text-xs text-gray-500 dark:text-slate-400">
                            <span v-if="w.estimated_distance_km">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ w.estimated_distance_km }}</span> km
                            </span>
                            <span v-if="w.estimated_duration_min">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ formatDuration(w.estimated_duration_min) }}</span>
                            </span>
                            <span v-if="w.times_used > 0">{{ w.times_used }}× genutzt</span>
                        </div>

                        <!-- Tags -->
                        <div v-if="w.tags?.length" class="mt-2 flex flex-wrap gap-1">
                            <span v-for="tag in w.tags" :key="tag"
                                class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400">
                                {{ tag }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Garmin Modal -->
        <Teleport to="body">
            <div v-if="garminModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="garminModal = false">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="h-9 w-9 rounded-xl bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-gray-900 dark:text-white">Zu Garmin Connect senden</h2>
                            <p class="text-xs text-gray-500 dark:text-slate-400 truncate max-w-[200px]">{{ garminWorkout?.name }}</p>
                        </div>
                        <button @click="garminModal = false" class="ml-auto text-gray-400 hover:text-gray-600 dark:hover:text-slate-300">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div v-if="garminSuccess" class="text-center py-4">
                        <svg class="h-10 w-10 text-green-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <p class="text-sm font-semibold text-green-700 dark:text-green-400">Workout übertragen!</p>
                    </div>
                    <template v-else>
                        <div v-if="garminError" class="mb-3 rounded-xl bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-400">{{ garminError }}</div>

                        <!-- Date -->
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Datum</label>
                            <input v-model="garminDate" type="date"
                                class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                        </div>

                        <div v-if="garminConnected && !garminError" class="mb-3 flex items-center gap-2 rounded-xl bg-green-50 dark:bg-green-900/20 px-3 py-2.5">
                            <svg class="h-4 w-4 text-green-600 dark:text-green-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <p class="text-xs text-green-700 dark:text-green-400 truncate">{{ garminSavedEmail }}</p>
                        </div>
                        <div v-else class="space-y-2 mb-3">
                            <input v-model="garminEmail" type="email" placeholder="Garmin E-Mail"
                                class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                            <input v-model="garminPassword" type="password" placeholder="Passwort"
                                class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                        </div>

                        <button @click="sendToGarmin" :disabled="garminSending || (!garminConnected && (!garminEmail || !garminPassword))"
                            class="w-full rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                            <svg v-if="garminSending" class="inline h-4 w-4 animate-spin mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            {{ garminSending ? 'Wird übertragen…' : 'Senden' }}
                        </button>
                    </template>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
