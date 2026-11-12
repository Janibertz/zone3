<script setup>
/**
 * Einwilligung in die KI-Auswertung für Konten, die vor dem 12.11.2026
 * angefangen haben (App Store, Guideline 5.1.2(i)). Neue Konten geben sie
 * im Onboarding beim Coach. Ohne Einwilligung gibt es keinen Plan und
 * keinen Coach, darum lässt sich das Sheet nicht wegwischen; wer nicht
 * will, kommt über den Link zum Konto löschen.
 */
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AiConsentText from '@/Components/Consent/AiConsentText.vue';

const checked = ref(false);
const busy    = ref(false);

function agree() {
    busy.value = true;
    router.post(route('ai-consent.store'), { ai_consent: true }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <AppSheet :show="true" :closeable="false" dialog title="Dein Coach arbeitet mit KI">
        <div class="space-y-4 px-5 pb-5">
            <AiConsentText v-model="checked" />
            <button type="button" :disabled="!checked || busy"
                class="w-full rounded-full bg-accent px-4 py-3 text-[17px] font-semibold text-white transition active:opacity-80 disabled:opacity-40"
                @click="agree">
                Zustimmen und weiter
            </button>
            <p class="text-center text-[13px] text-ink-3">
                Lieber nicht? Dann kannst du dein Konto unter
                <Link :href="route('profile.edit', { section: 'account' })" class="text-accent">Konto</Link> löschen.
            </p>
        </div>
    </AppSheet>
</template>
