<script setup>
import { ref, computed } from 'vue';

/**
 * Ein Verlauf wie in Health: oben groß die Zahl, darunter das Diagramm,
 * und ein Tipp auf einen Tag zeigt seinen Wert (28.10.2026).
 *
 * Vorher waren die Diagramme ein SVG mit 1000 Einheiten Breite, das auf
 * das iPhone herunterskaliert wurde — die Beschriftung kam dort mit gut
 * 4 px an, die Linie mit einem halben Pixel, und die Werte standen nur in
 * einem <title>, den ein Touchscreen nie zeigt. Jetzt sind Schrift, Balken
 * und Punkte HTML in echter Größe; nur die Linie ist SVG, mit
 * `non-scaling-stroke`, damit sie beim Strecken nicht dünner wird.
 *
 * points: [{ date, label, value, lo?, color?, note? }] — value null = keine Daten.
 */
const props = defineProps({
    points:    { type: Array,  required: true },
    kind:      { type: String, default: 'line' }, // line | bar | range
    unit:      { type: String, default: '' },
    color:     { type: String, default: 'rgb(var(--z-accent))' },
    min:       { type: Number, default: null },
    max:       { type: Number, default: null },
    /** Normalbereich als Band: { from, to }. */
    band:      { type: Object, default: null },
    /** Bezugslinie: { value, label }. */
    reference: { type: Object, default: null },
    /** Was oben steht, solange kein Tag gewählt ist: { label, value }. */
    summary:   { type: Object, default: null },
    format:    { type: Function, default: (v) => Math.round(v).toLocaleString('de-DE') },
});

const n = computed(() => props.points.length);
const valued = computed(() => props.points.filter(p => p.value != null));

function niceStep(x) {
    if (x <= 0) return 1;
    const pow = 10 ** Math.floor(Math.log10(x));
    const m = x / pow;
    return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * pow;
}

/** Wertebereich mit runden Teilstrichen. Linien zoomen auf die Werte, Balken beginnen bei 0. */
const scale = computed(() => {
    const vals = valued.value.flatMap(p => [p.value, p.lo]).filter(v => v != null);
    if (props.band) vals.push(props.band.from, props.band.to);
    if (props.reference) vals.push(props.reference.value);

    let lo = props.min ?? (props.kind === 'line' ? Math.min(...vals) : 0);
    let hi = props.max ?? Math.max(...vals, lo + 1);
    if (props.kind === 'line' && props.min == null && props.max == null) {
        const pad = Math.max((hi - lo) * 0.15, 2);
        lo -= pad; hi += pad;
    }
    const step = niceStep((hi - lo) / 3);
    if (props.min == null) lo = Math.floor(lo / step) * step;
    if (props.max == null) hi = Math.ceil(hi / step) * step;
    if (props.kind !== 'line' && lo < 0) lo = 0;

    const ticks = [];
    for (let v = lo; v <= hi + step / 2; v += step) ticks.push(v);
    return { lo, hi, ticks };
});

const yPct = (v) => (1 - (v - scale.value.lo) / ((scale.value.hi - scale.value.lo) || 1)) * 100;
const xPct = (i) => ((i + 0.5) / n.value) * 100;

/** Die Linie bricht an Tagen ohne Wert ab, statt eine Verbindung zu erfinden. */
const path = computed(() => {
    if (props.kind !== 'line') return '';
    let d = '', open = false;
    props.points.forEach((p, i) => {
        if (p.value == null) { open = false; return; }
        d += `${open ? 'L' : 'M'}${xPct(i).toFixed(2)} ${yPct(p.value).toFixed(2)} `;
        open = true;
    });
    return d;
});

/** Punkte nur, solange sie sich nicht drängen — bei wenigen Tagen trägt der Punkt die Aussage. */
const showDots = computed(() => n.value <= 21 || valued.value.length <= 3);

/** Tage beschriften: wenige alle, sonst Anfang, Mitte, Ende. */
const xLabels = computed(() => {
    const count = n.value;
    if (count <= 8) return props.points.map((p, i) => ({ i, t: p.label }));
    return [0, Math.floor((count - 1) / 2), count - 1].map(i => ({ i, t: props.points[i].label }));
});

const barWidth = computed(() => `min(${(70 / n.value).toFixed(2)}%, 28px)`);

/* ── Antippen ─────────────────────────────────────────────────────── */

const plot = ref(null);
const selected = ref(null);
const pressed = ref(false);

/** Der nächste Tag mit Wert unter dem Finger — ein Tipp auf eine Lücke trifft den Nachbarn. */
function pick(e) {
    const r = plot.value.getBoundingClientRect();
    const raw = Math.floor(((e.clientX - r.left) / r.width) * n.value);
    const at = Math.min(Math.max(raw, 0), n.value - 1);
    let best = null;
    props.points.forEach((p, i) => {
        if (p.value == null) return;
        if (best === null || Math.abs(i - at) < Math.abs(best - at)) best = i;
    });
    return best;
}

