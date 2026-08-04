<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

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
    <AuthenticatedLayout>
        <div class="max-w-2xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 space-y-4">

            <!-- Back + Header -->
            <div class="flex items-start gap-3">
                <Link
                    :href="route('activities.index')"
                    class="shrink-0 mt-0.5 h-8 w-8 flex items-center justify-center rounded-field bg-surface-2 text-ink-3 hover:bg-surface-3 transition-colors"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0" :class="typeColor(activity.type)">
                            {{ typeLabel[activity.type] ?? activity.type }}
                        </span>
                        <span v-if="paceZoneInfo" class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0" :class="[zoneColors[paceZoneInfo.key]?.light, zoneColors[paceZoneInfo.key]?.text]">
                            {{ paceZoneInfo.name }}
                        </span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold text-ink leading-tight">{{ activity.name }}</h1>
                    <p class="text-sm text-ink-3 mt-0.5">
                        {{ formatDate(activity.start_date) }}
                        <span class="text-ink-3 mx-1">·</span>
                        {{ formatTime(activity.start_date) }} Uhr
                        <template v-if="activity.location_city">
                            <span class="text-ink-3 mx-1">·</span>
                            {{ activity.location_city }}<template v-if="activity.location_country">, {{ activity.location_country }}</template>
                        </template>
                    </p>
                </div>
            </div>

            <!-- Map -->
            <div
                v-if="activity.polyline"
                ref="mapContainer"
                class="w-full h-56 sm:h-72 rounded-card overflow-hidden border border-line bg-surface-2"
            />
            <div
                v-else
                class="w-full h-28 rounded-card bg-surface-2 border border-line flex items-center justify-center"
            >
                <p class="text-sm text-ink-3">Keine Kartendaten verfügbar</p>
            </div>

            <!-- Key stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <div class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Distanz</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ (activity.distance / 1000).toFixed(2) }}</p>
                    <p class="text-xs text-ink-3 mt-1">km</p>
                </div>
                <div class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Bewegungszeit</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ formatDuration(activity.moving_time) }}</p>
                    <p class="text-xs text-ink-3 mt-1">h:min:s</p>
                </div>
                <div v-if="activity.type === 'Run'" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Pace</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ formatPace(activity.average_speed) }}</p>
                    <p class="text-xs text-ink-3 mt-1">min/km</p>
                </div>
                <div v-if="activity.type === 'Swim'" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Pace</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ formatSwimPace(activity.average_speed) }}</p>
                    <p class="text-xs text-ink-3 mt-1">min/100m</p>
                </div>
                <div v-if="isCycling && activity.average_speed" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Geschwindigkeit</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ (activity.average_speed * 3.6).toFixed(1) }}</p>
                    <p class="text-xs text-ink-3 mt-1">km/h</p>
                </div>
                <div v-if="isCycling && activity.average_watts" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Leistung</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ Math.round(activity.average_watts) }}</p>
                    <p class="text-xs text-ink-3 mt-1">Watt</p>
                </div>
                <div v-if="activity.total_elevation_gain > 0" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Höhenmeter</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ Math.round(activity.total_elevation_gain) }}</p>
                    <p class="text-xs text-ink-3 mt-1">m</p>
                </div>
                <div v-if="activity.type !== 'Run'" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1.5">Gesamtzeit</p>
                    <p class="text-2xl font-bold text-ink leading-none">{{ formatDuration(activity.elapsed_time) }}</p>
                    <p class="text-xs text-ink-3 mt-1">h:min:s</p>
                </div>
            </div>

            <!-- Heart rate -->
            <div v-if="activity.average_heartrate" class="bg-surface rounded-card border border-line p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
                    <span class="text-danger">♥</span> Herzfrequenz
                </h2>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Durchschnitt</p>
                        <p class="text-3xl font-bold text-ink">{{ Math.round(activity.average_heartrate) }}<span class="text-sm font-normal text-ink-3 ml-1">bpm</span></p>
                    </div>
                    <div v-if="activity.max_heartrate">
                        <p class="text-xs text-ink-3 mb-1">Maximum</p>
                        <p class="text-3xl font-bold text-ink">{{ Math.round(activity.max_heartrate) }}<span class="text-sm font-normal text-ink-3 ml-1">bpm</span></p>
                    </div>
                </div>
                <!-- HR bar visual -->
                <div v-if="activity.max_heartrate" class="relative h-2 bg-surface-2 rounded-full overflow-hidden">
                    <!-- Avg marker -->
                    <div
                        class="absolute top-0 h-full bg-danger rounded-full"
                        :style="{ width: (activity.average_heartrate / activity.max_heartrate * 100).toFixed(0) + '%' }"
                    />
                    <div
                        class="absolute top-1/2 -translate-y-1/2 w-3 h-3 bg-danger rounded-full border-2 border-white shadow"
                        :style="{ left: 'calc(' + (activity.average_heartrate / activity.max_heartrate * 100).toFixed(0) + '% - 6px)' }"
                    />
                </div>
                <div v-if="activity.max_heartrate" class="flex justify-between mt-1">
                    <span class="text-xs text-ink-3">0</span>
                    <span class="text-xs text-ink-3">{{ Math.round(activity.max_heartrate) }} bpm max</span>
                </div>
            </div>

            <!-- Lap chart -->
            <div v-if="hasLaps" class="bg-surface rounded-card border border-line p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-ink mb-3">
                    Runden <span class="text-xs font-normal text-ink-3 ml-1">{{ activity.laps.length }} Laps</span>
                </h2>

                <!-- Bar chart: width = duration, height = relative speed (faster = taller) -->
                <div class="flex gap-0.5 h-12 items-end mb-3">
                    <div
                        v-for="(lap, i) in activity.laps"
                        :key="i"
                        :style="{
                            width:  ((lap.moving_time || lap.elapsed_time || 0) / totalLapTime * 100).toFixed(2) + '%',
                            height: lapHeightPct[i] + '%',
                        }"
                        :class="[
                            lapColor(lap, i),
                            activeLap === i
                                ? 'opacity-100 ring-2 ring-warn ring-offset-1 ring-offset-white'
                                : (activeLap !== null ? 'opacity-25' : 'opacity-80'),
                        ]"
                        class="rounded-t-sm transition-all cursor-pointer"
                        @mouseenter="setActiveLap(i)"
                        @mouseleave="clearActiveLap"
                        @click="toggleActiveLap(i)"
                        :title="activity.type === 'Run'
                            ? `Lap ${lap.index ?? i+1}: ${lapDist(lap)} km · ${lapPace(lap)} min/km`
                            : `Lap ${lap.index ?? i+1}: ${lapDist(lap)} km · ${lapSpeed(lap)} km/h`"
                    />
                </div>

                <!-- Lap table -->
                <div class="space-y-1">
                    <div class="grid text-xs text-ink-3 font-medium px-1 mb-1"
                         :class="activity.average_heartrate ? 'grid-cols-[2.5rem_1fr_auto_auto_auto]' : 'grid-cols-[2.5rem_1fr_auto_auto]'">
                        <span>#</span>
                        <span>Zeit</span>
                        <span class="text-right">Distanz</span>
                        <span class="text-right">{{ ['Ride','VirtualRide'].includes(activity.type) ? 'km/h' : 'Pace' }}</span>
                        <span v-if="activity.average_heartrate" class="text-right">HF</span>
                    </div>
                    <div
                        v-for="(lap, i) in activity.laps"
                        :key="'row'+i"
                        class="grid items-center gap-x-2 px-1 py-1.5 rounded-field transition-colors text-sm cursor-pointer"
                        :class="[
                            activity.average_heartrate ? 'grid-cols-[2.5rem_1fr_auto_auto_auto]' : 'grid-cols-[2.5rem_1fr_auto_auto]',
                            activeLap === i
                                ? 'bg-warn-soft ring-1 ring-warn'
                                : 'hover:bg-surface-2',
                        ]"
                        @mouseenter="setActiveLap(i)"
                        @mouseleave="clearActiveLap"
                        @click="toggleActiveLap(i)"
                    >
                        <!-- Color dot + index -->
                        <span class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-full shrink-0" :class="lapColor(lap, i)" />
                            <span class="text-xs text-ink-3">{{ lap.index ?? i + 1 }}</span>
                        </span>
                        <!-- Duration -->
                        <span class="font-medium text-ink-2 text-xs">
                            {{ lapTime(lap.moving_time || lap.elapsed_time || 0) }}
                        </span>
                        <!-- Distance -->
                        <span class="text-right text-xs text-ink-3">{{ lapDist(lap) }} km</span>
                        <!-- Pace / Speed -->
                        <span class="text-right text-xs font-semibold text-ink">
                            <template v-if="['Ride','VirtualRide'].includes(activity.type)">{{ lapSpeed(lap) }}</template>
                            <template v-else>{{ lapPace(lap) }}</template>
                        </span>
                        <!-- HR -->
                        <span v-if="activity.average_heartrate" class="text-right text-xs text-danger">
                            {{ lap.average_heartrate ? Math.round(lap.average_heartrate) : '–' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Pace Zone indicator -->
            <div v-if="paceZoneInfo && paceZones" class="bg-surface rounded-card border border-line p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-ink mb-3">Tempo-Zonen</h2>
                <div class="flex gap-1.5 mb-3">
                    <div
                        v-for="key in ['z1','z2','z3','z4','z5']"
                        :key="key"
                        class="flex-1 h-2 rounded-full transition-all"
                        :class="[
                            zoneColors[key]?.bg,
                            paceZoneInfo.key === key ? 'opacity-100 scale-y-150' : 'opacity-25'
                        ]"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-field flex items-center justify-center shrink-0" :class="zoneColors[paceZoneInfo.key]?.light">
                        <span class="text-lg font-bold" :class="zoneColors[paceZoneInfo.key]?.text">{{ paceZoneInfo.key.replace('z','') }}</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ paceZoneInfo.name }}</p>
                        <p class="text-xs text-ink-3">{{ paceZoneInfo.minPace }} – {{ paceZoneInfo.maxPace }} min/km</p>
                    </div>
                    <div class="ml-auto text-right">
                        <p class="text-xs text-ink-3">Dein Tempo</p>
                        <p class="text-sm font-bold text-ink">{{ formatPace(activity.average_speed) }} min/km</p>
                    </div>
                </div>

                <!-- All zones list -->
                <div class="mt-3 space-y-1.5 border-t border-line pt-3">
                    <div
                        v-for="key in ['z1','z2','z3','z4','z5']"
                        :key="key"
                        class="flex items-center gap-3 px-2 py-1.5 rounded-field transition-colors"
                        :class="paceZoneInfo.key === key ? [zoneColors[key]?.light] : ''"
                    >
                        <div class="h-2 w-2 rounded-full shrink-0" :class="zoneColors[key]?.bg" />
                        <span class="text-xs font-medium text-ink-2 flex-1">{{ paceZones[key]?.name }}</span>
                        <span class="text-xs text-ink-3">{{ paceZones[key]?.min_pace }} – {{ paceZones[key]?.max_pace }} min/km</span>
                        <svg v-if="paceZoneInfo.key === key" class="h-3.5 w-3.5 shrink-0" :class="zoneColors[key]?.text" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Additional stats (max speed, elapsed time) — not shown for cycling -->
            <div v-if="!isCycling" class="grid grid-cols-2 gap-2.5">
                <div v-if="activity.max_speed" class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1">Max. Pace</p>
                    <p class="text-xl font-bold text-ink">{{ formatPace(activity.max_speed) }}</p>
                    <p class="text-xs text-ink-3 mt-0.5">min/km</p>
                </div>
                <div class="bg-surface rounded-card border border-line p-4">
                    <p class="text-xs text-ink-3 mb-1">Gesamtzeit</p>
                    <p class="text-xl font-bold text-ink">{{ formatDuration(activity.elapsed_time) }}</p>
                    <p class="text-xs text-ink-3 mt-0.5">inkl. Pausen</p>
                </div>
            </div>

            <!-- Description -->
            <div v-if="activity.description" class="bg-surface rounded-card border border-line p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-ink mb-2">Beschreibung</h2>
                <p class="text-sm text-ink-2 leading-relaxed">{{ activity.description }}</p>
            </div>

            <!-- Session rating (shown when activity is linked to a training plan session) -->
            <div v-if="linkedSession" class="bg-surface rounded-card border border-line p-4 sm:p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-sm font-semibold text-ink">Einheit bewerten</h2>
                        <p class="text-xs text-ink-3 mt-0.5">{{ linkedSession.title }} · Dein Feedback verbessert den KI-Plan</p>
                    </div>
                    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                        <span v-if="ratingSaved" class="text-xs text-success-ink font-semibold">Gespeichert</span>
                    </Transition>
                </div>

                <!-- Stars -->
                <div class="mb-4">
                    <p class="text-xs font-medium text-ink-3 mb-2">Wie war die Einheit?</p>
                    <div class="flex gap-1.5">
                        <button
                            v-for="star in 5"
                            :key="star"
                            type="button"
                            @click="ratingValue = ratingValue === star ? 0 : star"
                            class="h-9 w-9 rounded-field flex items-center justify-center text-xl transition-colors"
                            :class="star <= ratingValue
                                ? 'bg-warn-soft'
                                : 'bg-surface-2 opacity-40 hover:opacity-70'"
                        >⭐</button>
                        <span class="ml-2 self-center text-sm text-ink-3">
                            {{ ['', 'Sehr schwer', 'Schwer', 'Okay', 'Gut', 'Top'][ratingValue] }}
                        </span>
                    </div>
                </div>

                <!-- RPE -->
                <div class="mb-4">
                    <p class="text-xs font-medium text-ink-3 mb-2">Gefühlte Belastung (RPE)</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="rpe in 10"
                            :key="rpe"
                            type="button"
                            @click="effortValue = effortValue === rpe ? 0 : rpe"
                            class="h-8 w-8 rounded-lg text-xs font-bold transition-colors border"
                            :class="rpe === effortValue
                                ? 'bg-accent border-accent text-white'
                                : 'bg-surface-2 border-line text-ink-3 hover:border-accent'"
                        >{{ rpe }}</button>
                        <span class="ml-1 self-center text-xs text-ink-3">
                            {{ effortValue ? `RPE ${effortValue}/10` : '' }}
                        </span>
                    </div>
                </div>

                <!-- Notes -->
                <div class="mb-4">
                    <textarea
                        v-model="feelingNotes"
                        rows="2"
                        placeholder="Notizen (optional) — was hat gut/schlecht funktioniert?"
                        class="block w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent resize-none transition-colors"
                    />
                </div>

                <p v-if="ratingError" class="text-xs text-danger mb-3">{{ ratingError }}</p>

                <button
                    @click="saveRating"
                    :disabled="ratingSaving || (!ratingValue && !effortValue && !feelingNotes)"
                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-40 transition-colors"
                >
                    <svg v-if="ratingSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    Bewertung speichern
                </button>
            </div>

            <!-- Strava link -->
            <div v-if="activity.strava_id" class="flex justify-center pb-4">
                <a
                    :href="`https://www.strava.com/activities/${activity.strava_id}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-field bg-[#FC4C02] text-white text-sm font-medium hover:bg-[#e84400] transition-colors"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/>
                    </svg>
                    Auf Strava ansehen
                </a>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
