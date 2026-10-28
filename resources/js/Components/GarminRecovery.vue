<script setup>
import { ref, computed } from 'vue';
import AppCard from '@/Components/UI/AppCard.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import RecoveryChart from '@/Components/RecoveryChart.vue';
import { SLEEP_SCORE_ZONES, TONE_COLOR, zoneOf } from '@/Composables/useRecoveryGauges.js';

const props = defineProps({
    metrics:    { type: Object,  default: null },     // { latest, series: [...] } bis zu 60 Tage
    activities: { type: Array,   default: () => [] }, // Erholungsaktivitäten (letzte 60 Tage)
});

/**
 * Farben kommen aus den Design-Tokens, damit Hell und Dunkel automatisch
 * mitgehen. In SVG-Attributen brauchen wir sie als CSS-Funktion.
 */
const C = {
    hrv:    'rgb(var(--z-info))',
    rhr:    'rgb(var(--z-danger))',
    sleep:  'rgb(var(--z-accent))',
    energy: 'rgb(var(--z-success))',
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
            vo2max:             r.vo2max ?? null,
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

const daysWithData = computed(() =>
    days.value.filter(d => ['hrv', 'resting_hr', 'sleep_hours', 'sleep_score', 'body_battery_high', 'stress_avg', 'steps', 'vo2max']
        .some(k => d[k] !== null)).length);

const hasData = computed(() => daysWithData.value > 0 || props.activities.length > 0);

// ── Verläufe ────────────────────────────────────────────────────────────────
/**
 * Ein Diagramm über 60 Tage mit drei Werten ist eine leere Fläche mit einem
 * Strich. Solange weniger Tage da sind, zeigt es nur die Tage seit dem
 * ersten Wert, mindestens eine Woche.
 */
const chartDays = computed(() => {
    const first = days.value.findIndex(d => ['hrv', 'resting_hr', 'sleep_hours', 'sleep_score', 'body_battery_high']
        .some(k => d[k] !== null));
    if (first < 0) return days.value;

    return days.value.slice(Math.min(first, Math.max(0, days.value.length - 7)));
});

/** Bei einer Woche reicht der Wochentag; Datumsangaben stießen dort aneinander. */
const dayLabel = (date) => chartDays.value.length <= 8
    ? new Date(date + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short' }).replace('.', '')
    : fmtDM(date) + '.';
const avg = (key) => {
    const a = chartDays.value.map(d => d[key]).filter(v => v !== null);
    return a.length ? a.reduce((s, v) => s + v, 0) / a.length : null;
};
/** „Ø 3 Tage" zählt die Tage mit Wert, nicht die Breite des Diagramms. */
const avgLabel = (key) => {
    const n = chartDays.value.filter(d => d[key] !== null).length;
    return `Ø ${n} ${n === 1 ? 'Tag' : 'Tage'}`;
};
const hm = (h) => `${Math.floor(h)}:${String(Math.round((h % 1) * 60)).padStart(2, '0')} h`;

function line(key) {
    const points = chartDays.value.map(d => ({ date: d.date, label: dayLabel(d.date), value: d[key] }));
    const a = avg(key);

    return points.some(p => p.value !== null)
        ? { points, summary: { label: avgLabel(key), value: Math.round(a).toLocaleString('de-DE') } }
        : null;
}

const hrvChart = computed(() => {
    const c = line('hrv');
    const s = stat('hrv');
    // Der Normalbereich braucht ein paar Werte, sonst ist die Streuung Zufall.
    return c && { ...c, band: s && s.n >= 7 ? { from: s.mean - s.sd, to: s.mean + s.sd } : null };
});
const rhrChart = computed(() => line('resting_hr'));

// ── Schlaf: Schlafwert in Garmins Stufen, ohne Wert die Stunden ──────────────
const sleepChart = computed(() => {
    if (series('sleep_score').length) {
        const points = chartDays.value.map(d => ({
            date: d.date, label: dayLabel(d.date), value: d.sleep_score,
            color: d.sleep_score != null ? TONE_COLOR[zoneOf(SLEEP_SCORE_ZONES, d.sleep_score).tone] : null,
            note: d.sleep_score != null
                ? [zoneOf(SLEEP_SCORE_ZONES, d.sleep_score).label, d.sleep_hours != null ? hm(d.sleep_hours) : null].filter(Boolean).join(' · ')
                : null,
        }));
        return { score: true, points, summary: { label: avgLabel('sleep_score'), value: Math.round(avg('sleep_score')) } };
    }
    if (!series('sleep_hours').length) return null;

    const points = chartDays.value.map(d => ({
        date: d.date, label: dayLabel(d.date), value: d.sleep_hours,
        color: d.sleep_hours != null && d.sleep_hours < 6 ? C.rhr : C.sleep,
    }));
    return { score: false, points, summary: { label: avgLabel('sleep_hours'), value: avg('sleep_hours').toLocaleString('de-DE', { maximumFractionDigits: 1 }) } };
});

// ── Body Battery als Tagesspanne ─────────────────────────────────────────────
const bbChart = computed(() => {
    if (!series('body_battery_high').length) return null;
    const points = chartDays.value.map(d => ({
        date: d.date, label: dayLabel(d.date), value: d.body_battery_high, lo: d.body_battery_low,
        color: d.body_battery_high != null && d.body_battery_high < 50 ? C.rhr : C.energy,
    }));
    return { points, summary: { label: `Höchstwert, ${avgLabel('body_battery_high')}`, value: Math.round(avg('body_battery_high')) } };
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
    const points = keys.map(wk => ({
        date: wk, label: 'KW ' + Number(wk.slice(-2)), title: `KW ${Number(wk.slice(-2))}${wk === nowWk ? ' (läuft)' : ''}`,
        value: Math.round(map[wk] * 10) / 10,
        color: wk === nowWk ? 'rgb(var(--z-info) / 0.45)' : C.hrv,
    }));
    const done = keys.filter(k => k !== nowWk).map(k => map[k]);
    return {
        points,
        summary: done.length ? { label: 'Ø je Woche', value: (done.reduce((s, v) => s + v, 0) / done.length).toLocaleString('de-DE', { maximumFractionDigits: 1 }) } : null,
    };
});

// ── Rohdaten ─────────────────────────────────────────────────────────────────
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

</script>

<template>
    <section v-if="metrics">
        <!-- Kein „Aktualisieren“ mehr: die Werte schickt Garmin von selbst,
             sobald die Uhr synchronisiert. Der Knopf rief den alten Login ab. -->
        <SectionHeader title="Erholung" />

        <AppCard v-if="!hasData">
            <p class="text-[15px] text-ink-3">
                Noch keine Garmin-Daten. Sie kommen von selbst, sobald deine Uhr mit Garmin Connect synchronisiert.
            </p>
        </AppCard>

        <div v-else class="space-y-4">

            <!-- Zeitraum -->
            <div class="flex items-center justify-between gap-3">
                <p class="text-[13px] text-ink-3">{{ dateSpan }} · {{ daysWithData }} Tage mit Werten</p>
                <div class="flex gap-1 rounded-full bg-surface-2 p-1">
                    <button v-for="r in RANGES" :key="r" @click="rangeDays = r"
                        class="rounded-full px-3 py-1.5 text-[13px] font-semibold transition"
                        :class="rangeDays === r ? 'bg-surface text-ink shadow-card' : 'text-ink-3 hover:text-ink-2'">
                        {{ r }} Tage
                    </button>
                </div>
            </div>

            <!-- HRV -->
            <AppCard v-if="hrvChart" title="HRV"
                :subtitle="hrvChart.band ? 'Das grüne Band ist dein Normalbereich. Tippe auf einen Tag für seinen Wert.' : 'Tippe auf einen Tag für seinen Wert.'">
                <RecoveryChart :points="hrvChart.points" :summary="hrvChart.summary" :band="hrvChart.band" unit="ms" :color="C.hrv" />
            </AppCard>

            <!-- Ruhepuls -->
            <AppCard v-if="rhrChart" title="Ruhepuls"
                subtitle="Steigt er über mehrere Tage, steckt oft Belastung, zu wenig Schlaf oder ein Infekt dahinter.">
                <RecoveryChart :points="rhrChart.points" :summary="rhrChart.summary" unit="bpm" :color="C.rhr" />
            </AppCard>

            <!-- Schlaf + Body Battery nebeneinander ab lg -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <AppCard v-if="sleepChart" :title="sleepChart.score ? 'Schlafwert' : 'Schlaf'"
                    :subtitle="sleepChart.score ? 'Jede Säule in der Farbe ihrer Stufe.' : 'Rot markiert sind Nächte unter 6 Stunden.'">
                    <RecoveryChart v-if="sleepChart.score" kind="bar" :points="sleepChart.points" :summary="sleepChart.summary" :min="0" :max="100" />
                    <RecoveryChart v-else kind="bar" :points="sleepChart.points" :summary="sleepChart.summary" unit="h"
                        :min="0" :reference="{ value: 7, label: '7 h' }"
                        :format="v => v.toLocaleString('de-DE', { maximumFractionDigits: 1 })" />
                    <div v-if="sleepChart.score" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[13px] text-ink-3">
                        <span v-for="z in SLEEP_SCORE_ZONES" :key="z.label" class="flex items-center gap-1.5">
                            <i class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: TONE_COLOR[z.tone] }" />
                            {{ z.label }}{{ z.from ? ` ab ${z.from}` : ` unter ${z.to}` }}
                        </span>
                    </div>
                </AppCard>

                <AppCard v-if="bbChart" title="Body Battery"
                    subtitle="Jede Säule reicht vom Tiefst- zum Höchstwert des Tages. Rot: nicht über 50 gekommen.">
                    <RecoveryChart kind="range" :points="bbChart.points" :summary="bbChart.summary" :min="0" :max="100"
                        :reference="{ value: 50 }" />
                </AppCard>
            </div>

            <!-- Kilometer pro Woche -->
            <AppCard v-if="weeklyKm" title="Kilometer pro Kalenderwoche"
                subtitle="Die blasse Säule ist die laufende, noch unvollständige Woche.">
                <RecoveryChart kind="bar" :points="weeklyKm.points" :summary="weeklyKm.summary" unit="km"
                    :format="v => v.toLocaleString('de-DE', { maximumFractionDigits: 1 })" />
            </AppCard>

            <!-- Rohdaten -->
            <AppCard>
                <button class="flex items-center gap-1.5 text-[13px] font-semibold text-ink-2 transition-colors hover:text-ink"
                    @click="rawOpen = !rawOpen">
                    <svg class="h-4 w-4 transition-transform duration-200 ease-in-out-strong" :class="rawOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
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
                                <th class="px-2 py-2 text-right font-semibold">Schlafwert</th>
                                <th class="px-2 py-2 text-right font-semibold">Battery</th>
                                <th class="px-2 py-2 text-right font-semibold">Stress</th>
                                <th class="px-2 py-2 text-right font-semibold">Schritte</th>
                                <th class="px-2 py-2 text-right font-semibold">VO2max</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="d in [...days].reverse()" :key="d.date">
                                <td class="whitespace-nowrap px-2 py-2 text-ink-3">{{ fmtDM(d.date) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.hrv) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.resting_hr) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ d.sleep_hours != null ? d.sleep_hours.toFixed(1) : '–' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.sleep_score) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ d.body_battery_high != null ? `${d.body_battery_low ?? '?'}–${d.body_battery_high}` : '–' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.stress_avg) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.steps) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-2">{{ nf(d.vo2max, 1) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </section>
</template>
