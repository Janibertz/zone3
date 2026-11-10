<script setup>
/**
 * Der letzte Lauf als leuchtende Route auf Dunkel, wie im Teilen-Bild
 * (Mockup „Zone3 mit mehr Leben“). Die Linie rechnet der Server aus der
 * Polyline (`TodayExtras::sketch()`), das Straßenmuster ist Deko.
 */
import { Link } from '@inertiajs/vue3';
import { fmtNum } from '@/Composables/useFormat';
import { activityHref } from '@/Composables/useActivityLink';

defineProps({
    run: { type: Object, required: true },
});
</script>

<template>
    <Link :href="activityHref(run.id, '/dashboard')" class="relative block h-[190px] overflow-hidden rounded-[20px] bg-[#0B0E12] p-4 text-white transition active:scale-[0.99] lg:h-[220px]"
        :aria-label="`Letzter Lauf: ${run.name}`">
        <svg viewBox="0 0 300 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="absolute inset-0 h-full w-full">
            <g fill="none" stroke="#1C232C" stroke-width="1.4">
                <path d="M-10 55 C60 45 120 85 200 65 S300 35 320 50" />
                <path d="M-10 140 C70 150 140 110 220 140 S300 180 320 160" />
                <path d="M80 -10 C70 65 110 130 90 210" />
                <path d="M210 -10 C220 75 190 140 220 210" />
            </g>
        </svg>
        <!-- Die Route nie beschneiden: eigenes SVG mit „meet“ zwischen Titel und Zahlen. -->
        <svg viewBox="0 0 300 200" preserveAspectRatio="xMidYMid meet" aria-hidden="true" class="absolute inset-x-4 bottom-14 top-9 h-[calc(100%-5.75rem)] w-[calc(100%-2rem)]">
            <path :d="run.path" fill="none" stroke="#0A84FF" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" opacity=".25" />
            <path :d="run.path" fill="none" stroke="#64B5FF" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span class="relative block text-[13px] font-bold text-[#64B5FF]">{{ run.day }} · {{ run.name }}</span>
        <span class="absolute inset-x-4 bottom-4 flex gap-5 tabular-nums">
            <span class="font-rounded text-[22px] font-extrabold lg:text-[26px]">{{ fmtNum(run.km) }}<span class="text-[13px] font-semibold text-[#8E8E93]"> km</span></span>
            <span v-if="run.pace" class="font-rounded text-[22px] font-extrabold lg:text-[26px]">{{ run.pace }}<span class="text-[13px] font-semibold text-[#8E8E93]"> /km</span></span>
            <span v-if="run.hr" class="font-rounded text-[22px] font-extrabold lg:text-[26px]">{{ run.hr }}<span class="text-[13px] font-semibold text-[#8E8E93]"> bpm</span></span>
        </span>
    </Link>
</template>
