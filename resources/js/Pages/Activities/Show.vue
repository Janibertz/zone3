<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { activityType } from '@/Composables/useActivityTypes';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum, clock, speedOf, distanceOf, paceFromSpeed } from '@/Composables/useFormat';

/**
 * Eine Aktivität im Story-Look (Redesign 28.10.2026).
 *
 * Vorher sieben bis elf gleich große Kacheln, eine davon mit rotem Puls,
 * dazu eine Rundentabelle und eine Zonenkarte mit fünf Zeilen. Jetzt:
 * die Karte randlos oben, drei große Zahlen, die übrigen Werte klein in
 * einer Karte, das Coach-Review, die Runden als Balken in Zonenfarbe und
 * die Zeit je Zone als ein Band.
 */

const props = defineProps({
    activity:      Object,
    paceZones:     Object,
    linkedSession: Object,
    // { estimate, current } — die Fahrt lag über der eingetragenen FTP.
    ftpHint:       Object,
});

// ── Löschen ───────────────────────────────────────────────────────────────────
// Unwiderruflich, deshalb mit Rückfrage. Die Rückfrage sagt auch, was mit der
// verknüpften Trainingseinheit passiert — sonst bliebe unklar, ob der Plan
// eine Lücke bekommt.
const confirmDelete = ref(false);
const deleting      = ref(false);
const deleteError   = ref('');

const deleteMessage = computed(() => {
    const base = 'Die Aktivität wird endgültig gelöscht und beim nächsten Strava-Abgleich nicht erneut geladen.';

    if (!props.linkedSession) return base;

    return props.linkedSession.was_unplanned
        ? `${base} Der dazugehörige Eintrag im Trainingsplan verschwindet mit ihr.`
        : `${base} Die geplante Einheit bleibt im Plan und steht danach wieder als offen.`;
});

async function destroyActivity() {
    deleting.value    = true;
    deleteError.value = '';

    try {
        await axios.delete(route('activities.destroy', props.activity.id));
        router.visit(route('activities.index'));
    } catch (e) {
        deleteError.value = e.response?.data?.message ?? 'Löschen fehlgeschlagen. Bitte versuche es erneut.';
        deleting.value    = false;
    }
}

// ── Runden ────────────────────────────────────────────────────────────────────

const hasLaps = computed(() => Array.isArray(props.activity.laps) && props.activity.laps.length > 1);
const isRun = computed(() => activityType(props.activity.type).group === 'run');
const isCycling = computed(() => activityType(props.activity.type).group === 'ride');

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

const ZONES = ['z1', 'z2', 'z3', 'z4', 'z5'];
/** Dieselben Farben wie die Einheiten: Z2 locker, Z4 Tempo, Z5 Intervall. */
const ZONE_BG = { z1: 'bg-ink-3', z2: 'bg-success', z3: 'bg-accent', z4: 'bg-warn', z5: 'bg-danger' };

/** Zone zu einer Geschwindigkeit (m/s) — nur bei Läufen mit Zonen. */
function zoneOf(speed) {
    if (!isRun.value || !props.paceZones || !speed) return null;
    const sec = 1000 / speed;
    for (const key of ZONES) {
        const z = props.paceZones[key];
        if (!z) continue;
        if (sec >= (paceToSeconds(z.min_pace) ?? 0) && sec <= (paceToSeconds(z.max_pace) ?? Infinity)) return key;
    }
    return null;
}

/** Je Runde: Balkenbreite nach Tempo (schnell = lang, 40–100 %), Farbe nach Zone. */
const lapRows = computed(() => {
    if (!hasLaps.value) return [];
    const speeds = props.activity.laps.map((l) => l.average_speed || 0).filter((v) => v > 0);
    const min = Math.min(...speeds);
    const range = Math.max(...speeds) - min;

    return props.activity.laps.map((lap, i) => {
        const speed = lap.average_speed || 0;
        const zone = zoneOf(speed);
        return {
            n: lap.index ?? i + 1,
            width: speed && range ? Math.round(40 + (speed - min) / range * 60) : 70,
            bar: zone ? ZONE_BG[zone] : 'bg-accent',
            value: isCycling.value ? fmtNum(speed * 3.6) : (paceFromSpeed(speed) ?? '–'),
            distance: fmtNum((lap.distance || 0) / 1000, 2),
            time: clock(lap.moving_time || lap.elapsed_time || 0),
            hr: lap.average_heartrate ? Math.round(lap.average_heartrate) : null,
        };
    });
});

