<script setup>
/**
 * Planseite am Desktop (Mockup „Zone3 mit mehr Leben“, von Jan am 10.11.2026
 * abgenommen): oben ein Fotoband mit Phase, Ziel, Prognose und Tagen, darunter
 * die laufende Woche als sieben Karten (erledigt = ganz in der Typfarbe,
 * geplant = weiß mit Farbkante oben) und die folgenden Wochen als gestapelte
 * Balken. Die Liste darunter bleibt für Ziehen, Wischen und Details.
 *
 * Am Telefon unsichtbar — dort trägt die Rennkarte das Foto. Woche und
 * Wochenbalken (`PlanWeeksOverview`) stehen seit 12.11.2026 auch dort.
 */
import { computed } from 'vue';
import PlanWeeksOverview from '@/Components/Plan/PlanWeeksOverview.vue';

const props = defineProps({
    event:      { type: Object, required: true },
    sessions:   { type: Array, default: () => [] },
    prediction: { type: Object, default: null },
    isOpenGoal: { type: Boolean, default: false },
    sharing:    { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'share']);

const PHASE_LABELS = { base: 'Grundlage', build: 'Aufbau', peak: 'Spitze', taper: 'Taper', race_week: 'Rennwoche' };

/** Ein Satz je Phase — fest, kein Modell. */
const PHASE_LINES = {
    base:      'Grundlage. Locker laufen, das Fundament wächst.',
    build:     'Aufbau. Die Schwelle wird dein Zuhause.',
    peak:      'Spitze. Jetzt läufst du dein Renntempo.',
    taper:     'Taper. Weniger Umfang, die Frische kommt.',
    race_week: 'Rennwoche. Locker bleiben, die Arbeit ist getan.',
};

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = iso(new Date());
function mondayOf(dateIso) {
    const d = new Date(dateIso + 'T00:00:00');
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    return iso(d);
}
function addDays(dateIso, n) {
    const d = new Date(dateIso + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return iso(d);
}

const phaseKey = computed(() => props.event.phase);
const headline = computed(() => props.isOpenGoal
    ? `${props.event.name}. Block ${props.event.block_number ?? 1}.`
    : (PHASE_LINES[phaseKey.value] ?? props.event.name));

const eyebrow = computed(() => {
    const date = new Date(props.event.event_date + 'T00:00:00').toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
    return props.isOpenGoal
        ? `${props.event.progress?.phase ?? 'Plan'} · Block endet am ${date}`
        : `${props.event.name} · ${date}`;
});

const stats = computed(() => {
    const out = [];
    const target = props.event.target_time_formatted?.replace(/\s*Std\.?$/, '');
    if (target && !props.isOpenGoal) out.push({ value: target, label: 'Ziel' });
    if (props.prediction?.time) out.push({ value: props.prediction.time, label: props.isOpenGoal ? 'Prognose 5 km' : 'Prognose heute' });
    if (props.event.days_until != null) out.push({ value: String(props.event.days_until), label: props.event.days_until === 1 ? 'Tag' : 'Tage' });
    return out;
});

const segments = computed(() => (props.event.phase_segments || []).map((s, i) => ({
    ...s, label: PHASE_LABELS[s.key] ?? s.key, current: i === 0,
})));

</script>

<template>
    <div class="hidden flex-col gap-6 lg:flex">
        <!-- Fotoband -->
        <section aria-label="Rennen und Phase"
            class="relative isolate flex min-h-[260px] flex-wrap items-end justify-between gap-8 overflow-hidden rounded-[28px] bg-black px-9 py-8 text-white">
            <img src="/images/moments/lang.jpg" alt="" aria-hidden="true" class="absolute inset-0 -z-10 h-full w-full object-cover object-[center_55%]" />
            <div class="absolute inset-0 -z-10" aria-hidden="true"
                style="background: linear-gradient(90deg, rgba(0,0,0,.82) 0%, rgba(0,0,0,.35) 60%, rgba(0,0,0,.1) 100%);" />

            <button type="button" :disabled="sharing" @click="$emit('share')"
                class="absolute right-6 top-6 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 backdrop-blur-md transition active:scale-90 disabled:opacity-60"
                aria-label="Plan als Story teilen">
                <svg v-if="!sharing" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" /></svg>
                <svg v-else class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            </button>

            <div class="max-w-[540px]">
                <p class="text-[13px] font-bold text-white/80">{{ eyebrow }}</p>
                <h2 class="mt-2 text-[44px] font-extrabold leading-[48px] tracking-[-0.04em] xl:text-[48px] xl:leading-[50px]">{{ headline }}</h2>
                <div v-if="stats.length" class="mt-5 flex gap-8">
                    <div v-for="s in stats" :key="s.label">
                        <p class="font-rounded text-[30px] font-extrabold tabular-nums">{{ s.value }}</p>
                        <p class="text-[13px] text-white/70">{{ s.label }}</p>
                    </div>
                </div>
            </div>

            <div v-if="segments.length" class="min-w-0 max-w-[460px] flex-[1_1_360px]">
                <div class="flex h-2.5 gap-1">
                    <span v-for="(seg, i) in segments" :key="seg.key" class="rounded-[5px]"
                        :class="seg.current ? 'bg-white' : 'bg-white/30'" :style="{ flex: Math.max(seg.days, 3) }" />
                </div>
                <div class="mt-2 flex gap-1 text-[12px] text-white/75">
                    <span v-for="seg in segments" :key="seg.key" class="min-w-0 truncate"
                        :class="seg.current ? 'font-bold text-white' : ''" :style="{ flex: Math.max(seg.days, 3) }">
                        {{ seg.current && event.progress?.week ? `${seg.label} · Woche ${event.progress.week}` : (seg.days >= 14 ? seg.label : '') }}
                    </span>
                </div>
            </div>
        </section>

        <PlanWeeksOverview :event="event" :sessions="sessions" @open="emit('open', $event)" />
    </div>
</template>
