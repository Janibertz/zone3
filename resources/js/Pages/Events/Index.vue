<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
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
function thisEventHasPlan(event) {
    return props.active_plan_event_id === event.id;
}
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

const priorityConfig = {
    A: { label: 'A', title: 'A-Event',  desc: 'Hauptrennen',       bg: 'bg-danger-soft',    text: 'text-danger-ink',    border: 'border-danger/25',   dot: 'bg-danger' },
    B: { label: 'B', title: 'B-Event',  desc: 'Wichtiges Rennen',  bg: 'bg-warn-soft', text: 'text-warn-ink', border: 'border-warn/25', dot: 'bg-warn' },
    C: { label: 'C', title: 'C-Event',  desc: 'Trainingsrennen',   bg: 'bg-surface-2',    text: 'text-ink-2',  border: 'border-line',   dot: 'bg-ink-3' },
};

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
        <div class="px-3 sm:px-6 py-4 sm:py-8">

            <!-- Header -->
            <div class="flex items-center justify-between mb-5 sm:mb-7">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-ink">Events & Rennen</h1>
                    <p class="mt-0.5 text-sm text-ink-3">Plane deine Wettkämpfe und lass {{ coachName }} Trainingspläne erstellen</p>
                </div>
                <button
                    @click="openCreate"
                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors shadow-card"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Event hinzufügen
                </button>
            </div>

            <!-- Success banner -->
            <div v-if="status === 'event-created' || status === 'event-updated'" class="mb-4 flex items-center gap-2 rounded-field bg-success-soft border border-success/25 px-4 py-3 text-sm text-success-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Event {{ status === 'event-created' ? 'hinzugefügt' : 'gespeichert' }}.
            </div>

            <!-- Priority filter tabs -->
            <div class="flex gap-1 bg-surface-2 rounded-card p-1 mb-5 w-fit">
                <button v-for="f in ['all','A','B','C']" :key="f"
                    @click="filterPriority = f"
                    class="px-3 py-1.5 rounded-field text-sm font-medium transition-all whitespace-nowrap"
                    :class="filterPriority === f
                        ? 'bg-surface text-ink shadow-card'
                        : 'text-ink-3 hover:text-ink'"
                >
                    {{ f === 'all' ? 'Alle' : f + '-Events' }}
                </button>
            </div>

            <!-- Empty state -->
            <div v-if="filteredEvents.length === 0" class="text-center py-16 bg-surface rounded-card border border-dashed border-line">
                <svg class="h-12 w-12 mx-auto text-ink-3 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                </svg>
                <p class="text-sm font-medium text-ink-3">Keine Events gefunden</p>
                <p class="mt-1 text-xs text-ink-3">Füge dein erstes Rennevent hinzu</p>
                <button @click="openCreate" class="mt-4 inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition-colors">
                    Event hinzufügen
                </button>
            </div>

            <!-- ── Upcoming & active events ───────────────────────────────── -->
            <div v-if="upcomingEvents.length === 0 && filteredEvents.length > 0" class="text-center py-10 bg-surface rounded-card border border-dashed border-line mb-3">
                <p class="text-sm text-ink-3">Keine kommenden Events — füge dein nächstes Rennen hinzu.</p>
                <button @click="openCreate" class="mt-3 inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition-colors">Event hinzufügen</button>
            </div>

            <div class="space-y-3">
                <SwipeRow
                    v-for="event in upcomingEvents"
                    :key="event.id"
                    class="rounded-card shadow-card"
                    content-class=""
                    :left-width="80"
                    :right-width="80"
                >
                    <!-- Swipe rechts → Bearbeiten -->
                    <template #left="{ close }">
                        <button
                            @click="openEdit(event); close()"
                            class="w-full bg-accent text-white flex flex-col items-center justify-center gap-1 text-[11px] font-semibold"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                            Bearbeiten
                        </button>
                    </template>
                    <!-- Swipe links → Löschen -->
                    <template #right="{ close }">
                        <button
                            @click="confirmingDelete = event; close()"
                            class="w-full bg-danger text-white flex flex-col items-center justify-center gap-1 text-[11px] font-semibold"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            Löschen
                        </button>
                    </template>

                    <div
                        class="bg-surface rounded-card border overflow-hidden transition-all"
                        :class="priorityConfig[event.priority].border"
                    >
                    <!-- Card top strip -->
                    <div class="h-1 w-full" :class="priorityConfig[event.priority].dot.replace('bg-', 'bg-') + ' opacity-60'" style="background: var(--strip-color);"
                        :style="`background-color: ${event.priority === 'A' ? '#ef4444' : event.priority === 'B' ? '#f59e0b' : '#9ca3af'}`"
                    />

                    <div class="p-4 sm:p-5">
                        <div class="flex items-start gap-3">
                            <!-- Priority badge -->
                            <div class="shrink-0 h-10 w-10 rounded-field flex items-center justify-center font-bold text-lg"
                                :class="[priorityConfig[event.priority].bg, priorityConfig[event.priority].text]">
                                {{ event.priority }}
                            </div>

                            <!-- Main info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="font-semibold text-ink leading-tight">{{ event.name }}</h3>
                                            <span v-if="event.plan_is_active" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink bg-accent-soft px-1.5 py-0.5 rounded-full">
                                                <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                                Aktiv
                                            </span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                                            <span class="text-sm text-ink-3">{{ formatDate(event.event_date) }}</span>
                                            <span class="text-xs font-medium text-accent-ink bg-accent-soft px-2 py-0.5 rounded-full">{{ event.distance_label }}</span>
                                            <span v-if="event.target_time_formatted" class="text-xs text-ink-3">
                                                Ziel: {{ event.target_time_formatted }}
                                            </span>
                                        </div>
                                    </div>
                                    <!-- Days until -->
                                    <div class="shrink-0 text-right">
                                        <span class="text-sm font-semibold"
                                            :class="event.days_until <= 7 ? 'text-danger-ink' : event.days_until <= 30 ? 'text-warn-ink' : 'text-ink-3'"
                                        >
                                            {{ daysLabel(event.days_until) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Notes -->
                                <p v-if="event.notes" class="mt-2 text-xs text-ink-3 line-clamp-1">{{ event.notes }}</p>

                                <!-- Actions -->
                                <div class="flex items-center gap-2 mt-3 flex-wrap">

                                    <!-- Past event: Auswertung only (no edit/delete) -->
                                    <template v-if="event.days_until < 0">
                                        <a v-if="event.plan_generated_at" :href="route('events.plan.show', event.id)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold bg-surface-2 text-ink-2 hover:bg-surface-3 transition-colors"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                                            Auswertung
                                        </a>
                                        <span class="text-xs text-ink-3 italic">Event vorbei</span>
                                    </template>

                                    <!-- Future events -->
                                    <template v-else>
                                        <!-- Case 1: This event has the active plan → show -->
                                        <template v-if="thisEventHasPlan(event)">
                                            <a :href="route('events.plan.show', event.id)"
                                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold bg-accent-soft text-accent-ink hover:opacity-90-soft transition-colors"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                                Plan ansehen
                                            </a>
                                        </template>

                                        <!-- Case 2: Another event has the active plan → locked -->
                                        <template v-else-if="anotherEventHasPlan(event)">
                                            <span class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-ink-3 bg-surface-2 cursor-not-allowed" title="Ein anderes Event hat bereits einen aktiven Plan">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" /></svg>
                                                Plan gesperrt
                                            </span>
                                        </template>

                                        <!-- Case 3: No active plan anywhere → create -->
                                        <template v-else>
                                            <a :href="route('events.plan.show', event.id)"
                                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                                                :class="event.plan_generated_at
                                                    ? 'bg-accent-soft text-accent-ink hover:opacity-90-soft'
                                                    : 'bg-accent text-white hover:opacity-90'"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                                {{ event.plan_generated_at ? 'Plan ansehen' : 'Plan erstellen' }}
                                            </a>
                                        </template>

                                        <button @click="openEdit(event)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-ink-2 bg-surface-2 hover:bg-surface-3 transition-colors"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                            Bearbeiten
                                        </button>

                                        <button @click="confirmingDelete = event"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-danger-ink bg-danger-soft hover:opacity-90-soft transition-colors"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                            Löschen
                                        </button>
                                    </template>

                                    <span v-if="event.plan_generated_at" class="ml-auto text-xs text-ink-3">
                                        Plan vom {{ event.plan_generated_at }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </SwipeRow>
            </div>

            <!-- ── Past events ────────────────────────────────────────────── -->
            <div v-if="pastEvents.length > 0" class="mt-8">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex-1 h-px bg-surface-3"></div>
                    <span class="text-xs font-semibold uppercase tracking-widest text-ink-3">Vergangene Events</span>
                    <div class="flex-1 h-px bg-surface-3"></div>
                </div>

                <div class="space-y-2 opacity-70">
                    <div
                        v-for="event in pastEvents"
                        :key="event.id"
                        class="bg-surface rounded-card border overflow-hidden grayscale-[30%]"
                        :class="priorityConfig[event.priority].border"
                    >
                        <div class="h-0.5 w-full" :style="`background-color: ${event.priority === 'A' ? '#ef4444' : event.priority === 'B' ? '#f59e0b' : '#9ca3af'}`" />

                        <div class="p-4 sm:p-5">
                            <div class="flex items-start gap-3">
                                <div class="shrink-0 h-9 w-9 rounded-field flex items-center justify-center font-bold text-base bg-surface-2 text-ink-3">
                                    {{ event.priority }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <h3 class="font-semibold text-ink-2 leading-tight">{{ event.name }}</h3>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                                                <span class="text-sm text-ink-3">{{ formatDate(event.event_date) }}</span>
                                                <span class="text-xs font-medium text-ink-3 bg-surface-2 px-2 py-0.5 rounded-full">{{ event.distance_label }}</span>
                                                <span v-if="event.target_time_formatted" class="text-xs text-ink-3">Ziel: {{ event.target_time_formatted }}</span>
                                            </div>
                                        </div>
                                        <span class="text-xs font-medium text-ink-3 shrink-0">Vorbei</span>
                                    </div>
                                    <p v-if="event.notes" class="mt-1.5 text-xs text-ink-3 line-clamp-1">{{ event.notes }}</p>
                                    <div class="flex items-center gap-2 mt-3">
                                        <a v-if="event.plan_generated_at" :href="route('events.plan.show', event.id)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold bg-surface-2 text-ink-3 hover:bg-surface-3 transition-colors"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                                            Auswertung
                                        </a>
                                        <button @click="confirmingDelete = event"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-danger bg-danger-soft hover:opacity-90-soft transition-colors"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                            Löschen
                                        </button>
                                        <span v-if="event.plan_generated_at" class="ml-auto text-xs text-ink-3">Plan vom {{ event.plan_generated_at }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
