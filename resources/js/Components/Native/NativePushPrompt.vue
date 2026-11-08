<script setup>
/**
 * In der iOS-App: einmal fragen, ob Mitteilungen an sollen. iOS zeigt seinen
 * eigenen Dialog nur ein einziges Mal; wer dort „Nicht erlauben“ tippt, muss
 * in die Einstellungen. Darum zuerst diese Karte mit dem Grund, und erst
 * „Einschalten“ löst den Systemdialog aus.
 *
 * Wer schließt, wird 30 Tage nicht mehr gefragt. Wer schon entschieden hat
 * (erlaubt oder abgelehnt), sieht die Karte nie.
 */
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePush } from '@/Composables/usePush';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

const DISMISS_KEY  = 'native_push_prompt_dismissed_at';
const DISMISS_DAYS = 30;

const page = usePage();
const push = usePush(page.props.vapidPublicKey ?? '');

function wasDismissed() {
    try {
        const ts = localStorage.getItem(DISMISS_KEY);
        return !!ts && Date.now() - parseInt(ts, 10) < DISMISS_DAYS * 86400000;
    } catch {
        return false;
    }
}

const dismissed = ref(wasDismissed());

const visible = computed(() =>
    push.native === true
    && !dismissed.value
    && push.permission.value === 'default'
    && !push.subscribed.value
);

function dismiss() {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch {}
    dismissed.value = true;
}

async function enable() {
    await push.subscribe();
    // Erlaubt oder abgelehnt: die Frage ist beantwortet.
    dismissed.value = true;
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out-strong"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-out-strong"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-4 opacity-0"
    >
        <div
            v-if="visible"
            class="fixed inset-x-3 z-40 flex items-center gap-3 rounded-2xl bg-surface px-3 py-3 shadow-[0_8px_30px_rgba(0,0,0,0.18)] ring-1 ring-line lg:hidden"
            style="bottom: calc(4.75rem + env(safe-area-inset-bottom))"
        >
            <ZoneIcon class="h-10 w-10 rounded-[9px]" />
            <div class="min-w-0 flex-1">
                <p class="text-[15px] font-semibold leading-tight">Mitteilungen einschalten?</p>
                <p class="mt-0.5 text-[13px] leading-snug text-ink-3">Für deinen Coach, die Tagesform und neue Pläne</p>
            </div>
            <button
                @click="enable"
                :disabled="push.loading.value"
                class="shrink-0 rounded-full bg-accent px-4 py-2 text-[15px] font-semibold text-white transition active:opacity-80 disabled:opacity-60"
            >
                Einschalten
            </button>
            <button @click="dismiss" class="-mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-3 transition active:bg-surface-2" aria-label="Schließen">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </Transition>
</template>
