<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({ tickets: Array });

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const showForm = ref(props.tickets.length === 0);
const form = ref({ subject: '', description: '', type: 'bug' });
const submitting = ref(false);

const typeOptions = [
    { value: 'bug',         label: 'Fehler' },
    { value: 'improvement', label: 'Idee' },
    { value: 'question',    label: 'Frage' },
    { value: 'other',       label: 'Sonstiges' },
];

const statusConfig = {
    open:        { label: 'Offen',          class: 'bg-info-soft text-info-ink' },
    in_progress: { label: 'In Bearbeitung', class: 'bg-warn-soft text-warn-ink' },
    resolved:    { label: 'Gelöst',         class: 'bg-success-soft text-success-ink' },
    closed:      { label: 'Geschlossen',    class: 'bg-surface-2 text-ink-3' },
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
    <Head title="Hilfe & Feedback" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" title="Hilfe & Feedback">
                <template v-if="!showForm" #action>
                    <AppButton variant="plain" @click="showForm = true">Neu</AppButton>
                </template>
            </PageHeader>

            <div class="mx-auto max-w-3xl space-y-7 px-4 pb-10 pt-3 lg:px-8">
                <p v-if="flash.success" class="rounded-card bg-success-soft px-4 py-3 text-[15px] text-success-ink">{{ flash.success }}</p>

                <!-- Neue Nachricht -->
                <section v-if="showForm" class="space-y-3">
                    <FormGroup title="Worum geht es?" plain>
                        <SegmentedControl v-model="form.type" :options="typeOptions" />
                    </FormGroup>
                    <FormGroup footer="Die Antwort findest du später hier.">
                        <input v-model="form.subject" type="text" placeholder="Betreff"
                            class="block w-full border-0 bg-transparent px-4 py-3 text-[17px] text-ink placeholder-ink-3 focus:outline-none focus:ring-0" />
                        <textarea v-model="form.description" rows="5" placeholder="Beschreib das Problem oder deine Idee so genau wie möglich …"
                            class="block w-full resize-none border-0 bg-transparent px-4 py-3 text-[17px] text-ink placeholder-ink-3 focus:outline-none focus:ring-0" />
                    </FormGroup>
                    <div class="flex gap-3">
                        <AppButton v-if="tickets.length > 0" variant="secondary" block @click="showForm = false">Abbrechen</AppButton>
                        <AppButton block :loading="submitting" :disabled="!form.subject || !form.description" @click="submit">Senden</AppButton>
                    </div>
                </section>

                <!-- Bisherige Nachrichten -->
                <p v-if="tickets.length === 0 && !showForm" class="py-12 text-center text-[15px] text-ink-3">
                    Noch keine Nachrichten. Hast du ein Problem oder eine Idee?
                </p>
                <FormGroup v-else-if="tickets.length > 0" title="Deine Nachrichten">
                    <Link v-for="ticket in tickets" :key="ticket.id" :href="route('support.tickets.show', ticket.id)"
                        class="flex min-h-[56px] items-center gap-3 px-4 py-2.5 transition-colors active:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[17px] text-ink">{{ ticket.subject }}</span>
                            <span class="block text-[13px] text-ink-3">#{{ ticket.id }} · {{ ticket.replies_count }} Antwort{{ ticket.replies_count !== 1 ? 'en' : '' }}</span>
                        </span>
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[13px] font-semibold" :class="statusConfig[ticket.status]?.class">
                            {{ statusConfig[ticket.status]?.label }}
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </Link>
                </FormGroup>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
