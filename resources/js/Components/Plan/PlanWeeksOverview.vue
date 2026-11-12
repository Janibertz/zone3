<script setup>
/**
 * „Diese Woche“ und „Die nächsten Wochen“ (Mockup „Zone3 mit mehr Leben“,
 * 10.11.2026). Bis 12.11.2026 nur am Desktop; Jan: „Warum ist die in der App
 * nicht? Was heissen die Balken?“ Jetzt auch am Telefon, und jeder Abschnitt
 * der Wochenbalken erklärt sich: Hover (Maus) bzw. Antippen (Finger) zeigt
 * eine Blase mit Tag, Art, Dauer und Strecke; ein zweites Tippen bzw. ein
 * Klick öffnet die Details. Darunter eine Legende mit den Farben, die
 * tatsächlich vorkommen.
 *
 * In den Balken hat jede Farbe genau eine Bedeutung (Jan, 12.11.2026):
 * Kraft, Rumpf und Mobilität sind grau statt orange (sonst wie Tempo),
 * Alternativtraining (Rad, Schwimmen) ist lila gestreift (sonst wie der
 * lange Lauf).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';
import { useFinePointer } from '@/Composables/useFinePointer';

const props = defineProps({
    event:    { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
});
const emit = defineEmits(['open']);

const fine = useFinePointer();

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

const NON_RUN = ['strength', 'core', 'mobility', 'rest'];
const isRun = (s) => !s.sport_label && !NON_RUN.includes(s.type);
const isCross = (s) => s.type === 'cross_training' || !!s.sport_label;
const isGym = (s) => ['strength', 'core', 'mobility'].includes(s.type);

/** Gestreift statt einer zweiten Bedeutung für Lila. */
const STRIPES = 'repeating-linear-gradient(135deg, rgb(var(--z-info)) 0 4px, rgb(var(--z-info) / 0.45) 4px 8px)';

/** Farbe einer Einheit in Balken und Kacheln — eine Farbe, eine Bedeutung. */
function look(s) {
    if (isCross(s)) return { cls: '', style: { background: STRIPES }, key: 'cross', dark: false };
    if (isGym(s))   return { cls: 'bg-ink-3', style: null, key: 'gym', dark: false };
    const meta = sessionType(s.type);
    return { cls: meta.dot, style: null, key: meta.dot, dark: meta.accent !== 'neutral' };
}

const LEGEND = [
    { key: 'bg-danger',  label: 'Intervall' },
    { key: 'bg-warn',    label: 'Tempo' },
    { key: 'bg-success', label: 'Locker' },
    { key: 'bg-info',    label: 'Langer Lauf' },
    { key: 'bg-accent',  label: 'Rennen & Test' },
    { key: 'gym',        label: 'Kraft & Mobilität', cls: 'bg-ink-3' },
    { key: 'cross',      label: 'Rad & Schwimmen', style: { background: STRIPES } },
];

const sizeOf = (s) => s.duration_min || (s.distance_km ? s.distance_km * 6 : 20);

function typeLabel(s) {
    if (isCross(s)) return s.sport_label || 'Alternativtraining';
    return sessionType(s.type).label;
}

function describe(s) {
    const day = new Date(s.planned_date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' }).replace(/\.,?/, '');
    const parts = [day, typeLabel(s)];
    if (s.duration_min) parts.push(`${s.duration_min} min`);
    if (s.distance_km && isRun(s)) parts.push(`${fmtNum(s.distance_km)} km`);
    return parts.join(' · ');
}

// ── Diese Woche ─────────────────────────────────────────────────────────
const thisMonday = mondayOf(todayIso);

const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => {
    const date = addDays(thisMonday, i);
    const day = props.sessions.filter((s) => s.planned_date === date && s.status !== 'skipped');
    const main = day.find((s) => s.type !== 'rest' && isRun(s)) ?? day.find((s) => s.type !== 'rest') ?? null;
    const active = main && main.type !== 'rest';
    const lk = active ? look(main) : null;
    const done = main?.status === 'completed';
    const short = new Date(date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short' }).replace('.', '');
    let value = null; let unit = null;
    if (main?.distance_km && isRun(main)) { value = fmtNum(main.distance_km); unit = 'km'; }
    else if (main?.duration_min) { value = String(main.duration_min); unit = 'min'; }
    return {
        date, main, active, look: lk, done, value, unit, short,
        label: date === todayIso ? `${short} · heute` : short,
        today: date === todayIso,
        extra: day.filter((s) => s !== main && s.type !== 'rest').length,
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
        list = [...list].sort((a, b) => a.planned_date.localeCompare(b.planned_date) || (a.sort_order ?? 0) - (b.sort_order ?? 0));
        const km = list.filter(isRun).reduce((t, s) => t + (s.distance_km || 0), 0);
        const parts = list.map((s) => ({ session: s, look: look(s), size: sizeOf(s), text: describe(s) }));
        const size = parts.reduce((t, p) => t + p.size, 0);
        const offset = Math.round((new Date(key) - new Date(thisMonday)) / (7 * 86400000));
        const week = props.event.progress && !(props.event.progress.starts_in > 0) ? props.event.progress.week + offset : null;
        const date = new Date(key + 'T00:00:00').toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });
        const label = week ? `Woche ${week}` : `Ab ${date}`;
        // Am Telefon ist die Spalte schmal: „Ab 16. Nov.“ bräche um.
        const short = week ? `Wo. ${week}` : date;
        return { key, label, short, km, parts, size };
    });

    // Maßstab ist auch die laufende Woche, sonst füllt eine einzelne Einheit den ganzen Balken.
    const current = props.sessions
        .filter((s) => mondayOf(s.planned_date) === thisMonday && s.type !== 'rest' && s.status !== 'skipped')
        .reduce((t, s) => t + sizeOf(s), 0);
    const max = Math.max(1, current, ...weeks.map((w) => w.size));
    return weeks.map((w) => {
        let run = 0;
        const parts = w.parts.map((p) => {
            const center = ((run + p.size / 2) / max) * 100;
            run += p.size;
            return { ...p, center };
        });
        return { ...w, parts, rest: Math.max(0, max - w.size) };
    });
});

