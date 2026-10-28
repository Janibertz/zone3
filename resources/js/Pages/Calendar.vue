<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SessionCard from '@/Components/UI/SessionCard.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import { sessionType } from '@/Composables/useSessionTypes';
import { activityType } from '@/Composables/useActivityTypes';
import { clock, speedOf, distanceOf } from '@/Composables/useFormat';
import SwipeRow from '@/Components/SwipeRow.vue';
import DragGhost from '@/Components/UI/DragGhost.vue';
import { useFinePointer } from '@/Composables/useFinePointer';
import { useDragToDay } from '@/Composables/useDragToDay';

/**
 * Kalender im Story-Look (Redesign 28.10.2026).
 *
 * Großer Monatstitel, das Raster als eine Karte mit einem Punkt je Tag in
 * der Farbe der Einheit — voll, wenn erledigt, blass, wenn sie noch kommt.
 * Darunter die Phasen bis zum Rennen als dünner Streifen und der gewählte
 * Tag. Vorher stand der Tag zweimal da (Seitenleiste und eine zweite Liste
 * nur fürs Telefon), dazu eine Legendenkarte der Phasen mit Wochenangaben.
 */
const props = defineProps({
    activities:       { type: Array, default: () => [] },
    events:           { type: Array, default: () => [] },
    trainingSessions: { type: Array, default: () => [] },
    phaseSegments:    { type: Array, default: () => [] },
});

const pad = (n) => String(n).padStart(2, '0');
const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;

const today    = new Date();
const todayKey = iso(today.getFullYear(), today.getMonth(), today.getDate());
const calYear  = ref(today.getFullYear());
const calMonth = ref(today.getMonth());
const selectedKey = ref(todayKey);

function shiftMonth(n) {
    const d = new Date(calYear.value, calMonth.value + n, 1);
    calYear.value = d.getFullYear();
    calMonth.value = d.getMonth();
    // Im heutigen Monat steht heute unten, sonst der Erste.
    selectedKey.value = calYear.value === today.getFullYear() && calMonth.value === today.getMonth()
        ? todayKey
        : iso(calYear.value, calMonth.value, 1);
}

const monthName = computed(() => new Date(calYear.value, calMonth.value, 1).toLocaleDateString('de-DE', { month: 'long' }));
const isCurrentMonth = computed(() => calYear.value === today.getFullYear() && calMonth.value === today.getMonth());

/* ── Daten je Tag ───────────────────────────────────────────────── */

function groupBy(list, keyOf) {
    const map = new Map();
    list.forEach((x) => {
        const k = keyOf(x);
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(x);
    });
    return map;
}

const activitiesByDay = computed(() => groupBy(props.activities, (a) => {
    const d = new Date(a.start_date);
    return iso(d.getFullYear(), d.getMonth(), d.getDate());
}));
const sessionsByDay = computed(() => groupBy(props.trainingSessions, (s) => s.planned_date));
const eventsByDay   = computed(() => groupBy(props.events, (e) => e.event_date));

/** Erste Einheit des Tages, die kein Ruhetag ist — sie gibt die Farbe. */
const mainSession = (sessions) => sessions.find((s) => s.type !== 'rest') ?? null;


/** Phase je Datum, ab heute aus den Segmenten des aktiven Plans. */
const PHASE_LABELS = { base: 'Grundlage', build: 'Aufbau', peak: 'Spitze', taper: 'Taper', race_week: 'Rennwoche' };
const PHASE_COLORS = { base: 'bg-success', build: 'bg-accent', peak: 'bg-warn', taper: 'bg-info', race_week: 'bg-danger' };

const phaseByDay = computed(() => {
    const map = new Map();
    const d = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    props.phaseSegments.forEach((seg) => {
        for (let i = 0; i < seg.days; i++) {
            map.set(iso(d.getFullYear(), d.getMonth(), d.getDate()), seg.key);
            d.setDate(d.getDate() + 1);
        }
    });
    return map;
});

const cells = computed(() => {
    const y = calYear.value;
    const m = calMonth.value;
    const lead = (new Date(y, m, 1).getDay() + 6) % 7;
    const days = new Date(y, m + 1, 0).getDate();
    const total = Math.ceil((lead + days) / 7) * 7;

    return Array.from({ length: total }, (_, i) => {
        const date = new Date(y, m, i - lead + 1);
        const key = iso(date.getFullYear(), date.getMonth(), date.getDate());
        const inMonth = date.getMonth() === m;
        const sessions = sessionsByDay.value.get(key) ?? [];
        const acts = activitiesByDay.value.get(key) ?? [];
        const event = (eventsByDay.value.get(key) ?? [])[0] ?? null;
        const main = mainSession(sessions);
        const done = acts.length > 0 || main?.status === 'completed';

        let dot = null;
        if (event) dot = sessionType('race_prep').dot;
        else if (main) dot = sessionType(main.type).dot;
        else if (acts.length) dot = 'bg-ink-3';

        return {
            key, inMonth, day: date.getDate(), sessions, acts, event, main,
            isToday: key === todayKey,
            dot,
            dotState: done || event ? 'done' : main?.status === 'skipped' ? 'skipped' : 'open',
        };
    });
});

