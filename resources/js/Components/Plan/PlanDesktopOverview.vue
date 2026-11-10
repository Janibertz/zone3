<script setup>
/**
 * Planseite am Desktop (Mockup „Zone3 mit mehr Leben“, von Jan am 10.11.2026
 * abgenommen): oben ein Fotoband mit Phase, Ziel, Prognose und Tagen, darunter
 * die laufende Woche als sieben Karten (erledigt = ganz in der Typfarbe,
 * geplant = weiß mit Farbkante oben) und die folgenden Wochen als gestapelte
 * Balken. Die Liste darunter bleibt für Ziehen, Wischen und Details.
 *
 * Am Telefon unsichtbar — dort trägt die Rennkarte das Foto.
 */
import { computed } from 'vue';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    event:      { type: Object, required: true },
    sessions:   { type: Array, default: () => [] },
    prediction: { type: Object, default: null },
    isOpenGoal: { type: Boolean, default: false },
    sharing:    { type: Boolean, default: false },
});
defineEmits(['open', 'share']);

const PHASE_LABELS = { base: 'Grundlage', build: 'Aufbau', peak: 'Spitze', taper: 'Taper', race_week: 'Rennwoche' };

/** Ein Satz je Phase — fest, kein Modell. */
const PHASE_LINES = {
    base:      'Grundlage. Locker laufen, das Fundament wächst.',
    build:     'Aufbau. Die Schwelle wird dein Zuhause.',
    peak:      'Spitze. Jetzt läufst du dein Renntempo.',
    taper:     'Taper. Weniger Umfang, die Frische kommt.',
    race_week: 'Rennwoche. Locker bleiben, die Arbeit ist getan.',
};

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = iso(new Date());
function mondayOf(dateIso) {
    const d = new Date(dateIso + 'T00:00:00');
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    return iso(d);
}
function addDays(dateIso, n) {
    const d = new Date(dateIso + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return iso(d);
}

const phaseKey = computed(() => props.event.phase);
const headline = computed(() => props.isOpenGoal
    ? `${props.event.name}. Block ${props.event.block_number ?? 1}.`
    : (PHASE_LINES[phaseKey.value] ?? props.event.name));

const eyebrow = computed(() => {
    const date = new Date(props.event.event_date + 'T00:00:00').toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
    return props.isOpenGoal
        ? `${props.event.progress?.phase ?? 'Plan'} · Block endet am ${date}`
        : `${props.event.name} · ${date}`;
});

const stats = computed(() => {
    const out = [];
    const target = props.event.target_time_formatted?.replace(/\s*Std\.?$/, '');
    if (target && !props.isOpenGoal) out.push({ value: target, label: 'Ziel' });
    if (props.prediction?.time) out.push({ value: props.prediction.time, label: props.isOpenGoal ? 'Prognose 5 km' : 'Prognose heute' });
    if (props.event.days_until != null) out.push({ value: String(props.event.days_until), label: props.event.days_until === 1 ? 'Tag' : 'Tage' });
    return out;
});

const segments = computed(() => (props.event.phase_segments || []).map((s, i) => ({
    ...s, label: PHASE_LABELS[s.key] ?? s.key, current: i === 0,
})));

const isRun = (s) => !s.sport_label && !['strength', 'core', 'mobility', 'rest'].includes(s.type);

// ── Diese Woche ─────────────────────────────────────────────────────────
const thisMonday = mondayOf(todayIso);

const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => {
    const date = addDays(thisMonday, i);
    const day = props.sessions.filter((s) => s.planned_date === date && s.status !== 'skipped');
    const main = day.find((s) => s.type !== 'rest' && isRun(s)) ?? day.find((s) => s.type !== 'rest') ?? null;
    const meta = main ? sessionType(main.type) : null;
    const done = main?.status === 'completed';
    const label = new Date(date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short' }).replace('.', '');
    let value = null; let unit = null;
    if (main?.distance_km && isRun(main)) { value = fmtNum(main.distance_km); unit = 'km'; }
    else if (main?.duration_min) { value = String(main.duration_min); unit = 'min'; }
    return {
        date, main, meta, done, value, unit,
        label: date === todayIso ? `${label} · heute` : label,
        today: date === todayIso,
        extra: day.filter((s) => s !== main && s.type !== 'rest').length,
        darkText: meta && meta.accent !== 'neutral',
    };
}));

