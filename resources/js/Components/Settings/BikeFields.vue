<script setup>
/**
 * Rad / Zwift — im Onboarding und im Profil dieselben Felder.
 *
 * Die FTP trägt der Athlet selbst ein: von Strava speichert Zone3 pro Fahrt
 * nur die Durchschnittswatt, und daraus lässt sich keine FTP ehrlich
 * schätzen.
 */
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';

const model = defineModel({ type: Object, required: true });

const seasons  = [{ value: 'winter', label: 'Nur Winter' }, { value: 'all_year', label: 'Ganzjährig' }];
const purposes = [{ value: 'easy', label: 'Locker' }, { value: 'intervals', label: 'Intervalle' }, { value: 'both', label: 'Beides' }];
const days = [
    { key: 'monday', label: 'Mo' }, { key: 'tuesday', label: 'Di' }, { key: 'wednesday', label: 'Mi' },
    { key: 'thursday', label: 'Do' }, { key: 'friday', label: 'Fr' }, { key: 'saturday', label: 'Sa' },
    { key: 'sunday', label: 'So' },
];

function toggleDay(key) {
    const list = model.value.bike_preferred_days ?? [];
    model.value.bike_preferred_days = list.includes(key) ? list.filter(d => d !== key) : [...list, key];
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between rounded-card border border-line bg-surface p-4">
            <div>
                <p class="text-[15px] font-semibold text-ink">Rad einplanen</p>
                <p class="mt-0.5 text-[13px] text-ink-3">Indoor auf Zwift oder draußen</p>
            </div>
            <button type="button" :aria-pressed="model.bike_enabled"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                :class="model.bike_enabled ? 'bg-accent' : 'bg-surface-3'"
                @click="model.bike_enabled = !model.bike_enabled">
                <span class="inline-block h-4 w-4 rounded-full bg-surface transition-transform"
                    :class="model.bike_enabled ? 'translate-x-6' : 'translate-x-1'" />
            </button>
        </div>

        <template v-if="model.bike_enabled">
            <div class="space-y-1.5">
                <p class="text-[13px] font-medium text-ink-2">Wann</p>
                <SegmentedControl v-model="model.bike_season" :options="seasons" />
                <p class="text-[13px] text-ink-3">Winter heißt November bis März.</p>
            </div>

            <div class="space-y-1.5">
                <p class="text-[13px] font-medium text-ink-2">Wofür</p>
                <SegmentedControl v-model="model.bike_purpose" :options="purposes" />
                <p class="text-[13px] text-ink-3">
                    {{ model.bike_purpose === 'easy'
                        ? 'Eine Radeinheit ersetzt einen lockeren Lauf.'
                        : model.bike_purpose === 'intervals'
                            ? 'Eigene harte Radeinheiten, als .zwo-Datei für Zwift.'
                            : 'Lockere Läufe ersetzen und eigene Radintervalle.' }}
                    Der lange Lauf wird nie ersetzt.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-ink-2">Höchstens pro Woche</label>
                    <select v-model.number="model.bike_max_per_week" class="z-input">
                        <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-ink-2">FTP</label>
                    <div class="relative">
                        <input v-model.number="model.ftp" type="number" min="50" max="600" inputmode="numeric"
                            placeholder="z. B. 250" class="z-input pr-12" />
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[13px] text-ink-3">Watt</span>
                    </div>
                </div>
            </div>
            <p v-if="model.bike_purpose !== 'easy' && !model.ftp" class="text-[13px] text-warn-ink">
                Ohne FTP kann Zone3 keine Radintervalle in % FTP schreiben. Den Wert findest du in Zwift unter deinem Profil.
            </p>

            <div class="space-y-1.5">
                <p class="text-[13px] font-medium text-ink-2">Bevorzugte Tage <span class="font-normal text-ink-3">(optional)</span></p>
                <div class="grid grid-cols-7 gap-1.5">
                    <button v-for="d in days" :key="d.key" type="button"
                        class="rounded-field border py-2 text-[13px] font-semibold transition active:scale-95"
                        :class="(model.bike_preferred_days ?? []).includes(d.key)
                            ? 'border-accent bg-accent text-white'
                            : 'border-line bg-surface-2 text-ink-3'"
                        @click="toggleDay(d.key)">
                        {{ d.label }}
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
