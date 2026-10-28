<script setup>
import { Head } from '@inertiajs/vue3';

/**
 * Rückweg von Garmin ohne Anmeldung. Aus der Homescreen-App öffnet iOS den
 * Callback in Safari, und von dort führt kein Weg zurück in die App. Die
 * Seite sagt, was passiert ist, und schickt den Athleten zurück zur App.
 */
defineProps({
    ok:      { type: Boolean, default: false },
    message: { type: String, default: '' },
});
</script>

<template>
    <Head :title="ok ? 'Garmin verbunden – Zone3' : 'Garmin – Zone3'" />
    <div class="min-h-screen bg-canvas flex items-center justify-center px-4">
        <div class="text-center max-w-md">
            <div class="h-16 w-16 rounded-2xl flex items-center justify-center mx-auto mb-6"
                :class="ok ? 'bg-success-soft' : 'bg-danger-soft'">
                <svg v-if="ok" class="h-8 w-8 text-success-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else class="h-8 w-8 text-danger-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-ink mb-2">{{ message }}</h1>
            <p class="text-ink-3 text-[15px] leading-relaxed">
                <template v-if="ok">
                    Du kannst dieses Fenster schließen und zurück zur Zone3-App wechseln.
                    Dort steht die Verbindung, sobald du zurückwechselst.
                </template>
                <template v-else>
                    Wechsle zurück zur Zone3-App und starte die Verbindung noch einmal.
                </template>
            </p>
        </div>
    </div>
</template>
