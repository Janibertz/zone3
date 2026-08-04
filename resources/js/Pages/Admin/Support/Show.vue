<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ ticket: Object });

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const reply    = ref('');
const sending  = ref(false);

const statusOptions = [
    { value: 'open',        label: 'Offen' },
    { value: 'in_progress', label: 'In Bearbeitung' },
    { value: 'resolved',    label: 'Gelöst' },
    { value: 'closed',      label: 'Geschlossen' },
];

const statusConfig = {
    open:        'bg-info-soft text-info-ink',
    in_progress: 'bg-warn-soft text-warn-ink',
    resolved:    'bg-success-soft text-success-ink',
    closed:      'bg-surface-2 text-ink-3',
};

function setStatus(status) {
    router.patch(route('admin.support.status', props.ticket.id), { status }, { preserveScroll: true });
}

function sendReply() {
    sending.value = true;
    router.post(route('admin.support.reply', props.ticket.id), { message: reply.value }, {
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
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.support.index')" class="text-ink-3 hover:text-ink-2 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                </Link>
                <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Ticket #{{ ticket.id }}</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="statusConfig[ticket.status]">
                    {{ statusOptions.find(s => s.value === ticket.status)?.label }}
                </span>
            </div>
        </template>

        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-5">

            <!-- Flash -->
            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <!-- Ticket-Info + Status -->
            <div class="bg-surface rounded-card px-5 py-4 flex items-start justify-between gap-4 shadow-card">
                <div>
                    <p class="text-sm font-semibold text-ink mb-1">{{ ticket.subject }}</p>
                    <p class="text-xs text-ink-3">Von {{ ticket.user?.name }} ({{ ticket.user?.email }}) · {{ formatDate(ticket.created_at) }}</p>
                </div>
                <div class="flex gap-2 flex-wrap justify-end shrink-0">
                    <button v-for="opt in statusOptions" :key="opt.value"
                        @click="setStatus(opt.value)"
                        class="px-3 py-1.5 rounded-field text-xs font-semibold border transition-colors"
                        :class="ticket.status === opt.value
                            ? statusConfig[opt.value] + ' border-transparent'
                            : 'border-line text-ink-3 hover:border-line-strong'">
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <!-- Nachrichten -->
            <div class="space-y-3">
                <div class="flex gap-3">
                    <div class="h-8 w-8 rounded-full bg-accent-soft flex items-center justify-center shrink-0 text-accent-ink text-xs font-bold">
                        {{ ticket.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 bg-surface rounded-card rounded-tl-sm px-4 py-3 shadow-card">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-ink-2">{{ ticket.user?.name }}</span>
                            <span class="text-xs text-ink-3">{{ formatDate(ticket.created_at) }}</span>
                        </div>
                        <p class="text-sm text-ink-2 whitespace-pre-wrap">{{ ticket.description }}</p>
                    </div>
                </div>

                <div v-for="rep in ticket.replies" :key="rep.id" class="flex gap-3" :class="rep.is_admin ? 'flex-row-reverse' : ''">
                    <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 text-xs font-bold"
                        :class="rep.is_admin ? 'bg-accent-soft text-accent-ink' : 'bg-accent-soft text-accent-ink'">
                        {{ rep.is_admin ? 'Z3' : rep.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 border rounded-card px-4 py-3"
                        :class="rep.is_admin
                            ? 'bg-accent-soft border-accent/25 rounded-tr-sm'
                            : 'bg-surface border-line rounded-tl-sm'">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold" :class="rep.is_admin ? 'text-accent-ink' : 'text-ink-2'">
                                {{ rep.is_admin ? rep.user?.name + ' (Admin)' : rep.user?.name }}
                            </span>
                            <span class="text-xs text-ink-3">{{ formatDate(rep.created_at) }}</span>
                        </div>
                        <p class="text-sm text-ink-2 whitespace-pre-wrap">{{ rep.message }}</p>
                    </div>
                </div>
            </div>

            <!-- Admin-Antwort -->
            <div class="bg-surface rounded-card p-4 space-y-3 shadow-card">
                <p class="text-xs font-semibold text-ink-3 uppercase tracking-wider">Admin-Antwort</p>
                <textarea v-model="reply" rows="4" placeholder="Antwort an den Nutzer…"
                    class="w-full rounded-field bg-surface px-3 py-2.5 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40 resize-none" />
                <button @click="sendReply" :disabled="sending || !reply.trim()"
                    class="px-5 py-2.5 rounded-field bg-accent hover:opacity-90 disabled:opacity-50 text-sm font-semibold text-white transition-colors">
                    {{ sending ? 'Wird gesendet…' : 'Antwort senden' }}
                </button>
            </div>

        </div>
    </AdminLayout>
</template>
