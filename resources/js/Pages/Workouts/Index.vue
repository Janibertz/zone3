<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ListRow from '@/Components/UI/ListRow.vue';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    workouts:  Array,
    paceZones: Object,
});

const workouts    = ref(props.workouts ?? []);
const filterType  = ref('all');
const searchQuery = ref('');
const deleting    = ref(null);

/** Filter als wischbare Chips, wie im Mockup. */
const FILTERS = [
    { value: 'all',       label: 'Alle' },
    { value: 'interval',  label: 'Intervalle' },
    { value: 'tempo_run', label: 'Tempo' },
    { value: 'long_run',  label: 'Lang' },
    { value: 'easy_run',  label: 'Locker' },
    { value: 'public',    label: 'Geteilt' },
];

const TEXT = { success: 'text-success', warn: 'text-warn', danger: 'text-danger', info: 'text-info', accent: 'text-accent', neutral: 'text-ink-3' };
const typeText = (type) => TEXT[sessionType(type).accent] ?? 'text-ink-3';

/** Dieselben Zonenfarben wie die Runden einer Aktivität: Z1 grau bis Z5 rot. */
const ZONE_BAR_COLORS = ['bg-ink-3/50', 'bg-success', 'bg-accent', 'bg-warn', 'bg-danger'];

const filteredWorkouts = computed(() => {
    let list = workouts.value;
    if (filterType.value === 'public') list = list.filter(w => w.is_public);
    else if (filterType.value !== 'all') list = list.filter(w => w.type === filterType.value);
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
                const sw = blockWeight(step) || 180;
                addSeg(step.zone ?? 2, sw);
            }
        } else {
            addSeg(b.pace_zone ?? zoneForType(b.type), w);
        }
    }

    if (!totalWeight) return [{ color: ZONE_BAR_COLORS[1], weight: 1 }];

    return segments;
}

/**
 * Gewicht im Band in Sekunden. Strecken werden mit 5:00/km umgerechnet —
 * vorher standen Meter neben Sekunden, und 4800 m Intervalle drückten
 * zehn Minuten Einlaufen auf einen Strich.
 */
function blockWeight(b) {
    if ((b.duration_mode ?? 'time') === 'distance') return (b.distance_m ?? 0) * 0.3;
    return b.duration_sec ?? 0;
}

function zoneForType(type) {
    return { warmup: 2, cooldown: 2, recovery: 1, rest: 1, free: 2, active: 3, ramp_up: 2, ramp_down: 2 }[type] ?? 2;
}

function formatDuration(min) {
    if (!min) return null;
    const h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? `${h}:${String(m).padStart(2,'0')} h` : `${m} min`;
}

// ── Aktionen als Sheet: ein Tipp auf die Karte, statt vier Symbole daneben ──
const actions = ref(null);

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
    actions.value = null;
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

/**
 * Ein Workout für alle freigeben — oder die Freigabe zurücknehmen.
 *
 * Geteilt wird die STRUKTUR, nicht das Tempo: die Blöcke tragen Zonen, und
 * jeder Läufer bekommt die Sekunden aus seiner eigenen Schwellenpace
 * gerechnet. Ein „4×1000 m in Zone 5" ist damit für jeden richtig.
 *
 * Ändern kann ein freigegebenes Workout weiterhin nur sein Ersteller.
 */
const publishing = ref(null);

async function togglePublic(w) {
    publishing.value = w.id;
    try {
        const { data } = await axios.post(route('workouts.toggle-public', w.id));
        Object.assign(w, data.workout);
    } finally {
        publishing.value = null;
    }
}

async function duplicateWorkout(w) {
    const { data } = await axios.post(route('workouts.duplicate', w.id));
    workouts.value.unshift(data.workout);
    actions.value = null;
}

