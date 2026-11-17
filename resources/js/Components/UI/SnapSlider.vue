<script setup>
/**
 * Wischbare Reihe aus Folien, in voller Breite mit Punkten darunter oder
 * mit hereinragender nächster Folie (Jan,
 * 17.11.2026: Aufmacher und „Zuletzt“ auf Heute als Slider, „wirkt
 * aufgeräumter“). Natives Scroll-Snap statt einer Bibliothek: am iPhone
 * wischt es sich wie jede andere Liste, am Desktop mit dem Trackpad. Mit
 * Maus zusätzlich Pfeile, die Punkte springen. Eine einzige Folie zeigt
 * weder Punkte noch Pfeile.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    items:   { type: Array, required: true },
    /** Schlüssel je Folie. */
    itemKey: { type: Function, default: (item, i) => item?.id ?? i },
    /** Abstand zwischen den Folien in px. */
    gap:     { type: Number, default: 12 },
    /** Name der Reihe für Bildschirmleser. */
    label:   { type: String, default: 'Folien' },
    /** So viele px der nächsten Folie ragen herein (Zuletzt, Jan 17.11.2026). */
    peek:    { type: Number, default: 0 },
    /** Punkte weiß unten im Bild statt darunter (Foto-Aufmacher). */
    overlay: { type: Boolean, default: false },
});

/** Breite einer Folie samt Abstand — mit `peek` schmaler als die Reihe. */
function step(el) {
    return (el.firstElementChild?.offsetWidth || el.clientWidth) + props.gap;
}

const track = ref(null);
const active = ref(0);
let frame = 0;

function onScroll() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        const el = track.value;
        if (!el || !el.clientWidth) return;
        const atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2;
        active.value = atEnd ? props.items.length - 1 : Math.max(0, Math.min(props.items.length - 1, Math.round(el.scrollLeft / step(el))));
    });
}

function go(i) {
    const el = track.value;
    if (!el) return;
    const target = Math.max(0, Math.min(props.items.length - 1, i));
    el.scrollTo({ left: target * step(el), behavior: 'smooth' });
}

/** Pfeiltasten, wenn die Reihe den Fokus hat. */
function onKey(e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); go(active.value + 1); }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); go(active.value - 1); }
}

watch(() => props.items.length, () => {
    if (active.value > props.items.length - 1) go(props.items.length - 1);
});

onMounted(onScroll);
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <div class="group/slider relative min-w-0">
        <div ref="track" @scroll.passive="onScroll" @keydown="onKey"
            :tabindex="items.length > 1 ? 0 : undefined" role="region" :aria-roledescription="items.length > 1 ? 'Karussell' : undefined" :aria-label="label"
            :class="peek ? 'max-lg:-mx-4 max-lg:scroll-px-4 max-lg:px-4' : ''"
            class="flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain [scrollbar-width:none] focus:outline-none [&::-webkit-scrollbar]:hidden"
            :style="{ gap: `${gap}px` }">
            <div v-for="(item, i) in items" :key="itemKey(item, i)"
                class="flex shrink-0 snap-start snap-always flex-col [&>*]:grow"
                :style="{ width: peek && items.length > 1 ? `calc(100% - ${peek + gap}px)` : '100%' }"
                :aria-hidden="items.length > 1 && i !== active ? 'true' : undefined"
                :inert="items.length > 1 && i !== active ? true : undefined">
                <slot :item="item" :index="i" :active="i === active" />
            </div>
        </div>

        <template v-if="items.length > 1">
            <!-- Pfeile nur mit Maus, sonst reicht das Wischen. -->
            <button type="button" aria-label="Zurück" @click="go(active - 1)" :disabled="active === 0"
                class="absolute left-3 top-1/2 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white opacity-0 backdrop-blur-md transition hover:bg-black/60 disabled:!opacity-0 group-hover/slider:opacity-100 [@media(hover:hover)_and_(pointer:fine)]:flex">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <button type="button" aria-label="Weiter" @click="go(active + 1)" :disabled="active === items.length - 1"
                class="absolute right-3 top-1/2 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white opacity-0 backdrop-blur-md transition hover:bg-black/60 disabled:!opacity-0 group-hover/slider:opacity-100 [@media(hover:hover)_and_(pointer:fine)]:flex">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6" /></svg>
            </button>

            <!-- Mit hereinragender Karte sieht man, dass es weitergeht — wie bei „Als Nächstes“ ohne Punkte. -->
            <div v-if="!peek" class="flex justify-center gap-1.5"
                :class="overlay ? 'pointer-events-none absolute inset-x-0 bottom-2.5 [&>button]:pointer-events-auto' : 'mt-2.5'">
                <button v-for="(item, i) in items" :key="itemKey(item, i)" type="button" @click="go(i)"
                    :aria-label="`Folie ${i + 1} von ${items.length}`" :aria-current="i === active ? 'true' : undefined"
                    class="flex h-4 items-center px-0.5">
                    <span class="block h-[7px] rounded-full transition-[width,background-color] duration-200 ease-out-strong"
                        :class="overlay ? (i === active ? 'w-[18px] bg-white' : 'w-[7px] bg-white/40') : (i === active ? 'w-[18px] bg-ink' : 'w-[7px] bg-ink/25')" />
                </button>
            </div>
        </template>
    </div>
</template>
