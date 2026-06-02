<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ tickets: Array });

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const showForm = ref(props.tickets.length === 0);
const form = ref({ subject: '', description: '', type: 'bug' });
const submitting = ref(false);

const typeOptions = [
    { value: 'bug',         label: '🐛 Bug / Fehler' },
    { value: 'improvement', label: '💡 Verbesserungsvorschlag' },
    { value: 'question',    label: '❓ Frage' },
    { value: 'other',       label: '📝 Sonstiges' },
];

const statusConfig = {
    open:        { label: 'Offen',          class: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300' },
    resolved:    { label: 'Gelöst',         class: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300' },
    closed:      { label: 'Geschlossen',    class: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400' },
};

function submit() {
    submitting.value = true;
    router.post(route('support.tickets.store'), form.value, {
        preserveScroll: true,
        onSuccess: () => { form.value = { subject: '', description: '', type: 'bug' }; showForm.value = false; },
        onFinish: () => { submitting.value = false; },
    });
}
</script>

<template>
    <Head title="Support & Feedback" />
    <AuthenticatedLayout>
        <div class="max-w-2xl mx-auto px-4 py-6 space-y-5">

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">Support & Feedback</h1>
                <button v-if="!showForm" @click="showForm = true"
                    class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-sm font-semibold text-white transition-colors">
                    + Neues Ticket
                </button>
            </div>

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl text-sm text-green-700 dark:text-green-300">
                {{ flash.success }}
            </div>

            <!-- Neues Ticket Formular -->
            <div v-if="showForm" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Neues Ticket erstellen</h2>
                    <button v-if="tickets.length > 0" @click="showForm = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button v-for="opt in typeOptions" :key="opt.value"
                        @click="form.type = opt.value"
                        class="px-3 py-2.5 rounded-xl border text-sm font-medium transition-colors text-left"
                        :class="form.type === opt.value
                            ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300'
                            : 'border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 hover:border-gray-300 dark:hover:border-slate-600'">
                        {{ opt.label }}
                    </button>
                </div>

                <input v-model="form.subject" type="text" placeholder="Kurze Zusammenfassung…"
                    class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500" />

                <textarea v-model="form.description" rows="4" placeholder="Beschreibe das Problem oder deinen Vorschlag so genau wie möglich…"
                    class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none" />

                <button @click="submit" :disabled="submitting || !form.subject || !form.description"
                    class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-sm font-semibold text-white transition-colors">
                    {{ submitting ? 'Wird gesendet…' : 'Ticket senden' }}
                </button>
            </div>

            <!-- Ticket Liste -->
            <div v-if="tickets.length === 0 && !showForm" class="text-center py-12 text-gray-400 dark:text-slate-500">
                <svg class="h-10 w-10 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" /></svg>
                <p class="text-sm">Noch keine Tickets. Hast du ein Problem oder einen Vorschlag?</p>
            </div>

            <div v-else-if="tickets.length > 0" class="space-y-2">
                <a v-for="ticket in tickets" :key="ticket.id" :href="route('support.tickets.show', ticket.id)"
                    class="block bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl px-4 py-3.5 hover:border-indigo-200 dark:hover:border-indigo-500/30 transition-colors">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">#{{ ticket.id }} {{ ticket.subject }}</p>
                            <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ ticket.replies_count }} Antwort{{ ticket.replies_count !== 1 ? 'en' : '' }}</p>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium shrink-0"
                            :class="statusConfig[ticket.status]?.class">
                            {{ statusConfig[ticket.status]?.label }}
                        </span>
                    </div>
                </a>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
