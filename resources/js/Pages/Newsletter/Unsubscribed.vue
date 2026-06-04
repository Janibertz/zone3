<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    alreadyUnsubscribed: Boolean,
    name:  String,
    token: { type: String, default: null },
});

const resubscribing = ref(false);
const resubscribed  = ref(false);

function resubscribe() {
    resubscribing.value = true;
    router.post(route('newsletter.resubscribe'), { token: props.token }, {
        onSuccess: () => { resubscribed.value = true; },
        onFinish:  () => { resubscribing.value = false; },
    });
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 flex items-center justify-center p-6">
        <div class="bg-white rounded-2xl shadow-lg max-w-md w-full p-8 text-center">
            <!-- Logo -->
            <div class="flex items-center justify-center gap-2 mb-6">
                <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center">
                    <span class="text-white text-sm font-bold">Z3</span>
                </div>
                <span class="text-xl font-bold text-gray-900">Zone3</span>
            </div>

            <template v-if="resubscribed">
                <div class="h-14 w-14 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
                    <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 mb-2">Wieder angemeldet!</h1>
                <p class="text-gray-600 text-sm">Du erhältst den Zone3-Newsletter wieder. 🎉</p>
            </template>

            <template v-else-if="alreadyUnsubscribed">
                <div class="h-14 w-14 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
                    <svg class="h-7 w-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 mb-2">Bereits abgemeldet</h1>
                <p class="text-gray-600 text-sm">{{ name }}, du hast den Newsletter bereits abbestellt.</p>
            </template>

            <template v-else>
                <div class="h-14 w-14 rounded-full bg-indigo-100 flex items-center justify-center mx-auto mb-4">
                    <svg class="h-7 w-7 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 mb-2">Newsletter abgemeldet</h1>
                <p class="text-gray-600 text-sm mb-6">
                    {{ name }}, du erhältst keine Newsletter-E-Mails mehr von Zone3.
                </p>
                <button v-if="token" @click="resubscribe" :disabled="resubscribing"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 disabled:opacity-50 transition-colors">
                    <svg v-if="resubscribing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    Wieder anmelden
                </button>
            </template>

            <div class="mt-8 pt-6 border-t border-gray-100">
                <a href="/" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Zur Zone3 App →</a>
            </div>
        </div>
    </div>
</template>
