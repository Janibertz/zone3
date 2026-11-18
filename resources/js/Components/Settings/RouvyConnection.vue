<script setup>
/**
 * ROUVY verbinden (Profil → Verbindungen und Onboarding, 18.11.2026).
 *
 * Direkt über die ROUVY-API, ohne Umweg über intervals.icu: Radeinheiten
 * gehen sieben Tage voraus als Workout mit Datum in die ROUVY-Bibliothek,
 * gefahrene ROUVY-Fahrten kommen zurück. Keine Gesundheitswerte. ROUVY
 * erlaubt KI auf seinen Fahrten nur mit ausdrücklicher Einwilligung.
 * Kein Logo, nur der Name.
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
    router.post(route('rouvy.connect'), { ai_consent: true }, {
        onError: (e) => { error.value = e.ai_consent ?? 'Verbinden ging nicht.'; },
        onFinish: () => { busy.value = false; },
    });
}

function disconnect() {
    busy.value = true;
    router.delete(route('rouvy.connection.destroy'), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <div id="conn-rouvy" class="scroll-mt-[calc(4.5rem+env(safe-area-inset-top))]">
        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas text-[13px] font-bold" aria-hidden="true">R</span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] text-ink">ROUVY</span>
                <span class="block text-[13px] leading-snug" :class="connection?.error ? 'text-danger' : 'text-ink-3'">
                    <template v-if="connection?.error">{{ connection.error }}</template>
                    <template v-else-if="linked">Verbunden · Radeinheiten stehen von selbst in ROUVY</template>
                    <template v-else>Radeinheiten automatisch in ROUVY, Fahrten zurück</template>
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
                    Ich willige ein, dass Zone3 meine ROUVY-Fahrten (Dauer, Strecke, Leistung, Herzfrequenz) für Plan, Auswertungen und Coach an OpenAI übermittelt.
                    Nicht zum Training von KI-Modellen. Widerruf jederzeit durch Trennen.
                    <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                </span>
            </label>
            <AppButton variant="secondary" block :loading="busy" :disabled="!consent" @click="connect">Mit ROUVY verbinden</AppButton>
            <p v-if="error" class="text-[13px] text-danger">{{ error }}</p>
            <p class="text-[13px] leading-snug text-ink-3">Zone3 legt jede Radeinheit sieben Tage voraus in deine ROUVY-Workouts und übernimmt deine ROUVY-Fahrten, keine Gesundheitswerte. Läufe kommen weiter über deine Uhr.</p>
        </div>

        <p v-if="linked && allowDisconnect" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
            Die Einheiten findest du in ROUVY unter Workouts. Trennen nimmt die geplanten Radeinheiten wieder heraus, gefahrene Fahrten bleiben in Zone3.
        </p>
        <p v-if="status" class="px-4 py-3 text-[13px]"
            :class="status.type === 'error' ? 'text-danger' : 'text-success-ink'">{{ status.message }}</p>
    </div>
</template>
