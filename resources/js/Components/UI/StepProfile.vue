<script setup>
/**
 * Der Ablauf einer Einheit als Höhenprofil (Mockup „Zone3 Einheiten mit
 * Profil und Soll/Ist“, Jan 25.11.2026): Breite = Dauer, Höhe = Zone,
 * Farbe = Zone. Steht auf den Wochenkacheln, in den Zeilen der Planseite,
 * im Heute-Aufmacher, im Coach-Vorschlag (vorher/neu) und unter Soll/Ist.
 *
 * `tone`:
 *  - `zone`  Zonenfarben auf heller Fläche (Karte, Zeile)
 *  - `hero`  Zonenfarben auf dem Foto, Zone 1 halbweiß
 *  - `light` weiß auf einer Kachel in Typfarbe (erledigt), dunkler je Zone
 *
 * `total` setzt einen gemeinsamen Maßstab in Minuten: zwei Profile
 * untereinander (vorher/neu, geplant/gelaufen) sind dann gleich lang
 * gerechnet, das kürzere endet früher.
 */
import { computed } from 'vue';
import { stepProfile } from '@/Composables/useSessionTypes';

const props = defineProps({
    steps:   { type: Array, default: null },
    /** Schon fertige Stücke `{ flex, zone, height }` statt Schritten (Soll/Ist). */
    parts:   { type: Array, default: null },
    height:  { type: Number, default: 16 },
    tone:    { type: String, default: 'zone' },
    total:   { type: Number, default: null },
    gap:     { type: Number, default: 2 },
    label:   { type: String, default: 'Ablauf der Einheit' },
});

const ZONE_BG = { 1: 'bg-ink-3', 2: 'bg-success', 3: 'bg-accent', 4: 'bg-warn', 5: 'bg-danger' };
const HERO_BG = { ...ZONE_BG, 1: 'bg-white/45' };

const segments = computed(() => props.parts ?? stepProfile(props.steps));
const sum = computed(() => segments.value.reduce((t, s) => t + (Number(s.flex) || 0), 0));
const rest = computed(() => (props.total && props.total > sum.value ? props.total - sum.value : 0));

function cls(seg) {
    if (props.tone === 'light') return '';
    return (props.tone === 'hero' ? HERO_BG : ZONE_BG)[seg.zone] ?? ZONE_BG[2];
}
function style(seg) {
    const s = { flex: `${seg.flex} 1 0`, height: `${Math.max(2, Math.round(props.height * seg.height))}px` };
    if (props.tone === 'light') s.background = `rgba(255,255,255,${(0.35 + seg.height * 0.65).toFixed(2)})`;
    return s;
}
</script>

<template>
    <div v-if="segments.length" class="flex items-end" :style="{ height: `${height}px`, gap: `${gap}px` }" role="img" :aria-label="label">
        <span v-for="(seg, i) in segments" :key="i" class="block min-w-[1.5px] rounded-[2px]" :class="cls(seg)" :style="style(seg)" />
        <span v-if="rest" class="block" :style="{ flex: `${rest} 1 0` }" aria-hidden="true" />
    </div>
</template>
