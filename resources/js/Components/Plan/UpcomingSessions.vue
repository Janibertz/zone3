<script setup>
/**
 * „Als Nächstes“ auf Heute (Jan, 10.11.2026, Mockup „Zone3 Kacheln und
 * Lauf teilen“): die folgenden Einheiten als wischbare Kacheln. Typ in
 * Farbe, Tag, Titel groß, Strecke und Dauer — wer die Details will, tippt.
 *
 * Seit dem Mockup „Zone3 mit mehr Leben“ (10.11.2026) mit Foto je Typ
 * (`useMomentPhotos.js`), Kraft, Rumpf und Mobilität als volle Typfarbe.
 * Die nächste harte Einheit bekommt einen Rand in ihrer Farbe, damit man
 * sieht, worauf die Woche zuläuft.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';
import { tilePhoto } from '@/Composables/useMomentPhotos';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
});

const HARD = ['tempo_run', 'interval', 'race_prep', 'test_run'];
const RING = {
    warn: 'ring-warn', danger: 'ring-danger', accent: 'ring-accent',
    info: 'ring-info', success: 'ring-success', neutral: 'ring-white',
};
const RIDES = ['Ride', 'VirtualRide'];

function dayLabel(date) {
    const d = new Date(date + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const diff = Math.round((d - today) / 86400000);
    if (diff === 0) return 'Heute';
    if (diff === 1) return 'Morgen';
    const wd = d.toLocaleDateString('de-DE', { weekday: 'short' }).replace('.', '');
    return diff < 7 ? wd : `${wd} ${d.getDate()}.${d.getMonth() + 1}.`;
}

function duration(min) {
    if (!min) return null;
    if (min < 90) return { value: String(min), unit: 'min' };
    return { value: `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`, unit: 'h' };
}

const tiles = computed(() => {
    const firstHard = props.sessions.find(s => HARD.includes(s.type))?.id;
    return props.sessions.map((s) => {
        const meta = sessionType(s.type);
        const ride = RIDES.includes(s.sport_type);
        return {
            ...s,
            meta,
            label: ride ? 'Rad' : meta.label,
            day: dayLabel(s.date),
            km: s.distance_km && !ride ? fmtNum(s.distance_km) : null,
            dur: duration(s.duration_min),
            photo: tilePhoto(s.type),
            ring: s.id === firstHard ? `ring-[3px] ring-inset ${RING[meta.accent] ?? RING.accent}` : '',
        };
    });
});
</script>

<template>
    <div class="no-scrollbar -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-2.5 overflow-x-auto px-4 lg:mx-0 lg:grid lg:grid-cols-3 lg:gap-3.5 lg:overflow-visible lg:px-0"
        role="list" aria-label="Nächste Einheiten">
        <Link v-for="t in tiles" :key="t.id" :href="t.href" role="listitem"
            class="relative flex h-[200px] w-[236px] shrink-0 snap-start lg:h-[220px] lg:w-auto lg:[&:nth-child(n+4)]:hidden flex-col overflow-hidden rounded-[20px] p-4 text-white transition active:scale-[0.98]"
            :class="[t.photo ? 'bg-[#1C1C1E]' : t.meta.dot, t.ring]">
            <template v-if="t.photo">
                <img :src="t.photo.src" alt="" decoding="async"
                    class="pointer-events-none absolute inset-0 h-full w-full object-cover" :style="{ objectPosition: t.photo.pos }" />
                <span aria-hidden="true" class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/45 via-black/10 to-black/75" />
            </template>

            <span class="relative flex items-center justify-between gap-2">
                <span class="inline-flex min-w-0 items-center gap-1.5 text-[13px] font-bold">
                    <span v-if="t.photo" class="h-2 w-2 shrink-0 rounded-full" :class="t.meta.dot" />
                    <span class="truncate">{{ t.label }}</span>
                </span>
                <span class="shrink-0 text-[12px] font-semibold text-white/80">{{ t.day }}</span>
            </span>

            <span class="relative mt-3 line-clamp-2 text-[21px] font-extrabold leading-[25px] tracking-[-0.025em]">{{ t.title }}</span>

            <span class="relative mt-auto flex items-baseline gap-4 tabular-nums">
                <span v-if="t.km">
                    <span class="font-rounded text-[24px] font-bold">{{ t.km }}</span>
                    <span class="ml-0.5 text-[13px] text-white/75">km</span>
                </span>
                <span v-if="t.dur">
                    <span class="font-rounded text-[24px] font-bold">{{ t.dur.value }}</span>
                    <span class="ml-0.5 text-[13px] text-white/75">{{ t.dur.unit }}</span>
                </span>
            </span>
        </Link>
    </div>
</template>
