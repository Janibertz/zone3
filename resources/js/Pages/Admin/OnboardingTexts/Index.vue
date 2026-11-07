<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import CoachBubble from '@/Components/Onboarding/CoachBubble.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    texts:     Array,
    maxLength: Number,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({ texts: Object.fromEntries(props.texts.map((t) => [t.key, t.text])) });

const preview = { name: 'Max', avatar_initials: 'M', avatar_color: 'green' };

function save() {
    form.put(route('admin.onboarding-texts.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Onboarding-Texte" />

    <AdminLayout>
        <template #header>
            <PageHeader title="Onboarding-Texte" />
        </template>
        <div class="space-y-6 px-4 py-4 lg:px-8 lg:py-6">

            <div v-if="flash.success" class="rounded-field border border-success/25 bg-success-soft px-4 py-3 text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <p class="text-sm text-ink-3">
                Was der Coach im Onboarding über jeder Frage sagt. <code>{name}</code> wird zum Vornamen des Athleten,
                <code>{coach}</code> zum Namen des gewählten Coaches. Ein leeres Feld nimmt den Standardtext.
            </p>

            <form class="space-y-4" @submit.prevent="save">
                <div v-for="t in texts" :key="t.key" class="space-y-3 rounded-card bg-surface p-4 lg:p-6">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-ink-2">{{ t.label }}</h2>
                        <button v-if="form.texts[t.key] !== t.default" type="button"
                            class="text-xs text-accent active:opacity-60" @click="form.texts[t.key] = t.default">
                            Standard
                        </button>
                    </div>
                    <textarea v-model="form.texts[t.key]" rows="3" :maxlength="maxLength" :placeholder="t.default"
                        class="w-full rounded-field border-line bg-canvas text-base text-ink placeholder:text-ink-3 sm:text-sm" />
                    <p v-if="form.errors[`texts.${t.key}`]" class="text-xs text-danger">{{ form.errors[`texts.${t.key}`] }}</p>
                    <div class="rounded-card bg-canvas p-3">
                        <CoachBubble :coach="preview" name="Jan" :text="form.texts[t.key] || t.default" />
                    </div>
                </div>

                <div class="sticky bottom-4 flex justify-end">
                    <button type="submit" :disabled="form.processing || !form.isDirty"
                        class="rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white shadow-card transition active:opacity-80 disabled:opacity-50">
                        Speichern
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
