<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
            <div class="text-center mb-8">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <div class="h-12 w-12 rounded-2xl bg-accent flex items-center justify-center shadow-lg">
                        <span class="text-white text-lg font-bold">Z3</span>
                    </div>
                    <span class="text-xl font-bold text-ink tracking-tight">Zone3</span>
                </Link>
                <h1 class="mt-5 text-2xl font-bold text-ink">Neues Passwort</h1>
                <p class="mt-1 text-sm text-ink-3">Wähle ein sicheres Passwort</p>
            </div>

            <!-- Card -->
            <div class="bg-surface rounded-2xl border border-line-strong shadow-xl p-6">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-ink-3 mb-1.5">E-Mail</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            class="block w-full rounded-xl border border-line-strong bg-surface-2 px-4 py-2.5 text-sm text-ink placeholder-ink-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40 transition-colors"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-ink-3 mb-1.5">Neues Passwort</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Mindestens 8 Zeichen"
                            class="block w-full rounded-xl border border-line-strong bg-surface-2 px-4 py-2.5 text-sm text-ink placeholder-ink-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40 transition-colors"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password" />
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-ink-3 mb-1.5">Passwort bestätigen</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••"
                            class="block w-full rounded-xl border border-line-strong bg-surface-2 px-4 py-2.5 text-sm text-ink placeholder-ink-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40 transition-colors"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-sm"
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
