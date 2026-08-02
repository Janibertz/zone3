<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    track: { type: Object, required: true },
});

const data = ref({ ...props.track });

// ── Uhr ──────────────────────────────────────────────────────────────────────
// Tickt lokal jede Sekunde. Die Yard-Rechnung haengt allein an der Startzeit,
// nicht an Garmin — faellt LiveTrack aus, laeuft die Uhr trotzdem weiter.
const now = ref(Date.now());
let clockTimer = null;
let pollTimer  = null;

const startMs   = computed(() => new Date(data.value.startsAt).getTime());
const elapsedMs = computed(() => now.value - startMs.value);
const HOUR      = 3600_000;

const hasStarted = computed(() => elapsedMs.value >= 0);

/** Rennen vorbei? Dann friert alles beim gesetzten Stand ein. */
const isOver = computed(() => data.value.outcome != null);

/** Laufender Yard, 1-basiert. Vor dem Start 0. */
const currentYard = computed(() => {
    if (isOver.value) return data.value.stoppedAtYard ?? 0;
    return hasStarted.value ? Math.floor(elapsedMs.value / HOUR) + 1 : 0;
});

/** Sekunden bis zur naechsten vollen Stunde — der Glocke. */
const secondsToBell = computed(() => {
    if (!hasStarted.value) return Math.max(0, Math.round(-elapsedMs.value / 1000));
    return Math.round((HOUR - (elapsedMs.value % HOUR)) / 1000);
});

/** Anteil des laufenden Yards, der schon vorbei ist. */
const yardProgress = computed(() => {
    if (!hasStarted.value) return 0;
    return ((elapsedMs.value % HOUR) / HOUR) * 100;
});

/**
 * Abgeschlossene Runden. Bei einem Backyard startet jede Runde zur vollen
 * Stunde — wer in Runde N ist, hat N-1 hinter sich. Das braucht keine
 * Verbindung zu Garmin.
 */
const completedYards = computed(() => {
    if (isOver.value) return data.value.stoppedAtYard ?? 0;
    return Math.max(0, currentYard.value - 1);
});

/**
 * Distanz aus den Runden. Bei fester Rundenlaenge ist das exakt und
 * dazu genauer als GPS, das ueber 24 Stunden wegdriftet. Garmins Wert
 * wird nur genommen, wenn er ueberhaupt ankommt und plausibel groesser ist.
 */
const distanceKm = computed(() => {
    const fromYards = completedYards.value * (data.value.yardKm ?? 0);
    const fromGarmin = data.value.distanceKm ?? 0;
    return Math.max(fromYards, fromGarmin).toFixed(2);
});

