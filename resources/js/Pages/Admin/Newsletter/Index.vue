<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    newsletters:     Array,
    subscriberCount: Number,
    totalUsers:      Number,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

// ── Editor state ──────────────────────────────────────────────────────────────
const editorRef   = ref(null);
const subject     = ref('');
const previewText = ref('');
const saving      = ref(false);
const sending     = ref(false);
const sendConfirm = ref(null);   // newsletter to confirm sending
const editingId   = ref(null);   // null = new draft

function execCmd(cmd, value = null) {
    editorRef.value?.focus();
    document.execCommand(cmd, false, value);
}

function insertLink() {
    const url = prompt('URL eingeben:', 'https://');
    if (url) execCmd('createLink', url);
}

const toolbarButtons = [
    { cmd: 'bold',          icon: 'B',   title: 'Fett',         class: 'font-bold' },
    { cmd: 'italic',        icon: 'I',   title: 'Kursiv',       class: 'italic' },
    { cmd: 'underline',     icon: 'U',   title: 'Unterstrichen',class: 'underline' },
    { cmd: 'formatBlock',   icon: 'H2',  title: 'Überschrift 2',class: 'font-bold text-[11px]', val: 'h2' },
    { cmd: 'formatBlock',   icon: 'H3',  title: 'Überschrift 3',class: 'font-bold text-[10px]', val: 'h3' },
    { cmd: 'insertUnorderedList', icon: '•—', title: 'Liste',   class: '' },
    { cmd: 'insertOrderedList',   icon: '1.', title: 'Nummeriert',class: '' },
];

function getHtml() {
    return editorRef.value?.innerHTML ?? '';
}

function setHtml(html) {
    if (editorRef.value) editorRef.value.innerHTML = html;
}

// ── Save draft ────────────────────────────────────────────────────────────────
function saveDraft() {
    if (!subject.value.trim() || !getHtml().trim()) return;
    saving.value = true;

    const payload = {
        subject:      subject.value,
        preview_text: previewText.value,
        html_content: getHtml(),
    };

    if (editingId.value) {
        router.put(route('admin.newsletter.update', editingId.value), payload, {
            preserveScroll: true,
            onFinish: () => { saving.value = false; },
        });
    } else {
        router.post(route('admin.newsletter.store'), payload, {
            preserveScroll: true,
            onSuccess: () => {
                subject.value = '';
                previewText.value = '';
                setHtml('');
                editingId.value = null;
            },
            onFinish: () => { saving.value = false; },
        });
    }
}

