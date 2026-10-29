<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ListRow from '@/Components/UI/ListRow.vue';
import { fmtNum, clock } from '@/Composables/useFormat';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    events: Array,
    status: String,
    active_plan_event_id: { type: Number, default: null },
});

// ── Gliederung wie im Mockup: das nächste Rennen groß, dann „Danach“ und „Gelaufen“ ──
const upcomingEvents = computed(() => props.events
    .filter(e => e.days_until >= 0)
    .sort((x, y) => x.event_date.localeCompare(y.event_date)));
const pastEvents = computed(() => props.events
    .filter(e => e.days_until < 0)
    .sort((x, y) => y.event_date.localeCompare(x.event_date)));

/** Groß steht das Rennen, auf das der Plan hinläuft — ohne Plan das nächste. */
const featured = computed(() =>
    upcomingEvents.value.find(e => e.id === props.active_plan_event_id) ?? upcomingEvents.value[0] ?? null);
const later = computed(() => upcomingEvents.value.filter(e => e.id !== featured.value?.id));

// ── Modal state ──────────────────────────────────────────────────────────────
const showModal   = ref(false);
const editingEvent = ref(null);

const form = useForm({
    name:                '',
    event_date:          '',
    race_distance:       '10km',
    distance_km:         '',
    priority:            'B',
    target_time_hours:   0,
    target_time_minutes: 0,
    target_yards:        '',
    notes:               '',
});

function openCreate() {
    editingEvent.value = null;
    form.reset();
    form.race_distance = '10km';
    form.priority = 'B';
    form.target_time_hours = 0;
    form.target_time_minutes = 0;
    form.target_yards = '';
    showModal.value = true;
}

function openEdit(event) {
    editingEvent.value = event;
    form.name                = event.name;
    form.event_date          = event.event_date;
    form.race_distance       = event.race_distance;
    form.distance_km         = event.distance_km ?? '';
    form.priority            = event.priority;
    form.target_time_hours   = event.target_time_hours;
    form.target_time_minutes = event.target_time_minutes;
    form.target_yards        = event.target_yards ?? '';
    form.notes               = event.notes ?? '';
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingEvent.value = null;
    form.reset();
    form.clearErrors();
}

function submitForm() {
    if (editingEvent.value) {
        form.patch(route('events.update', editingEvent.value.id), {
            onSuccess: closeModal,
        });
    } else {
        form.post(route('events.store'), {
            onSuccess: closeModal,
        });
    }
}

// ── Plan state helpers ───────────────────────────────────────────────────────
// thisEventHasPlan: this event owns the active plan
// anotherEventHasPlan: a *different* event owns the active plan → block
function anotherEventHasPlan(event) {
    return props.active_plan_event_id !== null && props.active_plan_event_id !== event.id;
}

// ── Delete ───────────────────────────────────────────────────────────────────
const confirmingDelete = ref(null);

