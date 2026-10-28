<script setup>
import { computed } from 'vue';
import { TONE_COLOR } from '@/Composables/useRecoveryGauges.js';

/**
 * Kennzahl als Garmin-Bogen — die Kacheln unter „Körper“.
 *
 * Ein offener Dreiviertelbogen, in Stufen geteilt (`zones`). `fill` füllt bis
 * zum Wert, `mark` zeigt alle Stufen und einen Punkt. Die Werte liefert
 * `useRecoveryGauges.js`; hier wird nur gezeichnet.
 */
const props = defineProps({
    icon:    { type: String, default: null },
    label:   { type: String, required: true },
    mode:    { type: String, default: 'fill' },      // fill | mark
    value:   { type: Number, default: null },        // Position im Bogen
    display: { type: String, default: '–' },         // Zahl in der Mitte
    unit:    { type: String, default: null },
    min:     { type: Number, default: 0 },
    max:     { type: Number, default: 100 },
    /** [{ from, to, tone }] */
    zones:   { type: Array, default: () => [] },
    /** { text, tone } */
    status:  { type: Object, default: null },
    hint:    { type: String, default: null },
});

const START = 135, SWEEP = 270, GAP = 4, R = 41;
const STROKE = computed(() => (props.mode === 'mark' ? 7 : 9));

function pt(a) {
    const t = (a * Math.PI) / 180;
    return [50 + R * Math.cos(t), 50 + R * Math.sin(t)];
}
function arc(a0, a1) {
    const [x0, y0] = pt(a0), [x1, y1] = pt(a1);
    return `M ${x0.toFixed(2)} ${y0.toFixed(2)} A ${R} ${R} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
}
function angle(v) {
    const span = (props.max - props.min) || 1;
    const c = Math.max(props.min, Math.min(props.max, v));
    return START + ((c - props.min) / span) * SWEEP;
}

const segments = computed(() => {
    // Ohne Stufen (noch kein Normalbereich): ein grauer Bogen.
    if (!props.zones.length) {
        return [{ d: arc(START, START + SWEEP), color: 'rgb(var(--z-surface-2))' }];
    }
    const out = [];
    const n = props.zones.length;
    props.zones.forEach((z, i) => {
        const a0 = angle(z.from) + (i ? GAP / 2 : 0);
        const a1 = angle(z.to) - (i < n - 1 ? GAP / 2 : 0);
        if (a1 <= a0) return;
        const color = TONE_COLOR[z.tone] ?? TONE_COLOR.none;
        if (props.mode === 'mark') {
            out.push({ d: arc(a0, a1), color });
            return;
        }
        out.push({ d: arc(a0, a1), color: 'rgb(var(--z-surface-2))' });
        if (props.value != null && props.value > z.from) {
            const end = Math.min(a1, angle(props.value));
            if (end > a0) out.push({ d: arc(a0, end), color });
        }
    });
    return out;
});

const marker = computed(() => {
    if (props.mode !== 'mark' || props.value == null || !props.zones.length) return null;
    const [x, y] = pt(angle(props.value));
    return { x, y };
});

const ICONS = {
    sleep:   ['M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z'],
    battery: ['M5.5 7h11A2.5 2.5 0 0 1 19 9.5v5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 3 14.5v-5A2.5 2.5 0 0 1 5.5 7Z', 'M21 10.5v3', 'M6.5 10v4M9.5 10v4M12.5 10v4'],
    hrv:     ['M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z', 'M7.5 12h2l1.2-2.2 1.8 4.4 1.3-2.2h2.7'],
    heart:   ['M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z'],
    lungs:   ['M12 3v6', 'M12 9c-1.5 0-6 1.5-6 7.5 0 2 1 3.5 2.5 3.5S11 18 11 16v-4', 'M12 9c1.5 0 6 1.5 6 7.5 0 2-1 3.5-2.5 3.5S13 18 13 16v-4'],
    energy:  ['M13 3 5 13.5h6L10 21l8-10.5h-6L13 3Z'],
    muscle:  ['M5 15c2-1 3-4 3-7 0-2 1.5-3 3-3s2 1 2 2.5c0 1-1 1.5-2 1.5', 'M5 15c1 3 4 4 7 4 4 0 7-2 7-5.5 0-2.5-2-4-4.5-4-1.5 0-3 1-3.5 2.5'],
    stress:  ['M3 12h3l2-5 3 10 3-8 2 3h5'],
};
const iconPaths = computed(() => ICONS[props.icon] ?? null);
// Das halbtransparente Hellblau trägt als Text zu wenig Kontrast.
const statusColor = computed(() => {
    if (!props.status) return null;
    const tone = props.status.tone === 'sky' ? 'accent' : props.status.tone;
    return TONE_COLOR[tone] ?? TONE_COLOR.none;
});
</script>

<template>
    <div class="flex min-w-0 flex-col items-center rounded-card bg-surface px-2 pb-3.5 pt-3">
        <svg v-if="iconPaths" class="h-[18px] w-[18px] text-ink-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path v-for="(d, i) in iconPaths" :key="i" :d="d" />
        </svg>

        <div class="relative mt-1 aspect-square w-[88px] max-w-full">
            <svg viewBox="0 0 100 100" class="block h-full w-full" aria-hidden="true">
                <path v-for="(s, i) in segments" :key="i" :d="s.d" fill="none" :stroke="s.color" :stroke-width="STROKE" />
                <circle v-if="marker" :cx="marker.x" :cy="marker.y" r="6.2"
                    fill="rgb(var(--z-surface))" stroke="rgb(var(--z-ink))" stroke-width="2.4" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center pt-1">
                <span class="font-rounded text-[23px] font-bold leading-none tabular-nums text-ink">{{ display }}</span>
                <span v-if="unit" class="mt-0.5 text-[9px] font-semibold leading-none text-ink-3">{{ unit }}</span>
            </div>
        </div>

        <p class="mt-0.5 w-full truncate text-center text-[13px] font-semibold text-ink">{{ label }}</p>
        <p v-if="status" class="w-full truncate text-center text-[12px] font-semibold" :style="{ color: statusColor }">{{ status.text }}</p>
        <p v-if="hint" class="w-full truncate text-center text-[12px] text-ink-3">{{ hint }}</p>
    </div>
</template>