const selected = computed(() => cells.value.find((c) => c.key === selectedKey.value) ?? null);

const selectedTitle = computed(() => {
    if (!selected.value) return '';
    return new Date(selected.value.key + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });
});

/**
 * Was die Tagesansicht zeigt. An einem Tag mit Aktivität steht die
 * Aktivität — die erledigte Einheit dazu wäre derselbe Lauf ein zweites
 * Mal. Ruhetage zeigen wir nur, wenn sonst nichts da ist.
 */
const daySessions = computed(() => {
    const c = selected.value;
    if (!c) return [];
    const list = c.acts.length ? c.sessions.filter((s) => s.status !== 'completed') : c.sessions;
    const real = list.filter((s) => s.type !== 'rest');
    return real.length ? real : list;
});

const dayIsEmpty = computed(() => selected.value && !selected.value.event && !selected.value.acts.length && !daySessions.value.length);

/* ── Phasenstreifen ─────────────────────────────────────────────── */

const monthPhases = computed(() => cells.value
    .filter((c) => c.inMonth)
    .map((c) => ({ key: c.key, phase: phaseByDay.value.get(c.key) ?? null })));

const hasPhases = computed(() => monthPhases.value.some((p) => p.phase));

const phaseLine = computed(() => {
    const seg = props.phaseSegments[0];
    if (!seg) return null;
    const next = props.phaseSegments[1];
    const weeks = Math.round(seg.days / 7);
    const left = seg.days < 14 ? `noch ${seg.days} ${seg.days === 1 ? 'Tag' : 'Tage'}` : `noch ${weeks} Wochen`;

    return next ? `${PHASE_LABELS[seg.key]} · ${left}, dann ${PHASE_LABELS[next.key]}` : `${PHASE_LABELS[seg.key]} · ${left}`;
});

/* ── Verschieben und Löschen per Geste ──────────────────────────── */
// Lange drücken auf eine Einheit in der Tagesansicht und nach oben ins
// Raster ziehen; nach links wischen löscht. Regel wie
// SessionScheduleService::canChange(): geplant, kein Ruhetag, kein Rennen.

/** Ein Tag im Raster ist ein Ziel, wenn er in diesem Monat liegt und nicht vorbei ist. */
const dropDate = (c) => (c.inMonth && c.key >= todayKey ? c.key : null);

const canChange = (s) => s.status === 'planned' && !['rest', 'race_prep'].includes(s.type) && s.planned_date >= todayKey;
const actionError = ref('');

function reloadSessions() {
    router.reload({ only: ['trainingSessions'], preserveScroll: true });
}

const { drag, start: startDrag } = useDragToDay(async (session, date) => {
    actionError.value = '';
    try {
        await axios.patch(route('training-sessions.move', session.id), { date });
        reloadSessions();
    } catch (e) {
        actionError.value = e?.response?.data?.error ?? 'Verschieben hat nicht geklappt.';
    }
});

// Am Desktop gibt es kein Wischen — dort ein Papierkorb an der Einheit.
const finePointer = useFinePointer();

async function deleteSession(session) {
    actionError.value = '';
    try {
        await axios.delete(route('training-sessions.destroy', session.id));
        reloadSessions();
    } catch (e) {
        actionError.value = e?.response?.data?.error ?? 'Löschen hat nicht geklappt.';
    }
}

function confirmDelete(session) {
    if (window.confirm(`„${session.title}“ löschen?`)) deleteSession(session);
}

function openSession(s) {
    if (s.event_id) router.visit(`/events/${s.event_id}/plan?open=${s.id}`);
}

/* ── Zeilen ─────────────────────────────────────────────────────── */

const actPrimary = (a) => {
    const d = distanceOf(a);
    return d ? `${d.value} ${d.unit}` : clock(a.moving_time);
};
const actSecondary = (a) => {
    if (!distanceOf(a)) return null;
    const s = speedOf(a);
    return s ? `${s.value} ${s.unit}` : clock(a.moving_time);
};
const actTime = (a) => new Date(a.start_date).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

const LEGEND = [
    { label: 'Locker', type: 'easy_run' },
    { label: 'Tempo', type: 'tempo_run' },
    { label: 'Intervalle', type: 'interval' },
    { label: 'Lang', type: 'long_run' },
    { label: 'Rennen', type: 'race_prep' },
];

