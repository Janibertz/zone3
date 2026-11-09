<script setup>
import { computed, ref } from 'vue';
import MomentScreen from './MomentScreen.vue';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';
import { drawStory, shareStory } from '@/Composables/useStoryImage';

/**
 * „Woche geschafft“ (seit 09.11.2026, Mockup 4 im Canvas „Zone3 Momente mit
 * Emotion“): jede Planeinheit der letzten Woche erledigt. Daten aus
 * {@see App\Services\Moments\MomentService::weekDone()}.
 */
const props = defineProps({ week: { type: Object, required: true } });
const emit = defineEmits(['close']);

const PHOTO = '/images/moments/feld.jpg';
const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

const range = computed(() => {
    const start = new Date(props.week.week_start + 'T00:00:00');
    const end = new Date(start); end.setDate(end.getDate() + 6);
    const month = (d) => d.toLocaleDateString('de-DE', { month: 'short' });
    return start.getMonth() === end.getMonth()
        ? `${start.getDate()}.–${end.getDate()}. ${month(end)}`
        : `${start.getDate()}. ${month(start)} – ${end.getDate()}. ${month(end)}`;
});

const nextLine = computed(() => {
    const n = props.week.next;
    if (!n) return null;
    if (n.recovery) return `Diese Woche entlastest du. Der Körper baut jetzt auf, was du in den letzten Wochen gesetzt hast.`;
    if (n.weeks_to_race <= 0) return `Diese Woche ist Rennwoche. ${n.race} — du bist bereit.`;
    return `Weiter in der Phase ${n.label}. Noch ${n.weeks_to_race} ${n.weeks_to_race === 1 ? 'Woche' : 'Wochen'} bis ${n.race}.`;
});

const sharing = ref(false);
async function share() {
    sharing.value = true;
    try {
        const w = props.week;
        const stats = [{ value: fmtNum(w.km, 1), label: 'km gelaufen' }];
        if (w.streak > 1) stats.push({ value: String(w.streak), label: 'Wochen am Stück' });
        const blob = await drawStory({
            photo: PHOTO, eyebrow: 'Woche geschafft', label: range.value,
            value: `${w.done}/${w.total}`, stats,
        });
        await shareStory(blob, `zone3-woche-${w.week_start}.png`);
    } catch { /* Teilen ist ein Extra; ohne Bild bleibt der Moment. */ } finally {
        sharing.value = false;
    }
}
</script>

<template>
    <MomentScreen :photo="PHOTO" badge="Woche geschafft" @close="emit('close')">
        <template #icon>
            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
        </template>

        <p class="text-[20px] font-bold text-white/80">{{ range }}</p>
        <p class="mt-0.5 flex items-baseline gap-3">
            <span class="font-rounded text-[96px] font-extrabold leading-[0.92] tracking-[-0.055em]">{{ week.done }}/{{ week.total }}</span>
            <span class="text-[17px] font-semibold text-white/75">Einheiten<br>erledigt</span>
        </p>

        <div class="mt-5 grid grid-cols-7 gap-1.5" role="img" :aria-label="`${week.done} von ${week.total} Einheiten erledigt`">
            <div v-for="(d, i) in week.days" :key="d.date" class="flex flex-col items-center gap-1.5">
                <span class="h-7 w-7 rounded-full" :class="d.type ? sessionType(d.type).dot : 'ring-2 ring-inset ring-white/25'" />
                <span class="text-[12px] font-semibold text-white/60">{{ WEEKDAYS[i] }}</span>
            </div>
        </div>

        <div class="mt-5 flex gap-8">
            <div>
                <p class="font-rounded text-[30px] font-bold leading-9 tabular-nums">{{ fmtNum(week.km, 1) }}<span class="ml-1 text-[17px] font-semibold text-white/60">km</span></p>
                <p class="text-[13px] text-white/60">gelaufen</p>
            </div>
            <div v-if="week.streak > 1">
                <p class="font-rounded text-[30px] font-bold leading-9 tabular-nums">{{ week.streak }}</p>
                <p class="text-[13px] text-white/60">Wochen am Stück</p>
            </div>
        </div>
        <p v-if="nextLine" class="mt-4 text-[17px] leading-snug text-white/90">{{ nextLine }}</p>

        <div class="mt-7 space-y-2.5">
            <button type="button" :disabled="sharing"
                class="flex h-[54px] w-full items-center justify-center gap-2 rounded-full bg-white text-[19px] font-bold text-black transition active:scale-[0.97] disabled:opacity-60"
                @click="share">
                {{ sharing ? 'Bild wird erstellt …' : 'Als Story teilen' }}
            </button>
            <button type="button"
                class="flex h-[54px] w-full items-center justify-center rounded-full bg-white/15 text-[19px] font-semibold backdrop-blur-md transition active:scale-[0.97]"
                @click="emit('close')">
                Weiter
            </button>
        </div>
    </MomentScreen>
</template>
