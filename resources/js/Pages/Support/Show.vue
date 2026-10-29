<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppButton from '@/Components/UI/AppButton.vue';

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
    <Head :title="`Nachricht #${ticket.id}`" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" :back="route('support.tickets.index')" :eyebrow="`#${ticket.id} · ${formatDate(ticket.created_at)}`" :title="ticket.subject">
                <template #action>
                    <span class="rounded-full px-2.5 py-0.5 text-[13px] font-semibold" :class="statusConfig[ticket.status]?.class">{{ statusConfig[ticket.status]?.label }}</span>
                </template>
            </PageHeader>

            <!-- Verlauf als Chat wie im Coach-Chat: eigene Nachrichten rechts in Akzentfarbe -->
            <div class="mx-auto max-w-3xl space-y-3 px-4 pb-10 pt-3 lg:px-8">
                <div class="flex flex-col items-end">
                    <div class="max-w-[85%] rounded-[20px] rounded-br-md bg-accent px-4 py-2.5 text-[17px] leading-snug text-white">
                        <p class="whitespace-pre-wrap">{{ ticket.description }}</p>
                    </div>
                    <p class="mr-2 mt-1 text-[13px] text-ink-3">{{ formatDate(ticket.created_at) }}</p>
                </div>

                <div v-for="rep in ticket.replies" :key="rep.id" class="flex flex-col" :class="rep.is_admin ? 'items-start' : 'items-end'">
                    <div class="max-w-[85%] rounded-[20px] px-4 py-2.5 text-[17px] leading-snug"
                        :class="rep.is_admin ? 'rounded-bl-md bg-surface text-ink' : 'rounded-br-md bg-accent text-white'">
                        <p class="whitespace-pre-wrap">{{ rep.message }}</p>
                    </div>
                    <p class="mx-2 mt-1 text-[13px] text-ink-3">{{ rep.is_admin ? 'Zone3 · ' : '' }}{{ formatDate(rep.created_at) }}</p>
                </div>

                <div v-if="!isClosed" class="space-y-3 pt-4">
                    <div class="overflow-hidden rounded-card bg-surface">
                        <textarea v-model="reply" rows="3" placeholder="Deine Antwort …"
                            class="block w-full resize-none border-0 bg-transparent px-4 py-3 text-[17px] text-ink placeholder-ink-3 focus:outline-none focus:ring-0" />
                    </div>
                    <AppButton block :loading="sending" :disabled="!reply.trim()" @click="sendReply">Antworten</AppButton>
                </div>
                <p v-else class="py-3 text-center text-[15px] text-ink-3">Diese Unterhaltung ist abgeschlossen.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