// ── Formatierung ─────────────────────────────────────────────────────────────
function clock(totalSeconds) {
    if (totalSeconds == null || totalSeconds < 0) return '–';
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = Math.floor(totalSeconds % 60);
    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${m}:${String(s).padStart(2, '0')}`;
}

const pace = computed(() => {
    const p = data.value.paceSecPerKm;
    if (!p) return null;
    return `${Math.floor(p / 60)}:${String(Math.round(p % 60)).padStart(2, '0')}`;
});

const elapsedTotal = computed(() =>
    hasStarted.value ? Math.floor(elapsedMs.value / 1000) : null
);

const startLabel = computed(() =>
    new Date(data.value.startsAt).toLocaleString('de-DE', {
        weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    })
);

// Die Glocke wird in der letzten Minute dringlich.
const bellUrgent = computed(() => hasStarted.value && secondsToBell.value <= 60);

// ── Pulskurve ────────────────────────────────────────────────────────────────
const hrChart = computed(() => {
    const pts = (data.value.series ?? []).filter(p => p.hr);
    if (pts.length < 3) return null;

    const W = 600, H = 90, pad = 6;
    const hrs = pts.map(p => p.hr);
    const lo = Math.min(...hrs) - 3;
    const hi = Math.max(...hrs) + 3;
    const range = (hi - lo) || 1;
    const t0 = pts[0].t;
    const span = (pts[pts.length - 1].t - t0) || 1;

    const d = pts.map((p, i) => {
        const x = pad + ((p.t - t0) / span) * (W - pad * 2);
        const y = pad + (H - pad * 2) - ((p.hr - lo) / range) * (H - pad * 2);
        return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');

    return { d, W, H, lo: Math.round(lo), hi: Math.round(hi), count: pts.length };
});

// ── Karte ────────────────────────────────────────────────────────────────────
// Zwei Wege: eigene Leaflet-Karte, wenn Positionen ankommen (dann steht kein
// Garmin-Token in der Seite), sonst Garmins eigene Seite im iframe.
const mapEl = ref(null);
let map = null, trackLine = null, marker = null, L = null;

async function initMap() {
    if (map || !mapEl.value || !data.value.path?.length) return;

    L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    map = L.map(mapEl.value, { zoomControl: true, attributionControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    drawMap();
}

function drawMap() {
    if (!map || !L) return;

    const path = data.value.path ?? [];
    if (!path.length) return;

    if (trackLine) trackLine.remove();
    trackLine = L.polyline(path, { color: '#4f46e5', weight: 4, opacity: 0.8 }).addTo(map);

    const pos = data.value.position ?? path[path.length - 1];
    if (marker) marker.remove();
    marker = L.circleMarker(pos, {
        radius: 8, color: '#fff', weight: 3, fillColor: '#ef4444', fillOpacity: 1,
    }).addTo(map);

    map.fitBounds(trackLine.getBounds(), { padding: [24, 24] });
}

// ── Nachladen ────────────────────────────────────────────────────────────────
const fetchFailed = ref(false);

async function refresh() {
    try {
        const { data: fresh } = await axios.get(`${window.location.pathname}/data`);
        data.value = fresh;
        fetchFailed.value = false;
        if (map) drawMap(); else initMap();
    } catch {
        fetchFailed.value = true;
    }
}

onMounted(() => {
    clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
    pollTimer  = setInterval(refresh, 45_000);
    initMap();
});

onUnmounted(() => {
    clearInterval(clockTimer);
    clearInterval(pollTimer);
    map?.remove();
});
</script>

<template>
    <Head :title="data.title" />

    <div class="min-h-screen bg-canvas">
        <div class="mx-auto max-w-3xl space-y-4 px-4 py-5">

            <!-- Kopf — bewusst ohne Name und Foto -->
            <header class="px-1">
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ data.title }}</h1>
                <p class="mt-0.5 text-[13px] text-ink-3">Start: {{ startLabel }}</p>
            </header>

            <!-- ══════════════════════════════════════════════════
                 DIE GLOCKE — das Wichtigste auf der Seite
                 ══════════════════════════════════════════════════ -->
            <section class="rounded-card bg-surface p-6 text-center shadow-card">
                <template v-if="isOver">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">
                        {{ data.outcome === 'finished' ? 'Gewonnen' : 'Rennen beendet' }}
                    </p>
                    <p class="mt-2 text-6xl font-bold tabular-nums tracking-tight text-ink">{{ completedYards }}</p>
                    <p class="mt-1 text-[15px] text-ink-3">
                        {{ completedYards === 1 ? 'Runde' : 'Runden' }} · {{ distanceKm }} km
                    </p>
                </template>

                <template v-else-if="!hasStarted">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Start in</p>
                    <p class="mt-2 text-5xl font-bold tabular-nums tracking-tight text-ink">{{ clock(secondsToBell) }}</p>
                </template>

                <template v-else>
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Yard {{ currentYard }}</p>
                    <p class="mt-2 text-6xl font-bold tabular-nums tracking-tight"
                        :class="bellUrgent ? 'text-danger' : 'text-ink'">
                        {{ clock(secondsToBell) }}
                    </p>
                    <p class="mt-1 text-[13px] text-ink-3">bis zur nächsten Glocke</p>

                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full transition-[width] duration-1000 ease-linear"
                            :class="bellUrgent ? 'bg-danger' : 'bg-success'"
                            :style="{ width: yardProgress + '%' }" />
                    </div>
                </template>
            </section>

            <!-- ══════════════════════════════════════════════════
                 FORTSCHRITT
                 ══════════════════════════════════════════════════ -->
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Runden</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                        {{ completedYards }}<span v-if="data.targetYards" class="text-[15px] font-medium text-ink-3"> / {{ data.targetYards }}</span>
                    </p>
                </div>
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Distanz</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                        {{ distanceKm }}<span class="text-[15px] font-medium text-ink-3"> km</span>
                    </p>
                </div>
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Puls</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums" :class="data.heartRate ? 'text-danger' : 'text-ink-3'">
                        {{ data.heartRate ?? '–' }}<span v-if="data.heartRate" class="text-[15px] font-medium text-ink-3"> bpm</span>
                    </p>
                </div>
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Pace</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                        {{ pace ?? '–' }}<span v-if="pace" class="text-[15px] font-medium text-ink-3"> /km</span>
                    </p>
                </div>
            </section>

            <!-- Gesamtzeit -->
            <section v-if="hasStarted" class="rounded-card bg-surface px-5 py-4 shadow-card">
                <div class="flex items-baseline justify-between">
                    <span class="text-[13px] text-ink-3">Unterwegs seit</span>
                    <span class="text-xl font-bold tabular-nums text-ink">{{ clock(elapsedTotal) }}</span>
                </div>
            </section>

            <!-- ══════════════════════════════════════════════════
                 PULSVERLAUF
                 ══════════════════════════════════════════════════ -->
            <section v-if="hrChart" class="rounded-card bg-surface p-5 shadow-card">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-[15px] font-semibold text-ink">Pulsverlauf</h2>
                    <span class="text-[12px] text-ink-3">{{ hrChart.lo }}–{{ hrChart.hi }} bpm</span>
                </div>
                <svg :viewBox="`0 0 ${hrChart.W} ${hrChart.H}`" class="block h-auto w-full" preserveAspectRatio="none">
                    <path :d="hrChart.d" fill="none" stroke="rgb(var(--z-danger))" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </section>

            <!-- ══════════════════════════════════════════════════
                 KARTE — selbst gezeichnet, damit der Garmin-Link
                 (der den Token enthält) nicht öffentlich wird
                 ══════════════════════════════════════════════════ -->
            <section v-if="data.path?.length" class="overflow-hidden rounded-card bg-surface shadow-card">
                <div class="px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-ink">Strecke</h2>
                </div>
                <div ref="mapEl" class="h-[360px] w-full" />
            </section>

            <!-- Ohne eigene Positionen: Garmins Seite einbetten -->
            <section v-else-if="data.mapUrl" class="overflow-hidden rounded-card bg-surface shadow-card">
                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-ink">Live-Position</h2>
                    <a :href="data.mapUrl" target="_blank" rel="noopener"
                        class="text-[13px] font-semibold text-accent hover:underline">Bei Garmin öffnen</a>
                </div>
                <iframe
                    :src="data.mapUrl"
                    class="h-[440px] w-full border-0"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    title="Garmin LiveTrack"
                />
            </section>

            <!-- Hinweis, wenn die Daten alt sind -->
            <p v-if="(data.stale || fetchFailed) && !isOver" class="px-1 text-[13px] text-ink-3">
                Die Livewerte kommen gerade nicht durch — Funkloch oder Handy aus.
                Die Uhr oben läuft trotzdem korrekt weiter.
            </p>

            <footer class="px-1 pb-4 pt-2 text-[12px] text-ink-3">
                Werte kommen von Garmin LiveTrack und aktualisieren sich etwa jede Minute.
            </footer>
        </div>
    </div>
</template>
