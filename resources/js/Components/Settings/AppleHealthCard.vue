<script setup>
import { onMounted } from 'vue';
import { useAppleHealth } from '@/Composables/useAppleHealth';
import { fmtNum } from '@/Composables/useFormat';

/**
 * Apple Health im Profil unter Verbindungen — nur in der iOS-App.
 *
 * Stufe 1 (08.11.2026): Zugriff erlauben und die Werte der letzten sieben
 * Tage zur Kontrolle zeigen. Übertragen wird noch nichts; erst wenn die
 * Zahlen mit der Health-App übereinstimmen, gehen sie an den Server.
 */
const health = useAppleHealth();

onMounted(async () => {
    if (await health.check() && health.asked.value) health.load();
});

function sleepLabel(min) {
    if (min == null) return '–';
    return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')} h`;
}

function dayLabel(date) {
    return new Date(`${date}T12:00:00`).toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'numeric' });
}

const num = (v, digits = 0) => (v == null ? '–' : fmtNum(v, digits));
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
                    <template v-if="!health.supported.value">Erst mit der neuesten App aus TestFlight</template>
                    <template v-else-if="!health.available.value">Auf diesem Gerät nicht verfügbar</template>
                    <template v-else-if="health.asked.value">Schlaf, HRV, Ruhepuls, VO2max · Vorschau</template>
                    <template v-else>Nicht freigegeben</template>
                </span>
            </span>
            <button v-if="health.supported.value && health.available.value && !health.asked.value" type="button"
                :disabled="health.busy.value"
                class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40"
                @click="health.connect()">
                Freigeben
            </button>
            <button v-else-if="health.asked.value" type="button"
                :disabled="health.busy.value"
                class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40"
                @click="health.load()">
                {{ health.busy.value ? 'Lese …' : 'Neu lesen' }}
            </button>
        </div>

        <p v-if="health.supported.value && health.available.value && !health.asked.value" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
            Zone3 liest Schlafdauer, HRV, Ruhepuls und VO2max aus Apple Health — sonst nichts. iOS fragt dich gleich einmal, welche Werte du erlaubst.
        </p>

        <template v-if="health.asked.value">
            <p v-if="health.error.value" class="px-4 pb-3 text-[13px] text-danger">{{ health.error.value }}</p>
            <div v-else-if="health.days.value.length" class="px-4 pb-3">
                <table class="w-full text-[13px] tabular-nums">
                    <thead class="text-ink-3">
                        <tr>
                            <th class="py-1 text-left font-normal">Tag</th>
                            <th class="py-1 text-right font-normal">Schlaf</th>
                            <th class="py-1 text-right font-normal">HRV ms</th>
                            <th class="py-1 text-right font-normal">Ruhepuls</th>
                            <th class="py-1 text-right font-normal">VO2max</th>
                        </tr>
                    </thead>
                    <tbody class="text-ink">
                        <tr v-for="d in health.days.value" :key="d.date" class="border-t border-line">
                            <td class="py-1.5">{{ dayLabel(d.date) }}</td>
                            <td class="py-1.5 text-right">{{ sleepLabel(d.sleep_min) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.hrv_ms) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.resting_hr) }}</td>
                            <td class="py-1.5 text-right">{{ num(d.vo2max, 1) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-[13px] leading-snug text-ink-3">Vorschau zum Vergleich mit der Health-App. Zone3 speichert davon noch nichts.</p>
            </div>
            <p v-else-if="!health.busy.value" class="px-4 pb-3 text-[13px] leading-snug text-ink-3">
                Keine Werte aus den letzten sieben Tagen. Entweder misst nichts (HRV und Ruhepuls brauchen eine Apple Watch), oder der Zugriff ist aus: iPhone-Einstellungen → Health → Datenzugriff &amp; Geräte → Zone3.
            </p>
        </template>
    </div>
</template>