// ── Einplanen ─────────────────────────────────────────────────────────────────
// Das Workout wird eine Einheit in Zone3, zusaetzlich zum Plan. Wer Garmin
// verbunden hat, bekommt sie von dort aus auf die Uhr — verschieben oder
// loeschen in Zone3 erreicht sie dann auch.
function localToday() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const scheduleWorkout = ref(null);
const scheduleDate    = ref(localToday());
const scheduling      = ref(false);
const scheduleError   = ref('');
const scheduleDone    = ref(null);

function openSchedule(w) {
    actions.value = null;
    scheduleWorkout.value = w;
    scheduleDate.value    = localToday();
    scheduleError.value   = '';
    scheduleDone.value    = null;
}

async function submitSchedule() {
    scheduling.value    = true;
    scheduleError.value = '';
    try {
        const { data } = await axios.post(route('workouts.schedule', scheduleWorkout.value.id), { date: scheduleDate.value });
        scheduleDone.value = data;
        setTimeout(() => { scheduleWorkout.value = null; }, 2500);
    } catch (e) {
        scheduleError.value = e.response?.data?.message || 'Einplanen hat nicht geklappt.';
    } finally {
        scheduling.value = false;
    }
}

</script>

<template>
    <Head title="Workouts" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" title="Workouts" :eyebrow="workouts.length ? `${workouts.length} gespeichert` : null">
                <template #action>
                    <Link :href="route('workouts.create')" aria-label="Neues Workout"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-accent transition-transform active:scale-90">
                        <svg class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    </Link>
                </template>
            </PageHeader>

            <div class="mx-auto max-w-3xl space-y-4 px-4 pb-8 pt-3 lg:px-8">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
                    <label for="workout-search" class="sr-only">Workout suchen</label>
                    <input id="workout-search" v-model="searchQuery" type="search" enterkeyhint="search" placeholder="Suchen"
                        class="h-10 w-full rounded-[10px] border-0 bg-surface-2 pl-9 pr-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
                </div>

                <!-- Filter: eine Zeile, wischbar -->
                <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] lg:mx-0 lg:px-0">
                    <button v-for="f in FILTERS" :key="f.value" type="button"
                        class="h-9 shrink-0 rounded-full px-4 text-[15px] font-semibold transition-colors"
                        :class="filterType === f.value ? 'bg-ink text-canvas' : 'bg-surface text-ink-2 active:bg-surface-2'"
                        @click="filterType = f.value">{{ f.label }}</button>
                </div>

                <div v-if="filteredWorkouts.length === 0" class="rounded-card bg-surface px-5 py-10 text-center">
                    <p class="text-[17px] font-semibold text-ink">{{ workouts.length ? 'Nichts gefunden' : 'Noch keine Workouts' }}</p>
                    <p class="mt-1 text-[15px] text-ink-3">{{ workouts.length ? 'Mit diesem Filter gibt es nichts.' : 'Bau dir dein erstes Workout, du kannst es einplanen und teilen.' }}</p>
                    <AppButton v-if="!workouts.length" class="mt-4" :href="route('workouts.create')">Workout bauen</AppButton>
                </div>

                <div v-else class="grid gap-3 sm:grid-cols-2">
                    <button v-for="w in filteredWorkouts" :key="w.id" type="button"
                        class="block w-full rounded-card bg-surface p-5 text-left transition-transform duration-150 ease-out-strong active:scale-[0.98]"
                        @click="actions = w">
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-1.5 text-[15px] font-semibold" :class="typeText(w.type)">
                                <span class="h-2 w-2 rounded-full" :class="sessionType(w.type).dot" />{{ sessionType(w.type).label }}
                            </span>
                            <span class="shrink-0 text-[13px] text-ink-3">{{ w.is_public ? 'geteilt' : 'nur für dich' }}</span>
                        </div>
                        <h3 class="mt-1.5 text-[20px] font-bold leading-snug text-ink">{{ w.name }}</h3>
                        <p class="mt-2 flex gap-4 font-rounded text-[17px] font-bold tabular-nums text-ink">
                            <span v-if="w.estimated_distance_km">{{ fmtNum(w.estimated_distance_km) }} km</span>
                            <span v-if="formatDuration(w.estimated_duration_min)">{{ formatDuration(w.estimated_duration_min) }}</span>
                        </p>
                        <!-- Die Struktur als Band: Länge = Anteil, Farbe = Zone -->
                        <div class="mt-3 flex h-2.5 gap-[2px] overflow-hidden rounded-full" aria-hidden="true">
                            <i v-for="(seg, i) in workoutBarSegments(w)" :key="i" class="block h-full" :class="seg.color" :style="{ flex: seg.weight }" />
                        </div>
                        <p v-if="!w.is_public && w.unpublished_reason" class="mt-2 text-[13px] text-warn">{{ w.unpublished_reason }}</p>
                    </button>
                </div>
            </div>
        </div>

        <!-- Aktionen -->
        <AppSheet :show="!!actions" :title="actions?.name ?? ''" @close="actions = null">
            <template v-if="actions">
                <div class="divide-y divide-line overflow-hidden rounded-card bg-surface-2/60">
                    <ListRow title="Einplanen" chevron @click="openSchedule(actions)" />
                    <ListRow title="Bearbeiten" :href="route('workouts.edit', actions.id)" />
                    <ListRow title="Duplizieren" @click="duplicateWorkout(actions)" />
                    <ListRow :title="actions.is_public ? 'Freigabe zurücknehmen' : 'Für alle freigeben'"
                        :subtitle="actions.is_public ? 'Andere sehen die Struktur und rechnen ihre eigenen Paces.' : 'Geteilt wird die Struktur, nicht dein Tempo.'"
                        @click="togglePublic(actions)" />
                    <ListRow title="Löschen" danger @click="askDelete(actions)" />
                </div>
                <p v-if="actions.times_used > 0" class="mt-3 text-[13px] text-ink-3">{{ actions.times_used }}× genutzt</p>
            </template>
        </AppSheet>

        <ConfirmSheet
            :show="pendingDelete !== null"
            title="Workout löschen?"
            :message="pendingDelete ? `„${pendingDelete.name}“ wird dauerhaft entfernt.` : null"
            confirm-label="Ja, löschen"
            :loading="deleting === pendingDelete?.id"
            @confirm="deleteWorkout"
            @close="pendingDelete = null"
        />

        <!-- Einplanen -->
        <AppSheet :show="!!scheduleWorkout" title="Einplanen" :subtitle="scheduleWorkout?.name" @close="scheduleWorkout = null">
            <div v-if="scheduleDone" class="rounded-card bg-success-soft p-5 text-center">
                <p class="text-sm font-semibold text-success-ink">Eingeplant</p>
                <p class="mt-1 text-xs text-success-ink/80">
                    Steht jetzt im Kalender{{ scheduleDone.garmin ? ' und kommt in den nächsten Minuten auf deine Garmin-Uhr' : '' }}.
                </p>
            </div>
            <template v-else>
                <div v-if="scheduleError" class="mb-4 rounded-card bg-danger-soft p-3">
                    <p class="text-sm text-danger-ink">{{ scheduleError }}</p>
                </div>
                <label class="z-label">Datum</label>
                <input v-model="scheduleDate" type="date" :min="localToday()" class="z-input" />
                <p class="z-hint mt-2">Kommt zusätzlich zu dem, was an dem Tag im Plan steht.</p>
            </template>
            <template v-if="!scheduleDone" #footer>
                <div class="flex gap-3">
                    <AppButton variant="secondary" block @click="scheduleWorkout = null">Abbrechen</AppButton>
                    <AppButton block :loading="scheduling" :disabled="!scheduleDate" @click="submitSchedule">Einplanen</AppButton>
                </div>
            </template>
        </AppSheet>

    </AuthenticatedLayout>
</template>
