<script setup>
/**
 * Plan ohne Rennen (Jan, 10.11.2026): Ausrichtung, beim schneller Werden
 * die Distanz, und ob am Ende jedes Blocks ein 5-km-Test steht. Dieselben
 * Felder im Onboarding und auf der Rennen-Seite.
 *
 * modelValue: { open_focus, race_distance, open_test, plan_start_date? }
 */
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Toggle from '@/Components/UI/Toggle.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    /** Startdatum abfragen (Rennen-Seite; im Onboarding fragt das Interview). */
    withStart:  { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const FOCI = [
    { value: 'base',     label: 'Grundlage aufbauen', hint: 'Für den Einstieg: mehr Kilometer, viel locker, eine ruhige harte Einheit.' },
    { value: 'speed',    label: 'Schneller werden',   hint: 'Schwelle und Intervalle auf 5 oder 10 km, der lange Lauf hält die Grundlage.' },
    { value: 'maintain', label: 'Form halten',        hint: 'Gleicher Umfang Woche für Woche, genug Reize, damit nichts verloren geht.' },
];

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });
</script>

<template>
    <FormGroup title="Ausrichtung">
        <button v-for="f in FOCI" :key="f.value" type="button" role="radio" :aria-checked="modelValue.open_focus === f.value"
            class="flex min-h-[56px] w-full items-center gap-3 px-4 py-2.5 text-left transition-colors active:bg-surface-2"
            @click="set('open_focus', f.value)">
            <span class="min-w-0 flex-1">
                <span class="block text-[17px]">{{ f.label }}</span>
                <span class="mt-0.5 block text-[13px] leading-snug text-ink-3">{{ f.hint }}</span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-accent" :class="modelValue.open_focus === f.value ? '' : 'invisible'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </button>
    </FormGroup>

    <FormGroup footer="Der Plan läuft in Blöcken zu 12 Wochen: drei Wochen Belastung, eine leichter. Danach beginnt der nächste Block von selbst.">
        <FormRow v-if="modelValue.open_focus === 'speed'" label="Distanz">
            <div class="w-40">
                <SegmentedControl :model-value="modelValue.race_distance" :options="[{ value: '5km', label: '5 km' }, { value: '10km', label: '10 km' }]"
                    @update:model-value="v => set('race_distance', v)" />
            </div>
        </FormRow>
        <FormRow label="5-km-Test am Blockende" hint="Ein Lauf, so schnell es gleichmäßig geht. Zeigt, was der Block gebracht hat.">
            <Toggle :model-value="!!modelValue.open_test" label="5-km-Test am Blockende" @update:model-value="v => set('open_test', v)" />
        </FormRow>
        <FormRow v-if="withStart" as="label" label="Start">
            <input :value="modelValue.plan_start_date" type="date" class="z-inline w-40" @input="e => set('plan_start_date', e.target.value || null)" />
        </FormRow>
    </FormGroup>
</template>