// ── Edit existing draft ───────────────────────────────────────────────────────
function editDraft(nl) {
    editingId.value   = nl.id;
    subject.value     = nl.subject;
    previewText.value = nl.preview_text ?? '';
    // Use nextTick-equivalent: wait for DOM
    setTimeout(() => setHtml(nl.html_content), 50);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelEdit() {
    editingId.value   = null;
    subject.value     = '';
    previewText.value = '';
    setHtml('');
}

// ── Send ──────────────────────────────────────────────────────────────────────
function confirmSend(nl) { sendConfirm.value = nl; }
function cancelSend()    { sendConfirm.value = null; }

function doSend() {
    if (!sendConfirm.value) return;
    sending.value = true;
    router.post(route('admin.newsletter.send', sendConfirm.value.id), {}, {
        preserveScroll: true,
        onFinish: () => { sending.value = false; sendConfirm.value = null; },
    });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteDraft(nl) {
    if (!confirm(`Newsletter "${nl.subject}" wirklich löschen?`)) return;
    router.delete(route('admin.newsletter.destroy', nl.id), { preserveScroll: true });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Admin – Newsletter" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Newsletter</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl text-sm text-green-700 dark:text-green-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                {{ flash.success }}
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400">Abonnenten</p>
                    <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-0.5">{{ subscriberCount }}</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">von {{ totalUsers }} Nutzern</p>
                </div>
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400">Versendet</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-0.5">{{ newsletters.filter(n => n.sent_at).length }}</p>
                </div>
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400">Entwürfe</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-0.5">{{ newsletters.filter(n => !n.sent_at).length }}</p>
                </div>
            </div>

            <!-- ── Editor ── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-100 dark:border-slate-800 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
                        {{ editingId ? 'Entwurf bearbeiten' : 'Neuer Newsletter' }}
                    </h2>
                    <button v-if="editingId" @click="cancelEdit" class="text-xs text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 transition-colors">
                        Abbrechen
                    </button>
                </div>

                <div class="p-4 sm:p-6 space-y-4">
                    <!-- Subject -->
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Betreff *</label>
                        <input v-model="subject" type="text" placeholder="z.B. Zone3 – Dein Monats-Update Mai 2026"
                            class="mt-1.5 w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Preview text -->
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Vorschautext <span class="font-normal">(optional, erscheint in der Inbox-Vorschau)</span></label>
                        <input v-model="previewText" type="text" maxlength="255" placeholder="Kurze Zusammenfassung für die E-Mail-Vorschau…"
                            class="mt-1.5 w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Rich text editor -->
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Inhalt *</label>

                        <!-- Toolbar -->
                        <div class="mt-1.5 flex flex-wrap items-center gap-1 px-2 py-1.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-t-xl border-b-0">
                            <button v-for="btn in toolbarButtons" :key="btn.cmd + btn.icon"
                                @mousedown.prevent="execCmd(btn.cmd, btn.val ?? null)"
                                :title="btn.title"
                                class="h-7 min-w-[28px] px-1.5 flex items-center justify-center rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
                                :class="btn.class">
                                {{ btn.icon }}
                            </button>
                            <div class="h-5 w-px bg-gray-200 dark:bg-slate-600 mx-0.5" />
                            <button @mousedown.prevent="insertLink" title="Link einfügen"
                                class="h-7 px-2 flex items-center justify-center rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                </svg>
                            </button>
                            <button @mousedown.prevent="execCmd('insertHorizontalRule')" title="Trennlinie"
                                class="h-7 px-2 flex items-center justify-center rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                                <span class="text-xs">—</span>
                            </button>
                            <button @mousedown.prevent="execCmd('removeFormat')" title="Formatierung entfernen"
                                class="h-7 px-2 flex items-center justify-center rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Editable area -->
                        <div
                            ref="editorRef"
                            contenteditable="true"
                            spellcheck="true"
                            class="min-h-[280px] w-full border border-gray-200 dark:border-slate-700 rounded-b-xl bg-white dark:bg-slate-800 px-4 py-3 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 newsletter-editor"
                            @input="() => {}"
                        />
                        <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">
                            Opt-Out-Link wird automatisch an jede E-Mail angehängt.
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button @click="saveDraft" :disabled="saving || !subject.trim()"
                            class="inline-flex items-center gap-2 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-300 text-sm font-semibold px-4 py-2.5 disabled:opacity-40 transition-colors">
                            <svg v-if="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            {{ editingId ? 'Änderungen speichern' : 'Als Entwurf speichern' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Newsletter list ── -->
            <div v-if="newsletters.length" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-100 dark:border-slate-800">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Alle Newsletter</h2>
                </div>
                <div class="divide-y divide-gray-50 dark:divide-slate-800">
                    <div v-for="nl in newsletters" :key="nl.id" class="px-4 sm:px-6 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ nl.subject }}</span>
                                    <span v-if="nl.sent_at"
                                        class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        Versendet
                                    </span>
                                    <span v-else
                                        class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400">
                                        Entwurf
                                    </span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-400 dark:text-slate-500">
                                    <span v-if="nl.sent_at">Versendet: {{ formatDate(nl.sent_at) }} · {{ nl.sent_count }} Empfänger</span>
                                    <span v-else>Erstellt: {{ formatDate(nl.created_at) }}</span>
                                    <span v-if="nl.preview_text" class="truncate max-w-xs italic">{{ nl.preview_text }}</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 shrink-0">
                                <template v-if="!nl.sent_at">
                                    <button @click="editDraft(nl)"
                                        class="h-8 w-8 flex items-center justify-center rounded-lg text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors" title="Bearbeiten">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                        </svg>
                                    </button>
                                    <button @click="confirmSend(nl)"
                                        class="h-8 px-3 flex items-center gap-1.5 rounded-lg text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                                        </svg>
                                        Senden
                                    </button>
                                    <button @click="deleteDraft(nl)"
                                        class="h-8 w-8 flex items-center justify-center rounded-lg text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors" title="Löschen">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </template>
                                <span v-else class="text-xs text-gray-400 dark:text-slate-500">Unveränderlich</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p v-else class="text-center text-sm text-gray-400 dark:text-slate-500 py-4">Noch keine Newsletter erstellt.</p>
        </div>

        <!-- ── Send confirmation dialog ── -->
        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition duration-100 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="sendConfirm" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-4" @click.self="cancelSend">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-6 w-full max-w-md">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">Newsletter wirklich senden?</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">
                        <strong class="text-gray-900 dark:text-white">{{ sendConfirm.subject }}</strong><br>
                        wird an <strong class="text-indigo-600 dark:text-indigo-400">{{ subscriberCount }} Abonnenten</strong> versendet.
                        Dieser Vorgang kann nicht rückgängig gemacht werden.
                    </p>
                    <div class="mt-5 flex gap-3 justify-end">
                        <button @click="cancelSend" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">Abbrechen</button>
                        <button @click="doSend" :disabled="sending"
                            class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 transition-colors">
                            <svg v-if="sending" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            Jetzt senden
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AdminLayout>
</template>

<style scoped>
.newsletter-editor :deep(h2) { font-size: 1.2em; font-weight: 700; margin: 1em 0 0.4em; }
.newsletter-editor :deep(h3) { font-size: 1.05em; font-weight: 600; margin: 0.8em 0 0.3em; }
.newsletter-editor :deep(ul) { list-style: disc; padding-left: 1.4em; margin: 0.5em 0; }
.newsletter-editor :deep(ol) { list-style: decimal; padding-left: 1.4em; margin: 0.5em 0; }
.newsletter-editor :deep(a)  { color: #6366f1; text-decoration: underline; }
.newsletter-editor :deep(hr) { border: none; border-top: 1px solid #e2e8f0; margin: 1em 0; }
.newsletter-editor :deep(blockquote) { border-left: 3px solid #6366f1; padding: 0.5em 1em; background: #f8f9ff; margin: 0.5em 0; }
</style>
