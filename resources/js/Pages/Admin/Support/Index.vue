<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    tickets: Object,
    counts:  Object,
    status:  String,
});

const tabs = [
    { key: 'open',        label: 'Offen' },
    { key: 'in_progress', label: 'In Bearbeitung' },
    { key: 'resolved',    label: 'Gelöst' },
    { key: 'closed',      label: 'Geschlossen' },
    { key: 'all',         label: 'Alle' },
];

const statusConfig = {
    open:        { label: 'Offen',          class: 'bg-info-soft text-info-ink' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-warn-soft text-warn-ink' },
    resolved:    { label: 'Gelöst',         class: 'bg-success-soft text-success-ink' },
    closed:      { label: 'Geschlossen',    class: 'bg-surface-2 text-ink-3' },
};

const typeConfig = {
    bug:         { label: '🐛 Bug',          },
    improvement: { label: '💡 Verbesserung', },
    question:    { label: '❓ Frage',        },
    other:       { label: '📝 Sonstiges',    },
};

function setTab(key) {
    router.get(route('admin.support.index'), { status: key }, { preserveState: true, replace: true });
}

function formatDate(dt) {
    return new Date(dt).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Admin – Support" />
    <AdminLayout>
        <template #header>
            <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Support-Tickets</h1>
        </template>

        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-4">

            <!-- Tabs -->
            <div class="flex gap-1 bg-surface-2 rounded-field p-1">
                <button v-for="tab in tabs" :key="tab.key"
                    @click="setTab(tab.key)"
                    class="flex-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                    :class="status === tab.key
                        ? 'bg-surface text-ink shadow-card'
                        : 'text-ink-3 hover:text-ink-2'">
                    {{ tab.label }}
                    <span class="ml-1 text-ink-3">({{ counts[tab.key] ?? 0 }})</span>
                </button>
            </div>

            <!-- Ticket-Liste -->
            <div class="bg-surface rounded-card overflow-hidden shadow-card">
                <div v-if="tickets.data.length === 0" class="px-6 py-12 text-center text-sm text-ink-3">
                    Keine Tickets in diesem Status.
                </div>
                <div v-else class="divide-y divide-line">
                    <Link v-for="ticket in tickets.data" :key="ticket.id"
                        :href="route('admin.support.show', ticket.id)"
                        class="flex items-center gap-4 px-5 py-4 hover:bg-surface-2/50 transition-colors">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="text-xs text-ink-3">#{{ ticket.id }}</span>
                                <span class="text-xs text-ink-3">{{ typeConfig[ticket.type]?.label }}</span>
                            </div>
                            <p class="text-sm font-semibold text-ink truncate">{{ ticket.subject }}</p>
                            <p class="text-xs text-ink-3 mt-0.5">
                                {{ ticket.user?.name }} · {{ ticket.replies_count }} Antwort{{ ticket.replies_count !== 1 ? 'en' : '' }} · {{ formatDate(ticket.updated_at) }}
                            </p>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium shrink-0"
                            :class="statusConfig[ticket.status]?.class">
                            {{ statusConfig[ticket.status]?.label }}
                        </span>
                    </Link>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="tickets.last_page > 1" class="flex justify-center gap-2">
                <Link v-for="link in tickets.links" :key="link.label"
                    :href="link.url ?? '#'"
                    v-html="link.label"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    :class="link.active
                        ? 'bg-accent text-white'
                        : link.url ? 'bg-surface text-ink-2 hover:bg-surface-2' : 'opacity-40 cursor-default bg-surface text-ink-3'" />
            </div>

        </div>
    </AdminLayout>
</template>
