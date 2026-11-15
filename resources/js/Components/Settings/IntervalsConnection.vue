<script setup>
/**
 * intervals.icu verbinden (Profil → Verbindungen und Onboarding, 15.11.2026).
 *
 * Bei Zone3 nur die Brücke zu Zwift (Jan): Radeinheiten gehen in den
 * intervals-Kalender und von dort in Zwift, gefahrene Zwift-Fahrten kommen
 * zurück. Keine Gesundheitsdaten. Dafür braucht der Athlet ein (kostenloses)
 * Konto bei intervals.icu und muss dort einmal Zwift verbinden — das erklärt
 * die Karte Schritt für Schritt. Kein Logo, nur der Name.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    connection:     { type: Object, default: null },
    status:         { type: Object, default: null },
    allowDisconnect:{ type: Boolean, default: false },
});

const consent  = ref(false);
const busy     = ref(false);
const error    = ref('');
const showHelp = ref(false);
const linked   = computed(() => !!props.connection);

function connect() {
    if (!consent.value) return;
    busy.value = true;
    error.value = '';
    router.post(route('intervals.connect'), { ai_consent: true }, {
        onError: (e) => { error.value = e.ai_consent ?? 'Verbinden ging nicht.'; },
        onFinish: () => { busy.value = false; },
    });
}

function disconnect() {
    busy.value = true;
    router.delete(route('intervals.connection.destroy'), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <div id="conn-intervals" class="scroll-mt-[calc(4.5rem+env(safe-area-inset-top))]">
        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas text-[13px] font-bold" aria-hidden="true">i</span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] text-ink">intervals.icu <span class="text-ink-3">· für Zwift</span></span>
                <span class="block text-[13px] leading-snug" :class="connection?.error ? 'text-danger' : 'text-ink-3'">
                    <template v-if="connection?.error">{{ connection.error }} · bitte trennen und neu verbinden</template>
                    <template v-else-if="linked">Verbunden · Radeinheiten gehen von selbst nach Zwift</template>
                    <template v-else>Radeinheiten automatisch in Zwift, Fahrten zurück</template>
                </span>
            </span>
            <button v-if="linked && allowDisconnect" type="button" :disabled="busy" class="shrink-0 text-[17px] text-danger active:opacity-60 disabled:opacity-40" @click="disconnect">
                {{ busy ? 'Trenne …' : 'Trennen' }}
            </button>
            <svg v-else-if="linked" class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </div>

        <div v-if="!linked" class="space-y-3 px-4 pb-3">
            <button type="button" class="text-[15px] text-accent active:opacity-60" @click="showHelp = !showHelp">
                {{ showHelp ? 'Anleitung ausblenden' : 'So kommen deine Radeinheiten in Zwift' }}
            </button>
            <ol v-if="showHelp" class="list-decimal space-y-1.5 pl-5 text-[15px] leading-snug text-ink-2">
                <li>Leg dir ein kostenloses Konto bei <a href="https://intervals.icu" target="_blank" rel="noopener noreferrer" class="text-accent">intervals.icu</a> an.</li>
                <li>Dort in den Einstellungen Zwift verbinden und das Hochladen geplanter Workouts einschalten.</li>
                <li>Hier mit intervals.icu verbinden.</li>
                <li>Fertig: Zone3 legt jede Radeinheit sieben Tage voraus in deinen intervals-Kalender, intervals.icu schickt sie nach Zwift. In Zwift stehen sie dann bei den Workouts für den Tag.</li>
                <li>Nach der Fahrt kommt sie über intervals.icu zurück und hakt die Einheit ab.</li>
            </ol>
            <label class="flex items-start gap-3 text-[15px] leading-snug text-ink-2">
                <input v-model="consent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40" />
                <span>
                    Ich willige ein, dass Zone3 meine Zwift-Fahrten von intervals.icu (Dauer, Strecke, Leistung, Herzfrequenz) für Plan, Auswertungen und Coach an OpenAI übermittelt.
                    Nicht zum Training von KI-Modellen. Widerruf jederzeit durch Trennen.
                    <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                </span>
            </label>
            <AppButton variant="secondary" block :loading="busy" :disabled="!consent" @click="connect">Mit intervals.icu verbinden</AppButton>
            <p v-if="error" class="text-[13px] text-danger">{{ error }}</p>
            <p class="text-[13px] leading-snug text-ink-3">Zone3 übernimmt von intervals.icu nur Zwift-Fahrten und keine Gesundheitswerte. Läufe kommen weiter über deine Uhr.</p>
        </div>

        <p v-if="linked && allowDisconnect" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
            In intervals.icu muss Zwift verbunden sein, sonst kommen die Einheiten dort nicht an. Eine Änderung kurz vor der Fahrt erreicht Zwift nicht immer. Trennen nimmt die geplanten Radeinheiten aus dem Kalender, gefahrene bleiben.
        </p>
        <p v-if="status" class="px-4 py-3 text-[13px]"
            :class="status.type === 'error' ? 'text-danger' : 'text-success-ink'">{{ status.message }}</p>
    </div>
</template>
