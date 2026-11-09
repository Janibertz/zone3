<script setup>
import { onMounted, onBeforeUnmount } from 'vue';

/**
 * Die Hülle eines Moments als Vollbild (seit 09.11.2026): Foto randlos,
 * Verlauf nach unten, Abzeichen oben links, Schließen oben rechts, der Inhalt
 * unten. „Woche geschafft“ und „Rennen gelaufen“ benutzen sie; die Bestzeit
 * hat ihre eigene, ältere Fassung derselben Form.
 */
const props = defineProps({
    photo: { type: String, required: true },
    focus: { type: String, default: 'center' },
    badge: { type: String, required: true },
});
const emit = defineEmits(['close']);

function onKey(e) { if (e.key === 'Escape') emit('close'); }
onMounted(() => { document.addEventListener('keydown', onKey); document.body.style.overflow = 'hidden'; });
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); document.body.style.overflow = ''; });
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[80] overflow-y-auto bg-black text-white" role="dialog" aria-modal="true" :aria-label="badge">
            <img :src="props.photo" alt="" aria-hidden="true" class="pointer-events-none fixed inset-0 h-full w-full object-cover" :style="{ objectPosition: props.focus }" />
            <div class="pointer-events-none fixed inset-0" aria-hidden="true"
                style="background: linear-gradient(to bottom, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0.05) 25%, rgba(0,0,0,0.25) 42%, rgba(0,0,0,0.9) 66%, #000 100%);" />

            <div class="relative mx-auto flex min-h-full w-full max-w-md flex-col px-5 pt-safe-banner pb-safe-sheet">
                <div class="mt-2 flex items-center justify-between">
                    <span class="flex items-center gap-2 rounded-full bg-accent/90 py-1.5 pl-2 pr-3">
                        <slot name="icon" />
                        <span class="text-[14px] font-bold">{{ badge }}</span>
                    </span>
                    <button type="button" aria-label="Schließen"
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-black/35 backdrop-blur-md transition active:scale-90"
                        @click="emit('close')">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>

                <div class="min-h-[28vh] flex-1" />
                <slot />
            </div>
        </div>
    </Teleport>
</template>
