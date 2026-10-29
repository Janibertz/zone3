<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

const props = defineProps({
    // Gültiger Token oder null — ohne gibt es kein Formular.
    invite:      { type: String, default: null },
    inviteGiven: { type: Boolean, default: false },
});

const form = useForm({
    invite: props.invite ?? '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Registrieren" />

        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="text-center mb-7">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <ZoneIcon class="h-14 w-14" />
                    <Wordmark class="mt-1 h-6 text-ink" />
                </Link>
                <h1 class="mt-6 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">
                    {{ invite ? 'Konto erstellen' : 'Nur auf Einladung' }}
                </h1>
                <p v-if="invite" class="mt-1 text-[17px] text-ink-3">Du wurdest zu Zone3 eingeladen</p>
            </div>

            <!-- Ohne gültigen Link kein Formular -->
            <div v-if="!invite" class="rounded-card bg-surface p-5 text-[15px] leading-relaxed text-ink-2">
                <p v-if="inviteGiven">
                    Dieser Einladungslink ist nicht mehr gültig — er wurde schon eingelöst,
                    ist abgelaufen oder wurde zurückgezogen. Frag nach einem neuen.
                </p>
                <p v-else>
                    Zone3 ist im Moment nur auf Einladung. Wenn du einen Einladungslink
                    bekommen hast, öffne ihn direkt.
                </p>
            </div>

            <!-- Card -->
            <div v-else class="rounded-card bg-surface p-5">
                <form @submit.prevent="submit" class="space-y-4">
                    <InputError :message="form.errors.invite" />

                    <div>
                        <label for="name" class="mb-1.5 block text-[15px] text-ink-2">Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Max Mustermann"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.name" />
                    </div>

                    <div>
                        <label for="email" class="mb-1.5 block text-[15px] text-ink-2">E-Mail</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            placeholder="deine@email.de"
                            class="z-input"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-[15px] text-ink-2">Passwort</label>
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
                        class="inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-full bg-accent px-6 text-[17px] font-semibold text-white transition active:scale-[0.97] hover:opacity-90 disabled:opacity-40 mt-1"
                    >
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Konto erstellen
                    </button>
                </form>
            </div>

            <!-- Login link -->
            <p class="mt-5 text-center text-[15px] text-ink-3">
                Bereits registriert?
                <Link :href="route('login')" class="text-accent font-medium hover:text-accent transition-colors">
                    Anmelden
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