const weekSummary = computed(() => {
    const list = props.sessions.filter((s) => mondayOf(s.planned_date) === thisMonday && s.type !== 'rest' && s.status !== 'skipped');
    const km = list.filter(isRun).reduce((t, s) => t + (s.distance_km || 0), 0);
    const min = list.reduce((t, s) => t + (s.duration_min || 0), 0);
    const parts = [];
    if (km) parts.push(`${fmtNum(km)} km`);
    if (min) parts.push(`${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')} h`);
    return parts.join(' · ');
});

// ── Die nächsten Wochen ─────────────────────────────────────────────────
const nextWeeks = computed(() => {
    const groups = new Map();
    props.sessions
        .filter((s) => s.planned_date >= addDays(thisMonday, 7) && s.type !== 'rest' && s.status !== 'skipped')
        .forEach((s) => {
            const key = mondayOf(s.planned_date);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(s);
        });

    const weeks = [...groups.entries()].sort(([a], [b]) => a.localeCompare(b)).slice(0, 6).map(([key, list]) => {
        list = [...list].sort((a, b) => a.planned_date.localeCompare(b.planned_date));
        const km = list.filter(isRun).reduce((t, s) => t + (s.distance_km || 0), 0);
        const parts = list.map((s) => ({ dot: sessionType(s.type).dot, size: s.duration_min || (s.distance_km ? s.distance_km * 6 : 20) }));
        const size = parts.reduce((t, p) => t + p.size, 0);
        const offset = Math.round((new Date(key) - new Date(thisMonday)) / (7 * 86400000));
        const week = props.event.progress && !(props.event.progress.starts_in > 0) ? props.event.progress.week + offset : null;
        const label = week ? `Woche ${week}` : `Ab ${new Date(key + 'T00:00:00').toLocaleDateString('de-DE', { day: 'numeric', month: 'short' })}`;
        return { key, label, km, parts, size };
    });

    // Maßstab ist auch die laufende Woche, sonst füllt eine einzelne Einheit den ganzen Balken.
    const current = props.sessions
        .filter((s) => mondayOf(s.planned_date) === thisMonday && s.type !== 'rest' && s.status !== 'skipped')
        .reduce((t, s) => t + (s.duration_min || (s.distance_km ? s.distance_km * 6 : 20)), 0);
    const max = Math.max(1, current, ...weeks.map((w) => w.size));
    return weeks.map((w) => ({ ...w, rest: Math.max(0, max - w.size) }));
});
</script>

