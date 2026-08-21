<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import GarminSendSheet from '@/Components/UI/GarminSendSheet.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';

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
    easy_run:  'bg-success-soft text-success-ink',
    tempo_run: 'bg-warn-soft text-warn-ink',
    interval:  'bg-danger-soft text-danger-ink',
    long_run:  'bg-info-soft text-info-ink',
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
/**
 * Geloescht wird ueber das Sheet der App, nicht ueber den nativen Dialog.
 *
 * `confirm()` reisst auf dem Telefon ein Systemfenster auf, das nichts mit
 * der App zu tun hat — und blockiert nebenbei alles andere. Den Rest der
 * App fragt laengst ConfirmSheet.
 */
const pendingDelete = ref(null);

function askDelete(w) {
    pendingDelete.value = w;
}

async function deleteWorkout() {
    const w = pendingDelete.value;
    if (!w) return;

    deleting.value = w.id;
    try {
        await axios.delete(route('workouts.destroy', w.id));
        workouts.value = workouts.value.filter(x => x.id !== w.id);
        pendingDelete.value = null;
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
    garminDate.value     = new Date().toISOString().slice(0, 10);
}

async function sendToGarmin({ email, password, date } = {}) {
    garminSending.value = true;
    garminError.value   = '';
    try {
        const payload = garminConnected.value
            ? { date }
            : { date, email, password };
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
        <div class="px-4 sm:px-6 py-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-bold text-ink">Workout-Bibliothek</h1>
                    <p class="text-sm text-ink-3 mt-0.5">Deine gespeicherten Lauf-Workouts</p>
                </div>
                <Link :href="route('workouts.create')"
                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors shadow-card">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Neues Workout
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex gap-2 mb-5 flex-wrap">
                <input v-model="searchQuery" type="search" placeholder="Suchen…"
                    class="rounded-field bg-surface px-3 py-2 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40 w-44" />
                <button v-for="t in ['all','easy_run','tempo_run','interval','long_run']" :key="t"
                    @click="filterType = t"
                    class="rounded-field px-3 py-2 text-xs font-semibold transition-colors"
                    :class="filterType === t
                        ? 'bg-accent text-white'
                        : 'bg-surface text-ink-2 border border-line hover:bg-surface-2'">
                    {{ t === 'all' ? 'Alle' : TYPE_LABELS[t] }}
                </button>
            </div>

            <!-- Empty -->
            <div v-if="filteredWorkouts.length === 0"
                class="bg-surface rounded-card border border-dashed border-line p-12 text-center">
                <svg class="h-12 w-12 mx-auto text-ink-3 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
                <p class="text-sm font-medium text-ink-2">Noch keine Workouts</p>
                <p class="text-xs text-ink-3 mt-1 mb-4">Erstelle dein erstes Workout im Baukasten.</p>
                <Link :href="route('workouts.create')"
                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Workout erstellen
                </Link>
            </div>

            <!-- Grid -->
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <div v-for="w in filteredWorkouts" :key="w.id"
                    class="bg-surface rounded-card border border-line shadow-card overflow-hidden hover:shadow-md transition-shadow">

                    <!-- Zone bar -->
                    <div class="h-2 flex w-full">
                        <div v-for="(seg, i) in workoutBarSegments(w)" :key="i"
                            :style="{ width: seg.pct + '%', backgroundColor: seg.color }" />
                    </div>

                    <div class="p-4">
                        <!-- Title row -->
                        <!--
                            Auf dem Telefon standen vier Symbolknoepfe neben dem
                            Titel und liessen ihm rund 120 Pixel: aus
                            "KRAFT / 500FAST - 500SLOW" wurde "KRAFT / 500S…".
                            Der Name ist das Einzige, woran man ein Workout
                            erkennt — er bekommt die Zeile fuer sich.
                        -->
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-ink sm:truncate">{{ w.name }}</h3>
                                <span class="inline-block mt-1 text-[11px] font-medium px-2 py-0.5 rounded-full" :class="TYPE_COLORS[w.type]">
                                    {{ TYPE_LABELS[w.type] ?? w.type }}
                                </span>
                            </div>
                            <!-- Actions -->
                            <!-- 28 Pixel sind fuer einen Daumen zu wenig, erst recht
                                 fuer "Loeschen". Auf dem Telefon 44, ab sm wieder kompakt. -->
                            <div class="flex shrink-0 gap-1 self-end sm:self-auto">
                                <button @click="openGarminModal(w)" title="Zu Garmin senden"
                                    class="p-3.5 sm:p-1.5 rounded-lg text-ink-3 hover:text-accent hover:bg-accent-soft transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                                </button>
                                <button @click="duplicateWorkout(w)" title="Duplizieren"
                                    class="p-3.5 sm:p-1.5 rounded-lg text-ink-3 hover:text-ink-2 hover:bg-surface-2 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                                </button>
                                <Link :href="route('workouts.edit', w.id)" title="Bearbeiten"
                                    class="p-3.5 sm:p-1.5 rounded-lg text-ink-3 hover:text-ink-2 hover:bg-surface-2 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                </Link>
                                <button @click="askDelete(w)" :disabled="deleting === w.id" title="Löschen"
                                    class="p-3.5 sm:p-1.5 rounded-lg text-ink-3 hover:text-danger hover:bg-danger-soft transition-colors disabled:opacity-40">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="flex gap-4 text-xs text-ink-3">
                            <span v-if="w.estimated_distance_km">
                                <span class="font-semibold text-ink">{{ w.estimated_distance_km }}</span> km
                            </span>
                            <span v-if="w.estimated_duration_min">
                                <span class="font-semibold text-ink">{{ formatDuration(w.estimated_duration_min) }}</span>
                            </span>
                            <span v-if="w.times_used > 0">{{ w.times_used }}× genutzt</span>
                        </div>

                        <!-- Tags -->
                        <div v-if="w.tags?.length" class="mt-2 flex flex-wrap gap-1">
                            <span v-for="tag in w.tags" :key="tag"
                                class="text-[11px] px-2 py-0.5 rounded-full bg-surface-2 text-ink-3">
                                {{ tag }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmSheet
            :show="pendingDelete !== null"
            title="Workout löschen?"
            :message="pendingDelete ? `„${pendingDelete.name}“ wird dauerhaft entfernt.` : null"
            confirm-label="Ja, löschen"
            :loading="deleting === pendingDelete?.id"
            @confirm="deleteWorkout"
            @close="pendingDelete = null"
        />

        <!-- Zu Garmin senden -->
        <GarminSendSheet
            v-model:date="garminDate"
            :show="garminModal"
            :connected="garminConnected"
            :saved-email="garminSavedEmail"
            :sending="garminSending"
            :error="garminError"
            :success="garminSuccess"
            :subtitle="garminWorkout?.name"
            with-date
            @send="sendToGarmin"
            @close="garminModal = false"
        />
    </AuthenticatedLayout>
</template>
