<script setup>
/**
 * Verlaufskurve der Aktivitätsseite (Mockup „Zone3 Einheiten mit Profil und
 * Soll/Ist“, Etappe 4): Pace, Puls oder Höhe über die Zeit. Zur Pace das
 * Zielband je Abschnitt in Zonenfarbe (aus `execution.windows`), gestrichelt
 * dieselbe Einheit beim letzten Mal. Antippen bzw. Hover zeigt die Werte an
 * der Stelle.
 *
 * Gezeichnet in einem SVG mit festem viewBox und `preserveAspectRatio=none`,
 * die Linien mit `non-scaling-stroke`; Beschriftungen liegen als HTML
 * darüber, damit sie nicht verzerren.
 */
import { computed, ref } from 'vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import { clock } from '@/Composables/useFormat';

const props = defineProps({
    chart:      { type: Object, required: true },
    windows:    { type: Array, default: null },
    comparison: { type: Object, default: null },
});

const W = 600;
const H = 180;

const ZONE_FILL = { 1: '--z-ink-3', 2: '--z-success', 3: '--z-accent', 4: '--z-warn', 5: '--z-danger' };

const METRICS = [
    { value: 'pace', label: 'Pace' },
    { value: 'hr', label: 'Puls' },
    { value: 'alt', label: 'Höhe' },
];
const options = computed(() => METRICS.filter((m) => props.chart[m.value]?.some((v) => v != null)));
const metric = ref(options.value[0]?.value ?? 'hr');

