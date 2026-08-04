<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';

const props = defineProps({
    page:       Object,
    categories: Object,
});

const editing    = ref(false);
const saving     = ref(false);
const confirmDel = ref(false);
const editTitle   = ref(props.page.title);
const editContent = ref(props.page.content ?? '');
const editCat     = ref(props.page.category);

function save() {
    saving.value = true;
    router.put(route('admin.wiki.update', props.page.slug), {
        title:    editTitle.value,
        category: editCat.value,
        content:  editContent.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
        onFinish:  () => { saving.value = false; },
    });
}

function deletePage() {
    router.delete(route('admin.wiki.destroy', props.page.slug));
}

// Minimal Markdown → HTML renderer (no npm dependency)
function renderMarkdown(src) {
    if (!src) return '<p class="text-ink-3 italic">Noch kein Inhalt.</p>';

    // Split into blocks to handle fenced code blocks first (prevents inline-code
    // regex from eating triple-backtick content across newlines)
    const PRE = [];
    let html = src.replace(/```([\w]*)\n([\s\S]*?)```/g, (_, _lang, code) => {
        const escaped = code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        PRE.push(`<pre class="my-4 p-4 rounded-field bg-ink text-canvas text-sm font-mono overflow-x-auto"><code>${escaped}</code></pre>`);
        return `\x00PRE${PRE.length - 1}\x00`;
    });

    html = html
        // Headings
        .replace(/^### (.+)$/gm, '<h3 class="text-base font-semibold text-ink mt-6 mb-2">$1</h3>')
        .replace(/^## (.+)$/gm,  '<h2 class="text-lg font-bold text-ink mt-8 mb-3 border-b border-line pb-2">$1</h2>')
        .replace(/^# (.+)$/gm,   '<h1 class="text-xl font-bold text-ink mt-8 mb-4">$1</h1>')
        // Bold + italic
        .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
        .replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-ink">$1</strong>')
        .replace(/\*(.+?)\*/g, '<em class="italic">$1</em>')
        // Inline code (single-line only — [^`\n] prevents crossing newlines)
        .replace(/`([^`\n]+)`/g, '<code class="px-1.5 py-0.5 rounded bg-surface-2 text-sm font-mono text-accent-ink">$1</code>')
        // Tables
        .replace(/^\|(.+)\|\n\|[-| :]+\|\n((?:\|.+\|\n?)+)/gm, (_, header, body) => {
            const th = header.split('|').map(c => c.trim()).filter(Boolean)
                .map(c => `<th class="px-3 py-2 text-left text-xs font-semibold text-ink-3 uppercase">${c}</th>`).join('');
            const rows = body.trim().split('\n').map(row => {
                const tds = row.split('|').map(c => c.trim()).filter(Boolean)
                    .map(c => `<td class="px-3 py-2 text-sm text-ink-2 border-t border-line">${c}</td>`).join('');
                return `<tr>${tds}</tr>`;
            }).join('');
            return `<table class="w-full my-4 border-collapse"><thead><tr>${th}</tr></thead><tbody>${rows}</tbody></table>`;
        })
        // Lists
        .replace(/^- (.+)$/gm, '<li class="flex gap-2 text-ink-2"><span class="text-accent mt-1">•</span><span>$1</span></li>')
        .replace(/^(\d+)\. (.+)$/gm, '<li class="flex gap-2 text-ink-2"><span class="text-accent font-mono text-sm min-w-[1.2rem]">$1.</span><span>$2</span></li>')
        // Links
        .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" class="text-accent-ink hover:underline" target="_blank">$1</a>')
        // Horizontal rule
        .replace(/^---$/gm, '<hr class="my-6 border-line">')
        // Paragraphs (skip lines that are already HTML tags or placeholders)
        .replace(/^(?!<|[\x00])(.+)$/gm, '<p class="text-ink-2 leading-relaxed">$1</p>')
        // Wrap consecutive <li> in <ul>
        .replace(/(<li[\s\S]*?<\/li>\n?)+/g, '<ul class="my-3 space-y-1 pl-2">$&</ul>')
        // Blank lines
        .replace(/^\s*$/gm, '');

    // Restore code blocks
    html = html.replace(/\x00PRE(\d+)\x00/g, (_, i) => PRE[i]);
    return html;
}

const rendered = computed(() => renderMarkdown(props.page.content));

const categoryColors = {
    architecture: 'bg-info-soft text-info-ink',
    features:     'bg-accent-soft text-accent-ink',
    api:          'bg-warn-soft text-warn-ink',
    decisions:    'bg-success-soft text-success-ink',
};
</script>

<template>
    <AdminLayout>
        <Head :title="`Wiki – ${page.title}`" />

        <div class="px-4 lg:px-6 py-8">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-ink-3 mb-6">
                <Link :href="route('admin.wiki.index')" class="hover:text-accent transition-colors">Wiki</Link>
                <span>/</span>
                <span class="text-ink-2 font-medium">{{ page.title }}</span>
            </div>

            <!-- Page header -->
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-2xl font-bold text-ink">{{ page.title }}</h1>
                    <span :class="['text-xs font-semibold px-2.5 py-1 rounded-full', categoryColors[page.category]]">
                        {{ categories[page.category]?.label }}
                    </span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button v-if="!editing" @click="editing = true"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-field bg-surface text-sm text-ink-2 hover:bg-surface-2 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                        Bearbeiten
                    </button>
                    <button v-if="!editing" @click="confirmDel = true"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-field border border-danger/25 text-danger-ink text-sm hover:bg-danger-soft transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        Löschen
                    </button>
                </div>
            </div>

            <!-- Seite löschen -->
            <ConfirmSheet
                :show="confirmDel"
                title="Seite löschen?"
                :message="`„${page.title}“ wird dauerhaft gelöscht.`"
                confirm-label="Löschen"
                @confirm="deletePage"
                @close="confirmDel = false"
            />

            <!-- Edit mode -->
            <div v-if="editing" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-ink-3 mb-1">Titel</label>
                        <input v-model="editTitle" type="text"
                            class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-3 mb-1">Kategorie</label>
                        <select v-model="editCat"
                            class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                            <option v-for="(meta, key) in categories" :key="key" :value="key">{{ meta.label }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink-3 mb-1">Inhalt (Markdown)</label>
                    <textarea v-model="editContent" rows="24"
                        class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink font-mono focus:outline-none focus:ring-2 focus:ring-accent/40 resize-y"
                        placeholder="# Überschrift&#10;&#10;Inhalt hier..."></textarea>
                </div>
                <div class="flex gap-3 justify-end">
                    <button @click="editing = false; editTitle = page.title; editContent = page.content ?? ''; editCat = page.category"
                        class="px-4 py-2 rounded-field text-sm text-ink-2 hover:bg-surface-2 transition-colors">Abbrechen</button>
                    <button @click="save" :disabled="saving"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-field bg-accent hover:opacity-90 disabled:opacity-50 text-white text-sm font-semibold transition-colors">
                        <svg v-if="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        {{ saving ? 'Speichert...' : 'Speichern' }}
                    </button>
                </div>
            </div>

            <!-- View mode -->
            <div v-else class="prose-zone3 bg-surface rounded-card border border-line p-8">
                <div v-html="rendered"></div>

                <!-- Meta footer -->
                <div class="mt-10 pt-4 border-t border-line flex items-center gap-2 text-xs text-ink-3">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Zuletzt bearbeitet: {{ new Date(page.updated_at).toLocaleString('de-DE') }}
                    <span v-if="page.editor"> · von {{ page.editor.name }}</span>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
