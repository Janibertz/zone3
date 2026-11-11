<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { syncAppleHealth, useAppleHealth } from '@/Composables/useAppleHealth';
import { isIosApp } from '@/Composables/useNativeApp';
import { fmtNum } from '@/Composables/useFormat';

/**
 * Apple Health im Profil unter Verbindungen.
 *
 * In der iOS-App: Zugriff erlauben, die Werte der letzten sieben Tage zur
 * Kontrolle sehen und mit eigenem Haken an Zone3 übertragen (Stufe 2,
 * 08.11.2026). Im Browser gibt es HealthKit nicht; dort steht die Karte
 * nur, solange übertragen wird, damit man es auch dort beenden kann.
 */
const props = defineProps({
    state:          { type: Object, default: () => ({ consent_at: null, watch_metrics: false, synced_at: null }) },
    activitySource: { type: String, default: null },
    // Wo die Wahl der Aktivitätsquelle steht (im Onboarding nicht „unten“).
    sourceHint:     { type: String, default: null },
});

const page = usePage();
const native = isIosApp();
const health = useAppleHealth();
const consent = ref(false);
const saving = ref(false);
const syncNote = ref(null);

const consented = computed(() => !!props.state?.consent_at);
const readable = computed(() => native && health.supported.value && health.available.value);

onMounted(async () => {
    if (await health.check() && health.asked.value) health.load();
});

