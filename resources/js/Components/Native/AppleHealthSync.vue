<script setup>
/**
 * In der iOS-App: Apple Health an Zone3 schicken, beim Öffnen und bei jeder
 * Rückkehr in die App (Jans Entscheidung: kein Lesen im Hintergrund).
 * Ohne Einwilligung (`auth.appleHealth` = null) passiert nichts.
 *
 * Zeigt nichts an; Fehler landen nur in der Konsole — ein fehlgeschlagener
 * Abgleich wird beim nächsten Öffnen nachgeholt.
 */
import { onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { syncAppleHealth } from '@/Composables/useAppleHealth';
import { nativePlugin } from '@/Composables/useNativeApp';

const page = usePage();
let listener = null;

function run() {
    syncAppleHealth(page.props.auth?.appleHealth).catch((e) => console.warn('Apple Health: Abgleich fehlgeschlagen', e));
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
