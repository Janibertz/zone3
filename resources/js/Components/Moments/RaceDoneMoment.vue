<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import MomentScreen from './MomentScreen.vue';
import { clock } from '@/Composables/useFormat';
import { drawStory, shareStory } from '@/Composables/useStoryImage';

/**
 * „Rennen gelaufen“ (seit 09.11.2026, Mockup 3 im Canvas „Zone3 Momente mit
 * Emotion“): Zeit gegen Ziel, einmal nach dem Renntag. Daten aus
 * {@see App\Services\Moments\MomentService::raceRun()} — die Zeit ist die
 * Uhrzeit des Laufs vom Renntag.
 */
const props = defineProps({ race: { type: Object, required: true } });
const emit = defineEmits(['close']);

const PHOTO = '/images/moments/rennen.jpg';

/** Sekunden unter (+) oder über (−) dem Ziel. */
const diff = computed(() => props.race.target_seconds ? props.race.target_seconds - props.race.seconds : null);
const diffLabel = computed(() => {
    if (diff.value === null) return null;
    const d = Math.abs(diff.value);
    const t = d >= 60 ? clock(d) : `${d} s`;
    return diff.value >= 0 ? `${t} unter Ziel` : `${t} über Ziel`;
});
const day = computed(() => new Date(props.race.date + 'T00:00:00')
    .toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' }));

const sharing = ref(false);
async function share() {
    sharing.value = true;
    try {
        const r = props.race;
        const stats = [];
        if (r.pace) stats.push({ value: r.pace, label: 'min/km' });
        if (r.target_seconds) stats.push({ value: clock(r.target_seconds), label: 'Ziel' });
        if (diff.value !== null && diff.value >= 0) stats.push({ value: `−${Math.abs(diff.value) >= 60 ? clock(Math.abs(diff.value)) : diff.value + ' s'}`, label: 'unter Ziel', color: '#30d158' });
        const blob = await drawStory({
            photo: PHOTO, eyebrow: 'Finisher', label: `${r.name} · ${r.distance}`,
            value: clock(r.seconds), stats,
        });
        await shareStory(blob, `zone3-rennen-${r.date}.png`);
    } catch { /* Teilen ist ein Extra. */ } finally {
        sharing.value = false;
    }
}
</script>

<template>
    <MomentScreen :photo="PHOTO" badge="Rennen gelaufen" focus="center 35%" @close="emit('close')">
        <template #icon>
            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4M5 4h11l-2 4 2 4H5" /></svg>
        </template>

        <p class="text-[20px] font-bold text-white/80">{{ race.name }} · {{ race.distance }}</p>
        <p class="mt-0.5 font-rounded text-[88px] font-extrabold leading-[0.92] tracking-[-0.055em] sm:text-[96px]">{{ clock(race.seconds) }}</p>
        <p class="mt-3.5 flex flex-wrap items-center gap-2.5 text-[17px]">
            <span v-if="diffLabel" class="rounded-lg px-2.5 py-1 font-extrabold tabular-nums"
                :class="diff >= 0 ? 'bg-success text-black' : 'bg-white/20 text-white'">{{ diffLabel }}</span>
            <span class="tabular-nums text-white/75">
                <template v-if="race.target_seconds">Ziel {{ clock(race.target_seconds) }}</template><template v-if="race.target_seconds && race.pace"> · </template><template v-if="race.pace">{{ race.pace }} /km</template>
            </span>
        </p>
        <p class="mt-4 text-[17px] leading-snug text-white/90">
            <template v-if="diff === null || diff >= 0">Du hast es geschafft, {{ day }}. Genieß den Moment — die nächsten Tage gehören der Erholung.</template>
            <template v-else>Ins Ziel gekommen, {{ day }}. Die Zeit war heute nicht dein Tag — der Weg dahin bleibt. Erhol dich gut.</template>
        </p>

        <div class="mt-7 space-y-2.5">
            <button type="button" :disabled="sharing"
                class="flex h-[54px] w-full items-center justify-center gap-2 rounded-full bg-white text-[19px] font-bold text-black transition active:scale-[0.97] disabled:opacity-60"
                @click="share">
                {{ sharing ? 'Bild wird erstellt …' : 'Als Story teilen' }}
            </button>
            <Link :href="route('activities.show', race.activity_id)"
                class="flex h-[54px] w-full items-center justify-center rounded-full bg-white/15 text-[19px] font-semibold backdrop-blur-md transition active:scale-[0.97]"
                @click="emit('close')">
                Zum Rennen
            </Link>
        </div>
    </MomentScreen>
</template>
