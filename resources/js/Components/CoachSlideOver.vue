<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useCoachChat } from '@/Composables/useCoachChat';
import axios from 'axios';

const { isOpen, close } = useCoachChat();
const page  = usePage();
const coach = computed(() => page.props.coach);

const messages         = ref([]);
const inputText        = ref('');
const isLoading        = ref(false);
const hasLoaded        = ref(false);
const messagesEl       = ref(null);
const inputEl          = ref(null);

const quickPrompts = [
    'Analysiere mein letztes Training',
    'Bin ich auf Kurs für mein Rennen?',
    'Das heutige Training ist zu leicht – mach es schwerer',
    'Ich liege mit Grippe im Bett und kann eine Woche nicht trainieren',
    'Ich möchte meine Zielzeit für das nächste Rennen anpassen',
    'Was soll ich heute trainieren?',
];

// Show a placeholder greeting when no messages exist yet
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

    // Optimistic user bubble
    messages.value.push({ role: 'user', content, created_at: new Date().toISOString() });
    await nextTick();
    scrollToBottom();

    try {
        const res = await axios.post(route('coach.send'), { message: content });
        if (res.data.response) {
            messages.value.push({
                role: 'assistant',
                content: res.data.response,
                actions: res.data.actions_taken ?? [],
                created_at: res.data.timestamp ?? new Date().toISOString(),
            });
            await nextTick();
            scrollToBottom();
        }
    } catch {
        messages.value.push({
            role: 'assistant',
            content: 'Entschuldigung, ich konnte gerade nicht antworten. Versuche es gleich nochmal.',
            created_at: new Date().toISOString(),
            isError: true,
        });
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

// Simple markdown → HTML (bold, lists, inline code, line breaks)
function renderMarkdown(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code class="bg-black/10 rounded px-0.5 text-[0.85em]">$1</code>')
        .replace(/^#{1,3} (.+)$/gm, '<p class="font-semibold mt-2 mb-0.5">$1</p>')
        .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>')
        .replace(/(<li[\s\S]+?<\/li>)/g, '<ul class="space-y-0.5 my-1">$1</ul>')
        .replace(/\n\n/g, '</p><p class="mt-2">')
        .replace(/\n/g, '<br>');
}

const actionIcons = {
    memory:           '🧠',
    session_modified: '✏️',
    sessions_skipped: '⏸️',
    event_updated:    '🎯',
};

watch(isOpen, async (val) => {
    if (val) {
        await fetchMessages();
        await nextTick();
        inputEl.value?.focus();
    }
});
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm"
                @click="close"
            />
        </Transition>

        <!-- Panel -->
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
                class="fixed inset-y-0 right-0 z-50 flex flex-col w-full sm:max-w-md bg-white dark:bg-slate-900 shadow-2xl"
            >
                <!-- Safe-area spacer (Dynamic Island / Notch) -->
                <div class="shrink-0 pt-safe" />

                <!-- Header -->
                <div class="shrink-0 flex items-center gap-3 px-4 py-3 border-b border-gray-100 dark:border-slate-800">
                    <!-- Coach avatar -->
                    <div
                        class="h-10 w-10 rounded-full flex items-center justify-center text-white text-sm font-bold shrink-0"
                        :style="coach ? `background-color: ${coach.avatar_color}` : 'background-color: #6366f1'"
                    >
                        {{ coach?.avatar_initials ?? 'CO' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white leading-tight">
                            {{ coach?.name ?? 'Dein Coach' }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 truncate">
                            {{ coach?.tagline ?? 'Persönlicher Lauf-Coach' }}
                        </p>
                    </div>
                    <button
                        @click="close"
                        class="shrink-0 h-8 w-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Messages -->
                <div ref="messagesEl" class="flex-1 overflow-y-auto px-4 py-4 space-y-3">
                    <div
                        v-for="(msg, i) in displayMessages"
                        :key="i"
                        class="flex gap-2"
                        :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <!-- Coach avatar for assistant messages -->
                        <div
                            v-if="msg.role === 'assistant'"
                            class="h-7 w-7 rounded-full flex items-center justify-center text-white text-[10px] font-bold shrink-0 mt-0.5"
                            :style="coach ? `background-color: ${coach.avatar_color}` : 'background-color: #6366f1'"
                        >
                            {{ coach?.avatar_initials ?? 'CO' }}
                        </div>

                        <div class="max-w-[80%]">
                            <!-- Message bubble -->
                            <div
                                class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed"
                                :class="msg.role === 'user'
                                    ? 'bg-indigo-600 text-white rounded-br-sm whitespace-pre-wrap'
                                    : msg.isError
                                        ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300 rounded-bl-sm whitespace-pre-wrap'
                                        : 'bg-gray-100 dark:bg-slate-800 text-gray-900 dark:text-white rounded-bl-sm'"
                                v-html="msg.role === 'assistant' && !msg.isError ? renderMarkdown(msg.content) : msg.content"
                            />
                            <!-- Action confirmation cards -->
                            <div v-if="msg.actions && msg.actions.length" class="mt-1.5 space-y-1">
                                <div
                                    v-for="(action, ai) in msg.actions"
                                    :key="ai"
                                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium"
                                    :class="{
                                        'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300': action.type === 'memory',
                                        'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300': action.type === 'session_modified',
                                        'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300': action.type === 'sessions_skipped',
                                        'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300': action.type === 'event_updated',
                                    }"
                                >
                                    <span>{{ actionIcons[action.type] ?? '✓' }}</span>
                                    <span>{{ action.label }}</span>
                                </div>
                            </div>
                            <p
                                v-if="msg.created_at"
                                class="mt-1 text-[10px] text-gray-400 dark:text-slate-600"
                                :class="msg.role === 'user' ? 'text-right' : 'text-left'"
                            >
                                {{ formatTime(msg.created_at) }}
                            </p>
                        </div>
                    </div>

                    <!-- Typing indicator -->
                    <div v-if="isLoading" class="flex gap-2 justify-start">
                        <div
                            class="h-7 w-7 rounded-full flex items-center justify-center text-white text-[10px] font-bold shrink-0"
                            :style="coach ? `background-color: ${coach.avatar_color}` : 'background-color: #6366f1'"
                        >
                            {{ coach?.avatar_initials ?? 'CO' }}
                        </div>
                        <div class="bg-gray-100 dark:bg-slate-800 rounded-2xl rounded-bl-sm px-4 py-3 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-slate-500 animate-bounce" style="animation-delay:0ms" />
                            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-slate-500 animate-bounce" style="animation-delay:150ms" />
                            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-slate-500 animate-bounce" style="animation-delay:300ms" />
                        </div>
                    </div>
                </div>

                <!-- Quick prompts -->
                <div v-if="messages.length < 2 && !isLoading" class="shrink-0 px-4 pb-2 flex gap-2 overflow-x-auto scrollbar-hide">
                    <button
                        v-for="prompt in quickPrompts"
                        :key="prompt"
                        @click="sendMessage(prompt)"
                        class="shrink-0 text-xs px-3 py-1.5 rounded-full border border-indigo-200 dark:border-indigo-500/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-colors whitespace-nowrap"
                    >
                        {{ prompt }}
                    </button>
                </div>

                <!-- Input -->
                <div class="shrink-0 border-t border-gray-100 dark:border-slate-800 p-3 flex gap-2 items-end"
                     style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom))">
                    <textarea
                        ref="inputEl"
                        v-model="inputText"
                        @keydown="handleKeydown"
                        placeholder="Schreib deinem Coach…"
                        rows="1"
                        class="flex-1 resize-none rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent max-h-32"
                        style="field-sizing: content;"
                    />
                    <button
                        @click="sendMessage()"
                        :disabled="!inputText.trim() || isLoading"
                        class="shrink-0 h-10 w-10 flex items-center justify-center rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
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
