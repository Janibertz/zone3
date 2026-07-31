<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    metrics:    { type: Object,  default: null },   // { latest, series: [...] } up to 60 days
    activities: { type: Array,   default: () => [] }, // recovery activities (last 60 days)
    syncing:    { type: Boolean, default: false },
});
const emit = defineEmits(['refresh']);

// ── Bauhaus palette (a colour = a meaning, never a rank) ─────────────────────
const C = {
    hrv:    '#3D7BFF',  // blue  — hrv / volume
    rhr:    '#F0402F',  // red   — heart rate
    sleep:  '#FFC400',  // yellow— sleep
    energy: '#00C46A',  // green — energy / stress / body battery
    white:  '#FFFFFF',  // highlight (short nights, weak battery, last point, current week)
    grid:   '#26262c',
    ink:    '#F5F5F5',
    sub:    '#8a8a92',
    muted:  '#6c6c74',
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

// Full calendar over the selected range so missing days become gaps (not zeros).
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
const readiness    = computed(() => last('training_readiness'));
const readinessAvg  = computed(() => { const s = stat('training_readiness'); return s ? Math.round(s.mean) : null; });
const readinessDiff = computed(() => (readiness.value != null && readinessAvg.value != null) ? readiness.value - readinessAvg.value : null);
const readinessColor = computed(() => {
    const r = readiness.value ?? 0;
    return r >= 75 ? C.energy : r >= 50 ? C.sleep : r >= 25 ? '#F59E0B' : C.rhr;
});

// ── Tiles ────────────────────────────────────────────────────────────────────
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
    tile('hrv',         { label: 'hrv',      color: C.hrv,   unit: ' ms',  digits: 0 }),
    tile('resting_hr',  { label: 'ruhepuls', color: C.rhr,   unit: ' bpm', digits: 0, invert: true }),
    tile('sleep_hours', { label: 'schlaf',   color: C.sleep, unit: ' h',   digits: 1 }),
    tile('stress_avg',  { label: 'stress',   color: C.energy, unit: '',    digits: 0, invert: true }),
]);
function deltaClass(t) {
    if (t.delta == null) return C.muted;
    const good = t.invert ? t.delta < 0 : t.delta > 0;
    return good ? C.energy : C.rhr;
}
function fmtDelta(t) {
    const sign = t.delta > 0 ? '+' : '';
    return `${sign}${t.delta.toFixed(t.digits === 1 ? 1 : 1)} ggü. erster hälfte`;
}

// ── Line charts (HRV with baseline band, RHR) ────────────────────────────────
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
    // gridlines (3)
    const grid = [0.25, 0.5, 0.75].map(f => ({ y: padTop + (H - padTop - padBottom) * f, v: Math.round(hi - range * f) }));
    // x labels
    const step = Math.max(1, Math.ceil(n / 6));
    const xlabels = [];
    for (let i = 0; i < n; i += step) xlabels.push({ x: X(i), t: fmtDM(days.value[i].date) });
    const bandRect = band ? { y1: Y(s.mean + s.sd), y2: Y(s.mean - s.sd), mean: Y(s.mean) } : null;
    return { d, pts, W, H, padX, lo, hi, X, Y, lastPt, grid, xlabels, bandRect };
}
const hrvChart = computed(() => buildLine('hrv', { band: true }));
const rhrChart = computed(() => buildLine('resting_hr'));

// crosshair tooltip for line charts
const hover = ref(null); // { chart, x, y, v, date, unit }
function onMove(evt, chart, unit) {
    const svg = evt.currentTarget;
    const pt = svg.createSVGPoint(); pt.x = evt.clientX; pt.y = evt.clientY;
    const p = pt.matrixTransform(svg.getScreenCTM().inverse());
    let best = null, bd = 1e9;
    chart.pts.forEach(q => { if (!q) return; const dd = Math.abs(q.x - p.x); if (dd < bd) { bd = dd; best = q; } });
    if (best) hover.value = { x: best.x, y: best.y, v: best.v, date: best.date, unit, W: chart.W };
}
function onLeave() { hover.value = null; }

