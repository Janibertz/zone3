<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSegmented from '@/Components/Admin/AdminSegmented.vue';
import AdminChips from '@/Components/Admin/AdminChips.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { ago, firstName } from '@/Composables/useAdminFormat';

/**
 * Postfach (18.11.2026): Warteliste, Hilfe & Feedback, Kontaktformular und
 * gemeldete Workouts in einer Liste. Jede Karte hat ihren eigenen Weg.
 */
const props = defineProps({
    items:  { type: Array, default: () => [] },
    tab:    { type: String, default: 'open' },
    counts: { type: Object, required: true },
});

const kind = ref('all');

const chips = computed(() => [
    { value: 'all', label: 'Alle' },
    { value: 'waitlist', label: 'Warteliste', count: props.counts.waitlist },
    { value: 'ticket', label: 'Hilfe', count: props.counts.ticket },
    { value: 'contact', label: 'Kontakt', count: props.counts.contact },
    { value: 'report', label: 'Meldung', count: props.counts.report },
]);

const tabs = computed(() => [
    { value: 'open', label: `Offen ${props.counts.open}`, href: route('admin.inbox.index') },
    { value: 'done', label: 'Erledigt', href: route('admin.inbox.index', { tab: 'done' }) },
]);

const shown = computed(() => props.items.filter((i) => kind.value === 'all' || i.kind === kind.value));

const LABEL = {
    ticket:   { text: 'Hilfe & Feedback', cls: 'bg-accent-soft text-accent-ink' },
    waitlist: { text: 'Warteliste', cls: 'bg-surface-2 text-ink-2' },
    contact:  { text: 'Kontaktformular', cls: 'bg-success-soft text-success-ink' },
    report:   { text: 'Workout gemeldet', cls: 'bg-warn-soft text-warn-ink' },
};

const opts = { preserveScroll: true };
const approve = (i) => router.post(route('admin.waitlist.approve', i.user_id), {}, opts);
const impersonate = (i) => router.post(route('admin.users.impersonate', i.user_id));
const handle = (i) => router.patch(route('admin.contact.handle', i.id), {}, opts);
const keep = (i) => router.post(route('admin.inbox.keep-workout', i.id), {}, opts);
const unpublish = (i) => router.post(route('admin.workouts.unpublish', i.id), { reason: 'Nach einer Meldung von der Administration zurückgezogen.' }, opts);

const confirming = ref(null);
function confirmDo() {
    const c = confirming.value;
    if (!c) return;
    const done = { ...opts, onFinish: () => (confirming.value = null) };
    if (c.what === 'reject') router.delete(route('admin.waitlist.reject', c.item.user_id), done);
    if (c.what === 'delete') router.delete(route('admin.contact.destroy', c.item.id), done);
    if (c.what === 'unpublish') { unpublish(c.item); confirming.value = null; }
}
</script>

