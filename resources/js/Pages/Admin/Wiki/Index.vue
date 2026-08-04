<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    grouped:    Array,
    categories: Object,
});

const showNewPage  = ref(false);
const newTitle     = ref('');
const newCategory  = ref('architecture');
const creating     = ref(false);

function createPage() {
    if (!newTitle.value.trim()) return;
    creating.value = true;
    router.post(route('admin.wiki.store'), {
        title:    newTitle.value.trim(),
        category: newCategory.value,
        content:  '',
    }, {
        onFinish: () => { creating.value = false; showNewPage.value = false; newTitle.value = ''; },
    });
}

const categoryIcons = {
    architecture: `<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 6 0m-6 0v-1.5m7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V4.875c0-.621-.504-1.125-1.125-1.125H6.375m6.75 0V3m0 1.875h4.5m-4.5 7.5h4.5" />`,
    features:     `<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />`,
    api:          `<path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />`,
    decisions:    `<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />`,
};

const categoryColors = {
    architecture: 'bg-info-soft border-info/25 text-info-ink',
    features:     'bg-accent-soft border-accent/25 text-accent-ink',
    api:          'bg-warn-soft border-warn/25 text-warn-ink',
    decisions:    'bg-success-soft border-success/25 text-success-ink',
};
</script>

<template>
    <AdminLayout>
        <Head title="Admin – Wiki" />

        <div class="px-4 lg:px-6 py-8">

            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-ink">Projekt-Wiki</h1>
                    <p class="mt-1 text-sm text-ink-3">Dokumentation, Architektur-Entscheidungen und API-Referenz</p>
                </div>
                <div class="flex gap-3">
                    <Link :href="route('admin.wiki.changelog')"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-field bg-surface text-sm font-medium text-ink-2 hover:bg-surface-2 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Changelog
                    </Link>
                    <button @click="showNewPage = true"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-field bg-accent hover:opacity-90 text-white text-sm font-semibold transition-colors shadow-card">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Neue Seite
                    </button>
                </div>
            </div>

            <!-- Neue Seite -->
            <AppSheet :show="showNewPage" title="Neue Wiki-Seite" @close="showNewPage = false">
                <div class="space-y-4 pt-1">
                    <div>
                        <label class="z-label">Titel</label>
                        <input v-model="newTitle" type="text" placeholder="z.B. Garmin Connect Integration" class="z-input" />
                    </div>
                    <div>
                        <label class="z-label">Kategorie</label>
                        <select v-model="newCategory" class="z-input">
                            <option v-for="(meta, key) in categories" :key="key" :value="key">{{ meta.label }}</option>
                        </select>
                    </div>
                </div>

                <template #footer>
                    <div class="flex gap-3">
                        <AppButton variant="secondary" block @click="showNewPage = false">Abbrechen</AppButton>
                        <AppButton block :disabled="!newTitle.trim()" :loading="creating" @click="createPage">
                            Erstellen
                        </AppButton>
                    </div>
                </template>
            </AppSheet>

            <!-- Category groups -->
            <div class="space-y-8">
                <div v-for="cat in grouped" :key="cat.key">
                    <!-- Category header -->
                    <div class="flex items-center gap-3 mb-4">
                        <div :class="['flex items-center justify-center h-8 w-8 rounded-lg border', categoryColors[cat.key]]">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="categoryIcons[cat.key]"></svg>
                        </div>
                        <h2 class="text-base font-semibold text-ink">{{ cat.label }}</h2>
                        <span class="text-xs text-ink-3">{{ cat.pages.length }} {{ cat.pages.length === 1 ? 'Seite' : 'Seiten' }}</span>
                    </div>

                    <!-- Pages grid -->
                    <div v-if="cat.pages.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <Link v-for="page in cat.pages" :key="page.id"
                            :href="route('admin.wiki.page', page.slug)"
                            class="group flex flex-col gap-1 p-4 rounded-field bg-surface hover:border-accent/25 hover:shadow-card transition-all">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold text-ink group-hover:text-accent transition-colors leading-snug">
                                    {{ page.title }}
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-ink-3 group-hover:text-accent transition-colors mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </div>
                            <span class="text-xs text-ink-3">
                                {{ page.content ? page.content.slice(0, 80).replace(/#+\s/g, '').replace(/\*\*/g, '').trim() + (page.content.length > 80 ? '…' : '') : 'Noch kein Inhalt' }}
                            </span>
                        </Link>
                    </div>
                    <p v-else class="text-sm text-ink-3 italic pl-11">
                        Noch keine Seiten in dieser Kategorie.
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