// ── Sleep bars (7h marker) ───────────────────────────────────────────────────
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
    // last with data
    let lastIdx = -1; bars.forEach((b, i) => { if (b) lastIdx = i; });
    return { W, H, bars, sevenY: Y(7), lastIdx };
});

// ── Body Battery range bars ──────────────────────────────────────────────────
const bbBars = computed(() => {
    const lows = series('body_battery_low'), highs = series('body_battery_high');
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

// ── Weekly km ────────────────────────────────────────────────────────────────
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
        v: map[wk], label: 'kw' + wk.slice(-2), current: wk === nowWk,
    }));
    return { W, H, bars };
});

// ── Logbook + sidebar figures ────────────────────────────────────────────────
function paceStr(a) {
    let mpk = null;
    if (a.moving_time && a.distance_km) mpk = (a.moving_time / 60) / a.distance_km;
    else if (a.speed) mpk = (1000 / a.speed) / 60;
    if (!mpk || !isFinite(mpk)) return '–';
    const m = Math.floor(mpk), s = Math.round((mpk - m) * 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}
function durStr(sec) {
    if (!sec) return '–';
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}
const logbook = computed(() => actsInRange.value); // already newest-first from backend
const dist7 = computed(() => {
    const cut = new Date(); cut.setHours(0, 0, 0, 0); cut.setDate(cut.getDate() - 6);
    return props.activities.filter(a => a.date && new Date(a.date + 'T00:00:00') >= cut)
        .reduce((s, a) => s + (a.distance_km || 0), 0);
});

const rawOpen = ref(false);
const dateSpan = computed(() => {
    const d = days.value;
    return d.length ? `${fmtDM(d[0].date)}.${new Date(d[0].date).getFullYear()} – ${fmtDM(d[d.length - 1].date)}.${new Date(d[d.length - 1].date).getFullYear()}` : '';
});
function nf(v, digits = 0) { return v == null ? '–' : Number(v).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits }); }
</script>

