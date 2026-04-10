<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    activity: Object,
    paceZones: Object,
});

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
    z1: { bg: 'bg-blue-500',   light: 'bg-blue-50 dark:bg-blue-500/10',   text: 'text-blue-700 dark:text-blue-300',   label: 'Zone 1' },
    z2: { bg: 'bg-green-500',  light: 'bg-green-50 dark:bg-green-500/10', text: 'text-green-700 dark:text-green-300', label: 'Zone 2' },
    z3: { bg: 'bg-yellow-500', light: 'bg-yellow-50 dark:bg-yellow-500/10', text: 'text-yellow-700 dark:text-yellow-300', label: 'Zone 3' },
    z4: { bg: 'bg-orange-500', light: 'bg-orange-50 dark:bg-orange-500/10', text: 'text-orange-700 dark:text-orange-300', label: 'Zone 4' },
    z5: { bg: 'bg-red-500',    light: 'bg-red-50 dark:bg-red-500/10',     text: 'text-red-700 dark:text-red-300',     label: 'Zone 5' },
};

// ── Type badges ───────────────────────────────────────────────────────────────

const typeLabel = { Run: 'Laufen', Ride: 'Radeln', Swim: 'Schwimmen', Walk: 'Gehen', Workout: 'Workout' };
const typeColors = {
    Run:     'bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    Ride:    'bg-green-100 dark:bg-green-500/15 text-green-700 dark:text-green-300',
    Swim:    'bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300',
    Workout: 'bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300',
    Walk:    'bg-yellow-100 dark:bg-yellow-500/15 text-yellow-700 dark:text-yellow-300',
};
function typeColor(t) {
    return typeColors[t] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300';
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
let mapInstance = null;

onMounted(async () => {
    if (!props.activity.polyline || !mapContainer.value) return;

    try {
        const coords = decodePolyline(props.activity.polyline);
        if (coords.length === 0) return;

        const L = (await import('leaflet')).default;
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

        const polyline = L.polyline(coords, { color: '#4f46e5', weight: 3, opacity: 0.85 }).addTo(mapInstance);
        mapInstance.fitBounds(polyline.getBounds(), { padding: [16, 16] });

        // Start + End markers
        L.circleMarker(coords[0], { radius: 7, color: '#16a34a', fillColor: '#16a34a', fillOpacity: 1, weight: 2 }).addTo(mapInstance);
        L.circleMarker(coords[coords.length - 1], { radius: 7, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 1, weight: 2 }).addTo(mapInstance);
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
                    class="shrink-0 mt-0.5 h-8 w-8 flex items-center justify-center rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white leading-tight">{{ activity.name }}</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {{ formatDate(activity.start_date) }}
                        <span class="text-gray-300 dark:text-slate-600 mx-1">·</span>
                        {{ formatTime(activity.start_date) }} Uhr
                        <template v-if="activity.location_city">
                            <span class="text-gray-300 dark:text-slate-600 mx-1">·</span>
                            {{ activity.location_city }}<template v-if="activity.location_country">, {{ activity.location_country }}</template>
                        </template>
                    </p>
                </div>
            </div>

            <!-- Map -->
            <div
                v-if="activity.polyline"
                ref="mapContainer"
                class="w-full h-56 sm:h-72 rounded-2xl overflow-hidden border border-gray-100 dark:border-slate-800 bg-gray-100 dark:bg-slate-800"
            />
            <div
                v-else
                class="w-full h-28 rounded-2xl bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-800 flex items-center justify-center"
            >
                <p class="text-sm text-gray-400 dark:text-slate-500">Keine Kartendaten verfügbar</p>
            </div>

            <!-- Key stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Distanz</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none">{{ (activity.distance / 1000).toFixed(2) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">km</p>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Bewegungszeit</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none">{{ formatDuration(activity.moving_time) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">h:min:s</p>
                </div>
                <div v-if="activity.type === 'Run'" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Pace</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none">{{ formatPace(activity.average_speed) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">min/km</p>
                </div>
                <div v-if="activity.total_elevation_gain > 0" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Höhenmeter</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none">{{ Math.round(activity.total_elevation_gain) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">m</p>
                </div>
                <div v-if="activity.type !== 'Run'" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Gesamtzeit</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none">{{ formatDuration(activity.elapsed_time) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">h:min:s</p>
                </div>
            </div>

            <!-- Heart rate -->
            <div v-if="activity.average_heartrate" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                    <span class="text-red-400">♥</span> Herzfrequenz
                </h2>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">Durchschnitt</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ Math.round(activity.average_heartrate) }}<span class="text-sm font-normal text-gray-400 dark:text-slate-500 ml-1">bpm</span></p>
                    </div>
                    <div v-if="activity.max_heartrate">
                        <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">Maximum</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ Math.round(activity.max_heartrate) }}<span class="text-sm font-normal text-gray-400 dark:text-slate-500 ml-1">bpm</span></p>
                    </div>
                </div>
                <!-- HR bar visual -->
                <div v-if="activity.max_heartrate" class="relative h-2 bg-gray-100 dark:bg-slate-800 rounded-full overflow-hidden">
                    <!-- Avg marker -->
                    <div
                        class="absolute top-0 h-full bg-red-400 rounded-full"
                        :style="{ width: (activity.average_heartrate / activity.max_heartrate * 100).toFixed(0) + '%' }"
                    />
                    <div
                        class="absolute top-1/2 -translate-y-1/2 w-3 h-3 bg-red-600 rounded-full border-2 border-white dark:border-slate-900 shadow"
                        :style="{ left: 'calc(' + (activity.average_heartrate / activity.max_heartrate * 100).toFixed(0) + '% - 6px)' }"
                    />
                </div>
                <div v-if="activity.max_heartrate" class="flex justify-between mt-1">
                    <span class="text-xs text-gray-400 dark:text-slate-500">0</span>
                    <span class="text-xs text-gray-400 dark:text-slate-500">{{ Math.round(activity.max_heartrate) }} bpm max</span>
                </div>
            </div>

            <!-- Pace Zone indicator -->
            <div v-if="paceZoneInfo && paceZones" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Tempo-Zonen</h2>
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
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center shrink-0" :class="zoneColors[paceZoneInfo.key]?.light">
                        <span class="text-lg font-bold" :class="zoneColors[paceZoneInfo.key]?.text">{{ paceZoneInfo.key.replace('z','') }}</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ paceZoneInfo.name }}</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400">{{ paceZoneInfo.minPace }} – {{ paceZoneInfo.maxPace }} min/km</p>
                    </div>
                    <div class="ml-auto text-right">
                        <p class="text-xs text-gray-500 dark:text-slate-400">Dein Tempo</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ formatPace(activity.average_speed) }} min/km</p>
                    </div>
                </div>

                <!-- All zones list -->
                <div class="mt-3 space-y-1.5 border-t border-gray-100 dark:border-slate-800 pt-3">
                    <div
                        v-for="key in ['z1','z2','z3','z4','z5']"
                        :key="key"
                        class="flex items-center gap-3 px-2 py-1.5 rounded-xl transition-colors"
                        :class="paceZoneInfo.key === key ? [zoneColors[key]?.light] : ''"
                    >
                        <div class="h-2 w-2 rounded-full shrink-0" :class="zoneColors[key]?.bg" />
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300 flex-1">{{ paceZones[key]?.name }}</span>
                        <span class="text-xs text-gray-400 dark:text-slate-500">{{ paceZones[key]?.min_pace }} – {{ paceZones[key]?.max_pace }} min/km</span>
                        <svg v-if="paceZoneInfo.key === key" class="h-3.5 w-3.5 shrink-0" :class="zoneColors[key]?.text" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Additional stats (max speed, elapsed time) -->
            <div class="grid grid-cols-2 gap-2.5">
                <div v-if="activity.max_speed" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">Max. Pace</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white">{{ formatPace(activity.max_speed) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">min/km</p>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">Gesamtzeit</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white">{{ formatDuration(activity.elapsed_time) }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">inkl. Pausen</p>
                </div>
            </div>

            <!-- Description -->
            <div v-if="activity.description" class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Beschreibung</h2>
                <p class="text-sm text-gray-600 dark:text-slate-400 leading-relaxed">{{ activity.description }}</p>
            </div>

            <!-- Strava link -->
            <div v-if="activity.strava_id" class="flex justify-center pb-4">
                <a
                    :href="`https://www.strava.com/activities/${activity.strava_id}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#FC4C02] text-white text-sm font-medium hover:bg-[#e84400] transition-colors"
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
