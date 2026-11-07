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
    // Fest gewählter Abschnitt: wird ins Bild geholt (Hover nur hervorgehoben).
    focus:    { type: Number, default: null },
    // 'zones': jede Runde in ihrer Zonenfarbe. 'calm': eine Farbe, die Zone
    // zeigt nur die angetippte Runde.
    mode:     { type: String, default: 'zones' },
    // Echte Strecke in Metern — für die Kilometermarken (die Linie ist
    // vereinfacht, darum wird ihr Verlauf auf diese Länge skaliert).
    distance: { type: Number, default: 0 },
    // Freiraum für das, was über der Karte liegt (Daten unten).
    padding:  { type: Object, default: () => ({ top: 56, bottom: 28, left: 28, right: 28 }) },
});

const el = ref(null);
const { isDark } = useDarkMode();
let map = null;
let maplib = null;

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
                properties: {
                    i,
                    zone: cssColor(s.tone || 'accent'),
                    color: props.mode === 'calm' ? cssColor('accent') : cssColor(s.tone || 'accent'),
                },
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

/** Im ruhigen Modus bekommt nur die angetippte Runde ihre Zonenfarbe. */
function lineColor() {
    if (props.active === null || props.active === undefined) return ['get', 'color'];
    return ['case', ['==', ['get', 'i'], props.active], ['get', 'zone'], ['get', 'color']];
}

/** Punkte je 1 km (kurze Läufe) bzw. je 5 km entlang der Linie, skaliert auf die echte Strecke. */
function kmMarkers() {
    const c = props.coords;
    if (c.length < 2 || !props.distance) return [];
    const R = 6371000, rad = Math.PI / 180;
    const cum = [0];
    for (let k = 1; k < c.length; k++) {
        const dLat = (c[k][0] - c[k - 1][0]) * rad, dLng = (c[k][1] - c[k - 1][1]) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(c[k - 1][0] * rad) * Math.cos(c[k][0] * rad) * Math.sin(dLng / 2) ** 2;
        cum[k] = cum[k - 1] + 2 * R * Math.asin(Math.sqrt(h));
    }
    const scale = cum[cum.length - 1] / props.distance;
    const step = props.distance > 12000 ? 5 : 1;
    const out = [];
    let k = 0;
    for (let km = step; km * 1000 < props.distance - 300; km += step) {
        const target = km * 1000 * scale;
        while (k < c.length - 1 && cum[k + 1] < target) k++;
        out.push({ type: 'Feature', properties: { km: String(km) }, geometry: { type: 'Point', coordinates: lngLat(c[k]) } });
    }
    return out;
}

function paintRoute() {
    const dark = isDark.value;
    const set = (id, prop, v) => map.getLayer(id) && map.setPaintProperty(id, prop, v);
    ['route-glow-wide', 'route-glow', 'route-core'].forEach((id) => set(id, 'line-color', lineColor()));
    const on = props.active !== null && props.active !== undefined;
    set('route-core', 'line-width', on ? ['case', ['==', ['get', 'i'], props.active], 5, 2.5] : 3.2);
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
        paint: { 'line-color': color, 'line-width': dark ? 14 : 10, 'line-blur': dark ? 12 : 8 } });
    map.addLayer({ id: 'route-glow', type: 'line', source: 'route', layout,
        paint: { 'line-color': color, 'line-width': dark ? 6 : 5, 'line-blur': 3 } });
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

    const marks = kmMarkers();
    if (marks.length) {
        map.addSource('km', { type: 'geojson', data: { type: 'FeatureCollection', features: marks } });
        map.addLayer({ id: 'km-dot', type: 'circle', source: 'km', paint: {
            'circle-radius': 8,
            'circle-color': dark ? '#1c1c1e' : '#ffffff',
            'circle-stroke-width': 1,
            'circle-stroke-color': dark ? 'rgba(255,255,255,0.35)' : 'rgba(0,0,0,0.2)',
        } });
        map.addLayer({ id: 'km-label', type: 'symbol', source: 'km',
            layout: { 'text-field': ['get', 'km'], 'text-font': ['Noto Sans Regular'], 'text-size': 9, 'text-allow-overlap': true },
            paint: { 'text-color': dark ? '#ffffff' : '#000000' } });
    }

    paintRoute();
}

/** Angetippte Runde ins Bild holen, ohne Auswahl wieder die ganze Strecke. */
function frame(animate = true) {
    if (!map || !maplib) return;
    const n = props.coords.length;
    const seg = props.focus !== null && props.focus !== undefined ? props.segments[props.focus] : null;
    const pts = seg ? props.coords.slice(seg.from, Math.min(n, seg.to + 2)) : props.coords;
    if (pts.length < 2) return;
    const b = pts.reduce((acc, c) => acc.extend(lngLat(c)), new maplib.LngLatBounds(lngLat(pts[0]), lngLat(pts[0])));
    map.fitBounds(b, { padding: props.padding, maxZoom: 16, duration: animate ? 600 : 0 });
}

onMounted(async () => {
    if (!el.value || props.coords.length < 2) return;
    try {
        const maplibregl = (await import('maplibre-gl')).default;
        maplib = maplibregl;
        await import('maplibre-gl/dist/maplibre-gl.css');

        const bounds = props.coords.reduce(
            (b, c) => b.extend(lngLat(c)),
            new maplibregl.LngLatBounds(lngLat(props.coords[0]), lngLat(props.coords[0])),
        );

        map = new maplibregl.Map({
            container: el.value,
            style: baseStyle(isDark.value),
            bounds,
            fitBoundsOptions: { padding: props.padding },
            attributionControl: false,
            scrollZoom: false,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false,
            maxZoom: 17,
        });
        map.touchZoomRotate.disableRotation();
        // Oben rechts: unten liegen die Daten über der Karte.
        map.addControl(new maplibregl.AttributionControl({ compact: false }), 'top-right');
        // Fires once for the first style and again after every setStyle().
        map.on('style.load', addRoute);
    } catch (e) {
        console.warn('Karte konnte nicht geladen werden:', e);
    }
});

watch(isDark, (dark) => { if (map) map.setStyle(baseStyle(dark), { diff: false }); });
watch(() => props.active, () => { if (map?.isStyleLoaded()) paintRoute(); });
watch(() => props.focus, () => frame());

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
