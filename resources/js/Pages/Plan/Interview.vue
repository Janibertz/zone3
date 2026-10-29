<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    prefill: Object,
    latest:  Object,
    // Gesetzt, wenn der Weg über „Plan erstellen" unter Events kam.
    event:   Object,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});
const coachName = computed(() => page.props.coach?.name ?? 'dein Coach');

/*
 * Fünf Schritte, jeder mit einer Frage.
 *
 * Der Rückblick entfällt, wenn es keinen letzten Block gibt — wer zum ersten
 * Mal plant, soll nicht an einer Frage vorbei, die ihn nicht betrifft.
 */
const steps = computed(() => [
    ...(props.prefill.lastBlock ? [{ key: 'review', label: 'Rückblick' }] : []),
    { key: 'skips',  label: 'Ausfälle' },
    { key: 'time',   label: 'Zeit' },
    // Steht das Rennen schon fest, ist die Frage nach dem Ziel beantwortet.
    ...(props.event ? [] : [{ key: 'goal', label: 'Ziel' }]),
    { key: 'free',   label: 'Sonst noch' },
]);

const at      = ref(0);
const current = computed(() => steps.value[at.value]);
const isLast  = computed(() => at.value === steps.value.length - 1);
const busy    = ref(false);

const form = ref({
    reviewed_event_id: props.prefill.lastBlock?.id ?? null,
    // Der Vorschlag aus Strava steht drin, muss aber bestätigt werden.
    actual_minutes:    props.prefill.lastBlock?.measured_min ?? null,
    block_rating:      null,
    block_note:        '',

    skip_reason:       null,
    skip_note:         '',

    days_per_week:     props.prefill.availability?.days ?? null,
    minutes_per_day:   props.prefill.availability?.minutes ?? null,
    changes_note:      '',

    // Wer über „Plan erstellen" kommt, hat sein Ziel bereits gewählt — die
    // Frage danach wäre eine, die er gerade beantwortet hat.
    focus:             props.event ? 'race' : null,
    event_id:          props.event?.id ?? null,
    free_note:         '',

    // Ab wann der Plan beginnt. Davor steht nichts im Plan.
    plan_start_date:   props.event?.start_date ?? null,
});

const errors = computed(() => page.props.errors ?? {});

/** „Mo, 3. November" — oder „heute". */
const startLabel = computed(() => {
    const d = form.value.plan_start_date;
    if (!d || d === props.event?.start_min) return 'heute';
    return new Date(d + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'long' });
});

function next() {
    if (isLast.value) return submit();
    at.value++;
}

function submit() {
    busy.value = true;
    router.post(route('plan-interview.store'), form.value, {
        preserveState: true,
        // Ein ungültiges Startdatum steht im Schritt „Zeit" — dorthin zurück.
        onError: (e) => {
            if (e.plan_start_date) at.value = Math.max(0, steps.value.findIndex(s => s.key === 'time'));
        },
        onFinish: () => { busy.value = false; },
    });
}

/** „3:38 Std" aus Minuten — dieselbe Schreibweise wie im Event. */
function asTime(minutes) {
    if (!minutes) return '–';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')} Std` : `${m} Min`;
}

/** Zielzeit und gelaufene Zeit gegeneinander — die eigentliche Information. */
const vsTarget = computed(() => {
    const target = props.prefill.lastBlock?.target_min;
    const actual = form.value.actual_minutes;
    if (!target || !actual) return null;

    const diff = actual - target;
    if (Math.abs(diff) < 1) return { label: 'genau auf Zielzeit', tone: 'success' };

    return diff < 0
        ? { label: `${Math.abs(diff)} min unter Ziel`, tone: 'success' }
        : { label: `${diff} min über Ziel`, tone: 'warn' };
});

const QUESTION = {
    review: 'Wie lief dein letztes Rennen?',
    skips:  'Woran lag es, wenn eine Einheit ausfiel?',
    time:   'Wie viel Zeit hast du ab jetzt?',
    goal:   'Worauf soll der nächste Block hinarbeiten?',
    free:   'Was soll dein Coach sonst noch wissen?',
};

/** Schließen führt dorthin zurück, woher man kam — ohne Verlauf zu den Rennen. */
function close() {
    if (window.history.length > 1) window.history.back();
    else router.visit(route('events.index'));
}

