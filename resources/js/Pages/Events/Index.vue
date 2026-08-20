<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import SwipeRow from '@/Components/SwipeRow.vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const coachName = computed(() => usePage().props.coach?.name ?? 'Dein Coach');

const props = defineProps({
    events: Array,
    status: String,
    active_plan_event_id: { type: Number, default: null },
});

// ── Filters ─────────────────────────────────────────────────────────────────
const filterPriority = ref('all');

const filteredEvents = computed(() => {
    if (filterPriority.value === 'all') return props.events;
    return props.events.filter(e => e.priority === filterPriority.value);
});

const upcomingEvents = computed(() => filteredEvents.value.filter(e => e.days_until >= 0));
const pastEvents     = computed(() => filteredEvents.value.filter(e => e.days_until < 0));

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
        onSuccess: () => { confirmingDelete.value = null; },
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

// Die Prioritaet traegt nur noch eine kleine Pille. Vorher lag ueber jeder
// Karte ein farbiger Streifen mit fest verdrahteten Hexwerten (#ef4444 …),
// die am Designsystem vorbeigingen und im dunklen Modus nicht mitzogen.
const priorityConfig = {
    A: { pill: 'bg-danger-soft text-danger-ink', desc: 'Hauptrennen'      },
    B: { pill: 'bg-warn-soft text-warn-ink',     desc: 'Wichtiges Rennen' },
    C: { pill: 'bg-surface-2 text-ink-2',        desc: 'Trainingsrennen'  },
};

// Kommende und vergangene Events teilen sich dieselbe Karte — vorher stand
// deren Markup zweimal im Template, knapp sechzig Zeilen fast gleich.
const sections = computed(() => [
    { key: 'upcoming', title: null,                events: upcomingEvents.value },
    { key: 'past',     title: 'Vergangene Events', events: pastEvents.value     },
].filter(s => s.events.length > 0));

const filterOptions = [
    { value: 'all', label: 'Alle' },
    { value: 'A',   label: 'A' },
    { value: 'B',   label: 'B' },
    { value: 'C',   label: 'C' },
];

