<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppButton from '@/Components/UI/AppButton.vue';

/**
 * Der Plan-Tab ohne aktiven Plan.
 *
 * Der Tab heißt immer „Plan“ (Entscheidung von Jan, 03.11.2026). Ohne Plan
 * zeigt er den nächsten Schritt: zum eingetragenen Rennen den Plan erstellen
 * (über das Interview), sonst erst ein Rennen eintragen.
 */
defineProps({
    event: { type: Object, default: null },
});
</script>

<template>
    <Head title="Plan" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-2xl" title="Plan" />

            <div class="mx-auto max-w-2xl px-4 pb-8 pt-3 lg:px-8">
                <!-- Mit Foto (09.11.2026, Mockup 6): der Anfang eines Plans ist ein
                     Moment, keine leere Liste. Seit 10.11.2026 auch ohne Rennen:
                     Blöcke zu zwölf Wochen. -->
                <section class="relative overflow-hidden rounded-card bg-black text-white">
                    <img src="/images/moments/beine.jpg" alt="" aria-hidden="true"
                        class="absolute inset-0 h-full w-full object-cover object-[center_45%]" />
                    <div class="absolute inset-0" aria-hidden="true"
                        style="background: linear-gradient(to bottom, rgba(0,0,0,0.25) 0%, rgba(0,0,0,0) 30%, rgba(0,0,0,0.55) 60%, rgba(0,0,0,0.92) 100%);" />

                    <div class="relative flex min-h-[460px] flex-col p-5 lg:min-h-[420px]">
                        <div class="flex-1" />
                        <template v-if="event">
                            <p v-if="event.open" class="text-[15px] font-semibold text-white/80">{{ event.name }} · Blöcke zu 12 Wochen</p>
                            <p v-else class="text-[15px] font-semibold text-white/80">{{ event.name }} · {{ event.distance }} · {{ event.date_label }}</p>
                            <h2 class="mt-1 text-[34px] font-extrabold leading-[1.05] tracking-[-0.035em]">Dein Plan wartet<span class="text-accent">.</span></h2>
                            <p class="mt-2 text-[17px] leading-snug text-white/80">
                                Dein Coach fragt kurz, was die Daten nicht wissen. Dann steht dein Weg {{ event.open ? 'durch den ersten Block' : 'bis zum Renntag' }}.
                            </p>
                            <AppButton class="mt-5" block :href="route('plan-interview.show', { event: event.id })">Plan erstellen</AppButton>
                        </template>
                        <template v-else>
                            <h2 class="text-[34px] font-extrabold leading-[1.05] tracking-[-0.035em]">Worauf läufst du hin<span class="text-accent">?</span></h2>
                            <p class="mt-2 text-[17px] leading-snug text-white/80">
                                Sag dem Coach dein Ziel. Den Rest rechnet er aus deinen Läufen.
                            </p>
                            <AppButton class="mt-5" block :href="route('events.index', { new: 1 })">Rennen eintragen</AppButton>
                            <AppButton class="mt-2" variant="secondary" block :href="route('events.index', { new: 'open' })">Ohne Rennen trainieren</AppButton>
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
