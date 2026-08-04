<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    activity:      Object,
    paceZones:     Object,
    linkedSession: Object,
});

// ── Lap helpers ───────────────────────────────────────────────────────────────

const hasLaps = computed(() => Array.isArray(props.activity.laps) && props.activity.laps.length > 1);
const totalLapTime = computed(() => hasLaps.value
    ? props.activity.laps.reduce((s, l) => s + (l.moving_time || l.elapsed_time || 0), 0)
    : 0
);

function lapPace(lap) {
    if (!lap.average_speed || lap.average_speed <= 0) return '–';
    const sec = 1000 / lap.average_speed;
    return `${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2,'0')}`;
}

function lapSpeed(lap) {
    if (!lap.average_speed || lap.average_speed <= 0) return '–';
    return (lap.average_speed * 3.6).toFixed(1);
}

function lapDist(lap) {
    return ((lap.distance || 0) / 1000).toFixed(2);
}

function lapTime(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2,'0')}`;
}

// Lap bar color by pace (for runs) or by index cycling (for others)
const lapBarColors = [
    'bg-accent', 'bg-accent', 'bg-accent', 'bg-accent',
    'bg-info', 'bg-info', 'bg-info', 'bg-info',
];

function lapColor(lap, index) {
    if (props.activity.type !== 'Run' || !lap.average_speed) {
        return lapBarColors[index % lapBarColors.length];
    }
    if (!props.paceZones) return 'bg-accent';
    const paceSec = 1000 / lap.average_speed;
    const zones = ['z1','z2','z3','z4','z5'];
    const zoneBar = { z1: 'bg-info', z2: 'bg-success', z3: 'bg-warn', z4: 'bg-warn', z5: 'bg-danger' };
    for (const key of zones) {
        const z = props.paceZones[key];
        if (!z) continue;
        const minSec = paceToSeconds(z.min_pace) ?? 0;
        const maxSec = paceToSeconds(z.max_pace) ?? Infinity;
        if (paceSec >= minSec && paceSec <= maxSec) return zoneBar[key];
    }
    return 'bg-accent';
}

// Lap height as % of container: faster lap = taller bar (40%–100%)
const lapHeightPct = computed(() => {
    if (!hasLaps.value) return [];
    const speeds = props.activity.laps.map(l => l.average_speed || 0).filter(s => s > 0);
    if (speeds.length === 0) return props.activity.laps.map(() => 70);
    const minSpeed = Math.min(...speeds);
    const maxSpeed = Math.max(...speeds);
    const range = maxSpeed - minSpeed;
    return props.activity.laps.map(l => {
        if (!l.average_speed || range === 0) return 70;
        return Math.round(40 + ((l.average_speed - minSpeed) / range) * 60);
    });
});

// ── Lap ↔ chart ↔ map interaction ─────────────────────────────────────────────
// Hovering (desktop) or tapping (mobile) a lap highlights it across the bar
// chart, the table row, and the route segment on the map.
const activeLap = ref(null);
function setActiveLap(i)    { activeLap.value = i; }
function clearActiveLap()   { activeLap.value = null; }
function toggleActiveLap(i) { activeLap.value = activeLap.value === i ? null : i; }

// ── Session rating ────────────────────────────────────────────────────────────
const ratingValue  = ref(props.linkedSession?.rating          ?? 0);
const effortValue  = ref(props.linkedSession?.effort_perceived ?? 0);
const feelingNotes = ref(props.linkedSession?.feeling_notes    ?? '');
const ratingSaving = ref(false);
const ratingSaved  = ref(false);
const ratingError  = ref('');

async function saveRating() {
    if (!props.linkedSession) return;
    ratingSaving.value = true;
    ratingError.value  = '';
    try {
        await axios.patch(route('training-sessions.rate', props.linkedSession.id), {
            rating:           ratingValue.value  || null,
            effort_perceived: effortValue.value  || null,
            feeling_notes:    feelingNotes.value || null,
        });
        ratingSaved.value = true;
        setTimeout(() => { ratingSaved.value = false; }, 2500);
    } catch {
        ratingError.value = 'Speichern fehlgeschlagen.';
    } finally {
        ratingSaving.value = false;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatDistance(m) {
    if (!m) return '–';
    return (m / 1000).toFixed(2) + ' km';
}

function formatDuration(seconds) {
    if (!seconds) return '–';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    return `${m}:${String(s).padStart(2,'0')}`;
}

function formatPace(averageSpeed) {
    if (!averageSpeed || averageSpeed === 0) return '–';
    const secPerKm = 1000 / averageSpeed;
    const min = Math.floor(secPerKm / 60);
    const sec = Math.floor(secPerKm % 60);
    return `${min}:${String(sec).padStart(2,'0')}`;
}

function formatSwimPace(averageSpeed) {
    if (!averageSpeed || averageSpeed === 0) return '–';
    const secPer100m = 100 / averageSpeed;
    const min = Math.floor(secPer100m / 60);
    const sec = Math.floor(secPer100m % 60);
    return `${min}:${String(sec).padStart(2,'0')}`;
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
}

function formatTime(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

// Parse "m:ss" pace string to total seconds.
// "∞" → Infinity (Zone 1 has no upper slowness limit)
// "0:00" → 0 (Zone 5 has no lower speed limit)
function paceToSeconds(paceStr) {
    if (!paceStr) return null;
    if (paceStr === '∞') return Infinity;
    const parts = paceStr.split(':');
    if (parts.length !== 2) return null;
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
}

// ── Zone computation ─────────────────────────────────────────────────────────

const paceZoneInfo = computed(() => {
    if (!props.paceZones || !props.activity.average_speed || props.activity.type !== 'Run') return null;

    const paceSec = 1000 / props.activity.average_speed; // sec/km

    const zones = ['z1','z2','z3','z4','z5'];
    for (const key of zones) {
        const z = props.paceZones[key];
        if (!z) continue;
        const minSec = paceToSeconds(z.min_pace) ?? 0;          // faster bound (0 = no limit)
        const maxSec = paceToSeconds(z.max_pace) ?? Infinity;   // slower bound (∞ = no limit)
        if (paceSec >= minSec && paceSec <= maxSec) {
            return { key, name: z.name, minPace: z.min_pace, maxPace: z.max_pace };
        }
    }
    return null;
});

const zoneColors = {
    z1: { bg: 'bg-info',   light: 'bg-info-soft',   text: 'text-info-ink',   label: 'Zone 1' },
    z2: { bg: 'bg-success',  light: 'bg-success-soft', text: 'text-success-ink', label: 'Zone 2' },
    z3: { bg: 'bg-warn', light: 'bg-accent-soft', text: 'text-warn-ink', label: 'Zone 3' },
    z4: { bg: 'bg-warn', light: 'bg-warn-soft', text: 'text-warn-ink', label: 'Zone 4' },
    z5: { bg: 'bg-danger',    light: 'bg-danger-soft',     text: 'text-danger-ink',     label: 'Zone 5' },
};

// ── Type badges ───────────────────────────────────────────────────────────────

const typeLabel = { Run: 'Laufen', Ride: 'Radfahren', VirtualRide: 'Virtual Ride', Swim: 'Schwimmen', Walk: 'Gehen', Workout: 'Workout' };
const typeColors = {
    Run:         'bg-accent-soft text-accent-ink',
    Ride:        'bg-success-soft text-success-ink',
    VirtualRide: 'bg-success-soft text-success-ink',
    Swim:        'bg-info-soft text-info-ink',
    Workout:     'bg-warn-soft text-warn-ink',
    Walk:        'bg-warn-soft text-warn-ink',
};

const isCycling = computed(() => ['Ride', 'VirtualRide'].includes(props.activity.type));
function typeColor(t) {
    return typeColors[t] ?? 'bg-surface-2 text-ink-2';
}

// ── Google Encoded Polyline decoder ──────────────────────────────────────────

function decodePolyline(encoded) {
    const coords = [];
    let index = 0, lat = 0, lng = 0;
    while (index < encoded.length) {
        let shift = 0, result = 0, b;
        do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
        lat += (result & 1) ? ~(result >> 1) : (result >> 1);
        shift = 0; result = 0;
        do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
        lng += (result & 1) ? ~(result >> 1) : (result >> 1);
        coords.push([lat / 1e5, lng / 1e5]);
    }
    return coords;
}

// ── Leaflet map ───────────────────────────────────────────────────────────────

const mapContainer = ref(null);
let mapInstance    = null;
let L              = null;       // Leaflet module, kept for the highlight watcher
let routeCoords    = [];         // decoded polyline points [[lat,lng], …]
let polyCum        = [];         // cumulative distance (m) up to each point
let polyTotal      = 0;          // total polyline length (m)
let lapFractions   = [];         // [{start, end}] distance fraction per lap (0–1)
let highlightLayer = null;       // currently drawn lap-segment polyline

// Great-circle distance between two [lat,lng] points in metres.
function haversine(a, b) {
    const R = 6371000;
    const dLat = (b[0] - a[0]) * Math.PI / 180;
    const dLng = (b[1] - a[1]) * Math.PI / 180;
    const lat1 = a[0] * Math.PI / 180, lat2 = b[0] * Math.PI / 180;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

// Map a lap onto the route by distance fraction (approximate — the stored
// polyline may be a simplified summary, so we scale by fraction of total length
// rather than absolute metres).
function buildLapSegment(i) {
    if (routeCoords.length < 2 || !lapFractions[i] || polyTotal <= 0) return [];
    const startD = lapFractions[i].start * polyTotal;
    const endD   = lapFractions[i].end   * polyTotal;
    let a = -1, b = -1;
    for (let k = 0; k < routeCoords.length; k++) {
        if (a === -1 && polyCum[k] >= startD) a = k;
        if (polyCum[k] <= endD) b = k;
    }
    if (a === -1) a = b;
    a = Math.max(0, a - 1);                              // include neighbours so
    b = Math.min(routeCoords.length - 1, b + 1);         // segments stay connected
    return a < b ? routeCoords.slice(a, b + 1) : [];
}

// Redraw the orange highlight whenever the active lap changes.
watch(activeLap, (i) => {
    if (!mapInstance || !L) return;
    if (highlightLayer) { highlightLayer.remove(); highlightLayer = null; }
    if (i === null || i === undefined) return;
    const seg = buildLapSegment(i);
    if (seg.length < 2) return;
    highlightLayer = L.polyline(seg, { color: '#f97316', weight: 6, opacity: 1 }).addTo(mapInstance);
    highlightLayer.bringToFront();
});

onMounted(async () => {
    // Precompute per-lap distance fractions (independent of the map).
    if (hasLaps.value) {
        const dists  = props.activity.laps.map(l => l.distance || 0);
        const totalD = dists.reduce((s, d) => s + d, 0) || 1;
        let cum = 0;
        lapFractions = dists.map(d => {
            const start = cum / totalD;
            cum += d;
            return { start, end: cum / totalD };
        });
    }

    if (!props.activity.polyline || !mapContainer.value) return;

    try {
        routeCoords = decodePolyline(props.activity.polyline);
        if (routeCoords.length === 0) return;

        // Cumulative distance along the route, for lap-segment lookup.
        polyCum = new Array(routeCoords.length);
        polyCum[0] = 0;
        for (let k = 1; k < routeCoords.length; k++) {
            polyCum[k] = polyCum[k - 1] + haversine(routeCoords[k - 1], routeCoords[k]);
        }
        polyTotal = polyCum[routeCoords.length - 1];

        L = (await import('leaflet')).default;
        await import('leaflet/dist/leaflet.css');

        // Fix Leaflet default icon paths broken by Vite
        delete L.Icon.Default.prototype._getIconUrl;
        L.Icon.Default.mergeOptions({
            iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
            iconUrl:       'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
            shadowUrl:     'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
        });

        mapInstance = L.map(mapContainer.value, { zoomControl: true, scrollWheelZoom: false });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap',
            maxZoom: 18,
        }).addTo(mapInstance);

        const polyline = L.polyline(routeCoords, { color: '#4f46e5', weight: 3, opacity: 0.85 }).addTo(mapInstance);
        mapInstance.fitBounds(polyline.getBounds(), { padding: [16, 16] });

        // Start + End markers
        L.circleMarker(routeCoords[0], { radius: 7, color: '#16a34a', fillColor: '#16a34a', fillOpacity: 1, weight: 2 }).addTo(mapInstance);
        L.circleMarker(routeCoords[routeCoords.length - 1], { radius: 7, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 1, weight: 2 }).addTo(mapInstance);

        // If a lap was already activated before the map finished loading, draw it.
        if (activeLap.value !== null) {
            const seg = buildLapSegment(activeLap.value);
            if (seg.length >= 2) {
                highlightLayer = L.polyline(seg, { color: '#f97316', weight: 6, opacity: 1 }).addTo(mapInstance);
            }
        }
    } catch (e) {
        console.warn('Leaflet init failed:', e);
    }
});

onUnmounted(() => {
    if (mapInstance) { mapInstance.remove(); mapInstance = null; }
});
</script>

<template>
    <Head :title="activity.name" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <div class="space-y-5 px-4 py-4 lg:px-6 lg:py-6">

                <!-- ── Kopf ───────────────────────────────────────── -->
                <header class="flex items-start gap-3 px-1">
                    <Link
                        :href="route('activities.index')"
                        class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface text-ink-3 shadow-card transition-colors hover:text-ink"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                        </svg>
                    </Link>
                    <div class="min-w-0 flex-1">
                        <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="typeColor(activity.type)">
                                {{ typeLabel[activity.type] ?? activity.type }}
                            </span>
                            <span v-if="paceZoneInfo" class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="[zoneColors[paceZoneInfo.key]?.light, zoneColors[paceZoneInfo.key]?.text]">
                                {{ paceZoneInfo.name }}
                            </span>
                        </div>
                        <h1 class="text-2xl font-bold leading-tight tracking-tight text-ink lg:text-3xl">{{ activity.name }}</h1>
                        <p class="mt-1 text-[15px] text-ink-3">
                            {{ formatDate(activity.start_date) }} · {{ formatTime(activity.start_date) }} Uhr
                            <template v-if="activity.location_city">
                                · {{ activity.location_city }}<template v-if="activity.location_country">, {{ activity.location_country }}</template>
                            </template>
                        </p>
                    </div>
                </header>

                <!-- ── Kennzahlen ─────────────────────────────────── -->
                <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Distanz</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ (activity.distance / 1000).toFixed(2) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">km</p>
                    </div>
                    <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Bewegungszeit</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ formatDuration(activity.moving_time) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">ohne Pausen</p>
                    </div>
                    <div v-if="activity.type === 'Run'" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Pace</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ formatPace(activity.average_speed) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">min/km</p>
                    </div>
                    <div v-if="activity.type === 'Swim'" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Pace</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ formatSwimPace(activity.average_speed) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">min/100m</p>
                    </div>
                    <div v-if="isCycling && activity.average_speed" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Tempo</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ (activity.average_speed * 3.6).toFixed(1) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">km/h</p>
                    </div>
                    <div v-if="isCycling && activity.average_watts" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Leistung</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ Math.round(activity.average_watts) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">Watt</p>
                    </div>
                    <div v-if="activity.total_elevation_gain > 0" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Höhenmeter</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ Math.round(activity.total_elevation_gain) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">m aufwärts</p>
                    </div>
                    <div v-if="activity.average_heartrate" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Puls</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-danger">{{ Math.round(activity.average_heartrate) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">
                            Ø bpm<template v-if="activity.max_heartrate"> · max {{ Math.round(activity.max_heartrate) }}</template>
                        </p>
                    </div>
                    <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Gesamtzeit</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ formatDuration(activity.elapsed_time) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">inkl. Pausen</p>
                    </div>
                    <div v-if="!isCycling && activity.max_speed" class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Schnellste Pace</p>
                        <p class="mt-1.5 text-2xl font-bold leading-none tabular-nums text-ink">{{ formatPace(activity.max_speed) }}</p>
                        <p class="mt-1 text-[11px] text-ink-3">min/km</p>
                    </div>
                </section>

                <!-- ── Karte + Runden nebeneinander ab xl ─────────── -->
                <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">

                    <!-- Karte -->
                    <section class="min-w-0 xl:col-span-2">
                        <AppCard flush>
                            <div
                                v-if="activity.polyline"
                                ref="mapContainer"
                                class="h-72 w-full overflow-hidden rounded-card bg-surface-2 lg:h-96"
                            />
                            <div v-else class="flex h-32 w-full items-center justify-center rounded-card bg-surface-2">
                                <p class="text-[15px] text-ink-3">Keine Kartendaten verfügbar</p>
                            </div>
                        </AppCard>
                    </section>

                    <!-- Tempo-Zonen -->
                    <section v-if="paceZoneInfo && paceZones" class="min-w-0">
                        <AppCard title="Tempo-Zonen">
                            <div class="mb-4 flex gap-1.5">
                                <div
                                    v-for="key in ['z1','z2','z3','z4','z5']"
                                    :key="key"
                                    class="h-2 flex-1 rounded-full transition-all"
                                    :class="[zoneColors[key]?.bg, paceZoneInfo.key === key ? 'scale-y-150 opacity-100' : 'opacity-25']"
                                />
                            </div>

                            <div class="mb-4 flex items-center gap-3">
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full" :class="zoneColors[paceZoneInfo.key]?.light">
                                    <span class="text-lg font-bold" :class="zoneColors[paceZoneInfo.key]?.text">{{ paceZoneInfo.key.replace('z','') }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-[15px] font-bold text-ink">{{ paceZoneInfo.name }}</p>
                                    <p class="text-[13px] text-ink-3">{{ paceZoneInfo.minPace }} – {{ paceZoneInfo.maxPace }} min/km</p>
                                </div>
                                <div class="ml-auto shrink-0 text-right">
                                    <p class="text-[11px] text-ink-3">Dein Tempo</p>
                                    <p class="text-[15px] font-bold tabular-nums text-ink">{{ formatPace(activity.average_speed) }}</p>
                                </div>
                            </div>

                            <div class="space-y-1 border-t border-line pt-3">
                                <div
                                    v-for="key in ['z1','z2','z3','z4','z5']"
                                    :key="key"
                                    class="flex items-center gap-3 rounded-field px-2 py-2"
                                    :class="paceZoneInfo.key === key ? [zoneColors[key]?.light] : ''"
                                >
                                    <div class="h-2 w-2 shrink-0 rounded-full" :class="zoneColors[key]?.bg" />
                                    <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-ink-2">{{ paceZones[key]?.name }}</span>
                                    <span class="shrink-0 text-[12px] tabular-nums text-ink-3">{{ paceZones[key]?.min_pace }}–{{ paceZones[key]?.max_pace }}</span>
                                </div>
                            </div>
                        </AppCard>
                    </section>
                </div>

                <!-- ── Runden ─────────────────────────────────────── -->
                <section v-if="hasLaps">
                    <AppCard :title="`Runden`" :subtitle="`${activity.laps.length} Laps · zum Hervorheben antippen`">
                        <div class="mb-4 flex h-14 items-end gap-0.5">
                            <div
                                v-for="(lap, i) in activity.laps"
                                :key="i"
                                :style="{
                                    width:  ((lap.moving_time || lap.elapsed_time || 0) / totalLapTime * 100).toFixed(2) + '%',
                                    height: lapHeightPct[i] + '%',
                                }"
                                :class="[
                                    lapColor(lap, i),
                                    activeLap === i ? 'opacity-100 ring-2 ring-ink' : (activeLap !== null ? 'opacity-25' : 'opacity-80'),
                                ]"
                                class="cursor-pointer rounded-t-sm transition-all"
                                @mouseenter="setActiveLap(i)"
                                @mouseleave="clearActiveLap"
                                @click="toggleActiveLap(i)"
                                :title="activity.type === 'Run'
                                    ? `Lap ${lap.index ?? i+1}: ${lapDist(lap)} km · ${lapPace(lap)} min/km`
                                    : `Lap ${lap.index ?? i+1}: ${lapDist(lap)} km · ${lapSpeed(lap)} km/h`"
                            />
                        </div>

                        <div class="-mx-1 overflow-x-auto">
                            <table class="w-full min-w-[26rem] border-collapse text-[13px]">
                                <thead>
                                    <tr class="text-ink-3">
                                        <th class="px-2 py-2 text-left font-semibold">#</th>
                                        <th class="px-2 py-2 text-left font-semibold">Zeit</th>
                                        <th class="px-2 py-2 text-right font-semibold">Distanz</th>
                                        <th class="px-2 py-2 text-right font-semibold">{{ isCycling ? 'km/h' : 'Pace' }}</th>
                                        <th v-if="activity.average_heartrate" class="px-2 py-2 text-right font-semibold">Puls</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="(lap, i) in activity.laps"
                                        :key="'row'+i"
                                        class="cursor-pointer transition-colors"
                                        :class="activeLap === i ? 'bg-surface-2' : 'hover:bg-surface-2'"
                                        @mouseenter="setActiveLap(i)"
                                        @mouseleave="clearActiveLap"
                                        @click="toggleActiveLap(i)"
                                    >
                                        <td class="px-2 py-2.5">
                                            <span class="flex items-center gap-2">
                                                <span class="h-2 w-2 shrink-0 rounded-full" :class="lapColor(lap, i)" />
                                                <span class="text-ink-3">{{ lap.index ?? i + 1 }}</span>
                                            </span>
                                        </td>
                                        <td class="px-2 py-2.5 font-medium tabular-nums text-ink-2">{{ lapTime(lap.moving_time || lap.elapsed_time || 0) }}</td>
                                        <td class="px-2 py-2.5 text-right tabular-nums text-ink-3">{{ lapDist(lap) }} km</td>
                                        <td class="px-2 py-2.5 text-right font-bold tabular-nums text-ink">
                                            <template v-if="isCycling">{{ lapSpeed(lap) }}</template>
                                            <template v-else>{{ lapPace(lap) }}</template>
                                        </td>
                                        <td v-if="activity.average_heartrate" class="px-2 py-2.5 text-right tabular-nums text-danger">
                                            {{ lap.average_heartrate ? Math.round(lap.average_heartrate) : '–' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </AppCard>
                </section>

                <!-- ── Beschreibung + Bewertung ───────────────────── -->
                <div class="grid grid-cols-1 gap-5" :class="activity.description && linkedSession ? 'xl:grid-cols-2' : ''">

                    <AppCard v-if="activity.description" title="Beschreibung">
                        <p class="whitespace-pre-wrap text-[15px] leading-relaxed text-ink-2">{{ activity.description }}</p>
                    </AppCard>

                    <AppCard v-if="linkedSession" title="Einheit bewerten"
                        :subtitle="`${linkedSession.title} · dein Feedback verbessert den Plan`">
                        <template #action>
                            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <span v-if="ratingSaved" class="text-[13px] font-semibold text-success-ink">Gespeichert</span>
                            </Transition>
                        </template>

                        <p class="z-label">Wie war die Einheit?</p>
                        <div class="mb-4 flex items-center gap-1.5">
                            <button
                                v-for="star in 5"
                                :key="star"
                                type="button"
                                class="flex h-10 w-10 items-center justify-center rounded-full text-xl transition-all"
                                :class="star <= ratingValue ? 'scale-110 bg-warn-soft' : 'bg-surface-2 opacity-40 hover:opacity-70'"
                                @click="ratingValue = ratingValue === star ? 0 : star"
                            >⭐</button>
                            <span class="ml-2 text-[13px] text-ink-3">
                                {{ ['', 'Sehr schwer', 'Schwer', 'Okay', 'Gut', 'Top'][ratingValue] }}
                            </span>
                        </div>

                        <p class="z-label">Gefühlte Belastung (RPE)</p>
                        <div class="mb-4 flex flex-wrap gap-1.5">
                            <button
                                v-for="rpe in 10"
                                :key="rpe"
                                type="button"
                                class="h-9 w-9 rounded-full text-[13px] font-bold transition-all active:scale-90"
                                :class="rpe === effortValue
                                    ? (rpe <= 3 ? 'bg-success text-white' : rpe <= 6 ? 'bg-warn text-white' : 'bg-danger text-white')
                                    : 'bg-surface-2 text-ink-3 hover:bg-surface-3'"
                                @click="effortValue = effortValue === rpe ? 0 : rpe"
                            >{{ rpe }}</button>
                        </div>

                        <textarea
                            v-model="feelingNotes"
                            rows="2"
                            placeholder="Notizen (optional) — was lief gut, was nicht?"
                            class="z-input resize-none"
                        />

                        <p v-if="ratingError" class="z-error">{{ ratingError }}</p>

                        <AppButton
                            block
                            class="mt-4"
                            :loading="ratingSaving"
                            :disabled="!ratingValue && !effortValue && !feelingNotes"
                            @click="saveRating"
                        >
                            Bewertung speichern
                        </AppButton>
                    </AppCard>
                </div>

                <!-- ── Strava ─────────────────────────────────────── -->
                <div v-if="activity.strava_id" class="flex justify-center pb-2 pt-1">
                    <a
                        :href="`https://www.strava.com/activities/${activity.strava_id}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-full bg-[#FC4C02] px-5 py-2.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/>
                        </svg>
                        Auf Strava ansehen
                    </a>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
