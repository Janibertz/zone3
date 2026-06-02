<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ ticket: Object });

const reply    = ref('');
const sending  = ref(false);

const statusConfig = {
    open:        { label: 'Offen',          class: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300' },
    resolved:    { label: 'Gelöst',         class: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300' },
    closed:      { label: 'Geschlossen',    class: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400' },
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

            <Link :href="route('support.tickets.index')" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-white transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Zurück
            </Link>

            <!-- Header -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl px-5 py-4">
                <div class="flex items-start justify-between gap-3 mb-1">
                    <h1 class="text-base font-bold text-gray-900 dark:text-white">#{{ ticket.id }} {{ ticket.subject }}</h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium shrink-0"
                        :class="statusConfig[ticket.status]?.class">
                        {{ statusConfig[ticket.status]?.label }}
                    </span>
                </div>
                <p class="text-xs text-gray-400 dark:text-slate-500">Erstellt am {{ formatDate(ticket.created_at) }}</p>
            </div>

            <!-- Nachrichten -->
            <div class="space-y-3">
                <!-- Erste Nachricht (Ticket-Beschreibung) -->
                <div class="flex gap-3">
                    <div class="h-8 w-8 rounded-full bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center shrink-0 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                        {{ ticket.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl rounded-tl-sm px-4 py-3">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-gray-700 dark:text-slate-300">{{ ticket.user?.name }}</span>
                            <span class="text-xs text-gray-400 dark:text-slate-500">{{ formatDate(ticket.created_at) }}</span>
                        </div>
                        <p class="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ ticket.description }}</p>
                    </div>
                </div>

                <!-- Antworten -->
                <div v-for="rep in ticket.replies" :key="rep.id" class="flex gap-3" :class="rep.is_admin ? 'flex-row-reverse' : ''">
                    <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 text-xs font-bold"
                        :class="rep.is_admin
                            ? 'bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300'
                            : 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300'">
                        {{ rep.is_admin ? 'Z3' : rep.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 border rounded-2xl px-4 py-3"
                        :class="rep.is_admin
                            ? 'bg-violet-50 dark:bg-violet-500/10 border-violet-100 dark:border-violet-500/20 rounded-tr-sm'
                            : 'bg-white dark:bg-slate-900 border-gray-100 dark:border-slate-800 rounded-tl-sm'">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold" :class="rep.is_admin ? 'text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-slate-300'">
                                {{ rep.is_admin ? 'Zone3-Team' : rep.user?.name }}
                            </span>
                            <span class="text-xs text-gray-400 dark:text-slate-500">{{ formatDate(rep.created_at) }}</span>
                        </div>
                        <p class="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ rep.message }}</p>
                    </div>
                </div>
            </div>

            <!-- Antwort-Formular -->
            <div v-if="!isClosed" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-4 space-y-3">
                <textarea v-model="reply" rows="3" placeholder="Deine Antwort…"
                    class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none" />
                <button @click="sendReply" :disabled="sending || !reply.trim()"
                    class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-sm font-semibold text-white transition-colors">
                    {{ sending ? 'Wird gesendet…' : 'Antworten' }}
                </button>
            </div>
            <div v-else class="text-center text-sm text-gray-400 dark:text-slate-500 py-3">
                Dieses Ticket ist geschlossen.
            </div>

        </div>
    </AuthenticatedLayout>
</template>
