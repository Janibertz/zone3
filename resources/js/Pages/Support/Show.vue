<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ ticket: Object });

const reply    = ref('');
const sending  = ref(false);

const statusConfig = {
    open:        { label: 'Offen',          class: 'bg-info-soft text-info-ink' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-warn-soft text-warn-ink' },
    resolved:    { label: 'Gelöst',         class: 'bg-success-soft text-success-ink' },
    closed:      { label: 'Geschlossen',    class: 'bg-surface-2 text-ink-3' },
};

const isClosed = ['resolved', 'closed'].includes(props.ticket.status);

function sendReply() {
    sending.value = true;
    router.post(route('support.tickets.reply', props.ticket.id), { message: reply.value }, {
        preserveScroll: true,
        onSuccess: () => { reply.value = ''; },
        onFinish: () => { sending.value = false; },
    });
}

function formatDate(dt) {
    return new Date(dt).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head :title="`Ticket #${ticket.id}`" />
    <AuthenticatedLayout>
        <div class="max-w-2xl mx-auto px-4 py-6 space-y-4">

            <Link :href="route('support.tickets.index')" class="inline-flex items-center gap-1.5 text-sm text-ink-3 hover:text-ink-2 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Zurück
            </Link>

            <!-- Header -->
            <div class="bg-surface rounded-card px-5 py-4">
                <div class="flex items-start justify-between gap-3 mb-1">
                    <h1 class="text-base font-bold text-ink">#{{ ticket.id }} {{ ticket.subject }}</h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium shrink-0"
                        :class="statusConfig[ticket.status]?.class">
                        {{ statusConfig[ticket.status]?.label }}
                    </span>
                </div>
                <p class="text-xs text-ink-3">Erstellt am {{ formatDate(ticket.created_at) }}</p>
            </div>

            <!-- Nachrichten -->
            <div class="space-y-3">
                <!-- Erste Nachricht (Ticket-Beschreibung) -->
                <div class="flex gap-3">
                    <div class="h-8 w-8 rounded-full bg-accent-soft flex items-center justify-center shrink-0 text-accent-ink text-xs font-bold">
                        {{ ticket.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 bg-surface rounded-card rounded-tl-sm px-4 py-3">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-ink-2">{{ ticket.user?.name }}</span>
                            <span class="text-xs text-ink-3">{{ formatDate(ticket.created_at) }}</span>
                        </div>
                        <p class="text-sm text-ink-2 whitespace-pre-wrap">{{ ticket.description }}</p>
                    </div>
                </div>

                <!-- Antworten -->
                <div v-for="rep in ticket.replies" :key="rep.id" class="flex gap-3" :class="rep.is_admin ? 'flex-row-reverse' : ''">
                    <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 text-xs font-bold"
                        :class="rep.is_admin
                            ? 'bg-accent-soft text-accent-ink'
                            : 'bg-accent-soft text-accent-ink'">
                        {{ rep.is_admin ? 'Z3' : rep.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 border rounded-card px-4 py-3"
                        :class="rep.is_admin
                            ? 'bg-accent-soft border-accent/25 rounded-tr-sm'
                            : 'bg-surface border-line rounded-tl-sm'">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold" :class="rep.is_admin ? 'text-accent-ink' : 'text-ink-2'">
                                {{ rep.is_admin ? 'Zone3-Team' : rep.user?.name }}
                            </span>
                            <span class="text-xs text-ink-3">{{ formatDate(rep.created_at) }}</span>
                        </div>
                        <p class="text-sm text-ink-2 whitespace-pre-wrap">{{ rep.message }}</p>
                    </div>
                </div>
            </div>

            <!-- Antwort-Formular -->
            <div v-if="!isClosed" class="bg-surface rounded-card p-4 space-y-3">
                <textarea v-model="reply" rows="3" placeholder="Deine Antwort…"
                    class="w-full rounded-field bg-surface px-3 py-2.5 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40 resize-none" />
                <button @click="sendReply" :disabled="sending || !reply.trim()"
                    class="w-full py-2.5 rounded-field bg-accent hover:opacity-90 disabled:opacity-50 text-sm font-semibold text-white transition-colors">
                    {{ sending ? 'Wird gesendet…' : 'Antworten' }}
                </button>
            </div>
            <div v-else class="text-center text-sm text-ink-3 py-3">
                Dieses Ticket ist geschlossen.
            </div>

        </div>
    </AuthenticatedLayout>
</template>