</script>

<template>
    <Head title="Kalender – Zone3" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <header class="flex items-end justify-between gap-3 px-4 pb-2 pt-2 lg:px-8 lg:pt-8">
                <div class="min-w-0">
                    <p class="text-[15px] leading-5 text-ink-3">{{ calYear }}</p>
                    <h1 class="text-[34px] font-extrabold capitalize leading-[41px] tracking-[-0.035em] text-ink">{{ monthName }}</h1>
                </div>
                <div class="flex shrink-0 items-center pb-1">
                    <button v-if="!isCurrentMonth" type="button" class="mr-1 px-2 text-[17px] text-accent active:opacity-60" @click="shiftMonth((today.getFullYear() - calYear) * 12 + today.getMonth() - calMonth)">Heute</button>
                    <button type="button" aria-label="Vorheriger Monat" class="flex h-11 w-11 items-center justify-center rounded-full text-accent active:bg-surface-2" @click="shiftMonth(-1)">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    </button>
                    <button type="button" aria-label="Nächster Monat" class="flex h-11 w-11 items-center justify-center rounded-full text-accent active:bg-surface-2" @click="shiftMonth(1)">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>
                </div>
            </header>

            <div class="flex flex-col gap-6 px-4 pb-8 pt-3 lg:flex-row lg:items-start lg:px-8">
                <!-- ── Raster ───────────────────────────────────── -->
                <section class="rounded-card bg-surface px-2 pb-3 pt-3.5 lg:flex-1">
                    <div class="mb-1.5 grid grid-cols-7 text-center text-[13px] font-semibold text-ink-3">
                        <span v-for="d in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']" :key="d">{{ d }}</span>
                    </div>
                    <div class="grid grid-cols-7 gap-y-1.5">
                        <button v-for="c in cells" :key="c.key" type="button"
                            :data-drop-date="dropDate(c)"
                            :disabled="!c.inMonth"
                            :aria-label="new Date(c.key + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })"
                            :aria-pressed="c.key === selectedKey"
                            class="flex min-h-[48px] min-w-0 flex-col items-center gap-[5px] rounded-[12px] pt-1 transition-colors sm:min-h-[84px]"
                            :class="drag.overDate && drag.overDate === dropDate(c) ? 'bg-accent-soft ring-2 ring-inset ring-accent' : ''"
                            @click="selectedKey = c.key">
                            <span class="flex h-[30px] w-[30px] items-center justify-center rounded-full text-[17px] tabular-nums"
                                :class="c.key === selectedKey && !c.isToday ? 'bg-ink font-bold text-canvas'
                                    : c.isToday ? 'bg-accent font-bold text-white'
                                    : c.inMonth ? 'text-ink' : 'text-ink-3/60'">
                                {{ c.day }}
                            </span>
                            <span v-if="c.inMonth && c.dot" class="h-1.5 w-1.5 rounded-full"
                                :class="[c.dotState === 'skipped' ? 'bg-ink-3/40' : c.dot, c.dotState === 'open' ? 'opacity-40' : '']" />
                            <!-- Ab sm Platz für den Titel; am Desktop zieht man ihn direkt von hier. -->
                            <span v-if="c.inMonth && (c.event || c.main)"
                                class="hidden w-full truncate px-1 text-[12px] font-medium sm:block"
                                :class="[
                                    c.event ? sessionType('race_prep').text : sessionType(c.main.type).text,
                                    c.main && canChange(c.main) ? 'cursor-grab' : '',
                                    c.main && drag.active && drag.item?.id === c.main.id ? 'opacity-40' : '',
                                ]"
                                @mousedown="!c.event && c.main && canChange(c.main) && startDrag($event, c.main, c.main.planned_date, c.main.title)">
                                {{ c.event ? c.event.name : c.main.title }}
                            </span>
                        </button>
                    </div>

                    <div v-if="hasPhases" class="mx-2 mt-3">
                        <div class="flex h-1 gap-[2px]" aria-hidden="true">
                            <i v-for="p in monthPhases" :key="p.key" class="block flex-1 rounded-full" :class="p.phase ? PHASE_COLORS[p.phase] : 'bg-surface-2'" />
                        </div>
                        <p v-if="phaseLine" class="mt-1.5 text-[13px] text-ink-3">{{ phaseLine }}</p>
                    </div>
                </section>

                <!-- ── Gewählter Tag ────────────────────────────── -->
                <section v-if="selected" class="min-w-0 lg:w-[360px] lg:shrink-0">
                    <div class="mb-2.5 flex items-baseline justify-between gap-3">
                        <h2 class="text-[22px] font-bold leading-7 tracking-[-0.02em] text-ink">{{ selectedTitle }}</h2>
                        <span v-if="selected.isToday" class="shrink-0 text-[13px] font-semibold text-accent">Heute</span>
                    </div>

                    <p v-if="actionError" class="mb-3 rounded-field bg-danger-soft px-3 py-2 text-[13px] text-danger-ink">{{ actionError }}</p>

                    <div class="space-y-3">
                        <!-- Rennen -->
                        <Link v-if="selected.event" :href="`/events/${selected.event.id}/plan`"
                            class="block rounded-card bg-surface p-5 transition-transform active:scale-[0.98]">
                            <p class="flex items-center gap-1.5 text-[15px] font-semibold" :class="sessionType('race_prep').text">
                                <span class="h-2 w-2 rounded-full" :class="sessionType('race_prep').dot" />Rennen · Priorität {{ selected.event.priority }}
                            </p>
                            <h3 class="mt-1.5 text-[22px] font-bold leading-7 tracking-tight text-ink">{{ selected.event.name }}</h3>
                            <div class="mt-3 flex gap-6">
                                <div>
                                    <p class="text-[13px] text-ink-3">Distanz</p>
                                    <p class="font-rounded text-[22px] font-bold tabular-nums text-ink">{{ selected.event.distance_label }}</p>
                                </div>
                                <div v-if="selected.event.target_time_formatted">
                                    <p class="text-[13px] text-ink-3">Ziel</p>
                                    <p class="font-rounded text-[22px] font-bold tabular-nums text-ink">{{ selected.event.target_time_formatted }}</p>
                                </div>
                            </div>
                        </Link>

                        <!-- Einheiten -->
                        <SwipeRow v-for="s in daySessions" :key="s.id"
                            class="rounded-card"
                            content-class="bg-transparent"
                            :right-width="canChange(s) ? 88 : 0"
                            :disabled="!canChange(s) || drag.active">
                            <template #right="{ close }">
                                <button @click="deleteSession(s); close()"
                                    class="flex w-full flex-col items-center justify-center gap-1 bg-danger text-[11px] font-semibold text-white">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    Löschen
                                </button>
                            </template>
                            <div class="relative select-none"
                                :class="[drag.active && drag.item?.id === s.id ? 'opacity-40' : '', s.status === 'skipped' ? 'opacity-50' : '', s.event_id ? 'cursor-pointer' : '']"
                                :style="canChange(s) ? '-webkit-touch-callout: none' : null"
                                @touchstart="canChange(s) && startDrag($event, s, s.planned_date, s.title)"
                                @mousedown="canChange(s) && startDrag($event, s, s.planned_date, s.title)"
                                @contextmenu="canChange(s) && $event.preventDefault()"
                                @click="openSession(s)">
                                <SessionCard :session="s"
                                    :badge="s.status === 'completed' ? 'Erledigt' : s.status === 'skipped' ? 'Ausgelassen' : null"
                                    :badge-tone="s.status === 'completed' ? 'success' : 'neutral'" />
                                <button v-if="finePointer && canChange(s)" type="button"
                                    :aria-label="`${s.title} löschen`" title="Löschen"
                                    class="absolute right-3 top-3 rounded-full p-1.5 text-ink-3 transition hover:bg-danger-soft hover:text-danger-ink active:scale-95"
                                    @click.stop="confirmDelete(s)">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </div>
                        </SwipeRow>

                        <!-- Aktivitäten -->
                        <div v-if="selected.acts.length" class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                            <Link v-for="a in selected.acts" :key="a.id" :href="route('activities.show', a.id)"
                                class="flex min-w-0 items-center gap-3 px-4 py-3 transition-colors active:bg-surface-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-surface-2 text-ink-2">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true" v-html="activityType(a.type).icon" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[17px] text-ink">{{ a.name }}</span>
                                    <span class="block text-[13px] text-ink-3">{{ actTime(a) }} Uhr</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-rounded text-[17px] font-bold tabular-nums text-ink">{{ actPrimary(a) }}</span>
                                    <span v-if="actSecondary(a)" class="block text-[13px] tabular-nums text-ink-3">{{ actSecondary(a) }}</span>
                                </span>
                            </Link>
                        </div>

                        <p v-if="dayIsEmpty" class="rounded-card bg-surface px-4 py-6 text-center text-[15px] text-ink-3">Nichts geplant, nichts aufgezeichnet.</p>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-x-3.5 gap-y-1.5 text-[13px] text-ink-3">
                        <span v-for="l in LEGEND" :key="l.type" class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-full" :class="sessionType(l.type).dot" />{{ l.label }}
                        </span>
                    </div>
                </section>
            </div>
        </div>
        <DragGhost :drag="drag" />
    </AuthenticatedLayout>
</template>
