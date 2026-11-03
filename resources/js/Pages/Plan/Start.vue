<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

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
                <AppCard>
                    <EmptyState v-if="event"
                        title="Noch kein Trainingsplan"
                        :description="`Für ${event.name} (${event.distance}, ${event.date_label}) baut dein Coach dir einen Plan. Vorher fragt er kurz, was die Daten nicht wissen.`">
                        <template #icon>
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08M8.25 8.25H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" /></svg>
                        </template>
                        <AppButton :href="route('plan-interview.show', { event: event.id })">Plan erstellen</AppButton>
                    </EmptyState>
                    <EmptyState v-else
                        title="Noch kein Rennen"
                        description="Ein Plan arbeitet auf ein Rennen hin. Trag dein nächstes Rennen ein, dann baut dein Coach den Plan dazu.">
                        <template #icon>
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" /></svg>
                        </template>
                        <AppButton :href="route('events.index', { new: 1 })">Rennen eintragen</AppButton>
                    </EmptyState>
                </AppCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
