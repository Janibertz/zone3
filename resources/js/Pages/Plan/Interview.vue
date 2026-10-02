<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
});

function next() {
    if (isLast.value) return submit();
    at.value++;
}

function submit() {
    busy.value = true;
    router.post(route('plan-interview.store'), form.value, {
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

    <AuthenticatedLayout>
        <div class="mx-auto max-w-2xl px-4 py-6 space-y-5">

            <div v-if="flash.success"
                class="rounded-field bg-success-soft px-4 py-3 text-[14px] text-success-ink">
                {{ flash.success }}
            </div>

            <!-- ── Kopf ─────────────────────────────────────────────── -->
            <div>
                <h1 class="text-[22px] font-bold text-ink">Vor dem nächsten Block</h1>
                <p class="mt-1 text-[14px] text-ink-3">
                    {{ steps.length }} kurze Fragen. Zone3 kennt deine Läufe, deine Werte und deine
                    Pace — gefragt wird nur, was in keiner Zahl steht.
                </p>

                <div v-if="event" class="mt-3 rounded-field bg-accent-soft px-3 py-2.5">
                    <p class="text-[13px] text-accent-ink">
                        Danach baut {{ coachName }} deinen Plan für
                        <span class="font-semibold">{{ event.name }}</span>
                        — {{ event.date_label }} · {{ event.distance }} · in {{ event.days_until }} Tagen.
                    </p>
                </div>
            </div>

            <!-- Fortschritt -->
            <div class="flex items-center gap-1.5">
                <div v-for="(s, i) in steps" :key="s.key"
                    class="h-1 flex-1 rounded-full transition-colors"
                    :class="i <= at ? 'bg-accent' : 'bg-surface-2'" />
            </div>
            <p class="-mt-3 text-[12px] text-ink-3">
                Schritt {{ at + 1 }} von {{ steps.length }} · {{ current.label }}
            </p>

            <!-- ══════════════════════════════════════════════════════
                 1 · RÜCKBLICK
                 ══════════════════════════════════════════════════════ -->
            <div v-if="current.key === 'review'" class="space-y-4">
                <div class="rounded-card bg-surface p-5 shadow-card space-y-4">
                    <div>
                        <p class="text-[13px] text-ink-3">Dein letzter Wettkampf</p>
                        <p class="text-[17px] font-semibold text-ink">{{ prefill.lastBlock.name }}</p>
                        <p class="text-[13px] text-ink-3">
                            {{ prefill.lastBlock.date_label }} · {{ prefill.lastBlock.distance }}
                            <span v-if="prefill.lastBlock.target_label"> · Ziel {{ prefill.lastBlock.target_label }}</span>
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Wie lange hast du gebraucht?
                        </label>
                        <div class="flex items-center gap-2">
                            <input v-model.number="form.actual_minutes" type="number" min="1" max="6000"
                                class="w-28 rounded-field border-line bg-canvas text-[15px] text-ink" />
                            <span class="text-[14px] text-ink-3">Minuten</span>
                            <span v-if="form.actual_minutes" class="text-[14px] font-medium text-ink">
                                = {{ asTime(form.actual_minutes) }}
                            </span>
                        </div>

                        <p v-if="prefill.lastBlock.measured_min" class="mt-1 text-[12px] text-ink-3">
                            Vorschlag aus deiner Strava-Aktivität an dem Tag — bitte prüfen.
                        </p>

                        <p v-if="vsTarget" class="mt-2 inline-block rounded-full px-2.5 py-1 text-[12px] font-medium"
                            :class="vsTarget.tone === 'success' ? 'bg-success-soft text-success-ink' : 'bg-warn-soft text-warn-ink'">
                            {{ vsTarget.label }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-ink-2">
                            Und wie war der Block insgesamt?
                        </label>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="r in ratings" :key="r.v"
                                class="rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors"
                                :class="form.block_rating === r.v
                                    ? 'bg-accent text-white'
                                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                                @click="form.block_rating = r.v">
                                {{ r.label }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Was hat getragen, was nicht? <span class="font-normal text-ink-3">(optional)</span>
                        </label>
                        <textarea v-model="form.block_note" rows="3" maxlength="2000"
                            placeholder="Die langen Läufe haben gesessen, die Intervalle sind mir schwergefallen …"
                            class="w-full rounded-field border-line bg-canvas text-[14px] text-ink placeholder:text-ink-3" />
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 2 · AUSFÄLLE
                 ══════════════════════════════════════════════════════ -->
            <div v-else-if="current.key === 'skips'" class="space-y-4">
                <div class="rounded-card bg-surface p-5 shadow-card space-y-4">
                    <div>
                        <p class="text-[13px] text-ink-3">Die letzten {{ prefill.adherence.days }} Tage</p>
                        <div class="mt-2 flex flex-wrap gap-5">
                            <div>
                                <p class="text-[20px] font-bold tabular-nums text-ink">{{ prefill.adherence.completed }}</p>
                                <p class="text-[12px] text-ink-3">absolviert</p>
                            </div>
                            <div>
                                <p class="text-[20px] font-bold tabular-nums"
                                   :class="prefill.adherence.skip_pct >= 25 ? 'text-warn-ink' : 'text-ink'">
                                    {{ prefill.adherence.skipped }}
                                </p>
                                <p class="text-[12px] text-ink-3">ausgelassen</p>
                            </div>
                            <div v-if="prefill.adherence.skip_pct">
                                <p class="text-[20px] font-bold tabular-nums text-ink-3">{{ prefill.adherence.skip_pct }} %</p>
                                <p class="text-[12px] text-ink-3">Quote</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-ink-2">
                            Woran lag es meistens?
                        </label>
                        <div class="space-y-1.5">
                            <button v-for="(label, key) in prefill.skipReasons" :key="key"
                                class="flex w-full items-center gap-2.5 rounded-field px-3 py-2.5 text-left text-[14px] transition-colors"
                                :class="form.skip_reason === key
                                    ? 'bg-accent-soft text-accent-ink'
                                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                                @click="form.skip_reason = key">
                                <span class="h-2 w-2 shrink-0 rounded-full"
                                    :class="form.skip_reason === key ? 'bg-accent' : 'bg-ink-3/40'" />
                                {{ label }}
                            </button>
                        </div>

                        <p class="mt-2 text-[12px] text-ink-3">
                            Das ändert etwas: wer aus Zeitmangel oder wegen des Körpers ausfällt,
                            bekommt einen kleineren Block statt desselben noch einmal.
                        </p>
                    </div>

                    <div v-if="form.skip_reason && form.skip_reason !== 'none'">
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Magst du das kurz ausführen? <span class="font-normal text-ink-3">(optional)</span>
                        </label>
                        <textarea v-model="form.skip_note" rows="2" maxlength="2000"
                            placeholder="Dienstags und donnerstags klappt es beruflich fast nie …"
                            class="w-full rounded-field border-line bg-canvas text-[14px] text-ink placeholder:text-ink-3" />
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 3 · ZEIT
                 ══════════════════════════════════════════════════════ -->
            <div v-else-if="current.key === 'time'" class="space-y-4">
                <div class="rounded-card bg-surface p-5 shadow-card space-y-4">
                    <div>
                        <p class="text-[13px] text-ink-3">Dein Wochenraster bisher</p>
                        <p class="text-[15px] text-ink">
                            {{ prefill.availability.days }} Tage offen, typisch {{ prefill.availability.minutes }} min
                        </p>
                        <p v-if="prefill.volume.has_data" class="text-[13px] text-ink-3">
                            Gelaufen: Ø {{ prefill.volume.avg }} km pro Woche
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-ink-2">
                            Wie viele Tage pro Woche willst du ab jetzt laufen?
                        </label>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="d in [2,3,4,5,6,7]" :key="d"
                                class="h-10 w-10 rounded-full text-[14px] font-medium transition-colors"
                                :class="form.days_per_week === d
                                    ? 'bg-accent text-white'
                                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                                @click="form.days_per_week = d">
                                {{ d }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Und wie viel Zeit hast du an einem normalen Trainingstag?
                        </label>
                        <div class="flex items-center gap-2">
                            <input v-model.number="form.minutes_per_day" type="number" min="20" max="600" step="5"
                                class="w-24 rounded-field border-line bg-canvas text-[15px] text-ink" />
                            <span class="text-[14px] text-ink-3">Minuten</span>
                        </div>
                        <p class="mt-1 text-[12px] text-ink-3">
                            Längere Tage, die schon im Raster stehen (etwa der Sonntag), bleiben länger.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Hat sich sonst etwas geändert? <span class="font-normal text-ink-3">(optional)</span>
                        </label>
                        <textarea v-model="form.changes_note" rows="2" maxlength="2000"
                            placeholder="Urlaub Ende Oktober, Knie zwickt beim Bergablaufen …"
                            class="w-full rounded-field border-line bg-canvas text-[14px] text-ink placeholder:text-ink-3" />
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 4 · ZIEL
                 ══════════════════════════════════════════════════════ -->
            <div v-else-if="current.key === 'goal'" class="space-y-4">
                <div class="rounded-card bg-surface p-5 shadow-card space-y-4">
                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-ink-2">
                            Worauf soll der nächste Block hinarbeiten?
                        </label>
                        <div class="space-y-1.5">
                            <button v-for="(label, key) in prefill.focusOptions" :key="key"
                                class="flex w-full items-center gap-2.5 rounded-field px-3 py-2.5 text-left text-[14px] transition-colors"
                                :class="form.focus === key
                                    ? 'bg-accent-soft text-accent-ink'
                                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                                @click="form.focus = key">
                                <span class="h-2 w-2 shrink-0 rounded-full"
                                    :class="form.focus === key ? 'bg-accent' : 'bg-ink-3/40'" />
                                {{ label }}
                            </button>
                        </div>
                    </div>

                    <!-- Rennen nur, wenn es darum geht. -->
                    <div v-if="form.focus === 'race'">
                        <p v-if="!prefill.upcoming.length" class="rounded-field bg-warn-soft px-3 py-2.5 text-[13px] text-warn-ink">
                            Du hast noch kein kommendes Rennen eingetragen. Leg es unter
                            <span class="font-medium">Events</span> an — dort gehören Datum, Distanz
                            und Zielzeit hin, und der Plan rechnet damit.
                        </p>

                        <div v-else class="space-y-1.5">
                            <label class="mb-1 block text-[13px] font-medium text-ink-2">Welches Rennen?</label>
                            <button v-for="e in prefill.upcoming" :key="e.id"
                                class="flex w-full items-baseline justify-between gap-2 rounded-field px-3 py-2.5 text-left transition-colors"
                                :class="form.event_id === e.id
                                    ? 'bg-accent-soft text-accent-ink'
                                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                                @click="form.event_id = e.id">
                                <span class="text-[14px] font-medium">{{ e.name }}</span>
                                <span class="shrink-0 text-[12px] text-ink-3">
                                    {{ e.date_label }} · {{ e.distance }} · in {{ e.days_until }} Tg
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 5 · SONST NOCH
                 ══════════════════════════════════════════════════════ -->
            <div v-else class="space-y-4">
                <div class="rounded-card bg-surface p-5 shadow-card space-y-3">
                    <div>
                        <label class="mb-1 block text-[13px] font-medium text-ink-2">
                            Was soll dein Coach sonst noch wissen?
                        </label>
                        <textarea v-model="form.free_note" rows="4" maxlength="2000"
                            placeholder="Ich will dieses Jahr endlich unter 20 Minuten auf 5 km, und Intervalle machen mir Spaß …"
                            class="w-full rounded-field border-line bg-canvas text-[14px] text-ink placeholder:text-ink-3" />
                    </div>

                    <p class="text-[12px] text-ink-3">
                        Alles, wofür es oben kein Feld gab. Es landet in den Coach-Notizen und
                        fließt in die Planung ein.
                    </p>
                </div>
            </div>

            <!-- ── Navigation ───────────────────────────────────────── -->
            <div class="flex items-center justify-between gap-3">
                <button v-if="at > 0"
                    class="rounded-field px-3 py-2 text-[14px] text-ink-3 hover:text-ink"
                    @click="at--">
                    Zurück
                </button>
                <span v-else />

                <div class="flex items-center gap-2">
                    <button v-if="!isLast"
                        class="rounded-field px-3 py-2 text-[14px] text-ink-3 hover:text-ink"
                        @click="at++">
                        Überspringen
                    </button>
                    <AppButton :disabled="busy" @click="next">
                        {{ isLast ? (event ? 'Fertig — zum Plan' : 'Fertig') : 'Weiter' }}
                    </AppButton>
                </div>
            </div>

            <p v-if="latest" class="text-center text-[12px] text-ink-3">
                Zuletzt geführt am {{ latest.at }}<span v-if="latest.focus_label"> · {{ latest.focus_label }}</span>
            </p>
        </div>
    </AuthenticatedLayout>
</template>
