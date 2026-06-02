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
    open:        { label: 'Offen',          class: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300' },
    resolved:    { label: 'Gelöst',         class: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300' },
    closed:      { label: 'Geschlossen',    class: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400' },
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
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Support-Tickets</h1>
        </template>

        <div class="p-6 space-y-4 max-w-5xl">

            <!-- Tabs -->
            <div class="flex gap-1 bg-gray-100 dark:bg-slate-800 rounded-xl p-1">
                <button v-for="tab in tabs" :key="tab.key"
                    @click="setTab(tab.key)"
                    class="flex-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                    :class="status === tab.key
                        ? 'bg-white dark:bg-slate-700 text-gray-900 dark:text-white shadow-sm'
                        : 'text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300'">
                    {{ tab.label }}
                    <span class="ml-1 text-gray-400 dark:text-slate-500">({{ counts[tab.key] ?? 0 }})</span>
                </button>
            </div>

            <!-- Ticket-Liste -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div v-if="tickets.data.length === 0" class="px-6 py-12 text-center text-sm text-gray-400 dark:text-slate-500">
                    Keine Tickets in diesem Status.
                </div>
                <div v-else class="divide-y divide-gray-100 dark:divide-slate-800">
                    <Link v-for="ticket in tickets.data" :key="ticket.id"
                        :href="route('admin.support.show', ticket.id)"
                        class="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="text-xs text-gray-400 dark:text-slate-500">#{{ ticket.id }}</span>
                                <span class="text-xs text-gray-400 dark:text-slate-500">{{ typeConfig[ticket.type]?.label }}</span>
                            </div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ ticket.subject }}</p>
                            <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
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
                        ? 'bg-indigo-600 text-white'
                        : link.url ? 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-50' : 'opacity-40 cursor-default bg-white dark:bg-slate-800 text-gray-400'" />
            </div>

        </div>
    </AdminLayout>
</template>
