<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({ status: String });

const form = useForm({ email: '' });

const submit = () => { form.post(route('password.email')); };
</script>

<template>
    <GuestLayout>
        <Head title="Passwort vergessen" />

        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="text-center mb-8">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <div class="h-12 w-12 rounded-2xl bg-accent flex items-center justify-center shadow-lg">
                        <span class="text-white text-lg font-bold">Z3</span>
                    </div>
                    <span class="text-xl font-bold text-ink tracking-tight">Zone3</span>
                </Link>
                <h1 class="mt-5 text-2xl font-bold text-ink">Passwort vergessen?</h1>
                <p class="mt-1 text-sm text-ink-3">Wir senden dir einen Reset-Link per E-Mail</p>
            </div>

            <!-- Card -->
            <div class="bg-surface rounded-2xl border border-line-strong shadow-xl p-6">

                <div v-if="status" class="mb-5 flex items-center gap-2 rounded-xl bg-success/10 border border-success/30 px-4 py-3 text-sm text-success">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-ink-3 mb-1.5">E-Mail-Adresse</label>
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

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-sm"
                    >
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Reset-Link senden
                    </button>
                </form>
            </div>

            <p class="mt-5 text-center text-sm text-ink-3">
                <Link :href="route('login')" class="text-accent font-medium hover:text-accent transition-colors">
                    Zurück zur Anmeldung
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
