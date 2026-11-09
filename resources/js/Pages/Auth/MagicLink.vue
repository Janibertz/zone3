<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Der Link aus der Mail landet hier, angemeldet wird erst mit dem Knopf
 * (POST) — Mail-Scanner öffnen Links vorab und hätten ihn sonst verbraucht.
 */
const props = defineProps({
    token: String,
    email: String,
});

const form = useForm({});
function go() {
    form.post(route('login.link.consume', props.token));
}
</script>

<template>
    <GuestLayout>
        <Head title="Anmelden" />

        <div class="w-full max-w-sm text-center">
            <Wordmark class="mx-auto h-7 text-ink" />

            <div class="mt-8 rounded-card bg-surface p-6">
                <template v-if="token">
                    <h1 class="text-[22px] font-bold tracking-[-0.02em] text-ink">Fast geschafft</h1>
                    <p class="mt-2 text-[15px] text-ink-2">Anmelden als <span class="font-medium text-ink">{{ email }}</span></p>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="mt-5 flex h-[50px] w-full items-center justify-center rounded-full bg-accent text-[17px] font-semibold text-white transition active:scale-[0.97] disabled:opacity-40"
                        @click="go">
                        Weiter zu Zone3
                    </button>
                </template>
                <template v-else>
                    <h1 class="text-[22px] font-bold tracking-[-0.02em] text-ink">Link abgelaufen</h1>
                    <p class="mt-2 text-[15px] text-ink-2">Der Link gilt 30 Minuten und nur einmal. Fordere einfach einen neuen an.</p>
                    <Link
                        :href="route('login')"
                        class="mt-5 flex h-[50px] w-full items-center justify-center rounded-full bg-accent text-[17px] font-semibold text-white transition active:scale-[0.97]">
                        Zur Anmeldung
                    </Link>
                </template>
            </div>
        </div>
    </GuestLayout>
</template>
