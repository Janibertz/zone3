<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Passwort zurücksetzen" />

        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="text-center mb-7">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <ZoneIcon class="h-14 w-14" />
                    <Wordmark class="mt-1 h-6 text-ink" />
                </Link>
                <h1 class="mt-6 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">Neues Passwort</h1>
                <p class="mt-1 text-[17px] text-ink-3">Wähle ein sicheres Passwort</p>
            </div>

            <!-- Card -->
            <div class="rounded-card bg-surface p-5">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="mb-1.5 block text-[15px] text-ink-2">E-Mail</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-[15px] text-ink-2">Neues Passwort</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Mindestens 8 Zeichen"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password" />
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1.5 block text-[15px] text-ink-2">Passwort bestätigen</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-full bg-accent px-6 text-[17px] font-semibold text-white transition active:scale-[0.97] hover:opacity-90 disabled:opacity-40"
                    >
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Passwort zurücksetzen
                    </button>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>