function onDown(e) {
    pressed.value = true;
    const i = pick(e);
    selected.value = (i === selected.value && e.pointerType !== 'mouse') ? null : i;
}
function onMove(e) {
    if (e.pointerType === 'mouse' || pressed.value) selected.value = pick(e);
}
function onUp() { pressed.value = false; }
function onLeave(e) {
    pressed.value = false;
    if (e.pointerType === 'mouse') selected.value = null;
}

const current = computed(() => (selected.value != null ? props.points[selected.value] : null));

function display(p) {
    return props.kind === 'range' && p.lo != null
        ? `${props.format(p.lo)}–${props.format(p.value)}`
        : props.format(p.value);
}

const header = computed(() => {
    if (current.value) {
        const d = new Date(current.value.date + 'T00:00:00');
        const date = current.value.title ?? d.toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });
        return { label: date, value: display(current.value), note: current.value.note };
    }
    if (props.summary) return { ...props.summary, note: null };
    const last = valued.value[valued.value.length - 1];
    return last ? { label: 'Zuletzt', value: display(last), note: null } : null;
});

const fill = (p) => p.color ?? props.color;
const dim = (i) => selected.value != null && selected.value !== i;
</script>

<template>
    <div>
        <!-- Die Zahl oben: Zusammenfassung, oder der getippte Tag -->
        <div v-if="header" class="mb-3 min-h-[52px]">
            <p class="text-[13px] font-semibold text-ink-3">{{ header.label }}</p>
            <p class="flex items-baseline gap-1.5">
                <span class="font-rounded text-[28px] font-bold leading-tight tabular-nums text-ink">{{ header.value }}</span>
                <span v-if="unit" class="text-[15px] font-semibold text-ink-3">{{ unit }}</span>
                <span v-if="header.note" class="ml-1 text-[15px] text-ink-3">{{ header.note }}</span>
            </p>
        </div>

        <div class="flex gap-2">
            <!-- Zeichenfläche -->
            <div ref="plot" class="relative h-40 flex-1 cursor-crosshair select-none [touch-action:pan-y]"
                @pointerdown="onDown" @pointermove="onMove" @pointerup="onUp" @pointercancel="onUp" @pointerleave="onLeave">
                <div v-for="t in scale.ticks" :key="t" class="pointer-events-none absolute inset-x-0 border-t border-line" :style="{ top: yPct(t) + '%' }" />

                <div v-if="band" class="pointer-events-none absolute inset-x-0 bg-success/15"
                    :style="{ top: yPct(band.to) + '%', height: (yPct(band.from) - yPct(band.to)) + '%' }" />

                <template v-if="reference">
                    <div class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-ink-3/60" :style="{ top: yPct(reference.value) + '%' }" />
                    <span v-if="reference.label" class="pointer-events-none absolute left-0 -translate-y-full pb-0.5 text-[12px] font-semibold text-ink-3" :style="{ top: yPct(reference.value) + '%' }">{{ reference.label }}</span>
                </template>

                <!-- Auswahl -->
                <div v-if="selected != null" class="pointer-events-none absolute inset-y-0 w-px -translate-x-1/2 bg-ink-2/60" :style="{ left: xPct(selected) + '%' }" />

                <!-- Balken -->
                <template v-if="kind !== 'line'">
                    <template v-for="(p, i) in points" :key="p.date">
                        <div v-if="p.value != null"
                            class="pointer-events-none absolute -translate-x-1/2 rounded-[4px] transition-opacity duration-150"
                            :style="{
                                left: xPct(i) + '%', width: barWidth,
                                top: yPct(p.value) + '%',
                                height: `max(3px, ${yPct(kind === 'range' ? (p.lo ?? scale.lo) : scale.lo) - yPct(p.value)}%)`,
                                background: fill(p), opacity: dim(i) ? 0.35 : 1,
                            }" />
                    </template>
                </template>

                <!-- Linie -->
                <template v-else>
                    <svg class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <path :d="path" fill="none" :stroke="color" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    </svg>
                    <template v-for="(p, i) in points" :key="p.date">
                        <span v-if="p.value != null && (showDots || i === selected)"
                            class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface"
                            :class="i === selected ? 'h-3.5 w-3.5' : 'h-2.5 w-2.5'"
                            :style="{ left: xPct(i) + '%', top: yPct(p.value) + '%', background: color }" />
                    </template>
                </template>
            </div>

            <!-- Achse rechts, wie in Health -->
            <div class="relative w-8 shrink-0">
                <span v-for="t in scale.ticks" :key="t" class="absolute left-0 -translate-y-1/2 text-[12px] tabular-nums text-ink-3" :style="{ top: yPct(t) + '%' }">{{ format(t) }}</span>
            </div>
        </div>

        <div class="relative mr-10 mt-1.5 h-4">
            <span v-for="l in xLabels" :key="l.i"
                class="absolute whitespace-nowrap text-[12px] tabular-nums text-ink-3"
                :class="n > 8 && l.i === 0 ? '' : n > 8 && l.i === n - 1 ? '-translate-x-full' : '-translate-x-1/2'"
                :style="{ left: (n > 8 && l.i === 0 ? 0 : n > 8 && l.i === n - 1 ? 100 : xPct(l.i)) + '%' }">{{ l.t }}</span>
        </div>
    </div>
</template>
