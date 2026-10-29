<script setup>
/**
 * Rad / Zwift — im Onboarding und im Profil dieselben Felder.
 *
 * Die FTP trägt der Athlet selbst ein: Strava liefert pro Fahrt nur
 * Durchschnitt und Normalized Power, keinen Leistungsverlauf. Liegt eine
 * Fahrt darüber, schlägt das Profil einen höheren Wert vor (`FtpCheck`).
 */
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import Toggle from '@/Components/UI/Toggle.vue';

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
    <div class="space-y-6">
        <FormGroup>
            <FormRow label="Rad einplanen" hint="Indoor auf Zwift oder draußen">
                <Toggle v-model="model.bike_enabled" label="Rad einplanen" />
            </FormRow>
        </FormGroup>

        <template v-if="model.bike_enabled">
            <FormGroup title="Wann" footer="Winter heißt November bis März." plain>
                <SegmentedControl v-model="model.bike_season" :options="seasons" />
            </FormGroup>

            <FormGroup title="Wofür" plain>
                <SegmentedControl v-model="model.bike_purpose" :options="purposes" />
                <template #footer>
                    {{ model.bike_purpose === 'easy'
                        ? 'Eine Radeinheit ersetzt einen lockeren Lauf.'
                        : model.bike_purpose === 'intervals'
                            ? 'Eigene harte Radeinheiten, als .zwo-Datei für Zwift.'
                            : 'Lockere Läufe ersetzen und eigene Radintervalle.' }}
                    Der lange Lauf wird nie ersetzt.
                </template>
            </FormGroup>

            <FormGroup>
                <FormRow as="label" label="Höchstens pro Woche">
                    <select v-model.number="model.bike_max_per_week" class="z-inline appearance-none pr-1 text-ink-3">
                        <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                    </select>
                </FormRow>
                <FormRow as="label" label="FTP">
                    <input v-model.number="model.ftp" type="number" min="50" max="600" inputmode="numeric"
                        placeholder="250" class="z-inline w-20" />
                    <span class="text-[17px] text-ink-3">W</span>
                </FormRow>
                <template v-if="model.bike_purpose !== 'easy' && !model.ftp" #footer>
                    <span class="text-warn-ink">Ohne FTP kann Zone3 keine Radintervalle in % FTP schreiben. Den Wert findest du in Zwift unter deinem Profil.</span>
                </template>
            </FormGroup>

            <FormGroup title="Bevorzugte Tage (optional)" plain>
                <div class="grid grid-cols-7 gap-1.5">
                    <button v-for="d in days" :key="d.key" type="button"
                        class="h-9 rounded-full text-[15px] font-semibold transition active:scale-95"
                        :class="(model.bike_preferred_days ?? []).includes(d.key)
                            ? 'bg-accent text-white'
                            : 'bg-surface-2 text-ink-2'"
                        @click="toggleDay(d.key)">
                        {{ d.label }}
                    </button>
                </div>
            </FormGroup>
        </template>
    </div>
</template>
