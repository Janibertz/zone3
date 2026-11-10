/**
 * Fotos zur Einheit (Mockup „Zone3 mit mehr Leben“, von Jan am 10.11.2026
 * abgenommen): Tempo und Intervalle auf der Straße, locker im Feld, lang
 * auf dem Weg, Rennen im Startfeld, Ruhe und Kraft bei den Beinen. Alle
 * Fotos von Unsplash, lokal gespeichert, ohne Marken und ohne Gesichter im
 * Vordergrund.
 */
const PHOTOS = {
    street: { src: '/images/moments/bestzeit.jpg', pos: 'center 35%' },
    field:  { src: '/images/moments/feld.jpg',     pos: 'center 50%' },
    long:   { src: '/images/moments/lang.jpg',     pos: 'center 55%' },
    race:   { src: '/images/moments/rennen.jpg',   pos: 'center 30%' },
    legs:   { src: '/images/moments/beine.jpg',    pos: 'center 45%' },
};

const BY_TYPE = {
    tempo_run: 'street', interval: 'street', progressive_run: 'street', test_run: 'street',
    easy_run: 'field', cross_training: 'field', night_run: 'field',
    long_run: 'long', back_to_back_long: 'long', time_on_feet: 'long', yard_simulation: 'long',
    race_prep: 'race',
    rest: 'legs', strength: 'legs', core: 'legs', mobility: 'legs',
};

/** Foto für den Aufmacher: jede Einheit hat eins. */
export function heroPhoto(type) {
    return PHOTOS[BY_TYPE[type] ?? 'field'];
}

/** Foto für eine Kachel: Kraft, Rumpf und Mobilität sind Farbe statt Bild. */
export function tilePhoto(type) {
    if (['strength', 'core', 'mobility', 'rest'].includes(type)) return null;
    return PHOTOS[BY_TYPE[type] ?? 'field'];
}

export const RACE_PHOTO = PHOTOS.race;