/** Zeit je Zone in Prozent, aus den Runden; ohne Runden der ganze Lauf in einer. */
const zoneShare = computed(() => {
    if (!isRun.value || !props.paceZones) return null;
    const secs = Object.fromEntries(ZONES.map((z) => [z, 0]));
    const laps = hasLaps.value ? props.activity.laps : [{ average_speed: props.activity.average_speed, moving_time: props.activity.moving_time }];
    laps.forEach((l) => {
        const z = zoneOf(l.average_speed);
        if (z) secs[z] += l.moving_time || l.elapsed_time || 0;
    });
    const total = Object.values(secs).reduce((a, b) => a + b, 0);
    if (!total) return null;

    return ZONES.map((z) => ({ key: z, label: z.toUpperCase(), pct: Math.round(secs[z] / total * 100), bg: ZONE_BG[z] }));
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

// ── Kopf und Kennzahlen ───────────────────────────────────────────────────────

const planned = computed(() => props.linkedSession && !props.linkedSession.was_unplanned);

/** Oben farbig: der Typ der geplanten Einheit, sonst die Sportart. */
const label = computed(() => {
    if (planned.value) {
        const t = sessionType(props.linkedSession.type);
        return { text: `${t.label} · geplant und erledigt`, color: t.text, dot: t.dot };
    }
    return { text: activityType(props.activity.type).label, color: 'text-accent', dot: 'bg-accent' };
});

const dateLine = computed(() => {
    const d = new Date(props.activity.start_date);
    const parts = [
        d.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' }),
        d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }),
    ];
    if (props.activity.device_name && props.activity.device_name !== 'unknown') parts.push(props.activity.device_name);
    else if (props.activity.location_city) parts.push(props.activity.location_city);
    return parts.join(' · ');
});

const bigMetrics = computed(() => {
    const a = props.activity;
    const out = [];
    const d = distanceOf(a);
    if (d) out.push({ k: 'Distanz', v: d.value, u: d.unit });
    out.push({ k: 'Zeit', v: clock(a.moving_time), u: null });
    const s = speedOf(a);
    if (s) out.push({ k: s.label, v: s.value, u: s.unit });
    return out;
});

const miniMetrics = computed(() => {
    const a = props.activity;
    const out = [];
    if (a.average_heartrate) out.push({ k: 'Puls Ø', v: Math.round(a.average_heartrate), u: 'bpm' });
    if (a.max_heartrate)     out.push({ k: 'Puls max', v: Math.round(a.max_heartrate), u: 'bpm' });
    if (a.total_elevation_gain > 0) out.push({ k: 'Höhe', v: fmtNum(a.total_elevation_gain, 0), u: 'm' });
    if (isCycling.value && a.average_watts) out.push({ k: 'Leistung', v: Math.round(a.average_watts), u: 'W' });
    if (isCycling.value && a.weighted_average_watts) out.push({ k: 'NP', v: Math.round(a.weighted_average_watts), u: 'W' });
    if (isRun.value && a.max_speed) out.push({ k: 'Schnellste', v: paceFromSpeed(a.max_speed), u: '/km' });
    if (a.elapsed_time && a.elapsed_time - a.moving_time >= 60) out.push({ k: 'Gesamtzeit', v: clock(a.elapsed_time), u: null });
    if (props.linkedSession?.effort_perceived) out.push({ k: 'Gefühl', v: props.linkedSession.effort_perceived, u: '/10' });
    return out;
});

