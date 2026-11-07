<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { useDarkMode } from '@/Composables/useDarkMode';
import { baseStyle } from './routeMapStyle';

/**
 * Streckenkarte im dunklen Look (MapLibre + OpenFreeMap, seit 07.11.2026).
 *
 * Die Route leuchtet und ist abschnittsweise eingefärbt — auf der
 * Aktivitätsseite je Runde in ihrer Zonenfarbe. Ein aktiver Abschnitt
 * (Runde angetippt) bleibt hell, die übrigen treten zurück.
 *
 * `segments`: [{ from, to, tone }] — Indizes in `coords`, `tone` ist der
 * Name einer Farbe aus app.css (`success` → `--z-success`). Die Farbe wird
 * beim Zeichnen gelesen, damit sie beim Wechsel hell/dunkel mitgeht.
 */
const props = defineProps({
    coords:   { type: Array, required: true },   // [[lat, lng], …]
    segments: { type: Array, default: () => [] },
    active:   { type: Number, default: null },
});

const el = ref(null);
const { isDark } = useDarkMode();
let map = null;

function cssColor(tone) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(`--z-${tone}`).trim();
    return v ? `rgb(${v.split(/\s+/).join(',')})` : '#0a84ff';
}

const lngLat = ([lat, lng]) => [lng, lat];

function routeData() {
    const n = props.coords.length;
    const segs = props.segments.length ? props.segments : [{ from: 0, to: n - 1, tone: 'accent' }];
    return {
        type: 'FeatureCollection',
        features: segs
            .map((s, i) => ({
                type: 'Feature',
                properties: { i, color: cssColor(s.tone || 'accent') },
                // Ein Punkt Überlappung, damit die Abschnitte ohne Lücke aneinanderstoßen.
                geometry: { type: 'LineString', coordinates: props.coords.slice(s.from, Math.min(n, s.to + 2)).map(lngLat) },
            }))
            .filter((f) => f.geometry.coordinates.length >= 2),
    };
}

/** Deckkraft je Abschnitt: ohne Auswahl alle voll, sonst nur der aktive. */
function opacity(base, dimmed) {
    if (props.active === null || props.active === undefined) return base;
    return ['case', ['==', ['get', 'i'], props.active], base, dimmed];
}

function paintRoute() {
    const dark = isDark.value;
    const set = (id, prop, v) => map.getLayer(id) && map.setPaintProperty(id, prop, v);
    set('route-glow-wide', 'line-opacity', opacity(dark ? 0.45 : 0.22, 0));
    set('route-glow', 'line-opacity', opacity(dark ? 0.7 : 0.35, 0));
    set('route-core', 'line-opacity', opacity(1, 0.25));
    set('route-hot', 'line-opacity', opacity(0.55, 0));
}

function addRoute() {
    const dark = isDark.value;
    const color = ['get', 'color'];
    const layout = { 'line-cap': 'round', 'line-join': 'round' };

    map.addSource('route', { type: 'geojson', data: routeData() });
    map.addLayer({ id: 'route-glow-wide', type: 'line', source: 'route', layout,
        paint: { 'line-color': color, 'line-width': dark ? 18 : 12, 'line-blur': dark ? 14 : 10 } });
    map.addLayer({ id: 'route-glow', type: 'line', source: 'route', layout,
        paint: { 'line-color': color, 'line-width': dark ? 8 : 6, 'line-blur': 4 } });
    map.addLayer({ id: 'route-core', type: 'line', source: 'route', layout,
        paint: { 'line-color': color, 'line-width': 3.2 } });
    if (dark) {
        map.addLayer({ id: 'route-hot', type: 'line', source: 'route', layout,
            paint: { 'line-color': '#ffffff', 'line-width': 1 } });
    }

    // Start als Ring, Ziel als Punkt — bei einer Runde liegt der Punkt im Ring.
    const n = props.coords.length;
    const data = routeData();
    const first = data.features[0]?.properties.color;
    const last = data.features[data.features.length - 1]?.properties.color;
    map.addSource('ends', { type: 'geojson', data: { type: 'FeatureCollection', features: [
        { type: 'Feature', properties: { kind: 'start', color: first }, geometry: { type: 'Point', coordinates: lngLat(props.coords[0]) } },
        { type: 'Feature', properties: { kind: 'end', color: last }, geometry: { type: 'Point', coordinates: lngLat(props.coords[n - 1]) } },
    ] } });
    map.addLayer({ id: 'ends', type: 'circle', source: 'ends', paint: {
        'circle-radius': ['case', ['==', ['get', 'kind'], 'start'], 6, 3],
        'circle-color': ['case', ['==', ['get', 'kind'], 'start'], dark ? '#0b0d12' : '#ffffff', ['get', 'color']],
        'circle-stroke-width': ['case', ['==', ['get', 'kind'], 'start'], 3, 0],
        'circle-stroke-color': ['get', 'color'],
    } });

    paintRoute();
}

onMounted(async () => {
    if (!el.value || props.coords.length < 2) return;
    try {
        const maplibregl = (await import('maplibre-gl')).default;
        await import('maplibre-gl/dist/maplibre-gl.css');

        const bounds = props.coords.reduce(
            (b, c) => b.extend(lngLat(c)),
            new maplibregl.LngLatBounds(lngLat(props.coords[0]), lngLat(props.coords[0])),
        );

        map = new maplibregl.Map({
            container: el.value,
            style: baseStyle(isDark.value),
            bounds,
            fitBoundsOptions: { padding: { top: 56, bottom: 28, left: 28, right: 28 } },
            attributionControl: { compact: false },
            scrollZoom: false,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false,
            maxZoom: 17,
        });
        map.touchZoomRotate.disableRotation();
        // Fires once for the first style and again after every setStyle().
        map.on('style.load', addRoute);
    } catch (e) {
        console.warn('Karte konnte nicht geladen werden:', e);
    }
});

watch(isDark, (dark) => { if (map) map.setStyle(baseStyle(dark), { diff: false }); });
watch(() => props.active, () => { if (map?.isStyleLoaded()) paintRoute(); });

onUnmounted(() => { map?.remove(); map = null; });
</script>

<template>
    <div ref="el" class="route-map" />
</template>

<style>
.route-map .maplibregl-ctrl.maplibregl-ctrl-attrib {
    background: rgb(var(--z-bg) / 0.6);
    font-size: 10px;
    color: rgb(var(--z-ink-3));
}
.route-map .maplibregl-ctrl.maplibregl-ctrl-attrib a { color: rgb(var(--z-ink-3)); }
</style>