<template>
<div class="gr" v-if="metrics">
    <!-- Header -->
    <div class="gr-head">
        <div>
            <h3 class="gr-title"><span class="sq" :style="{ background: C.energy }"></span> erholung — garmin</h3>
            <p class="gr-sub">{{ dateSpan }} · {{ daysWithData }} tage mit werten</p>
        </div>
        <div class="gr-head-right">
            <button class="gr-refresh" :disabled="syncing" @click="emit('refresh')">{{ syncing ? 'sync…' : 'aktualisieren' }}</button>
            <div class="gr-toggle">
                <button v-for="r in RANGES" :key="r" @click="rangeDays = r" :class="{ on: rangeDays === r }">{{ r }} tage</button>
            </div>
        </div>
    </div>

    <div v-if="!hasData" class="gr-empty">
        noch keine garmin-daten. verbinde dich im profil und tippe auf „aktualisieren".
    </div>

    <template v-else>
        <!-- Readiness + aktuelle werte -->
        <div class="gr-grid2">
            <div class="gr-mod gr-readiness">
                <p class="gr-label"><span class="sq" :style="{ background: C.energy }"></span> training readiness</p>
                <template v-if="readiness != null">
                    <div class="gr-big" :style="{ color: readinessColor }">{{ readiness }}<span class="gr-big-unit"> / 100</span></div>
                    <p class="gr-stand">stand {{ dateSpan.split('–')[1]?.trim() || '' }}</p>
                    <div class="gr-scale">
                        <div class="gr-scale-fill" :style="{ width: readiness + '%', background: readinessColor }"></div>
                        <div class="gr-scale-mark" :style="{ left: readiness + '%' }"></div>
                    </div>
                    <div class="gr-scale-ax"><span>0</span><span>50</span><span>100</span></div>
                    <p class="gr-note">
                        Garmins Bereitschaftswert von 0 bis 100 fasst Schlaf, Erholung, HRV und Belastung zusammen.
                        <template v-if="readinessDiff != null">
                            Dein Wert liegt <strong :style="{ color: C.ink }">{{ Math.abs(readinessDiff) }} Punkte {{ readinessDiff >= 0 ? 'über' : 'unter' }}</strong>
                            deinem Durchschnitt ({{ readinessAvg }}). Die weiße Marke zeigt den aktuellen Wert.
                        </template>
                    </p>
                </template>
                <p v-else class="gr-nodata">keine readiness-daten</p>
            </div>

            <div class="gr-mod gr-side">
                <p class="gr-label"><span class="sq" :style="{ background: C.ink }"></span> aktuelle werte</p>
                <div class="gr-side-row"><span>schlaf</span><b>{{ nf(last('sleep_hours'), 1) }}<i> h</i></b></div>
                <div class="gr-side-row"><span>hrv</span><b>{{ nf(last('hrv')) }}<i> ms</i></b></div>
                <div class="gr-side-row"><span>ruhepuls</span><b>{{ nf(last('resting_hr')) }}<i> bpm</i></b></div>
                <div class="gr-side-row"><span>body battery</span><b>{{ nf(last('body_battery_high')) }}</b></div>
                <div class="gr-side-row"><span>distanz 7 tage</span><b>{{ nf(dist7, 1) }}<i> km</i></b></div>
            </div>
        </div>

        <!-- Four tiles -->
        <div class="gr-tiles">
            <div v-for="t in tiles" :key="t.label" class="gr-mod gr-tile" :class="{ hot: t.highlight }">
                <p class="gr-label"><span class="sq" :style="{ background: t.color }"></span> {{ t.label }}</p>
                <div class="gr-tile-val" :style="{ color: t.color }">{{ t.value == null ? '–' : Number(t.value).toFixed(t.digits) }}<i>{{ t.unit }}</i></div>
                <svg v-if="t.spark" viewBox="0 0 120 34" class="gr-spark" preserveAspectRatio="none">
                    <path :d="t.spark" fill="none" :stroke="t.color" stroke-width="1.5" />
                </svg>
                <p class="gr-tile-delta" :style="{ color: t.highlight ? deltaClass(t) : C.muted }">
                    {{ t.delta == null ? '—' : fmtDelta(t) }}
                </p>
            </div>
        </div>

        <!-- HRV with baseline band -->
        <div class="gr-mod" v-if="hrvChart">
            <p class="gr-label"><span class="sq" :style="{ background: C.hrv }"></span> hrv mit baseline-band</p>
            <p class="gr-cnote">Herzratenvariabilität. Das Band zeigt deinen Normalbereich (Mittelwert ± eine Standardabweichung) — Ausreißer fallen sofort auf.</p>
            <svg :viewBox="`0 0 ${hrvChart.W} ${hrvChart.H}`" class="gr-chart" @mousemove="onMove($event, hrvChart, ' ms')" @mouseleave="onLeave">
                <rect v-if="hrvChart.bandRect" :x="hrvChart.padX" :y="hrvChart.bandRect.y1" :width="hrvChart.W - hrvChart.padX*2" :height="Math.max(0, hrvChart.bandRect.y2 - hrvChart.bandRect.y1)" :fill="C.hrv" opacity="0.12" />
                <line v-if="hrvChart.bandRect" :x1="hrvChart.padX" :x2="hrvChart.W - hrvChart.padX" :y1="hrvChart.bandRect.mean" :y2="hrvChart.bandRect.mean" :stroke="C.hrv" stroke-width="1" opacity="0.4" />
                <line v-for="g in hrvChart.grid" :key="g.y" :x1="hrvChart.padX" :x2="hrvChart.W - hrvChart.padX" :y1="g.y" :y2="g.y" :stroke="C.grid" stroke-width="1" />
                <path :d="hrvChart.d" fill="none" :stroke="C.hrv" stroke-width="2" />
                <rect v-if="hrvChart.lastPt" :x="hrvChart.lastPt.x - 3" :y="hrvChart.lastPt.y - 3" width="6" height="6" :fill="C.white" />
                <text v-if="hrvChart.lastPt" :x="hrvChart.lastPt.x - 8" :y="hrvChart.lastPt.y - 8" :fill="C.white" font-size="13" text-anchor="end">{{ Math.round(hrvChart.lastPt.v) }} ms</text>
                <text v-for="l in hrvChart.xlabels" :key="l.x" :x="l.x" :y="hrvChart.H - 6" :fill="C.muted" font-size="11" text-anchor="middle">{{ l.t }}</text>
                <template v-if="hover">
                    <line :x1="hover.x" :x2="hover.x" y1="12" :y2="hrvChart.H - 22" :stroke="C.sub" stroke-width="1" opacity="0.5" />
                    <rect :x="Math.min(Math.max(hover.x - 34, 2), hover.W - 70)" y="2" width="68" height="18" :fill="C.grid" />
                    <text :x="Math.min(Math.max(hover.x, 36), hover.W - 36)" y="15" :fill="C.ink" font-size="11" text-anchor="middle">{{ fmtDM(hover.date) }}: {{ Math.round(hover.v) }}{{ hover.unit }}</text>
                </template>
            </svg>
        </div>

        <!-- Resting HR -->
        <div class="gr-mod" v-if="rhrChart">
            <p class="gr-label"><span class="sq" :style="{ background: C.rhr }"></span> ruhepuls</p>
            <p class="gr-cnote">Dein niedrigster Puls in Ruhe. Steigt er über mehrere Tage, steckt oft Trainingsbelastung, zu wenig Schlaf oder ein Infekt dahinter.</p>
            <svg :viewBox="`0 0 ${rhrChart.W} ${rhrChart.H}`" class="gr-chart" @mousemove="onMove($event, rhrChart, ' bpm')" @mouseleave="onLeave">
                <line v-for="g in rhrChart.grid" :key="g.y" :x1="rhrChart.padX" :x2="rhrChart.W - rhrChart.padX" :y1="g.y" :y2="g.y" :stroke="C.grid" stroke-width="1" />
                <path :d="rhrChart.d" fill="none" :stroke="C.rhr" stroke-width="2" />
                <rect v-if="rhrChart.lastPt" :x="rhrChart.lastPt.x - 3" :y="rhrChart.lastPt.y - 3" width="6" height="6" :fill="C.white" />
                <text v-if="rhrChart.lastPt" :x="rhrChart.lastPt.x - 8" :y="rhrChart.lastPt.y - 8" :fill="C.white" font-size="13" text-anchor="end">{{ Math.round(rhrChart.lastPt.v) }} bpm</text>
                <text v-for="l in rhrChart.xlabels" :key="l.x" :x="l.x" :y="rhrChart.H - 6" :fill="C.muted" font-size="11" text-anchor="middle">{{ l.t }}</text>
                <template v-if="hover">
                    <line :x1="hover.x" :x2="hover.x" y1="12" :y2="rhrChart.H - 22" :stroke="C.sub" stroke-width="1" opacity="0.5" />
                    <rect :x="Math.min(Math.max(hover.x - 38, 2), hover.W - 78)" y="2" width="76" height="18" :fill="C.grid" />
                    <text :x="Math.min(Math.max(hover.x, 40), hover.W - 40)" y="15" :fill="C.ink" font-size="11" text-anchor="middle">{{ fmtDM(hover.date) }}: {{ Math.round(hover.v) }}{{ hover.unit }}</text>
                </template>
            </svg>
        </div>

        <!-- Sleep -->
        <div class="gr-mod" v-if="sleepBars">
            <p class="gr-label"><span class="sq" :style="{ background: C.sleep }"></span> schlaf</p>
            <p class="gr-cnote">Schlafdauer pro Nacht. Die waagerechte Linie markiert 7 Stunden als grobe Orientierung. Weiß hervorgehobene Säulen sind kurze Nächte.</p>
            <svg :viewBox="`0 0 ${sleepBars.W} ${sleepBars.H}`" class="gr-chart">
                <line :x1="8" :x2="sleepBars.W - 8" :y1="sleepBars.sevenY" :y2="sleepBars.sevenY" :stroke="C.sub" stroke-width="1" opacity="0.5" />
                <text :x="12" :y="sleepBars.sevenY - 4" :fill="C.muted" font-size="10">7 stunden</text>
                <template v-for="(b, i) in sleepBars.bars" :key="i">
                    <rect v-if="b" :x="b.x" :y="b.y" :width="b.w" :height="b.h" :fill="b.short || i === sleepBars.lastIdx ? C.white : C.sleep">
                        <title>{{ fmtDM(b.date) }}: {{ b.v.toFixed(1) }} h</title>
                    </rect>
                </template>
            </svg>
        </div>

        <!-- Body battery -->
        <div class="gr-mod" v-if="bbBars">
            <p class="gr-label"><span class="sq" :style="{ background: C.energy }"></span> body battery — tagesspanne</p>
            <p class="gr-cnote">Energiespeicher von 0 bis 100. Jede Säule zeigt die Spanne eines Tages von tief nach hoch. Weiß markiert sind Tage unter 50.</p>
            <svg :viewBox="`0 0 ${bbBars.W} ${bbBars.H}`" class="gr-chart">
                <line :x1="8" :x2="bbBars.W - 8" :y1="bbBars.fiftyY" :y2="bbBars.fiftyY" :stroke="C.grid" stroke-width="1" />
                <template v-for="(b, i) in bbBars.bars" :key="i">
                    <rect v-if="b" :x="b.x" :y="b.y" :width="b.w" :height="b.h" :fill="b.weak ? C.white : C.energy">
                        <title>{{ fmtDM(b.date) }}: {{ b.lo ?? '?' }}–{{ b.hi }}</title>
                    </rect>
                </template>
            </svg>
        </div>

        <!-- Weekly km -->
        <div class="gr-mod" v-if="weeklyKm">
            <p class="gr-label"><span class="sq" :style="{ background: C.hrv }"></span> kilometer pro kalenderwoche</p>
            <p class="gr-cnote">Summe der Distanz deiner Einheiten, gebündelt pro Kalenderwoche. Die weiße Säule ist die laufende (noch unvollständige) Woche.</p>
            <svg :viewBox="`0 0 ${weeklyKm.W} ${weeklyKm.H}`" class="gr-chart">
                <template v-for="(b, i) in weeklyKm.bars" :key="i">
                    <rect :x="b.x" :y="b.y" :width="b.w" :height="b.h" :fill="b.current ? C.white : C.hrv">
                        <title>{{ b.label }}: {{ b.v.toFixed(1) }} km</title>
                    </rect>
                    <text :x="b.x + b.w / 2" :y="weeklyKm.H - 6" :fill="C.muted" font-size="10" text-anchor="middle">{{ b.label }}</text>
                </template>
            </svg>
        </div>

        <!-- Logbook -->
        <div class="gr-mod" v-if="logbook.length">
            <p class="gr-label"><span class="sq" :style="{ background: C.ink }"></span> logbuch der einheiten</p>
            <p class="gr-cnote">Deine Einheiten im gewählten Zeitraum, neueste zuerst. Pace in Minuten pro Kilometer.</p>
            <div class="gr-tablewrap">
                <table class="gr-table">
                    <thead><tr><th>datum</th><th>einheit</th><th class="r">km</th><th class="r">zeit</th><th class="r">pace</th><th class="r">puls</th></tr></thead>
                    <tbody>
                        <tr v-for="(a, i) in logbook" :key="i">
                            <td>{{ fmtDM(a.date) }}</td>
                            <td class="nm">{{ a.name }}</td>
                            <td class="r">{{ a.distance_km ? a.distance_km.toFixed(2) : '–' }}</td>
                            <td class="r">{{ durStr(a.moving_time) }}</td>
                            <td class="r">{{ paceStr(a) }}</td>
                            <td class="r">{{ a.avg_hr ? Math.round(a.avg_hr) : '–' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Raw data -->
        <div class="gr-mod">
            <button class="gr-raw-toggle" @click="rawOpen = !rawOpen">
                <span class="sq" :style="{ background: C.muted }"></span> rohdaten {{ rawOpen ? '▲' : '▼' }}
            </button>
            <p class="gr-cnote">Alle Werte des Zeitraums. „–" bedeutet keine Daten, nicht null.</p>
            <div v-if="rawOpen" class="gr-tablewrap">
                <table class="gr-table">
                    <thead><tr><th>datum</th><th class="r">hrv</th><th class="r">rhr</th><th class="r">schlaf</th><th class="r">bb</th><th class="r">stress</th><th class="r">schritte</th><th class="r">readiness</th></tr></thead>
                    <tbody>
                        <tr v-for="d in [...days].reverse()" :key="d.date">
                            <td>{{ fmtDM(d.date) }}</td>
                            <td class="r">{{ nf(d.hrv) }}</td>
                            <td class="r">{{ nf(d.resting_hr) }}</td>
                            <td class="r">{{ d.sleep_hours != null ? d.sleep_hours.toFixed(1) : '–' }}</td>
                            <td class="r">{{ d.body_battery_high != null ? `${d.body_battery_low ?? '?'}–${d.body_battery_high}` : '–' }}</td>
                            <td class="r">{{ nf(d.stress_avg) }}</td>
                            <td class="r">{{ nf(d.steps) }}</td>
                            <td class="r">{{ nf(d.training_readiness) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="gr-foot"><span>sport ki 01</span><span>garmin · nur lesend</span></div>
    </template>
</div>
</template>

<style scoped>
.gr {
    background: #08080A;
    border: 1px solid #26262c;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    color: #F5F5F5;
    letter-spacing: -0.01em;
}
.gr * { box-sizing: border-box; }
.sq { display: inline-block; width: 9px; height: 9px; margin-right: 6px; vertical-align: middle; }
.gr-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; border-bottom: 1px solid #26262c; flex-wrap: wrap; }
.gr-title { font-size: 14px; font-weight: 700; text-transform: lowercase; margin: 0; }
.gr-sub { font-size: 11px; color: #6c6c74; margin: 4px 0 0; }
.gr-head-right { display: flex; align-items: center; gap: 12px; }
.gr-refresh { font-size: 11px; color: #3D7BFF; background: transparent; border: 0; cursor: pointer; font-family: inherit; text-transform: lowercase; padding: 0; }
.gr-refresh:disabled { opacity: 0.5; }
.gr-toggle { display: flex; }
.gr-toggle button { font-size: 11px; color: #8a8a92; background: transparent; border: 1px solid #26262c; padding: 5px 10px; cursor: pointer; margin-left: -1px; }
.gr-toggle button.on { color: #08080A; background: #F5F5F5; border-color: #F5F5F5; font-weight: 700; }
.gr-empty, .gr-nodata, .gr-nodata { padding: 24px 18px; color: #6c6c74; font-size: 13px; }
.gr-mod { padding: 16px 18px; border-bottom: 1px solid #26262c; }
.gr-label { font-size: 11px; color: #8a8a92; text-transform: lowercase; margin: 0 0 6px; letter-spacing: 0; }
.gr-cnote { font-size: 11px; color: #6c6c74; line-height: 1.5; margin: 0 0 10px; max-width: 62ch; }
.tabular, .gr-big, .gr-tile-val, .gr-side-row b, .gr-table td.r, .gr-table th.r { font-variant-numeric: tabular-nums; }

.gr-grid2 { display: grid; grid-template-columns: 2fr 1fr; }
.gr-grid2 .gr-mod { border-bottom: 1px solid #26262c; }
.gr-grid2 .gr-mod + .gr-mod { border-left: 1px solid #26262c; }
.gr-big { font-size: 56px; font-weight: 800; line-height: 1; letter-spacing: -0.04em; margin-top: 4px; }
.gr-big-unit { font-size: 20px; font-weight: 400; color: #6c6c74; letter-spacing: 0; }
.gr-stand { font-size: 11px; color: #6c6c74; margin: 8px 0 12px; }
.gr-scale { position: relative; height: 8px; background: #141418; }
.gr-scale-fill { height: 100%; }
.gr-scale-mark { position: absolute; top: -2px; width: 3px; height: 12px; background: #FFFFFF; transform: translateX(-50%); }
.gr-scale-ax { display: flex; justify-content: space-between; font-size: 10px; color: #6c6c74; margin-top: 4px; }
.gr-note { font-size: 11px; color: #8a8a92; line-height: 1.5; margin: 12px 0 0; }
.gr-side-row { display: flex; align-items: baseline; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #1a1a1f; font-size: 12px; color: #8a8a92; }
.gr-side-row:last-child { border-bottom: 0; }
.gr-side-row b { font-size: 17px; font-weight: 700; color: #F5F5F5; }
.gr-side-row i { font-size: 11px; font-weight: 400; color: #6c6c74; font-style: normal; }

.gr-tiles { display: grid; grid-template-columns: repeat(4, 1fr); }
.gr-tile { border-bottom: 1px solid #26262c; }
.gr-tile + .gr-tile { border-left: 1px solid #26262c; }
.gr-tile.hot { background: #101014; }
.gr-tile-val { font-size: 26px; font-weight: 800; letter-spacing: -0.03em; margin: 2px 0 6px; }
.gr-tile-val i { font-size: 12px; font-weight: 400; color: #6c6c74; font-style: normal; }
.gr-spark { width: 100%; height: 34px; display: block; }
.gr-tile-delta { font-size: 11px; margin: 6px 0 0; }

.gr-chart { width: 100%; height: auto; display: block; }
.gr-tablewrap { overflow-x: auto; }
.gr-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.gr-table th { text-align: left; color: #6c6c74; font-weight: 600; text-transform: lowercase; padding: 6px 8px; border-bottom: 1px solid #26262c; position: sticky; top: 0; background: #08080A; }
.gr-table td { padding: 6px 8px; border-bottom: 1px solid #16161a; color: #c5c5cc; }
.gr-table td.nm { color: #F5F5F5; }
.gr-table .r { text-align: right; }
.gr-raw-toggle { background: transparent; border: 0; color: #8a8a92; font-size: 11px; text-transform: lowercase; cursor: pointer; padding: 0; margin-bottom: 6px; font-family: inherit; }

.gr-foot { display: flex; justify-content: space-between; font-size: 10px; color: #6c6c74; padding: 12px 18px; }

@media (max-width: 640px) {
    .gr-grid2 { grid-template-columns: 1fr; }
    .gr-grid2 .gr-mod + .gr-mod { border-left: 0; }
    .gr-tiles { grid-template-columns: repeat(2, 1fr); }
    .gr-tile:nth-child(3), .gr-tile:nth-child(4) { border-top: 1px solid #26262c; }
    .gr-tile:nth-child(odd) { border-left: 0; }
    .gr-big { font-size: 44px; }
}
@media (prefers-reduced-motion: reduce) { .gr * { transition: none !important; animation: none !important; } }
</style>