function deleteEvent(event) {
    router.delete(route('events.destroy', event.id), {
        onSuccess: () => { confirmingDelete.value = null; detail.value = null; },
    });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const raceDistances = [
    { value: '5km',            label: '5 km' },
    { value: '10km',           label: '10 km' },
    { value: 'half_marathon',  label: 'Halbmarathon' },
    { value: 'marathon',       label: 'Marathon' },
    { value: 'custom',         label: 'Eigene Distanz' },
    { value: 'backyard_ultra', label: 'Backyard Ultra' },
];

// Standard Backyard loop distance (km) — mirrors Event::BACKYARD_LAP_KM
const BACKYARD_LAP_KM = 6.706;
const isBackyard = computed(() => form.race_distance === 'backyard_ultra');
const backyardTargetKm = computed(() =>
    form.target_yards ? (Number(form.target_yards) * BACKYARD_LAP_KM).toFixed(1).replace('.', ',') : null
);

const PRIORITY_LABEL = { A: 'Hauptrennen', B: 'Wichtiges Rennen', C: 'Trainingsrennen' };

function featuredLabel(e) {
    return e.id === props.active_plan_event_id ? 'Zielrennen' : PRIORITY_LABEL[e.priority];
}

function longDate(d) {
    return new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
const monthShort = (d) => new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { month: 'short' }).replace('.', '');
const dayNum = (d) => new Date(d + 'T00:00:00').getDate();

function countdown(days) {
    if (days === 0) return { value: 'Heute', unit: '' };
    if (days === 1) return { value: 'Morgen', unit: '' };
    return { value: fmtNum(days, 0), unit: 'Tage' };
}

function distance(e) {
    return e.race_km ? `${fmtNum(e.race_km)} km` : e.distance_label;
}

/** „21,1 km · Ziel 1:30:00" — die Zeile unter dem Namen. */
function foot(e) {
    const parts = [distance(e)];
    if (e.target_seconds) parts.push(`Ziel ${clock(e.target_seconds)}`);
    else if (e.target_yards) parts.push(`Ziel ${e.target_yards} Yards`);
    else parts.push('ohne Zielzeit');
    return parts.join(' · ');
}

/** Abstand zum Ziel: +1:05 heißt langsamer. */
function delta(e) {
    if (!e.result_seconds || !e.target_seconds) return null;
    const d = e.result_seconds - e.target_seconds;
    return (d > 0 ? '+' : d < 0 ? '−' : '±') + clock(Math.abs(d));
}

function planHref(e) {
    return e.plan_generated_at
        ? route('events.plan.show', e.id)
        : route('plan-interview.show', { event: e.id });
}

// ── Details als Sheet: hier stehen Plan, Bearbeiten und Löschen ─────────────
const detail = ref(null);
const openDetail = (e) => { detail.value = e; };

const hoursOptions = Array.from({ length: 24 }, (_, i) => i);
const minutesOptions = Array.from({ length: 60 }, (_, i) => i);

// Auto-generate name when distance changes in create mode
watch(() => form.race_distance, (val) => {
    if (editingEvent.value) return;
    const found = raceDistances.find(r => r.value === val);
    if (found && found.value !== 'custom') {
        const year = new Date().getFullYear();
        form.name = `${found.label} ${year}`;
    }
}, { immediate: false });
</script>

<template>
    <Head title="Rennen" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" title="Rennen" :eyebrow="upcomingEvents.length ? `${upcomingEvents.length} geplant` : null">
                <template #action>
                    <button type="button" aria-label="Rennen hinzufügen"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-accent transition-transform active:scale-90"
                        @click="openCreate">
                        <svg class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    </button>
                </template>
            </PageHeader>

            <div class="mx-auto max-w-3xl space-y-7 px-4 pb-8 pt-3 lg:px-8">
                <div v-if="status === 'event-created' || status === 'event-updated'"
                    class="rounded-card bg-success-soft px-4 py-3 text-[15px] text-success-ink">
                    Rennen {{ status === 'event-created' ? 'eingetragen' : 'gespeichert' }}.
                </div>

                <AppCard v-if="events.length === 0">
                    <EmptyState title="Noch kein Rennen"
                        description="Trag dein nächstes Rennen ein, daraus entsteht dein Trainingsplan.">
                        <AppButton @click="openCreate">Rennen eintragen</AppButton>
                    </EmptyState>
                </AppCard>

                <!-- ══ Das nächste Rennen ══════════════════════════════════ -->
                <section v-if="featured" class="rounded-card bg-surface p-5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5 text-[15px] font-semibold text-danger">
                            <span class="h-2 w-2 rounded-full bg-danger" />{{ featuredLabel(featured) }}
                        </span>
                        <span v-if="featured.plan_is_active" class="rounded-full bg-accent/15 px-2.5 py-1 text-[13px] font-semibold text-accent">Plan aktiv</span>
                    </div>
                    <h2 class="mt-2 text-[28px] font-extrabold leading-tight tracking-[-0.03em] text-ink">{{ featured.name }}</h2>
                    <p class="mt-0.5 text-[15px] text-ink-3">{{ longDate(featured.event_date) }} · {{ distance(featured) }}</p>

                    <p class="mt-5 flex items-baseline gap-2.5">
                        <span class="font-rounded text-[64px] font-extrabold leading-none tracking-[-0.04em] text-ink">{{ countdown(featured.days_until).value }}</span>
                        <span class="text-[20px] font-semibold text-ink-2">{{ countdown(featured.days_until).unit }}</span>
                    </p>

                    <div v-if="featured.target_seconds || featured.predicted_seconds"
                        class="mt-5 grid grid-cols-3 gap-2 border-t border-line pt-4">
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Ziel</p>
                            <p class="font-rounded text-[22px] font-bold tabular-nums text-ink">{{ featured.target_seconds ? clock(featured.target_seconds) : '–' }}</p>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Prognose</p>
                            <p class="font-rounded text-[22px] font-bold tabular-nums text-ink">{{ featured.predicted_seconds ? clock(featured.predicted_seconds) : '–' }}</p>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Pace</p>
                            <p class="font-rounded text-[22px] font-bold tabular-nums text-ink">{{ featured.target_pace ?? '–' }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <AppButton variant="secondary" block @click="openDetail(featured)">Details</AppButton>
                        <AppButton v-if="!anotherEventHasPlan(featured)" block :href="planHref(featured)">
                            {{ featured.plan_generated_at ? 'Zum Plan' : 'Plan erstellen' }}
                        </AppButton>
                        <AppButton v-else variant="secondary" block disabled>Plan gesperrt</AppButton>
                    </div>
                </section>

                <!-- ══ Danach ═══════════════════════════════════════════════ -->
                <section v-if="later.length">
                    <h2 class="mx-1 mb-2 text-[22px] font-bold text-ink">Danach</h2>
                    <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                        <button v-for="e in later" :key="e.id" type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                            @click="openDetail(e)">
                            <span class="w-11 shrink-0 text-center">
                                <span class="block text-[13px] font-semibold text-ink-3">{{ monthShort(e.event_date) }}</span>
                                <span class="block font-rounded text-[22px] font-extrabold leading-tight text-ink">{{ dayNum(e.event_date) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[17px] text-ink">{{ e.name }}</span>
                                <span class="block truncate text-[13px] text-ink-3">{{ foot(e) }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                </section>

                <!-- ══ Gelaufen ═════════════════════════════════════════════ -->
                <section v-if="pastEvents.length">
                    <h2 class="mx-1 mb-2 text-[22px] font-bold text-ink">Gelaufen</h2>
                    <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                        <button v-for="e in pastEvents" :key="e.id" type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                            @click="openDetail(e)">
                            <span class="w-11 shrink-0 text-center">
                                <span class="block text-[13px] font-semibold text-ink-3">{{ monthShort(e.event_date) }}</span>
                                <span class="block font-rounded text-[22px] font-extrabold leading-tight text-ink">{{ dayNum(e.event_date) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[17px] text-ink">{{ e.name }}</span>
                                <span class="block truncate text-[13px] text-ink-3">{{ foot(e) }}</span>
                            </span>
                            <span v-if="e.result_seconds" class="shrink-0 text-right">
                                <span class="block font-rounded text-[17px] font-bold tabular-nums text-ink">{{ clock(e.result_seconds) }}</span>
                                <span v-if="delta(e)" class="block text-[13px] tabular-nums text-ink-3">{{ delta(e) }}</span>
                            </span>
                            <svg v-else class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                </section>
            </div>
        </div>

        <!-- ── Details ───────────────────────────────────────────────────── -->
        <AppSheet :show="!!detail" :title="detail?.name ?? ''" max-width="lg" @close="detail = null">
            <template v-if="detail">
                <p class="text-[15px] text-ink-3">{{ longDate(detail.event_date) }} · {{ foot(detail) }}</p>
                <p class="mt-1 text-[15px] text-ink-3">{{ PRIORITY_LABEL[detail.priority] }} ({{ detail.priority }})</p>
                <p v-if="detail.notes" class="mt-3 whitespace-pre-line text-[15px] text-ink-2">{{ detail.notes }}</p>

                <div class="mt-5 divide-y divide-line overflow-hidden rounded-card bg-surface-2/60">
                    <ListRow v-if="detail.days_until < 0 && detail.plan_generated_at" title="Auswertung" :href="route('events.plan.show', detail.id)" />
                    <ListRow v-else-if="detail.days_until >= 0 && !anotherEventHasPlan(detail)"
                        :title="detail.plan_generated_at ? 'Zum Plan' : 'Plan erstellen'" :href="planHref(detail)" />
                    <ListRow v-else-if="detail.days_until >= 0" title="Plan gesperrt" subtitle="Ein anderes Rennen hat schon den aktiven Plan." />
                    <ListRow v-if="detail.days_until >= 0" title="Bearbeiten" chevron @click="openEdit(detail); detail = null" />
                    <ListRow title="Löschen" danger @click="confirmingDelete = detail" />
                </div>
                <p v-if="detail.plan_generated_at" class="mt-3 text-[13px] text-ink-3">Plan vom {{ detail.plan_generated_at }}</p>
            </template>
        </AppSheet>

        <!-- ── Create/Edit Sheet ─────────────────────────────────────────── -->
        <AppSheet
            :show="showModal"
            :title="editingEvent ? 'Rennen bearbeiten' : 'Neues Rennen'"
            max-width="lg"
            @close="closeModal"
        >
            <form id="event-form" @submit.prevent="submitForm" class="space-y-4 pt-1">
                <!-- Priority -->
                <div>
                    <label class="z-label">Priorität</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button v-for="p in ['A','B','C']" :key="p" type="button"
                            @click="form.priority = p"
                            class="rounded-field border-2 px-3 py-2.5 text-sm font-semibold transition active:scale-[0.98]"
                            :class="form.priority === p
                                ? (p === 'A' ? 'border-danger bg-danger-soft text-danger-ink' : p === 'B' ? 'border-warn bg-warn-soft text-warn-ink' : 'border-line-strong bg-surface-2 text-ink')
                                : 'border-line text-ink-3 hover:border-line-strong'"
                        >
                            <div class="text-lg font-bold">{{ p }}</div>
                            <div class="mt-0.5 text-xs">{{ p === 'A' ? 'Hauptrennen' : p === 'B' ? 'Wichtig' : 'Training' }}</div>
                        </button>
                    </div>
                    <p v-if="form.errors.priority" class="z-error">{{ form.errors.priority }}</p>
                </div>

                <!-- Name -->
                <div>
                    <label class="z-label">Name</label>
                    <input v-model="form.name" type="text" required placeholder="z.B. Halbmarathon Berlin" class="z-input" />
                    <p v-if="form.errors.name" class="z-error">{{ form.errors.name }}</p>
                </div>

                <!-- Distance + Date -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="z-label">Distanz</label>
                        <select v-model="form.race_distance" class="z-input">
                            <option v-for="d in raceDistances" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="z-label">Datum</label>
                        <input v-model="form.event_date" type="date" required class="z-input" />
                        <p v-if="form.errors.event_date" class="z-error">{{ form.errors.event_date }}</p>
                    </div>
                </div>

                <!-- Custom distance km -->
                <div v-if="form.race_distance === 'custom'">
                    <label class="z-label">Distanz in km</label>
                    <input v-model="form.distance_km" type="number" step="0.1" min="0.1" placeholder="z.B. 15" class="z-input" />
                </div>

                <!-- Backyard Ultra: goal in hours/yards -->
                <div v-if="isBackyard">
                    <label class="z-label">Ziel: Anzahl Stunden (Yards)</label>
                    <div class="flex items-center gap-2">
                        <input v-model="form.target_yards" type="number" step="1" min="1" max="100" placeholder="z.B. 24" class="z-input w-32" />
                        <span class="text-sm text-ink-3">
                            Std<template v-if="backyardTargetKm"> · ≈ {{ backyardTargetKm }} km</template>
                        </span>
                    </div>
                    <p class="z-hint">1 Yard = 1 Stunde = eine 6,706-km-Runde. Jede Runde startet zur vollen Stunde.</p>
                    <p v-if="form.errors.target_yards" class="z-error">{{ form.errors.target_yards }}</p>
                </div>

                <!-- Target time (not for Backyard) -->
                <div v-if="!isBackyard">
                    <label class="z-label">Zielzeit <span class="font-normal text-ink-3">(optional)</span></label>
                    <div class="flex items-center gap-2">
                        <select v-model.number="form.target_time_hours" class="z-input w-auto px-3">
                            <option v-for="h in hoursOptions" :key="h" :value="h">{{ h }}h</option>
                        </select>
                        <select v-model.number="form.target_time_minutes" class="z-input w-auto px-3">
                            <option v-for="m in minutesOptions" :key="m" :value="m">{{ String(m).padStart(2,'0') }} min</option>
                        </select>
                    </div>
                </div>

                <!-- Notes -->
                <div>
                    <label class="z-label">Notizen <span class="font-normal text-ink-3">(optional)</span></label>
                    <textarea v-model="form.notes" rows="2" placeholder="z.B. Kurs, Hotel, Motivation..." class="z-input resize-none" />
                </div>
            </form>

            <template #footer>
                <div class="flex gap-3">
                    <AppButton variant="secondary" block @click="closeModal">Abbrechen</AppButton>
                    <AppButton type="submit" form="event-form" block :loading="form.processing">
                        {{ editingEvent ? 'Speichern' : 'Eintragen' }}
                    </AppButton>
                </div>
            </template>
        </AppSheet>

        <!-- ── Delete confirm ────────────────────────────────────────────── -->
        <ConfirmSheet
            :show="!!confirmingDelete"
            title="Rennen löschen?"
            :message="`${confirmingDelete?.name} und der zugehörige Trainingsplan werden dauerhaft gelöscht.`"
            confirm-label="Ja, löschen"
            @confirm="deleteEvent(confirmingDelete)"
            @close="confirmingDelete = null"
        />

    </AuthenticatedLayout>
</template>
