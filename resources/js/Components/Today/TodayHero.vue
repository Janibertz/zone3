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
    /** Am Telefon randlos bis unter die Statusleiste, Kopf (Slot „top“) im Foto. */
    immersive: { type: Boolean, default: false },
});

const meta  = computed(() => sessionType(props.session.type));
const photo = computed(() => heroPhoto(props.session.type, props.session.sport_type));
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
    <section class="relative isolate overflow-hidden bg-black text-white lg:rounded-[28px]"
        :class="immersive ? 'max-lg:-mx-4 max-lg:rounded-none rounded-[28px]' : 'rounded-[28px]'"
        :aria-label="`Heute: ${session.title}`">
        <img :src="photo.src" alt="" aria-hidden="true" class="absolute inset-0 -z-10 h-full w-full object-cover" :style="{ objectPosition: photo.pos }" />
        <div class="absolute inset-0 -z-10" aria-hidden="true"
            style="background: linear-gradient(180deg, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 22%, rgba(0,0,0,.15) 45%, rgba(0,0,0,.85) 100%);" />

        <div class="flex h-full flex-col p-5 sm:p-7 lg:min-h-[420px] lg:p-8"
            :class="immersive ? 'min-h-[560px] max-lg:pb-6 max-lg:pt-[calc(env(safe-area-inset-top)+16px)]' : 'min-h-[440px]'">
            <!-- Telefon, randlos: Datum, „Heute“ und Avatar im Foto -->
            <div v-if="immersive && $slots.top" class="lg:hidden">
                <slot name="top" />
            </div>

            <!-- Typ und Woche oben (Desktop; am Telefon ohne Kopf im Foto) -->
            <div class="items-center justify-between gap-3" :class="immersive && $slots.top ? 'hidden lg:flex' : 'flex'">
                <span class="flex min-w-0 items-center gap-2">
                    <span class="inline-flex h-7 shrink-0 items-center rounded-full px-3 text-[13px] font-bold" :class="chipClass">{{ chip }}</span>
                    <span v-if="badge" class="inline-flex h-7 shrink-0 items-center rounded-full bg-white/20 px-3 text-[13px] font-semibold backdrop-blur-md">{{ badge }}</span>
                </span>
                <span v-if="context" class="truncate text-[13px] font-semibold text-white/85">{{ context }}</span>
            </div>

            <div class="flex-1" />

            <!-- Am Telefon mit Kopf im Foto: Typ direkt über dem Titel -->
            <span v-if="immersive && $slots.top" class="mb-3 flex items-center gap-2 lg:hidden">
                <span class="inline-flex h-[26px] items-center rounded-full px-2.5 text-[12px] font-bold" :class="chipClass">{{ chip }}</span>
                <span v-if="badge" class="inline-flex h-[26px] items-center rounded-full bg-white/20 px-2.5 text-[12px] font-semibold backdrop-blur-md">{{ badge }}</span>
            </span>

            <h2 class="text-[38px] font-extrabold leading-[40px] tracking-[-0.04em] [overflow-wrap:anywhere] lg:text-[52px] lg:leading-[1.05]">{{ session.title }}</h2>
            <p v-if="session.description && !rest" class="mt-2.5 hidden max-w-xl text-[17px] leading-6 text-white/85 line-clamp-2 lg:block">{{ session.description }}</p>
            <p v-else-if="rest" class="mt-2.5 max-w-xl text-[17px] leading-6 text-white/85">Erholung gehört zum Training. Genieß den freien Tag.</p>

            <div v-if="numbers.length" class="mt-[18px] flex flex-wrap gap-x-[22px] gap-y-2 lg:mt-5 lg:gap-x-9">
                <div v-for="n in numbers" :key="n.label">
                    <p class="whitespace-nowrap font-rounded font-extrabold tabular-nums tracking-[-0.03em]"
                        :class="n.value.length > 7 ? 'text-[24px] leading-9 lg:text-[30px]' : 'text-[30px] leading-9 lg:text-[40px] lg:leading-[48px]'">
                        {{ n.value }}<span class="ml-1 text-[13px] font-semibold text-white/70 lg:text-[17px]">{{ n.unit }}</span>
                    </p>
                    <p class="hidden text-[13px] text-white/70 lg:block">{{ n.label }}</p>
                </div>
            </div>

            <div v-if="band.length" class="mt-4 flex h-[7px] gap-[3px] overflow-hidden rounded-full lg:mt-5 lg:h-2" role="img" aria-label="Ablauf der Einheit">
                <span v-for="(seg, i) in band" :key="i" class="block h-full" :class="segClass(seg)" :style="{ flex: seg.flex }" />
            </div>

            <div v-if="$slots.default" class="mt-5 lg:mt-6">
                <slot />
            </div>
        </div>
    </section>
</template>
