<script setup>
import { isIosApp } from '@/Composables/useNativeApp';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import { Head, router } from '@inertiajs/vue3';
import { usePush } from '@/Composables/usePush';

// App Store (Guideline 2.2): in der iOS-App ist von keiner Beta die Rede.
const inApp = isIosApp();

/**
 * Warteliste (seit 09.11.2026): das Konto steht, der Admin schaltet frei.
 * Die Freischaltung kommt per Mail — und per Push, wer ihn hier erlaubt.
 */
const props = defineProps({
    name: String,
    email: String,
    since: String,
    vapidPublicKey: { type: String, default: '' },
});

const push = usePush(props.vapidPublicKey);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <GuestLayout>
        <Head title="Warteliste" />

        <div class="w-full max-w-sm text-center">
            <Wordmark class="mx-auto h-7 text-ink" />

            <div class="mt-8 rounded-card bg-surface p-6 text-left">
                <p class="text-[15px] font-semibold text-accent">Warteliste</p>
                <h1 class="mt-1 text-[28px] font-extrabold leading-tight tracking-[-0.03em] text-ink">Du bist auf der Liste.</h1>
                <p class="mt-3 text-[17px] leading-snug text-ink-2">
                    {{ inApp ? 'Wir nehmen' : 'Zone3 ist gerade in der Beta, und wir nehmen' }} neue Läufer nach und nach auf.
                    Sobald du dabei bist, bekommst du eine Mail an <span class="font-medium text-ink">{{ email }}</span>.
                </p>

                <div v-if="push.supported.value" class="mt-5">
                    <p v-if="push.subscribed.value" class="rounded-field bg-success-soft px-4 py-3 text-[15px] text-success-ink">
                        Du bekommst auch eine Mitteilung.
                    </p>
                    <button
                        v-else
                        type="button"
                        :disabled="push.loading.value"
                        class="flex h-[50px] w-full items-center justify-center rounded-full bg-accent text-[17px] font-semibold text-white transition active:scale-[0.97] disabled:opacity-40"
                        @click="push.subscribe()">
                        Auch per Mitteilung Bescheid geben
                    </button>
                    <p v-if="push.error.value" class="mt-2 text-[13px] text-danger-ink">{{ push.error.value }}</p>
                </div>
            </div>

            <button type="button" class="mt-6 text-[15px] text-ink-3 active:opacity-60" @click="logout">Abmelden</button>
        </div>
    </GuestLayout>
</template>
