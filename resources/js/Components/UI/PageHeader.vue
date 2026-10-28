<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Large-title page header, the app-like counterpart to the old
 * `<template #header>` bar. `back` turns the title into a detail-view
 * header with a back affordance.
 */
defineProps({
    title:    { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Kleine Zeile ÜBER dem Titel, z. B. das Datum — wie in Health. */
    eyebrow:  { type: String, default: null },
    /** Route URL for the back chevron. Omit for top-level pages. */
    back:     { type: String, default: null },
});
</script>

<template>
    <header class="px-4 pb-2 pt-2 lg:px-8 lg:pt-8">
        <!--
            `active:scale` ist hier kein Zierrat: der Hover-Zustand liegt seit
            dem 09.10. hinter `@media (hover: hover)`, auf dem iPhone bleibt
            also nichts uebrig, was auf die Beruehrung antwortet. Ein
            Zurueck-Link wird oft getippt — ohne Rueckmeldung tippt man ein
            zweites Mal.
        -->
        <Link
            v-if="back"
            :href="back"
            class="-ml-2 mb-1 inline-flex items-center gap-0.5 rounded-field px-2 py-1 text-[17px] text-accent transition-[opacity,transform] duration-150 ease-out-strong hover:opacity-70 active:scale-[0.97]"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Zurück
        </Link>

        <div class="flex items-end gap-3">
            <div class="min-w-0 flex-1">
                <!--
                    Die Datumszeile ist normal gesetzt, nicht in gesperrten
                    Versalien: Health schreibt „Freitag, 16. Oktober", nicht
                    „FREITAG, 16. OKTOBER". Gesperrte Versalien sind ausserdem
                    genau das Label-Muster, das CLAUDE.md verbietet.
                -->
                <p v-if="eyebrow" class="text-[15px] leading-5 text-ink-3">{{ eyebrow }}</p>
                <!--
                    Negatives Tracking gehoert ab Grad 28 dazu. Seit den
                    Story-Slides (28.10.2026) extra fett und enger, wie dort
                    die Headlines — Inter schaltet bei der Groesse auf den
                    Display-Schnitt.
                -->
                <h1 class="line-clamp-2 break-words text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">{{ title }}</h1>
                <p v-if="subtitle" class="mt-0.5 text-[15px] text-ink-3">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.action" class="shrink-0 pb-1">
                <slot name="action" />
            </div>
        </div>
    </header>
</template>