const legend = computed(() => {
    const keys = new Set(nextWeeks.value.flatMap((w) => w.parts.map((p) => p.look.key)));
    return LEGEND.filter((l) => keys.has(l.key));
});

// ── Erklärung je Abschnitt ──────────────────────────────────────────────
const picked = ref(null);   // per Tipp gewählt: { week, i }
const hovered = ref(null);  // unter der Maus
const shown = computed(() => (fine.value ? hovered.value ?? picked.value : picked.value));

const isShown = (wk, i) => shown.value?.week === wk && shown.value?.i === i;
const dimmed = (wk) => shown.value?.week === wk;
const shownPart = (w) => (shown.value?.week === w.key ? w.parts[shown.value.i] ?? null : null);

function tap(wk, i, part) {
    if (fine.value || isShown(wk, i)) {
        picked.value = null;
        emit('open', part.session);
        return;
    }
    picked.value = { week: wk, i };
}

function bubbleStyle(center) {
    if (center < 30) return { left: `${center}%`, transform: 'translateX(-14px)' };
    if (center > 70) return { left: `${center}%`, transform: 'translateX(calc(-100% + 14px))' };
    return { left: `${center}%`, transform: 'translateX(-50%)' };
}

const root = ref(null);
function outside(e) {
    if (picked.value && root.value && !e.target.closest?.('[data-week-bar]')) picked.value = null;
}
onMounted(() => document.addEventListener('click', outside));
onBeforeUnmount(() => document.removeEventListener('click', outside));
</script>