<template>
    <Head title="Admin – Postfach" />

    <AdminLayout>
        <AdminPage title="Postfach" eyebrow="Alles, was eine Antwort braucht">
            <AdminSegmented :model-value="tab" :options="tabs" />
            <AdminChips v-if="tab === 'open'" v-model="kind" :options="chips" />

            <p v-if="!shown.length" class="rounded-[14px] bg-surface px-3.5 py-4 text-[15px] text-ink-3">
                {{ tab === 'open' ? 'Nichts offen.' : 'Noch nichts erledigt.' }}
            </p>

            <article v-for="i in shown" :key="i.key" class="flex flex-col gap-2.5 rounded-[14px] bg-surface p-3.5">
                <div class="flex items-center justify-between gap-2">
                    <span class="rounded-md px-[7px] py-[3px] text-[12px] font-bold uppercase" :class="LABEL[i.kind].cls">{{ LABEL[i.kind].text }}</span>
                    <span class="text-[13px] text-ink-3">{{ ago(i.at) }}</span>
                </div>
                <div class="text-[17px] font-bold">{{ i.title }}</div>

                <p v-if="i.kind === 'ticket' || i.kind === 'contact'" class="m-0 whitespace-pre-line text-[15px] leading-[21px] text-ink-2">{{ i.text }}</p>
                <div v-else class="text-[15px] text-ink-2">{{ i.text }}</div>

                <div v-if="i.kind === 'contact'" class="text-[13px] text-ink-3">{{ i.email }}</div>
                <div v-if="i.context" class="rounded-lg bg-canvas px-2.5 py-2 text-[13px] text-ink-3">{{ i.context }}</div>

                <!-- Aktionen -->
                <div class="flex gap-2">
                    <template v-if="i.kind === 'ticket'">
                        <Link :href="route('admin.support.show', i.id)" class="flex h-[38px] flex-1 items-center justify-center rounded-[10px] text-[15px] font-semibold" :class="tab === 'open' ? 'bg-accent text-white' : 'bg-surface-2'">
                            {{ tab === 'open' ? (i.answered ? 'Gespräch öffnen' : 'Antworten') : 'Öffnen' }}
                        </Link>
                        <button v-if="i.user_id && tab === 'open'" type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold" @click="impersonate(i)">Als {{ i.user_first }} ansehen</button>
                    </template>

                    <template v-else-if="i.kind === 'waitlist'">
                        <button type="button" class="h-[38px] flex-1 rounded-[10px] bg-accent text-[15px] font-semibold text-white" @click="approve(i)">Freigeben</button>
                        <button type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold text-danger" @click="confirming = { what: 'reject', item: i }">Ablehnen</button>
                    </template>

                    <template v-else-if="i.kind === 'contact'">
                        <a :href="`mailto:${i.email}?subject=${encodeURIComponent('Deine Nachricht an Zone3')}`" class="flex h-[38px] flex-1 items-center justify-center rounded-[10px] bg-accent text-[15px] font-semibold text-white">Per Mail antworten</a>
                        <button v-if="tab === 'open'" type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold" @click="handle(i)">Erledigt</button>
                        <button v-else type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold text-danger" @click="confirming = { what: 'delete', item: i }">Löschen</button>
                    </template>

                    <template v-else-if="i.kind === 'report'">
                        <template v-if="tab === 'open'">
                            <button type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold" @click="keep(i)">Freigabe lassen</button>
                            <button type="button" class="h-[38px] flex-1 rounded-[10px] bg-surface-2 text-[15px] font-semibold text-danger" @click="confirming = { what: 'unpublish', item: i }">Zurücknehmen</button>
                        </template>
                        <Link v-else :href="route('admin.workouts.index', { all: 1 })" class="flex h-[38px] flex-1 items-center justify-center rounded-[10px] bg-surface-2 text-[15px] font-semibold">
                            {{ i.public ? 'Freigabe gelassen' : 'Freigabe zurückgenommen' }} · Workouts
                        </Link>
                    </template>
                </div>
            </article>
        </AdminPage>

        <ConfirmSheet
            :show="!!confirming"
            :title="confirming?.what === 'reject' ? `${firstName(confirming.item.title)} ablehnen?` : confirming?.what === 'delete' ? 'Nachricht löschen?' : 'Freigabe zurücknehmen?'"
            :message="confirming?.what === 'reject' ? 'Das Konto wird gelöscht.' : confirming?.what === 'delete' ? 'So steht es in der Datenschutzerklärung: erledigte Nachrichten werden gelöscht.' : 'Das Workout verschwindet aus der Auswahl der anderen. Der Ersteller behält es und sieht den Grund.'"
            :confirm-label="confirming?.what === 'reject' ? 'Ablehnen' : confirming?.what === 'delete' ? 'Löschen' : 'Zurücknehmen'"
            @confirm="confirmDo"
            @close="confirming = null"
        />
    </AdminLayout>
</template>