function daysLabel(days) {
    if (days < 0) return 'Vorbei';
    if (days === 0) return 'Heute!';
    if (days === 1) return 'Morgen';
    return `in ${days} Tagen`;
}

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('de-DE', { day: '2-digit', month: 'short', year: 'numeric' });
}

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
    <Head title="Events" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <div class="space-y-5 px-4 py-4 lg:px-6 lg:py-6">

                <!-- ══ KOPF ══════════════════════════════════════════ -->
                <!--
                    Auf dem Telefon untereinander, die Schaltflaeche ueber die
                    volle Breite: nebeneinander brach sie ohnehin um und stand
                    dann schmal am linken Rand.
                -->
                <header class="flex flex-col gap-4 px-1 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Events &amp; Rennen</h1>
                        <p class="mt-1 text-[15px] text-ink-3">
                            Plane deine Wettkämpfe — {{ coachName }} baut den Plan dazu.
                        </p>
                    </div>
                    <AppButton class="w-full sm:w-auto sm:shrink-0" @click="openCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Event hinzufügen
                    </AppButton>
                </header>

                <div v-if="status === 'event-created' || status === 'event-updated'"
                    class="rounded-card bg-success-soft px-4 py-3 text-sm text-success-ink shadow-card">
                    Event {{ status === 'event-created' ? 'hinzugefügt' : 'gespeichert' }}.
                </div>

                <div class="max-w-xs">
                    <SegmentedControl v-model="filterPriority" :options="filterOptions" />
                </div>

                <!-- ══ NICHTS DA ═════════════════════════════════════ -->
                <AppCard v-if="filteredEvents.length === 0">
                    <EmptyState
                        title="Keine Events"
                        description="Trag dein nächstes Rennen ein — daraus entsteht dein Trainingsplan."
                    >
                        <template #icon>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                            </svg>
                        </template>
                        <AppButton @click="openCreate">Event hinzufügen</AppButton>
                    </EmptyState>
                </AppCard>

                <!-- ══ EVENTS ════════════════════════════════════════ -->
                <section v-for="section in sections" :key="section.key" class="min-w-0">
                    <SectionHeader v-if="section.title" :title="section.title" />

                    <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                        <SwipeRow
                            v-for="event in section.events"
                            :key="event.id"
                            class="rounded-card"
                            content-class="bg-surface-2 rounded-card"
                            :left-width="event.days_until < 0 ? 0 : 80"
                            :right-width="80"
                            :disabled="event.days_until < 0"
                        >
                            <template #left="{ close }">
                                <button
                                    class="flex w-full flex-col items-center justify-center gap-1 bg-accent text-[11px] font-semibold text-white"
                                    @click="openEdit(event); close()"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                    </svg>
                                    Bearbeiten
                                </button>
                            </template>

                            <template #right="{ close }">
                                <button
                                    class="flex w-full flex-col items-center justify-center gap-1 bg-danger text-[11px] font-semibold text-white"
                                    @click="confirmingDelete = event; close()"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                    Löschen
                                </button>
                            </template>

                            <article class="h-full rounded-card bg-surface p-5 shadow-card"
                                :class="event.days_until < 0 ? 'opacity-60' : ''">

                                <!-- Kopfzeile -->
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <span class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-bold"
                                            :class="priorityConfig[event.priority].pill"
                                            :title="priorityConfig[event.priority].desc">
                                            {{ event.priority }}
                                        </span>
                                        <span v-if="event.plan_is_active"
                                            class="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-[11px] font-semibold text-success-ink">
                                            <span class="h-1.5 w-1.5 rounded-full bg-success" />
                                            Aktiver Plan
                                        </span>
                                    </div>

                                    <span class="shrink-0 text-[13px] font-semibold"
                                        :class="event.days_until < 0 ? 'text-ink-3'
                                              : event.days_until <= 7 ? 'text-danger'
                                              : event.days_until <= 30 ? 'text-warn' : 'text-ink-3'">
                                        {{ daysLabel(event.days_until) }}
                                    </span>
                                </div>

                                <h3 class="mt-2.5 truncate text-lg font-bold tracking-tight text-ink">{{ event.name }}</h3>

                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-3">
                                    <span>{{ formatDate(event.event_date) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span class="font-medium text-ink-2">{{ event.distance_label }}</span>
                                    <template v-if="event.target_time_formatted">
                                        <span aria-hidden="true">·</span>
                                        <span>Ziel {{ event.target_time_formatted }}</span>
                                    </template>
                                </div>

                                <p v-if="event.notes" class="mt-2 line-clamp-2 text-[13px] leading-relaxed text-ink-3">
                                    {{ event.notes }}
                                </p>

                                <!-- Aktionen -->
                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    <template v-if="event.days_until < 0">
                                        <AppButton v-if="event.plan_generated_at" variant="secondary" size="sm"
                                            :href="route('events.plan.show', event.id)">
                                            Auswertung
                                        </AppButton>
                                        <span v-else class="text-[13px] text-ink-3">Kein Plan erstellt</span>
                                    </template>

                                    <template v-else>
                                        <AppButton
                                            v-if="!anotherEventHasPlan(event)"
                                            :variant="event.plan_generated_at ? 'secondary' : 'primary'"
                                            size="sm"
                                            :href="route('events.plan.show', event.id)"
                                        >
                                            {{ event.plan_generated_at ? 'Plan ansehen' : 'Plan erstellen' }}
                                        </AppButton>

                                        <span v-else
                                            class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-3 py-1.5 text-[13px] font-medium text-ink-3"
                                            title="Ein anderes Event hat bereits den aktiven Plan">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                                            </svg>
                                            Plan gesperrt
                                        </span>

                                        <AppButton variant="ghost" size="sm" @click="openEdit(event)">Bearbeiten</AppButton>
                                    </template>

                                    <!-- Löschen bleibt bewusst zurückhaltend: es stand vorher als
                                         rot gefüllter Knopf auf jeder Karte. -->
                                    <button type="button"
                                        class="ml-auto rounded-full px-2.5 py-1.5 text-[13px] font-medium text-ink-3 transition-colors hover:bg-danger-soft hover:text-danger-ink"
                                        @click="confirmingDelete = event">
                                        Löschen
                                    </button>
                                </div>

                                <p v-if="event.plan_generated_at" class="mt-3 border-t border-line pt-3 text-[12px] text-ink-3">
                                    Plan vom {{ event.plan_generated_at }}
                                </p>
                            </article>
                        </SwipeRow>
                    </div>
                </section>

            </div>
        </div>

        <!-- ── Create/Edit Sheet ─────────────────────────────────────────── -->
        <AppSheet
            :show="showModal"
            :title="editingEvent ? 'Event bearbeiten' : 'Neues Event'"
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
                            class="rounded-field border-2 px-3 py-2.5 text-sm font-semibold transition-all active:scale-[0.98]"
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
                        {{ editingEvent ? 'Speichern' : 'Event erstellen' }}
                    </AppButton>
                </div>
            </template>
        </AppSheet>

        <!-- ── Delete confirm ────────────────────────────────────────────── -->
        <ConfirmSheet
            :show="!!confirmingDelete"
            title="Event löschen?"
            :message="`${confirmingDelete?.name} und der zugehörige Trainingsplan werden dauerhaft gelöscht.`"
            confirm-label="Ja, löschen"
            @confirm="deleteEvent(confirmingDelete)"
            @close="confirmingDelete = null"
        />

    </AuthenticatedLayout>
</template>
