<script setup>
/**
 * Das Rennen auf Heute, immer sichtbar (Mockup „Zone3 mit mehr Leben“):
 * am Telefon ein Fotostreifen mit Countdown rechts, ab lg eine hohe Karte
 * neben dem Aufmacher. Ohne Rennen zeigt sie den Block.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { RACE_PHOTO } from '@/Composables/useMomentPhotos';

const props = defineProps({
    race: { type: Object, required: true },
});

const daysWord = computed(() => props.race.days === 1 ? 'Tag' : 'Tage');
const until = computed(() => props.race.open
    ? (props.race.open_test ? 'bis zum 5-km-Test' : `bis Block ${props.race.block + 1}`)
    : 'bis zum Start');
const weekLine = computed(() => {
    const p = props.race.progress;
    if (!p) return null;
    if (p.starts_in > 0) return `Plan startet in ${p.starts_in} ${p.starts_in === 1 ? 'Tag' : 'Tagen'}`;
    return props.race.open ? `Block ${props.race.block} · Woche ${p.week} von ${p.weeks}` : `Woche ${p.week} von ${p.weeks}`;
});
const sub = computed(() => props.race.open
    ? (props.race.open_test ? `5-km-Test am ${props.race.date_label}` : `Block endet am ${props.race.date_label}`)
    : [props.race.date_label, props.race.target ? `Ziel ${props.race.target.replace(/\s*Std\.?$/, '')}` : null].filter(Boolean).join(' · '));
const shortSub = computed(() => props.race.open
    ? props.race.name
    : [props.race.name, props.race.target ? `Ziel ${props.race.target.replace(/\s*Std\.?$/, '')}` : null].filter(Boolean).join(' · '));
</script>

<template>
    <Link :href="race.href" class="relative isolate block overflow-hidden rounded-[20px] bg-black text-white transition active:scale-[0.99] lg:rounded-[28px]"
        :aria-label="`${race.name}: noch ${race.days} ${daysWord}`">
        <img :src="RACE_PHOTO.src" alt="" aria-hidden="true" class="absolute inset-0 -z-10 h-full w-full object-cover" :style="{ objectPosition: RACE_PHOTO.pos }" />
        <div class="absolute inset-0 -z-10 bg-gradient-to-r from-black/80 to-black/20 lg:bg-gradient-to-b lg:from-black/10 lg:to-black/75" aria-hidden="true" />

        <!-- Telefon: Streifen -->
        <div class="flex h-[120px] items-center justify-between gap-4 p-[18px] lg:hidden">
            <span class="min-w-0">
                <span class="block truncate text-[13px] font-semibold text-white/80">{{ shortSub }}</span>
                <span v-if="weekLine" class="mt-1 block text-[17px] font-bold">{{ weekLine }}</span>
            </span>
            <span class="shrink-0 text-right">
                <span class="block font-rounded text-[48px] font-black leading-[48px] tracking-[-0.05em] tabular-nums">{{ race.days }}</span>
                <span class="text-[13px] text-white/80">{{ daysWord }}</span>
            </span>
        </div>

        <!-- Ab lg: hohe Karte -->
        <div class="hidden h-full min-h-[420px] flex-col justify-between p-7 lg:flex">
            <span class="text-[13px] font-bold text-white/85">{{ race.open ? 'Dein Block' : 'Dein Rennen' }}</span>
            <div>
                <p class="font-rounded text-[88px] font-black leading-[84px] tracking-[-0.05em] tabular-nums">{{ race.days }}</p>
                <p class="text-[17px] font-semibold text-white/80">{{ daysWord }} {{ until }}</p>
                <h3 class="mt-4 text-[26px] font-extrabold leading-[30px] tracking-[-0.03em] [overflow-wrap:anywhere]">{{ race.name }}</h3>
                <p class="mt-1 text-[15px] text-white/80">{{ sub }}</p>
                <template v-if="race.progress">
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-white/25">
                        <div class="h-full rounded-full bg-white" :style="{ width: race.progress.percent + '%' }" />
                    </div>
                    <div class="mt-2 flex justify-between gap-3 text-[13px] text-white/75">
                        <span>{{ weekLine }}</span>
                        <span v-if="race.prediction">Prognose {{ race.open ? '5 km ' : '' }}{{ race.prediction }}</span>
                    </div>
                </template>
                <p v-else-if="race.prediction" class="mt-3 text-[13px] text-white/75">Prognose {{ race.prediction }}</p>
            </div>
        </div>
    </Link>
</template>