const reviewedAt = computed(() => {
    const at = props.linkedSession?.reviewed_at;
    if (!at) return null;
    const d = new Date(at);
    return `${d.toLocaleDateString('de-DE', { day: 'numeric', month: 'long' })}, ${d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`;
});

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

        mapInstance = L.map(mapContainer.value, { zoomControl: false, scrollWheelZoom: false, attributionControl: true });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap',
            maxZoom: 18,
        }).addTo(mapInstance);

        // Die Linie in der Farbe der Einheit, nicht im alten Indigo.
        const accent = getComputedStyle(document.documentElement).getPropertyValue('--z-accent').trim().split(/\s+/).join(',');
        const polyline = L.polyline(routeCoords, { color: `rgb(${accent || '0,113,227'})`, weight: 4, opacity: 0.95 }).addTo(mapInstance);
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
        <div class="min-h-screen bg-canvas pb-8">
            <div class="mx-auto max-w-3xl lg:px-8 lg:pt-6">
                <!-- ── Karte randlos oben ─────────────────────────── -->
                <div class="relative">
                    <div v-if="activity.polyline" ref="mapContainer" class="z-0 h-[300px] w-full bg-surface-2 lg:rounded-card" />
                    <div v-else class="h-16" />
                    <Link :href="route('activities.index')" aria-label="Zurück"
                        class="absolute left-4 top-4 z-[500] flex h-10 w-10 items-center justify-center rounded-full bg-surface/80 text-ink backdrop-blur-xl transition-transform active:scale-90">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    </Link>
                </div>

                <div class="space-y-7 px-4 pt-5 lg:px-0">
                    <!-- ── Kopf ───────────────────────────────────── -->
                    <header>
                        <p class="flex items-center gap-1.5 text-[15px] font-semibold" :class="label.color">
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="label.dot" />{{ label.text }}
                        </p>
                        <h1 class="mt-1.5 break-words text-[28px] font-extrabold leading-[34px] tracking-[-0.03em] text-ink">{{ activity.name }}</h1>
                        <p class="mt-1 text-[15px] text-ink-3">{{ dateLine }}</p>
                    </header>

                    <div class="grid grid-cols-3 gap-3">
                        <div v-for="m in bigMetrics" :key="m.k" class="min-w-0">
                            <p class="text-[13px] text-ink-3">{{ m.k }}</p>
                            <p class="whitespace-nowrap font-rounded text-[30px] font-extrabold leading-9 tracking-[-0.035em] tabular-nums text-ink">{{ m.v }}<span v-if="m.u" class="ml-0.5 text-[15px] font-semibold tracking-normal text-ink-3">{{ m.u }}</span></p>
                        </div>
                    </div>

                    <div v-if="miniMetrics.length" class="grid grid-cols-3 gap-y-4 rounded-card bg-surface p-4">
                        <div v-for="m in miniMetrics" :key="m.k" class="min-w-0">
                            <p class="text-[13px] text-ink-3">{{ m.k }}</p>
                            <p class="whitespace-nowrap font-rounded text-[20px] font-bold tracking-[-0.02em] tabular-nums text-ink">{{ m.v }}<span v-if="m.u" class="ml-0.5 text-[13px] font-semibold tracking-normal text-ink-3">{{ m.u }}</span></p>
                        </div>
                    </div>

                    <!--
                        Zone3 schätzt keine FTP, aber eine Fahrt kann belegen, dass
                        die eingetragene zu niedrig ist. Übernehmen geht im Profil,
                        wo der Wert steht.
                    -->
                    <div v-if="ftpHint" class="rounded-card bg-warn-soft p-4 text-[15px] text-warn-ink">
                        <p class="font-semibold">Diese Fahrt lag über deiner FTP</p>
                        <p class="mt-1 text-[13px] text-ink-2">
                            Sie spricht für mindestens <span class="font-semibold tabular-nums">{{ ftpHint.estimate }} W</span><template v-if="ftpHint.current">, eingetragen sind {{ ftpHint.current }} W</template>.
                            Mit einer zu niedrigen FTP fallen die Radintervalle zu leicht aus.
                        </p>
                        <Link :href="route('profile.edit')" class="mt-2 inline-block text-[13px] font-semibold text-accent active:opacity-60">
                            FTP im Profil anpassen
                        </Link>
                    </div>

                    <!-- ── Coach ──────────────────────────────────── -->
                    <section v-if="linkedSession?.coach_review">
                        <SectionHeader title="Coach" />
                        <div class="rounded-card bg-surface p-4">
                            <p class="whitespace-pre-line text-[17px] leading-[25px] text-ink">{{ linkedSession.coach_review }}</p>
                            <p v-if="reviewedAt" class="mt-2.5 text-[13px] text-ink-3">Zone3 Coach · {{ reviewedAt }}</p>
                        </div>
                    </section>

                    <!-- ── Runden ─────────────────────────────────── -->
                    <section v-if="hasLaps">
                        <SectionHeader title="Runden">
                            <template #action><span class="text-[13px] text-ink-3">{{ zoneShare ? 'nach Zone' : (isCycling ? 'km/h' : 'Pace') }}</span></template>
                        </SectionHeader>
                        <div class="space-y-1 rounded-card bg-surface p-3">
                            <button v-for="(r, i) in lapRows" :key="i" type="button"
                                class="grid w-full grid-cols-[28px_1fr_56px] items-center gap-2.5 rounded-[8px] px-1 py-[3px] text-left transition-colors"
                                :class="activeLap === i ? 'bg-surface-2' : ''"
                                :aria-label="`Runde ${r.n}: ${r.distance} km in ${r.time}, ${r.value}`"
                                :aria-pressed="activeLap === i"
                                @mouseenter="setActiveLap(i)" @mouseleave="clearActiveLap" @click="toggleActiveLap(i)">
                                <span class="text-[13px] tabular-nums text-ink-3">{{ r.n }}</span>
                                <span class="block h-[18px]">
                                    <span class="block h-full rounded-[5px] transition-opacity" :class="[r.bar, activeLap !== null && activeLap !== i ? 'opacity-30' : '']" :style="{ width: r.width + '%' }" />
                                </span>
                                <span class="text-right text-[15px] font-bold tabular-nums text-ink">{{ r.value }}</span>
                            </button>
                            <p v-if="activeLap !== null && lapRows[activeLap]" class="px-1 pt-2 text-[13px] tabular-nums text-ink-3">
                                Runde {{ lapRows[activeLap].n }} · {{ lapRows[activeLap].distance }} km · {{ lapRows[activeLap].time }}<template v-if="lapRows[activeLap].hr"> · {{ lapRows[activeLap].hr }} bpm</template>
                            </p>
                        </div>
                    </section>

                    <!-- ── Zonen ──────────────────────────────────── -->
                    <section v-if="zoneShare">
                        <SectionHeader title="Zonen" />
                        <div class="rounded-card bg-surface p-4">
                            <div class="flex h-3.5 gap-[2px] overflow-hidden rounded-full" aria-hidden="true">
                                <i v-for="z in zoneShare.filter(z => z.pct > 0)" :key="z.key" class="block" :class="z.bg" :style="{ flex: z.pct }" />
                            </div>
                            <div class="mt-2.5 grid grid-cols-5 text-center text-[13px] text-ink-3">
                                <span v-for="z in zoneShare" :key="z.key">{{ z.label }}<br><b class="font-semibold tabular-nums text-ink">{{ z.pct }} %</b></span>
                            </div>
                        </div>
                    </section>

                    <!-- ── Beschreibung ───────────────────────────── -->
                    <section v-if="activity.description">
                        <SectionHeader title="Notiz" />
                        <p class="whitespace-pre-wrap rounded-card bg-surface p-4 text-[17px] leading-[25px] text-ink-2">{{ activity.description }}</p>
                    </section>

                    <!-- ── Bewerten ───────────────────────────────── -->
                    <section v-if="linkedSession">
                        <SectionHeader title="Wie war's?">
                            <template #action>
                                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="ratingSaved" class="text-[13px] font-semibold text-success">Gespeichert</span>
                                </Transition>
                            </template>
                        </SectionHeader>
                        <div class="rounded-card bg-surface p-4">
                            <div class="flex items-center gap-1">
                                <button v-for="star in 5" :key="star" type="button"
                                    :aria-label="`${star} von 5`" :aria-pressed="star <= ratingValue"
                                    class="flex h-11 w-11 items-center justify-center rounded-full transition-transform active:scale-90"
                                    :class="star <= ratingValue ? 'text-warn' : 'text-surface-3'"
                                    @click="ratingValue = ratingValue === star ? 0 : star">
                                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.6 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.5 6.1 20.6l1.2-6.5L2.5 9.5l6.6-.9Z" /></svg>
                                </button>
                                <span class="ml-2 text-[15px] text-ink-3">{{ ['', 'Sehr schwer', 'Schwer', 'Okay', 'Gut', 'Top'][ratingValue] }}</span>
                            </div>

                            <p class="mb-2 mt-4 text-[13px] text-ink-3">Gefühlte Belastung (1–10)</p>
                            <div class="grid grid-cols-10 gap-1">
                                <button v-for="rpe in 10" :key="rpe" type="button"
                                    class="h-10 rounded-[10px] text-[15px] font-semibold tabular-nums transition active:scale-95"
                                    :class="rpe === effortValue ? 'bg-accent text-white' : 'bg-surface-2 text-ink-2'"
                                    @click="effortValue = effortValue === rpe ? 0 : rpe">{{ rpe }}</button>
                            </div>

                            <label for="feeling-notes" class="sr-only">Notiz</label>
                            <textarea id="feeling-notes" v-model="feelingNotes" rows="2"
                                placeholder="Was lief gut, was nicht? (optional)"
                                class="z-input mt-4 resize-none" />

                            <p v-if="ratingError" class="z-error">{{ ratingError }}</p>

                            <AppButton block class="mt-3" :loading="ratingSaving"
                                :disabled="!ratingValue && !effortValue && !feelingNotes" @click="saveRating">
                                Sichern
                            </AppButton>
                        </div>
                    </section>

                    <!-- ── Herkunft und Löschen ───────────────────── -->
                    <section class="space-y-3">
                        <div v-if="activity.strava_id || activity.garmin_activity_id" class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                            <a v-if="activity.strava_id" :href="`https://www.strava.com/activities/${activity.strava_id}`" target="_blank" rel="noopener noreferrer"
                                class="flex min-h-[44px] items-center gap-3 px-4 py-3 transition-colors active:bg-surface-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-[#FC4C02] text-white">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/></svg>
                                </span>
                                <span class="flex-1 text-[17px] text-ink">Auf Strava ansehen</span>
                                <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </a>
                            <!-- Garmin verlangt die Herkunftsangabe. -->
                            <a v-if="activity.garmin_activity_id" :href="`https://connect.garmin.com/modern/activity/${activity.garmin_activity_id}`" target="_blank" rel="noopener noreferrer"
                                class="flex min-h-[44px] items-center gap-3 px-4 py-3 transition-colors active:bg-surface-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="7" /><path stroke-linecap="round" d="M12 9v3l2 1.5" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">In Garmin Connect ansehen</span>
                                    <span v-if="activity.device_name && activity.device_name !== 'unknown'" class="block text-[13px] text-ink-3">Aufgezeichnet mit {{ activity.device_name }}</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </a>
                        </div>

                        <p v-if="deleteError" class="z-error text-center">{{ deleteError }}</p>
                        <div class="overflow-hidden rounded-card bg-surface">
                            <button type="button" class="flex min-h-[44px] w-full items-center px-4 py-3 text-left text-[17px] text-danger transition-colors active:bg-surface-2"
                                @click="confirmDelete = true">
                                Aktivität löschen
                            </button>
                        </div>
                    </section>
                </div>
            </div>

            <ConfirmSheet
                :show="confirmDelete"
                title="Aktivität löschen?"
                :message="deleteMessage"
                confirm-label="Endgültig löschen"
                :loading="deleting"
                @confirm="destroyActivity"
                @close="confirmDelete = false"
            />
        </div>
    </AuthenticatedLayout>
</template>
