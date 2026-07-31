<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['close', 'saved']);

const today = new Date();

const blank = () => ({
    energy_level:    5,
    mood:            5,
    sleep_quality:   5,
    muscle_soreness: 5,
    stress_level:    5,
    notes:           '',
    is_sick:         false,
    is_injured:      false,
});

const form       = ref(blank());
const submitting = ref(false);
const loadError  = ref('');
const saveError  = ref('');

/** The five sliders, so the markup stays a single loop. */
const scales = [
    { key: 'energy_level',    label: '⚡ Energielevel',    tone: 'warn' },
    { key: 'mood',            label: '😊 Stimmung',        tone: 'info' },
    { key: 'sleep_quality',   label: '😴 Schlafqualität',  tone: 'accent' },
    { key: 'muscle_soreness', label: '🦵 Muskelkater',     tone: 'danger' },
    { key: 'stress_level',    label: '😰 Stress-Level',    tone: 'warn' },
];

const toneClasses = {
    warn:   'bg-warn-soft text-warn-ink',
    info:   'bg-info-soft text-info-ink',
    accent: 'bg-accent-soft text-accent-ink',
    danger: 'bg-danger-soft text-danger-ink',
};

// Soreness and stress are inverted — high values are bad.
const wellbeingScore = computed(() => {
    const f = form.value;
    const total = f.energy_level + f.mood + f.sleep_quality
        + (10 - f.muscle_soreness) + (10 - f.stress_level);
    return (total / 5).toFixed(1);
});

const wellbeingStatus = computed(() => {
    const score = parseFloat(wellbeingScore.value);

    if (form.value.is_sick || form.value.is_injured) return '🤕 Nicht trainieren';
    if (score >= 8) return '🚀 Perfekt für hartes Training';
    if (score >= 6) return '💪 Moderates Training möglich';
    if (score >= 4) return '😴 Nur leichtes Training';
    return '⚠️ Ruhetag empfohlen';
});

const formatDate = (date) => date.toLocaleDateString('de-DE', {
    weekday: 'long',
    year:    'numeric',
    month:   'long',
    day:     'numeric',
});

async function submitWellbeing() {
    submitting.value = true;
    saveError.value  = '';
    try {
        const res = await axios.post('/api/wellbeing', form.value);
        emit('saved', res.data);
        emit('close');
    } catch (error) {
        saveError.value = error.response?.data?.message
            ?? 'Speichern fehlgeschlagen. Bitte noch einmal versuchen.';
    } finally {
        submitting.value = false;
    }
}

// Pull today's entry when the sheet opens so an edit continues where it left off.
watch(() => props.show, async (open) => {
    if (!open) return;

    loadError.value = '';
    saveError.value = '';
    try {
        const { data } = await axios.get('/api/wellbeing/today');
        if (data) {
            form.value = {
                energy_level:    data.energy_level    || 5,
                mood:            data.mood            || 5,
                sleep_quality:   data.sleep_quality   || 5,
                muscle_soreness: data.muscle_soreness || 5,
                stress_level:    data.stress_level    || 5,
                notes:           data.notes           || '',
                is_sick:         data.is_sick         || false,
                is_injured:      data.is_injured      || false,
            };
        }
    } catch {
        loadError.value = 'Der heutige Eintrag konnte nicht geladen werden — du startest bei den Standardwerten.';
        form.value = blank();
    }
});
</script>

<template>
    <AppSheet
        :show="show"
        title="💪 Tägliches Wellbeing"
        :subtitle="formatDate(today)"
        max-width="lg"
        @close="$emit('close')"
    >
        <p v-if="loadError" class="mb-4 rounded-card bg-warn-soft p-3 text-sm text-warn-ink">{{ loadError }}</p>

        <div class="space-y-5 pt-1">
            <!-- Scales -->
            <div v-for="s in scales" :key="s.key">
                <label class="z-label">{{ s.label }}</label>
                <div class="flex items-center gap-3">
                    <input
                        v-model.number="form[s.key]"
                        type="range"
                        min="1"
                        max="10"
                        class="z-range flex-1"
                    />
                    <span class="w-11 shrink-0 rounded-field py-1 text-center text-lg font-bold tabular-nums" :class="toneClasses[s.tone]">
                        {{ form[s.key] }}
                    </span>
                </div>
            </div>

            <!-- Status flags -->
            <div class="rounded-card border border-line bg-surface-2 p-4">
                <p class="mb-3 text-sm font-medium text-ink-2">Status</p>
                <div class="space-y-3">
                    <label class="flex cursor-pointer items-center gap-3">
                        <input v-model="form.is_sick" type="checkbox" class="h-4 w-4 rounded border-line-strong text-danger focus:ring-danger" />
                        <span class="text-sm text-ink">🦠 Ich bin krank</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-3">
                        <input v-model="form.is_injured" type="checkbox" class="h-4 w-4 rounded border-line-strong text-danger focus:ring-danger" />
                        <span class="text-sm text-ink">🤕 Ich bin verletzt</span>
                    </label>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label for="wellbeing-notes" class="z-label">📝 Notizen (optional)</label>
                <textarea
                    id="wellbeing-notes"
                    v-model="form.notes"
                    rows="3"
                    class="z-input resize-none"
                    placeholder="z.B. Gutes Training heute, leichte Verletzung am linken Fuß…"
                />
            </div>

            <!-- Result -->
            <div class="rounded-card border border-accent/30 bg-accent-soft p-4">
                <p class="text-sm font-medium text-ink-2">Dein Wellbeing-Status</p>
                <p class="mt-1 text-xl font-bold text-ink">
                    {{ wellbeingStatus }}
                    <span class="text-base font-semibold text-ink-3">({{ wellbeingScore }}/10)</span>
                </p>
            </div>
        </div>

        <template #footer>
            <p v-if="saveError" class="mb-3 text-sm text-danger">{{ saveError }}</p>
            <div class="flex gap-3">
                <AppButton variant="secondary" block @click="$emit('close')">Abbrechen</AppButton>
                <AppButton block :loading="submitting" @click="submitWellbeing">
                    {{ submitting ? 'Speichert…' : 'Speichern' }}
                </AppButton>
            </div>
        </template>
    </AppSheet>
</template>
