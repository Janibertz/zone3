<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import Dot from '@/Components/Admin/Dot.vue';
import { hm, when } from '@/Composables/useAdminFormat';

/** Ein Ticket als Gespräch (Mockup „Ticket“, 18.11.2026). */
const props = defineProps({
    ticket:  { type: Object, required: true },
    context: { type: Object, default: null },
});

const reply = ref('');
const sending = ref(false);

const open = computed(() => ['open', 'in_progress'].includes(props.ticket.status));
const STATUS = { open: 'Offen', in_progress: 'In Bearbeitung', resolved: 'Gelöst', closed: 'Geschlossen' };
const TYPE = { bug: 'Fehler melden', improvement: 'Verbesserung', question: 'Frage' };

const first = computed(() => props.context?.first ?? 'Athlet');

const messages = computed(() => [
    { id: 'start', mine: false, text: props.ticket.description, at: props.ticket.created_at, who: first.value },
    ...props.ticket.replies.map((r) => ({ id: r.id, mine: r.is_admin, text: r.message, at: r.created_at, who: r.is_admin ? 'Du' : first.value })),
]);

const quick = ['Danke, ist behoben', 'Schau ich mir an', 'Kannst du mir einen Screenshot schicken?'];

function send(resolve = false) {
    if (!reply.value.trim()) return;
    sending.value = true;
    router.post(route('admin.support.reply', props.ticket.id), { message: reply.value, resolve }, {
        preserveScroll: true,
        onSuccess: () => { reply.value = ''; },
        onFinish: () => { sending.value = false; },
    });
}

function setStatus(status) {
    router.patch(route('admin.support.status', props.ticket.id), { status }, { preserveScroll: true });
}

const impersonate = () => router.post(route('admin.users.impersonate', props.ticket.user_id));
</script>

<template>
    <Head :title="`Admin – ${ticket.subject}`" />

    <AdminLayout>
        <AdminPage :title="ticket.subject" detail :back="route('admin.inbox.index')" back-label="Postfach">
            <template #nav>
                <button v-if="open" type="button" class="h-11 px-2.5 text-[17px] font-semibold text-accent" @click="setStatus('resolved')">Erledigt</button>
                <button v-else type="button" class="h-11 px-2.5 text-[17px] text-accent" @click="setStatus('open')">Wieder öffnen</button>
            </template>

            <div class="-mt-3 flex flex-col gap-1">
                <span class="self-start rounded-md bg-accent-soft px-[7px] py-[3px] text-[12px] font-bold uppercase text-accent-ink">Hilfe &amp; Feedback · {{ STATUS[ticket.status] }}</span>
                <div class="text-[15px] text-ink-3">{{ context?.short ?? 'Gelöschtes Konto' }} · {{ TYPE[ticket.type] ?? 'Sonstiges' }} · {{ when(ticket.created_at) }}</div>
            </div>

            <div v-if="context" class="overflow-hidden rounded-[14px] bg-surface">
                <div v-for="c in context.connections" :key="c.name" class="flex items-center gap-2.5 border-b-[0.5px] border-line px-3.5 py-[11px]">
                    <Dot :tone="c.tone" />
                    <span class="flex-1 text-[15px]">{{ c.name }}</span>
                    <span class="text-right text-[15px]" :class="c.tone === 'warn' ? 'text-warn-ink' : 'text-ink-3'">{{ c.value }}</span>
                </div>
                <div class="flex justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-[11px]">
                    <span class="text-[15px]">Gerät</span><span class="text-[15px] text-ink-3">{{ context.way }}</span>
                </div>
                <div class="flex justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-[11px]">
                    <span class="text-[15px]">Antwort kommt</span><span class="text-right text-[15px] text-ink-3">per Mail und {{ context.reaches }}</span>
                </div>
                <div class="flex gap-2 px-3.5 py-2.5">
                    <Link :href="route('admin.users.show', ticket.user_id)" class="flex h-9 flex-1 items-center justify-center rounded-[10px] bg-surface-2 text-[15px] font-semibold">Athlet öffnen</Link>
                    <button type="button" class="h-9 flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold" @click="impersonate">Als {{ first }} ansehen</button>
                </div>
            </div>

            <div class="flex flex-col gap-1.5 pt-1">
                <template v-for="m in messages" :key="m.id">
                    <div class="mt-2 max-w-[78%] whitespace-pre-wrap px-[13px] py-2.5 text-[16px] leading-[22px]"
                        :class="m.mine ? 'self-end rounded-[18px_18px_4px_18px] bg-accent text-white' : 'self-start rounded-[18px_18px_18px_4px] bg-surface'">{{ m.text }}</div>
                    <div class="text-[12px] text-ink-3" :class="m.mine ? 'self-end pr-1.5' : 'pl-1.5'">{{ m.who }} · {{ hm(m.at) }}</div>
                </template>
            </div>

            <!-- Antwortfeld -->
            <div class="sticky bottom-[calc(env(safe-area-inset-bottom)+60px)] -mx-4 flex flex-col gap-2 border-t-[0.5px] border-line-strong bg-surface/90 px-3 py-2.5 backdrop-blur-xl lg:bottom-4 lg:mx-0 lg:rounded-[14px] lg:border-0">
                <div class="no-scrollbar flex gap-1.5 overflow-x-auto">
                    <button v-for="q in quick" :key="q" type="button" class="h-[30px] shrink-0 rounded-[15px] bg-surface-2 px-2.5 text-[13px] font-medium" @click="reply = q">{{ q }}</button>
                </div>
                <form class="flex items-end gap-2" @submit.prevent="send(false)">
                    <textarea v-model="reply" rows="1" :aria-label="`Antwort an ${first}`" :placeholder="`Antwort an ${first}`"
                        class="min-h-[38px] flex-1 resize-none rounded-[19px] border border-line-strong bg-transparent px-3.5 py-2 text-[16px] focus:border-accent focus:ring-0"
                        @keydown.enter.meta.prevent="send(false)" />
                    <button type="submit" aria-label="Senden" :disabled="sending || !reply.trim()" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent text-white disabled:opacity-40">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                    </button>
                </form>
                <button v-if="open && reply.trim()" type="button" class="self-start text-[13px] font-semibold text-accent" :disabled="sending" @click="send(true)">Senden und erledigt</button>
            </div>
        </AdminPage>
    </AdminLayout>
</template>
