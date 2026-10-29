<script setup>
/**
 * Trainingsmodell, Lauferfahrung und Laktatmessgerät — im Onboarding und im
 * Profil dieselben Felder. Speichern macht die Seite, die es einbindet.
 *
 * readiness: { recommended, reasons[] } aus TrainingModelService. Zone3
 * sperrt Norwegisch nicht, sagt aber, wenn die Voraussetzungen fehlen.
 */
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import Toggle from '@/Components/UI/Toggle.vue';

const model = defineModel({ type: Object, required: true });

defineProps({
    readiness: { type: Object, default: null },
});

const models = [
    {
        value: 'polarized',
        title: 'Polarisiert (80/20)',
        text:  'Mindestens 80 % der Laufzeit locker, dazu ein bis zwei harte Einheiten pro Woche. Seiler und Daniels — der bewährte Weg für die meisten Läufer.',
    },
    {
        value: 'norwegian',
        title: 'Norwegisch (Doppel-Schwelle)',
        text:  'An zwei Tagen je zwei Einheiten knapp unter der Schwelle, morgens und abends, alles andere locker. Viel Umfang im Schwellenbereich — für erfahrene Läufer mit solidem Unterbau.',
    },
];
</script>

<template>
    <div class="space-y-6">
        <FormGroup>
            <button
                v-for="m in models"
                :key="m.value"
                type="button"
                role="radio"
                :aria-checked="model.training_model === m.value"
                class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                @click="model.training_model = m.value"
            >
                <span class="min-w-0 flex-1">
                    <span class="block text-[17px] text-ink">{{ m.title }}</span>
                    <span class="mt-0.5 block text-[13px] leading-snug text-ink-3">{{ m.text }}</span>
                </span>
                <svg class="mt-1 h-5 w-5 shrink-0 text-accent" :class="model.training_model === m.value ? '' : 'invisible'"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </FormGroup>

        <div v-if="model.training_model === 'norwegian' && readiness && !readiness.recommended"
            class="rounded-card bg-warn-soft px-4 py-3 text-[15px] leading-snug text-warn-ink">
            <p class="font-semibold">Zone3 rät dir eher zu 80/20</p>
            <p v-for="r in readiness.reasons" :key="r" class="mt-1">{{ r }}</p>
            <p class="mt-1">Du kannst Norwegisch trotzdem wählen.</p>
        </div>

        <FormGroup footer="Ohne Laktatmessgerät steuert Zone3 die Schwellenarbeit über Pace und einen Pulsdeckel.">
            <FormRow as="label" label="Lauferfahrung">
                <input v-model.number="model.running_experience_years" type="number" min="0" max="60" inputmode="numeric"
                    placeholder="0" class="z-inline w-16" />
                <span class="text-[17px] text-ink-3">Jahre</span>
            </FormRow>
            <FormRow label="Laktatmessgerät">
                <Toggle v-model="model.has_lactate_meter" label="Laktatmessgerät vorhanden" />
            </FormRow>
        </FormGroup>
    </div>
</template>