const pace = (s) => (s == null ? '–' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`);
const fmt = (m, v) => (v == null ? '–' : m === 'pace' ? `${pace(v)} /km` : m === 'hr' ? `${v} bpm` : `${Math.round(v)} m`);

const duration = computed(() => props.chart.duration || props.chart.t.at(-1) || 1);
const compare = computed(() => (props.comparison?.[metric.value] ? props.comparison : null));

const bands = computed(() => (metric.value === 'pace' && props.windows
    ? props.windows.filter((w) => w.range && w.to > w.from) : []));

// Wertebereich ohne Ausreißer (5.–95. Perzentil), mit Zielband und Vergleich.
const domain = computed(() => {
    const m = metric.value;
    const values = [...(props.chart[m] || []), ...(compare.value?.[m] || [])].filter((v) => v != null).sort((a, b) => a - b);
    if (!values.length) return [0, 1];
    const q = (p) => values[Math.min(values.length - 1, Math.floor(p * (values.length - 1)))];
    let lo = m === 'alt' ? values[0] : q(0.05);
    let hi = m === 'alt' ? values.at(-1) : q(0.95);
    for (const b of bands.value) { lo = Math.min(lo, b.range[0]); hi = Math.max(hi, b.range[1]); }
    const pad = Math.max((hi - lo) * 0.12, m === 'pace' ? 10 : m === 'hr' ? 4 : 5);
    return [lo - pad, hi + pad];
});

const x = (t) => (t / duration.value) * W;
// Pace: schneller ist oben.
const y = (v) => {
    const [lo, hi] = domain.value;
    const r = (Math.min(hi, Math.max(lo, v)) - lo) / (hi - lo || 1);
    return metric.value === 'pace' ? r * H : H - r * H;
};

const path = (t, values) => {
    let d = '';
    let pen = false;
    values.forEach((v, i) => {
        if (v == null || t[i] > duration.value) { pen = false; return; }
        d += `${pen ? 'L' : 'M'}${x(t[i]).toFixed(1)},${y(v).toFixed(1)}`;
        pen = true;
    });
    return d;
};

const line = computed(() => path(props.chart.t, props.chart[metric.value] || []));
const compareLine = computed(() => (compare.value ? path(compare.value.t, compare.value[metric.value]) : ''));
const area = computed(() => (metric.value === 'alt' && line.value ? `${line.value}L${W},${H}L0,${H}Z` : ''));

const stroke = computed(() => (metric.value === 'hr' ? 'rgb(var(--z-danger))' : metric.value === 'alt' ? 'rgb(var(--z-ink-3))' : 'rgb(var(--z-accent))'));

const yLabels = computed(() => {
    const [lo, hi] = domain.value;
    const top = metric.value === 'pace' ? lo : hi;
    const bottom = metric.value === 'pace' ? hi : lo;
    return [fmt(metric.value, Math.round(top)), fmt(metric.value, Math.round(bottom))];
});
const xLabels = computed(() => [0, duration.value / 2, duration.value].map((t) => clock(Math.round(t))));

// ── Antippen / Hover ──────────────────────────────────────────────
const box = ref(null);
const cursor = ref(null);

const nearest = (t, at) => {
    let best = 0;
    for (let i = 0; i < t.length; i++) if (Math.abs(t[i] - at) < Math.abs(t[best] - at)) best = i;
    return best;
};

const pick = (event) => {
    const rect = box.value?.getBoundingClientRect();
    if (!rect) return;
    const at = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)) * duration.value;
    const i = nearest(props.chart.t, at);
    const c = compare.value ? compare.value[metric.value][nearest(compare.value.t, at)] : null;
    cursor.value = { left: (props.chart.t[i] / duration.value) * 100, t: props.chart.t[i], v: props.chart[metric.value]?.[i], c };
};
const leave = () => { cursor.value = null; };
</script>

<template>
    <div class="rounded-card bg-surface p-4">
        <SegmentedControl v-if="options.length > 1" v-model="metric" :options="options" class="mb-3" />

        <div class="relative">
            <!-- Wert an der angetippten Stelle -->
            <div class="mb-2 flex h-[20px] items-baseline gap-2 text-[13px] tabular-nums">
                <template v-if="cursor">
                    <span class="text-ink-3">{{ clock(cursor.t) }}</span>
                    <span class="font-semibold text-ink">{{ fmt(metric, cursor.v) }}</span>
                    <span v-if="compare && cursor.c != null" class="text-ink-3">· letztes Mal {{ fmt(metric, cursor.c) }}</span>
                </template>
                <span v-else class="text-ink-3">Auf die Kurve tippen für die Werte</span>
            </div>

            <div
                ref="box"
                class="relative touch-pan-y select-none"
                @pointermove="pick"
                @pointerdown="pick"
                @pointerleave="leave"
            >
                <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="block h-[180px] w-full overflow-visible" role="img" :aria-label="`Verlauf ${options.find((o) => o.value === metric)?.label}`">
                    <line v-for="g in [0, H / 2, H]" :key="g" x1="0" :x2="W" :y1="g" :y2="g" stroke="rgb(var(--z-border))" stroke-width="1" vector-effect="non-scaling-stroke" />
                    <!-- Zielband je Abschnitt -->
                    <rect
                        v-for="(b, i) in bands"
                        :key="'b' + i"
                        :x="x(b.from)" :width="Math.max(1, x(b.to) - x(b.from))"
                        :y="Math.min(y(b.range[0]), y(b.range[1]))" :height="Math.max(2, Math.abs(y(b.range[1]) - y(b.range[0])))"
                        :fill="`rgb(var(${ZONE_FILL[b.zone] || '--z-success'}) / ${b.kind === 'work' ? 0.22 : 0.12})`"
                    />
                    <path v-if="area" :d="area" fill="rgb(var(--z-ink-3) / 0.18)" />
                    <path v-if="compareLine" :d="compareLine" fill="none" stroke="rgb(var(--z-ink-3))" stroke-width="1.5" stroke-dasharray="5 4" vector-effect="non-scaling-stroke" />
                    <path :d="line" fill="none" :stroke="stroke" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
                <span v-if="cursor" class="pointer-events-none absolute inset-y-0 w-px bg-ink/40" :style="{ left: cursor.left + '%' }" />
                <span class="pointer-events-none absolute left-1 top-0.5 rounded bg-surface/80 px-1 text-[11px] tabular-nums text-ink-3">{{ yLabels[0] }}</span>
                <span class="pointer-events-none absolute bottom-0.5 left-1 rounded bg-surface/80 px-1 text-[11px] tabular-nums text-ink-3">{{ yLabels[1] }}</span>
            </div>

            <div class="mt-1 flex justify-between text-[11px] tabular-nums text-ink-3">
                <span v-for="(l, i) in xLabels" :key="i">{{ l }}</span>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink-2">
            <span class="flex items-center gap-1.5"><span class="h-[2px] w-4 rounded-full" :style="{ background: stroke }" />heute</span>
            <span v-if="compare" class="flex items-center gap-1.5">
                <span class="w-4 border-t-[1.5px] border-dashed border-ink-3" />{{ comparison.date }}
            </span>
            <span v-if="bands.length" class="flex items-center gap-1.5"><span class="h-2.5 w-3 rounded-[3px] bg-success/30" />Zielbereich</span>
        </div>
    </div>
</template>
