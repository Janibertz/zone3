<script setup>
/**
 * Die Einheit von heute als Foto-Aufmacher (Mockup „Zone3 mit mehr Leben“,
 * Jan am 10.11.2026: „Das gefällt mir!!!! Übernehmen!“). Das Foto passt
 * zur Art der Einheit, darüber in Weiß Typ, Titel, drei große Zahlen und
 * der Ablauf als Band in Zonenfarben. Knöpfe kommen über den Slot.
 */
import { computed } from 'vue';
import { paceValue, sessionType, stepBand, zoneSpan } from '@/Composables/useSessionTypes';
import { heroPhoto } from '@/Composables/useMomentPhotos';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    session:  { type: Object, required: true },
    /** „Woche 3 von 22 · Aufbau“ oben rechts. */
    context:  { type: String, default: null },
    /** „Erledigt“, „Vorschlag“ … neben dem Typ. */
    badge:    { type: String, default: null },
});

const meta  = computed(() => sessionType(props.session.type));
const photo = computed(() => heroPhoto(props.session.type));
const rest  = computed(() => props.session.type === 'rest');
const RIDES = ['Ride', 'VirtualRide'];
const ride  = computed(() => RIDES.includes(props.session.sport_type));

const chip = computed(() => {
    const zone = zoneSpan(props.session);
    const label = ride.value ? 'Rad' : meta.value.label;
    return zone && !rest.value ? `${label} · Zone ${zone}` : label;
});

/** Typfarbe als Fläche; auf Grau (Ruhetag) steht Weiß, sonst Schwarz. */
const chipClass = computed(() => [meta.value.dot, meta.value.accent === 'neutral' ? 'text-white' : 'text-black']);

const numbers = computed(() => {
    const s = props.session;
    if (rest.value) return [];
    const out = [];
    if (s.distance_km && !ride.value) out.push({ label: 'Strecke', value: fmtNum(Number(s.distance_km)), unit: 'km' });
    if (s.duration_min) out.push({ label: 'Dauer', value: String(s.duration_min), unit: 'min' });
    const pace = paceValue(s.pace_target);
    if (pace && !ride.value) out.push({ label: 'Pace', value: pace, unit: '/km' });
    return out.slice(0, 3);
});

const band = computed(() => stepBand(props.session.steps));
const ZONE_FILL = { 1: 'bg-white/45', 2: 'bg-success', 3: 'bg-accent', 4: 'bg-warn', 5: 'bg-danger' };
const segClass = (seg) => seg.kind === 'work' ? (ZONE_FILL[seg.zone] ?? meta.value.dot) : 'bg-white/45';
</script>

<template>
    <section class="relative isolate overflow-hidden rounded-[28px] bg-black text-white" :aria-label="`Heute: ${session.title}`">
        <img :src="photo.src" alt="" aria-hidden="true" class="absolute inset-0 -z-10 h-full w-full object-cover" :style="{ objectPosition: photo.pos }" />
        <div class="absolute inset-0 -z-10" aria-hidden="true"
            style="background: linear-gradient(180deg, rgba(0,0,0,.30) 0%, rgba(0,0,0,0) 24%, rgba(0,0,0,.18) 45%, rgba(0,0,0,.86) 100%);" />

        <div class="flex h-full min-h-[440px] flex-col p-5 sm:p-7 lg:min-h-[420px] lg:p-8">
            <div class="flex items-center justify-between gap-3">
                <span class="flex min-w-0 items-center gap-2">
                    <span class="inline-flex h-7 shrink-0 items-center rounded-full px-3 text-[13px] font-bold" :class="chipClass">{{ chip }}</span>
                    <span v-if="badge" class="inline-flex h-7 shrink-0 items-center rounded-full bg-white/20 px-3 text-[13px] font-semibold backdrop-blur-md">{{ badge }}</span>
                </span>
                <span v-if="context" class="truncate text-[13px] font-semibold text-white/85">{{ context }}</span>
            </div>

            <div class="flex-1" />

            <h2 class="text-[38px] font-extrabold leading-[1.05] tracking-[-0.04em] [overflow-wrap:anywhere] lg:text-[52px]">{{ session.title }}</h2>
            <p v-if="session.description && !rest" class="mt-2.5 hidden max-w-xl text-[17px] leading-6 text-white/85 line-clamp-2 lg:block">{{ session.description }}</p>
            <p v-else-if="rest" class="mt-2.5 max-w-xl text-[17px] leading-6 text-white/85">Erholung gehört zum Training. Genieß den freien Tag.</p>

            <div v-if="numbers.length" class="mt-5 flex flex-wrap gap-x-7 gap-y-2 lg:gap-x-9">
                <div v-for="n in numbers" :key="n.label">
                    <p class="whitespace-nowrap font-rounded font-extrabold tabular-nums tracking-[-0.03em]"
                        :class="n.value.length > 6 ? 'text-[24px] leading-9 lg:text-[30px]' : 'text-[30px] leading-9 lg:text-[40px] lg:leading-[48px]'">
                        {{ n.value }}<span class="ml-1 text-[13px] font-semibold text-white/70 lg:text-[17px]">{{ n.unit }}</span>
                    </p>
                    <p class="hidden text-[13px] text-white/70 lg:block">{{ n.label }}</p>
                </div>
            </div>

            <div v-if="band.length > 1" class="mt-5 flex h-[7px] gap-[3px] overflow-hidden rounded-full lg:h-2" role="img" aria-label="Ablauf der Einheit">
                <span v-for="(seg, i) in band" :key="i" class="block h-full" :class="segClass(seg)" :style="{ flex: seg.flex }" />
            </div>

            <div v-if="$slots.default" class="mt-6">
                <slot />
            </div>
        </div>
    </section>
</template>
