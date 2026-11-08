<script setup>
/**
 * Wo der Coach dich erreicht: Telegram oder Push, gleichrangig (Jan,
 * 07.11.2026) — jeder entscheidet selbst. Im Onboarding als eigener Schritt
 * und auf „Heute“ aus der Einsteiger-Checkliste.
 *
 * Telegram öffnet t.me mit einem Einmal-Code und verlässt dafür kurz die
 * App; beim Zurückwechseln lädt die Seite den Stand neu (`changed`).
 */
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import { usePush } from '@/Composables/usePush';
import { isNativeApp } from '@/Composables/useNativeApp';

const props = defineProps({
    vapidPublicKey: { type: String, default: '' },
    telegram:       { type: Object, default: () => ({ available: false, linked: false }) },
});

const emit = defineEmits(['changed']);

const push = usePush(props.vapidPublicKey);

const telegramLinked = computed(() => !!props.telegram?.linked);
const anyOn = computed(() => telegramLinked.value || push.subscribed.value);
defineExpose({ anyOn });

function linkTelegram() {
    router.post(route('telegram.link'));
}

async function togglePush(on) {
    if (on) await push.subscribe(); else await push.unsubscribe();
    emit('changed');
}

function onVisible() {
    if (!document.hidden) emit('changed');
}
onMounted(() => document.addEventListener('visibilitychange', onVisible));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible));

const pushHint = computed(() => {
    if (!push.supported.value) return isNativeApp() ? 'In der App noch nicht verfügbar' : 'Auf dem iPhone nur aus der Homescreen-App';
    if (push.permission.value === 'denied') return 'Im Browser blockiert';
    return push.subscribed.value ? 'Aktiv auf diesem Gerät' : 'Mitteilungen auf diesem Gerät';
});
</script>

<template>
    <div class="space-y-7">
        <FormGroup title="Was kommt" plain>
            <ul class="space-y-2.5 text-[15px] leading-snug text-ink-2">
                <li class="flex gap-2.5"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-accent" />Morgens deine Einheit und die Frage nach der Tagesform</li>
                <li class="flex gap-2.5"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success" />Nach jedem Lauf das Feedback deines Coaches</li>
                <li class="flex gap-2.5"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warn" />Vorschläge zum Plan, zum Annehmen oder Ablehnen</li>
            </ul>
        </FormGroup>

        <FormGroup title="Wähle, was zu dir passt" :footer="telegram.available ? 'Mit Telegram kannst du dem Coach auch direkt antworten. Hast du beides, kommen Coach-Nachrichten über Telegram.' : null">
            <div v-if="telegram.available" class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-info text-white">
                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 4L3 11l6 2.5M21 4l-3.5 16-8.5-6.5M21 4L9 13.5v5.5l3-3.5" /></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[17px] text-ink">Telegram</span>
                    <span class="block text-[13px] leading-snug text-ink-3">{{ telegramLinked ? 'Verbunden' : 'Dein Coach im Messenger' }}</span>
                </span>
                <svg v-if="telegramLinked" class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-label="Verbunden"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <button v-else type="button" class="shrink-0 text-[17px] text-accent active:opacity-60" @click="linkTelegram">Verbinden</button>
            </div>

            <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-danger text-white">
                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4" /></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[17px] text-ink">Push-Mitteilungen</span>
                    <span class="block text-[13px] leading-snug text-ink-3">{{ pushHint }}</span>
                </span>
                <svg v-if="push.loading.value" class="h-5 w-5 animate-spin text-ink-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                <Toggle v-if="push.supported.value" :model-value="push.subscribed.value" label="Push-Mitteilungen auf diesem Gerät"
                    :disabled="push.loading.value || push.permission.value === 'denied'" @update:model-value="togglePush" />
            </div>
            <template v-if="push.error.value" #footer><span class="text-danger">{{ push.error.value }}</span></template>
        </FormGroup>
    </div>
</template>
