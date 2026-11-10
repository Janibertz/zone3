<script setup>
/**
 * „Als Nächstes“ auf Heute (Jan, 10.11.2026, Mockup „Zone3 Kacheln und
 * Lauf teilen“): die folgenden Einheiten als wischbare Kacheln. Typ in
 * Farbe, Tag, Titel groß, Strecke und Dauer — wer die Details will, tippt.
 *
 * Das Straßenmuster im Hintergrund ist eine eigene Zeichnung und rein
 * dekorativ. Die nächste harte Einheit bekommt einen Rand in ihrer Farbe,
 * damit man sieht, worauf die Woche zuläuft.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
});

const HARD = ['tempo_run', 'interval', 'race_prep', 'test_run'];
const RING = {
    warn: 'ring-warn/60', danger: 'ring-danger/60', accent: 'ring-accent/60',
    info: 'ring-info/60', success: 'ring-success/60', neutral: 'ring-line-strong',
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
            ring: s.id === firstHard ? `ring-[1.5px] ring-inset ${RING[meta.accent] ?? RING.accent}` : '',
        };
    });
});
</script>

<template>
    <div class="no-scrollbar -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-2.5 overflow-x-auto px-4 lg:mx-0 lg:px-0"
        role="list" aria-label="Nächste Einheiten">
        <Link v-for="t in tiles" :key="t.id" :href="t.href" role="listitem"
            class="relative flex h-[168px] w-[236px] shrink-0 snap-start flex-col overflow-hidden rounded-[18px] bg-surface p-3.5 transition active:scale-[0.98]"
            :class="t.ring">
            <!-- Straßenmuster, eigene Zeichnung -->
            <svg class="pointer-events-none absolute inset-0 h-full w-full text-ink/[0.06]" viewBox="0 0 220 150"
                preserveAspectRatio="xMidYMid slice" aria-hidden="true">
                <g fill="none" stroke="currentColor" stroke-width="1.2">
                    <path d="M-10 40 C40 30 70 70 120 55 S200 20 240 35" />
                    <path d="M-10 95 C30 100 60 80 110 100 S190 130 240 110" />
                    <path d="M60 -10 C55 40 85 80 70 160" />
                    <path d="M150 -10 C160 50 130 90 160 160" />
                    <path d="M-10 130 L240 125" />
                    <path d="M100 -10 L115 160" stroke-width="0.7" />
                    <path d="M-10 70 L240 80" stroke-width="0.7" />
                </g>
            </svg>

            <span class="relative flex items-center justify-between gap-2">
                <span class="inline-flex min-w-0 items-center gap-1.5 text-[13px] font-bold" :class="t.meta.text">
                    <span class="h-2 w-2 shrink-0 rounded-full" :class="t.meta.dot" />
                    <span class="truncate">{{ t.label }}</span>
                </span>
                <span class="shrink-0 text-[12px] font-semibold text-ink-3">{{ t.day }}</span>
            </span>

            <span class="relative mt-3 line-clamp-2 text-[21px] font-extrabold leading-[25px] tracking-[-0.025em] text-ink">{{ t.title }}</span>

            <span class="relative mt-auto flex items-baseline gap-4 tabular-nums">
                <span v-if="t.km">
                    <span class="font-rounded text-[24px] font-bold text-ink">{{ t.km }}</span>
                    <span class="ml-0.5 text-[13px] text-ink-3">km</span>
                </span>
                <span v-if="t.dur">
                    <span class="font-rounded text-[24px] font-bold text-ink">{{ t.dur.value }}</span>
                    <span class="ml-0.5 text-[13px] text-ink-3">{{ t.dur.unit }}</span>
                </span>
            </span>
        </Link>
    </div>
</template>
