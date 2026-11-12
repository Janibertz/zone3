/**
 * Fotos zur Einheit (Mockup „Zone3 mit mehr Leben“, von Jan am 10.11.2026
 * abgenommen; eigene Fotos für Ruhetag, Intervall, Kraft und Rad am
 * 11.11.2026): Tempo auf der Straße, Intervalle verwischt im Lauf, locker
 * im Feld, lang auf dem Weg, Rennen im Startfeld, Ruhetag mit Buch und
 * Tasse, Kraft/Rumpf/Mobilität im Studio, Rad vor dem roten Bildschirm,
 * Schwimmen am leeren Becken mit Paddles (Jan, 12.11.2026, Foto von
 * Marcelo Uva; oben abgeschnitten, damit die adidas-Banner fehlen).
 * Alle Fotos von Unsplash, lokal gespeichert, ohne Gesichter im
 * Vordergrund. Ausnahme bei den Marken: das Kraftfoto zeigt Gerätenamen
 * (Jans ausdrückliche Wahl); beim Rad sind die Schilder abgeschnitten.
 */
const PHOTOS = {
    street:   { src: '/images/moments/bestzeit.jpg',  pos: 'center 35%' },
    interval: { src: '/images/moments/intervall.jpg', pos: 'center 45%' },
    field:    { src: '/images/moments/feld.jpg',      pos: 'center 50%' },
    long:     { src: '/images/moments/lang.jpg',      pos: 'center 55%' },
    race:     { src: '/images/moments/rennen.jpg',    pos: 'center 30%' },
    rest:     { src: '/images/moments/ruhetag.jpg',   pos: '42% 60%' },
    strength: { src: '/images/moments/kraft.jpg',     pos: 'center 82%' },
    ride:     { src: '/images/moments/rad.jpg',       pos: '55% 48%' },
    swim:     { src: '/images/moments/schwimmen.jpg', pos: 'center 45%' },
};

const BY_TYPE = {
    tempo_run: 'street', progressive_run: 'street', test_run: 'street',
    interval: 'interval',
    easy_run: 'field', cross_training: 'field', night_run: 'field',
    long_run: 'long', back_to_back_long: 'long', time_on_feet: 'long', yard_simulation: 'long',
    race_prep: 'race',
    rest: 'rest',
    strength: 'strength', core: 'strength', mobility: 'strength',
};

const RIDES = ['Ride', 'VirtualRide'];

function key(type, sportType) {
    if (RIDES.includes(sportType)) return 'ride';
    if (sportType === 'Swim') return 'swim';
    return BY_TYPE[type] ?? 'field';
}

/** Foto für den Aufmacher: jede Einheit hat eins, eine Radeinheit das Rad. */
export function heroPhoto(type, sportType = null) {
    return PHOTOS[key(type, sportType)];
}

/** Foto für eine Kachel: jede Einheit außer dem Ruhetag (der steht nie in „Als Nächstes“). */
export function tilePhoto(type, sportType = null) {
    if (type === 'rest') return null;
    return PHOTOS[key(type, sportType)];
}

/**
 * Foto zu einer Aktivität (Stravas `type`), wo der Läufer nicht passt:
 * Aufmacher einer Aktivität ohne Strecke und Vorlage beim Teilen.
 * null = kein eigenes Foto für diese Sportart.
 */
export function activityPhoto(activityType) {
    if (activityType === 'Swim') return PHOTOS.swim;
    return null;
}

export const RACE_PHOTO = PHOTOS.race;
