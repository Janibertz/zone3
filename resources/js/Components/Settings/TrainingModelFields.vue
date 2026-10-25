<script setup>
/**
 * Trainingsmodell, Lauferfahrung und Laktatmessgerät — im Onboarding und im
 * Profil dieselben Felder. Speichern macht die Seite, die es einbindet.
 *
 * readiness: { recommended, reasons[] } aus TrainingModelService. Zone3
 * sperrt Norwegisch nicht, sagt aber, wenn die Voraussetzungen fehlen.
 */
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
    <div class="space-y-4">
        <div class="space-y-2" role="radiogroup" aria-label="Trainingsmodell">
            <button
                v-for="m in models"
                :key="m.value"
                type="button"
                role="radio"
                :aria-checked="model.training_model === m.value"
                class="w-full rounded-card border-2 p-4 text-left transition active:scale-[0.99]"
                :class="model.training_model === m.value
                    ? 'border-accent bg-accent-soft'
                    : 'border-line bg-surface hover:border-line-strong'"
                @click="model.training_model = m.value"
            >
                <p class="text-[15px] font-semibold text-ink">{{ m.title }}</p>
                <p class="mt-1 text-[13px] leading-relaxed text-ink-2">{{ m.text }}</p>
            </button>
        </div>

        <div v-if="model.training_model === 'norwegian' && readiness && !readiness.recommended"
            class="rounded-field border border-warn/25 bg-warn-soft px-4 py-3 text-[13px] leading-relaxed text-warn-ink">
            <p class="font-semibold">Zone3 rät dir eher zu 80/20</p>
            <p v-for="r in readiness.reasons" :key="r" class="mt-1">{{ r }}</p>
            <p class="mt-1">Du kannst Norwegisch trotzdem wählen.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-ink-2">Lauferfahrung</label>
                <div class="relative">
                    <input v-model.number="model.running_experience_years" type="number" min="0" max="60" inputmode="numeric"
                        placeholder="z. B. 5" class="z-input pr-14" />
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[13px] text-ink-3">Jahre</span>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-ink-2">Laktatmessgerät</label>
                <button type="button"
                    class="flex h-[44px] w-full items-center justify-between rounded-field border border-line bg-surface px-3 text-[15px] text-ink"
                    :aria-pressed="model.has_lactate_meter"
                    @click="model.has_lactate_meter = !model.has_lactate_meter">
                    {{ model.has_lactate_meter ? 'Vorhanden' : 'Keins' }}
                    <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                        :class="model.has_lactate_meter ? 'bg-accent' : 'bg-surface-3'">
                        <span class="inline-block h-4 w-4 rounded-full bg-surface transition-transform"
                            :class="model.has_lactate_meter ? 'translate-x-6' : 'translate-x-1'" />
                    </span>
                </button>
            </div>
        </div>
        <p class="text-[13px] leading-relaxed text-ink-3">
            Ohne Laktatmessgerät steuert Zone3 die Schwellenarbeit über Pace und einen Pulsdeckel.
        </p>
    </div>
</template>
