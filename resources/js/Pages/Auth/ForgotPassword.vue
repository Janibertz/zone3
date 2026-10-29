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
            <div class="text-center mb-7">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <div class="h-14 w-14 rounded-[14px] bg-accent flex items-center justify-center">
                        <span class="text-white text-lg font-bold">Z3</span>
                    </div>
                    <span class="sr-only">Zone3</span>
                </Link>
                <h1 class="mt-6 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">Passwort vergessen?</h1>
                <p class="mt-1 text-[17px] text-ink-3">Wir senden dir einen Reset-Link per E-Mail</p>
            </div>

            <!-- Card -->
            <div class="rounded-card bg-surface p-5">

                <div v-if="status" class="mb-5 flex items-center gap-2 rounded-field bg-success-soft px-4 py-3 text-[15px] text-success-ink">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="mb-1.5 block text-[15px] text-ink-2">E-Mail-Adresse</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="deine@email.de"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
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
                        Reset-Link senden
                    </button>
                </form>
            </div>

            <p class="mt-5 text-center text-[15px] text-ink-3">
                <Link :href="route('login')" class="text-accent font-medium hover:text-accent transition-colors">
                    Zurück zur Anmeldung
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
