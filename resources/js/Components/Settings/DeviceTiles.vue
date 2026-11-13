<script setup>
/**
 * Geräte & Apps als Kacheln (Jan, 10.11.2026, nach einem Mockup im Stil
 * einer anderen Lauf-App). Eine Kachel je Anbieter mit einer eigenen
 * Uhr-Zeichnung und dem Namen als Schrift — bewusst keine Hersteller-Logos
 * oder Produktfotos (Markenrechte; bei Apple ohnehin nur der Name).
 *
 * Apple Watch und Apple Health sind EINE Kachel: das eine legt den Plan auf
 * die Uhr (WorkoutKit), das andere liest Werte und Läufe. Zwei Kacheln für
 * Apple ließen glauben, das eine schalte das andere.
 *
 * Die Kacheln zeigen nur, was es gibt, und führen in den Abschnitt des
 * Profils mit den Einstellungen (Anker `conn-…`).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    garmin:      { type: Boolean, default: false },
    coros:       { type: Boolean, default: null },   // null = auf dem Server aus
    polar:       { type: Boolean, default: null },   // null = auf dem Server nicht eingerichtet
    strava:      { type: Boolean, default: null },   // null = nicht freigeschaltet
    apple:       { type: Boolean, default: null },   // null = nicht angeboten (Browser)
    appleDetail: { type: String,  default: '' },
});

const connections = (anchor) => route('profile.edit', { section: 'connections' }) + '#' + anchor;

const tiles = computed(() => [
    { key: 'garmin', name: 'GARMIN', track: 'tracking-[0.08em]', art: 'round', on: props.garmin, href: connections('conn-garmin') },
    ...(props.coros !== null ? [{ key: 'coros', name: 'COROS', track: 'tracking-[0.12em]', art: 'crown', on: props.coros, href: connections('conn-coros') }] : []),
    ...(props.polar !== null ? [{ key: 'polar', name: 'POLAR', track: 'tracking-[0.14em]', art: 'band', on: props.polar, href: connections('conn-polar') }] : []),
    ...(props.apple !== null ? [{ key: 'apple', name: 'Apple Watch', track: '', art: 'square', on: props.apple, detail: props.appleDetail, href: connections('conn-apple') }] : []),
    ...(props.strava !== null ? [{ key: 'strava', name: 'STRAVA', track: 'tracking-[0.1em]', art: 'phone', on: props.strava, href: connections('conn-strava') }] : []),
    { key: 'file', name: 'FIT-Datei', track: '', art: 'file', on: null, detail: 'Zwift & Co. hochladen', href: route('activities.index') },
]);
</script>

<template>
    <div class="grid grid-cols-2 gap-3">
        <Link
            v-for="t in tiles"
            :key="t.key"
            :href="t.href"
            class="flex flex-col items-center gap-2.5 rounded-[18px] bg-surface px-3 pb-3.5 pt-4 transition active:scale-[0.98] active:bg-surface-2"
            :class="t.on ? 'ring-[1.5px] ring-inset ring-success/60' : ''"
        >
            <!-- Runde Sportuhr -->
            <svg v-if="t.art === 'round'" class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <rect x="30" y="0" width="36" height="26" rx="6" class="fill-surface-3" />
                <rect x="30" y="90" width="36" height="26" rx="6" class="fill-surface-3" />
                <rect x="88" y="44" width="5" height="12" rx="2" class="fill-line-strong" />
                <rect x="3" y="40" width="5" height="10" rx="2" class="fill-line-strong" />
                <rect x="3" y="62" width="5" height="10" rx="2" class="fill-line-strong" />
                <circle cx="48" cy="58" r="40" class="fill-line-strong" />
                <circle cx="48" cy="58" r="33" fill="#0B0B0C" />
                <path d="M24 46 A27 27 0 0 1 72 46" fill="none" stroke="#30D158" stroke-width="3.5" stroke-linecap="round" />
                <text x="48" y="63" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF">5:34</text>
                <text x="48" y="77" text-anchor="middle" font-size="8" fill="#8E8E93">/km</text>
            </svg>

            <!-- Runde Uhr mit Krone -->
            <svg v-else-if="t.art === 'crown'" class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <rect x="32" y="0" width="32" height="26" rx="5" class="fill-surface-3" />
                <rect x="32" y="90" width="32" height="26" rx="5" class="fill-surface-3" />
                <rect x="87" y="50" width="7" height="16" rx="3" class="fill-line-strong" />
                <circle cx="48" cy="58" r="39" class="fill-line-strong" />
                <circle cx="48" cy="58" r="32" fill="#0B0B0C" />
                <circle cx="48" cy="58" r="26" fill="none" stroke="#2C2C2E" stroke-width="3" />
                <path d="M48 32 A26 26 0 0 1 72 66" fill="none" stroke="#FF9F0A" stroke-width="3" stroke-linecap="round" />
                <text x="48" y="63" text-anchor="middle" font-size="14" font-weight="700" fill="#FFFFFF">10,2</text>
                <text x="48" y="76" text-anchor="middle" font-size="8" fill="#8E8E93">km</text>
            </svg>

            <!-- Schmale Sportuhr mit Herzfrequenz -->
            <svg v-else-if="t.art === 'band'" class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <rect x="31" y="0" width="34" height="28" rx="6" class="fill-surface-3" />
                <rect x="31" y="88" width="34" height="28" rx="6" class="fill-surface-3" />
                <rect x="86" y="38" width="5" height="11" rx="2" class="fill-line-strong" />
                <rect x="86" y="67" width="5" height="11" rx="2" class="fill-line-strong" />
                <circle cx="48" cy="58" r="38" class="fill-line-strong" />
                <circle cx="48" cy="58" r="31" fill="#0B0B0C" />
                <path d="M27 56h9l4-8 6 16 4-8h19" fill="none" stroke="#FF375F" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
                <text x="48" y="80" text-anchor="middle" font-size="12" font-weight="700" fill="#FFFFFF">142</text>
            </svg>

            <!-- Eckige Smartwatch -->
            <svg v-else-if="t.art === 'square'" class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <rect x="28" y="0" width="40" height="24" rx="8" fill="#D8D2C4" />
                <rect x="28" y="92" width="40" height="24" rx="8" fill="#D8D2C4" />
                <rect x="80" y="40" width="6" height="16" rx="3" fill="#8E8E93" />
                <rect x="14" y="18" width="68" height="80" rx="20" fill="#AEAEB2" />
                <rect x="19" y="23" width="58" height="70" rx="16" fill="#0B0B0C" />
                <circle cx="31" cy="36" r="5" fill="none" stroke="#30D158" stroke-width="2" />
                <text x="27" y="57" font-size="12" font-weight="700" fill="#FFFFFF">18:41</text>
                <text x="27" y="70" font-size="9" font-weight="700" fill="#FF453A">165 bpm</text>
                <text x="27" y="82" font-size="9" font-weight="700" fill="#FFFFFF">2,84 km</text>
            </svg>

            <!-- Telefon mit Strecke -->
            <svg v-else-if="t.art === 'phone'" class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <rect x="24" y="8" width="48" height="100" rx="12" class="fill-line-strong" />
                <rect x="28" y="14" width="40" height="88" rx="8" fill="#0B0B0C" />
                <path d="M36 86c4-14 14-6 16-20s12-10 8-26" fill="none" stroke="#FC4C02" stroke-width="3" stroke-linecap="round" />
                <circle cx="36" cy="86" r="3.5" fill="#30D158" />
                <circle cx="60" cy="40" r="3.5" fill="#FFFFFF" />
            </svg>

            <!-- Datei -->
            <svg v-else class="h-[104px] w-[86px]" viewBox="0 0 96 116" aria-hidden="true">
                <path d="M26 16h30l16 16v68a6 6 0 0 1-6 6H26a6 6 0 0 1-6-6V22a6 6 0 0 1 6-6Z" class="fill-surface-2" />
                <path d="M56 16v12a4 4 0 0 0 4 4h12" class="fill-line-strong" />
                <path d="M46 88V56M34 68l12-12 12 12" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="text-accent" />
            </svg>

            <span class="text-[18px] font-extrabold text-ink" :class="t.track">{{ t.name }}</span>
            <span v-if="t.on === true" class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-success-ink">
                <span class="h-[7px] w-[7px] rounded-full bg-success" />{{ t.detail || 'Verbunden' }}
            </span>
            <span v-else-if="t.on === false" class="text-[13px] font-semibold text-accent">Verbinden</span>
            <span v-else class="text-[13px] font-semibold text-ink-3">{{ t.detail }}</span>
        </Link>
    </div>
</template>
