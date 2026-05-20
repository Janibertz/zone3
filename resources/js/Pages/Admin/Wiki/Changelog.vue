<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    entries: Object, // paginated
});

const expanded = ref(new Set());
function toggle(id) {
    expanded.value.has(id) ? expanded.value.delete(id) : expanded.value.add(id);
    expanded.value = new Set(expanded.value);
}

function fmtDate(d) {
    return new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function shortSha(sha) {
    return sha ? sha.slice(0, 7) : '—';
}

function guessType(message) {
    const m = message.toLowerCase();
    if (m.startsWith('feat'))   return { label: 'Feature', color: 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300' };
    if (m.startsWith('fix'))    return { label: 'Fix',     color: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' };
    if (m.startsWith('refact')) return { label: 'Refactor',color: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' };
    if (m.startsWith('docs'))   return { label: 'Docs',    color: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300' };
    return { label: 'Update', color: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' };
}
</script>

<template>
    <AdminLayout>
        <Head title="Admin – Wiki Changelog" />

        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <div class="flex items-center gap-2 text-sm text-gray-400 dark:text-slate-500 mb-1">
                        <Link :href="route('admin.wiki.index')" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Wiki</Link>
                        <span>/</span>
                        <span>Changelog</span>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Changelog</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Automatisch nach jedem GitHub-Push mit KI-Zusammenfassung</p>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!entries.data.length" class="text-center py-20">
                <div class="h-12 w-12 mx-auto rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                    <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Noch kein Changelog</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Nach dem nächsten GitHub-Push erscheinen hier automatisch Einträge.</p>
            </div>

            <!-- Timeline -->
            <div v-else class="relative">
                <!-- Vertical line -->
                <div class="absolute left-[1.1rem] top-0 bottom-0 w-px bg-gray-100 dark:bg-slate-800"></div>

                <div class="space-y-6">
                    <div v-for="entry in entries.data" :key="entry.id" class="relative pl-10">
                        <!-- Dot -->
                        <div class="absolute left-0 top-1 h-9 w-9 rounded-full bg-indigo-600 flex items-center justify-center shadow-sm">
                            <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                            </svg>
                        </div>

                        <!-- Card -->
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 overflow-hidden">
                            <!-- Header -->
                            <div class="p-4 flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <!-- Commit types badges -->
                                    <div class="flex flex-wrap gap-1.5 mb-2">
                                        <template v-for="commit in entry.commits.slice(0, 4)" :key="commit.id">
                                            <span :class="['text-[10px] font-semibold px-2 py-0.5 rounded-full', guessType(commit.message).color]">
                                                {{ guessType(commit.message).label }}
                                            </span>
                                        </template>
                                        <span v-if="entry.commits.length > 4" class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">
                                            +{{ entry.commits.length - 4 }} weitere
                                        </span>
                                    </div>
                                    <!-- First commit message as title -->
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white leading-snug">
                                        {{ entry.commits[0]?.message?.split('\n')[0] ?? 'Push' }}
                                    </p>
                                    <div class="mt-1 flex items-center gap-3 text-xs text-gray-400 dark:text-slate-500">
                                        <span>{{ fmtDate(entry.pushed_at) }}</span>
                                        <span class="font-mono">{{ shortSha(entry.commit_sha) }}</span>
                                        <span v-if="entry.pusher_name">von {{ entry.pusher_name }}</span>
                                        <span>{{ entry.files_changed.length }} Dateien</span>
                                    </div>
                                </div>
                                <button @click="toggle(entry.id)"
                                    class="shrink-0 text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors p-1">
                                    <svg class="h-4 w-4 transition-transform" :class="expanded.has(entry.id) ? 'rotate-180' : ''"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                            </div>

                            <!-- AI Summary (always visible) -->
                            <div v-if="entry.ai_summary" class="px-4 pb-4">
                                <div class="rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800 p-3">
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <svg class="h-3.5 w-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                                        </svg>
                                        <span class="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">KI-Zusammenfassung</span>
                                    </div>
                                    <p class="text-sm text-indigo-800 dark:text-indigo-200 leading-relaxed">{{ entry.ai_summary }}</p>
                                </div>
                            </div>

                            <!-- Expanded details -->
                            <div v-if="expanded.has(entry.id)" class="border-t border-gray-100 dark:border-slate-800 p-4 space-y-4">
                                <!-- All commits -->
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2">Commits ({{ entry.commits.length }})</p>
                                    <div class="space-y-1.5">
                                        <div v-for="c in entry.commits" :key="c.id" class="flex items-start gap-2">
                                            <code class="text-[10px] font-mono text-gray-400 dark:text-slate-500 bg-gray-100 dark:bg-slate-800 px-1.5 py-0.5 rounded shrink-0 mt-0.5">{{ c.id }}</code>
                                            <span class="text-sm text-gray-700 dark:text-slate-300 leading-snug">{{ c.message }}</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Changed files -->
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2">Geänderte Dateien</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <code v-for="f in entry.files_changed" :key="f"
                                            class="text-[10px] font-mono text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-800 px-2 py-0.5 rounded">{{ f }}</code>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="entries.last_page > 1" class="mt-8 flex justify-center gap-2">
                    <Link v-if="entries.prev_page_url" :href="entries.prev_page_url"
                        class="px-4 py-2 rounded-xl border border-gray-200 dark:border-slate-700 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                        ← Neuere
                    </Link>
                    <span class="px-4 py-2 text-sm text-gray-500 dark:text-slate-400">
                        Seite {{ entries.current_page }} / {{ entries.last_page }}
                    </span>
                    <Link v-if="entries.next_page_url" :href="entries.next_page_url"
                        class="px-4 py-2 rounded-xl border border-gray-200 dark:border-slate-700 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                        Ältere →
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
