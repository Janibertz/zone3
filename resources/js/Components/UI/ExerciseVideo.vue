<script setup>
/**
 * Übungsvideo von YouTube, ohne die App zu verlassen.
 *
 * Bis zum Antippen ist das eine eigene Vorschau im Zone3-Look: Bild aus dem
 * Katalog (sonst eine ruhige Fläche), Play-Knopf, Hinweis „YouTube". Es
 * wird nichts von Google geladen, auch kein Vorschaubild — erst der Tipp
 * lädt den Player von youtube-nocookie.com (Datenschutz, Abschnitt 4 und 8).
 *
 * Den Player selbst umzugestalten verbieten YouTubes API-Richtlinien
 * (nichts darüberlegen, nichts ausblenden). `playsinline` hält das Video
 * auf dem iPhone in der Seite statt im Vollbild-Player bzw. der YouTube-App.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    youtubeId: { type: String, required: true },
    poster:    { type: String, default: null },
    title:     { type: String, default: '' },
});

const playing = ref(false);

const src = computed(() => {
    const q = new URLSearchParams({ autoplay: '1', playsinline: '1', rel: '0', modestbranding: '1' });
    return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(props.youtubeId)}?${q}`;
});
</script>

<template>
    <div class="relative aspect-video w-full overflow-hidden bg-black">
        <iframe
            v-if="playing"
            :src="src"
            :title="title ? `Video: ${title}` : 'Übungsvideo'"
            class="absolute inset-0 h-full w-full"
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
        />
        <button
            v-else
            type="button"
            class="group absolute inset-0 flex items-center justify-center bg-white transition active:opacity-80"
            :aria-label="title ? `Video abspielen: ${title}` : 'Video abspielen'"
            @click="playing = true"
        >
            <img v-if="poster" :src="poster" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-contain" />
            <span v-else class="absolute inset-0 bg-gradient-to-br from-surface-2 to-surface-3" />
            <span class="absolute inset-0 bg-black/25" />

            <span class="relative flex h-16 w-16 items-center justify-center rounded-full bg-white/90 shadow-lg backdrop-blur transition group-active:scale-95">
                <svg class="ml-1 h-7 w-7 text-accent" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l10.6-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" /></svg>
            </span>

            <span class="absolute bottom-2.5 left-3 rounded-full bg-black/55 px-2.5 py-1 text-[12px] font-semibold text-white backdrop-blur">
                Video · YouTube
            </span>
        </button>
    </div>
</template>
