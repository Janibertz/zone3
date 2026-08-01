<script setup>
import { Head } from '@inertiajs/vue3';

// `window` ist im Template nicht verfuegbar (steht nicht auf Vues Global-Whitelist),
// der Button warf deshalb einen TypeError statt neu zu laden.
function reload() {
    window.location.reload();
}
</script>

<template>
    <Head title="Wartung – Zone3" />
    <div class="min-h-screen bg-slate-950 flex items-center justify-center px-4">
        <div class="text-center max-w-md">
            <div class="h-16 w-16 rounded-2xl bg-indigo-600 flex items-center justify-center mx-auto mb-6">
                <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Kurze Wartungspause</h1>
            <p class="text-slate-400 text-sm leading-relaxed mb-6">
                Zone3 wird gerade aktualisiert und ist in Kürze wieder erreichbar.<br>
                Bitte versuche es in wenigen Minuten erneut.
            </p>
            <button @click="reload"
                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-sm font-semibold text-white transition-colors">
                Seite neu laden
            </button>
        </div>
    </div>
</template>
