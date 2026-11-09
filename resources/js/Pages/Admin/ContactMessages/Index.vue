<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ messages: Array });

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

function when(value) {
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function toggle(m) {
    router.patch(route('admin.contact.handle', m.id), {}, { preserveScroll: true });
}

const toDelete = ref(null);
const busy     = ref(false);
function confirmDelete() {
    if (!toDelete.value) return;
    busy.value = true;
    router.delete(route('admin.contact.destroy', toDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toDelete.value = null; },
    });
}

function replyHref(m) {
    return `mailto:${m.email}?subject=${encodeURIComponent('Re: deine Nachricht an Zone3')}`;
}
</script>

<template>
    <Head title="Kontakt" />

    <AdminLayout>
        <template #header>
            <PageHeader title="Kontakt" />
        </template>
        <div class="space-y-4 px-4 py-4 lg:px-8 lg:py-6">
            <div v-if="flash.success" class="rounded-field border border-success/25 bg-success-soft px-4 py-3 text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <p class="text-sm text-ink-3">
                Nachrichten aus dem Kontaktformular im Impressum. Laut Datenschutzerklärung werden sie gelöscht, sobald sie erledigt sind.
            </p>

            <p v-if="!messages.length" class="rounded-card bg-surface px-6 py-8 text-center text-sm text-ink-3">Noch keine Nachrichten.</p>

            <div v-for="m in messages" :key="m.id" class="rounded-card bg-surface px-5 py-4 shadow-card" :class="m.handled_at ? 'opacity-60' : ''">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="min-w-0">
                        <span class="font-semibold text-ink">{{ m.name || 'Ohne Namen' }}</span>
                        <a :href="replyHref(m)" class="ml-2 select-text text-sm text-accent">{{ m.email }}</a>
                    </div>
                    <span class="text-xs text-ink-3">{{ when(m.created_at) }}</span>
                </div>
                <p class="mt-2 whitespace-pre-line select-text text-[15px] text-ink-2">{{ m.message }}</p>
                <div class="mt-3 flex gap-4 text-sm">
                    <a :href="replyHref(m)" class="font-semibold text-accent active:opacity-60">Antworten</a>
                    <button type="button" class="font-semibold text-accent active:opacity-60" @click="toggle(m)">
                        {{ m.handled_at ? 'Wieder öffnen' : 'Erledigt' }}
                    </button>
                    <button type="button" class="font-semibold text-danger active:opacity-60" @click="toDelete = m">Löschen</button>
                </div>
            </div>
        </div>

        <ConfirmSheet
            :show="!!toDelete"
            title="Nachricht löschen?"
            :message="toDelete ? `Die Nachricht von ${toDelete.email} wird endgültig gelöscht.` : ''"
            confirm-label="Löschen"
            :loading="busy"
            @confirm="confirmDelete"
            @close="toDelete = null" />
    </AdminLayout>
</template>
