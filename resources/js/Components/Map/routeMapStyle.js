/**
 * Kartenstil der Streckenkarte (seit 07.11.2026, Variante „Zonen", von Jan
 * gewählt). Vektorkacheln von OpenFreeMap — kostenlos, ohne Schlüssel, auch
 * kommerziell erlaubt; die Namensnennung ist Pflicht und steht in der Quelle.
 *
 * Bewusst ein eigener, kleiner Stil statt eines fertigen: wenig Farbe,
 * gedämpfte Schrift, damit die Route der hellste Punkt der Karte ist.
 * Dunkel und hell folgen dem Theme der App.
 */

export const TILE_SOURCE = 'https://tiles.openfreemap.org/planet';
export const GLYPHS = 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf';
export const ATTRIBUTION =
    '<a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a> '
    + '© <a href="https://www.openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> '
    + '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';

const PALETTE = {
    dark: {
        bg: '#0b0d12', water: '#0e1a2b', green: '#0d1712', building: '#12151c',
        road: '#232936', roadMajor: '#323a4a', path: '#1d222c',
        label: '#6b7487', place: '#5a6374', halo: '#0b0d12',
    },
    light: {
        bg: '#eceef2', water: '#cfdcec', green: '#dde8dc', building: '#e2e4e9',
        road: '#ffffff', roadMajor: '#ffffff', path: '#e4e6ea',
        label: '#8a8f99', place: '#a0a5ae', halo: '#eceef2',
    },
};

const isLine = ['match', ['geometry-type'], ['LineString', 'MultiLineString'], true, false];
const cls = (...c) => ['match', ['get', 'class'], c, true, false];
const width = (a, b) => ['interpolate', ['exponential', 1.5], ['zoom'], 12, a * 0.6, 13, a, 17, b];

export function baseStyle(dark) {
    const c = dark ? PALETTE.dark : PALETTE.light;
    const src = { source: 'base' };

    return {
        version: 8,
        glyphs: GLYPHS,
        sources: { base: { type: 'vector', url: TILE_SOURCE, attribution: ATTRIBUTION } },
        layers: [
            { id: 'bg', type: 'background', paint: { 'background-color': c.bg } },
            { id: 'green', type: 'fill', ...src, 'source-layer': 'landcover', filter: cls('wood', 'grass'), paint: { 'fill-color': c.green } },
            { id: 'park', type: 'fill', ...src, 'source-layer': 'park', paint: { 'fill-color': c.green } },
            { id: 'water', type: 'fill', ...src, 'source-layer': 'water', paint: { 'fill-color': c.water } },
            { id: 'waterway', type: 'line', ...src, 'source-layer': 'waterway', paint: { 'line-color': c.water, 'line-width': 1.5 } },
            { id: 'building', type: 'fill', ...src, 'source-layer': 'building', minzoom: 13, paint: { 'fill-color': c.building } },
            { id: 'path', type: 'line', ...src, 'source-layer': 'transportation', filter: ['all', isLine, cls('path', 'track')],
                paint: { 'line-color': c.path, 'line-width': width(0.6, 2), 'line-dasharray': [2, 1.5] } },
            { id: 'minor', type: 'line', ...src, 'source-layer': 'transportation', filter: ['all', isLine, cls('minor', 'service')],
                layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': c.road, 'line-width': width(1.4, 7) } },
            { id: 'major', type: 'line', ...src, 'source-layer': 'transportation', filter: ['all', isLine, cls('tertiary', 'secondary', 'primary', 'trunk', 'motorway')],
                layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': c.roadMajor, 'line-width': width(1.6, 11) } },
            { id: 'street-label', type: 'symbol', ...src, 'source-layer': 'transportation_name', minzoom: 13,
                layout: { 'symbol-placement': 'line', 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'], 'text-size': 10, 'text-letter-spacing': 0.04 },
                paint: { 'text-color': c.label, 'text-halo-color': c.halo, 'text-halo-width': 1.2 } },
            { id: 'place', type: 'symbol', ...src, 'source-layer': 'place', filter: cls('suburb', 'quarter', 'neighbourhood'),
                layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'], 'text-size': 11, 'text-transform': 'uppercase', 'text-letter-spacing': 0.25 },
                paint: { 'text-color': c.place, 'text-halo-color': c.halo, 'text-halo-width': 1.5 } },
        ],
    };
}
