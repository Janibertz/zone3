<script setup>
/**
 * In der iOS-App: die nächsten sieben Tage per WorkoutKit auf die Apple
 * Watch, beim Öffnen und bei jeder Rückkehr in die App (wie Apple Health,
 * kein Hintergrund). Ohne Erlaubnis in iOS oder mit Garmin passiert nichts.
 */
import { onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { syncWatchWorkouts } from '@/Composables/useWatchWorkouts';
import { nativePlugin } from '@/Composables/useNativeApp';

const page = usePage();
let listener = null;

function run() {
    syncWatchWorkouts(page.props.auth?.appleWatch).catch((e) => console.warn('Apple Watch: Abgleich fehlgeschlagen', e));
}

onMounted(async () => {
    run();
    const app = nativePlugin('App');
    if (app) {
        listener = await app.addListener('appStateChange', ({ isActive }) => { if (isActive) run(); });
    }
});

onBeforeUnmount(() => listener?.remove?.());
</script>

<template>
    <span class="hidden" aria-hidden="true" />
</template>
