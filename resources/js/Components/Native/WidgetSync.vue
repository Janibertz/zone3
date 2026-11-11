<script setup>
/**
 * In der iOS-App: gibt dem Widget auf dem Homescreen die Adresse seiner
 * Daten und lässt es neu laden, beim Öffnen und bei jeder Rückkehr in die
 * App — so steht nach einem Lauf sofort „Erledigt“ im Widget.
 */
import { onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { syncWidget } from '@/Composables/useWidget';
import { nativePlugin } from '@/Composables/useNativeApp';

const page = usePage();
let listener = null;

function run() {
    syncWidget(page.props.auth?.user?.id).catch((e) => console.warn('Widget: Abgleich fehlgeschlagen', e));
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
