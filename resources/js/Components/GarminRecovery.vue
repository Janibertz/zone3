<script setup>
import { ref, computed } from 'vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    metrics:    { type: Object,  default: null },     // { latest, series: [...] } bis zu 60 Tage
    activities: { type: Array,   default: () => [] }, // Erholungsaktivitäten (letzte 60 Tage)
    syncing:    { type: Boolean, default: false },
});
const emit = defineEmits(['refresh']);

/**
 * Farben kommen aus den Design-Tokens, damit Hell und Dunkel automatisch
 * mitgehen. In SVG-Attributen brauchen wir sie als CSS-Funktion.
 */
const C = {
    hrv:    'rgb(var(--z-info))',
    rhr:    'rgb(var(--z-danger))',
    sleep:  'rgb(var(--z-accent))',
    energy: 'rgb(var(--z-success))',
    stress: 'rgb(var(--z-warn))',
    ink:    'rgb(var(--z-ink))',
    sub:    'rgb(var(--z-ink-2))',
    muted:  'rgb(var(--z-ink-3))',
    grid:   'rgb(var(--z-border))',
    mark:   'rgb(var(--z-ink))',
};

const rangeDays = ref(30);
const RANGES = [14, 30, 60];

function ymd(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function fmtDM(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}`;
}

const seriesByDate = computed(() => {
    const m = {};
    (props.metrics?.series ?? []).forEach(r => { m[r.date] = r; });
    return m;
});

// Volle Kalenderspanne, damit fehlende Tage Lücken werden statt Nullen.
const days = computed(() => {
    const out = [];
    const end = new Date(); end.setHours(0, 0, 0, 0);
    for (let i = rangeDays.value - 1; i >= 0; i--) {
        const d = new Date(end); d.setDate(end.getDate() - i);
        const r = seriesByDate.value[ymd(d)] || {};
        out.push({
            date: ymd(d),
            hrv:                r.hrv ?? null,
            resting_hr:         r.resting_hr ?? null,
            sleep_hours:        r.sleep_hours ?? null,
            sleep_score:        r.sleep_score ?? null,
            body_battery_low:   r.body_battery_low ?? null,
            body_battery_high:  r.body_battery_high ?? null,
            stress_avg:         r.stress_avg ?? null,
            steps:              r.steps ?? null,
            training_readiness: r.training_readiness ?? null,
        });
    }
    return out;
});

function series(key) { return days.value.map(d => d[key]).filter(v => v !== null && v !== undefined); }
function stat(key) {
    const a = series(key);
    if (!a.length) return null;
    const mean = a.reduce((s, v) => s + v, 0) / a.length;
    const sd = Math.sqrt(a.reduce((s, v) => s + (v - mean) ** 2, 0) / a.length);
    return { mean, sd, min: Math.min(...a), max: Math.max(...a), last: a[a.length - 1], n: a.length };
}
function last(key) { const a = series(key); return a.length ? a[a.length - 1] : null; }

const daysWithData = computed(() =>
    days.value.filter(d => ['hrv', 'resting_hr', 'sleep_hours', 'body_battery_high', 'stress_avg', 'steps', 'training_readiness']
        .some(k => d[k] !== null)).length);

const hasData = computed(() => daysWithData.value > 0 || props.activities.length > 0);

// ── Readiness ────────────────────────────────────────────────────────────────
const readiness     = computed(() => last('training_readiness'));
const readinessAvg  = computed(() => { const s = stat('training_readiness'); return s ? Math.round(s.mean) : null; });
const readinessDiff = computed(() => (readiness.value != null && readinessAvg.value != null) ? readiness.value - readinessAvg.value : null);
const readinessColor = computed(() => {
    const r = readiness.value ?? 0;
    return r >= 75 ? C.energy : r >= 50 ? C.sleep : r >= 25 ? C.stress : C.rhr;
});

// ── Kacheln ──────────────────────────────────────────────────────────────────
function spark(key) {
    const W = 120, H = 34, pad = 2;
    const s = stat(key);
    if (!s) return null;
    const range = (s.max - s.min) || 1;
    const n = days.value.length;
    let d = '', open = false;
    days.value.forEach((day, i) => {
        const v = day[key];
        if (v === null) { open = false; return; }
        const x = pad + (n > 1 ? i / (n - 1) : 0) * (W - pad * 2);
        const y = pad + (H - pad * 2) - ((v - s.min) / range) * (H - pad * 2);
        d += (open ? ' L ' : ' M ') + x.toFixed(1) + ' ' + y.toFixed(1);
        open = true;
    });
    return d;
}
function tile(key, opts) {
    const s = stat(key);
    const a = series(key);
    let delta = null, highlight = false;
    if (a.length >= 4) {
        const half = Math.floor(a.length / 2);
        const mean = arr => arr.reduce((x, y) => x + y, 0) / arr.length;
        delta = mean(a.slice(half)) - mean(a.slice(0, half));
        highlight = s.sd > 0 && Math.abs(delta) > 0.6 * s.sd;
    }
    return { ...opts, key, value: s ? s.last : null, spark: spark(key), delta, highlight };
}
const tiles = computed(() => [
    tile('hrv',         { label: 'HRV',       color: C.hrv,    unit: 'ms',  digits: 0 }),
    tile('resting_hr',  { label: 'Ruhepuls',  color: C.rhr,    unit: 'bpm', digits: 0, invert: true }),
    tile('sleep_hours', { label: 'Schlaf',    color: C.sleep,  unit: 'h',   digits: 1 }),
    tile('stress_avg',  { label: 'Stress',    color: C.stress, unit: '',    digits: 0, invert: true }),
]);
function deltaClass(t) {
    if (t.delta == null) return 'text-ink-3';
    const good = t.invert ? t.delta < 0 : t.delta > 0;
    return good ? 'text-success' : 'text-danger';
}
function fmtDelta(t) {
    const sign = t.delta > 0 ? '+' : '';
    return `${sign}${t.delta.toFixed(1)} zur ersten Hälfte`;
}

// ── Liniendiagramme (HRV mit Baseline-Band, Ruhepuls) ────────────────────────
function buildLine(key, { min = null, max = null, band = false } = {}) {
    const s = stat(key);
    if (!s || s.n < 2) return null;
    const W = 1000, H = 190, padX = 10, padTop = 16, padBottom = 24;
    const lo = min != null ? min : Math.floor(s.min - (s.max - s.min) * 0.1);
    const hi = max != null ? max : Math.ceil(s.max + (s.max - s.min) * 0.1);
    const range = (hi - lo) || 1;
    const n = days.value.length;
    const X = i => padX + (n > 1 ? i / (n - 1) : 0) * (W - padX * 2);
    const Y = v => padTop + (H - padTop - padBottom) - ((v - lo) / range) * (H - padTop - padBottom);
    let d = '', open = false; const pts = [];
    days.value.forEach((day, i) => {
        const v = day[key];
        if (v === null) { open = false; pts.push(null); return; }
        const x = X(i), y = Y(v);
        d += (open ? ' L ' : ' M ') + x.toFixed(1) + ' ' + y.toFixed(1);
        pts.push({ x, y, v, date: day.date }); open = true;
    });
    let lastPt = null; for (let i = pts.length - 1; i >= 0; i--) { if (pts[i]) { lastPt = pts[i]; break; } }
    const grid = [0.25, 0.5, 0.75].map(f => ({ y: padTop + (H - padTop - padBottom) * f, v: Math.round(hi - range * f) }));
    const step = Math.max(1, Math.ceil(n / 6));
    const xlabels = [];
    for (let i = 0; i < n; i += step) xlabels.push({ x: X(i), t: fmtDM(days.value[i].date) });
    const bandRect = band ? { y1: Y(s.mean + s.sd), y2: Y(s.mean - s.sd), mean: Y(s.mean) } : null;
    return { d, pts, W, H, padX, lo, hi, X, Y, lastPt, grid, xlabels, bandRect };
}
const hrvChart = computed(() => buildLine('hrv', { band: true }));
const rhrChart = computed(() => buildLine('resting_hr'));

// Fadenkreuz-Tooltip
const hover = ref(null);
function onMove(evt, chart, unit) {
    const svg = evt.currentTarget;
    const pt = svg.createSVGPoint(); pt.x = evt.clientX; pt.y = evt.clientY;
    const p = pt.matrixTransform(svg.getScreenCTM().inverse());
    let best = null, bd = 1e9;
    chart.pts.forEach(q => { if (!q) return; const dd = Math.abs(q.x - p.x); if (dd < bd) { bd = dd; best = q; } });
    if (best) hover.value = { x: best.x, y: best.y, v: best.v, date: best.date, unit, W: chart.W };
}
function onLeave() { hover.value = null; }

// ── Schlafbalken (7-Stunden-Marke) ───────────────────────────────────────────
const sleepBars = computed(() => {
    const s = stat('sleep_hours');
    if (!s) return null;
    const W = 1000, H = 170, padX = 8, padTop = 14, padBottom = 22;
    const hi = Math.max(s.max, 8);
    const n = days.value.length;
    const bw = (W - padX * 2) / n;
    const Y = v => padTop + (H - padTop - padBottom) - (v / hi) * (H - padTop - padBottom);
    const bars = days.value.map((day, i) => {
        const v = day.sleep_hours;
        if (v === null) return null;
        return { x: padX + i * bw + bw * 0.15, w: bw * 0.7, y: Y(v), h: (H - padTop - padBottom) - (Y(v) - padTop), v, date: day.date, short: v < 6 };
    });
    let lastIdx = -1; bars.forEach((b, i) => { if (b) lastIdx = i; });
    return { W, H, bars, sevenY: Y(7), lastIdx };
});

// ── Body Battery als Tagesspanne ─────────────────────────────────────────────
const bbBars = computed(() => {
    const highs = series('body_battery_high');
    if (!highs.length) return null;
    const W = 1000, H = 170, padX = 8, padTop = 14, padBottom = 22;
    const n = days.value.length;
    const bw = (W - padX * 2) / n;
    const Y = v => padTop + (H - padTop - padBottom) - (v / 100) * (H - padTop - padBottom);
    const bars = days.value.map((day, i) => {
        const lo = day.body_battery_low, hi = day.body_battery_high;
        if (hi === null) return null;
        const yTop = Y(hi), yBot = Y(lo ?? 0);
        return { x: padX + i * bw + bw * 0.15, w: bw * 0.7, y: yTop, h: Math.max(1, yBot - yTop), hi, lo, date: day.date, weak: hi < 50 };
    });
    return { W, H, bars, fiftyY: Y(50) };
});

// ── Kilometer pro Kalenderwoche ──────────────────────────────────────────────
function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const day = (t.getUTCDay() + 6) % 7; t.setUTCDate(t.getUTCDate() - day + 3);
    const first = new Date(Date.UTC(t.getUTCFullYear(), 0, 4));
    const week = 1 + Math.round(((t - first) / 86400000 - 3 + ((first.getUTCDay() + 6) % 7)) / 7);
    return `${t.getUTCFullYear()}-${String(week).padStart(2, '0')}`;
}
const rangeStart = computed(() => { const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - (rangeDays.value - 1)); return d; });
const actsInRange = computed(() => props.activities.filter(a => a.date && new Date(a.date + 'T00:00:00') >= rangeStart.value));
const weeklyKm = computed(() => {
    const map = {};
    actsInRange.value.forEach(a => { if (!a.distance_km) return; const wk = isoWeek(new Date(a.date + 'T00:00:00')); map[wk] = (map[wk] || 0) + a.distance_km; });
    const nowWk = isoWeek(new Date());
    const keys = Object.keys(map).sort();
    if (!keys.length) return null;
    const W = 1000, H = 150, padX = 8, padTop = 14, padBottom = 24;
    const hi = Math.max(...Object.values(map), 1);
    const bw = (W - padX * 2) / keys.length;
    const Y = v => padTop + (H - padTop - padBottom) - (v / hi) * (H - padTop - padBottom);
    const bars = keys.map((wk, i) => ({
        x: padX + i * bw + bw * 0.18, w: bw * 0.64, y: Y(map[wk]), h: (H - padTop - padBottom) - (Y(map[wk]) - padTop),
        v: map[wk], label: 'KW ' + wk.slice(-2), current: wk === nowWk,
    }));
    return { W, H, bars };
});

// ── Logbuch + Randwerte ──────────────────────────────────────────────────────
const dist7 = computed(() => {
    const cut = new Date(); cut.setHours(0, 0, 0, 0); cut.setDate(cut.getDate() - 6);
    return props.activities.filter(a => a.date && new Date(a.date + 'T00:00:00') >= cut)
        .reduce((s, a) => s + (a.distance_km || 0), 0);
});

const rawOpen = ref(false);
const dateSpan = computed(() => {
    const d = days.value;
    return d.length ? `${fmtDM(d[0].date)} – ${fmtDM(d[d.length - 1].date)}` : '';
});
/**
 * Zahl fuers Auge. Nicht darstellbare Werte werden zu „–" statt zu „NaN" —
 * genau das stand hier, weil unten das Ref selbst statt seines Werts
 * uebergeben wurde.
 */
function nf(v, digits = 0) {
    const n = Number(v);

    return (v == null || !Number.isFinite(n))
        ? '–'
        : n.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

const sideValues = computed(() => [
    { label: 'Schlaf',          value: nf(last('sleep_hours'), 1), unit: 'h'   },
    { label: 'HRV',             value: nf(last('hrv')),            unit: 'ms'  },
    { label: 'Ruhepuls',        value: nf(last('resting_hr')),     unit: 'bpm' },
    { label: 'Body Battery',    value: nf(last('body_battery_high')), unit: '' },
    { label: 'Distanz 7 Tage',  value: nf(dist7.value, 1),         unit: 'km'  },
]);
</script>

<template>
    <section v-if="metrics">
        <SectionHeader title="Erholung">
            <template #action>
                <AppButton size="sm" variant="secondary" :loading="syncing" @click="emit('refresh')">
                    {{ syncing ? 'Sync…' : 'Aktualisieren' }}
                </AppButton>
            </template>
        </SectionHeader>

        <AppCard v-if="!hasData">
            <p class="text-[15px] text-ink-3">
                Noch keine Garmin-Daten. Verbinde dich im Profil und tippe auf „Aktualisieren".
            </p>
        </AppCard>

        <div v-else class="space-y-4">

            <!-- Zeitraum -->
            <div class="flex items-center justify-between gap-3">
                <p class="text-[13px] text-ink-3">{{ dateSpan }} · {{ daysWithData }} Tage mit Werten</p>
                <div class="flex gap-1 rounded-full bg-surface-2 p-1">
                    <button v-for="r in RANGES" :key="r" @click="rangeDays = r"
                        class="rounded-full px-3 py-1.5 text-[13px] font-semibold transition-all"
                        :class="rangeDays === r ? 'bg-surface text-ink shadow-card' : 'text-ink-3 hover:text-ink-2'">
                        {{ r }} Tage
                    </button>
                </div>
            </div>

            <!-- Readiness + aktuelle Werte -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <AppCard class="lg:col-span-2">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Training Readiness</p>
                    <template v-if="readiness != null">
                        <p class="mt-1 flex items-baseline gap-2">
                            <span class="text-5xl font-bold tabular-nums tracking-tight" :style="{ color: readinessColor }">{{ readiness }}</span>
                            <span class="text-[15px] text-ink-3">/ 100</span>
                        </p>

                        <div class="relative mt-4 h-2 overflow-hidden rounded-full bg-surface-2">
                            <div class="h-full rounded-full" :style="{ width: readiness + '%', background: readinessColor }" />
                        </div>
                        <div class="mt-1.5 flex justify-between text-[11px] text-ink-3"><span>0</span><span>50</span><span>100</span></div>

                        <p class="mt-4 text-[13px] leading-relaxed text-ink-3">
                            Garmins Bereitschaftswert fasst Schlaf, Erholung, HRV und Belastung zusammen.
                            <template v-if="readinessDiff != null">
                                Dein Wert liegt
                                <strong class="text-ink">{{ Math.abs(readinessDiff) }} Punkte {{ readinessDiff >= 0 ? 'über' : 'unter' }}</strong>
                                deinem Schnitt ({{ readinessAvg }}).
                            </template>
                        </p>
                    </template>
                    <p v-else class="mt-2 text-[15px] text-ink-3">Keine Readiness-Daten</p>
                </AppCard>

                <AppCard flush>
                    <div class="divide-y divide-line">
                        <div v-for="row in sideValues" :key="row.label" class="flex items-baseline justify-between px-5 py-3">
                            <span class="text-[13px] text-ink-3">{{ row.label }}</span>
                            <span class="text-[17px] font-bold tabular-nums text-ink">
                                {{ row.value }}<span v-if="row.unit" class="ml-0.5 text-[12px] font-normal text-ink-3">{{ row.unit }}</span>
                            </span>
                        </div>
                    </div>
                </AppCard>
            </div>

            <!-- Vier Kacheln -->
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <AppCard v-for="t in tiles" :key="t.label">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">{{ t.label }}</p>
                    <p class="mt-1 flex items-baseline gap-1">
                        <span class="text-2xl font-bold tabular-nums tracking-tight" :style="{ color: t.color }">
                            {{ t.value == null ? '–' : Number(t.value).toFixed(t.digits) }}
                        </span>
                        <span v-if="t.unit" class="text-[12px] text-ink-3">{{ t.unit }}</span>
                    </p>
                    <svg v-if="t.spark" viewBox="0 0 120 34" class="mt-2 block h-8 w-full" preserveAspectRatio="none">
                        <path :d="t.spark" fill="none" :stroke="t.color" stroke-width="1.5" />
                    </svg>
                    <p class="mt-2 text-[11px]" :class="t.highlight ? deltaClass(t) : 'text-ink-3'">
                        {{ t.delta == null ? '—' : fmtDelta(t) }}
                    </p>
                </AppCard>
            </div>

            <!-- HRV -->
            <AppCard v-if="hrvChart" title="HRV mit Baseline-Band"
                subtitle="Das Band zeigt deinen Normalbereich — Ausreißer fallen sofort auf.">
                <svg :viewBox="`0 0 ${hrvChart.W} ${hrvChart.H}`" class="block h-auto w-full" @mousemove="onMove($event, hrvChart, ' ms')" @mouseleave="onLeave">
                    <rect v-if="hrvChart.bandRect" :x="hrvChart.padX" :y="hrvChart.bandRect.y1" :width="hrvChart.W - hrvChart.padX*2" :height="Math.max(0, hrvChart.bandRect.y2 - hrvChart.bandRect.y1)" :fill="C.hrv" opacity="0.12" />
                    <line v-if="hrvChart.bandRect" :x1="hrvChart.padX" :x2="hrvChart.W - hrvChart.padX" :y1="hrvChart.bandRect.mean" :y2="hrvChart.bandRect.mean" :stroke="C.hrv" stroke-width="1" opacity="0.4" />
                    <line v-for="g in hrvChart.grid" :key="g.y" :x1="hrvChart.padX" :x2="hrvChart.W - hrvChart.padX" :y1="g.y" :y2="g.y" :stroke="C.grid" stroke-width="1" />
                    <path :d="hrvChart.d" fill="none" :stroke="C.hrv" stroke-width="2" />
                    <circle v-if="hrvChart.lastPt" :cx="hrvChart.lastPt.x" :cy="hrvChart.lastPt.y" r="4" :fill="C.hrv" />
                    <text v-if="hrvChart.lastPt" :x="hrvChart.lastPt.x - 8" :y="hrvChart.lastPt.y - 10" :fill="C.ink" font-size="13" text-anchor="end">{{ Math.round(hrvChart.lastPt.v) }} ms</text>
                    <text v-for="l in hrvChart.xlabels" :key="l.x" :x="l.x" :y="hrvChart.H - 6" :fill="C.muted" font-size="11" text-anchor="middle">{{ l.t }}</text>
                    <template v-if="hover">
                        <line :x1="hover.x" :x2="hover.x" y1="12" :y2="hrvChart.H - 22" :stroke="C.sub" stroke-width="1" opacity="0.5" />
                        <text :x="Math.min(Math.max(hover.x, 40), hover.W - 40)" y="12" :fill="C.ink" font-size="12" text-anchor="middle">{{ fmtDM(hover.date) }}: {{ Math.round(hover.v) }}{{ hover.unit }}</text>
                    </template>
                </svg>
            </AppCard>

            <!-- Ruhepuls -->
            <AppCard v-if="rhrChart" title="Ruhepuls"
                subtitle="Steigt er über mehrere Tage, steckt oft Belastung, zu wenig Schlaf oder ein Infekt dahinter.">
                <svg :viewBox="`0 0 ${rhrChart.W} ${rhrChart.H}`" class="block h-auto w-full" @mousemove="onMove($event, rhrChart, ' bpm')" @mouseleave="onLeave">
                    <line v-for="g in rhrChart.grid" :key="g.y" :x1="rhrChart.padX" :x2="rhrChart.W - rhrChart.padX" :y1="g.y" :y2="g.y" :stroke="C.grid" stroke-width="1" />
                    <path :d="rhrChart.d" fill="none" :stroke="C.rhr" stroke-width="2" />
                    <circle v-if="rhrChart.lastPt" :cx="rhrChart.lastPt.x" :cy="rhrChart.lastPt.y" r="4" :fill="C.rhr" />
                    <text v-if="rhrChart.lastPt" :x="rhrChart.lastPt.x - 8" :y="rhrChart.lastPt.y - 10" :fill="C.ink" font-size="13" text-anchor="end">{{ Math.round(rhrChart.lastPt.v) }} bpm</text>
                    <text v-for="l in rhrChart.xlabels" :key="l.x" :x="l.x" :y="rhrChart.H - 6" :fill="C.muted" font-size="11" text-anchor="middle">{{ l.t }}</text>
                    <template v-if="hover">
                        <line :x1="hover.x" :x2="hover.x" y1="12" :y2="rhrChart.H - 22" :stroke="C.sub" stroke-width="1" opacity="0.5" />
                        <text :x="Math.min(Math.max(hover.x, 40), hover.W - 40)" y="12" :fill="C.ink" font-size="12" text-anchor="middle">{{ fmtDM(hover.date) }}: {{ Math.round(hover.v) }}{{ hover.unit }}</text>
                    </template>
                </svg>
            </AppCard>

            <!-- Schlaf + Body Battery nebeneinander ab lg -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <AppCard v-if="sleepBars" title="Schlaf"
                    subtitle="Die Linie markiert 7 Stunden. Hervorgehobene Säulen sind kurze Nächte.">
                    <svg :viewBox="`0 0 ${sleepBars.W} ${sleepBars.H}`" class="block h-auto w-full">
                        <line :x1="8" :x2="sleepBars.W - 8" :y1="sleepBars.sevenY" :y2="sleepBars.sevenY" :stroke="C.muted" stroke-width="1" opacity="0.5" stroke-dasharray="4 4" />
                        <text :x="12" :y="sleepBars.sevenY - 5" :fill="C.muted" font-size="11">7 Stunden</text>
                        <template v-for="(b, i) in sleepBars.bars" :key="i">
                            <rect v-if="b" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="2" :fill="b.short ? C.rhr : C.sleep" :opacity="b.short ? 1 : 0.85">
                                <title>{{ fmtDM(b.date) }}: {{ b.v.toFixed(1) }} h</title>
                            </rect>
                        </template>
                    </svg>
                </AppCard>

                <AppCard v-if="bbBars" title="Body Battery"
                    subtitle="Jede Säule ist die Tagesspanne von tief nach hoch. Rot markiert sind Tage unter 50.">
                    <svg :viewBox="`0 0 ${bbBars.W} ${bbBars.H}`" class="block h-auto w-full">
                        <line :x1="8" :x2="bbBars.W - 8" :y1="bbBars.fiftyY" :y2="bbBars.fiftyY" :stroke="C.grid" stroke-width="1" />
                        <template v-for="(b, i) in bbBars.bars" :key="i">
                            <rect v-if="b" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="2" :fill="b.weak ? C.rhr : C.energy">
                                <title>{{ fmtDM(b.date) }}: {{ b.lo ?? '?' }}–{{ b.hi }}</title>
                            </rect>
                        </template>
                    </svg>
                </AppCard>
            </div>

            <!-- Kilometer pro Woche -->
            <AppCard v-if="weeklyKm" title="Kilometer pro Kalenderwoche"
                subtitle="Die hervorgehobene Säule ist die laufende, noch unvollständige Woche.">
                <svg :viewBox="`0 0 ${weeklyKm.W} ${weeklyKm.H}`" class="block h-auto w-full">
                    <template v-for="(b, i) in weeklyKm.bars" :key="i">
                        <rect :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="3" :fill="C.hrv" :opacity="b.current ? 0.45 : 1">
                            <title>{{ b.label }}: {{ b.v.toFixed(1) }} km</title>
                        </rect>
                        <text :x="b.x + b.w / 2" :y="weeklyKm.H - 6" :fill="C.muted" font-size="11" text-anchor="middle">{{ b.label }}</text>
                    </template>
                </svg>
            </AppCard>

            <!-- Rohdaten -->
            <AppCard>
                <button class="flex items-center gap-1.5 text-[13px] font-semibold text-ink-2 transition-colors hover:text-ink"
                    @click="rawOpen = !rawOpen">
                    <svg class="h-4 w-4 transition-transform duration-200" :class="rawOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                    Rohdaten
                </button>
                <p class="mt-1 text-[13px] text-ink-3">„–" bedeutet keine Daten, nicht null.</p>

                <div v-if="rawOpen" class="-mx-1 mt-4 overflow-x-auto">
                    <table class="w-full min-w-[38rem] border-collapse text-[13px]">
                        <thead>
                            <tr class="text-ink-3">
                                <th class="px-2 py-2 text-left font-semibold">Datum</th>
                                <th class="px-2 py-2 text-right font-semibold">HRV</th>
                                <th class="px-2 py-2 text-right font-semibold">Ruhepuls</th>
                                <th class="px-2 py-2 text-right font-semibold">Schlaf</th>
                                <th class="px-2 py-2 text-right font-semibold">Battery</th>
                                <th class="px-2 py-2 text-right font-semibold">Stress</th>
                                <th class="px-2 py-2 text-right font-semibold">Schritte</th>
                                <th class="px-2 py-2 text-right font-semibold">Readiness</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="d in [...days].reverse()" :key="d.date">
                                <td class="whitespace-nowrap px-2 py-2 text-ink-3">{{ fmtDM(d.date) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.hrv) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.resting_hr) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ d.sleep_hours != null ? d.sleep_hours.toFixed(1) : '–' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ d.body_battery_high != null ? `${d.body_battery_low ?? '?'}–${d.body_battery_high}` : '–' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.stress_avg) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.steps) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.training_readiness) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </section>
</template>
