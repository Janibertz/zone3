<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Anmelden" />

        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="text-center mb-8">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <div class="h-12 w-12 rounded-2xl bg-accent flex items-center justify-center shadow-lg">
                        <span class="text-white text-lg font-bold">Z3</span>
                    </div>
                    <span class="text-xl font-bold text-ink tracking-tight">Zone3</span>
                </Link>
                <h1 class="mt-5 text-2xl font-bold text-ink">Willkommen zurück</h1>
                <p class="mt-1 text-sm text-ink-3">Meld dich an und weiter trainieren</p>
            </div>

            <!-- Card -->
            <div class="bg-surface rounded-2xl border border-line-strong shadow-xl p-6">

                <!-- Status message -->
                <div v-if="status" class="mb-5 flex items-center gap-2 rounded-xl bg-success/10 border border-success/30 px-4 py-3 text-sm text-success">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-ink-3 mb-1.5">E-Mail</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="deine@email.de"
                            class="block w-full rounded-xl border border-line-strong bg-surface-2 px-4 py-2.5 text-sm text-ink placeholder-ink-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40 transition-colors"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-sm font-medium text-ink-3">Passwort</label>
                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-xs text-accent hover:text-accent transition-colors"
                            >
                                Vergessen?
                            </Link>
                        </div>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="block w-full rounded-xl border border-line-strong bg-surface-2 px-4 py-2.5 text-sm text-ink placeholder-ink-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40 transition-colors"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password" />
                    </div>

                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="form.remember"
                            class="h-4 w-4 rounded border-line-strong bg-surface text-accent-ink focus:ring-accent/40"
                        />
                        <span class="text-sm text-ink-3">Angemeldet bleiben</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-sm mt-1"
                    >
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Anmelden
                    </button>
                </form>
            </div>

            <!-- Register link -->
            <p class="mt-5 text-center text-sm text-ink-3">
                Noch kein Account?
                <Link :href="route('register')" class="text-accent font-medium hover:text-accent transition-colors">
                    Jetzt registrieren
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