<template>
    <div ref="root" class="flex flex-col gap-6">
        <!-- Diese Woche -->
        <section aria-label="Diese Woche">
            <div class="mb-3 flex items-baseline justify-between gap-3">
                <h2 class="text-[22px] font-extrabold tracking-[-0.02em] text-ink">Diese Woche</h2>
                <span class="text-[15px] tabular-nums text-ink-3">{{ weekSummary }}</span>
            </div>
            <div class="grid grid-cols-7 gap-1.5 lg:gap-3">
                <component :is="d.active ? 'button' : 'div'" v-for="d in weekDays" :key="d.date"
                    :type="d.active ? 'button' : undefined" @click="d.active && emit('open', d.main)"
                    :aria-label="d.active ? `${d.label}: ${d.main.title}` : `${d.label}: Ruhetag`"
                    class="relative flex h-[92px] min-w-0 flex-col justify-between overflow-hidden rounded-[14px] p-2 text-left transition lg:h-[190px] lg:rounded-[18px] lg:p-3.5"
                    :class="[
                        d.done && d.look ? [d.look.cls, d.look.dark ? 'text-black' : 'text-white'] : 'bg-surface text-ink',
                        d.active ? 'active:scale-[0.97]' : '',
                        d.today ? 'ring-2 ring-accent ring-offset-2 ring-offset-canvas' : '',
                    ]"
                    :style="d.done && d.look ? d.look.style : null">
                    <span v-if="d.active && !d.done" aria-hidden="true" class="absolute inset-x-0 top-0 h-1" :class="d.look.cls" :style="d.look.style" />
                    <span class="flex items-center justify-between text-[12px] font-bold lg:text-[13px]" :class="d.done ? '' : 'text-ink-3'">
                        <span class="lg:hidden">{{ d.short }}</span>
                        <span class="hidden lg:inline">{{ d.label }}</span>
                        <svg v-if="d.done" class="h-3.5 w-3.5 lg:h-4 lg:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" role="img" aria-label="erledigt"><path d="M5 12l5 5L20 7" /></svg>
                    </span>
                    <span v-if="!d.active" class="text-[12px] font-bold text-ink-3 lg:text-[16px]">
                        <span class="lg:hidden">Ruhe</span><span class="hidden lg:inline">Ruhetag</span>
                    </span>
                    <span v-else class="min-w-0">
                        <span class="hidden text-[16px] font-extrabold leading-5 lg:line-clamp-3">{{ d.main.title }}</span>
                        <span v-if="d.value" class="block font-rounded text-[16px] font-extrabold leading-tight tabular-nums lg:mt-1 lg:text-[22px]">{{ d.value }}<span class="block text-[11px] font-semibold lg:inline lg:text-[12px]" :class="d.done ? '' : 'text-ink-3'"><span class="hidden lg:inline"> </span>{{ d.unit }}</span></span>
                        <span v-if="d.extra" class="mt-0.5 hidden text-[12px] font-semibold lg:block" :class="d.done ? 'opacity-75' : 'text-ink-3'">+ {{ d.extra }} weitere</span>
                    </span>
                </component>
            </div>
            <p class="mx-1 mt-2.5 text-[13px] text-ink-3">Erledigt ist ganz in der Farbe, geplant hat eine Farbkante oben.</p>
        </section>

        <!-- Die nächsten Wochen -->
        <section v-if="nextWeeks.length" aria-label="Die nächsten Wochen" class="rounded-card bg-surface px-4 py-5 lg:rounded-[24px] lg:px-7 lg:py-6">
            <h2 class="mb-4 text-[22px] font-extrabold tracking-[-0.02em] text-ink">Die nächsten Wochen</h2>
            <div class="flex flex-col gap-2 lg:gap-2.5">
                <div v-for="w in nextWeeks" :key="w.key" class="grid grid-cols-[64px_minmax(0,1fr)_56px] items-center gap-3 lg:grid-cols-[110px_minmax(0,1fr)_80px] lg:gap-4">
                    <span class="whitespace-nowrap text-[13px] font-bold text-ink lg:text-[15px]"><span class="lg:hidden">{{ w.short }}</span><span class="hidden lg:inline">{{ w.label }}</span></span>
                    <div class="relative flex h-8 gap-[3px]" data-week-bar>
                        <button v-for="(p, i) in w.parts" :key="i" type="button"
                            class="flex h-full min-w-[6px] items-center"
                            :style="{ flex: p.size }"
                            :aria-label="p.text"
                            @click.stop="tap(w.key, i, p)"
                            @mouseenter="fine && (hovered = { week: w.key, i })"
                            @mouseleave="fine && (hovered = null)">
                            <span class="block h-3.5 w-full transition-opacity duration-150"
                                :class="[p.look.cls, i === 0 ? 'rounded-l-[7px]' : '', i === w.parts.length - 1 && !w.rest ? 'rounded-r-[7px]' : '',
                                    dimmed(w.key) && !isShown(w.key, i) ? 'opacity-35' : '']"
                                :style="p.look.style" />
                        </button>
                        <span v-if="w.rest" class="flex h-full items-center" :style="{ flex: w.rest }" aria-hidden="true">
                            <span class="block h-3.5 w-full rounded-r-[7px] bg-surface-2" />
                        </span>

                        <Transition enter-active-class="transition duration-150 ease-out-strong" enter-from-class="opacity-0 translate-y-1"
                            leave-active-class="transition duration-100 ease-out-strong" leave-to-class="opacity-0">
                            <div v-if="shownPart(w)" :key="shown.i" role="status"
                                class="pointer-events-none absolute bottom-full z-20 mb-0.5 whitespace-nowrap rounded-[10px] bg-ink px-2.5 py-1.5 text-[13px] font-semibold text-canvas shadow-lg"
                                :style="bubbleStyle(shownPart(w).center)">
                                {{ shownPart(w).text }}
                                <span v-if="!fine" class="block text-[11px] font-medium opacity-70">Nochmal tippen für Details</span>
                            </div>
                        </Transition>
                    </div>
                    <span class="text-right text-[13px] font-bold tabular-nums text-ink lg:text-[15px]">{{ w.km ? fmtNum(w.km) + ' km' : '' }}</span>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line pt-3.5">
                <span v-for="l in legend" :key="l.key" class="flex items-center gap-1.5 text-[13px] text-ink-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="l.cls ?? l.key" :style="l.style" aria-hidden="true" />
                    {{ l.label }}
                </span>
            </div>
            <p class="mt-2 text-[13px] text-ink-3">
                Ein Abschnitt ist eine Einheit (Mo bis So), die Breite ihre Dauer. Rechts die Laufkilometer.
                {{ fine ? 'Mit der Maus darauf zeigen' : 'Antippen' }} für Details.
            </p>
        </section>
    </div>
</template>
