<script setup>
/**
 * WHOOP verbinden (Profil → Verbindungen und Onboarding, 13.11.2026).
 *
 * WHOOP ist reines Lesen: Trainings, Schlaf und Recovery (HRV, Ruhepuls,
 * Blutsauerstoff). Die Verbindung gibt es nur mit Einwilligung. Trennen
 * löscht alles, was von WHOOP kam. Kein Logo, nur der Name.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    connection:     { type: Object, default: null },
    status:         { type: Object, default: null },
    allowDisconnect:{ type: Boolean, default: false },
});

const consent = ref(false);
const busy    = ref(false);
const error   = ref('');
const linked  = computed(() => !!props.connection);

function connect() {
    if (!consent.value) return;
    busy.value = true;
    error.value = '';
    router.post(route('whoop.connect'), { ai_consent: true }, {
        onError: (e) => { error.value = e.ai_consent ?? 'Verbinden ging nicht.'; },
        onFinish: () => { busy.value = false; },
    });
}

function disconnect() {
    busy.value = true;
    router.delete(route('whoop.connection.destroy'), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <div id="conn-whoop" class="scroll-mt-[calc(4.5rem+env(safe-area-inset-top))]">
        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas text-[13px] font-bold" aria-hidden="true">W</span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] text-ink">WHOOP</span>
                <span class="block text-[13px] leading-snug" :class="connection?.error ? 'text-danger' : 'text-ink-3'">
                    <template v-if="connection?.error">{{ connection.error }} · bitte trennen und neu verbinden</template>
                    <template v-else-if="linked">Verbunden · Schlaf, HRV und Trainings</template>
                    <template v-else>Schlaf, HRV und Trainings von deinem WHOOP</template>
                </span>
            </span>
            <button v-if="linked && allowDisconnect" type="button" :disabled="busy" class="shrink-0 text-[17px] text-danger active:opacity-60 disabled:opacity-40" @click="disconnect">
                {{ busy ? 'Trenne …' : 'Trennen' }}
            </button>
            <svg v-else-if="linked" class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </div>

        <div v-if="!linked" class="space-y-3 px-4 pb-3">
            <label class="flex items-start gap-3 text-[15px] leading-snug text-ink-2">
                <input v-model="consent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40" />
                <span>
                    Ich willige ein, dass Zone3 meine WHOOP-Daten (Trainings mit Dauer, Strecke und Herzfrequenz, Schlaf, HRV, Ruhepuls, Blutsauerstoff, Atemfrequenz) für Plan, Auswertungen und Coach an OpenAI übermittelt.
                    Nicht zum Training von KI-Modellen. Widerruf jederzeit durch Trennen.
                    <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                </span>
            </label>
            <AppButton variant="secondary" block :loading="busy" :disabled="!consent" @click="connect">Mit WHOOP verbinden</AppButton>
            <p v-if="error" class="text-[13px] text-danger">{{ error }}</p>
            <p class="text-[13px] leading-snug text-ink-3">Die Anmeldung läuft direkt bei WHOOP. Zone3 holt die letzten Wochen einmal mit. WHOOP liefert keine Strecke auf der Karte und keine Runden.</p>
        </div>

        <p v-if="linked && allowDisconnect" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
            Neues kommt Minuten nachdem WHOOP es berechnet hat, spätestens nach einer Stunde. Trennen löscht alles, was von WHOOP kam.
        </p>
        <p v-if="status" class="px-4 py-3 text-[13px]"
            :class="status.type === 'error' ? 'text-danger' : 'text-success-ink'">{{ status.message }}</p>
    </div>
</template>