<template>
    <div class="hidden flex-col gap-6 lg:flex">
        <!-- Fotoband -->
        <section aria-label="Rennen und Phase"
            class="relative isolate flex min-h-[260px] flex-wrap items-end justify-between gap-8 overflow-hidden rounded-[28px] bg-black px-9 py-8 text-white">
            <img src="/images/moments/lang.jpg" alt="" aria-hidden="true" class="absolute inset-0 -z-10 h-full w-full object-cover object-[center_55%]" />
            <div class="absolute inset-0 -z-10" aria-hidden="true"
                style="background: linear-gradient(90deg, rgba(0,0,0,.82) 0%, rgba(0,0,0,.35) 60%, rgba(0,0,0,.1) 100%);" />

            <button type="button" :disabled="sharing" @click="$emit('share')"
                class="absolute right-6 top-6 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 backdrop-blur-md transition active:scale-90 disabled:opacity-60"
                aria-label="Plan als Story teilen">
                <svg v-if="!sharing" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" /></svg>
                <svg v-else class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            </button>

            <div class="max-w-[540px]">
                <p class="text-[13px] font-bold text-white/80">{{ eyebrow }}</p>
                <h2 class="mt-2 text-[44px] font-extrabold leading-[48px] tracking-[-0.04em] xl:text-[48px] xl:leading-[50px]">{{ headline }}</h2>
                <div v-if="stats.length" class="mt-5 flex gap-8">
                    <div v-for="s in stats" :key="s.label">
                        <p class="font-rounded text-[30px] font-extrabold tabular-nums">{{ s.value }}</p>
                        <p class="text-[13px] text-white/70">{{ s.label }}</p>
                    </div>
                </div>
            </div>

            <div v-if="segments.length" class="min-w-0 max-w-[460px] flex-[1_1_360px]">
                <div class="flex h-2.5 gap-1">
                    <span v-for="(seg, i) in segments" :key="seg.key" class="rounded-[5px]"
                        :class="seg.current ? 'bg-white' : 'bg-white/30'" :style="{ flex: Math.max(seg.days, 3) }" />
                </div>
                <div class="mt-2 flex gap-1 text-[12px] text-white/75">
                    <span v-for="seg in segments" :key="seg.key" class="min-w-0 truncate"
                        :class="seg.current ? 'font-bold text-white' : ''" :style="{ flex: Math.max(seg.days, 3) }">
                        {{ seg.current && event.progress?.week ? `${seg.label} · Woche ${event.progress.week}` : (seg.days >= 14 ? seg.label : '') }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Diese Woche -->
        <section aria-label="Diese Woche">
            <div class="mb-3 flex items-baseline justify-between">
                <h2 class="text-[22px] font-extrabold tracking-[-0.02em] text-ink">Diese Woche</h2>
                <span class="text-[15px] tabular-nums text-ink-3">{{ weekSummary }}</span>
            </div>
            <div class="grid grid-cols-7 gap-3">
                <component :is="d.main && d.main.type !== 'rest' ? 'button' : 'div'" v-for="d in weekDays" :key="d.date"
                    type="button" @click="d.main && d.main.type !== 'rest' && $emit('open', d.main)"
                    class="relative flex h-[190px] flex-col justify-between overflow-hidden rounded-[18px] p-3.5 text-left transition"
                    :class="[
                        d.done ? [d.meta.dot, d.darkText ? 'text-black' : 'text-white'] : 'bg-surface text-ink',
                        d.main && d.main.type !== 'rest' ? 'active:scale-[0.98]' : '',
                        d.today ? 'ring-2 ring-accent ring-offset-2 ring-offset-canvas' : '',
                    ]">
                    <span v-if="d.main && !d.done && d.main.type !== 'rest'" aria-hidden="true" class="absolute inset-x-0 top-0 h-1" :class="d.meta.dot" />
                    <span class="flex justify-between text-[13px] font-bold" :class="d.done ? '' : 'text-ink-3'">
                        <span>{{ d.label }}</span>
                        <svg v-if="d.done" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" role="img" aria-label="erledigt"><path d="M5 12l5 5L20 7" /></svg>
                    </span>
                    <span v-if="!d.main || d.main.type === 'rest'" class="text-[16px] font-bold text-ink-3">Ruhetag</span>
                    <span v-else>
                        <span class="line-clamp-3 block text-[16px] font-extrabold leading-5">{{ d.main.title }}</span>
                        <span v-if="d.value" class="mt-1 block font-rounded text-[22px] font-extrabold tabular-nums">{{ d.value }}<span class="text-[12px] font-semibold" :class="d.done ? '' : 'text-ink-3'"> {{ d.unit }}</span></span>
                        <span v-if="d.extra" class="mt-0.5 block text-[12px] font-semibold" :class="d.done ? 'opacity-75' : 'text-ink-3'">+ {{ d.extra }} weitere</span>
                    </span>
                </component>
            </div>
            <p class="mx-1 mt-2.5 text-[13px] text-ink-3">Erledigt ist ganz in der Farbe, geplant hat eine Farbkante oben.</p>
        </section>

        <!-- Die nächsten Wochen -->
        <section v-if="nextWeeks.length" aria-label="Die nächsten Wochen" class="rounded-[24px] bg-surface px-7 py-6">
            <h2 class="mb-4 text-[22px] font-extrabold tracking-[-0.02em] text-ink">Die nächsten Wochen</h2>
            <div class="flex flex-col gap-3.5">
                <div v-for="w in nextWeeks" :key="w.key" class="grid grid-cols-[110px_minmax(0,1fr)_80px] items-center gap-4">
                    <span class="text-[15px] font-bold text-ink">{{ w.label }}</span>
                    <div class="flex h-3.5 gap-[3px] overflow-hidden rounded-[7px]">
                        <span v-for="(p, i) in w.parts" :key="i" :class="p.dot" :style="{ flex: p.size }" />
                        <span v-if="w.rest" class="bg-surface-2" :style="{ flex: w.rest }" />
                    </div>
                    <span class="text-right text-[15px] font-bold tabular-nums text-ink">{{ w.km ? fmtNum(w.km) + ' km' : '' }}</span>
                </div>
            </div>
        </section>
    </div>
</template>
