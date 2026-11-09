<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { clock } from '@/Composables/useFormat';
import { drawStory, shareStory } from '@/Composables/useStoryImage';

/**
 * „Neue Bestzeit“ als Vollbild (seit 09.11.2026, Mockup von Jan
 * abgenommen: „Geil. Genau so will es!“). Erscheint einmal auf „Heute“, wenn
 * ein Lauf eine Bestzeit aufgestellt hat; Schließen und „Zur Aktivität“
 * melden es als gesehen, wie vorher „Danke, gesehen“.
 *
 * Das Foto (Unsplash-Lizenz) liegt in public/images/moments/.
 */
const props = defineProps({
    record:  { type: Object, required: true },
    message: { type: String, default: null },
});
const emit = defineEmits(['close']);

const PHOTO = '/images/moments/bestzeit.jpg';

const gain = computed(() => props.record.previous_seconds - props.record.seconds);
const gainLabel = computed(() => gain.value >= 60 ? `−${clock(gain.value)}` : `−${gain.value} s`);
const day = computed(() => new Date(props.record.date + 'T00:00:00')
    .toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' }));

const sharing = ref(false);
const shareNote = ref(null);

async function share() {
    sharing.value = true;
    shareNote.value = null;
    try {
        const r = props.record;
        const stats = [
            { value: r.pace, label: 'min/km' },
            { value: gainLabel.value, label: 'schneller', color: '#30d158' },
        ];
        if (r.race && r.race.days > 0) stats.push({ value: String(r.race.days), label: 'Tage bis zum Rennen' });

        const blob = await drawStory({
            photo: PHOTO, focusX: 0.35,
            eyebrow: 'Neue Bestzeit', label: r.distance, value: clock(r.seconds), stats,
        });
        const how = await shareStory(blob, `zone3-bestzeit-${r.date}.png`);
        if (how === 'downloaded') shareNote.value = 'Bild gespeichert. Du findest es in deinen Downloads.';
    } catch {
        shareNote.value = 'Das Bild konnte nicht erstellt werden.';
    } finally {
        sharing.value = false;
    }
}

function onKey(e) { if (e.key === 'Escape') emit('close'); }
onMounted(() => { document.addEventListener('keydown', onKey); document.body.style.overflow = 'hidden'; });
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); document.body.style.overflow = ''; });
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[80] overflow-y-auto bg-black text-white" role="dialog" aria-modal="true" aria-label="Neue Bestzeit">
            <img :src="PHOTO" alt="" aria-hidden="true" class="pointer-events-none fixed inset-0 h-full w-full object-cover object-[35%_center]" />
            <div class="pointer-events-none fixed inset-0" aria-hidden="true"
                style="background: linear-gradient(to bottom, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0.05) 28%, rgba(0,0,0,0.2) 45%, rgba(0,0,0,0.88) 70%, #000 100%);" />

            <div class="relative mx-auto flex min-h-full w-full max-w-md flex-col px-5 pt-safe-banner pb-safe-sheet">
                <div class="mt-2 flex items-center justify-between">
                    <span class="flex items-center gap-2 rounded-full bg-accent/90 py-1.5 pl-2 pr-3">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 01-10 0V4z" /><path d="M17 5h3v2a3 3 0 01-3 3M7 5H4v2a3 3 0 003 3" /></svg>
                        <span class="text-[14px] font-bold">Neue Bestzeit</span>
                    </span>
                    <button type="button" aria-label="Schließen"
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-black/35 backdrop-blur-md transition active:scale-90"
                        @click="emit('close')">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>

                <div class="min-h-[34vh] flex-1" />

                <p class="text-[20px] font-bold text-white/80">{{ record.distance }}</p>
                <p class="mt-0.5 font-rounded text-[96px] font-extrabold leading-[0.92] tracking-[-0.055em] sm:text-[104px]">{{ clock(record.seconds) }}</p>
                <p class="mt-3.5 flex flex-wrap items-center gap-2.5 text-[17px]">
                    <span class="rounded-lg bg-success px-2.5 py-1 font-extrabold tabular-nums text-black">{{ gainLabel }}</span>
                    <span class="tabular-nums text-white/75">vorher {{ clock(record.previous_seconds) }} · {{ record.pace }} /km</span>
                </p>
                <p class="mt-4 text-[17px] leading-snug text-white/90">
                    {{ message || `${record.activity_name || 'Dein Lauf'}, ${day}.` }}
                </p>

                <div class="mt-7 space-y-2.5">
                    <button type="button" :disabled="sharing"
                        class="flex h-[54px] w-full items-center justify-center gap-2 rounded-full bg-white text-[19px] font-bold text-black transition active:scale-[0.97] disabled:opacity-60"
                        @click="share">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12" /><path d="M7 8l5-5 5 5" /><path d="M5 13v6a2 2 0 002 2h10a2 2 0 002-2v-6" /></svg>
                        {{ sharing ? 'Bild wird erstellt …' : 'Als Story teilen' }}
                    </button>
                    <Link :href="route('activities.show', record.activity_id)"
                        class="flex h-[54px] w-full items-center justify-center rounded-full bg-white/15 text-[19px] font-semibold backdrop-blur-md transition active:scale-[0.97]"
                        @click="emit('close')">
                        Zur Aktivität
                    </Link>
                    <p v-if="shareNote" class="pt-1 text-center text-[15px] text-white/75">{{ shareNote }}</p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
