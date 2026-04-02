<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});
</script>

<template>
    <Head title="Zone3 – Trainingsplan" />
    <div class="bg-white text-black dark:bg-black dark:text-white">
        <div class="min-h-screen px-6 py-16">
            <div class="mx-auto max-w-4xl">
                <header class="mb-12 text-center">
                    <h1 class="text-4xl font-bold tracking-tight text-black dark:text-white sm:text-5xl">
                        Zone3
                    </h1>
                    <p class="mt-4 text-lg text-black/70 dark:text-white/70">
                        Verbinde deinen Strava-Account und erhalte deinen personalisierten Trainingsplan.
                    </p>
                </header>

                <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
                    <div class="rounded-xl border border-black/10 bg-white px-8 py-10 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                        <h2 class="text-2xl font-semibold text-black dark:text-white">
                            Schnell starten
                        </h2>
                        <p class="mt-4 text-base text-black/70 dark:text-white/70">
                            Erstelle ein Konto oder logge dich ein, um deine Aktivitäten zu synchronisieren und Trainingsziele zu verfolgen.
                        </p>

                        <div class="mt-8 flex flex-col gap-3">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="route('dashboard')"
                                class="inline-flex items-center justify-center rounded-lg bg-[#FF2D20] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#e0221c] focus:outline-none focus:ring-2 focus:ring-[#FF2D20]/60"
                            >
                                Zum Dashboard
                            </Link>

                            <template v-else>
                                <Link
                                    v-if="canLogin"
                                    :href="route('login')"
                                    class="inline-flex items-center justify-center rounded-lg border border-black/10 bg-white px-6 py-3 text-sm font-semibold text-black shadow-sm transition hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-[#FF2D20]/40 dark:border-white/10 dark:bg-zinc-900 dark:text-white dark:hover:bg-white/10"
                                >
                                    Einloggen
                                </Link>

                                <Link
                                    v-if="canRegister"
                                    :href="route('register')"
                                    class="inline-flex items-center justify-center rounded-lg bg-[#FF2D20] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#e0221c] focus:outline-none focus:ring-2 focus:ring-[#FF2D20]/60"
                                >
                                    Registrieren
                                </Link>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-xl border border-black/10 bg-white px-8 py-10 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                        <h2 class="text-2xl font-semibold text-black dark:text-white">
                            Was du bekommen kannst
                        </h2>
                        <ul class="mt-6 space-y-4 text-base text-black/70 dark:text-white/70">
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-500 text-white">
                                    ✓
                                </span>
                                Synchronisiere deine letzten Läufe von Strava.
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-500 text-white">
                                    ✓
                                </span>
                                Wähle ein Trainingsziel (5 km, 10 km, Halbmarathon, Marathon).
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-500 text-white">
                                    ✓
                                </span>
                                Erhalte einen persönlichen Wochenplan.
                            </li>
                        </ul>

                        <p class="mt-8 text-sm text-black/60 dark:text-white/60">
                            Zone3 ist dein Trainingsassistent, der dir hilft, smarter und mit mehr Spaß zu trainieren.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