function sleepLabel(min) {
    if (min == null) return '–';
    return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`;
}

function dayLabel(date) {
    return new Date(`${date}T12:00:00`).toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'numeric' });
}

const num = (v, digits = 0) => (v == null ? '–' : fmtNum(v, digits));

const latestVo2 = computed(() => health.days.value.find((d) => d.vo2max != null) ?? null);

function setConsent(enabled) {
    saving.value = true;
    router.patch(route('apple-health.consent'), { enabled, ai_consent: enabled }, {
        preserveScroll: true,
        // Im Onboarding bliebe sonst nicht der Verbinden-Schritt stehen.
        preserveState: true,
        onSuccess: async () => {
            if (!enabled) return;
            syncNote.value = 'Wird übertragen …';
            try {
                const res = await syncAppleHealth(page.props.auth?.appleHealth, { force: true });
                const days = Object.values(res?.metrics?.written ?? {}).reduce((a, b) => Math.max(a, b), 0);
                const runs = res?.workouts?.outcomes?.imported ?? 0;
                syncNote.value = `Übertragen: ${days} ${days === 1 ? 'Tag' : 'Tage'} Erholung` + (res?.workouts ? `, ${runs} neue Trainings.` : '.');
            } catch {
                syncNote.value = 'Übertragen hat nicht geklappt. Zone3 versucht es beim nächsten Öffnen wieder.';
            }
        },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div>
        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-white ring-1 ring-line" aria-hidden="true">
                <svg class="h-[18px] w-[18px] text-[#FF2D55]" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7.5-4.6-9.4-9.3C1.3 8.4 3.3 5 6.7 5c2 0 3.5 1.1 4.3 2.5h2C13.8 6.1 15.3 5 17.3 5c3.4 0 5.4 3.4 4.1 6.7C19.5 16.4 12 21 12 21z" /></svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] text-ink">Apple Health</span>
                <span class="block text-[13px] leading-snug text-ink-3">
                    <template v-if="consented">Wird an Zone3 übertragen</template>
                    <template v-else-if="!health.supported.value">Erst mit der neuesten App aus TestFlight</template>
                    <template v-else-if="!health.available.value">Auf diesem Gerät nicht verfügbar</template>
                    <template v-else-if="health.asked.value">Vorschau · noch nicht übertragen</template>
                    <template v-else>Nicht freigegeben</template>
                </span>
            </span>
            <button v-if="readable && !health.asked.value" type="button"
                :disabled="health.busy.value"
                class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40"
                @click="health.connect()">
                Freigeben
            </button>
            <button v-else-if="readable" type="button"
                :disabled="health.busy.value"
                class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40"
                @click="health.load()">
                {{ health.busy.value ? 'Lese …' : 'Neu lesen' }}
            </button>
        </div>

        <p v-if="readable && !health.asked.value" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
            Zone3 liest Schlaf, HRV, Ruhepuls, VO2max, Blutsauerstoff, Atemfrequenz und deine Trainings mit Herzfrequenz und Strecke — sonst nichts. iOS fragt dich gleich einmal, welche Werte du erlaubst.
        </p>

        <template v-if="readable && health.asked.value">
            <p v-if="health.error.value" class="px-4 pb-3 text-[13px] text-danger">{{ health.error.value }}</p>
            <div v-else-if="health.days.value.length" class="px-4 pb-3">
                <table class="w-full text-[13px] tabular-nums">
                    <thead class="text-ink-3">
                        <tr>
                            <th class="py-1 text-left font-normal">Tag</th>
                            <th class="py-1 text-right font-normal">Schlaf</th>
                            <th class="py-1 text-right font-normal">HRV</th>
                            <th class="py-1 text-right font-normal">Ruhe</th>
                            <th class="py-1 text-right font-normal">SpO₂</th>
                            <th class="py-1 text-right font-normal">Atem</th>
                        </tr>
                    </thead>
                    <tbody class="text-ink">
                        <tr v-for="d in health.days.value" :key="d.date" class="border-t border-line">
                            <td class="py-1.5">{{ dayLabel(d.date) }}</td>
                            <td class="py-1.5 text-right">{{ sleepLabel(d.sleep_min) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.hrv_ms) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.resting_hr) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.spo2) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.respiration, 1) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-[13px] leading-snug text-ink-3">
                    Schlaf in h:mm, HRV in ms (Apple misst SDNN), Ruhepuls in bpm, Blutsauerstoff in %, Atemzüge pro Minute.
                    <template v-if="latestVo2">VO2max {{ num(latestVo2.vo2max, 1) }} ({{ dayLabel(latestVo2.date) }}).</template>
                </p>
            </div>
            <p v-else-if="!health.busy.value" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
                Keine Werte aus den letzten sieben Tagen. Entweder misst nichts (HRV, Ruhepuls, Blutsauerstoff und Atemfrequenz brauchen eine Apple Watch), oder der Zugriff ist aus: iPhone-Einstellungen → Health → Datenzugriff &amp; Geräte → Zone3.
            </p>

            <!-- Übertragen: eigener Haken, die Werte gehen in die Prompts. -->
            <div v-if="!consented" class="space-y-3 px-4 pb-4">
                <label class="flex items-start gap-3 text-[15px] leading-snug text-ink-2">
                    <input v-model="consent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40" />
                    <span>
                        Ich willige ein, dass Zone3 diese Werte und meine Trainings aus Apple Health speichert und für Plan, Auswertungen und Coach an OpenAI übermittelt.
                        Nicht zum Training von KI-Modellen. Widerruf jederzeit hier.
                        <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                    </span>
                </label>
                <button type="button" :disabled="!consent || saving"
                    class="w-full rounded-[12px] bg-accent py-3 text-[17px] font-semibold text-white active:opacity-80 disabled:opacity-40"
                    @click="setConsent(true)">
                    An Zone3 übertragen
                </button>
            </div>
        </template>

        <div v-if="consented" class="space-y-2 px-4 pb-4 text-[13px] leading-snug text-ink-3">
            <p v-if="syncNote" class="text-ink-2">{{ syncNote }}</p>
            <p v-if="state.watch_metrics">Deine Erholungswerte kommen von deiner Uhr (Garmin oder COROS) — die haben Vorrang. Aus Apple Health kommen nur Trainings, wenn Apple die Aktivitätsquelle ist.</p>
            <p v-else>Schlaf, HRV, Ruhepuls, VO2max, Blutsauerstoff und Atemfrequenz gehen beim Öffnen der App an Zone3.</p>
            <p v-if="activitySource === 'apple'">Deine Trainings kommen aus Apple Health.</p>
            <p v-else-if="sourceHint">{{ sourceHint }}</p>
            <p v-else>Trainings aus Apple Health übernimmt Zone3, wenn du unten bei „Aktivitäten kommen von“ Apple wählst.</p>
            <p v-if="!native">Übertragen wird nur aus der iOS-App.</p>
            <button type="button" :disabled="saving" class="text-[15px] text-danger active:opacity-60 disabled:opacity-40" @click="setConsent(false)">
                Übertragung beenden
            </button>
            <p>Beenden löscht die Erholungswerte aus Apple Health in Zone3. Übernommene Trainings bleiben.</p>
        </div>
    </div>
</template>