const ratings = [
    { v: 5, label: 'Stark' },
    { v: 4, label: 'Gut' },
    { v: 3, label: 'Okay' },
    { v: 2, label: 'Zäh' },
    { v: 1, label: 'Schlecht' },
];
</script>

<template>
    <Head title="Vor dem nächsten Block" />

    <!--
        Vollbild ohne Tab-Leiste (Redesign Paket 3, 28.10.2026): die Leiste
        lag vorher über dem Weiter-Knopf. Eine große Frage je Schritt,
        Auswahl als Zeilen mit Haken, Weiter unten fest.
    -->
    <div class="min-h-screen bg-canvas text-ink">
        <div class="mx-auto flex max-w-xl items-center gap-3 px-4 pt-[max(env(safe-area-inset-top),16px)]">
            <button type="button" aria-label="Schließen"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-2 active:opacity-60"
                @click="close">
                <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m1 1 10 10M11 1 1 11" /></svg>
            </button>
            <div class="grid flex-1 gap-1" :style="{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }">
                <i v-for="(st, i) in steps" :key="st.key" class="block h-1 rounded-full transition-colors duration-200"
                    :class="i <= at ? 'bg-accent' : 'bg-surface-2'" />
            </div>
        </div>

        <main class="mx-auto max-w-xl space-y-6 px-4 pb-44 pt-8">
            <div v-if="flash.success" class="rounded-card bg-success-soft px-4 py-3 text-[15px] text-success-ink">{{ flash.success }}</div>

            <div>
                <p class="text-[15px] text-ink-3">{{ at + 1 }} von {{ steps.length }} · {{ current.label }}</p>
                <h1 class="mt-1.5 text-[30px] font-extrabold leading-[36px] tracking-[-0.03em] text-ink">{{ QUESTION[current.key] }}</h1>
                <p v-if="at === 0" class="mt-2 text-[15px] text-ink-3">
                    Zone3 kennt deine Läufe, Werte und Pace. Gefragt wird nur, was in keiner Zahl steht.
                    <template v-if="event"> Danach baut {{ coachName }} deinen Plan für {{ event.name }}.</template>
                </p>
            </div>

            <!-- 1 · Rückblick -->
            <template v-if="current.key === 'review'">
                <div class="rounded-card bg-surface p-4">
                    <p class="text-[17px] font-semibold text-ink">{{ prefill.lastBlock.name }}</p>
                    <p class="text-[13px] text-ink-3">
                        {{ prefill.lastBlock.date_label }} · {{ prefill.lastBlock.distance }}<span v-if="prefill.lastBlock.target_label"> · Ziel {{ prefill.lastBlock.target_label }}</span>
                    </p>
                    <label for="actual" class="mt-4 block text-[13px] font-semibold text-ink-3">Deine Zeit in Minuten</label>
                    <div class="mt-1 flex items-baseline gap-3">
                        <input id="actual" v-model.number="form.actual_minutes" type="number" inputmode="numeric" min="1" max="6000"
                            class="h-12 w-28 rounded-field border-0 bg-surface-2 text-[22px] font-bold tabular-nums text-ink focus:ring-2 focus:ring-accent" />
                        <span v-if="form.actual_minutes" class="font-rounded text-[17px] font-semibold text-ink">{{ asTime(form.actual_minutes) }}</span>
                        <span v-if="vsTarget" class="text-[15px]" :class="vsTarget.tone === 'success' ? 'text-success' : 'text-warn'">{{ vsTarget.label }}</span>
                    </div>
                    <p v-if="prefill.lastBlock.measured_min" class="mt-1.5 text-[13px] text-ink-3">Vorschlag aus der Aktivität an dem Tag, bitte prüfen.</p>
                </div>

                <section>
                    <h2 class="mx-1 mb-1.5 text-[13px] text-ink-3">Wie war der Block insgesamt?</h2>
                    <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                        <button v-for="r in ratings" :key="r.v" type="button" class="flex min-h-[48px] w-full items-center justify-between px-4 text-left text-[17px] text-ink active:bg-surface-2"
                            @click="form.block_rating = r.v">
                            {{ r.label }}
                            <svg v-if="form.block_rating === r.v" class="h-5 w-5 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                        </button>
                    </div>
                </section>

                <textarea v-model="form.block_note" rows="3" maxlength="2000" aria-label="Was hat getragen, was nicht?"
                    placeholder="Was hat getragen, was nicht? (optional)"
                    class="w-full resize-none rounded-card border-0 bg-surface px-4 py-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
            </template>

            <!-- 2 · Ausfälle -->
            <template v-else-if="current.key === 'skips'">
                <div class="grid grid-cols-3 rounded-card bg-surface p-4">
                    <div>
                        <p class="font-rounded text-[28px] font-bold tabular-nums text-ink">{{ prefill.adherence.completed }}</p>
                        <p class="text-[13px] text-ink-3">erledigt</p>
                    </div>
                    <div>
                        <p class="font-rounded text-[28px] font-bold tabular-nums" :class="prefill.adherence.skip_pct >= 25 ? 'text-warn' : 'text-ink'">{{ prefill.adherence.skipped }}</p>
                        <p class="text-[13px] text-ink-3">ausgelassen</p>
                    </div>
                    <div>
                        <p class="font-rounded text-[28px] font-bold tabular-nums text-ink">{{ prefill.adherence.skip_pct ?? 0 }}<span class="ml-0.5 text-[15px] text-ink-3">%</span></p>
                        <p class="text-[13px] text-ink-3">Quote, {{ prefill.adherence.days }} Tage</p>
                    </div>
                </div>

                <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                    <button v-for="(label, key) in prefill.skipReasons" :key="key" type="button"
                        class="flex min-h-[48px] w-full items-center justify-between gap-3 px-4 py-2 text-left text-[17px] text-ink active:bg-surface-2"
                        @click="form.skip_reason = key">
                        {{ label }}
                        <svg v-if="form.skip_reason === key" class="h-5 w-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                    </button>
                </div>
                <p class="mx-1 -mt-3 text-[13px] text-ink-3">Wer aus Zeitmangel oder wegen des Körpers ausfällt, bekommt einen kleineren Block statt desselben noch einmal.</p>

                <textarea v-if="form.skip_reason && form.skip_reason !== 'none'" v-model="form.skip_note" rows="2" maxlength="2000"
                    aria-label="Kurz ausführen" placeholder="Magst du das kurz ausführen? (optional)"
                    class="w-full resize-none rounded-card border-0 bg-surface px-4 py-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
            </template>

            <!-- 3 · Zeit -->
            <template v-else-if="current.key === 'time'">
                <p class="-mt-3 text-[15px] text-ink-3">
                    Bisher {{ prefill.availability.days }} Tage, typisch {{ prefill.availability.minutes }} min<template v-if="prefill.volume.has_data">, gelaufen Ø {{ prefill.volume.avg }} km pro Woche</template>.
                </p>

                <section>
                    <h2 class="mx-1 mb-1.5 text-[13px] text-ink-3">Tage pro Woche</h2>
                    <div class="grid grid-cols-6 gap-1 rounded-[10px] bg-surface-2 p-1">
                        <button v-for="d in [2,3,4,5,6,7]" :key="d" type="button"
                            class="h-10 rounded-[8px] text-[17px] font-semibold tabular-nums transition"
                            :class="form.days_per_week === d ? 'bg-surface text-ink shadow-card' : 'text-ink-2'"
                            @click="form.days_per_week = d">{{ d }}</button>
                    </div>
                </section>

                <section>
                    <label for="minutes" class="mx-1 mb-1.5 block text-[13px] text-ink-3">Zeit an einem normalen Trainingstag</label>
                    <div class="flex items-center gap-3 rounded-card bg-surface px-4 py-2">
                        <input id="minutes" v-model.number="form.minutes_per_day" type="number" inputmode="numeric" min="20" max="600" step="5"
                            class="h-11 w-24 rounded-field border-0 bg-surface-2 text-[22px] font-bold tabular-nums text-ink focus:ring-2 focus:ring-accent" />
                        <span class="text-[17px] text-ink-3">Minuten</span>
                    </div>
                    <p class="mx-1 mt-1.5 text-[13px] text-ink-3">Längere Tage im Raster, etwa der Sonntag, bleiben länger.</p>
                </section>

                <section v-if="event">
                    <label for="plan_start_date" class="mx-1 mb-1.5 block text-[13px] text-ink-3">Planstart</label>
                    <div class="flex items-center justify-between gap-3 rounded-card bg-surface px-4 py-2">
                        <span class="text-[17px] text-ink">{{ startLabel === 'heute' ? 'Heute' : startLabel }}</span>
                        <input id="plan_start_date" v-model="form.plan_start_date" type="date" :min="event.start_min" :max="event.start_max"
                            class="h-10 rounded-field border-0 bg-surface-2 text-[15px] text-ink focus:ring-2 focus:ring-accent" />
                    </div>
                    <p class="mx-1 mt-1.5 text-[13px] text-ink-3">Vor dem Start bleibt der Plan leer.</p>
                    <p v-if="errors.plan_start_date" class="mx-1 mt-1 text-[13px] text-danger">{{ errors.plan_start_date }}</p>
                </section>

                <textarea v-model="form.changes_note" rows="2" maxlength="2000" aria-label="Hat sich sonst etwas geändert?"
                    placeholder="Hat sich sonst etwas geändert? (optional)"
                    class="w-full resize-none rounded-card border-0 bg-surface px-4 py-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
            </template>

            <!-- 4 · Ziel -->
            <template v-else-if="current.key === 'goal'">
                <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                    <button v-for="(label, key) in prefill.focusOptions" :key="key" type="button"
                        class="flex min-h-[48px] w-full items-center justify-between gap-3 px-4 py-2 text-left text-[17px] text-ink active:bg-surface-2"
                        @click="form.focus = key">
                        {{ label }}
                        <svg v-if="form.focus === key" class="h-5 w-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                    </button>
                </div>

                <template v-if="form.focus === 'race'">
                    <p v-if="!prefill.upcoming.length" class="rounded-card bg-warn-soft px-4 py-3 text-[15px] text-warn-ink">
                        Du hast noch kein kommendes Rennen eingetragen. Leg es unter „Rennen“ an, dort gehören Datum, Distanz und Zielzeit hin.
                    </p>
                    <section v-else>
                        <h2 class="mx-1 mb-1.5 text-[13px] text-ink-3">Welches Rennen?</h2>
                        <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                            <button v-for="e in prefill.upcoming" :key="e.id" type="button"
                                class="flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2 text-left active:bg-surface-2"
                                @click="form.event_id = e.id">
                                <span class="min-w-0">
                                    <span class="block truncate text-[17px] text-ink">{{ e.name }}</span>
                                    <span class="block text-[13px] text-ink-3">{{ e.date_label }} · {{ e.distance }} · in {{ e.days_until }} Tagen</span>
                                </span>
                                <svg v-if="form.event_id === e.id" class="h-5 w-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                            </button>
                        </div>
                    </section>
                </template>
            </template>

            <!-- 5 · Sonst noch -->
            <template v-else>
                <textarea v-model="form.free_note" rows="5" maxlength="2000" aria-label="Was soll dein Coach sonst noch wissen?"
                    placeholder="Ich will endlich unter 20 Minuten auf 5 km, und Intervalle machen mir Spaß …"
                    class="w-full resize-none rounded-card border-0 bg-surface px-4 py-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
                <p class="mx-1 -mt-3 text-[13px] text-ink-3">Landet in den Coach-Notizen und fließt in die Planung ein.</p>
            </template>

            <p v-if="latest" class="text-center text-[13px] text-ink-3">
                Zuletzt geführt am {{ latest.at }}<span v-if="latest.focus_label"> · {{ latest.focus_label }}</span>
            </p>
        </main>

        <!-- Weiter unten fest -->
        <div class="fixed inset-x-0 bottom-0 z-10 bg-canvas/90 px-4 pb-[max(env(safe-area-inset-bottom),16px)] pt-3 backdrop-blur-xl">
            <div class="mx-auto max-w-xl space-y-1">
                <AppButton size="lg" block :loading="busy" :disabled="busy" @click="next">
                    {{ isLast ? (event ? 'Fertig, zum Plan' : 'Fertig') : 'Weiter' }}
                </AppButton>
                <div class="flex h-10 items-center justify-between">
                    <button v-if="at > 0" type="button" class="text-[15px] text-accent active:opacity-60" @click="at--">Zurück</button>
                    <span v-else />
                    <button v-if="!isLast" type="button" class="text-[15px] text-accent active:opacity-60" @click="at++">Überspringen</button>
                </div>
            </div>
        </div>
    </div>
</template>
