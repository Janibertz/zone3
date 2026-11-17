<script setup>
/**
 * Die letzte Einheit unter „Zuletzt“. Mit Strecke als leuchtende Route auf
 * Dunkel wie im Teilen-Bild (Mockup „Zone3 mit mehr Leben“; die Linie
 * rechnet der Server aus der Polyline, `TodayExtras::sketch()`, das
 * Straßenmuster ist Deko). Ohne Strecke — Laufband, Zwift, Becken, Kraft —
 * das Foto der Einheit wie im Aufmacher und im Widget (Jan, 17.11.2026).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { distanceOf, hours, speedOf } from '@/Composables/useFormat';
import { activityHref } from '@/Composables/useActivityLink';
import { activityType } from '@/Composables/useActivityTypes';
import { heroPhoto } from '@/Composables/useMomentPhotos';

const props = defineProps({
    activity: { type: Object, required: true },
});

const photo = computed(() => (props.activity.path ? null : heroPhoto(props.activity.photo_type, props.activity.sport_type)));

/** Drei Zahlen, passend zum Sport: Strecke und Tempo, sonst Dauer und Sätze; der Puls, wenn Platz ist. */
const stats = computed(() => {
    const a = props.activity;
    const out = [];
    const dist = distanceOf(a);
    const speed = speedOf(a);
    if (dist) out.push(dist);
    if (dist && speed) out.push({ value: speed.value, unit: speed.unit });
    if (!dist && a.moving_time) out.push(hours(a.moving_time));
    if (!dist && a.sets) out.push({ value: String(a.sets), unit: a.sets === 1 ? 'Satz' : 'Sätze' });
    if (a.hr && out.length < 3) out.push({ value: String(a.hr), unit: 'bpm' });

    return out;
});

const label = computed(() => activityType(props.activity.type).label);
</script>

<template>
    <Link :href="activityHref(activity.id, '/dashboard')" class="relative block h-[190px] overflow-hidden rounded-[20px] bg-[#0B0E12] p-4 text-white transition active:scale-[0.99] lg:h-[220px]"
        :aria-label="`Zuletzt: ${activity.name}`">
        <template v-if="activity.path">
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
                <path :d="activity.path" fill="none" stroke="#0A84FF" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" opacity=".25" />
                <path :d="activity.path" fill="none" stroke="#64B5FF" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>
        <template v-else-if="photo">
            <img :src="photo.src" alt="" decoding="async"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover" :style="{ objectPosition: photo.pos }" />
            <span aria-hidden="true" class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/45 via-black/10 to-black/75" />
        </template>
        <span class="relative block truncate text-[13px] font-bold" :class="activity.path ? 'text-[#64B5FF]' : 'text-white'">{{ activity.day }} · {{ activity.name }}</span>
        <span v-if="!activity.path" class="relative mt-1 block text-[12px] font-semibold text-white/80">{{ label }}</span>
        <span class="absolute inset-x-4 bottom-4 flex gap-5 tabular-nums">
            <span v-for="s in stats" :key="s.unit" class="font-rounded text-[22px] font-extrabold lg:text-[26px]">{{ s.value }}<span class="ml-1 text-[13px] font-semibold" :class="activity.path ? 'text-[#8E8E93]' : 'text-white/75'">{{ s.unit }}</span></span>
        </span>
    </Link>
</template>
