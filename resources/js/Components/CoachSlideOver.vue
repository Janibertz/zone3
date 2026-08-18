<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { useCoachChat } from '@/Composables/useCoachChat';
import axios from 'axios';

const { isOpen, close } = useCoachChat();
const page  = usePage();
const coach = computed(() => page.props.coach);

const messages   = ref([]);
const inputText  = ref('');
const isLoading  = ref(false);
const hasLoaded  = ref(false);
const messagesEl = ref(null);
const inputEl    = ref(null);
const pendingReload = ref(false);

/**
 * Die Coach-Farbe steht als Name in der Datenbank ('blue', 'orange', …).
 * Vorher landete sie ungefiltert in `background-color: blue` — also im
 * reinen CSS-Blau statt in der Farbe des Designsystems. Der Rest der App
 * bildet sie längst auf Tokens ab; hier fehlte diese Zuordnung.
 */
const coachColors = {
    orange: 'bg-warn',
    blue:   'bg-info',
    green:  'bg-success',
    purple: 'bg-accent',
};
const coachAvatar = computed(() => coachColors[coach.value?.avatar_color] ?? 'bg-accent');

const quickPrompts = [
    'Analysiere mein letztes Training',
    'Bin ich auf Kurs für mein Rennen?',
    'Das heutige Training ist zu leicht – mach es schwerer',
    'Ich liege mit Grippe im Bett und kann eine Woche nicht trainieren',
    'Ich möchte meine Zielzeit für das nächste Rennen anpassen',
    'Was soll ich heute trainieren?',
];

// Begrüßung, solange noch nichts geschrieben wurde.
const displayMessages = computed(() => {
    if (hasLoaded.value && messages.value.length === 0 && !isLoading.value) {
        return [{
            role: 'assistant',
            content: `Hallo! Ich bin ${coach.value?.name ?? 'dein Coach'}. Wie kann ich dir heute helfen? 💪`,
            created_at: null,
            placeholder: true,
        }];
    }
    return messages.value;
});

/**
 * Ein Datumstrenner, sobald ein neuer Tag beginnt. Der Verlauf reicht über
 * Wochen — ohne ihn stehen dort nur Uhrzeiten, und „08:12" sagt nichts
 * darüber, ob das heute oder vorletzten Dienstag war.
 */
function daySeparator(msg, index) {
    if (!msg.created_at) return null;

    const day = new Date(msg.created_at).toDateString();
    const prev = displayMessages.value[index - 1]?.created_at;
    if (prev && new Date(prev).toDateString() === day) return null;

    const today = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86400000).toDateString();

    if (day === today)     return 'Heute';
    if (day === yesterday) return 'Gestern';

    return new Date(msg.created_at).toLocaleDateString('de-DE', {
        weekday: 'long', day: 'numeric', month: 'long',
    });
}

async function fetchMessages() {
    if (hasLoaded.value) return;
    try {
        const res = await axios.get(route('coach.messages'));
        messages.value = res.data.messages ?? [];
        hasLoaded.value = true;
        await nextTick();
        scrollToBottom();
    } catch {
        hasLoaded.value = true;
    }
}

async function sendMessage(text) {
    const content = (text ?? inputText.value).trim();
    if (!content || isLoading.value) return;

    inputText.value = '';
    isLoading.value = true;

    messages.value.push({ role: 'user', content, created_at: new Date().toISOString() });
    await nextTick();
    scrollToBottom();

    try {
        const res = await axios.post(route('coach.send'), { message: content });
        if (res.data.response) {
            const actions = res.data.actions_taken ?? [];

            messages.value.push({
                role: 'assistant',
                content: res.data.response,
                actions,
                created_at: res.data.timestamp ?? new Date().toISOString(),
            });
            await nextTick();
            scrollToBottom();

            // Der Coach darf Einheiten ändern, absagen und Zielzeiten setzen.
            // Das Backend meldet das mit `reload` zurück — gelesen hat es
            // vorher niemand, und die Seite hinter dem Chat zeigte weiter den
            // alten Stand, bis man selbst neu lud.
            //
            // Nachgeladen wird aber erst beim Schließen: das Layout gehört zur
            // Seite, ein Reload währenddessen würde den Chat neu aufbauen und
            // das Gespräch mitten im Satz abreißen lassen.
            if (actions.some(a => a.reload)) {
                pendingReload.value = true;
            }
        }
    } catch (e) {
        const status = e?.response?.status;
        const fromServer = e?.response?.data?.message;

        const content = status === 429
            ? `${fromServer ?? 'Tageslimit für KI-Anfragen erreicht.'} Morgen geht es weiter — oder du hebst dein Limit im Admin-Bereich an.`
            : (fromServer ?? 'Entschuldigung, ich konnte gerade nicht antworten. Versuche es gleich nochmal.');

        messages.value.push({
            role: 'assistant',
            content,
            created_at: new Date().toISOString(),
            isError: true,
        });
        await nextTick();
        scrollToBottom();
    } finally {
        isLoading.value = false;
    }
}

function handleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function scrollToBottom() {
    if (messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
    }
}

function formatTime(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

/** Schlichtes Markdown: fett, Listen, Code, Absätze. */
function renderMarkdown(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code class="rounded bg-surface-3 px-1 text-[0.85em]">$1</code>')
        .replace(/^#{1,3} (.+)$/gm, '<p class="mt-2 mb-0.5 font-semibold">$1</p>')
        .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>')
        .replace(/(<li[\s\S]+?<\/li>)/g, '<ul class="my-1 space-y-0.5">$1</ul>')
        .replace(/\n\n/g, '</p><p class="mt-2">')
        .replace(/\n/g, '<br>');
}

const actionIcons = {
    memory:           '🧠',
    session_modified: '✏️',
    sessions_skipped: '⏸️',
    event_updated:    '🎯',
};

/**
 * Vorher stand hier ein Objekt-Literal mit zweimal demselben Schlüssel
 * ('bg-warn-soft text-warn-ink' für session_modified UND sessions_skipped).
 * Der zweite überschreibt den ersten, weshalb geänderte Einheiten gar keine
 * Farbe bekamen.
 */
function actionClass(type) {
    return {
        memory:           'bg-accent-soft text-accent-ink',
        session_modified: 'bg-warn-soft text-warn-ink',
        sessions_skipped: 'bg-warn-soft text-warn-ink',
        event_updated:    'bg-success-soft text-success-ink',
    }[type] ?? 'bg-surface-2 text-ink-2';
}

/** Schließen — und nachziehen, falls der Coach etwas geändert hat. */
function closePanel() {
    close();

    if (pendingReload.value) {
        pendingReload.value = false;
        router.reload({ preserveScroll: true });
    }
}

function onKeydown(e) {
    if (e.key === 'Escape' && isOpen.value) closePanel();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

watch(isOpen, async (val) => {
    if (val) {
        await fetchMessages();
        await nextTick();
        scrollToBottom();
        inputEl.value?.focus();
    }
}, { immediate: true });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm" @click="closePanel" />
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="isOpen"
                class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-canvas shadow-2xl sm:max-w-md lg:max-w-lg"
                role="dialog"
                aria-modal="true"
                :aria-label="`Chat mit ${coach?.name ?? 'deinem Coach'}`"
            >
                <div class="shrink-0 pt-safe" />

                <!-- ── Kopf ───────────────────────────────────────── -->
                <header class="flex shrink-0 items-center gap-3 bg-surface px-4 py-3 shadow-card">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                        :class="coachAvatar">
                        {{ coach?.avatar_initials ?? 'CO' }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold leading-tight text-ink">
                            {{ coach?.name ?? 'Dein Coach' }}
                        </p>
                        <p class="truncate text-[13px] text-ink-3">
                            {{ coach?.tagline ?? 'Persönlicher Lauf-Coach' }}
                        </p>
                    </div>
                    <button
                        aria-label="Schließen"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink active:scale-95"
                        @click="closePanel"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <!-- ── Verlauf ────────────────────────────────────── -->
                <div ref="messagesEl" class="flex-1 space-y-3 overflow-y-auto overscroll-contain px-4 py-4">
                    <template v-for="(msg, i) in displayMessages" :key="i">
                        <!-- Tagestrenner -->
                        <div v-if="daySeparator(msg, i)" class="flex items-center gap-3 pt-2">
                            <span class="h-px flex-1 bg-line" />
                            <span class="text-[11px] font-medium uppercase tracking-wide text-ink-3">
                                {{ daySeparator(msg, i) }}
                            </span>
                            <span class="h-px flex-1 bg-line" />
                        </div>

                        <div class="flex gap-2" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                            <span
                                v-if="msg.role === 'assistant'"
                                class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                :class="coachAvatar"
                            >
                                {{ coach?.avatar_initials ?? 'CO' }}
                            </span>

                            <div class="max-w-[82%] min-w-0">
                                <div
                                    class="rounded-card px-3.5 py-2.5 text-sm leading-relaxed"
                                    :class="msg.role === 'user'
                                        ? 'whitespace-pre-wrap rounded-br-sm bg-ink text-canvas'
                                        : msg.isError
                                            ? 'whitespace-pre-wrap rounded-bl-sm bg-danger-soft text-danger-ink'
                                            : 'rounded-bl-sm bg-surface text-ink shadow-card'"
                                    v-html="msg.role === 'assistant' && !msg.isError ? renderMarkdown(msg.content) : msg.content"
                                />

                                <!-- Was der Coach tatsächlich geändert hat -->
                                <div v-if="msg.actions && msg.actions.length" class="mt-1.5 space-y-1">
                                    <div
                                        v-for="(action, ai) in msg.actions"
                                        :key="ai"
                                        class="flex items-center gap-2 rounded-field px-3 py-2 text-[13px] font-medium"
                                        :class="actionClass(action.type)"
                                    >
                                        <span>{{ actionIcons[action.type] ?? '✓' }}</span>
                                        <span class="min-w-0">{{ action.label }}</span>
                                    </div>
                                </div>

                                <p
                                    v-if="msg.created_at"
                                    class="mt-1 text-[11px] text-ink-3"
                                    :class="msg.role === 'user' ? 'text-right' : 'text-left'"
                                >
                                    {{ formatTime(msg.created_at) }}
                                </p>
                            </div>
                        </div>
                    </template>

                    <!-- Der Coach tippt -->
                    <div v-if="isLoading" class="flex justify-start gap-2">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                            :class="coachAvatar">
                            {{ coach?.avatar_initials ?? 'CO' }}
                        </span>
                        <div class="flex items-center gap-1 rounded-card rounded-bl-sm bg-surface px-4 py-3 shadow-card">
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-3" style="animation-delay:0ms" />
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-3" style="animation-delay:150ms" />
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-3" style="animation-delay:300ms" />
                        </div>
                    </div>
                </div>

                <!-- ── Vorschläge ─────────────────────────────────── -->
                <!-- Bleiben erreichbar, solange nichts im Feld steht. Vorher
                     verschwanden sie nach der zweiten Nachricht für immer. -->
                <div v-if="!inputText.trim() && !isLoading"
                    class="scrollbar-hide flex shrink-0 gap-2 overflow-x-auto px-4 pb-2">
                    <button
                        v-for="prompt in quickPrompts"
                        :key="prompt"
                        class="shrink-0 whitespace-nowrap rounded-full bg-surface px-3.5 py-2 text-[13px] font-medium text-ink-2 shadow-card transition-colors hover:text-ink active:scale-[0.98]"
                        @click="sendMessage(prompt)"
                    >
                        {{ prompt }}
                    </button>
                </div>

                <!-- ── Eingabe ────────────────────────────────────── -->
                <div class="flex shrink-0 items-end gap-2 bg-surface p-3"
                    style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom))">
                    <textarea
                        ref="inputEl"
                        v-model="inputText"
                        rows="1"
                        placeholder="Schreib deinem Coach…"
                        class="z-input max-h-32 flex-1 resize-none"
                        style="field-sizing: content;"
                        @keydown="handleKeydown"
                    />
                    <button
                        :disabled="!inputText.trim() || isLoading"
                        aria-label="Senden"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-ink text-canvas transition-all hover:opacity-90 active:scale-95 disabled:cursor-not-allowed disabled:opacity-30"
                        @click="sendMessage()"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
